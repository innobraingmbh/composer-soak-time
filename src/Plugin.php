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
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PostFileDownloadEvent;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const DEFAULT_MIN_HOURS = 168;

    /** Always whitelist self to prevent locking the plugin out of its own updates. */
    private const SELF = 'innobrain/soak-time';

    private IOInterface $io;

    private SoakTimeConfig $config;

    private IntegrityLockFile $lockFile;

    private PublishedTimeResolver $publishedTime;

    /** @var array<string, true> Versions already pinned in composer.lock, keyed by PackageFilter::lockedKey(). */
    private array $lockedVersions = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $this->config = $this->resolveConfig($composer->getPackage()->getExtra());
        $this->lockFile = IntegrityLockFile::load($this->resolveLockPath());
        $this->publishedTime = new PublishedTimeResolver();
        $this->lockedVersions = $this->loadLockedVersions($composer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
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

        if ($this->config->skipAllSoak) {
            $this->io->writeError(
                '<warning>[Soak Time] Soak filter bypass active (SOAK_TIME_SKIP=1) — integrity checks '
                .'remain enabled.</warning>'
            );

            return;
        }

        $this->io->write(sprintf(
            '<info>[Soak Time] Inspecting packages (minimum age: %dh)...</info>',
            $this->config->minHours
        ));

        $threshold = (new \DateTimeImmutable())->modify("-{$this->config->minHours} hours");

        $result = (new PackageFilter($this->publishedTime))->filter(
            $event->getPackages(),
            $threshold,
            $this->config->whitelist,
            $this->lockedVersions
        );

        $this->report($result);

        $event->setPackages($result->keptPackages);
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
     * can exempt them. Empty when there is no lock yet (a first install/require
     * resolves fresh and is gated normally).
     *
     * @return array<string, true>
     */
    private function loadLockedVersions(Composer $composer): array
    {
        $locker = $composer->getLocker();

        if (! $locker->isLocked()) {
            return [];
        }

        $locked = [];

        foreach ($locker->getLockedRepository(true)->getPackages() as $package) {
            $locked[PackageFilter::lockedKey($package)] = true;
        }

        return $locked;
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
        );
    }

    private function report(FilterResult $result): void
    {
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
     * @param  list<PackageInterface>  $packages
     */
    private function describeDropped(string $name, array $packages): string
    {
        $lines = [sprintf(
            '  %s — dropped %d version(s) newer than %dh or without a release date:',
            $name,
            count($packages),
            $this->config->minHours
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
                    $this->config->minHours
                ),
            '<warning>            If Composer now fails to resolve dependencies, this is the likely cause. Options:</warning>',
            sprintf('<warning>              - Lower the soak time:  SOAK_TIME_HOURS=%d composer update</warning>', $ageHours ?? 0),
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
