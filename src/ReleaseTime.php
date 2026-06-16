<?php

namespace Innobrain\SoakTime;

/**
 * A package's effective release date together with the provenance of that date,
 * so the filter can both decide and explain why it dropped a version. The date
 * is non-null for Published and Committer, and null for None.
 */
final class ReleaseTime
{
    public function __construct(
        public readonly ?\DateTimeInterface $date,
        public readonly ReleaseTimeSource $source,
    ) {}
}
