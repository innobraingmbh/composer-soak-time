<?php

namespace Innobrain\SoakTime;

use Composer\Package\PackageInterface;

final class FilterResult
{
    /**
     * @param  list<PackageInterface>  $keptPackages
     * @param  array<string, list<PackageInterface>>  $droppedByName
     */
    public function __construct(
        public readonly array $keptPackages,
        public readonly array $droppedByName,
    ) {}

    public function droppedCount(): int
    {
        return array_sum(array_map('count', $this->droppedByName));
    }

    /**
     * Names whose every pooled version was dropped — the likely cause when
     * Composer then fails to resolve dependencies.
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
