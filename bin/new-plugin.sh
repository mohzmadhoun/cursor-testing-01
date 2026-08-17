#!/usr/bin/env bash
# Scaffolds a new plugin in plugins/, links it into the site and activates it.
#
#   bin/new-plugin.sh my-plugin
#   bin/new-plugin.sh my-plugin "My Plugin"
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

slug="${1:-}"
if [ -z "$slug" ]; then
	echo "Usage: bin/new-plugin.sh <plugin-slug> [\"Plugin Name\"]" >&2
	exit 1
fi

if ! [[ "$slug" =~ ^[a-z][a-z0-9-]*$ ]]; then
	echo "The slug must be lowercase letters, digits and hyphens, e.g. my-plugin." >&2
	exit 1
fi

prefix="${slug//-/_}"
upper="$(printf '%s' "$prefix" | tr '[:lower:]' '[:upper:]')"
# my-plugin -> My_Plugin
class_prefix="$(printf '%s' "$slug" | awk -F- '{for(i=1;i<=NF;i++){printf "%s%s", toupper(substr($i,1,1)) substr($i,2), (i<NF?"_":"")}}')"
name="${2:-${class_prefix//_/ }}"

plugin_dir="${REPO_DIR}/plugins/${slug}"
if [ -e "$plugin_dir" ]; then
	echo "plugins/${slug} already exists." >&2
	exit 1
fi

mkdir -p "${plugin_dir}/includes" "${plugin_dir}/assets" "${plugin_dir}/tests"

cat >"${plugin_dir}/${slug}.php" <<PHP
<?php
/**
 * Plugin Name:       ${name}
 * Description:       ${name} for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ${slug}
 *
 * @package ${class_prefix}
 */

defined( 'ABSPATH' ) || exit;

define( '${upper}_VERSION', '0.1.0' );
define( '${upper}_FILE', __FILE__ );
define( '${upper}_PATH', plugin_dir_path( __FILE__ ) );
define( '${upper}_URL', plugin_dir_url( __FILE__ ) );

require_once ${upper}_PATH . 'includes/class-${slug}-plugin.php';

/**
 * Returns the shared plugin instance.
 *
 * @return ${class_prefix}_Plugin
 */
function ${prefix}() {
	return ${class_prefix}_Plugin::instance();
}

${prefix}()->register();
PHP

cat >"${plugin_dir}/includes/class-${slug}-plugin.php" <<PHP
<?php
/**
 * Plugin bootstrap.
 *
 * @package ${class_prefix}
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires ${name} into WordPress.
 */
class ${class_prefix}_Plugin {

	/**
	 * Shared instance.
	 *
	 * @var ${class_prefix}_Plugin|null
	 */
	private static \$instance = null;

	/**
	 * Returns the shared instance.
	 *
	 * @return ${class_prefix}_Plugin
	 */
	public static function instance() {
		if ( null === self::\$instance ) {
			self::\$instance = new self();
		}

		return self::\$instance;
	}

	/**
	 * Registers every hook the plugin uses.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( \$this, 'init' ) );
	}

	/**
	 * Runs on init.
	 *
	 * @return void
	 */
	public function init() {
		load_plugin_textdomain( '${slug}', false, dirname( plugin_basename( ${upper}_FILE ) ) . '/languages' );
	}
}
PHP

cat >"${plugin_dir}/phpunit.xml.dist" <<XML
<?xml version="1.0"?>
<phpunit
		xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/9.6/phpunit.xsd"
		bootstrap="tests/bootstrap.php"
		colors="true"
		beStrictAboutTestsThatDoNotTestAnything="true"
		convertErrorsToExceptions="true"
		convertWarningsToExceptions="true"
		convertNoticesToExceptions="true"
		convertDeprecationsToExceptions="true"
		cacheResultFile="../../.phpunit.cache/${slug}"
		>
	<testsuites>
		<testsuite name="${slug}">
			<directory prefix="test-" suffix=".php">tests</directory>
		</testsuite>
	</testsuites>
	<php>
		<env name="WP_TESTS_DIR" value="/var/www/wp-tests-lib"/>
	</php>
</phpunit>
XML

cat >"${plugin_dir}/tests/bootstrap.php" <<PHP
<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite with this plugin active.
 *
 * @package ${class_prefix}
 */

\$${prefix}_repo_dir = dirname( __DIR__, 3 );

if ( file_exists( \$${prefix}_repo_dir . '/vendor/autoload.php' ) ) {
	require_once \$${prefix}_repo_dir . '/vendor/autoload.php';
}

\$${prefix}_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! \$${prefix}_tests_dir ) {
	\$${prefix}_tests_dir = '/var/www/wp-tests-lib';
}

\$${prefix}_tests_dir = rtrim( \$${prefix}_tests_dir, '/\\\\' );

if ( ! file_exists( \$${prefix}_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite in {\$${prefix}_tests_dir}." . PHP_EOL;
	exit( 1 );
}

require_once \$${prefix}_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress finishes booting.
 */
function ${prefix}_manually_load_plugin() {
	require dirname( __DIR__ ) . '/${slug}.php';
}

tests_add_filter( 'muplugins_loaded', '${prefix}_manually_load_plugin' );

require \$${prefix}_tests_dir . '/includes/bootstrap.php';
PHP

cat >"${plugin_dir}/tests/test-plugin.php" <<PHP
<?php
/**
 * Plugin tests.
 *
 * @package ${class_prefix}
 */

class Test_${class_prefix}_Plugin extends WP_UnitTestCase {

	public function test_plugin_is_loaded() {
		\$this->assertTrue( class_exists( '${class_prefix}_Plugin' ) );
		\$this->assertInstanceOf( ${class_prefix}_Plugin::class, ${prefix}() );
	}
}
PHP

cat >"${plugin_dir}/readme.txt" <<TXT
=== ${name} ===
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

${name} for WordPress.

== Changelog ==

= 0.1.0 =
* Initial release.
TXT

echo "Created plugins/${slug}"

if command -v wp >/dev/null 2>&1 && [ -f /var/www/wordpress/wp-config.php ]; then
	# shellcheck source=../.cursor/lib.sh
	source "${REPO_DIR}/.cursor/lib.sh"
	link_repo_content
	wp_cli plugin activate "$slug" || true
fi

cat <<EOF

Next steps
  Edit      plugins/${slug}/includes/class-${slug}-plugin.php
  Test      bin/test.sh ${slug}
  Lint      composer lint -- plugins/${slug}
EOF
