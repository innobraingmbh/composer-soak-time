<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Semver\VersionParser;
use Innobrain\SoakTime\SecurityFixGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityFixGateTest extends TestCase
{
    #[Test]
    public function it_admits_a_soaked_version_that_clears_an_advisory_affecting_the_locked_version(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')]],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertTrue($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    #[Test]
    public function it_rejects_a_version_younger_than_the_security_window(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')]],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertFalse($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-1 hour')
        ));
    }

    #[Test]
    public function it_rejects_a_version_without_a_release_date(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')]],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertFalse($gate->admits($this->package('vendor/pkg', '2.1.0'), null));
    }

    #[Test]
    public function it_rejects_a_version_still_affected_by_an_advisory_hitting_the_locked_version(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [
                $this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0'),
                $this->advisory('vendor/pkg', 'CVE-2026-0002', '<2.2.0'),
            ]],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertFalse($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
        $this->assertTrue($gate->admits(
            $this->package('vendor/pkg', '2.2.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    #[Test]
    public function it_rejects_when_no_advisory_affects_the_locked_version(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '>=3.0.0,<3.1.0')]],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertFalse($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    #[Test]
    public function it_rejects_a_package_that_is_not_locked(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')]],
            []
        );

        $this->assertFalse($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    #[Test]
    public function it_fails_closed_when_the_advisory_lookup_throws(): void
    {
        $repository = new FakeAdvisoryRepository([], throws: true);

        $gate = new SecurityFixGate(
            new \DateTimeImmutable('-72 hours'),
            [$repository],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $this->assertFalse($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    #[Test]
    public function it_fetches_advisories_at_most_once_per_package_name(): void
    {
        $repository = new FakeAdvisoryRepository([
            'vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')],
        ]);

        $gate = new SecurityFixGate(
            new \DateTimeImmutable('-72 hours'),
            [$repository],
            ['vendor/pkg' => ['2.0.0.0']]
        );

        $gate->admits($this->package('vendor/pkg', '2.1.0'), new \DateTimeImmutable('-100 hours'));
        $gate->admits($this->package('vendor/pkg', '2.1.1'), new \DateTimeImmutable('-100 hours'));

        $this->assertSame(1, $repository->fetchCount);
    }

    #[Test]
    public function it_matches_advisories_against_any_locked_version(): void
    {
        $gate = $this->gate(
            ['vendor/pkg' => [$this->advisory('vendor/pkg', 'CVE-2026-0001', '<2.1.0')]],
            ['vendor/pkg' => ['3.0.0.0', '2.0.0.0']]
        );

        $this->assertTrue($gate->admits(
            $this->package('vendor/pkg', '2.1.0'),
            new \DateTimeImmutable('-100 hours')
        ));
    }

    /**
     * @param  array<string, list<PartialSecurityAdvisory>>  $advisories
     * @param  array<string, list<string>>  $lockedVersionsByName
     */
    private function gate(array $advisories, array $lockedVersionsByName): SecurityFixGate
    {
        return new SecurityFixGate(
            new \DateTimeImmutable('-72 hours'),
            [new FakeAdvisoryRepository($advisories)],
            $lockedVersionsByName
        );
    }

    private function advisory(string $name, string $id, string $affectedVersions): PartialSecurityAdvisory
    {
        return new PartialSecurityAdvisory($name, $id, (new VersionParser())->parseConstraints($affectedVersions));
    }

    private function package(string $name, string $version): PackageInterface
    {
        return new Package($name, $version.'.0', $version);
    }
}
