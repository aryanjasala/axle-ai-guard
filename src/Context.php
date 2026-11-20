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

		$ip = self::client_ip();

		/**
		 * Filters the address of a visitor before it is anonymised and used for the per-visitor
		 * rate limit. Use it when the site sits behind a proxy that this plugin cannot know about.
		 * Return an empty string to skip the visitor limit for this request. Never trust a header
		 * a visitor can set yourself: read a forwarded header only when the proxy that sets it is
		 * the only way in.
		 *
		 * @param string $ip      A valid IPv4 or IPv6 address, from REMOTE_ADDR unless the site configured a trusted header.
		 * @param string $context The call context.
		 */
		$filtered = apply_filters( 'axle_ai_guard_visitor_ip', $ip, $context );
		$filtered = trim( Hooks::str( $filtered, '' ) );
		if ( '' === $filtered || false === filter_var( $filtered, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return (string) wp_privacy_anonymize_ip( $filtered );
	}

	/**
	 * The address of the client of this request. It is REMOTE_ADDR, the address that connected
	 * to the web server. A forwarded header is read only when the site names it, with the
	 * AXLE_AI_GUARD_IP_HEADER constant or the axle_ai_guard_ip_header filter, and, when the site
	 * also lists its proxies (axle_ai_guard_trusted_proxies), only when REMOTE_ADDR is one of them.
	 *
	 * @return string A valid IP address, or ''.
	 */
	public static function client_ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Validated below; only used, anonymised, for rate limits of AI calls, which never come from a page cache.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		$header = defined( 'AXLE_AI_GUARD_IP_HEADER' ) ? (string) AXLE_AI_GUARD_IP_HEADER : '';

		/**
		 * Filters the name of the request header that carries the real client address, such as
		 * HTTP_CF_CONNECTING_IP or HTTP_X_FORWARDED_FOR (the $_SERVER key). Empty by default: no
		 * forwarded header is trusted.
		 *
		 * @param string $header Default the AXLE_AI_GUARD_IP_HEADER constant, or empty.
		 */
		$header = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_]/', '', (string) apply_filters( 'axle_ai_guard_ip_header', $header ) ) );
		if ( '' === $header || ! isset( $_SERVER[ $header ] ) ) {
			return $remote;
		}

		/**
		 * Filters the proxies that may set the forwarded header: IP addresses or CIDR ranges such
		 * as 10.0.0.0/8. When the list is not empty, the header counts only when the connection
		 * comes from one of them.
		 *
		 * @param string[] $proxies Default the AXLE_AI_GUARD_TRUSTED_PROXIES constant (comma separated), or none.
		 */
		$default = defined( 'AXLE_AI_GUARD_TRUSTED_PROXIES' ) ? array_map( 'trim', explode( ',', (string) AXLE_AI_GUARD_TRUSTED_PROXIES ) ) : array();
		$proxies = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'axle_ai_guard_trusted_proxies', $default ) ) ) );
		if ( $proxies && ( '' === $remote || ! self::ip_in( $remote, $proxies ) ) ) {
			return $remote;
		}

		// The header is a list when it passes through several proxies: the nearest proxy appended last.
		$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $header ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- The site opted in to this header; each entry is validated as an IP address below.
		$list  = array_reverse( array_map( 'trim', explode( ',', $value ) ) );
		foreach ( $list as $candidate ) {
			if ( false === filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				// Anything that is not an address ends the walk: do not look past a bad entry.
				return $remote;
			}
			if ( $proxies && self::ip_in( $candidate, $proxies ) ) {
				continue;
			}

			return $candidate;
		}

		return $remote;
	}

	/**
	 * Whether an address is one of the listed addresses or inside one of the listed ranges.
	 *
	 * @param string   $ip   Address.
	 * @param string[] $ranges Addresses and CIDR ranges.
	 * @return bool
	 */
	public static function ip_in( string $ip, array $ranges ): bool {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $entry ) {
			$parts = explode( '/', trim( (string) $entry ), 2 );
			$net   = inet_pton( $parts[0] );
			if ( false === $net || strlen( $net ) !== strlen( $packed ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? min( (int) $parts[1], strlen( $net ) * 8 ) : strlen( $net ) * 8;
			$full = intdiv( $bits, 8 );
			if ( substr( $packed, 0, $full ) !== substr( $net, 0, $full ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
			if ( ( ord( $packed[ $full ] ) & $mask ) === ( ord( $net[ $full ] ) & $mask ) ) {
				return true;
			}
		}

		return false;
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
