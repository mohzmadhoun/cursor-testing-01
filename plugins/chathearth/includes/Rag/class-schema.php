<?php
/**
 * Knowledge-base table installer.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades custom tables used by RAG.
 */
final class Schema {

	public const DB_VERSION     = 1;
	public const VERSION_OPTION = 'chathearth_kb_db_version';

	/**
	 * Entries table name.
	 */
	public static function entries_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'chathearth_kb_entries';
	}

	/**
	 * Chunks table name.
	 */
	public static function chunks_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'chathearth_kb_chunks';
	}

	/**
	 * Install or upgrade tables when the schema version changes.
	 */
	public static function maybe_install(): void {
		$installed = (int) get_option( self::VERSION_OPTION, 0 );
		if ( self::DB_VERSION === $installed ) {
			return;
		}

		self::install();
	}

	/**
	 * Create tables and the markdown upload directory.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$entries         = self::entries_table();
		$chunks          = self::chunks_table();

		$sql_entries = "CREATE TABLE {$entries} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_id varchar(191) NOT NULL,
			object_type varchar(20) NOT NULL DEFAULT 'post',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(64) NOT NULL DEFAULT '',
			taxonomy varchar(64) NOT NULL DEFAULT '',
			title text NOT NULL,
			url text NOT NULL,
			markdown longtext NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			included tinyint(1) NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'pending',
			error_message text NULL,
			updated_gmt datetime NOT NULL,
			indexed_gmt datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_id (source_id),
			KEY status (status),
			KEY object_lookup (object_type,object_id)
		) {$charset_collate};";

		$sql_chunks = "CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			entry_id bigint(20) unsigned NOT NULL,
			chunk_id varchar(191) NOT NULL,
			chunk_index int(10) unsigned NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			embedding longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chunk_id (chunk_id),
			KEY entry_id (entry_id)
		) {$charset_collate};";

		dbDelta( $sql_entries );
		dbDelta( $sql_chunks );

		update_option( self::VERSION_OPTION, self::DB_VERSION, true );
		self::ensure_upload_dir();
	}

	/**
	 * Plugin-owned uploads root (markdown + optional local Chroma files).
	 */
	public static function plugin_upload_dir(): string {
		$uploads = wp_upload_dir();
		$base    = (string) $uploads['basedir'];

		return trailingslashit( $base ) . 'chathearth';
	}

	/**
	 * Absolute directory for generated markdown files.
	 */
	public static function upload_dir(): string {
		return trailingslashit( self::plugin_upload_dir() ) . 'kb';
	}

	/**
	 * Persist directory for a Chroma server running on this host.
	 *
	 * WordPress does not read these files itself. A Chroma HTTP process writes them.
	 */
	public static function chroma_dir(): string {
		return trailingslashit( self::plugin_upload_dir() ) . 'chroma';
	}

	/**
	 * Create the markdown directory and deny public listing.
	 */
	public static function ensure_upload_dir(): void {
		self::protect_dir( self::plugin_upload_dir() );
		self::protect_dir( self::upload_dir() );
		self::protect_dir( self::chroma_dir() );
	}

	/**
	 * Create a directory and deny public HTTP access.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function protect_dir( string $dir ): void {
		if ( '' === $dir || '/' === $dir ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private uploads guard file.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private uploads guard file.
			file_put_contents( $htaccess, $rules );
		}
	}

	/**
	 * Markdown file path for a source id.
	 *
	 * @param string $source_id Source id such as post:123.
	 */
	public static function markdown_path( string $source_id ): string {
		$safe = strtolower( preg_replace( '/[^a-zA-Z0-9._-]+/', '-', $source_id ) ?? $source_id );
		$safe = trim( $safe, '-' );
		if ( '' === $safe ) {
			$safe = 'entry';
		}

		return trailingslashit( self::upload_dir() ) . $safe . '.md';
	}

	/**
	 * Drop tables, cron, files, and version option (uninstall).
	 */
	public static function uninstall(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- uninstall drop of plugin table.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::chunks_table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- uninstall drop of plugin table.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::entries_table() );

		delete_option( self::VERSION_OPTION );
		delete_option( 'chathearth_kb_sync_state' );
		wp_clear_scheduled_hook( 'chathearth_process_kb_queue' );

		$dir = self::upload_dir();
		if ( is_dir( $dir ) ) {
			$files = glob( trailingslashit( $dir ) . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						wp_delete_file( $file );
					}
				}
			}
		}
	}
}
