<?php

namespace Innobrain\SoakTime;

use Composer\Package\AliasPackage;
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
     * When a security gate is given, a version that misses the threshold gets
     * one more chance: it is kept if it has aged past the gate's shorter
     * security window and clears every advisory affecting the locked version
     * of its package (see SecurityFixGate).
     *
     * Per-package thresholds replace the default threshold for packages
     * matching their pattern (whitelist pattern rules; first match wins), so a
     * single package's soak window can be shortened or lengthened without
     * touching the global one.
     *
     * @param  iterable<PackageInterface>  $packages
     * @param  list<string>  $whitelist
     * @param  array<string, true>  $lockedVersions  keyed by self::lockedKey()
     * @param  array<string, \DateTimeInterface>  $packageThresholds  keyed by package-name pattern
     */
    public function filter(
        iterable $packages,
        ?\DateTimeInterface $threshold,
        array $whitelist,
        array $lockedVersions = [],
        ?SecurityFixGate $securityGate = null,
        array $packageThresholds = [],
    ): FilterResult {
        $kept = [];
        $droppedByName = [];
        $securityAdmittedByName = [];
        $unverifiedByRepository = [];

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

            // An alias reports its own stability, so judge the package it aliases to keep the pair consistent.
            $target = $package instanceof AliasPackage ? $package->getAliasOf() : $package;

            if ($target->isDev()) {
                $kept[] = $package;

                continue;
            }

            $effectiveThreshold = self::firstMatch($package->getName(), $packageThresholds) ?? $threshold;

            if ($effectiveThreshold === null) {
                $kept[] = $package;

                continue;
            }

            $this->publishedTime->assertProvided($target);

            $releaseTime = $this->publishedTime->releaseTime($package);
            $releaseDate = $releaseTime->date;

            if ($releaseTime->source === ReleaseTimeSource::Committer && ! $package instanceof AliasPackage && $package->getRepository() !== null) {
                $unverifiedByRepository[$package->getRepository()->getRepoName()][] = $package;
            }

            if ($releaseDate === null || $releaseDate > $effectiveThreshold) {
                if ($securityGate !== null && $securityGate->admits($package, $releaseDate)) {
                    $kept[] = $package;
                    $securityAdmittedByName[$package->getName()][] = $package;

                    continue;
                }

                $droppedByName[$package->getName()][] = $package;

                continue;
            }

            $kept[] = $package;
        }

        return new FilterResult($kept, $droppedByName, $securityAdmittedByName, $unverifiedByRepository);
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
     * Value of the first pattern-keyed entry matching the package name, null
     * when none matches. Same pattern rules as the whitelist; invalid patterns
     * are skipped silently here, so callers that surface user-supplied config
     * should validate up front and warn.
     *
     * @template T
     *
     * @param  array<string, T>  $byPattern
     * @return T|null
     */
    public static function firstMatch(string $name, array $byPattern): mixed
    {
        foreach ($byPattern as $pattern => $value) {
            if (self::isValidWhitelistPattern($pattern) && fnmatch($pattern, $name)) {
                return $value;
            }
        }

        return null;
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
