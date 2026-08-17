#!/usr/bin/env bash
# Shared helpers for the WordPress Cloud Agent environment.
# Sourced by .cursor/install.sh and .cursor/start.sh.

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# WordPress core lives outside the repository so that it survives repository
# checkouts and is never committed. Only the code in plugins/ and themes/ is
# versioned; it is symlinked into the site.
WP_DIR="${WP_DIR:-/var/www/wordpress}"
WP_TESTS_LIB="${WP_TESTS_LIB:-/var/www/wp-tests-lib}"
SITE_PORT="${SITE_PORT:-8080}"

DB_NAME="${DB_NAME:-wordpress}"
DB_TEST_NAME="${DB_TEST_NAME:-wordpress_test}"
# MariaDB authenticates this account through the unix socket, matching the OS
# user, so no database password exists to store or leak.
DB_USER="$(id -un)"

WP_ADMIN_USER="${WP_ADMIN_USER:-admin}"
WP_ADMIN_PASSWORD="${WP_ADMIN_PASSWORD:-admin}"
WP_ADMIN_EMAIL="${WP_ADMIN_EMAIL:-admin@example.com}"
WP_SITE_TITLE="${WP_SITE_TITLE:-WordPress Plugin Dev}"

log() { printf '\n\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m warn:\033[0m %s\n' "$*" >&2; }

wp_cli() {
	wp --path="$WP_DIR" "$@"
}

mariadb_is_up() {
	mariadb-admin ping >/dev/null 2>&1
}

start_mariadb() {
	if mariadb_is_up; then
		return 0
	fi
	log "Starting MariaDB"
	sudo service mariadb start >/dev/null 2>&1 ||
		sudo service mariadb restart >/dev/null 2>&1 ||
		true
	for _ in $(seq 1 60); do
		if mariadb_is_up; then
			return 0
		fi
		sleep 1
	done
	echo "MariaDB did not become ready in time" >&2
	sudo tail -n 40 /var/log/mysql/error.log >&2 2>/dev/null || true
	return 1
}

apache_is_up() {
	curl -fsS -o /dev/null --max-time 5 "http://127.0.0.1:${SITE_PORT}/" 2>/dev/null
}

start_apache() {
	if apache_is_up; then
		log "Apache is already serving port ${SITE_PORT}"
		return 0
	fi

	log "Starting Apache on port ${SITE_PORT}"
	local pidfile='/var/run/apache2/apache2.pid'
	# An unclean shutdown, or booting from a snapshot, can leave a pidfile whose
	# process no longer exists; the init script then refuses to start.
	if [ -f "$pidfile" ] && ! sudo kill -0 "$(cat "$pidfile")" 2>/dev/null; then
		sudo rm -f "$pidfile"
	fi
	# Processes without a matching pidfile are equally fatal to the init script,
	# and they cannot be serving requests, or the check above would have passed.
	if pgrep -x apache2 >/dev/null 2>&1; then
		sudo pkill -x apache2 || true
		sleep 2
	fi

	sudo service apache2 start >/dev/null 2>&1 || true
	for _ in $(seq 1 20); do
		if apache_is_up; then
			return 0
		fi
		sleep 1
	done
	warn "Apache is not answering on port ${SITE_PORT} yet"
	sudo tail -n 30 /var/log/apache2/error.log >&2 2>/dev/null || true
	return 1
}

# Symlink every plugin and theme directory in the repository into the site.
# Run on each boot: the checked-out revision may add, rename, or remove
# directories that the base image knew nothing about.
link_repo_content() {
	local kind src dest name
	for kind in plugins themes; do
		[ -d "${REPO_DIR}/${kind}" ] || continue
		mkdir -p "${WP_DIR}/wp-content/${kind}"

		# Drop links that point at directories which no longer exist.
		for dest in "${WP_DIR}/wp-content/${kind}"/*; do
			if [ -L "$dest" ] && [ ! -e "$dest" ]; then
				rm -f "$dest"
			fi
		done

		for src in "${REPO_DIR}/${kind}"/*/; do
			[ -d "$src" ] || continue
			name="$(basename "$src")"
			dest="${WP_DIR}/wp-content/${kind}/${name}"
			if [ -L "$dest" ]; then
				[ "$(readlink -f "$dest")" = "$(readlink -f "$src")" ] && continue
				rm -f "$dest"
			elif [ -e "$dest" ]; then
				warn "${dest} exists and is not a symlink; leaving it alone"
				continue
			fi
			ln -s "${src%/}" "$dest"
			echo "  linked ${kind}/${name}"
		done
	done
}

