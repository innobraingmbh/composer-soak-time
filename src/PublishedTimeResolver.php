<?php

namespace Innobrain\SoakTime;

use Composer\Cache;
use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Package\PackageInterface;
use Composer\Repository\ComposerRepository;

/**
 * Recovers Packagist's `published-time` — the moment the registry recorded the
 * release — for a pooled package. Unlike `time` (the tag's committer timestamp,
 * which whoever publishes can backdate), `published-time` is stamped
 * server-side and cannot be moved earlier to slip a fresh release past the soak
 * window. Preferring it closes the backdating bypass of the freshness filter.
 *
 * Composer's ArrayLoader drops the field, so it never reaches PackageInterface.
 * But the raw p2 metadata that carries it is written to the repository cache
 * while the pool is built — before the soak filter runs — so we read it back
 * from there. Every failure path returns null so the caller falls back to the
 * package's own release date; this can only ever tighten the filter, never
 * loosen it, and leaves non-Packagist sources untouched.
 */
final class PublishedTimeResolver
{
    /** @var array<string, array<string, \DateTimeImmutable>> Parsed provider files, keyed by cache path. */
    private array $byProviderFile = [];

    public function resolve(PackageInterface $package): ?\DateTimeInterface
    {
        $cache = self::cacheFor($package);

        if ($cache === null) {
            return null;
        }

        $name = $package->getName();
        $cacheKey = 'provider-'.strtr($name.($package->isDev() ? '~dev' : ''), '/', '~').'.json';
        $memoKey = $cache->getRoot().$cacheKey;

        if (! array_key_exists($memoKey, $this->byProviderFile)) {
            $raw = $cache->read($cacheKey);
            $this->byProviderFile[$memoKey] = $raw === false ? [] : self::parsePublishedTimes($raw, $name);
        }

        return $this->byProviderFile[$memoKey][$package->getVersion()] ?? null;
    }

    /**
     * Parse a raw provider metadata document into a normalized-version =>
     * published-time map. Versions without a parseable `published-time` are
     * omitted so the caller falls back to the package's own release date —
     * which is exactly what happens for releases predating the field.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public static function parsePublishedTimes(string $raw, string $name): array
    {
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['packages'][$name]) || ! is_array($data['packages'][$name])) {
            return [];
        }

        $versions = $data['packages'][$name];

        if (($data['minified'] ?? null) === 'composer/2.0') {
            $versions = MetadataMinifier::expand($versions);
        }

        $map = [];

        foreach ($versions as $version) {
            if (! is_array($version) || ! isset($version['version_normalized']) || ! is_string($version['version_normalized'])) {
                continue;
            }

            $publishedTime = $version['published-time'] ?? null;

            if (! is_string($publishedTime) || $publishedTime === '') {
                continue;
            }

            try {
                $map[$version['version_normalized']] = new \DateTimeImmutable($publishedTime);
            } catch (\Exception) {
                // Unparseable timestamp: leave it out and fall back to the package's release date.
            }
        }

        return $map;
    }

    /**
     * The metadata cache lives on the ComposerRepository as a protected field
     * with no accessor, so reach it by reflection. Any other repository type
     * (path, VCS, artifact) has no such cache — and no published-time — so it
     * resolves to null and the package keeps its own release date.
     */
    private static function cacheFor(PackageInterface $package): ?Cache
    {
        $repository = $package->getRepository();

        if (! $repository instanceof ComposerRepository) {
            return null;
        }

        try {
            $property = new \ReflectionProperty(ComposerRepository::class, 'cache');
            $property->setAccessible(true);
            $cache = $property->getValue($repository);
        } catch (\Throwable) {
            return null;
        }

        return $cache instanceof Cache ? $cache : null;
    }
}
