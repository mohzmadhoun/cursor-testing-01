#!/usr/bin/env bash
# Rebuilds the development site from scratch: drops the database, reinstalls
# WordPress, relinks the repository plugins and activates them again.
#
# Use it after an experiment leaves the site in a state you no longer trust.
# Only the site content is lost; nothing in the repository is touched.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=../.cursor/lib.sh
source "${REPO_DIR}/.cursor/lib.sh"

if [ "${1:-}" != "--yes" ]; then
	printf 'This deletes every post, user and option in the %s database. Continue? [y/N] ' "$DB_NAME"
	read -r reply
	case "$reply" in
		y | Y | yes | YES) ;;
		*)
			echo "Aborted."
			exit 1
			;;
	esac
fi

start_mariadb

log "Dropping and recreating the ${DB_NAME} database"
wp_cli db reset --yes

log "Reinstalling WordPress"
wp_cli core install \
	--url="http://localhost:${SITE_PORT}" \
	--title="$WP_SITE_TITLE" \
	--admin_user="$WP_ADMIN_USER" \
	--admin_password="$WP_ADMIN_PASSWORD" \
	--admin_email="$WP_ADMIN_EMAIL" \
	--skip-email

wp_cli rewrite structure '/%postname%/'
wp_cli option update timezone_string 'UTC'
ensure_htaccess

# The reset dropped WooCommerce's tables, settings, pages and products along with
# everything else, so rebuild the store too.
install_woocommerce

link_repo_content

log "Activating plugins from the repository"
for dir in "${REPO_DIR}"/plugins/*/; do
	[ -d "$dir" ] || continue
	wp_cli plugin activate "$(basename "$dir")" || warn "Could not activate $(basename "$dir")"
done

wp_cli plugin activate query-monitor >/dev/null 2>&1 || true

site_summary