store_option() {
	local name="$1"
	shift
	wp_cli option update "$name" "$@" >/dev/null 2>&1 || warn "Could not set ${name}"
}

# Installs WooCommerce, configures it for development, and seeds the sample
# catalogue. Used by install.sh and again by bin/wp-reset.sh, which drops the
# database and so has to rebuild the store as well as the site.
install_woocommerce() {
	if ! wp_cli plugin is-installed woocommerce 2>/dev/null; then
		log "Installing WooCommerce"
		if ! wp_cli plugin install woocommerce; then
			warn "Could not install WooCommerce (offline?); skipping the store setup"
			return 0
		fi
	fi

	wp_cli plugin activate woocommerce >/dev/null 2>&1 || true

	log "Configuring the store"
	# Enough of a store profile that no screen asks for it, and no onboarding
	# wizard or marketplace suggestion gets between an agent and the store.
	# WooCommerce renames, redirects and retires these option names as it
	# evolves, so a single rejected write must not abort the install.
	#
	# "Coming soon" is the one that matters most: new installs enable it, and it
	# replaces the entire store front with a launch placeholder.
	store_option woocommerce_coming_soon 'no'
	store_option woocommerce_store_address '123 Example Street'
	store_option woocommerce_store_city 'Amsterdam'
	store_option woocommerce_store_postcode '1011AB'
	store_option woocommerce_default_country 'NL'
	store_option woocommerce_currency 'USD'
	store_option woocommerce_allow_tracking 'no'
	store_option woocommerce_show_marketplace_suggestions 'no'
	store_option woocommerce_admin_task_list_hidden 'yes'
	store_option woocommerce_task_list_welcome_modal_dismissed 'yes'
	# The one the "WooCommerce Setup" dashboard widget and the reminder bar
	# actually consult; the option above no longer hides them on its own.
	store_option woocommerce_task_list_hidden_lists '["setup","extended"]' --format=json
	store_option woocommerce_task_list_reminder_bar_hidden 'yes'
	store_option woocommerce_onboarding_profile '{"skipped":true,"completed":true}' --format=json
	# Let orders be placed without a payment gateway or shipping account.
	store_option woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json

	# Shop, cart, checkout and my-account pages. Activation normally creates
	# them, but running the tool is idempotent and covers a partial install.
	wp_cli wc tool run install_pages --user="$WP_ADMIN_USER" >/dev/null 2>&1 ||
		warn "Could not run the WooCommerce install_pages tool"

	seed_products
}

seed_products() {
	local csv="${REPO_DIR}/data/sample-products.csv"
	[ -f "$csv" ] || return 0

	local count
	count="$(wp_cli post list --post_type=product --post_status=any --format=count)"
	if [ "$count" -gt 0 ]; then
		log "Store already has ${count} products"
		return 0
	fi

	log "Importing sample products"
	# --user matters: WooCommerce silently drops every product category when the
	# importer runs without a user who can `manage_product_terms`. Product images
	# are downloaded from woocommerce.com, so this needs network access too; the
	# products themselves still import if an image cannot be fetched.
	wp_cli eval-file "${REPO_DIR}/bin/import-products.php" "$csv" --user="$WP_ADMIN_USER" ||
		warn "Product import did not complete"
}

# WP-CLI runs under the CLI SAPI, so WordPress does not detect Apache and never
# writes the rewrite block itself. Without it, pretty permalinks and /wp-json/
# both return 404.
ensure_htaccess() {
	local file="${WP_DIR}/.htaccess"
	if [ -f "$file" ] && grep -q '# BEGIN WordPress' "$file"; then
		return 0
	fi
	log "Writing ${file}"
	cat >"$file" <<-'EOF'
		# BEGIN WordPress
		<IfModule mod_rewrite.c>
		RewriteEngine On
		RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
		RewriteBase /
		RewriteRule ^index\.php$ - [L]
		RewriteCond %{REQUEST_FILENAME} !-f
		RewriteCond %{REQUEST_FILENAME} !-d
		RewriteRule . /index.php [L]
		</IfModule>
		# END WordPress
	EOF
}

site_summary() {
	cat <<-EOF

	WordPress is ready.
	  URL        http://localhost:${SITE_PORT}
	  Admin      http://localhost:${SITE_PORT}/wp-admin  (${WP_ADMIN_USER} / ${WP_ADMIN_PASSWORD})
	  Core files ${WP_DIR}
	  Plugins    ${REPO_DIR}/plugins  ->  ${WP_DIR}/wp-content/plugins
	  WP-CLI     run \`wp <command>\` from ${REPO_DIR}
	EOF
}
