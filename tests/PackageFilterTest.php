<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Package\Package;
use Composer\Package\PackageInterface;
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
