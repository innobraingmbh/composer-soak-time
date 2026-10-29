<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;

final class FilterResult
{
    /**
     * @param  list<PackageInterface>  $keptPackages
     * @param  array<string, list<PackageInterface>>  $droppedByName
     * @param  array<string, list<PackageInterface>>  $securityAdmittedByName  Versions younger than the soak window kept via the security-fix window because they clear an advisory affecting the locked version.
     * @param  array<string, list<PackageInterface>>  $unverifiedByRepository
     */
    public function __construct(
        public readonly array $keptPackages,
        public readonly array $droppedByName,
        public readonly array $securityAdmittedByName = [],
        public readonly array $unverifiedByRepository = [],
    ) {}

    public function droppedCount(): int
    {
        return array_sum(array_map('count', $this->droppedByName));
    }

    /**
     * Names whose every pooled version was dropped — likely cause when Composer
     * then fails to resolve dependencies.
     *
     * @return list<string>
     */
    public function fullyDroppedNames(): array
    {
        $survivors = [];

        foreach ($this->keptPackages as $package) {
            $survivors[$package->getName()] = true;
        }

        $fullyDropped = [];

        foreach (array_keys($this->droppedByName) as $name) {
            if (! isset($survivors[$name])) {
                $fullyDropped[] = $name;
            }
        }

        return $fullyDropped;
    }
}
