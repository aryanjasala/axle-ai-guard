<?php
/**
 * Data rules: detectors for personal data and secrets, their modes, and redaction.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Each detector finds strings in a prompt and has a mode: off, log, redact or
 * block. Redaction swaps each match for a placeholder and keeps no map back to
 * the original value.
 */
final class Detectors {

	public const OPTION = 'axle_ai_guard_rules';
	public const MODES  = array( 'off', 'log', 'redact', 'block' );

	private const SALTS = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/**
	 * Built-in and filtered detectors: id => label, default mode and a callback
	 * that returns the matched strings of a text.
	 *
	 * @return array<string, array{label: string, default: string, match: callable}>
	 */
	public function all(): array {
		$detectors = array(
			'user_email'    => array(
				'label'   => __( 'Emails of registered users', 'axle-ai-guard' ),
				'default' => 'redact',
				'match'   => array( self::class, 'match_user_emails' ),
			),
			'email'         => array(
				'label'   => __( 'Any email address', 'axle-ai-guard' ),
				'default' => 'log',
				'match'   => array( self::class, 'match_emails' ),
			),
			'phone'         => array(
				'label'   => __( 'Phone numbers', 'axle-ai-guard' ),
				'default' => 'log',
				'match'   => array( self::class, 'match_phones' ),
			),
			'ip'            => array(
				'label'   => __( 'IP addresses', 'axle-ai-guard' ),
				'default' => 'log',
				'match'   => array( self::class, 'match_ips' ),
			),
			'card'          => array(
				'label'   => __( 'Payment card numbers', 'axle-ai-guard' ),
				'default' => 'log',
				'match'   => array( self::class, 'match_cards' ),
			),
			'password_hash' => array(
				'label'   => __( 'Password hashes', 'axle-ai-guard' ),
				'default' => 'block',
				'match'   => array( self::class, 'match_hashes' ),
			),
			'salts'         => array(
				'label'   => __( 'WordPress salts and keys', 'axle-ai-guard' ),
				'default' => 'block',
				'match'   => array( self::class, 'match_salts' ),
			),
			'secrets'       => array(
				'label'   => __( 'Connector API keys and other secret options', 'axle-ai-guard' ),
				'default' => 'block',
				'match'   => array( self::class, 'match_secrets' ),
			),
		);

		foreach ( $this->custom() as $index => $rule ) {
			$detectors[ 'custom-' . ( $index + 1 ) ] = array(
				'label'   => '' !== $rule['label'] ? $rule['label'] : $rule['pattern'],
				'default' => $rule['mode'],
				'match'   => self::custom_matcher( $rule ),
			);
		}

		/**
		 * Filters the data rule detectors. Add one with a label, a default mode
		 * (off, log, redact, block) and a match callback that returns the
		 * matched strings of a text.
		 *
		 * @param array $detectors Detectors keyed by id.
		 */
		$filtered = apply_filters( 'axle_ai_guard_detectors', $detectors );
		$clean    = array();

		foreach ( Hooks::arr( $filtered, $detectors ) as $id => $detector ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $detector ) || ! isset( $detector['match'] ) || ! is_callable( $detector['match'] ) ) {
				continue;
			}
			$clean[ $id ] = array(
				'label'   => (string) ( $detector['label'] ?? $id ),
				'default' => in_array( $detector['default'] ?? '', self::MODES, true ) ? (string) $detector['default'] : 'log',
				'match'   => $detector['match'],
			);
		}

		return $clean;
	}

	/**
	 * Stored rules: modes per detector and custom rules.
	 *
	 * @return array{detectors: array<string, string>, custom: array<int, array{label: string, type: string, pattern: string, mode: string}>}
	 */
	public function rules(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$modes = array();

		foreach ( (array) ( $saved['detectors'] ?? array() ) as $id => $mode ) {
			if ( in_array( $mode, self::MODES, true ) ) {
				$modes[ sanitize_key( (string) $id ) ] = (string) $mode;
			}
		}

		return array(
			'detectors' => $modes,
			'custom'    => self::sanitize_custom( (array) ( $saved['custom'] ?? array() ) ),
		);
	}

	/**
	 * Saves rules.
	 *
	 * @param array $modes  Mode per detector id.
	 * @param array $custom Custom rules, or null to keep them.
	 */
	public function save( array $modes, ?array $custom = null ): void {
		$rules = $this->rules();
		foreach ( $modes as $id => $mode ) {
			if ( in_array( $mode, self::MODES, true ) ) {
				$rules['detectors'][ sanitize_key( (string) $id ) ] = (string) $mode;
			}
		}
		if ( null !== $custom ) {
			$rules['custom'] = self::sanitize_custom( $custom );
		}

		update_option( self::OPTION, $rules, false );
	}

	/**
	 * Custom rules.
	 *
	 * @return array<int, array{label: string, type: string, pattern: string, mode: string}>
	 */
	public function custom(): array {
		return $this->rules()['custom'];
	}

	/**
	 * Cleans custom rules: string or regex, with a pattern that compiles.
	 *
	 * @param array $rules Raw rules.
	 * @return array<int, array{label: string, type: string, pattern: string, mode: string}>
	 */
	public static function sanitize_custom( array $rules ): array {
		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$type    = 'regex' === ( $rule['type'] ?? '' ) ? 'regex' : 'string';
			$pattern = trim( (string) ( $rule['pattern'] ?? '' ) );
			if ( '' === $pattern || ( 'regex' === $type && ! self::valid_regex( $pattern ) ) ) {
				continue;
			}
			$clean[] = array(
				'label'   => sanitize_text_field( (string) ( $rule['label'] ?? '' ) ),
				'type'    => $type,
				'pattern' => substr( $pattern, 0, 500 ),
				'mode'    => in_array( $rule['mode'] ?? '', self::MODES, true ) ? (string) $rule['mode'] : 'log',
			);
		}

		return array_slice( $clean, 0, 50 );
	}

	/**
	 * Whether a regular expression compiles.
	 *
	 * @param string $pattern Pattern with delimiters.
	 * @return bool
	 */
	public static function valid_regex( string $pattern ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An invalid pattern from the settings screen must not raise a warning.
		return false !== @preg_match( $pattern, '' );
	}

	/**
	 * The mode of a detector for a caller.
	 *
	 * @param string $id     Detector id.
	 * @param string $caller Caller key.
	 * @return string off, log, redact or block.
	 */
	public function mode( string $id, string $caller = '' ): string {
		$detectors = $this->all();
		$rules     = $this->rules();
		$mode      = $rules['detectors'][ $id ] ?? ( $detectors[ $id ]['default'] ?? 'off' );

		/**
		 * Filters the mode of a data rule detector for a caller.
		 *
		 * @param string $mode   off, log, redact or block.
		 * @param string $id     Detector id.
		 * @param string $caller Caller key.
		 */
		$filtered = apply_filters( 'axle_ai_guard_rule_mode', $mode, $id, $caller );

		return in_array( $filtered, self::MODES, true ) ? (string) $filtered : $mode;
	}

	/**
	 * Runs every detector that is not off on a text.
	 *
	 * @param string $text   Text.
	 * @param string $caller Caller key.
	 * @return array<string, array{count: int, mode: string, label: string}> Findings keyed by detector id.
	 */
	public function scan( string $text, string $caller = '' ): array {
		$findings = array();
		if ( '' === $text ) {
			return $findings;
		}

		foreach ( $this->all() as $id => $detector ) {
			$mode = $this->mode( $id, $caller );
			if ( 'off' === $mode ) {
				continue;
			}
			$matches = self::run( $detector['match'], $text );
			if ( $matches ) {
				$findings[ $id ] = array(
					'count' => count( $matches ),
					'mode'  => $mode,
					'label' => $detector['label'],
				);
			}
		}

		return $findings;
	}

	/**
	 * Replaces what the given detectors find with placeholders.
	 *
	 * @param string   $text Text.
	 * @param string[] $ids  Detector ids to redact.
	 * @return array{0: string, 1: array<string, int>} The text and the count per detector.
	 */
	public function redact( string $text, array $ids ): array {
		$counts    = array();
		$detectors = $this->all();

		foreach ( $ids as $id ) {
			if ( ! isset( $detectors[ $id ] ) || '' === $text ) {
				continue;
			}
			$matches = self::run( $detectors[ $id ]['match'], $text );
			if ( ! $matches ) {
				continue;
			}
			// Longest first, so a match inside a longer one does not split it.
			usort(
				$matches,
				static function ( string $a, string $b ): int {
					return strlen( $b ) - strlen( $a );
				}
			);
			$text          = str_replace( $matches, self::placeholder( $id ), $text );
			$counts[ $id ] = count( $matches );
		}

		return array( $text, $counts );
	}

	/**
	 * The placeholder that replaces a match.
	 *
	 * @param string $id Detector id.
	 * @return string
	 */
	public static function placeholder( string $id ): string {
		$default = '[redacted:' . $id . ']';

		/**
		 * Filters the text that replaces redacted data.
		 *
		 * @param string $placeholder Default [redacted:<detector>].
		 * @param string $id          Detector id.
		 */
		$filtered = apply_filters( 'axle_ai_guard_redact_placeholder', $default, $id );

		return Hooks::str( $filtered, $default );
	}

	/**
	 * Runs a match callback and keeps the non-empty unique strings.
	 *
	 * @param callable $matcher Callback.
	 * @param string   $text    Text.
	 * @return string[]
	 */
	private static function run( callable $matcher, string $text ): array {
		$found = call_user_func( $matcher, $text );
		$clean = array();

		foreach ( is_array( $found ) ? $found : array() as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Email addresses in a text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_emails( string $text ): array {
		preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $matches );

		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Email addresses that belong to a user of this site. Each address is looked
	 * up on its own, so no user list is loaded.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_user_emails( string $text ): array {
		$found = array();

		foreach ( array_slice( self::match_emails( $text ), 0, 50 ) as $email ) {
			if ( email_exists( $email ) ) {
				$found[] = $email;
			}
		}

		return $found;
	}

	/**
	 * Phone numbers: 8 to 15 digits, with a + or separators.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_phones( string $text ): array {
		preg_match_all( '/(?<![\w.])\+?\(?\d[\d\s().\-]{6,}\d(?![\w.])/', $text, $matches );
		$found = array();

		foreach ( $matches[0] as $candidate ) {
			$digits = (string) preg_replace( '/\D/', '', $candidate );
			$length = strlen( $digits );
			if ( $length < 8 || $length > 15 ) {
				continue;
			}
			// A bare run of digits is more likely an ID than a phone number, unless it starts with +.
			if ( $digits === $candidate ) {
				continue;
			}
			// Card numbers are a detector of their own.
			if ( $length >= 13 && self::luhn( $digits ) ) {
				continue;
			}
			$found[] = trim( $candidate );
		}

		return $found;
	}

	/**
	 * IPv4 and IPv6 addresses.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_ips( string $text ): array {
		preg_match_all( '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])|(?<![\w:])(?:[A-Fa-f0-9]{0,4}:){2,7}[A-Fa-f0-9]{0,4}(?![\w:])/', $text, $matches );
		$found = array();

		foreach ( $matches[0] as $candidate ) {
			if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$found[] = $candidate;
			}
		}

		return $found;
	}

	/**
	 * Payment card numbers: 13 to 19 digits that pass the Luhn check.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_cards( string $text ): array {
		preg_match_all( '/(?<!\d)(?:\d[ \-]?){12,18}\d(?!\d)/', $text, $matches );
		$found = array();

		foreach ( $matches[0] as $candidate ) {
			$digits = (string) preg_replace( '/\D/', '', $candidate );
			if ( strlen( $digits ) >= 13 && strlen( $digits ) <= 19 && self::luhn( $digits ) ) {
				$found[] = $candidate;
			}
		}

		return $found;
	}

	/**
	 * The Luhn checksum.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	public static function luhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];
			if ( $double ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10 && '' !== trim( $digits, '0' );
	}

	/**
	 * Password hashes: phpass, WordPress 6.8 bcrypt, bcrypt and Argon2.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_hashes( string $text ): array {
		preg_match_all( '/\$P\$[.\/0-9A-Za-z]{31}|\$wp\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}|\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}|\$argon2(?:id|i|d)\$[^\s"\'<>]+/', $text, $matches );

		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * The values of the salts and keys in wp-config.php.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_salts( string $text ): array {
		$values = array();
		foreach ( self::SALTS as $name ) {
			if ( defined( $name ) ) {
				$values[] = (string) constant( $name );
			}
		}

		return self::literal( $text, $values );
	}

	/**
	 * The values of options that hold secrets: connector API keys and the
	 * filterable list.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function match_secrets( string $text ): array {
		$values = array();
		foreach ( self::secret_option_names() as $name ) {
			$value = get_option( $name, '' );
			if ( is_string( $value ) ) {
				$values[] = $value;
			}
		}

		return self::literal( $text, $values );
	}

	/**
	 * Option names that hold secrets.
	 *
	 * @return string[]
	 */
	public static function secret_option_names(): array {
		$names = array();
		if ( function_exists( 'wp_get_connectors' ) ) {
			foreach ( array_keys( (array) wp_get_connectors() ) as $id ) {
				$names[] = 'connectors_ai_' . sanitize_key( (string) $id ) . '_api_key';
			}
		}

		/**
		 * Filters the options whose values must never be sent to an AI provider.
		 *
		 * @param string[] $names Option names. Default: the API key option of every connector.
		 */
		$filtered = apply_filters( 'axle_ai_guard_secret_option_names', $names );

		return array_values( array_unique( array_filter( array_map( 'strval', Hooks::arr( $filtered, $names ) ) ) ) );
	}

	/**
	 * Values of 8 or more characters that appear in a text.
	 *
	 * @param string   $text   Text.
	 * @param string[] $values Secret values.
	 * @return string[]
	 */
	private static function literal( string $text, array $values ): array {
		$found = array();
		foreach ( $values as $value ) {
			if ( strlen( $value ) >= 8 && false !== strpos( $text, $value ) ) {
				$found[] = $value;
			}
		}

		return $found;
	}

	/**
	 * The match callback of a custom rule.
	 *
	 * @param array $rule Custom rule.
	 * @return callable
	 */
	private static function custom_matcher( array $rule ): callable {
		return static function ( string $text ) use ( $rule ): array {
			if ( 'regex' === $rule['type'] ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Patterns were checked when saved; a catastrophic one must not print a warning.
				return @preg_match_all( $rule['pattern'], $text, $matches ) ? array_values( array_unique( array_map( 'strval', $matches[0] ) ) ) : array();
			}

			$found  = array();
			$offset = 0;
			while ( false !== ( $at = stripos( $text, $rule['pattern'], $offset ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$found[] = substr( $text, $at, strlen( $rule['pattern'] ) );
				$offset  = $at + 1;
			}

			return $found;
		};
	}
}
