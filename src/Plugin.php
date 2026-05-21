<?php

namespace Cotonet\SoakTime;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    /** @var Composer */
    protected $composer;
    
    /** @var IOInterface */
    protected $io;
    
    /** @var int Minimum package age in hours */
    protected $minHours = 168;

    /** @var array<string> Packages to always allow */
    protected $whitelist = [
        'cotonet/soak-time' // Always whitelist self to prevent lock-out
    ];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $extra = $composer->getPackage()->getExtra();
        
        // Load custom hours if set
        if (isset($extra['soak-time-hours'])) {
            $this->minHours = (int) $extra['soak-time-hours'];
        }

        // Load custom whitelist if set
        if (isset($extra['soak-time-whitelist']) && is_array($extra['soak-time-whitelist'])) {
            // Merge user whitelist with our default whitelist
            $this->whitelist = array_merge($this->whitelist, $extra['soak-time-whitelist']);
        }

        // Environment override (in hours) takes precedence over composer.json
        $envHours = getenv('SOAK_TIME_HOURS');
        if ($envHours !== false && $envHours !== '') {
            $this->minHours = (int) $envHours;
        }
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
        if (getenv('SOAK_TIME_SKIP') === '1') {
            $this->io->write("<warning>[Soak Time] Emergency bypass detected! Skipping filters.</warning>");
            return;
        }

        $this->io->write("<info>[Soak Time] Inspecting packages (requiring minimum age of {$this->minHours} hours)...</info>");

        $packages = $event->getPackages();
        $filteredPackages = [];
        $droppedCount = 0;
        
        $thresholdDate = (new \DateTimeImmutable())->modify("-{$this->minHours} hours");

        foreach ($packages as $package) {
            // Check if the package is in our whitelist
            if (in_array($package->getName(), $this->whitelist, true)) {
                $filteredPackages[] = $package;
                continue;
            }

            $releaseDate = $package->getReleaseDate();
            
            if ($releaseDate !== null && $releaseDate > $thresholdDate) {
                if ($this->io->isVerbose()) {
                    $this->io->write(sprintf(
                        "  - <warning>Dropping %s v%s (released %s)</warning>", 
                        $package->getName(), 
                        $package->getPrettyVersion(), 
                        $releaseDate->format('Y-m-d H:i:s')
                    ));
                }
                $droppedCount++;
                continue;
            }
            $filteredPackages[] = $package;
        }

        if ($droppedCount > 0) {
            $this->io->write("<info>[Soak Time] Successfully filtered out {$droppedCount} recent package version(s).</info>");
        }

        $event->setPackages($filteredPackages);
    }
}
