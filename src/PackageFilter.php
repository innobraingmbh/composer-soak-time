<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Repository\PlatformRepository;

/**
 * Defends against the new-release attack: a fresh malicious tag (typosquat,
 * account takeover, malicious co-maintainer push). Prefers Packagist's
 * server-stamped `published-time` (via PublishedTimeResolver) and falls back to
 * the `time` field — the committer timestamp of the tag's commit. Because
 * `time` is attacker-controllable, a backdated tag can look old; `published-time`
 * cannot be moved earlier, so it closes that bypass wherever it is available.
 * Neither field defends against altered historical releases — see ReferenceDriftCheck.
 */
final class PackageFilter
{
    public function __construct(
        private readonly PublishedTimeResolver $publishedTime = new PublishedTimeResolver(),
    ) {}

    /**
     * Drop every package version published after the given threshold. Versions
     * with no known release date are also dropped unless explicitly whitelisted.
     *
     * Versions already pinned in composer.lock are exempt: the soak window
     * gates *adopting* a fresh version during resolution, and an already-locked
     * version was adopted earlier. Re-gating it would needlessly break partial
     * updates (`composer require x` failing on an unrelated fresh-but-locked
     * dependency) and `composer install`, without adding protection the
     * integrity checks do not already provide.
     *
     * @param  iterable<PackageInterface>  $packages
     * @param  list<string>  $whitelist
     * @param  array<string, true>  $lockedVersions  keyed by self::lockedKey()
     */
    public function filter(
        iterable $packages,
        \DateTimeInterface $threshold,
        array $whitelist,
        array $lockedVersions = [],
    ): FilterResult {
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

            if (isset($lockedVersions[self::lockedKey($package)])) {
                $kept[] = $package;

                continue;
            }

            $releaseDate = $this->publishedTime->releaseTime($package)->date;

            if ($releaseDate === null || $releaseDate > $threshold) {
                $droppedByName[$package->getName()][] = $package;

                continue;
            }

            $kept[] = $package;
        }

        return new FilterResult($kept, $droppedByName);
    }

    /**
     * Identity key for the locked-version exemption. Normalized version, so the
     * exemption is exact: a locked 1.0.0 does not exempt a fresh 2.0.0.
     */
    public static function lockedKey(PackageInterface $package): string
    {
        return $package->getName().'@'.$package->getVersion();
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
     * A genuine `path` repository always resolves to a local filesystem path.
     * `dist.type` itself is attacker-controlled metadata, so a non-path package
     * served by a malicious repository could claim `type: path` to dodge
     * integrity checks. Only exempt a path package when its dist URL is actually
     * local — i.e. it carries no remote transport (scheme or scp-style host).
     */
    public static function isLocalPathPackage(PackageInterface $package): bool
    {
        if ($package->getDistType() !== 'path') {
            return false;
        }

        $url = (string) $package->getDistUrl();

        if ($url === '') {
            return false;
        }

        if (preg_match('{^[a-z][a-z0-9+.\-]*://}i', $url) === 1) {
            return false;
        }

        return preg_match('{^[^@/\\\\]+@[^:/]+:}', $url) !== 1;
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
