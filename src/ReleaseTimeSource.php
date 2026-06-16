<?php

namespace Innobrain\SoakTime;

/**
 * Where a package's effective release date came from.
 *
 * - Published: Packagist's server-stamped `published-time` — tamper-resistant.
 * - Committer: the package's self-reported `time` (the tag's committer
 *   timestamp), which whoever publishes can backdate.
 * - None: no release date was available at all.
 */
enum ReleaseTimeSource
{
    case Published;
    case Committer;
    case None;
}
