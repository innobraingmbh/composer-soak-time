<?php

namespace Innobrain\SoakTime;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Package\PackageInterface;
use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;

/**
 * Scoped security override for the soak filter. A version that is younger
 * than the soak window may be judged against a shorter security window
 * instead — but only while the currently locked version of that package is
 * affected by a security advisory, and only if the candidate escapes every
 * advisory affecting the locked version. This codifies the "temporarily run
 * with a lower SOAK_TIME_HOURS to pull in a security fix" routine with a much
 * smaller surface: the relaxation is per package, conditional on registry
 * advisory data (the same server-side source `composer audit` reads, not part
 * of the release an attacker publishes), and still enforces a soak.
 *
 * Advisory lookups are lazy: one registry query per package name, made only
 * when a fresh version of a locked package is about to be dropped. A failed
 * lookup fails closed — the version stays subject to the full soak window.
 */
final class SecurityFixGate
{
    /** @var array<string, list<PartialSecurityAdvisory>> Advisories affecting the locked version(s), keyed by package name. */
    private array $advisoriesByName = [];

    /**
     * @param  list<RepositoryInterface>  $repositories
     * @param  array<string, list<string>>  $lockedVersionsByName  Normalized locked versions keyed by package name.
     */
    public function __construct(
        private readonly \DateTimeInterface $threshold,
        private readonly array $repositories,
        private readonly array $lockedVersionsByName,
    ) {}

    /**
     * Whether the package has aged past the security window and clears every
     * advisory affecting its locked version. Requiring all advisories to be
     * cleared (not just one) keeps the rule simple and only ever admits
     * versions the advisory data considers safe to run.
     */
    public function admits(PackageInterface $package, ?\DateTimeInterface $releaseDate): bool
    {
        if ($releaseDate === null || $releaseDate > $this->threshold) {
            return false;
        }

        $advisories = $this->advisoriesAffectingLocked($package->getName());

        if ($advisories === []) {
            return false;
        }

        $candidate = new Constraint('==', $package->getVersion());

        foreach ($advisories as $advisory) {
            if ($advisory->affectedVersions->matches($candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Advisories whose affected range includes a locked version of the
     * package. Fetched lazily and memoized so each name costs at most one
     * advisory query per run.
     *
     * @return list<PartialSecurityAdvisory>
     */
    public function advisoriesAffectingLocked(string $name): array
    {
        return $this->advisoriesByName[$name] ??= $this->fetchAdvisories($name);
    }

    /**
     * @return list<PartialSecurityAdvisory>
     */
    private function fetchAdvisories(string $name): array
    {
        $lockedVersions = $this->lockedVersionsByName[$name] ?? [];

        if ($lockedVersions === []) {
            return [];
        }

        $constraints = array_map(
            static fn (string $version) => new Constraint('==', $version),
            $lockedVersions
        );

        $constraintMap = [$name => MultiConstraint::create($constraints, false)];

        $advisories = [];

        foreach ($this->repositories as $repository) {
            if (! $repository instanceof AdvisoryProviderInterface || ! $repository->hasSecurityAdvisories()) {
                continue;
            }

            try {
                $result = $repository->getSecurityAdvisories($constraintMap, true);
            } catch (\Throwable) {
                // Advisory data unavailable: fail closed — without evidence of
                // a fix, the version stays gated by the full soak window.
                continue;
            }

            foreach ($result['advisories'][$name] ?? [] as $advisory) {
                $advisories[] = $advisory;
            }
        }

        return $advisories;
    }
}
