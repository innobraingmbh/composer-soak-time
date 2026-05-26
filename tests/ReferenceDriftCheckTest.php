<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Package\Package;
use Innobrain\SoakTime\IntegrityEntry;
use Innobrain\SoakTime\IntegrityLockFile;
use Innobrain\SoakTime\ReferenceDriftCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReferenceDriftCheckTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        $this->lockPath = tempnam(sys_get_temp_dir(), 'soak-drift-').'.json';
        @unlink($this->lockPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        @unlink($this->lockPath.'.tmp');
    }

    #[Test]
    public function it_passes_when_no_entry_is_recorded(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-fresh')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_passes_when_candidate_reference_matches_recorded_one(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-stable', null, new \DateTimeImmutable()
        ));

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-stable')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_throws_when_candidate_reference_differs(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-original', null, new \DateTimeImmutable()
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source reference drift for vendor/pkg@1.0.0');

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-rewritten')]);
    }

    #[Test]
    public function it_skips_entries_without_recorded_source_reference(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), null, null, new \DateTimeImmutable()
        ));

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-anything')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_skips_packages_without_a_candidate_reference(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-stable', null, new \DateTimeImmutable()
        ));

        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');

        (new ReferenceDriftCheck($lock))->verify([$package]);

        $this->addToAssertionCount(1);
    }

    private function package(string $name, string $version, string $sourceReference): Package
    {
        $package = new Package($name, $version.'.0', $version);
        $package->setSourceReference($sourceReference);

        return $package;
    }
}
