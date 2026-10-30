<?php
/**
 * Settings, policies and caller approvals, with their defaults.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options. Every value is sanitised on the way
 * in and again on the way out, so a hand-edited option cannot break a check.
 */
final class Settings {

	public const OPTION   = 'axle_ai_guard_settings';
	public const POLICIES = 'axle_ai_guard_policies';
	public const CALLERS  = 'axle_ai_guard_seen_callers';

	public const CONTEXTS = array( 'admin', 'front', 'cron', 'rest', 'ajax', 'cli' );

	public const NEW_CALLER_MODES = array( 'allow', 'alert', 'deny' );

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'new_caller'        => 'alert',
			'currency'          => 'USD',
			'soft_ratio'        => 80,
			'rate_plugin'       => 60,
			'rate_user'         => 20,
			'rate_ip'           => 5,
			'rate_global'       => 120,
			'retention_calls'   => 90,
			'retention_usage'   => 400,
			'retention_prompts' => 7,
			'store_preview'     => false,
			'store_prompts'     => false,
			'alert_email'       => true,
			'alert_recipients'  => '',
			'webhook_url'       => '',
			'direct_monitoring' => false,
			'autotrip_budget'   => false,
			'autotrip_errors'   => 0,
		);
	}

	/**
	 * All settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );

		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all = $this->all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Saves settings, merged over what is stored.
	 *
	 * @param array $values New values.
	 * @return array<string, mixed> What was saved.
	 */
	public function save( array $values ): array {
		$saved = self::sanitize( array_merge( $this->all(), $values ) );
		update_option( self::OPTION, $saved, false );

		return $saved;
	}

	/**
	 * Cleans settings and fills in defaults.
	 *
	 * @param array $values Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $values ): array {
		$defaults = self::defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			$value = array_key_exists( $key, $values ) ? $values[ $key ] : $default;

			if ( is_bool( $default ) ) {
				$clean[ $key ] = in_array( $value, array( true, 1, '1', 'on', 'yes', 'true' ), true );
			} elseif ( is_int( $default ) ) {
				$clean[ $key ] = max( 0, (int) $value );
			} else {
				$clean[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : (string) $default;
			}
		}

		if ( ! in_array( $clean['new_caller'], self::NEW_CALLER_MODES, true ) ) {
			$clean['new_caller'] = $defaults['new_caller'];
		}
		$clean['currency']          = preg_match( '/^[A-Z]{3}$/', strtoupper( $clean['currency'] ) ) ? strtoupper( $clean['currency'] ) : 'USD';
		$clean['soft_ratio']        = min( 100, max( 1, $clean['soft_ratio'] ) );
		$clean['retention_calls']   = max( 1, min( 3650, $clean['retention_calls'] ) );
		$clean['retention_usage']   = max( 1, min( 3650, $clean['retention_usage'] ) );
		$clean['retention_prompts'] = max( 1, min( 90, $clean['retention_prompts'] ) );
		$clean['autotrip_errors']   = min( 100, $clean['autotrip_errors'] );
		$clean['webhook_url']       = '' === $clean['webhook_url'] ? '' : (string) esc_url_raw( $clean['webhook_url'], array( 'https', 'http' ) );
		$clean['alert_recipients']  = implode( ', ', self::emails( $clean['alert_recipients'] ) );

		return $clean;
	}

	/**
	 * Valid email addresses from a comma or space separated list.
	 *
	 * @param string|array $value Addresses.
	 * @return string[]
	 */
	public static function emails( $value ): array {
		$items = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
		$valid = array();

		foreach ( (array) $items as $item ) {
			$email = sanitize_email( (string) $item );
			if ( '' !== $email && is_email( $email ) ) {
				$valid[] = $email;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * An empty policy: no budget, the default rate limit, everything allowed.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_policy(): array {
		return array(
			'daily_tokens'   => 0,
			'monthly_tokens' => 0,
			'daily_cost'     => 0,
			'monthly_cost'   => 0,
			'per_minute'     => -1,
			'contexts'       => array(),
			'providers'      => array(),
			'models'         => array(),
			'roles'          => array(),
			'block_files'    => false,
		);
	}

	/**
	 * Every stored policy, keyed by subject (global, plugin:<caller>).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function policies(): array {
		$saved    = get_option( self::POLICIES, array() );
		$policies = array();

		foreach ( is_array( $saved ) ? $saved : array() as $subject => $policy ) {
			$subject = self::subject( (string) $subject );
			if ( '' !== $subject && is_array( $policy ) ) {
				$policies[ $subject ] = self::sanitize_policy( $policy );
			}
		}

		return $policies;
	}

	/**
	 * The policy of one subject.
	 *
	 * @param string $subject global or plugin:<caller>.
	 * @return array<string, mixed>
	 */
	public function policy( string $subject ): array {
		$policies = $this->policies();

		return $policies[ $subject ] ?? self::empty_policy();
	}

	/**
	 * Saves the policy of one subject, merged over what is stored.
	 *
	 * @param string $subject global or plugin:<caller>.
	 * @param array  $values  Fields to change.
	 * @return array<string, mixed> The saved policy.
	 */
	public function save_policy( string $subject, array $values ): array {
		$subject = self::subject( $subject );
		if ( '' === $subject ) {
			return self::empty_policy();
		}

		$policies             = $this->policies();
		$policies[ $subject ] = self::sanitize_policy( array_merge( $policies[ $subject ] ?? self::empty_policy(), $values ) );
		update_option( self::POLICIES, $policies, false );

		return $policies[ $subject ];
	}

	/**
	 * A subject key, or '' when it is not one.
	 *
	 * @param string $subject Raw subject.
	 * @return string
	 */
	public static function subject( string $subject ): string {
		$subject = strtolower( trim( $subject ) );
		if ( 'global' === $subject ) {
			return 'global';
		}
		if ( preg_match( '/^(plugin|provider):[a-z0-9_.:\-]{1,100}$/', $subject ) ) {
			return $subject;
		}

		return '';
	}

	/**
	 * Cleans a policy.
	 *
	 * @param array $policy Raw policy.
	 * @return array<string, mixed>
	 */
	public static function sanitize_policy( array $policy ): array {
		$clean = self::empty_policy();

		foreach ( array( 'daily_tokens', 'monthly_tokens', 'daily_cost', 'monthly_cost' ) as $key ) {
			$clean[ $key ] = max( 0, (int) ( $policy[ $key ] ?? 0 ) );
		}
		$clean['per_minute']  = isset( $policy['per_minute'] ) && '' !== $policy['per_minute'] ? max( -1, (int) $policy['per_minute'] ) : -1;
		$clean['contexts']    = array_values( array_intersect( self::CONTEXTS, self::words( $policy['contexts'] ?? array() ) ) );
		$clean['providers']   = self::words( $policy['providers'] ?? array() );
		$clean['models']      = self::words( $policy['models'] ?? array() );
		$clean['roles']       = self::words( $policy['roles'] ?? array() );
		$clean['block_files'] = ! empty( $policy['block_files'] );

		return $clean;
	}

	/**
	 * A clean list of identifiers from an array or a comma separated string.
	 *
	 * @param string|array $value Raw list.
	 * @return string[]
	 */
	public static function words( $value ): array {
		$items = is_array( $value ) ? $value : explode( ',', (string) $value );
		$words = array();

		foreach ( $items as $item ) {
			$item = strtolower( trim( (string) $item ) );
			$item = (string) preg_replace( '/[^a-z0-9_.:\/*\-]/', '', $item );
			if ( '' !== $item ) {
				$words[] = $item;
			}
		}

		return array_values( array_unique( $words ) );
	}

	/**
	 * Callers seen so far, keyed by caller key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function callers(): array {
		$saved = get_option( self::CALLERS, array() );

		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * The approval status of a caller: approved, pending, denied, or '' when never seen.
	 *
	 * @param string $key Caller key.
	 * @return string
	 */
	public function caller_status( string $key ): string {
		$callers = $this->callers();

		return isset( $callers[ $key ]['status'] ) ? (string) $callers[ $key ]['status'] : '';
	}

	/**
	 * Records a caller seen for the first time.
	 *
	 * @param array  $caller Caller from Caller::detect().
	 * @param string $status approved or pending.
	 */
	public function remember_caller( array $caller, string $status ): void {
		$callers = $this->callers();
		$key     = (string) $caller['slug'];

		$callers[ $key ] = array(
			'type'       => (string) $caller['type'],
			'file'       => (string) $caller['file'],
			'first_seen' => time(),
			'status'     => in_array( $status, array( 'approved', 'pending', 'denied' ), true ) ? $status : 'approved',
		);
		update_option( self::CALLERS, $callers, false );
	}

	/**
	 * Approves or denies a caller.
	 *
	 * @param string $key    Caller key.
	 * @param string $status approved or denied.
	 * @return bool Whether the caller is known.
	 */
	public function set_caller_status( string $key, string $status ): bool {
		$callers = $this->callers();
		if ( ! isset( $callers[ $key ] ) || ! in_array( $status, array( 'approved', 'denied' ), true ) ) {
			return false;
		}

		$callers[ $key ]['status'] = $status;
		update_option( self::CALLERS, $callers, false );

		return true;
	}
}
