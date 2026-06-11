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
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-stable', null, null, new \DateTimeImmutable()
        ));

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-stable')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_throws_when_candidate_reference_differs(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-original', null, null, new \DateTimeImmutable()
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity metadata drift for vendor/pkg@1.0.0');

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-rewritten')]);
    }

    #[Test]
    public function it_skips_entries_without_recorded_source_reference(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), null, null, null, new \DateTimeImmutable()
        ));

        (new ReferenceDriftCheck($lock))->verify([$this->package('vendor/pkg', '1.0.0', 'ref-anything')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_throws_when_a_recorded_source_reference_is_missing_from_the_candidate(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-stable', null, null, new \DateTimeImmutable()
        ));

        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity metadata drift for vendor/pkg@1.0.0');

        (new ReferenceDriftCheck($lock))->verify([$package]);
    }

    #[Test]
    public function it_throws_when_the_source_url_differs(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg',
            '1.0.0',
            str_repeat('a', 64),
            'ref-stable',
            'https://example.com/original.git',
            null,
            new \DateTimeImmutable()
        ));

        $package = $this->package('vendor/pkg', '1.0.0', 'ref-stable');
        $package->setSourceUrl('https://example.com/rewritten.git');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field:     source URL');

        (new ReferenceDriftCheck($lock))->verify([$package]);
    }

    #[Test]
    public function it_throws_when_the_dist_url_differs(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg',
            '1.0.0',
            str_repeat('a', 64),
            'ref-stable',
            null,
            'https://example.com/original.zip',
            new \DateTimeImmutable()
        ));

        $package = $this->package('vendor/pkg', '1.0.0', 'ref-stable');
        $package->setDistUrl('https://example.com/rewritten.zip');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Field:     dist URL');

        (new ReferenceDriftCheck($lock))->verify([$package]);
    }

    #[Test]
    public function path_repository_package_is_skipped_even_when_a_recorded_entry_drifts(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', 'dev-master', null, 'ref-from-packagist', null, 'https://example.com/old.zip', new \DateTimeImmutable()
        ));

        // The dependency switched to a local path repository; its stale
        // Packagist entry must not brick updates.
        $package = new Package('vendor/pkg', 'dev-master', 'dev-master');
        $package->setDistType('path');
        $package->setDistUrl('../packages/pkg');

        (new ReferenceDriftCheck($lock))->verify([$package]);

        $this->addToAssertionCount(1);
    }

    private function package(string $name, string $version, string $sourceReference): Package
    {
        $package = new Package($name, $version.'.0', $version);
        $package->setSourceReference($sourceReference);
        $package->setSourceUrl('https://example.com/'.$name.'.git');
        $package->setDistUrl('https://example.com/'.$name.'/'.$version.'.zip');

        return $package;
    }
}
