<?php

namespace Innobrain\SoakTime\Tests;

use Innobrain\SoakTime\IntegrityEntry;
use Innobrain\SoakTime\IntegrityLockFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntegrityLockFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'soak-integrity-').'.json';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.tmp');
    }

    #[Test]
    public function it_returns_empty_when_file_does_not_exist(): void
    {
        $lock = IntegrityLockFile::load($this->path);

        $this->assertNull($lock->lookup('vendor/pkg', '1.0.0'));
    }

    #[Test]
    public function it_records_and_looks_up_an_entry(): void
    {
        $lock = IntegrityLockFile::load($this->path);

        $lock->record($this->entry('vendor/pkg', '1.0.0', str_repeat('a', 64)));

        $found = $lock->lookup('vendor/pkg', '1.0.0');

        $this->assertNotNull($found);
        $this->assertSame(str_repeat('a', 64), $found->sha256);
    }

    #[Test]
    public function it_persists_entries_across_load_and_save(): void
    {
        $lock = IntegrityLockFile::load($this->path);
        $lock->record($this->entry('vendor/pkg', '1.2.3', str_repeat('b', 64), 'abc123ref'));
        $lock->save();

        $reloaded = IntegrityLockFile::load($this->path);
        $found = $reloaded->lookup('vendor/pkg', '1.2.3');

        $this->assertNotNull($found);
        $this->assertSame(str_repeat('b', 64), $found->sha256);
        $this->assertSame('abc123ref', $found->sourceReference);
    }

    #[Test]
    public function it_writes_atomically_via_tmp_rename(): void
    {
        $lock = IntegrityLockFile::load($this->path);
        $lock->record($this->entry('vendor/pkg', '1.0.0', str_repeat('c', 64)));
        $lock->save();

        $this->assertFileExists($this->path);
        $this->assertFileDoesNotExist($this->path.'.tmp');
    }

    #[Test]
    public function it_throws_when_the_lock_file_is_malformed_json(): void
    {
        file_put_contents($this->path, '{ this is not json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integrity lock file is malformed JSON');

        IntegrityLockFile::load($this->path);
    }

    #[Test]
    public function it_ignores_malformed_entries_when_loading(): void
    {
        file_put_contents($this->path, json_encode([
            '_version' => 1,
            'packages' => [
                'vendor/pkg' => [
                    '1.0.0' => ['sha256' => '', 'firstSeenAt' => '2026-01-01T00:00:00+00:00'],
                    '1.0.1' => ['sha256' => 'valid', 'firstSeenAt' => 'not-a-date'],
                    '1.0.2' => ['sha256' => 'valid', 'firstSeenAt' => '2026-01-02T00:00:00+00:00'],
                ],
            ],
        ]));

        $lock = IntegrityLockFile::load($this->path);

        $this->assertNull($lock->lookup('vendor/pkg', '1.0.0'));
        $this->assertNull($lock->lookup('vendor/pkg', '1.0.1'));
        $this->assertNotNull($lock->lookup('vendor/pkg', '1.0.2'));
    }

    private function entry(string $name, string $version, string $sha256, ?string $ref = null): IntegrityEntry
    {
        return new IntegrityEntry(
            $name,
            $version,
            $sha256,
            $ref,
            'https://example.com/'.$name.'/'.$version.'.zip',
            new \DateTimeImmutable('2026-05-26T12:00:00+00:00'),
        );
    }
}
