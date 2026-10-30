<?php
/**
 * Database tables, the capability and activation.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the three tables, gives administrators the
 * capability, and schedules the daily clean-up.
 */
final class Schema {

	public const DB_VERSION = '1';
	public const OPTION     = 'axle_ai_guard_db_version';
	public const CAPABILITY = 'axle_ai_guard_manage';
	public const CRON       = 'axle_ai_guard_cleanup';

	/**
	 * Table names.
	 *
	 * @param string $name calls, usage or prompts.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'axle_ai_guard_' . $name;
	}

	/**
	 * Activation: tables, capability, schedule. On a network activation, every site.
	 *
	 * @param bool $network_wide Whether it was network activated.
	 */
	public static function activate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 500,
				)
			) as $site ) {
				switch_to_blog( (int) $site );
				self::install();
				restore_current_blog();
			}
			return;
		}

		self::install();
	}

	/**
	 * Deactivation: stops the schedule. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Upgrades when the stored version is older. Runs on every load, cheaply.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the tables and grants the capability.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$calls   = self::table( 'calls' );
		$usage   = self::table( 'usage' );
		$prompts = self::table( 'prompts' );

		dbDelta(
			"CREATE TABLE {$calls} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				started_at datetime NOT NULL,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(10) NOT NULL DEFAULT 'pending',
				block_rule varchar(64) NOT NULL DEFAULT '',
				path varchar(10) NOT NULL DEFAULT 'client',
				caller_type varchar(10) NOT NULL DEFAULT '',
				caller_slug varchar(100) NOT NULL DEFAULT '',
				caller_file varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				context varchar(10) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				provider varchar(64) NOT NULL DEFAULT '',
				model varchar(128) NOT NULL DEFAULT '',
				capability varchar(40) NOT NULL DEFAULT '',
				prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
				completion_tokens int(10) unsigned NOT NULL DEFAULT 0,
				thought_tokens int(10) unsigned NOT NULL DEFAULT 0,
				total_tokens int(10) unsigned NOT NULL DEFAULT 0,
				estimated tinyint(1) unsigned NOT NULL DEFAULT 0,
				cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				request_bytes int(10) unsigned NOT NULL DEFAULT 0,
				response_bytes int(10) unsigned NOT NULL DEFAULT 0,
				http_status smallint(5) unsigned NOT NULL DEFAULT 0,
				error_code varchar(100) NOT NULL DEFAULT '',
				profile longtext NULL,
				preview text NULL,
				PRIMARY KEY  (id),
				KEY started_at (started_at),
				KEY caller_started (caller_slug,started_at),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$usage} (
				period varchar(20) NOT NULL,
				subject varchar(120) NOT NULL,
				calls bigint(20) unsigned NOT NULL DEFAULT 0,
				tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
				blocked bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (period,subject)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$prompts} (
				call_id bigint(20) unsigned NOT NULL,
				redacted_messages longtext NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (call_id),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}

		update_option( self::OPTION, self::DB_VERSION );
	}

	/**
	 * Whether the tables exist, so the guard knows when it is degraded.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		global $wpdb;

		$found = wp_cache_get( 'tables_ready', 'axle_ai_guard' );
		if ( false === $found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Schema check, cached below.
			$found = self::table( 'calls' ) === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table( 'calls' ) ) ) ) ? 'yes' : 'no';
			wp_cache_set( 'tables_ready', $found, 'axle_ai_guard', MINUTE_IN_SECONDS );
		}

		return 'yes' === $found;
	}
}
