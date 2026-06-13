<?php

namespace Innobrain\SoakTime\Tests;

use Composer\IO\NullIO;
use Composer\Package\Package;
use Innobrain\SoakTime\IntegrityEntry;
use Innobrain\SoakTime\IntegrityLockFile;
use Innobrain\SoakTime\PackageIntegrityRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageIntegrityRecorderTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        $this->lockPath = tempnam(sys_get_temp_dir(), 'soak-installed-').'.json';
        @unlink($this->lockPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        @unlink($this->lockPath.'.tmp');
    }

    #[Test]
    public function it_records_a_source_install_when_no_dist_hash_exists(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $recorder = new PackageIntegrityRecorder($lock, new NullIO());

        $recorder->record($this->package('source', 'source-ref'));

        $entry = $lock->lookup('vendor/pkg', '1.0.0');
        $this->assertNotNull($entry);
        $this->assertNull($entry->sha256);
        $this->assertSame('source-ref', $entry->sourceReference);
        $this->assertSame('https://example.com/vendor/pkg.git', $entry->sourceUrl);

        $persisted = IntegrityLockFile::load($this->lockPath)->lookup('vendor/pkg', '1.0.0');
        $this->assertNotNull($persisted);
        $this->assertSame('source-ref', $persisted->sourceReference);
    }

    #[Test]
    public function it_throws_when_a_source_install_has_no_source_reference(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $recorder = new PackageIntegrityRecorder($lock, new NullIO());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot pin integrity for vendor/pkg@1.0.0');

        $recorder->record($this->package('source', null));
    }

    #[Test]
    public function it_throws_when_a_dist_install_was_not_pinned_by_the_download_hook(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $recorder = new PackageIntegrityRecorder($lock, new NullIO());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No dist hash was pinned for vendor/pkg@1.0.0');
        $this->expectExceptionMessage('composer reinstall vendor/pkg --prefer-source');

        $recorder->record($this->package('dist', 'source-ref'));
    }

    #[Test]
    public function it_throws_when_a_dist_install_only_has_a_source_only_pin(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg',
            '1.0.0',
            null,
            'source-ref',
            'https://example.com/vendor/pkg.git',
            'https://example.com/vendor/pkg/1.0.0.zip',
            new \DateTimeImmutable()
        ));
        $recorder = new PackageIntegrityRecorder($lock, new NullIO());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No dist hash was pinned for vendor/pkg@1.0.0');
        $this->expectExceptionMessage('composer global reinstall vendor/pkg --prefer-source');

        $recorder->record($this->package('dist', 'source-ref'));
    }

    #[Test]
    public function path_repository_package_is_not_pinned(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $recorder = new PackageIntegrityRecorder($lock, new NullIO());

        // Reproduces the reported failure: a path-repo dev package has no
        // dist hash and no source reference, so there is nothing to pin.
        $recorder->record($this->pathPackage('dev-master'));

        $this->assertNull($lock->lookup('vendor/pkg', 'dev-master'));
        $this->assertFileDoesNotExist($this->lockPath);
    }

    #[Test]
    public function declared_dev_version_with_changed_reference_gets_repinned(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg',
            'dev-main',
            null,
            'ref-old-sha',
            'https://example.com/vendor/pkg.git',
            null,
            new \DateTimeImmutable()
        ));

        $recorder = new PackageIntegrityRecorder($lock, new NullIO(), ['vendor/pkg']);
        $recorder->record($this->devPackage('source', 'ref-new-sha'));

        $entry = $lock->lookup('vendor/pkg', 'dev-main');
        $this->assertNotNull($entry);
        $this->assertSame('ref-new-sha', $entry->sourceReference);
        $this->assertNull($entry->sha256);

        $persisted = IntegrityLockFile::load($this->lockPath)->lookup('vendor/pkg', 'dev-main');
        $this->assertNotNull($persisted);
        $this->assertSame('ref-new-sha', $persisted->sourceReference);
    }

    #[Test]
    public function undeclared_dev_version_with_changed_reference_hard_fails_with_hint(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg',
            'dev-main',
            null,
            'ref-old-sha',
            null,
            null,
            new \DateTimeImmutable()
        ));

        $recorder = new PackageIntegrityRecorder($lock, new NullIO(), []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('soak-time-dev-branches');

        $recorder->record($this->devPackage('source', 'ref-new-sha'));
    }

    #[Test]
    public function ignored_package_is_not_pinned(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $recorder = new PackageIntegrityRecorder($lock, new NullIO(), [], ['vendor/pkg']);

        // A dist install with no observed hash would normally hard-fail; the
        // ignore list opts the package out before any of that runs.
        $recorder->record($this->package('dist', 'source-ref'));

        $this->assertNull($lock->lookup('vendor/pkg', '1.0.0'));
        $this->assertFileDoesNotExist($this->lockPath);
    }

    private function package(string $installationSource, ?string $sourceReference): Package
    {
        $package = new Package('vendor/pkg', '1.0.0.0', '1.0.0');
        $package->setInstallationSource($installationSource);
        $package->setSourceReference($sourceReference);
        $package->setSourceUrl('https://example.com/vendor/pkg.git');
        $package->setDistUrl('https://example.com/vendor/pkg/1.0.0.zip');

        return $package;
    }

    private function devPackage(string $installationSource, ?string $sourceReference): Package
    {
        $package = new Package('vendor/pkg', 'dev-main', 'dev-main');
        $package->setInstallationSource($installationSource);
        $package->setSourceReference($sourceReference);
        $package->setSourceUrl('https://example.com/vendor/pkg.git');
        $package->setDistUrl('https://example.com/vendor/pkg/dev-main.zip');

        return $package;
    }

    private function pathPackage(string $version): Package
    {
        $package = new Package('vendor/pkg', $version, $version);
        $package->setDistType('path');
        $package->setDistUrl('../packages/pkg');
        $package->setInstallationSource('dist');

        return $package;
    }
}
