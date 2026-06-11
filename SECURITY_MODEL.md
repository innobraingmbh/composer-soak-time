# Security Model

`innobrain/soak-time` defends Composer installs against two supply-chain failures:

- A fresh malicious release that hasn't had time to be noticed.
- An existing version whose source reference, URLs, or archive contents change after it was trusted. Packagist.org blocks this for stable versions ([composer/packagist#1742](https://github.com/composer/packagist/pull/1742), [docs](https://packagist.org/about/version-immutability)); the plugin's pin extends the same protection to dev versions, the local download cache, and non-packagist sources (Satis, private Packagist, VCS, mirrors).

Two layers enforce this: recent versions are dropped from the solver pool, and integrity evidence for every installed `name@version` is recorded in `composer-integrity.lock` and verified on later installs.

## Enforced

- Recent versions are filtered unless whitelisted; so are versions with no release date, except platform packages like `php` and `ext-*`.
- Lock entries fail closed: malformed or incomplete data stops the run.
- Source installs are pinned by source reference and URL; dist downloads by archive sha256 when Composer exposes it. A dist install that hides its archive fails closed — reinstall with `--prefer-source`.
- `SOAK_TIME_SKIP` bypasses only freshness; integrity checks still run.

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

## Trust Boundary

First install of a version is trust-on-first-use: the plugin records what Composer resolved and downloaded, and that becomes the local source of truth. So commit `composer-integrity.lock` with `composer.lock`, review new entries like dependency updates (especially under `SOAK_TIME_SKIP`), and treat edits or deletions of entries as a security override. The first-seen install relies on Composer, the configured repositories, TLS, and local filesystem integrity.

For dev branches declared in `soak-time-dev-branches`, each `composer update` that advances the branch tip overwrites the previous pin. Review these entries alongside the diff of the dependency itself.

## Out of Scope

The plugin does not prove a package is benign — it does not replace code review, vulnerability scanning, provenance/signature systems, or maintainer trust. A malicious release that ages past the soak window can still be installed if Composer selects it.
