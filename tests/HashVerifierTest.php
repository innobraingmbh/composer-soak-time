<?php

namespace Innobrain\SoakTime\Tests;

use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PostFileDownloadEvent;
use Innobrain\SoakTime\HashVerifier;
use Innobrain\SoakTime\IntegrityEntry;
use Innobrain\SoakTime\IntegrityLockFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HashVerifierTest extends TestCase
{
    private string $lockPath;

    private string $tarballPath;

    protected function setUp(): void
    {
        $this->lockPath = tempnam(sys_get_temp_dir(), 'soak-lock-').'.json';
        @unlink($this->lockPath);

        $this->tarballPath = tempnam(sys_get_temp_dir(), 'soak-pkg-').'.zip';
        file_put_contents($this->tarballPath, 'fake package contents');
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
        @unlink($this->lockPath.'.tmp');
        @unlink($this->tarballPath);
    }

    #[Test]
    public function it_records_a_new_entry_on_first_sight_tofu(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $verifier = new HashVerifier($lock, new NullIO());

        $verifier->verify($this->event($this->package('vendor/pkg', '1.0.0', 'ref-abc')));

        $entry = $lock->lookup('vendor/pkg', '1.0.0');
        $this->assertNotNull($entry);
        $this->assertSame(hash_file('sha256', $this->tarballPath), $entry->sha256);
        $this->assertSame('ref-abc', $entry->sourceReference);

        // Verification itself does not write to disk — the Plugin saves the
        // lock once at POST_INSTALL_CMD / POST_UPDATE_CMD.
        $this->assertFileDoesNotExist($this->lockPath);
    }

    #[Test]
    public function it_passes_silently_when_hash_matches_recorded_entry(): void
    {
        $sha = hash_file('sha256', $this->tarballPath);
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', $sha, 'ref-abc', null, new \DateTimeImmutable()
        ));
        $verifier = new HashVerifier($lock, new NullIO());

        $verifier->verify($this->event($this->package('vendor/pkg', '1.0.0', 'ref-abc')));

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_throws_when_hash_does_not_match_recorded_entry(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $lock->record(new IntegrityEntry(
            'vendor/pkg', '1.0.0', str_repeat('0', 64), 'ref-abc', null, new \DateTimeImmutable()
        ));
        $verifier = new HashVerifier($lock, new NullIO());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity check FAILED for vendor/pkg@1.0.0');

        $verifier->verify($this->event($this->package('vendor/pkg', '1.0.0', 'ref-abc')));
    }

    #[Test]
    public function it_skips_metadata_downloads(): void
    {
        $lock = IntegrityLockFile::load($this->lockPath);
        $verifier = new HashVerifier($lock, new NullIO());

        $event = new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            $this->tarballPath,
            null,
            'https://example.com/meta.json',
            'metadata',
            ['response' => null, 'repository' => null]
        );

        $verifier->verify($event);

        $this->assertFileDoesNotExist($this->lockPath);
    }

    private function package(string $name, string $version, string $sourceReference): Package
    {
        $package = new Package($name, $version.'.0', $version);
        $package->setSourceReference($sourceReference);
        $package->setDistUrl('https://example.com/'.$name.'/'.$version.'.zip');

        return $package;
    }

    private function event(Package $package): PostFileDownloadEvent
    {
        return new PostFileDownloadEvent(
            PluginEvents::POST_FILE_DOWNLOAD,
            $this->tarballPath,
            $package->getDistSha1Checksum(),
            $package->getDistUrl() ?? '',
            'package',
            $package
        );
    }
}
