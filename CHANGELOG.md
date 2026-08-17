# Changelog

A running record of the work done on this repository, newest first. Every pull
request gets an entry describing what changed, why, how it was verified, and the
commits it contains.

## How to add an entry

Add a new `##` section at the top for the pull request you are working on, using
the heading `## PR #<number> — <title>` followed by the date. Inside it, keep the
subsections that apply and drop the ones that do not:

- **Summary** — what the change accomplishes, in a sentence or two.
- **Added / Changed / Fixed / Removed** — the substance of the change.
- **Verification** — the checks that were run and their results, so a later
  reader can tell what was actually proven rather than assumed.
- **Notes** — decisions, trade-offs, and anything surprising that a future
  contributor would otherwise have to rediscover.
- **Commits** — each commit hash with its subject line.

Write for someone returning to this repository months from now with no memory of
the session. Prefer a sentence that explains a decision over a bullet that only
restates the diff.

---

## PR #1 — WordPress plugin development environment for Cloud Agents

_2026-08-17_

### Summary

Turned an empty repository into a WordPress plugin development workspace. A Cloud
Agent started here boots with a running WordPress site and the tooling to write,
test, and lint a plugin in the same session, so plugin work can be requested
directly of an agent without any local setup.

### Added

**Environment definition (`.cursor/`)**

- `environment.json` declares the environment: runs as `ubuntu`, installs with
  `install.sh`, starts with `start.sh`, forwards port 8080, and opens two
  terminals that tail the WordPress debug log and the Apache logs.
- `install.sh` builds the stack once: Apache 2.4, PHP 8.3 with the extensions
  WordPress expects, MariaDB 10.11, WP-CLI 2.12.0, Composer, WordPress itself at
  `/var/www/wordpress`, the WordPress core PHPUnit test suite at
  `/var/www/wp-tests-lib`, Query Monitor, and the repository's Composer
  dependencies. It is idempotent and keeps a copy of its output in
  `/tmp/wordpress-install.log`.
- `start.sh` runs on every boot: starts MariaDB and Apache, recreates the
  `wp-content` symlinks for the plugins in the checked-out revision, prunes links
  to directories that no longer exist, and checks that the site answers.
- `lib.sh` holds the helpers both scripts share, including the service startup
  that tolerates stale pidfiles and the `.htaccess` rewrite block.

**Tooling**

- `composer.json` with PHPUnit 9.6, PHP_CodeSniffer with the WordPress Coding
  Standards, PHPCompatibilityWP, PHPStan, and the WordPress stubs. `composer check`
  runs lint, static analysis, and tests together.
- `phpcs.xml.dist` applies the `WordPress` standard to `plugins/`, targeting
  WordPress 6.5 and PHP 8.1 and up, relaxing only the shipped-code documentation
  and output-escaping rules for test files.
- `phpstan.neon.dist` analyses `plugins/` at level 5 with WordPress stubs.
- `bin/new-plugin.sh` scaffolds a plugin, symlinks it into the site, and activates
  it. `bin/test.sh` runs one or every plugin's PHPUnit suite. `bin/wp-reset.sh`
  rebuilds the site without touching repository code.
- `wp-cli.yml` points WP-CLI at the site so `wp` works from the repository root.

**Reference plugin (`plugins/hello-cursor`)**

A plugin meant to be copied, showing the structure the workspace expects: the
plugin header and constants in the main file, one class per responsibility under
`includes/`, and integration tests under `tests/`. It implements a settings page,
the `[hello_cursor]` shortcode, and `GET /wp-json/hello-cursor/v1/greeting`, all
driven by the same configurable greeting.

**Documentation**

`README.md` explains what the environment provides, how to ask an agent for a
plugin, and the commands for testing, linting, debugging, and resetting the site.

### Verification

| Check | Result |
| --- | --- |
| Home page, admin, pretty permalinks | HTTP 200 |
| Shortcode on a published page | Renders `Hello, Ada!` with the plugin stylesheet enqueued |
| REST route | `{"greeting":"Hello, Ada!","name":"Ada"}`, and follows the settings value |
| Admin round-trip in a browser | Changed the greeting to `Howdy` in Settings → Hello Cursor, saw it on the front end, restored it |
| PHPUnit | 14 tests, 19 assertions, all passing |
| PHPCS and PHPStan level 5 | Clean |
| `bin/new-plugin.sh` output | Passes tests, lint, and static analysis unchanged |
| Install idempotence | Passed twice; the second run takes about two seconds |
| Full stop, then `start.sh` alone | Site back to HTTP 200, tests still pass |
| Fresh Cloud Agent on the branch | Stack ready 80 seconds after boot with no manual step; every check above reproduced, `debug.log` and the Apache error log clean |

### Notes

- **WordPress core is deliberately outside the repository.** It lives at
  `/var/www/wordpress` so it survives checkouts and is never committed. Only
  `plugins/` (and `themes/`, if added) is versioned, and it is symlinked into
  `wp-content/` on each boot. This is why per-boot symlink reconciliation belongs
  in `start.sh` rather than `install.sh`.
- **No credentials are stored anywhere.** The database account is authenticated
  by MariaDB's `unix_socket` plugin, and the site's admin password is a local
  placeholder. The trade-off is that Apache, WP-CLI, and PHPUnit must all run as
  the same non-root user that installed the site, which is why `environment.json`
  pins `"user": "ubuntu"` and `install.sh` refuses to run as root.
- **WP-CLI never writes the `.htaccess` rewrite block.** It runs under the CLI
  SAPI, so WordPress does not detect Apache; `wp rewrite structure` silently skips
  the file. Pretty permalinks and `/wp-json/` both returned 404 until
  `ensure_htaccess` was added.
- **Composer needs a GitHub token.** Anonymous clients get 60 requests an hour
  and this dependency tree needs about 45, which reliably tripped a 429. The
  install reuses whichever token the machine already has, never writes it to disk,
  and falls back to throttled retries and `--prefer-source`.
- **PHPStan cannot see constants defined from function calls.** A plugin defining
  `MY_PLUGIN_URL` with `plugin_dir_url( __FILE__ )` and using it from another file
  looks undefined. `bin/phpstan-bootstrap.php` declares those names for every
  plugin automatically, so the false positive does not return with each new plugin.
- **Prebuilt environment builds were not available.** Two draft builds failed
  within 30 seconds with no logs, which looked platform-side rather than caused by
  these scripts. Boots therefore run `install.sh`, measured at 80 seconds on a
  clean machine. Enabling Environment Builds later would bake that into the base
  image.

### Commits

| Commit | Subject |
| --- | --- |
| `a9ebd8a` | Add Cloud Agent environment for WordPress development |
| `1c97b7c` | Add plugin development tooling |
| `6f1a444` | Add reference plugin and workspace documentation |
| `99c2766` | Throttle Composer downloads when no GitHub token is available |
| `d479a2f` | Fail clearly when install runs as root |
| `92f0e61` | Keep a copy of the install output |
