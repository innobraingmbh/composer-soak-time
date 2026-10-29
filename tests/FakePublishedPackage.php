<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Package\Package;

final class FakePublishedPackage extends Package
{
    public function __construct(
        string $name,
        string $version,
        string $prettyVersion,
        private readonly ?\DateTimeImmutable $published,
    ) {
        parent::__construct($name, $version, $prettyVersion);
    }

    public function getPublishedDate(): ?\DateTimeImmutable
    {
        return $this->published;
    }
}
