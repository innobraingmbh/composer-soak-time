# Security Model

`innobrain/soak-time` defends Composer installs against two supply-chain failures:

- A fresh malicious release that hasn't had time to be noticed.
- An existing version whose source reference, URLs, or archive contents change after it was trusted. Packagist.org blocks this for stable versions ([composer/packagist#1742](https://github.com/composer/packagist/pull/1742), [docs](https://packagist.org/about/version-immutability)); the plugin's pin extends the same protection to dev versions, the local download cache, and non-packagist sources (Satis, private Packagist, VCS, mirrors).

Two layers enforce this: recent versions are dropped from the solver pool, and integrity evidence for every installed `name@version` is recorded in `composer-integrity.lock` and verified on later installs.

## Enforced

- Recent versions are filtered unless whitelisted; so are versions with no release date, except platform packages like `php` and `ext-*`.
- The soak window is global, with optional per-package overrides (`soak-time-package-hours` / `SOAK_TIME_PACKAGE_HOURS`) that shorten or lengthen it for matching packages. Overrides live in the project's own config or the operator's environment — inside the trust boundary — and are the scoped alternative to lowering the global window: every other package keeps the full soak. Versions with no release date are still dropped under an override; only the whitelist bypasses that.
- Versions already pinned in `composer.lock` are exempt from the freshness filter. The soak window gates *adopting* a fresh version during resolution; an already-locked `name@version` was adopted at an earlier `update`/`require`, so re-gating it adds no protection — it would only break `composer install` and partial updates non-deterministically when an unrelated locked dependency happens to be young. The exemption matches exactly on normalized version (a locked `1.0.0` does not exempt a fresh `2.0.0`), and tampering with `composer.lock` is outside this layer's threat model: the artifact behind each locked entry is still bound by the integrity checks. Whoever can rewrite the lock can remove the plugin outright.
- The freshness clock prefers Packagist's server-stamped `published-time` over `time`. `time` is the tag's committer timestamp, which whoever publishes a release can backdate to make a fresh version look mature and slip past the soak window; `published-time` is recorded by the registry at publish and cannot be moved earlier. The field never reaches Composer's package objects, so the plugin reads it back from the repository metadata cache that Composer populates while building the solver pool. It is preferred when present and falls back to `time` otherwise — for older releases predating the field and for non-Packagist sources (Satis, VCS, artifact) that do not emit it — so the change can only tighten the filter, never loosen it.
- Lock entries fail closed: malformed or incomplete data stops the run.
- Source installs are pinned by source reference and URL; dist downloads by archive sha256 when Composer exposes it. A dist install that hides its archive fails closed — reinstall with `--prefer-source`.
- `SOAK_TIME_SKIP` bypasses only freshness; integrity checks still run.

## Security-Fix Window

Soaking cuts both ways: when the locked version of a package is affected by a published security advisory, the full soak window forces the project to keep running known-vulnerable code until the fix ages past it. The historical workaround — rerunning with a globally lowered `SOAK_TIME_HOURS` — shrinks the window for *every* package at once. The security-fix window codifies that dance with a much smaller surface.

A version younger than the soak window is admitted anyway when both hold:

- It has aged past the shorter security window (`soak-time-security-hours` / `SOAK_TIME_SECURITY_HOURS`, default 72h; any value ≥ the soak window disables the override and suppresses all advisory lookups).
- Registry advisory data — the same server-side source `composer audit` reads, queried live from repositories that provide it — shows at least one advisory affecting the locked version of that package, and the candidate version escapes **every** advisory affecting the locked version.

Design properties:

- The relaxation is scoped per package and conditional: it exists only while the locked version is known-vulnerable, and the admitted version still soaks for the security window.
- Advisory data is registry-side metadata, not part of the release an attacker publishes. It cannot be backdated or forged inside the package; influencing it requires getting an advisory published against the victim's locked version, which is loud (GitHub advisories, CVE assignment, downstream scanner alerts).
- Lookups are lazy (one query per package name, only when a fresh version of a locked package would otherwise be dropped) and fail closed: if the advisory API is unreachable, the full soak window applies.
- Every admission is reported as a warning naming the version and the advisory IDs that justified it.

Residual risks, accepted deliberately:

- "Escapes the advisory's affected range" is a weaker claim than "is the security fix". If the locked version has *any* outstanding advisory, a brand-new unrelated version (e.g. a fresh major) also escapes the affected range and qualifies for the shorter window. A compromised maintainer could likewise publish a bogus advisory against existing versions and a "fixed" malicious release. In both cases the malicious version must still soak for the security window, is subject to all integrity checks, and the admission is loudly reported — against the alternative this replaces (a global window reduction), the exposure is strictly smaller.
- The advisory feed is trusted as an input that can only *shorten* the window from the soak default to the security default, never below the configured security window and never for packages without an advisory against the locked version.

## Dev Branches (Mutable Versions)

Stable versions are immutable: Packagist refuses to move a tag after it is indexed, and the plugin hard-fails on any drift from the recorded reference, URL, or archive hash.

Dev versions (`dev-main`, `1.x-dev`, …) are mutable by design — the branch tip advances with every commit. Treating them as immutable would permanently break `composer update` once the branch moves past the first pinned SHA.

The plugin requires an **explicit opt-in** via `extra.soak-time-dev-branches` (or `SOAK_TIME_DEV_BRANCHES`). For declared packages whose version is dev (`$package->isDev() === true`):

- A re-pin is triggered **only** by a change in the git source reference (the content-addressed commit SHA) — the one field an attacker cannot forge without changing the code. The new reference, URLs, and archive sha256 are recorded on that advance.
- If the source reference is **unchanged** but the downloaded archive's sha256 differs, the plugin **still hard-fails** — that is cache poisoning of a fixed SHA, not a branch advance. Source/dist URL changes alone never suppress the sha256 comparison.

Undeclared dev versions behave like stable versions: any drift hard-fails with an error that names `soak-time-dev-branches` so the operator can decide whether to declare the package as mutable or investigate a potential compromise.

## Path Repositories

Packages installed from `path` repositories are exempt from integrity pinning and drift checks. They are local code in the same trust domain as the root project: Composer symlinks or mirrors the directory without downloading an archive, so there is no sha256 and no source reference to pin. Anyone who can tamper with a path dependency already controls the project itself. The soak filter is likewise irrelevant for them — local code has no registry release date.

Because `dist.type` is metadata an attacker-controlled repository can set, the exemption applies only when the dist URL is actually a local filesystem path. A package that claims `type: path` while pointing at a remote URL (`https://…`, `git@…`) is treated as a normal remote package and remains subject to pinning and drift checks.

## Ignored Packages

Some Composer plugins install several dist archives under a single `name@version` — `statamic/cms`, via `pixelfear/composer-dist-plugin`, fetches both `dist.tar.gz` and `dist-frontend.tar.gz` as `statamic/cms@dist`. The integrity model records one set of metadata per `name@version`, so the second archive is indistinguishable from a drifted dist URL and hard-fails.

This is deliberately not auto-handled: relaxing the key to accept multiple dist URLs under one pinned version would mean trusting attacker-influenceable package metadata to vouch for a new URL — exactly the altered-historical-release surface the plugin closes. Instead, `extra.soak-time-integrity-ignore` (or `SOAK_TIME_INTEGRITY_IGNORE`) is an **explicit operator opt-in** that exempts a package from all integrity checks — drift, hash, and recording. A package can only be ignored by editing the project's own config, which is already inside the trust boundary; an attacker who can do that can remove the plugin outright. The exemption is loud: the ignored package(s) are named in a warning on every run, and the soak/freshness filter still applies. Treat additions to this list as a security override and verify the package's installs before adding it.

## Trust Boundary

First install of a version is trust-on-first-use: the plugin records what Composer resolved and downloaded, and that becomes the local source of truth. So commit `composer-integrity.lock` with `composer.lock`, review new entries like dependency updates (especially under `SOAK_TIME_SKIP`), and treat edits or deletions of entries as a security override. The first-seen install relies on Composer, the configured repositories, TLS, and local filesystem integrity.

For dev branches declared in `soak-time-dev-branches`, each `composer update` that advances the branch tip overwrites the previous pin. Review these entries alongside the diff of the dependency itself.

## Out of Scope

The plugin does not prove a package is benign — it does not replace code review, vulnerability scanning, provenance/signature systems, or maintainer trust. A malicious release that ages past the soak window can still be installed if Composer selects it.
