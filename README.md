# cursor-testing-01

A WordPress plugin development workspace for Cursor Cloud Agents. Every agent that
starts on this repository gets a running WordPress site, WP-CLI, the WordPress
PHPUnit test suite, and the WordPress coding standards, so you can ask it to build
and verify a plugin end to end.

## What the environment gives you

| Piece | Details |
| --- | --- |
| Site | <http://localhost:8080> (port 8080 is forwarded, so it is also reachable from the agent's browser preview) |
| Admin | <http://localhost:8080/wp-admin> — `admin` / `admin`, local-only throwaway credentials |
| WordPress | Latest release, installed at `/var/www/wordpress` with `WP_DEBUG` and pretty permalinks on |
| Stack | Apache 2.4 + PHP 8.3 (mod_php) + MariaDB 10.11 |
| Tools | WP-CLI, Composer, PHPUnit + WordPress test suite, PHPCS + WordPress Coding Standards, PHPStan, Query Monitor |

WordPress core lives outside the repository so it is never committed and survives
branch switches. Only your own code is versioned: everything in `plugins/` (and
`themes/`, if you add it) is symlinked into `wp-content/` on every boot.

## Repository layout

```
.cursor/environment.json   Cloud Agent environment definition
.cursor/install.sh         Builds the stack: packages, database, WordPress, test suite
.cursor/start.sh           Per-boot startup: MariaDB, Apache, plugin symlinks
plugins/hello-cursor/      Reference plugin: settings page, shortcode, REST route, tests
bin/new-plugin.sh          Scaffolds a new plugin
bin/test.sh                Runs the PHPUnit suites
bin/wp-reset.sh            Rebuilds the site from scratch
phpcs.xml.dist             WordPress coding standards ruleset
phpstan.neon.dist          Static analysis configuration
wp-cli.yml                 Points `wp` at the site, so no --path is needed
```

## Asking an agent to build a plugin

Start a Cloud Agent on this repository and describe the plugin. The environment is
already running, so the agent can write code, activate it, and check the result in
the same session. Useful things to include in the request:

- what the plugin should do, and where it appears (admin screen, block, shortcode, REST route, cron job);
- that it should live in `plugins/<slug>/`, generated with `bin/new-plugin.sh <slug>`;
- that it must pass `composer check` (coding standards, static analysis, tests);
- any behaviour worth an integration test, so the agent adds one to `plugins/<slug>/tests/`.

For example: *"Create a plugin `event-countdown` that renders a shortcode counting
down to a date set on a settings page. Cover the date parsing and the shortcode
output with tests, and make `composer check` pass."*

Agents can browse the site themselves, so you can also ask for a screenshot of the
plugin's admin screen or front-end output as proof it works.

## Everyday commands

```bash
bin/new-plugin.sh my-plugin "My Plugin"   # scaffold, symlink and activate a plugin
bin/test.sh                               # PHPUnit for every plugin
bin/test.sh my-plugin --filter test_name  # PHPUnit for one plugin or one test
composer lint                             # WordPress coding standards
composer lint:fix                         # fix what can be fixed automatically
composer analyse                          # PHPStan
composer check                            # lint + analyse + test
bin/wp-reset.sh                           # rebuild the site, keeping your code
```

WP-CLI works from the repository root without extra flags:

```bash
wp plugin list
wp plugin activate my-plugin
wp post create --post_type=page --post_title=Demo --post_status=publish --post_content='[hello_cursor]'
wp eval 'var_dump( get_option( "hello_cursor_settings" ) );'
wp db query 'SELECT option_name FROM wp_options LIMIT 5;'
```

## Writing a plugin

`plugins/hello-cursor` is the reference: a plugin header and constants in the main
file, one class per responsibility under `includes/`, and integration tests under
`tests/`. It registers a settings page, the `[hello_cursor]` shortcode, and
`GET /wp-json/hello-cursor/v1/greeting`, all covered by tests.

```bash
curl 'http://localhost:8080/wp-json/hello-cursor/v1/greeting?name=Ada'
# {"greeting":"Hello, Ada!","name":"Ada"}
```

Tests boot the real WordPress test suite (installed at `/var/www/wp-tests-lib`)
against a separate `wordpress_test` database, so they can create posts and users
and dispatch REST requests without touching the development site.

## Debugging

- `WP_DEBUG_LOG` is on; PHP notices land in `/var/www/wordpress/wp-content/debug.log`, which the **wp-debug-log** terminal tails.
- The **apache-log** terminal tails the Apache access and error logs.
- Query Monitor is active: it reports queries, hooks and PHP issues in the admin bar.
- Xdebug is installed but idle, so requests stay fast. Enable it per command, for example `php -d xdebug.mode=coverage vendor/bin/phpunit`.
- `.cursor/install.sh` keeps a copy of its own output in `/tmp/wordpress-install.log`, which is where to look if the stack did not come up.

## How the environment is defined

`.cursor/environment.json` wires up three phases:

- **install** (`.cursor/install.sh`) runs once when the environment image is built: it installs packages, creates the databases, installs WordPress and the test suite, and runs `composer install`. It is idempotent, so it is also safe to run by hand after changing it.
- **start** (`.cursor/start.sh`) runs on every boot: it starts MariaDB and Apache and re-creates the `wp-content` symlinks for the plugins in the checked-out revision.
- **terminals** tail the WordPress and Apache logs.

To change the stack, edit `.cursor/install.sh`, run it, and confirm the site and
`composer check` still work. New agents pick the change up from the committed file.

The database uses MariaDB's unix socket authentication and the site's admin
password is a local placeholder, so this repository contains no credentials.
