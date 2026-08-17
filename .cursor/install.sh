#!/usr/bin/env bash
# Builds the WordPress development stack: PHP, Apache, MariaDB, WP-CLI, a
# working WordPress site, the core PHPUnit test suite, and Composer tooling.
#
# Safe to re-run: every step converges on the same state.
set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PHP_VERSION="${PHP_VERSION:-8.3}"
WP_CLI_VERSION="${WP_CLI_VERSION:-2.12.0}"

# The database account is authenticated by MariaDB's unix_socket plugin, which
# matches the connecting OS user against the account name. Apache, WP-CLI and
# PHPUnit therefore all have to run as the same non-root user that installs the
# site, which is why .cursor/environment.json pins `"user": "ubuntu"`.
if [ "$(id -u)" -eq 0 ]; then
	cat >&2 <<-EOF
		This script must not run as root: the site's database account is tied to the
		OS user through unix socket authentication, so root would install a site that
		the agent's own user cannot connect to.

		Set "user" in .cursor/environment.json to a non-root user, or run
		  sudo -u ubuntu ./.cursor/install.sh
	EOF
	exit 1
fi

APT_PACKAGES=(
	apache2
	"libapache2-mod-php${PHP_VERSION}"
	"php${PHP_VERSION}-cli"
	"php${PHP_VERSION}-bcmath"
	"php${PHP_VERSION}-curl"
	"php${PHP_VERSION}-gd"
	"php${PHP_VERSION}-imagick"
	"php${PHP_VERSION}-intl"
	"php${PHP_VERSION}-mbstring"
	"php${PHP_VERSION}-mysql"
	"php${PHP_VERSION}-soap"
	"php${PHP_VERSION}-sqlite3"
	"php${PHP_VERSION}-xdebug"
	"php${PHP_VERSION}-xml"
	"php${PHP_VERSION}-zip"
	mariadb-client
	mariadb-server
	curl
	less
	rsync
	unzip
)

install_apt_packages() {
	local missing=()
	local pkg
	for pkg in "${APT_PACKAGES[@]}"; do
		dpkg -s "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
	done
	if [ "${#missing[@]}" -eq 0 ]; then
		log "System packages already installed"
		return 0
	fi
	log "Installing system packages: ${missing[*]}"
	sudo DEBIAN_FRONTEND=noninteractive apt-get update -qq
	sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends "${missing[@]}"
}

install_php_ini() {
	log "Applying PHP settings for WordPress development"
	local ini sapi
	ini="$(
		cat <<-EOF
			; Managed by .cursor/install.sh
			memory_limit = 512M
			max_execution_time = 120
			upload_max_filesize = 64M
			post_max_size = 64M
			error_reporting = E_ALL
			log_errors = On
			display_errors = Off
			; Xdebug slows every request down, so keep it loaded but idle. Enable a
			; feature per command when needed, e.g.
			;   php -d xdebug.mode=coverage vendor/bin/phpunit --coverage-text
			xdebug.mode = off
		EOF
	)"
	for sapi in cli apache2; do
		[ -d "/etc/php/${PHP_VERSION}/${sapi}/conf.d" ] || continue
		echo "$ini" | sudo tee "/etc/php/${PHP_VERSION}/${sapi}/conf.d/99-wordpress-dev.ini" >/dev/null
	done
}

install_wp_cli() {
	if [ "$(wp --version 2>/dev/null | awk '{print $2}')" = "$WP_CLI_VERSION" ]; then
		log "WP-CLI ${WP_CLI_VERSION} already installed"
		return 0
	fi
	log "Installing WP-CLI ${WP_CLI_VERSION}"
	local phar
	phar="$(mktemp)"
	curl -fsSL -o "$phar" \
		"https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"
	sudo install -m 0755 "$phar" /usr/local/bin/wp
	rm -f "$phar"
}

install_composer() {
	if command -v composer >/dev/null 2>&1; then
		log "Composer already installed"
		return 0
	fi
	log "Installing Composer"
	local dir
	dir="$(mktemp -d)"
	curl -fsSL -o "${dir}/installer" https://getcomposer.org/installer
	php "${dir}/installer" --quiet --install-dir="$dir"
	sudo install -m 0755 "${dir}/composer.phar" /usr/local/bin/composer
	rm -rf "$dir"
}

