<?php

namespace Innobrain\SoakTime;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class Plugin implements Capable, EventSubscriberInterface, PluginInterface
{
    public const DEFAULT_MIN_HOURS = 168;

    private const DEFAULT_SECURITY_HOURS = 72;

    /** Always whitelist self to prevent locking the plugin out of its own updates. */
    private const SELF = 'innobrain/soak-time';

    private Composer $composer;

    private IOInterface $io;

    private SoakTimeConfig $config;

    private IntegrityLockFile $lockFile;

    private PublishedTimeResolver $publishedTime;

    private ?NativeCooldown $nativeCooldown = null;

    private CooldownState $cooldownState = CooldownState::Disabled;

    private bool $periodSupplied = false;

    private bool $hoursConfigured = false;

    private bool $cooldownBypassed = false;

    private ?SecurityFixGate $securityGate = null;

    /** @var list<PackageInterface> */
    private array $securityExemptions = [];

    /** @var array<string, true> Versions already pinned in composer.lock, keyed by PackageFilter::lockedKey(). */
    private array $lockedVersions = [];

    /** @var array<string, list<string>> Normalized locked versions keyed by package name, for the security-fix override. */
    private array $lockedVersionsByName = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $extra = $composer->getPackage()->getExtra();

        $this->composer = $composer;
        $this->io = $io;
        $this->config = $this->resolveConfig($extra);
        $this->hoursConfigured = array_key_exists('soak-time-hours', $extra) || trim((string) getenv('SOAK_TIME_HOURS')) !== '';
        $this->lockFile = IntegrityLockFile::load($this->resolveLockPath());
        $this->publishedTime = new PublishedTimeResolver();
        $this->loadLockedVersions($composer);

