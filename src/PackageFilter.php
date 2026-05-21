<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;

final class PackageFilter
{
    /**
     * Drop every package version published after the given threshold,
     * keeping whitelisted packages and versions with no known release date.
     *
     * @param  iterable<PackageInterface>  $packages
     * @param  list<string>  $whitelist
     */
    public function filter(iterable $packages, \DateTimeInterface $threshold, array $whitelist): FilterResult
    {
        $kept = [];
        $droppedByName = [];

        foreach ($packages as $package) {
            if (in_array($package->getName(), $whitelist, true)) {
                $kept[] = $package;

                continue;
            }

            $releaseDate = $package->getReleaseDate();

            if ($releaseDate !== null && $releaseDate > $threshold) {
                $droppedByName[$package->getName()][] = $package;

                continue;
            }

            $kept[] = $package;
        }

        return new FilterResult($kept, $droppedByName);
    }
}
