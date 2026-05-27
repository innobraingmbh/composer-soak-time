<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;

/**
 * Defends against the new-release attack: a fresh malicious tag (typosquat,
 * account takeover, malicious co-maintainer push). Filters by Packagist's
 * `time` field — the committer timestamp of the tag's commit. An attacker
 * force-pushing an *old* tag can backdate that timestamp, so this filter is
 * NOT the defense against altered historical releases. See ReferenceDriftCheck.
 */
final class PackageFilter
{
    /**
     * Drop every package version published after the given threshold. Versions
     * with no known release date are also dropped unless explicitly whitelisted.
     *
     * @param  iterable<PackageInterface>  $packages
     * @param  list<string>  $whitelist
     */
    public function filter(iterable $packages, \DateTimeInterface $threshold, array $whitelist): FilterResult
    {
        $kept = [];
        $droppedByName = [];

        foreach ($packages as $package) {
            if ($package instanceof RootPackageInterface) {
                $kept[] = $package;

                continue;
            }

            if (PlatformRepository::isPlatformPackage($package->getName())) {
                $kept[] = $package;

                continue;
            }

            if (in_array($package->getName(), $whitelist, true)) {
                $kept[] = $package;

                continue;
            }

            $releaseDate = $package->getReleaseDate();

            if ($releaseDate === null || $releaseDate > $threshold) {
                $droppedByName[$package->getName()][] = $package;

                continue;
            }

            $kept[] = $package;
        }

        return new FilterResult($kept, $droppedByName);
    }
}
