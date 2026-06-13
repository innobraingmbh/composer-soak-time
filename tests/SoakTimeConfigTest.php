<?php

namespace Innobrain\SoakTime\Tests;

use Innobrain\SoakTime\SoakTimeConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SoakTimeConfigTest extends TestCase
{
    #[Test]
    #[DataProvider('validHours')]
    public function it_parses_valid_hour_values(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, SoakTimeConfig::parseHours($raw));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function validHours(): array
    {
        return [
            'integer' => [360, 360],
            'zero integer' => [0, 0],
            'numeric string' => ['168', 168],
            'zero string' => ['0', 0],
            'padded string' => ['  72  ', 72],
            'leading zeros' => ['007', 7],
        ];
    }

    #[Test]
    #[DataProvider('invalidHours')]
    public function it_rejects_invalid_hour_values(mixed $raw): void
    {
        $this->assertNull(SoakTimeConfig::parseHours($raw));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidHours(): array
    {
        return [
            'non-numeric string' => ['abc'],
            'decimal string' => ['12.5'],
            'float' => [12.5],
            'negative integer' => [-5],
            'negative string' => ['-5'],
            'boolean' => [true],
            'null' => [null],
            'array' => [[]],
            'empty string' => [''],
        ];
    }

    #[Test]
    public function it_exposes_its_resolved_values(): void
    {
        $config = new SoakTimeConfig(72, ['innobrain/soak-time', 'vendor/internal'], true);

        $this->assertSame(72, $config->minHours);
        $this->assertSame(['innobrain/soak-time', 'vendor/internal'], $config->whitelist);
        $this->assertTrue($config->skipAllSoak);
    }

    #[Test]
    public function it_defaults_dev_branches_to_an_empty_array(): void
    {
        $config = new SoakTimeConfig(168, [], false);

        $this->assertSame([], $config->devBranches);
    }

    #[Test]
    public function it_exposes_dev_branches_when_provided(): void
    {
        $config = new SoakTimeConfig(168, [], false, true, 'composer-integrity.lock', ['vendor/pkg', 'vendor/*']);

        $this->assertSame(['vendor/pkg', 'vendor/*'], $config->devBranches);
    }

    #[Test]
    public function it_defaults_integrity_ignore_to_an_empty_array(): void
    {
        $config = new SoakTimeConfig(168, [], false);

        $this->assertSame([], $config->integrityIgnore);
    }

    #[Test]
    public function it_exposes_integrity_ignore_when_provided(): void
    {
        $config = new SoakTimeConfig(168, [], false, true, 'composer-integrity.lock', [], ['statamic/cms']);

        $this->assertSame(['statamic/cms'], $config->integrityIgnore);
    }
}
