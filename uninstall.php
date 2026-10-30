<?php
/**
 * Removes Axle AI Guard's tables, options, schedule, transients and capability, on every site.
 *
 * @package AxleAiGuard
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Axle AI Guard data from the current site.
 */
function axle_ai_guard_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'axle_ai_guard_cleanup' );
	foreach ( array( 'settings', 'policies', 'rules', 'prices', 'kill', 'seen_callers', 'db_version' ) as $axle_ai_guard_option ) {
		delete_option( 'axle_ai_guard_' . $axle_ai_guard_option );
	}

	foreach ( wp_roles()->role_objects as $axle_ai_guard_role ) {
		$axle_ai_guard_role->remove_cap( 'axle_ai_guard_manage' );
	}

	foreach ( array( 'axle_ai_guard_calls', 'axle_ai_guard_usage', 'axle_ai_guard_prompts' ) as $axle_ai_guard_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $axle_ai_guard_table ) );
	}

	// Alert throttles, the degraded flag and rule test results.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients with a hashed name cannot be listed another way.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_axle_ai_guard_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_ai_guard_' ) . '%' ) );
}

if ( is_multisite() ) {
	delete_site_option( 'axle_ai_guard_network_kill' );
	$axle_ai_guard_offset = 0;
	do {
		$axle_ai_guard_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_ai_guard_offset,
			)
		);

		foreach ( $axle_ai_guard_sites as $axle_ai_guard_site ) {
			switch_to_blog( $axle_ai_guard_site );
			axle_ai_guard_uninstall_site();
			restore_current_blog();
		}

		$axle_ai_guard_offset += 100;
	} while ( $axle_ai_guard_sites );
} else {
	axle_ai_guard_uninstall_site();
}
