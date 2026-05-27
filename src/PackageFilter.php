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

            if (self::matchesWhitelist($package->getName(), $whitelist)) {
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

    /**
     * Invalid patterns are skipped silently here; callers that surface
     * user-supplied config should validate up front and warn.
     *
     * @param  list<string>  $whitelist
     */
    public static function matchesWhitelist(string $name, array $whitelist): bool
    {
        foreach ($whitelist as $pattern) {
            if (self::isValidWhitelistPattern($pattern) && fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vendor must be a literal so a single config line can never silently
     * whitelist every dependency.
     */
    public static function isValidWhitelistPattern(string $pattern): bool
    {
        $parts = explode('/', $pattern);

        if (count($parts) !== 2) {
            return false;
        }

        [$vendor, $name] = $parts;

        if ($vendor === '' || str_contains($vendor, '*')) {
            return false;
        }

        return $name !== '';
    }
}
