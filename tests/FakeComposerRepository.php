<?php

namespace Innobrain\SoakTime\Tests;

use Composer\Repository\ComposerRepository;

final class FakeComposerRepository extends ComposerRepository
{
    public function requiresPublishedTime(): bool
    {
        return ($this->getRepoConfig()['require-published-time'] ?? true) !== false;
    }
}
