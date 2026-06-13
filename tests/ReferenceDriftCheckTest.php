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

    #[Test]
    public function spoofed_path_type_with_remote_dist_url_is_not_exempt_and_hard_fails(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-original', null, null, new \DateTimeImmutable()
        ));

        // A malicious repository claims dist.type=path to dodge the drift check
        // while serving a remote archive. The remote URL must defeat the exemption.
        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $package->setDistType('path');
        $package->setDistUrl('https://evil.example.com/pkg.zip');
        $package->setSourceReference('ref-tampered');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity metadata drift for vendor/pkg@1.0.0');

        (new ReferenceDriftCheck($lock))->verify([$package]);
    }

    #[Test]
    public function stable_version_with_drifted_reference_still_hard_fails(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'sha-original', null, null, new \DateTimeImmutable()
        ));

        // Even if vendor/pkg is listed in devBranches, a stable version must still hard-fail.
        $check = new ReferenceDriftCheck($lock, ['vendor/pkg']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity metadata drift for vendor/pkg@1.0.0');

        $check->verify([$this->package('vendor/pkg', '1.0.0', 'sha-tampered')]);
    }

    #[Test]
    public function undeclared_dev_version_with_drifted_reference_hard_fails_with_dev_hint(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', 'dev-main', null, 'sha-original', null, null, new \DateTimeImmutable()
        ));

        // devBranches is empty — vendor/pkg is NOT declared as a mutable dev branch.
        $check = new ReferenceDriftCheck($lock, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('soak-time-dev-branches');

        $check->verify([$this->devPackage('vendor/pkg', 'dev-main', 'sha-new-commit')]);
    }

    #[Test]
    public function declared_dev_version_with_changed_reference_passes(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', 'dev-main', null, 'sha-original', null, null, new \DateTimeImmutable()
        ));

        $check = new ReferenceDriftCheck($lock, ['vendor/pkg']);

        $check->verify([$this->devPackage('vendor/pkg', 'dev-main', 'sha-new-commit')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function declared_dev_version_with_wildcard_pattern_passes(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', 'dev-main', null, 'sha-original', null, null, new \DateTimeImmutable()
        ));

        $check = new ReferenceDriftCheck($lock, ['vendor/*']);

        $check->verify([$this->devPackage('vendor/pkg', 'dev-main', 'sha-new-commit')]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ignored_package_with_drifted_dist_url_passes(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('a', 64), 'ref-stable', null, 'https://example.com/original.zip', new \DateTimeImmutable()
        ));

        $check = new ReferenceDriftCheck($lock, [], ['vendor/pkg']);

        $package = $this->package('vendor/pkg', '1.0.0', 'ref-stable');
        $package->setDistUrl('https://example.com/rewritten.zip');

        $check->verify([$package]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ignored_package_tolerates_a_second_dist_archive_under_one_version(): void
    {
        // Reproduces the statamic/cms case: pixelfear/composer-dist-plugin pulls
        // two archives (dist.tar.gz, dist-frontend.tar.gz) that both present as
        // statamic/cms@dist. The first is recorded; the second collides on the key.
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'statamic/cms', 'dist', str_repeat('a', 64), null, null,
            'https://github.com/statamic/cms/releases/download/v5.73.24/dist.tar.gz',
            new \DateTimeImmutable()
        ));

        $frontend = new Package('statamic/cms', 'dist', 'dist');
        $frontend->setDistUrl('https://github.com/statamic/cms/releases/download/v5.73.24/dist-frontend.tar.gz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity metadata drift for statamic/cms@dist');

        (new ReferenceDriftCheck($lock))->verify([$frontend]);
    }

    #[Test]
    public function ignored_statamic_tolerates_a_second_dist_archive_under_one_version(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'statamic/cms', 'dist', str_repeat('a', 64), null, null,
            'https://github.com/statamic/cms/releases/download/v5.73.24/dist.tar.gz',
            new \DateTimeImmutable()
        ));

        $frontend = new Package('statamic/cms', 'dist', 'dist');
        $frontend->setDistUrl('https://github.com/statamic/cms/releases/download/v5.73.24/dist-frontend.tar.gz');

        (new ReferenceDriftCheck($lock, [], ['statamic/cms']))->verify([$frontend]);

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

    private function devPackage(string $name, string $version, string $sourceReference): Package
    {
        // For dev-main the normalized version is also "dev-main".
        $package = new Package($name, $version, $version);
        $package->setSourceReference($sourceReference);
        $package->setSourceUrl('https://example.com/'.$name.'.git');
        $package->setDistUrl('https://example.com/'.$name.'/'.$version.'.zip');

        return $package;
    }
}
