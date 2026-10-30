<?php
/**
 * Where an AI call is made from: context, user and IP address.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Request signals for a call.
 */
final class Context {

	/**
	 * The request context: admin, front, cron, rest, ajax or cli.
	 *
	 * @return string
	 */
	public static function current(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$context = 'cli';
		} elseif ( wp_doing_cron() ) {
			$context = 'cron';
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			$context = 'rest';
		} elseif ( wp_doing_ajax() ) {
			$context = 'ajax';
		} elseif ( is_admin() ) {
			$context = 'admin';
		} else {
			$context = 'front';
		}

		/**
		 * Filters the context of an AI call.
		 *
		 * @param string $context admin, front, cron, rest, ajax or cli.
		 */
		$filtered = (string) apply_filters( 'axle_ai_guard_context', $context );

		return in_array( $filtered, Settings::CONTEXTS, true ) ? $filtered : $context;
	}

	/**
	 * The anonymised IP address of a visitor who is not logged in, outside
	 * WP-CLI and cron. Empty otherwise.
	 *
	 * @param string $context The call context.
	 * @return string
	 */
	public static function visitor_ip( string $context ): string {
		if ( is_user_logged_in() || in_array( $context, array( 'cli', 'cron' ), true ) ) {
			return '';
		}

		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Validated below; only used, anonymised, for rate limits of AI calls, which never come from a page cache.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return (string) wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * The call details every check needs.
	 *
	 * @param string $path client, embedding or direct.
	 * @param array  $caller From Caller::detect().
	 * @return array<string, mixed>
	 */
	public static function call( string $path, array $caller ): array {
		$context = self::current();
		$user    = wp_get_current_user();

		return array(
			'path'       => $path,
			'caller'     => $caller,
			'context'    => $context,
			'user_id'    => (int) $user->ID,
			'roles'      => $user->ID ? array_values( (array) $user->roles ) : array( 'anonymous' ),
			'ip'         => self::visitor_ip( $context ),
			'provider'   => '',
			'model'      => '',
			'capability' => '',
		);
	}
}
