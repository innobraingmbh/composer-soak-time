<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Cache;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Repository\ComposerRepository;
use Composer\Util\Filesystem;
use Composer\Util\HttpDownloader;
use Innobrain\SoakTime\PackageFilter;
use Innobrain\SoakTime\PublishedTimeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PublishedTimeResolverTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/soak-time-test-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->cacheDir);
    }

    #[Test]
    public function it_parses_published_time_from_a_non_minified_document(): void
    {
        $map = PublishedTimeResolver::parsePublishedTimes($this->document([
            ['version_normalized' => '2.0.0.0', 'published-time' => '2026-01-02T03:04:05+00:00'],
        ]), 'vendor/name');

        $this->assertEquals(new \DateTimeImmutable('2026-01-02T03:04:05+00:00'), $map['2.0.0.0']);
    }

    #[Test]
    public function it_expands_and_parses_a_minified_document(): void
    {
        $raw = json_encode([
            'minified' => 'composer/2.0',
            'packages' => [
                'vendor/name' => [
                    ['name' => 'vendor/name', 'version' => '2.0.0', 'version_normalized' => '2.0.0.0', 'published-time' => '2026-02-02T00:00:00+00:00'],
                    ['version' => '1.0.0', 'version_normalized' => '1.0.0.0', 'published-time' => '2025-02-02T00:00:00+00:00'],
                ],
            ],
        ]);

        $map = PublishedTimeResolver::parsePublishedTimes($raw, 'vendor/name');

        $this->assertEquals(new \DateTimeImmutable('2026-02-02T00:00:00+00:00'), $map['2.0.0.0']);
        $this->assertEquals(new \DateTimeImmutable('2025-02-02T00:00:00+00:00'), $map['1.0.0.0']);
    }

    #[Test]
    public function it_omits_versions_that_have_no_published_time(): void
    {
        $map = PublishedTimeResolver::parsePublishedTimes($this->document([
            ['version_normalized' => '2.0.0.0', 'time' => '2026-01-01T00:00:00+00:00'],
        ]), 'vendor/name');

        $this->assertArrayNotHasKey('2.0.0.0', $map);
    }

    #[Test]
    public function it_omits_versions_with_an_unparseable_published_time(): void
    {
        $map = PublishedTimeResolver::parsePublishedTimes($this->document([
            ['version_normalized' => '2.0.0.0', 'published-time' => 'not-a-date'],
        ]), 'vendor/name');

        $this->assertSame([], $map);
    }

    #[Test]
    public function it_returns_nothing_for_a_different_package_name_or_invalid_json(): void
    {
        $doc = $this->document([['version_normalized' => '2.0.0.0', 'published-time' => '2026-01-02T00:00:00+00:00']]);

        $this->assertSame([], PublishedTimeResolver::parsePublishedTimes($doc, 'other/name'));
        $this->assertSame([], PublishedTimeResolver::parsePublishedTimes('{not json', 'vendor/name'));
    }

    #[Test]
    public function it_resolves_published_time_from_the_repository_cache(): void
    {
        $repository = $this->repository('https://repo.example.org');
        $this->writeProvider($repository, 'provider-vendor~name.json', 'vendor/name', [
            ['version_normalized' => '2.0.0.0', 'published-time' => '2026-03-03T00:00:00+00:00'],
        ]);

        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');
        $package->setRepository($repository);

        $this->assertEquals(
            new \DateTimeImmutable('2026-03-03T00:00:00+00:00'),
            (new PublishedTimeResolver())->resolve($package)
        );
    }

    #[Test]
    public function it_reads_dev_versions_from_the_dev_provider_file(): void
    {
        $repository = $this->repository('https://repo.example.org');
        $this->writeProvider($repository, 'provider-vendor~name~dev.json', 'vendor/name', [
            ['version_normalized' => 'dev-main', 'published-time' => '2026-04-04T00:00:00+00:00'],
        ]);

        $package = new Package('vendor/name', 'dev-main', 'dev-main');
        $package->setRepository($repository);

        $this->assertTrue($package->isDev());
        $this->assertEquals(
            new \DateTimeImmutable('2026-04-04T00:00:00+00:00'),
            (new PublishedTimeResolver())->resolve($package)
        );
    }

    #[Test]
    public function it_returns_null_when_the_version_is_absent_from_the_cache(): void
    {
        $repository = $this->repository('https://repo.example.org');
        $this->writeProvider($repository, 'provider-vendor~name.json', 'vendor/name', [
            ['version_normalized' => '1.0.0.0', 'published-time' => '2026-03-03T00:00:00+00:00'],
        ]);

        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');
        $package->setRepository($repository);

        $this->assertNull((new PublishedTimeResolver())->resolve($package));
    }

    #[Test]
    public function it_returns_null_when_the_package_has_no_composer_repository(): void
    {
        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');

        $this->assertNull((new PublishedTimeResolver())->resolve($package));
    }

    #[Test]
    public function the_filter_drops_a_version_whose_published_time_is_fresh_despite_an_old_time(): void
    {
        $repository = $this->repository('https://repo.example.org');
        $this->writeProvider($repository, 'provider-vendor~backdated.json', 'vendor/backdated', [
            ['version_normalized' => '9.9.9.0', 'published-time' => (new \DateTimeImmutable('-1 hour'))->format(DATE_RFC3339)],
        ]);

        // The package's own release date (Packagist `time`) is backdated to look mature.
        $package = new Package('vendor/backdated', '9.9.9.0', '9.9.9');
        $package->setReleaseDate(new \DateTimeImmutable('-400 days'));
        $package->setRepository($repository);

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([], $result->keptPackages);
        $this->assertArrayHasKey('vendor/backdated', $result->droppedByName);
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     */
    private function document(array $versions): string
    {
        return json_encode(['packages' => ['vendor/name' => $versions]]);
    }

    private function repository(string $url): ComposerRepository
    {
        $io = new NullIO();
        $config = new Config(false);
        $config->merge(['config' => ['cache-dir' => $this->cacheDir]]);

        return new ComposerRepository(
            ['url' => $url],
            $io,
            $config,
            new HttpDownloader($io, $config),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     */
    private function writeProvider(ComposerRepository $repository, string $cacheKey, string $name, array $versions): void
    {
        $property = new \ReflectionProperty(ComposerRepository::class, 'cache');
        $property->setAccessible(true);
        $cache = $property->getValue($repository);

        $this->assertInstanceOf(Cache::class, $cache);
        $cache->write($cacheKey, json_encode(['packages' => [$name => $versions]]));
    }
}
