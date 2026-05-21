<?php

namespace Innobrain\SoakTime;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const DEFAULT_MIN_HOURS = 168;

    /** Always whitelist self to prevent locking the plugin out of its own updates. */
    private const SELF = 'innobrain/soak-time';

    private IOInterface $io;

    private SoakTimeConfig $config;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $this->config = $this->resolveConfig($composer->getPackage()->getExtra());
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => 'onPrePoolCreate',
        ];
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        if ($this->config->bypass) {
            $this->io->writeError(
                '<warning>[Soak Time] Emergency bypass active (SOAK_TIME_SKIP=1) — soak time is '
                .'DISABLED for ALL packages in this run, not just the ones named on the command line.</warning>'
            );

            return;
        }

        $this->io->write(sprintf(
            '<info>[Soak Time] Inspecting packages (minimum age: %dh)...</info>',
            $this->config->minHours
        ));

        $threshold = (new \DateTimeImmutable())->modify("-{$this->config->minHours} hours");

        $result = (new PackageFilter())->filter(
            $event->getPackages(),
            $threshold,
            $this->config->whitelist
        );

        $this->report($result);

        $event->setPackages($result->keptPackages);
    }

    /**
     * Resolve the runtime configuration, reporting any invalid input as it goes.
     *
     * The message wording lives here, next to the IO it is written to, rather
     * than inside the config value object.
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
            $whitelist = array_merge($whitelist, array_values($extra['soak-time-whitelist']));
        }

        return new SoakTimeConfig($minHours, $whitelist, getenv('SOAK_TIME_SKIP') === '1');
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

        foreach ($result->fullyDroppedNames() as $name) {
            $this->warnFullyDropped($name, $result->droppedByName[$name]);
        }
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function describeDropped(string $name, array $packages): string
    {
        $versions = array_map(
            static fn (PackageInterface $package): string => $package->getPrettyVersion(),
            $packages
        );

        return sprintf(
            '  %s — dropped %d version(s) newer than %dh: %s (newest released %s)',
            $name,
            count($packages),
            $this->config->minHours,
            implode(', ', $versions),
            $this->newestReleaseDate($packages)->format('Y-m-d H:i')
        );
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function warnFullyDropped(string $name, array $packages): void
    {
        $newest = $this->newestReleaseDate($packages);
        $ageHours = max(0, intdiv(time() - $newest->getTimestamp(), 3600));

        $this->io->writeError([
            sprintf(
                '<warning>[Soak Time] Every version of "%s" was filtered out '
                .'(newest is only %dh old; soak time is %dh).</warning>',
                $name,
                $ageHours,
                $this->config->minHours
            ),
            '<warning>            If Composer now fails to resolve dependencies, this is the likely cause. Options:</warning>',
            sprintf('<warning>              - Lower the soak time:  SOAK_TIME_HOURS=%d composer update</warning>', $ageHours),
            sprintf('<warning>              - Whitelist it:         add "%s" to extra.soak-time-whitelist</warning>', $name),
            '<warning>              - Emergency bypass:     SOAK_TIME_SKIP=1 composer update</warning>',
        ]);
    }

    /**
     * @param  list<PackageInterface>  $packages
     */
    private function newestReleaseDate(array $packages): \DateTimeInterface
    {
        $newest = null;

        foreach ($packages as $package) {
            $releaseDate = $package->getReleaseDate();

            if ($releaseDate !== null && ($newest === null || $releaseDate > $newest)) {
                $newest = $releaseDate;
            }
        }

        return $newest ?? new \DateTimeImmutable();
    }
}
