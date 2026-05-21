# Innobrain Soak Time 🛡️

A Composer plugin designed to mitigate **Supply Chain Attacks** by enforcing a "soak time" (minimum age) on all installed package versions.

Recently published packages or updates can sometimes carry malicious code (zero-days or compromised maintainer accounts). This plugin acts as a shield by completely hiding recent releases from the Composer solver, ensuring you only install mature, community-vetted code.

## 💡 How it works

This plugin intercepts Composer's `PRE_POOL_CREATE` event. It analyzes the release dates of all requested packages (including deep transitive dependencies) and drops any version that is newer than your configured threshold. 

Composer will then gracefully resolve your dependencies using older, safer versions, avoiding the "dependency hell" of manual conflict resolution.

## 📦 Installation

Install this plugin as a development dependency:

```bash
composer require --dev innobrain/soak-time
```

*Or install it globally to protect all your local projects:*
```bash
composer global require innobrain/soak-time
```

## ⚙️ Configuration

By default, the plugin enforces a minimum age of **168 hours (7 days)**. 

Customize this in the `extra` section of your project's `composer.json`:

```json
{
    "extra": {
        "soak-time-hours": 360
    }
}
```

### Overriding the Soak Time per Run

You can override the configured soak time for a single command using the `SOAK_TIME_HOURS` environment variable. This value is specified in **hours** and takes precedence over `soak-time-hours` in `composer.json`:

**Linux / macOS:**
```bash
SOAK_TIME_HOURS=336 composer update
```

**Windows (PowerShell):**
```powershell
$env:SOAK_TIME_HOURS=336; composer update
```

### Whitelisting Packages

Some packages, like security advisories or internal company packages, need to be updated constantly and should bypass the soak time filter. You can allow them permanently by adding an array of package names to `soak-time-whitelist` in your `composer.json`:

```json
{
    "extra": {
        "soak-time-hours": 168,
        "soak-time-whitelist": [
            "roave/security-advisories",
            "your-company/internal-package"
        ]
    }
}
```

## 🚨 Emergency Bypass (Security Patches)

If you need to install a critical security patch that was released just a few hours ago, you can bypass the filter using the `SOAK_TIME_SKIP` environment variable:

**Linux / macOS:**
```bash
SOAK_TIME_SKIP=1 composer update vendor/package-name
```

**Windows (PowerShell):**
```powershell
$env:SOAK_TIME_SKIP=1; composer update vendor/package-name
```

## 🔍 Debugging

To see which versions are being dropped, run Composer with the verbose flag (`-v`):

```bash
composer update -v
```

## 📄 License

This project is licensed under the MIT License.