configure_apache() {
	log "Configuring Apache (document root ${WP_DIR}, user $(id -un))"

	# Serving as the agent's own user keeps every file the web server writes
	# (uploads, plugin installs, debug.log) editable without sudo.
	sudo sed -i \
		-e "s/^export APACHE_RUN_USER=.*/export APACHE_RUN_USER=$(id -un)/" \
		-e "s/^export APACHE_RUN_GROUP=.*/export APACHE_RUN_GROUP=$(id -gn)/" \
		/etc/apache2/envvars

	printf 'Listen 80\nListen %s\n' "$SITE_PORT" | sudo tee /etc/apache2/ports.conf >/dev/null

	sudo tee /etc/apache2/sites-available/wordpress.conf >/dev/null <<-EOF
		<VirtualHost *:80 *:${SITE_PORT}>
		    ServerName localhost
		    DocumentRoot ${WP_DIR}
		    <Directory ${WP_DIR}>
		        Options FollowSymLinks
		        AllowOverride All
		        Require all granted
		    </Directory>
		    ErrorLog \${APACHE_LOG_DIR}/error.log
		    CustomLog \${APACHE_LOG_DIR}/access.log combined
		</VirtualHost>
	EOF

	echo 'ServerName localhost' | sudo tee /etc/apache2/conf-available/servername.conf >/dev/null

	sudo a2enmod rewrite >/dev/null
	sudo a2enconf servername >/dev/null
	sudo a2dissite 000-default >/dev/null 2>&1 || true
	sudo a2ensite wordpress >/dev/null
	sudo apache2ctl configtest
}

