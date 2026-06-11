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

## Path Repositories

Packages installed from `path` repositories are exempt from integrity pinning and drift checks. They are local code in the same trust domain as the root project: Composer symlinks or mirrors the directory without downloading an archive, so there is no sha256 and no source reference to pin. Anyone who can tamper with a path dependency already controls the project itself. The soak filter is likewise irrelevant for them — local code has no registry release date.

## Trust Boundary

First install of a version is trust-on-first-use: the plugin records what Composer resolved and downloaded, and that becomes the local source of truth. So commit `composer-integrity.lock` with `composer.lock`, review new entries like dependency updates (especially under `SOAK_TIME_SKIP`), and treat edits or deletions of entries as a security override. The first-seen install relies on Composer, the configured repositories, TLS, and local filesystem integrity.

## Out of Scope

The plugin does not prove a package is benign — it does not replace code review, vulnerability scanning, provenance/signature systems, or maintainer trust. A malicious release that ages past the soak window can still be installed if Composer selects it.
