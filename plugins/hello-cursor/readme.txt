=== Hello Cursor ===
Contributors: cursor
Tags: example, development
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reference plugin for this workspace: a settings page, a shortcode, a REST route and integration tests.

== Description ==

Hello Cursor exists to be copied. It shows how the plugins in this repository are structured:

* `hello-cursor.php` declares the plugin header, constants and activation hooks.
* `includes/` holds one class per responsibility, loaded from the main file.
* `tests/` holds PHPUnit tests that boot the real WordPress test suite.

The greeting word is configurable under Settings -> Hello Cursor and is used by both
the `[hello_cursor]` shortcode and `GET /wp-json/hello-cursor/v1/greeting`.

== Usage ==

Shortcode:

    [hello_cursor name="Ada"]

REST:

    curl http://localhost:8080/wp-json/hello-cursor/v1/greeting?name=Ada

== Changelog ==

= 0.1.0 =
* Initial reference implementation.