configure_database() {
	start_mariadb
	log "Creating databases ${DB_NAME} and ${DB_TEST_NAME} for '${DB_USER}'"
	sudo mariadb <<-SQL
		CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
		CREATE DATABASE IF NOT EXISTS \`${DB_TEST_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
		CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED VIA unix_socket;
		GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
		GRANT ALL PRIVILEGES ON \`${DB_TEST_NAME}\`.* TO '${DB_USER}'@'localhost';
		FLUSH PRIVILEGES;
	SQL
	mariadb -e "SELECT 1" "$DB_NAME" >/dev/null
}

install_wordpress() {
	sudo mkdir -p "$WP_DIR"
	sudo chown -R "$(id -un):$(id -gn)" "$WP_DIR"

	if [ ! -f "${WP_DIR}/wp-load.php" ]; then
		log "Downloading WordPress core"
		wp_cli core download
	else
		log "WordPress core present ($(wp_cli core version))"
	fi

	if [ ! -f "${WP_DIR}/wp-config.php" ]; then
		log "Writing wp-config.php"
		# Salts are generated locally by `config shuffle-salts` below so that
		# installation does not depend on api.wordpress.org being reachable.
		wp_cli config create \
			--dbname="$DB_NAME" \
			--dbuser="$DB_USER" \
			--dbpass='' \
			--dbhost='localhost:/run/mysqld/mysqld.sock' \
			--dbcharset='utf8mb4' \
			--dbcollate='utf8mb4_unicode_ci' \
			--skip-salts \
			--skip-check \
			--extra-php <<-'PHP'
				/*
				 * Serve the site under whichever host the request arrives on. Cloud
				 * Agent port forwarding rewrites the host, and a hard-coded
				 * WP_HOME would redirect the browser back to localhost.
				 */
				if ( ! defined( 'WP_HOME' ) ) {
				    $wp_dev_scheme = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
				        || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] )
				        ? 'https' : 'http';
				    $wp_dev_host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
				    define( 'WP_HOME', $wp_dev_scheme . '://' . $wp_dev_host );
				    define( 'WP_SITEURL', WP_HOME );
				}

				define( 'WP_DEBUG', true );
				define( 'WP_DEBUG_LOG', true );
				define( 'WP_DEBUG_DISPLAY', false );
				define( 'SCRIPT_DEBUG', true );
				define( 'WP_ENVIRONMENT_TYPE', 'local' );
				define( 'SAVEQUERIES', true );
				define( 'WP_DISABLE_FATAL_ERROR_HANDLER', true );

				/* Let WordPress write to the filesystem directly instead of asking for FTP. */
				define( 'FS_METHOD', 'direct' );

				define( 'AUTOMATIC_UPDATER_DISABLED', true );
				define( 'WP_AUTO_UPDATE_CORE', false );
				define( 'DISALLOW_FILE_MODS', false );
			PHP
		wp_cli config shuffle-salts
	else
		log "wp-config.php present"
	fi

	if ! wp_cli core is-installed 2>/dev/null; then
		log "Installing WordPress"
		wp_cli core install \
			--url="http://localhost:${SITE_PORT}" \
			--title="$WP_SITE_TITLE" \
			--admin_user="$WP_ADMIN_USER" \
			--admin_password="$WP_ADMIN_PASSWORD" \
			--admin_email="$WP_ADMIN_EMAIL" \
			--skip-email
		wp_cli rewrite structure '/%postname%/'
		wp_cli option update timezone_string 'UTC'
	else
		log "WordPress already installed"
	fi

	ensure_htaccess

	# Query Monitor surfaces PHP notices, slow queries and hook usage in the
	# admin bar, which is the fastest way for an agent to see what a plugin did.
	if ! wp_cli plugin is-installed query-monitor 2>/dev/null; then
		wp_cli plugin install query-monitor --activate || warn "Could not install Query Monitor (offline?)"
	fi

	if [ "$(wp_cli post list --post_type=post --format=count)" -le 1 ]; then
		log "Generating sample posts"
		wp_cli post generate --count=5 --post_type=post >/dev/null
	fi
}

install_test_suite() {
	local version
	version="$(wp_cli core version)"

	if [ -f "${WP_TESTS_LIB}/includes/bootstrap.php" ] &&
		[ "$(cat "${WP_TESTS_LIB}/.wp-version" 2>/dev/null)" = "$version" ]; then
		log "WordPress test suite ${version} already installed"
	else
		log "Installing WordPress ${version} test suite"
		local tmp
		tmp="$(mktemp -d)"
		if ! curl -fsSL -o "${tmp}/develop.tar.gz" \
			"https://github.com/WordPress/wordpress-develop/archive/refs/tags/${version}.tar.gz"; then
			warn "Could not download the ${version} test suite; skipping"
			rm -rf "$tmp"
			return 0
		fi
		tar -xzf "${tmp}/develop.tar.gz" -C "$tmp"
		sudo mkdir -p "$WP_TESTS_LIB"
		sudo chown -R "$(id -un):$(id -gn)" "$WP_TESTS_LIB"
		rm -rf "${WP_TESTS_LIB}/includes" "${WP_TESTS_LIB}/data"
		cp -r "${tmp}/wordpress-develop-${version}/tests/phpunit/includes" "${WP_TESTS_LIB}/"
		cp -r "${tmp}/wordpress-develop-${version}/tests/phpunit/data" "${WP_TESTS_LIB}/"
		echo "$version" >"${WP_TESTS_LIB}/.wp-version"
		rm -rf "$tmp"
	fi

	log "Writing wp-tests-config.php"
	cat >"${WP_TESTS_LIB}/wp-tests-config.php" <<-PHP
		<?php
		/* Generated by .cursor/install.sh. The test suite drops and recreates
		 * every table in this database on each run, so it must never point at
		 * the database backing the development site. */
		define( 'ABSPATH', '${WP_DIR}/' );
		define( 'DB_NAME', '${DB_TEST_NAME}' );
		define( 'DB_USER', '${DB_USER}' );
		define( 'DB_PASSWORD', '' );
		define( 'DB_HOST', 'localhost:/run/mysqld/mysqld.sock' );
		define( 'DB_CHARSET', 'utf8mb4' );
		define( 'DB_COLLATE', '' );

		\$table_prefix = 'wptests_';

		define( 'WP_TESTS_DOMAIN', 'localhost' );
		define( 'WP_TESTS_EMAIL', '${WP_ADMIN_EMAIL}' );
		define( 'WP_TESTS_TITLE', 'Test Blog' );
		define( 'WP_PHP_BINARY', 'php' );
		define( 'WP_DEBUG', true );
		define( 'WP_TESTS_MULTISITE', false );
	PHP
}

install_composer_dependencies() {
	[ -f "${REPO_DIR}/composer.json" ] || return 0
	log "Installing Composer dependencies"

	# Composer pulls ~45 archives from GitHub, which is far more than the 60
	# requests per hour that anonymous clients get. Reuse whichever token the
	# machine already has (never written to disk) and fall back to git clones,
	# which authenticate through the machine's existing git configuration.
	local token=''
	if [ -n "${GITHUB_TOKEN:-}" ]; then
		token="$GITHUB_TOKEN"
	elif [ -n "${GH_TOKEN:-}" ]; then
		token="$GH_TOKEN"
	elif command -v gh >/dev/null 2>&1; then
		token="$(gh auth token 2>/dev/null || true)"
	fi
	if [ -n "$token" ]; then
		export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"${token}\"}}"
	else
		# Without a token, downloading in a burst trips GitHub's secondary rate
		# limit, so trade a little speed for a much better chance of finishing.
		export COMPOSER_MAX_PARALLEL_HTTP=3
	fi

	local attempt
	for attempt in 1 2 3; do
		if (cd "$REPO_DIR" && composer install --no-interaction --no-progress); then
			return 0
		fi
		warn "composer install failed (attempt ${attempt}); retrying in $((attempt * 20))s"
		sleep "$((attempt * 20))"
	done

	warn "Retrying with --prefer-source"
	(cd "$REPO_DIR" && composer install --no-interaction --no-progress --prefer-source)
}

main() {
	install_apt_packages
	install_php_ini
	install_wp_cli
	install_composer
	configure_apache
	configure_database
	install_wordpress
	link_repo_content
	install_test_suite
	install_composer_dependencies

	log "Activating plugins from the repository"
	local dir name
	for dir in "${REPO_DIR}"/plugins/*/; do
		[ -d "$dir" ] || continue
		name="$(basename "$dir")"
		wp_cli plugin activate "$name" 2>/dev/null || warn "Could not activate ${name}"
	done

	start_apache || true
	site_summary
}

main "$@"
