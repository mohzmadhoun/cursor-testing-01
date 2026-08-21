#!/usr/bin/env bash
# Brings the WordPress stack up on a freshly booted machine: database, web
# server, and symlinks for whatever plugins the checked-out revision contains.
set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

if [ ! -f "${WP_DIR}/wp-config.php" ]; then
	warn "No WordPress install found at ${WP_DIR}. Run .cursor/install.sh first."
	exit 0
fi

start_mariadb

log "Linking plugins and themes from the repository"
link_repo_content

mkdir -p "${WP_DIR}/wp-content/upgrade" "${WP_DIR}/wp-content/uploads"
touch "${WP_DIR}/wp-content/debug.log"
ensure_htaccess

start_apache

# Cached rewrite rules and object-cache state can reference plugins that this
# revision no longer provides.
wp_cli rewrite flush >/dev/null 2>&1 || true
wp_cli cache flush >/dev/null 2>&1 || true

log "Site check"
curl -fsS -o /dev/null -w '  HTTP %{http_code} in %{time_total}s\n' "http://127.0.0.1:${SITE_PORT}/"

site_summary
