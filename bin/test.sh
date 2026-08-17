#!/usr/bin/env bash
# Runs the PHPUnit suite of one plugin, or of every plugin that has a
# phpunit.xml.dist when called without arguments.
#
#   bin/test.sh                      # all plugins
#   bin/test.sh hello-cursor         # one plugin
#   bin/test.sh hello-cursor --filter test_greeting
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_DIR"

if [ ! -x vendor/bin/phpunit ]; then
	echo "vendor/bin/phpunit is missing. Run: composer install" >&2
	exit 1
fi

# The test suite recreates its tables from scratch, so the database has to be up.
if ! mariadb-admin ping >/dev/null 2>&1; then
	echo "MariaDB is not running. Run: sudo service mariadb start" >&2
	exit 1
fi

run_plugin() {
	local slug="$1"
	shift
	local config="plugins/${slug}/phpunit.xml.dist"
	if [ ! -f "$config" ]; then
		echo "No ${config}; skipping ${slug}" >&2
		return 0
	fi
	printf '\n==> %s\n' "$slug"
	vendor/bin/phpunit --configuration "$config" "$@"
}

if [ "$#" -gt 0 ] && [ -d "plugins/$1" ]; then
	slug="$1"
	shift
	run_plugin "$slug" "$@"
	exit 0
fi

status=0
found=0
for dir in plugins/*/; do
	slug="$(basename "$dir")"
	[ -f "plugins/${slug}/phpunit.xml.dist" ] || continue
	found=1
	run_plugin "$slug" "$@" || status=1
done

if [ "$found" -eq 0 ]; then
	echo "No plugin with a phpunit.xml.dist was found in plugins/." >&2
fi

exit "$status"
