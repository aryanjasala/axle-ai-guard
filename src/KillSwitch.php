<?php
/**
 * Kill switches: global, network, per caller, provider and context.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A switch that is on stops AI calls in its scope. Each one records who
 * turned it on, when and why. The AXLE_AI_GUARD_KILL constant and the
 * network switch on multisite win over everything.
 */
final class KillSwitch implements Service {

	public const OPTION         = 'axle_ai_guard_kill';
	public const NETWORK_OPTION = 'axle_ai_guard_network_kill';
	public const SCOPES         = array( 'global', 'plugin', 'provider', 'context' );

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the switches.
	 *
	 * @param Alerts $alerts Alerts.
	 */
	public function __construct( Alerts $alerts ) {
		$this->alerts = $alerts;
	}

	/**
	 * Adds the admin bar node.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
	}

	/**
	 * The stored switches.
	 *
	 * @return array{global: array|null, plugin: array, provider: array, context: array}
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'global'   => isset( $saved['global'] ) && is_array( $saved['global'] ) ? $saved['global'] : null,
			'plugin'   => isset( $saved['plugin'] ) && is_array( $saved['plugin'] ) ? $saved['plugin'] : array(),
			'provider' => isset( $saved['provider'] ) && is_array( $saved['provider'] ) ? $saved['provider'] : array(),
			'context'  => isset( $saved['context'] ) && is_array( $saved['context'] ) ? $saved['context'] : array(),
		);
	}

	/**
	 * Whether the wp-config.php constant is on.
	 *
	 * @return bool
	 */
	public static function constant_on(): bool {
		return defined( 'AXLE_AI_GUARD_KILL' ) && AXLE_AI_GUARD_KILL;
	}

	/**
	 * The network switch, when on.
	 *
	 * @return array|null
	 */
	public static function network(): ?array {
		if ( ! is_multisite() ) {
			return null;
		}
		$value = get_site_option( self::NETWORK_OPTION, array() );

		return is_array( $value ) && ! empty( $value['on'] ) ? $value : null;
	}

	/**
	 * Whether every AI call on this site is off.
	 *
	 * @return bool
	 */
	public function global_on(): bool {
		return self::constant_on() || null !== self::network() || null !== $this->state()['global'];
	}

	/**
	 * Whether a switch is on.
	 *
	 * @param string $scope   global, plugin, provider or context.
	 * @param string $subject Caller key, provider id or context.
	 * @return bool
	 */
	public function is_on( string $scope, string $subject = '' ): bool {
		if ( 'global' === $scope ) {
			return $this->global_on();
		}
		$state = $this->state();

		return in_array( $scope, self::SCOPES, true ) && '' !== $subject && isset( $state[ $scope ][ $subject ] );
	}

	/**
	 * Whether any switch is on.
	 *
	 * @return bool
	 */
	public function any_on(): bool {
		$state = $this->state();

		return $this->global_on() || $state['plugin'] || $state['provider'] || $state['context'];
	}

	/**
	 * The switch rule that stops a call, or ''.
	 *
	 * @param array $call Call details.
	 * @return string kill:global, kill:context, kill:plugin, kill:provider or ''.
	 */
	public function rule_for( array $call ): string {
		if ( $this->global_on() ) {
			return 'kill:global';
		}
		if ( $this->is_on( 'context', (string) ( $call['context'] ?? '' ) ) ) {
			return 'kill:context';
		}
		if ( $this->is_on( 'plugin', (string) ( $call['caller']['slug'] ?? '' ) ) ) {
			return 'kill:plugin';
		}
		if ( $this->is_on( 'provider', (string) ( $call['provider'] ?? '' ) ) ) {
			return 'kill:provider';
		}

		return '';
	}

	/**
	 * Every switch that is on, as a list.
	 *
	 * @return array<int, array{scope: string, subject: string, reason: string, by: int, at: int, source: string}>
	 */
	public function active(): array {
		$list = array();
		if ( self::constant_on() ) {
			$list[] = array(
				'scope'   => 'global',
				'subject' => '',
				'reason'  => 'AXLE_AI_GUARD_KILL',
				'by'      => 0,
				'at'      => 0,
				'source'  => 'constant',
			);
		}
		$network = self::network();
		if ( $network ) {
			$list[] = self::entry( 'global', '', $network, 'network' );
		}

		$state = $this->state();
		if ( $state['global'] ) {
			$list[] = self::entry( 'global', '', $state['global'], 'site' );
		}
		foreach ( array( 'plugin', 'provider', 'context' ) as $scope ) {
			foreach ( $state[ $scope ] as $subject => $info ) {
				$list[] = self::entry( $scope, (string) $subject, (array) $info, 'site' );
			}
		}

		return $list;
	}

