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
use Innobrain\SoakTime\ReleaseTimeSource;
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
    public function it_prefers_the_published_time_composer_loads_onto_the_package(): void
    {
        $package = new FakePublishedPackage('vendor/name', '2.0.0.0', '2.0.0', new \DateTimeImmutable('2026-05-05T00:00:00+00:00'));

        $this->assertEquals(
            new \DateTimeImmutable('2026-05-05T00:00:00+00:00'),
            (new PublishedTimeResolver())->resolve($package)
        );
    }

    #[Test]
    public function it_fails_when_a_composer_repository_serves_no_published_time(): void
    {
        $package = new FakePublishedPackage('vendor/name', '2.0.0.0', '2.0.0', null);
        $package->setRepository($this->fakeRepository(['url' => 'https://repo.example.org']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('vendor/name 2.0.0 from composer repo (https://repo.example.org) has no published-time');

        (new PublishedTimeResolver())->assertProvided($package);
    }

    #[Test]
    public function it_accepts_a_missing_published_time_when_the_repository_opts_out(): void
    {
        $package = new FakePublishedPackage('vendor/name', '2.0.0.0', '2.0.0', null);
        $package->setRepository($this->fakeRepository(['url' => 'https://repo.example.org', 'require-published-time' => false]));

        $this->expectNotToPerformAssertions();

        (new PublishedTimeResolver())->assertProvided($package);
    }

    #[Test]
    public function it_accepts_a_composer_repository_version_with_a_published_time(): void
    {
        $package = new FakePublishedPackage('vendor/name', '2.0.0.0', '2.0.0', new \DateTimeImmutable('-30 days'));
        $package->setRepository($this->fakeRepository(['url' => 'https://repo.example.org']));

        $this->expectNotToPerformAssertions();

        (new PublishedTimeResolver())->assertProvided($package);
    }

    #[Test]
    public function it_keeps_the_time_fallback_on_composer_versions_without_the_setting(): void
    {
        if (method_exists(ComposerRepository::class, 'requiresPublishedTime')) {
            $this->markTestSkipped('This Composer version knows "require-published-time".');
        }

        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');
        $package->setRepository($this->repository('https://repo.example.org'));

        $this->expectNotToPerformAssertions();

        (new PublishedTimeResolver())->assertProvided($package);
    }

    #[Test]
    public function the_filter_fails_for_a_judged_version_without_published_time(): void
    {
        $package = new FakePublishedPackage('vendor/name', '2.0.0.0', '2.0.0', null);
        $package->setReleaseDate(new \DateTimeImmutable('-400 days'));
        $package->setRepository($this->fakeRepository(['url' => 'https://repo.example.org']));

        $this->expectException(\RuntimeException::class);

        (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);
    }

    #[Test]
    public function the_filter_does_not_require_published_time_for_exempt_versions(): void
    {
        $repository = $this->fakeRepository(['url' => 'https://repo.example.org']);
        $whitelisted = new FakePublishedPackage('vendor/trusted', '1.0.0.0', '1.0.0', null);
        $locked = new FakePublishedPackage('vendor/locked', '1.0.0.0', '1.0.0', null);
        $branch = new FakePublishedPackage('vendor/branch', 'dev-main', 'dev-main', null);

        foreach ([$whitelisted, $locked, $branch] as $package) {
            $package->setRepository($repository);
        }

        $result = (new PackageFilter())->filter(
            [$whitelisted, $locked, $branch],
            new \DateTimeImmutable('-168 hours'),
            ['vendor/trusted'],
            [PackageFilter::lockedKey($locked) => true]
        );

        $this->assertSame([$whitelisted, $locked, $branch], $result->keptPackages);
    }

    #[Test]
    public function it_resolves_published_time_from_the_repository_cache(): void
    {
        $this->requireCacheFallback();

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
        $this->requireCacheFallback();

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
    public function release_time_reports_the_server_stamped_source_when_published_time_is_present(): void
    {
        $this->requireCacheFallback();

        $repository = $this->repository('https://repo.example.org');
        $this->writeProvider($repository, 'provider-vendor~name.json', 'vendor/name', [
            ['version_normalized' => '2.0.0.0', 'published-time' => '2026-03-03T00:00:00+00:00'],
        ]);

        // A backdated `time` is present but published-time must win.
        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');
        $package->setReleaseDate(new \DateTimeImmutable('2000-01-01T00:00:00+00:00'));
        $package->setRepository($repository);

        $releaseTime = (new PublishedTimeResolver())->releaseTime($package);

        $this->assertSame(ReleaseTimeSource::Published, $releaseTime->source);
        $this->assertEquals(new \DateTimeImmutable('2026-03-03T00:00:00+00:00'), $releaseTime->date);
    }

    #[Test]
    public function release_time_falls_back_to_the_self_reported_source(): void
    {
        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');
        $package->setReleaseDate(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        $releaseTime = (new PublishedTimeResolver())->releaseTime($package);

        $this->assertSame(ReleaseTimeSource::Committer, $releaseTime->source);
        $this->assertEquals(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), $releaseTime->date);
    }

    #[Test]
    public function release_time_is_none_when_no_date_is_available(): void
    {
        $package = new Package('vendor/name', '2.0.0.0', '2.0.0');

        $releaseTime = (new PublishedTimeResolver())->releaseTime($package);

        $this->assertSame(ReleaseTimeSource::None, $releaseTime->source);
        $this->assertNull($releaseTime->date);
    }

    #[Test]
    public function the_filter_drops_a_version_whose_published_time_is_fresh_despite_an_old_time(): void
    {
        // The package's own release date (Packagist `time`) is backdated to look mature.
        $package = new FakePublishedPackage('vendor/backdated', '9.9.9.0', '9.9.9', new \DateTimeImmutable('-1 hour'));
        $package->setReleaseDate(new \DateTimeImmutable('-400 days'));
        $package->setRepository($this->repository('https://repo.example.org'));

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([], $result->keptPackages);
        $this->assertArrayHasKey('vendor/backdated', $result->droppedByName);
    }

    private function requireCacheFallback(): void
    {
        if (method_exists(Package::class, 'getPublishedDate')) {
            $this->markTestSkipped('Composer 2.11+ loads published-time onto packages; the cache is only read on older versions.');
        }
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
     * @param  array<string, mixed>  $repoConfig
     */
    private function fakeRepository(array $repoConfig): FakeComposerRepository
    {
        $io = new NullIO();
        $config = new Config(false);
        $config->merge(['config' => ['cache-dir' => $this->cacheDir]]);

        return new FakeComposerRepository($repoConfig, $io, $config, new HttpDownloader($io, $config));
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
