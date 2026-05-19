# Development & releasing

## Local setup

```bash
composer install
```

## Checks (what CI runs)

```bash
composer lint    # php -l on all plugin source
composer phpcs   # WordPress Coding Standards
composer test    # PHPUnit (pure unit tests, no WordPress needed)
composer check   # all three
```

## Building a distributable zip

```bash
composer build      # or: bash bin/build.sh
```

This writes two files to `dist/`:

- `temso-ai-<version>.zip` — versioned; hand this to a client.
- `temso-ai.zip` — stable name; the asset attached to GitHub releases.

Both contain a top-level `temso-ai/` folder, so a client installs them via
**Plugins → Add New → Upload Plugin** and the plugin lands under the correct
slug. File selection honors `.distignore`.

## Cutting a release (and client auto-updates)

`includes/class-temso-updater.php` lets copies installed *outside* wp.org
update themselves from GitHub releases. `temso-ai.php` ships with:

```php
define( 'TEMSO_GH_REPO', 'Temso-AI/temso-wordpress-plugin' );
```

so this works with no per-site setup. To disable it (e.g. a wordpress.org
build, so WP core's updater stays authoritative), pre-define it empty in
`wp-config.php`: `define( 'TEMSO_GH_REPO', '' );`.

To release:

1. Bump the version in **three** places (a unit test enforces they match):
   `temso-ai.php` header `Version:`, the `TEMSO_VERSION` constant, and
   `readme.txt` `Stable tag`.
2. Commit, then tag and push:
   ```bash
   git tag v0.1.0 && git push origin v0.1.0
   ```
3. CI runs the test suite, builds the package, and publishes a GitHub
   release with `temso-ai.zip` attached. Sites with `TEMSO_GH_REPO` set pick
   up the update within the updater's cache window (~6h, or sooner via
   **Dashboard → Updates**).

The tag must match `TEMSO_VERSION` or the release job fails.
