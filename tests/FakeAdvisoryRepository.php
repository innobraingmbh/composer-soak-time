<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Package\PackageInterface;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryInterface;

final class FakeAdvisoryRepository implements RepositoryInterface, AdvisoryProviderInterface
{
    public int $fetchCount = 0;

    /**
     * @param  array<string, list<PartialSecurityAdvisory>>  $advisories
     */
    public function __construct(
        private readonly array $advisories = [],
        private readonly bool $throws = false,
    ) {}

    public function hasSecurityAdvisories(): bool
    {
        return true;
    }

    public function getSecurityAdvisories(array $packageConstraintMap, bool $allowPartialAdvisories = false): array
    {
        $this->fetchCount++;

        if ($this->throws) {
            throw new \RuntimeException('advisory API unavailable');
        }

        $found = [];

        foreach ($packageConstraintMap as $name => $constraint) {
            foreach ($this->advisories[$name] ?? [] as $advisory) {
                if ($advisory->affectedVersions->matches($constraint)) {
                    $found[$name][] = $advisory;
                }
            }
        }

        return ['namesFound' => array_keys($found), 'advisories' => $found];
    }

    public function count(): int
    {
        return 0;
    }

    public function hasPackage(PackageInterface $package)
    {
        return false;
    }

    public function findPackage(string $name, $constraint)
    {
        return null;
    }

    public function findPackages(string $name, $constraint = null)
    {
        return [];
    }

    public function getPackages()
    {
        return [];
    }

    public function loadPackages(array $packageNameMap, array $acceptableStabilities, array $stabilityFlags, array $alreadyLoaded = [])
    {
        return ['namesFound' => [], 'packages' => []];
    }

    public function search(string $query, int $mode = 0, ?string $type = null)
    {
        return [];
    }

    public function getProviders(string $packageName)
    {
        return [];
    }

    public function getRepoName()
    {
        return 'fake advisory repo';
    }
}
