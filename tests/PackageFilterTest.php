<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Innobrain\SoakTime\PackageFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageFilterTest extends TestCase
{
    #[Test]
    public function it_drops_packages_newer_than_the_threshold(): void
    {
        $result = (new PackageFilter())->filter(
            [$this->package('vendor/fresh', '2.0.0', '-1 hour')],
            new \DateTimeImmutable('-168 hours'),
            []
        );

        $this->assertSame([], $result->keptPackages);
        $this->assertSame(1, $result->droppedCount());
        $this->assertArrayHasKey('vendor/fresh', $result->droppedByName);
    }

    #[Test]
    public function it_keeps_packages_older_than_the_threshold(): void
    {
        $package = $this->package('vendor/mature', '1.0.0', '-30 days');

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_packages_released_exactly_at_the_threshold(): void
    {
        $threshold = new \DateTimeImmutable();
        $package = $this->package('vendor/edge', '1.0.0', null);
        $package->setReleaseDate($threshold);

        $result = (new PackageFilter())->filter([$package], $threshold, []);

        $this->assertSame([$package], $result->keptPackages);
    }

    #[Test]
    public function it_drops_packages_without_a_release_date(): void
    {
        $package = $this->package('vendor/local', '1.0.0', null);

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([], $result->keptPackages);
        $this->assertSame(1, $result->droppedCount());
        $this->assertArrayHasKey('vendor/local', $result->droppedByName);
    }

    #[Test]
    public function it_keeps_whitelisted_packages_without_a_release_date(): void
    {
        $package = $this->package('vendor/local', '1.0.0', null);

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['vendor/local']
        );

        $this->assertSame([$package], $result->keptPackages);
    }

    #[Test]
    public function it_keeps_platform_packages_without_a_release_date(): void
    {
        $package = $this->package('php', '8.4.0', null);

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_root_packages_without_a_release_date(): void
    {
        $package = new RootPackage('laravel/laravel', '1.0.0.0', '1.0.0+no-version-set');

        $result = (new PackageFilter())->filter([$package], new \DateTimeImmutable('-168 hours'), []);

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_whitelisted_packages_even_when_fresh(): void
    {
        $package = $this->package('vendor/trusted', '3.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['vendor/trusted']
        );

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_a_locked_version_even_when_fresh(): void
    {
        $package = $this->package('vendor/onoffice', '2.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            [],
            [PackageFilter::lockedKey($package) => true]
        );

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_a_locked_version_with_no_release_date(): void
    {
        $package = $this->package('vendor/onoffice', '2.0.0', null);

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            [],
            [PackageFilter::lockedKey($package) => true]
        );

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_still_drops_a_fresh_version_that_differs_from_the_locked_one(): void
    {
        $locked = $this->package('vendor/onoffice', '1.0.0', '-1 hour');
        $bump = $this->package('vendor/onoffice', '2.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$locked, $bump],
            new \DateTimeImmutable('-168 hours'),
            [],
            [PackageFilter::lockedKey($locked) => true]
        );

        $this->assertSame([$locked], $result->keptPackages);
        $this->assertSame(1, $result->droppedCount());
        $this->assertSame([$bump], $result->droppedByName['vendor/onoffice']);
    }

    #[Test]
    public function it_keeps_packages_matching_a_vendor_wildcard(): void
    {
        $package = $this->package('acme/foo', '1.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['acme/*']
        );

        $this->assertSame([$package], $result->keptPackages);
        $this->assertSame(0, $result->droppedCount());
    }

    #[Test]
    public function it_keeps_packages_matching_a_name_suffix_wildcard(): void
    {
        $package = $this->package('acme/lib-internal', '1.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['acme/lib-*']
        );

        $this->assertSame([$package], $result->keptPackages);
    }

    #[Test]
    public function it_does_not_match_a_vendor_wildcard_against_a_different_vendor(): void
    {
        $package = $this->package('other/foo', '1.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['acme/*']
        );

        $this->assertSame([], $result->keptPackages);
        $this->assertSame(1, $result->droppedCount());
    }

    #[Test]
    public function it_treats_regex_metacharacters_in_whitelist_entries_as_literals(): void
    {
        $package = $this->package('vendor/a.b', '1.0.0', '-1 hour');

        $result = (new PackageFilter())->filter(
            [$package],
            new \DateTimeImmutable('-168 hours'),
            ['vendor/aXb']
        );

        $this->assertSame([], $result->keptPackages);
    }

    #[Test]
    public function it_rejects_a_bare_wildcard_pattern(): void
    {
        $this->assertFalse(PackageFilter::isValidWhitelistPattern('*'));
        $this->assertFalse(PackageFilter::matchesWhitelist('vendor/anything', ['*']));
    }

    #[Test]
    public function it_rejects_a_pattern_with_a_wildcard_in_the_vendor_half(): void
    {
        $this->assertFalse(PackageFilter::isValidWhitelistPattern('*/internal'));
        $this->assertFalse(PackageFilter::isValidWhitelistPattern('*/*'));
        $this->assertFalse(PackageFilter::isValidWhitelistPattern('ac*me/foo'));
        $this->assertFalse(PackageFilter::matchesWhitelist('vendor/anything', ['*/anything']));
    }

    #[Test]
    public function it_accepts_patterns_with_a_literal_vendor(): void
    {
        $this->assertTrue(PackageFilter::isValidWhitelistPattern('acme/foo'));
        $this->assertTrue(PackageFilter::isValidWhitelistPattern('acme/*'));
        $this->assertTrue(PackageFilter::isValidWhitelistPattern('vendor/foo-*'));
    }

    #[Test]
    public function it_matches_a_wildcard_entry_that_is_not_first_in_the_list(): void
    {
        $this->assertTrue(PackageFilter::matchesWhitelist(
            'acme/foo',
            ['unrelated/exact', 'other/*', 'acme/*']
        ));
    }

    #[Test]
    public function an_empty_whitelist_matches_nothing(): void
    {
        $this->assertFalse(PackageFilter::matchesWhitelist('vendor/anything', []));
    }

    #[Test]
    public function it_reports_a_package_whose_versions_were_all_dropped(): void
    {
        $result = (new PackageFilter())->filter(
            [
                $this->package('vendor/fresh', '2.0.0', '-1 hour'),
                $this->package('vendor/fresh', '2.0.1', '-2 hours'),
            ],
            new \DateTimeImmutable('-168 hours'),
            []
        );

        $this->assertSame(['vendor/fresh'], $result->fullyDroppedNames());
        $this->assertSame(2, $result->droppedCount());
    }

    #[Test]
    public function it_does_not_report_a_package_that_still_has_a_surviving_version(): void
    {
        $result = (new PackageFilter())->filter(
            [
                $this->package('vendor/mixed', '2.0.0', '-1 hour'),
                $this->package('vendor/mixed', '1.9.0', '-90 days'),
            ],
            new \DateTimeImmutable('-168 hours'),
            []
        );

        $this->assertSame([], $result->fullyDroppedNames());
        $this->assertSame(1, $result->droppedCount());
        $this->assertCount(1, $result->keptPackages);
    }

    #[Test]
    public function it_groups_dropped_versions_by_package_name(): void
    {
        $result = (new PackageFilter())->filter(
            [
                $this->package('vendor/a', '2.0.0', '-1 hour'),
                $this->package('vendor/a', '2.0.1', '-2 hours'),
                $this->package('vendor/b', '5.0.0', '-3 hours'),
            ],
            new \DateTimeImmutable('-168 hours'),
            []
        );

        $this->assertCount(2, $result->droppedByName['vendor/a']);
        $this->assertCount(1, $result->droppedByName['vendor/b']);
        $this->assertSame(3, $result->droppedCount());
    }

    private function package(string $name, string $version, ?string $releasedAgo): PackageInterface
    {
        $package = new Package($name, $version.'.0', $version);

        if ($releasedAgo !== null) {
            $package->setReleaseDate(new \DateTimeImmutable($releasedAgo));
        }

        return $package;
    }
}