	/**
	 * Turns a switch on.
	 *
	 * @param string $scope   global, plugin, provider or context.
	 * @param string $subject Caller key, provider id or context; '' for global.
	 * @param string $reason  Why.
	 * @param int    $user_id Who, 0 for the plugin itself.
	 * @return bool Whether it changed.
	 */
	public function engage( string $scope, string $subject, string $reason, int $user_id ): bool {
		$subject = self::clean_subject( $scope, $subject );
		if ( ! in_array( $scope, self::SCOPES, true ) || ( 'global' !== $scope && '' === $subject ) ) {
			return false;
		}

		$state = $this->state();
		$info  = array(
			'reason' => sanitize_text_field( $reason ),
			'by'     => $user_id,
			'at'     => time(),
		);

		if ( 'global' === $scope ) {
			if ( $state['global'] ) {
				return false;
			}
			$state['global'] = $info;
		} else {
			if ( isset( $state[ $scope ][ $subject ] ) ) {
				return false;
			}
			$state[ $scope ][ $subject ] = $info;
		}
		update_option( self::OPTION, $state, false );

		/**
		 * Fires when a kill switch is turned on.
		 *
		 * @param string $scope   global, plugin, provider or context.
		 * @param string $subject Caller key, provider id or context; '' for global.
		 * @param array  $info    reason, by (user id) and at (Unix time).
		 */
		do_action( 'axle_ai_guard_kill_switch_on', $scope, $subject, $info );

		$this->alerts->send(
			'kill_on',
			$scope . ':' . $subject,
			sprintf(
				/* translators: 1: what was switched off, 2: reason. */
				__( 'AI calls were switched off for %1$s. Reason: %2$s', 'axle-ai-guard' ),
				self::describe( $scope, $subject ),
				'' !== $info['reason'] ? $info['reason'] : __( 'none given', 'axle-ai-guard' )
			),
			array(
				'scope'   => $scope,
				'subject' => $subject,
			)
		);

		return true;
	}

	/**
	 * Turns a switch off.
	 *
	 * @param string $scope   global, plugin, provider or context.
	 * @param string $subject Caller key, provider id or context; '' for global.
	 * @param int    $user_id Who.
	 * @return bool Whether it changed.
	 */
	public function release( string $scope, string $subject, int $user_id ): bool {
		$subject = self::clean_subject( $scope, $subject );
		$state   = $this->state();

		if ( 'global' === $scope && $state['global'] ) {
			$state['global'] = null;
		} elseif ( in_array( $scope, array( 'plugin', 'provider', 'context' ), true ) && isset( $state[ $scope ][ $subject ] ) ) {
			unset( $state[ $scope ][ $subject ] );
		} else {
			return false;
		}
		update_option( self::OPTION, $state, false );

		/**
		 * Fires when a kill switch is turned off.
		 *
		 * @param string $scope   global, plugin, provider or context.
		 * @param string $subject Caller key, provider id or context; '' for global.
		 * @param int    $user_id Who turned it off.
		 */
		do_action( 'axle_ai_guard_kill_switch_off', $scope, $subject, $user_id );

		$this->alerts->send(
			'kill_off',
			$scope . ':' . $subject,
			sprintf(
				/* translators: %s: what was switched back on. */
				__( 'AI calls were switched back on for %s.', 'axle-ai-guard' ),
				self::describe( $scope, $subject )
			),
			array(
				'scope'   => $scope,
				'subject' => $subject,
			)
		);

		return true;
	}

	/**
	 * Turns every site switch off.
	 *
	 * @param int $user_id Who.
	 * @return int How many were on.
	 */
	public function release_all( int $user_id ): int {
		$count = 0;
		foreach ( $this->active() as $entry ) {
			if ( 'site' === $entry['source'] && $this->release( $entry['scope'], $entry['subject'], $user_id ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Shows "AI is off" in the admin bar while any switch is on.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		if ( ! current_user_can( Plugin::capability() ) || ! $this->any_on() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'axle-ai-guard',
				'title' => $this->global_on() ? esc_html__( 'AI is off', 'axle-ai-guard' ) : esc_html__( 'AI partly off', 'axle-ai-guard' ),
				'href'  => admin_url( 'tools.php?page=axle-ai-guard' ),
				'meta'  => array( 'class' => 'aag-adminbar' ),
			)
		);
	}

	/**
	 * Words for a scope and subject.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @return string
	 */
	public static function describe( string $scope, string $subject ): string {
		switch ( $scope ) {
			case 'plugin':
				/* translators: %s: caller key. */
				return sprintf( __( 'the caller %s', 'axle-ai-guard' ), $subject );
			case 'provider':
				/* translators: %s: provider id. */
				return sprintf( __( 'the provider %s', 'axle-ai-guard' ), $subject );
			case 'context':
				/* translators: %s: request context such as front. */
				return sprintf( __( 'the %s context', 'axle-ai-guard' ), $subject );
			default:
				return __( 'the whole site', 'axle-ai-guard' );
		}
	}

	/**
	 * A clean subject for a scope.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Raw subject.
	 * @return string
	 */
	private static function clean_subject( string $scope, string $subject ): string {
		if ( 'global' === $scope ) {
			return '';
		}
		$subject = strtolower( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', $subject ) );

		return 'context' === $scope && ! in_array( $subject, Settings::CONTEXTS, true ) ? '' : substr( $subject, 0, 100 );
	}

	/**
	 * A list entry.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param array  $info    Stored info.
	 * @param string $source  site or network.
	 * @return array{scope: string, subject: string, reason: string, by: int, at: int, source: string}
	 */
	private static function entry( string $scope, string $subject, array $info, string $source ): array {
		return array(
			'scope'   => $scope,
			'subject' => $subject,
			'reason'  => (string) ( $info['reason'] ?? '' ),
			'by'      => (int) ( $info['by'] ?? 0 ),
			'at'      => (int) ( $info['at'] ?? 0 ),
			'source'  => $source,
		);
	}
}