        if (NativeCooldown::isAvailable()) {
            $this->configureNativeCooldown(new NativeCooldown($composer->getConfig()));
        }
    }

    public function getCapabilities(): array
    {
        return [CommandProviderCapability::class => CommandProvider::class];
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => 'onCommand',
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
            PluginEvents::POST_FILE_DOWNLOAD => 'onPostFileDownload',
            PackageEvents::POST_PACKAGE_INSTALL => 'onPostPackageInstallOrUpdate',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPostPackageInstallOrUpdate',
            ScriptEvents::POST_INSTALL_CMD => 'onPostCommand',
            ScriptEvents::POST_UPDATE_CMD => 'onPostCommand',
        ];
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        if ($this->config->integrity) {
            (new ReferenceDriftCheck($this->lockFile, $this->config->devBranches, $this->config->integrityIgnore))
                ->verify($event->getPackages());
        }

        if ($this->nativeCooldown !== null) {
            $this->filterAlongsideNativeCooldown($event);

            return;
        }

        if ($this->config->skipAllSoak) {
            $this->io->writeError(
                '<warning>[Soak Time] Soak filter bypass active (SOAK_TIME_SKIP=1) — integrity checks '
                .'remain enabled.</warning>'
            );

            return;
        }

        $this->io->write(sprintf(
            '<info>[Soak Time] Inspecting packages (minimum age: %dh%s)...</info>',
            $this->config->minHours,
            $this->config->packageHours === []
                ? ''
                : sprintf(', %d per-package override(s)', count($this->config->packageHours))
        ));

        $now = new \DateTimeImmutable();
        $threshold = $now->modify("-{$this->config->minHours} hours");
        $securityGate = $this->soakSecurityGate($event);

        $packageThresholds = [];

        foreach ($this->config->packageHours as $pattern => $hours) {
            $packageThresholds[$pattern] = $now->modify("-{$hours} hours");
        }

        $result = (new PackageFilter($this->publishedTime))->filter(
            $event->getPackages(),
            $threshold,
            $this->config->whitelist,
            $this->lockedVersions,
            $securityGate,
            $packageThresholds
        );

        $this->report($result, $securityGate);

        $event->setPackages($result->keptPackages);
    }

    /**
     * Add every locked version as an ignore rule, so a full update never trades
     * a locked version for an older one.
     */
    private function configureNativeCooldown(NativeCooldown $cooldown): void
    {
        $this->nativeCooldown = $cooldown;
        $this->cooldownState = $cooldown->state();

        if ($this->cooldownState === CooldownState::Unconfigured && ! $this->config->skipAllSoak) {
            $cooldown->supplyPeriod($this->config->minHours);
            $this->cooldownState = CooldownState::Configured;
            $this->periodSupplied = true;
        }

        if ($this->cooldownState !== CooldownState::Configured) {
            return;
        }

        $rules = [];

        foreach ($this->config->whitelist as $pattern) {
            $rules[$pattern][] = ['reason' => 'soak-time: whitelisted'];
        }

        foreach (array_keys($this->config->packageHours) as $pattern) {
            $rules[$pattern][] = ['reason' => 'soak-time: judged by its own soak window'];
        }

        foreach ($this->lockedVersionsByName as $name => $versions) {
            foreach ($versions as $version) {
                $rules[$name][] = ['constraint' => '=='.$version, 'reason' => 'soak-time: locked in composer.lock'];
            }
        }

        $cooldown->exempt($rules);
    }

    public function onCommand(CommandEvent $event): void
    {
        if ($this->nativeCooldown === null || $this->cooldownState !== CooldownState::Configured) {
            return;
        }

        $input = $event->getInput();

        foreach (['no-cooldown', 'no-blocking', 'no-security-blocking'] as $option) {
            if ($input->hasOption($option) && $input->getOption($option)) {
                $this->cooldownBypassed = true;
            }
        }

        foreach (['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING'] as $variable) {
            if (in_array(getenv($variable), ['1', 'true', 'on'], true)) {
                $this->cooldownBypassed = true;
            }
        }

        // `fix` is innobrain/composer-fix, which updates without going through Composer's update command.
        if (! $this->cooldownBypassed && in_array($event->getCommandName(), ['update', 'require', 'remove', 'fix'], true)) {
            $this->exemptSecurityFixes($this->nativeCooldown);
        }
    }

    /**
     * The exemption has to exist before Composer reads its policy, so advisories
     * for all locked packages are fetched here, in one query per repository.
     */
    private function exemptSecurityFixes(NativeCooldown $cooldown): void
    {
        $periodSeconds = $cooldown->periodSeconds();

        if ($this->lockedVersionsByName === [] || ($periodSeconds !== null && $this->config->securityHours * 3600 >= $periodSeconds)) {
            return;
        }

        $now = new \DateTimeImmutable();
        $repositories = $this->composer->getRepositoryManager()->getRepositories();
        $this->securityGate = new SecurityFixGate(
            $now->modify("-{$this->config->securityHours} hours"),
            $repositories,
            $this->lockedVersionsByName,
        );
        $cooldownStart = $periodSeconds === null ? null : $now->modify("-{$periodSeconds} seconds");

        $rules = [];

        foreach ($this->securityGate->vulnerableNames() as $name) {
            if ($this->ignoredByNativeCooldown($name)) {
                continue;
            }

            $fixes = [];

            foreach ($repositories as $repository) {
                foreach ($repository->findPackages($name) as $candidate) {
                    $releaseDate = $this->publishedTime->releaseTime($candidate)->date;

                    if ($candidate->isDev() || $releaseDate === null || ($cooldownStart !== null && $releaseDate <= $cooldownStart)) {
                        continue;
                    }

                    if ($this->securityGate->admits($candidate, $releaseDate)) {
                        $fixes[$candidate->getVersion()] = $candidate;
                    }
                }
            }

            foreach ($fixes as $version => $fix) {
                $rules[$name][] = ['constraint' => '=='.$version, 'reason' => 'soak-time: security fix past the security window'];
                $this->securityExemptions[] = $fix;
            }
        }

        $cooldown->exempt($rules);
    }

    private function ignoredByNativeCooldown(string $name): bool
    {
        return PackageFilter::matchesWhitelist($name, $this->config->whitelist)
            || PackageFilter::firstMatch($name, $this->config->packageHours) !== null;
    }

    private function filterAlongsideNativeCooldown(PrePoolCreateEvent $event): void
    {
        $this->announceNativeCooldown();

        if ($this->cooldownState !== CooldownState::Configured || $this->cooldownBypassed || $this->config->packageHours === []) {
            return;
        }

        $now = new \DateTimeImmutable();
        $packageThresholds = [];

        foreach ($this->config->packageHours as $pattern => $hours) {
            $packageThresholds[$pattern] = $now->modify("-{$hours} hours");
        }

        $result = (new PackageFilter($this->publishedTime))->filter(
            $event->getPackages(),
            null,
            $this->config->whitelist,
            $this->lockedVersions,
            $this->securityGate,
            $packageThresholds
        );

        $this->report($result, $this->securityGate);

        $event->setPackages($result->keptPackages);
    }

    private function announceNativeCooldown(): void
    {
        if ($this->cooldownState === CooldownState::Disabled) {
            $this->io->write(
                '<info>[Soak Time] Composer\'s cooldown policy is turned off in the config, so fresh versions are not '
                .'held back. Integrity checks remain enabled.</info>'
            );

            return;
        }

        if ($this->cooldownState === CooldownState::Unconfigured) {
            $this->io->writeError(
                '<warning>[Soak Time] SOAK_TIME_SKIP=1: fresh versions are not held back in this run. Integrity checks '
                .'remain enabled.</warning>'
            );

            return;
        }

        if ($this->cooldownBypassed) {
            $this->io->write(
                '<info>[Soak Time] Composer\'s cooldown policy is bypassed for this run. Integrity checks remain enabled.</info>'
            );

            return;
        }

        $periodSeconds = $this->periodSupplied ? $this->config->minHours * 3600 : $this->nativeCooldown?->periodSeconds();
        $source = $this->periodSupplied ? 'set by soak-time' : 'from '.$this->nativeCooldown?->periodSource();

        $this->io->write(sprintf(
            '<info>[Soak Time] Composer\'s cooldown policy holds back %s (%s)%s.</info>',
            $periodSeconds === null ? 'fresh releases' : 'releases younger than '.NativeCooldown::formatPeriod($periodSeconds),
            $source,
            $this->config->packageHours === []
                ? ''
                : sprintf('; soak-time applies %d per-package window(s)', count($this->config->packageHours))
        ));

        if (! $this->periodSupplied && $this->hoursConfigured) {
            $this->io->writeError(sprintf(
                '<warning>[Soak Time] soak-time-hours / SOAK_TIME_HOURS is not applied: the period comes from %s.</warning>',
                $this->nativeCooldown?->periodSource()
            ));
        }

        if (! $this->periodSupplied && $this->config->skipAllSoak) {
            $this->io->writeError(
                '<warning>[Soak Time] SOAK_TIME_SKIP=1 cannot turn off Composer\'s cooldown. Run with --no-cooldown instead.</warning>'
            );
        }

        if ($this->securityGate !== null) {
            foreach ($this->securityExemptions as $package) {
                $this->reportSecurityOverride($package, $this->securityGate, "Composer's cooldown");
            }
        }

        $this->securityExemptions = [];
    }

    /**
     * The security-fix override only matters when its window is shorter than
     * the soak window and there are locked versions to compare advisories
     * against; otherwise return null so no advisory lookups can ever happen.
     */
    private function soakSecurityGate(PrePoolCreateEvent $event): ?SecurityFixGate
    {
        if ($this->config->securityHours >= $this->config->minHours || $this->lockedVersionsByName === []) {
            return null;
        }

        return new SecurityFixGate(
            (new \DateTimeImmutable())->modify("-{$this->config->securityHours} hours"),
            $event->getRepositories(),
            $this->lockedVersionsByName,
        );
    }

    public function onPostFileDownload(PostFileDownloadEvent $event): void
    {
        if (! $this->config->integrity) {
            return;
        }

        (new HashVerifier($this->lockFile, $this->io, $this->config->devBranches, $this->config->integrityIgnore))
            ->verify($event);
    }

    public function onPostPackageInstallOrUpdate(PackageEvent $event): void
    {
        if (! $this->config->integrity) {
            return;
        }

        $package = $this->packageFromOperation($event->getOperation());

        if ($package === null) {
            return;
        }

        (new PackageIntegrityRecorder($this->lockFile, $this->io, $this->config->devBranches, $this->config->integrityIgnore))
            ->record($package);
    }

    public function onPostCommand(): void
    {
        if (! $this->config->integrity) {
            return;
        }

        $this->lockFile->save();
    }

    /**
     * Snapshot the versions already pinned in composer.lock so the soak filter
     * can exempt them and the security-fix override can match advisories
     * against them. Empty when there is no lock yet (a first install/require
     * resolves fresh and is gated normally).
     */
    private function loadLockedVersions(Composer $composer): void
    {
        $locker = $composer->getLocker();

        if (! $locker->isLocked()) {
            return;
        }

        foreach ($locker->getLockedRepository(true)->getPackages() as $package) {
            $this->lockedVersions[PackageFilter::lockedKey($package)] = true;
            $this->lockedVersionsByName[$package->getName()][] = $package->getVersion();
        }
    }

    private function resolveLockPath(): string
    {
        $configured = $this->config->integrityLockPath;

        if ((new Filesystem())->isAbsolutePath($configured)) {
            return $configured;
        }

        return dirname(Factory::getComposerFile()).DIRECTORY_SEPARATOR.$configured;
    }

    private function packageFromOperation(OperationInterface $operation): ?PackageInterface
    {
        if ($operation instanceof InstallOperation) {
            return $operation->getPackage();
        }

        if ($operation instanceof UpdateOperation) {
            return $operation->getTargetPackage();
        }

        return null;
    }

    /**
     * Resolve runtime config, reporting invalid input as it goes. Message
     * wording lives here, next to the IO it writes to, not in the value object.
     *
     * @param  array<string, mixed>  $extra
     */
    private function resolveConfig(array $extra): SoakTimeConfig
    {
        $minHours = self::DEFAULT_MIN_HOURS;

        if (array_key_exists('soak-time-hours', $extra)) {
            $parsed = SoakTimeConfig::parseHours($extra['soak-time-hours']);

            if ($parsed === null) {
                $this->io->writeError(sprintf(
                    '<warning>[Soak Time] Ignoring invalid "soak-time-hours" in composer.json — '
                    .'expected a non-negative integer. Using %dh.</warning>',
                    $minHours
                ));
            } else {
                $minHours = $parsed;
            }
        }

        $envHours = getenv('SOAK_TIME_HOURS');

        if ($envHours !== false && $envHours !== '') {
            $parsed = SoakTimeConfig::parseHours($envHours);

            if ($parsed === null) {
                $this->io->writeError(sprintf(
                    '<warning>[Soak Time] Ignoring invalid SOAK_TIME_HOURS value "%s" — '
                    .'expected a non-negative integer. Using %dh.</warning>',
                    $envHours,
                    $minHours
                ));
            } else {
                $minHours = $parsed;
            }
        }

        if ($minHours === 0) {
            $this->io->writeError(
                '<warning>[Soak Time] Soak time is 0h — no package filtering will be applied.</warning>'
            );
        }

        $securityHours = self::DEFAULT_SECURITY_HOURS;

        if (array_key_exists('soak-time-security-hours', $extra)) {
            $parsed = SoakTimeConfig::parseHours($extra['soak-time-security-hours']);

            if ($parsed === null) {
                $this->io->writeError(sprintf(
                    '<warning>[Soak Time] Ignoring invalid "soak-time-security-hours" in composer.json — '
                    .'expected a non-negative integer. Using %dh.</warning>',
                    $securityHours
                ));
            } else {
                $securityHours = $parsed;
            }
        }

        $envSecurityHours = getenv('SOAK_TIME_SECURITY_HOURS');

        if ($envSecurityHours !== false && $envSecurityHours !== '') {
            $parsed = SoakTimeConfig::parseHours($envSecurityHours);

            if ($parsed === null) {
                $this->io->writeError(sprintf(
                    '<warning>[Soak Time] Ignoring invalid SOAK_TIME_SECURITY_HOURS value "%s" — '
                    .'expected a non-negative integer. Using %dh.</warning>',
                    $envSecurityHours,
                    $securityHours
                ));
            } else {
                $securityHours = $parsed;
            }
        }

        $packageHours = [];

        if (isset($extra['soak-time-package-hours']) && is_array($extra['soak-time-package-hours'])) {
            foreach ($extra['soak-time-package-hours'] as $pattern => $rawHours) {
                $hours = SoakTimeConfig::parseHours($rawHours);

                if (! is_string($pattern) || ! PackageFilter::isValidWhitelistPattern($pattern) || $hours === null) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid extra.soak-time-package-hours entry "%s" — expected a "vendor/name": hours pair with a literal vendor (wildcards only in the name half) and a non-negative integer.</warning>',
                        is_string($pattern) ? $pattern : get_debug_type($pattern)
                    ));

                    continue;
                }

                $packageHours[$pattern] = $hours;
            }
        }

        $envPackageHours = getenv('SOAK_TIME_PACKAGE_HOURS');

        if ($envPackageHours !== false && trim($envPackageHours) !== '') {
            $fromEnv = [];

            foreach (explode(',', $envPackageHours) as $entry) {
                $entry = trim($entry);

                if ($entry === '') {
                    continue;
                }

                $parts = explode(':', $entry);
                $pattern = strtolower(trim($parts[0]));
                $hours = count($parts) === 2 ? SoakTimeConfig::parseHours(trim($parts[1])) : null;

                if (! PackageFilter::isValidWhitelistPattern($pattern) || $hours === null) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid SOAK_TIME_PACKAGE_HOURS entry "%s" — expected "vendor/name:hours" with a literal vendor (wildcards only in the name half) and a non-negative integer.</warning>',
                        $entry
                    ));

                    continue;
                }

                $fromEnv[$pattern] = $hours;
            }

            // Env entries take precedence: they win on identical patterns and
            // are matched first when several patterns cover the same package.
            $packageHours = $fromEnv + $packageHours;
        }

        $whitelist = [self::SELF];

        if (isset($extra['soak-time-whitelist']) && is_array($extra['soak-time-whitelist'])) {
            foreach (array_values($extra['soak-time-whitelist']) as $entry) {
                if (! is_string($entry) || ! PackageFilter::isValidWhitelistPattern($entry)) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid extra.soak-time-whitelist entry "%s" — patterns must be "vendor/name"; the vendor must be a literal (no "*"), wildcards are only allowed in the name half (e.g. "your-company/*").</warning>',
                        is_string($entry) ? $entry : get_debug_type($entry)
                    ));

                    continue;
                }

                $whitelist[] = $entry;
            }
        }

        $skipAllSoak = false;
        $skip = getenv('SOAK_TIME_SKIP');

        if ($skip !== false && trim($skip) !== '' && ! in_array(strtolower(trim($skip)), ['0', 'false', 'no'], true)) {
            $trimmedSkip = trim($skip);

            if (in_array(strtolower($trimmedSkip), ['1', 'true', '*', 'all'], true)) {
                $skipAllSoak = true;
            } else {
                $skipPackages = [];

                foreach (explode(',', $trimmedSkip) as $packageName) {
                    $packageName = strtolower(trim($packageName));

                    if ($packageName === '') {
                        continue;
                    }

                    if (
                        preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.*-]+$/', $packageName) !== 1
                        || ! PackageFilter::isValidWhitelistPattern($packageName)
                    ) {
                        $this->io->writeError(sprintf(
                            '<warning>[Soak Time] Ignoring invalid package name "%s" in SOAK_TIME_SKIP.</warning>',
                            $packageName
                        ));

                        continue;
                    }

                    $skipPackages[] = $packageName;
                }

                if ($skipPackages !== []) {
                    $whitelist = array_merge($whitelist, $skipPackages);
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Soak filter skipped for package(s): %s. Integrity checks remain enabled.</warning>',
                        implode(', ', $skipPackages)
                    ));
                }
            }
        }

        $integrity = true;

        if (array_key_exists('soak-time-integrity', $extra)) {
            $raw = $extra['soak-time-integrity'];

            if (is_bool($raw)) {
                $integrity = $raw;
            } else {
                $this->io->writeError(
                    '<warning>[Soak Time] Ignoring invalid "soak-time-integrity" in composer.json — '
                    .'expected a boolean. Leaving integrity checks enabled.</warning>'
                );
            }
        }

        $integrityLockPath = 'composer-integrity.lock';

        if (isset($extra['soak-time-integrity-lock'])) {
            $raw = $extra['soak-time-integrity-lock'];

            if (is_string($raw) && $raw !== '') {
                $integrityLockPath = $raw;
            } else {
                $this->io->writeError(
                    '<warning>[Soak Time] Ignoring invalid "soak-time-integrity-lock" in composer.json — '
                    .'expected a non-empty string. Using "composer-integrity.lock".</warning>'
                );
            }
        }

        $devBranches = [];

        if (isset($extra['soak-time-dev-branches']) && is_array($extra['soak-time-dev-branches'])) {
            foreach (array_values($extra['soak-time-dev-branches']) as $entry) {
                if (! is_string($entry) || ! PackageFilter::isValidWhitelistPattern($entry)) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid extra.soak-time-dev-branches entry "%s" — patterns must be "vendor/name"; the vendor must be a literal (no "*"), wildcards are only allowed in the name half (e.g. "your-company/*").</warning>',
                        is_string($entry) ? $entry : get_debug_type($entry)
                    ));

                    continue;
                }

                $devBranches[] = $entry;
            }
        }

        $envDevBranches = getenv('SOAK_TIME_DEV_BRANCHES');

        if ($envDevBranches !== false && trim($envDevBranches) !== '') {
            foreach (explode(',', $envDevBranches) as $entry) {
                $entry = strtolower(trim($entry));

                if ($entry === '') {
                    continue;
                }

                if (! PackageFilter::isValidWhitelistPattern($entry)) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid SOAK_TIME_DEV_BRANCHES entry "%s" — patterns must be "vendor/name"; the vendor must be a literal (no "*"), wildcards are only allowed in the name half (e.g. "your-company/*").</warning>',
                        $entry
                    ));

                    continue;
                }

                $devBranches[] = $entry;
            }
        }

        $integrityIgnore = [];

        if (isset($extra['soak-time-integrity-ignore']) && is_array($extra['soak-time-integrity-ignore'])) {
            foreach (array_values($extra['soak-time-integrity-ignore']) as $entry) {
                if (! is_string($entry) || ! PackageFilter::isValidWhitelistPattern($entry)) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid extra.soak-time-integrity-ignore entry "%s" — patterns must be "vendor/name"; the vendor must be a literal (no "*"), wildcards are only allowed in the name half (e.g. "your-company/*").</warning>',
                        is_string($entry) ? $entry : get_debug_type($entry)
                    ));

                    continue;
                }

                $integrityIgnore[] = $entry;
            }
        }

        $envIntegrityIgnore = getenv('SOAK_TIME_INTEGRITY_IGNORE');

        if ($envIntegrityIgnore !== false && trim($envIntegrityIgnore) !== '') {
            foreach (explode(',', $envIntegrityIgnore) as $entry) {
                $entry = strtolower(trim($entry));

                if ($entry === '') {
                    continue;
                }

                if (! PackageFilter::isValidWhitelistPattern($entry)) {
                    $this->io->writeError(sprintf(
                        '<warning>[Soak Time] Ignoring invalid SOAK_TIME_INTEGRITY_IGNORE entry "%s" — patterns must be "vendor/name"; the vendor must be a literal (no "*"), wildcards are only allowed in the name half (e.g. "your-company/*").</warning>',
                        $entry
                    ));

                    continue;
                }

                $integrityIgnore[] = $entry;
            }
        }

        if ($integrity && $integrityIgnore !== []) {
            $this->io->writeError(sprintf(
                '<warning>[Soak Time] Integrity checks DISABLED for package(s): %s. Drift and hash verification will not run for these — only ignore packages whose installs (e.g. multi-archive Composer plugins) you have manually verified.</warning>',
                implode(', ', $integrityIgnore)
            ));
        }

        return new SoakTimeConfig(
            $minHours,
            $whitelist,
            $skipAllSoak,
            $integrity,
            $integrityLockPath,
            $devBranches,
            $integrityIgnore,
            $securityHours,
            $packageHours,
        );
    }

    private function report(FilterResult $result, ?SecurityFixGate $securityGate): void
    {
        $this->reportSecurityAdmitted($result, $securityGate);
        $this->reportUnverified($result);

        $droppedCount = $result->droppedCount();

        if ($droppedCount === 0) {
            return;
        }

        if ($this->io->isVerbose()) {
            foreach ($result->droppedByName as $name => $packages) {
                $this->io->write($this->describeDropped($name, $packages));
            }
        }

        $this->io->write(sprintf(
            '<info>[Soak Time] Filtered out %d recent version(s) across %d package(s).%s</info>',
            $droppedCount,
            count($result->droppedByName),
            $this->io->isVerbose() ? '' : ' Run with -v for details.'
        ));

        $this->io->write($this->describeSourceBreakdown($result));

        foreach ($result->fullyDroppedNames() as $name) {
            $this->warnFullyDropped($name, $result->droppedByName[$name]);
        }
    }

    /**
     * The security-fix override is a deliberate relaxation, so every version
     * it admits is reported loudly with the advisories that justified it.
     */
    private function reportSecurityAdmitted(FilterResult $result, ?SecurityFixGate $securityGate): void
    {
        if ($securityGate === null) {
            return;
        }

        foreach ($result->securityAdmittedByName as $name => $packages) {
            foreach ($packages as $package) {
                $this->reportSecurityOverride($package, $securityGate, sprintf('the full %dh soak', $this->hoursFor($name)));
            }
        }
    }

    private function reportSecurityOverride(PackageInterface $package, SecurityFixGate $securityGate, string $insteadOf): void
    {
        $this->io->writeError(sprintf(
            '<warning>[Soak Time] Security override: allowed "%s" %s (%s) after the %dh security window '
            .'instead of %s — it clears %s affecting the locked version.</warning>',
            $package->getName(),
            $package->getPrettyVersion(),
            $this->describeReleaseTime($package),
            $this->config->securityHours,
            $insteadOf,
            implode(', ', array_map(
                static fn ($advisory) => $advisory->advisoryId,
                $securityGate->advisoriesAffectingLocked($package->getName())
            ))
        ));
    }

    private function reportUnverified(FilterResult $result): void
    {
        foreach ($result->unverifiedByRepository as $repositoryName => $packages) {
            $this->io->writeError(sprintf(
                '<warning>[Soak Time] Could not verify the publication date of %d version(s) from %s — '
                .'judged by the self-reported, backdatable "time" field%s</warning>',
                count($packages),
                $repositoryName,
                $this->io->isVerbose() ? ':' : '. Run with -v to list them.'
            ));

            if ($this->io->isVerbose()) {
                foreach ($packages as $package) {
                    $this->io->writeError(sprintf('  - %s (%s)', $package->getPrettyName(), $package->getPrettyVersion()));
                }
            }
        }
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function describeDropped(string $name, array $packages): string
    {
        $lines = [sprintf(
            '  %s — dropped %d version(s) newer than %dh or without a release date:',
            $name,
            count($packages),
            $this->hoursFor($name)
        )];

        foreach ($packages as $package) {
            $lines[] = sprintf('    %s  [%s]', $package->getPrettyVersion(), $this->describeReleaseTime($package));
        }

        return implode("\n", $lines);
    }

    private function describeReleaseTime(PackageInterface $package): string
    {
        $releaseTime = $this->publishedTime->releaseTime($package);

        return match ($releaseTime->source) {
            ReleaseTimeSource::Published => 'published-time '.$releaseTime->date->format('Y-m-d H:i').', server-stamped',
            ReleaseTimeSource::Committer => 'time '.$releaseTime->date->format('Y-m-d H:i').', self-reported (backdatable)',
            ReleaseTimeSource::None => 'no release date',
        };
    }

    /**
     * Summarize which clock each dropped version was judged by. `time` is
     * self-reported and backdatable; the count of versions that fell back to it
     * (because Packagist served no `published-time`) is the residual surface a
     * backdating attacker could still aim at, so it is reported even without -v.
     */
    private function describeSourceBreakdown(FilterResult $result): string
    {
        $published = 0;
        $committer = 0;
        $none = 0;

        foreach ($result->droppedByName as $packages) {
            foreach ($packages as $package) {
                match ($this->publishedTime->releaseTime($package)->source) {
                    ReleaseTimeSource::Published => $published++,
                    ReleaseTimeSource::Committer => $committer++,
                    ReleaseTimeSource::None => $none++,
                };
            }
        }

        $parts = [
            sprintf('%d by server-stamped publish time', $published),
            sprintf('%d by self-reported time%s', $committer, $committer > 0 ? ' (backdatable)' : ''),
        ];

        if ($none > 0) {
            $parts[] = sprintf('%d with no release date', $none);
        }

        return '<info>[Soak Time]   Judged: '.implode(', ', $parts).'.</info>';
    }

    /** The soak window that actually applied to the package, for reporting. */
    private function hoursFor(string $name): int
    {
        return PackageFilter::firstMatch($name, $this->config->packageHours) ?? $this->config->minHours;
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function warnFullyDropped(string $name, array $packages): void
    {
        $newest = $this->newestReleaseDate($packages);
        $ageHours = $newest === null ? null : max(0, intdiv(time() - $newest->getTimestamp(), 3600));

        $this->io->writeError([
            $ageHours === null
                ? sprintf(
                    '<warning>[Soak Time] Every version of "%s" was filtered out '
                    .'because no release date was available.</warning>',
                    $name
                )
                : sprintf(
                    '<warning>[Soak Time] Every version of "%s" was filtered out '
                    .'(newest is only %dh old; soak time is %dh).</warning>',
                    $name,
                    $ageHours,
                    $this->hoursFor($name)
                ),
            '<warning>            If Composer now fails to resolve dependencies, this is the likely cause. Options:</warning>',
            sprintf('<warning>              - Lower its soak time:  SOAK_TIME_PACKAGE_HOURS=%s:%d composer update</warning>', $name, $ageHours ?? 0),
            ...(PackageFilter::firstMatch($name, $this->config->packageHours) === null
                ? [sprintf('<warning>              - Lower all soak times: SOAK_TIME_HOURS=%d composer update</warning>', $ageHours ?? 0)]
                : []),
            sprintf('<warning>              - Whitelist it:         add "%s" to extra.soak-time-whitelist</warning>', $name),
            sprintf('<warning>              - One-run skip:        SOAK_TIME_SKIP=%s composer update</warning>', $name),
        ]);
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function newestReleaseDate(array $packages): ?\DateTimeInterface
    {
        $newest = null;

        foreach ($packages as $package) {
            $releaseDate = $this->publishedTime->releaseTime($package)->date;

            if ($releaseDate !== null && ($newest === null || $releaseDate > $newest)) {
                $newest = $releaseDate;
            }
        }

        return $newest;
    }
}
