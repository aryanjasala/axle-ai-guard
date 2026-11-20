<?php
/**
 * Requests for more AI: what a blocked person asks for, and what the admin does about it.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A request is a short record: who, which rule stopped them, which plugin they
 * were using, when, and what happened to it (open, allowed or dismissed).
 * They live in one option, newest last, capped, and are removed after a month.
 * The rule and plugin come from what AI Guard itself recorded when it blocked
 * the person's call (a short transient), never from what the browser sends.
 */
final class Requests {

	public const OPTION = 'axle_ai_guard_requests';

	/**
	 * Most requests kept.
	 */
	private const MAX = 50;

	/**
	 * Allowances.
	 *
	 * @var Allowance
	 */
	private Allowance $allowance;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up requests.
	 *
	 * @param Allowance $allowance Allowances.
	 * @param Alerts    $alerts    Alerts.
	 */
	public function __construct( Allowance $allowance, Alerts $alerts ) {
		$this->allowance = $allowance;
		$this->alerts    = $alerts;
	}

	/**
	 * Every stored request, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$saved = get_option( self::OPTION, array() );

		return is_array( $saved ) ? array_values( array_filter( $saved, 'is_array' ) ) : array();
	}

	/**
	 * Requests nobody has dealt with, newest last.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function open(): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $request ): bool {
					return 'open' === ( $request['status'] ?? '' );
				}
			)
		);
	}

	/**
	 * One request.
	 *
	 * @param string $id Request id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		foreach ( $this->all() as $request ) {
			if ( (string) ( $request['id'] ?? '' ) === $id ) {
				return $request;
			}
		}

		return null;
	}

	/**
	 * A person's requests, for the privacy exporter.
	 *
	 * @param int $user_id User.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $request ) use ( $user_id ): bool {
					return (int) ( $request['user_id'] ?? 0 ) === $user_id;
				}
			)
		);
	}

	/**
	 * Remembers what just blocked a person, so a request can say it without trusting the browser.
	 *
	 * @param int    $user_id User.
	 * @param string $rule    Rule.
	 * @param string $caller  Caller key.
	 */
	public function remember_block( int $user_id, string $rule, string $caller ): void {
		set_transient(
			'axle_ai_guard_lastblock_' . $user_id,
			array(
				'rule'   => substr( $rule, 0, 64 ),
				'caller' => substr( $caller, 0, 100 ),
				'at'     => time(),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * What last blocked a person.
	 *
	 * @param int $user_id User.
	 * @return array{rule: string, caller: string, at: int}|null
	 */
	public function last_block( int $user_id ): ?array {
		$saved = get_transient( 'axle_ai_guard_lastblock_' . $user_id );

		return is_array( $saved ) && isset( $saved['rule'] ) ? array(
			'rule'   => (string) $saved['rule'],
			'caller' => (string) ( $saved['caller'] ?? '' ),
			'at'     => (int) ( $saved['at'] ?? 0 ),
		) : null;
	}

	/**
	 * A person asks for more. One open request per person: asking again refreshes it.
	 *
	 * @param int $user_id User.
	 * @return array{request: array<string, mixed>, created: bool}|null Null when nothing blocked the person lately.
	 */
	public function ask( int $user_id ): ?array {
		$block = $this->last_block( $user_id );
		if ( null === $block || $user_id <= 0 ) {
			return null;
		}

		$items   = $this->all();
		$created = true;
		$entry   = null;
		foreach ( $items as $index => $request ) {
			if ( (int) ( $request['user_id'] ?? 0 ) === $user_id && 'open' === ( $request['status'] ?? '' ) ) {
				$items[ $index ]['rule']   = $block['rule'];
				$items[ $index ]['caller'] = $block['caller'];
				$entry                     = $items[ $index ];
				$created                   = false;
				break;
			}
		}

		if ( null === $entry ) {
			$entry   = array(
				'id'      => substr( md5( $user_id . '|' . microtime( true ) . '|' . wp_rand() ), 0, 12 ),
				'user_id' => $user_id,
				'rule'    => $block['rule'],
				'caller'  => $block['caller'],
				'at'      => time(),
				'status'  => 'open',
				'by'      => 0,
				'handled' => 0,
				'calls'   => 0,
			);
			$items[] = $entry;
		}

		$this->save( $items );

		if ( $created ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->display_name : (string) $user_id;

			/**
			 * Fires when a blocked person asks for more AI.
			 *
			 * @param array $request id, user_id, rule, caller, at and status.
			 */
			do_action( 'axle_ai_guard_access_requested', $entry );

			$this->alerts->send(
				'access_request',
				'user:' . $user_id,
				sprintf(
					/* translators: 1: person's name, 2: plugin key. */
					__( '%1$s asked for more AI after a call from %2$s was blocked. You can allow it for today on the AI Guard screen.', 'axle-ai-guard' ),
					$name,
					'' !== $entry['caller'] ? (string) $entry['caller'] : __( 'a plugin', 'axle-ai-guard' )
				),
				array(
					'user_id' => $user_id,
					'rule'    => $entry['rule'],
					'caller'  => $entry['caller'],
				)
			);
		}

		return array(
			'request' => $entry,
			'created' => $created,
		);
	}

	/**
	 * Allows the person of a request for today.
	 *
	 * @param string $id    Request id.
	 * @param int    $by    Admin.
	 * @param int    $calls Calls, 0 for the default.
	 * @return array{request: array<string, mixed>, calls: int}|null Null when the request is gone or already handled.
	 */
	public function allow( string $id, int $by, int $calls = 0 ): ?array {
		$request = $this->get( $id );
		if ( ! $request || 'open' !== $request['status'] ) {
			return null;
		}

		$granted = $this->allowance->grant( (int) $request['user_id'], $calls );
		$request = $this->set_status( $id, 'allowed', $by, $granted );

		/**
		 * Fires when an admin allows a person for the rest of today.
		 *
		 * @param int   $user_id The person.
		 * @param int   $calls   Calls granted.
		 * @param int   $by      The admin.
		 * @param array $request The request.
		 */
		do_action( 'axle_ai_guard_allowance_granted', (int) $request['user_id'], $granted, $by, $request );

		return array(
			'request' => $request,
			'calls'   => $granted,
		);
	}

	/**
	 * Allows a person who did not ask (WP-CLI and abilities).
	 *
	 * @param int $user_id User.
	 * @param int $by      Admin.
	 * @param int $calls   Calls, 0 for the default.
	 * @return int Calls granted.
	 */
	public function allow_user( int $user_id, int $by, int $calls = 0 ): int {
		$granted = $this->allowance->grant( $user_id, $calls );

		// A request waiting for this person is answered by the same grant.
		foreach ( $this->open() as $request ) {
			if ( (int) $request['user_id'] === $user_id ) {
				$this->set_status( (string) $request['id'], 'allowed', $by, $granted );
			}
		}

		/** This action is documented in src/Requests.php. */
		do_action( 'axle_ai_guard_allowance_granted', $user_id, $granted, $by, array() );

		return $granted;
	}

	/**
	 * Takes back what an allow gave: ends the rest of today's grant and opens the request again.
	 *
	 * @param string $id Request id.
	 * @param int    $by Admin.
	 * @return array<string, mixed>|null The request, or null when it is not an allowed one.
	 */
	public function undo( string $id, int $by ): ?array {
		$request = $this->get( $id );
		if ( ! $request || 'allowed' !== $request['status'] ) {
			return null;
		}

		$this->revoke_user( (int) $request['user_id'], $by );

		return $this->set_status( $id, 'open', 0, 0 );
	}

	/**
	 * Ends a person's grant for today.
	 *
	 * @param int $user_id User.
	 * @param int $by      Admin.
	 * @return int Calls that were left.
	 */
	public function revoke_user( int $user_id, int $by ): int {
		$left = $this->allowance->revoke( $user_id );

		/**
		 * Fires when a person's allowance for today is ended by hand.
		 *
		 * @param int $user_id The person.
		 * @param int $left    Calls that were still left.
		 * @param int $by      The admin.
		 */
		do_action( 'axle_ai_guard_allowance_revoked', $user_id, $left, $by );

		return $left;
	}

	/**
	 * Dismisses a request without allowing anything.
	 *
	 * @param string $id Request id.
	 * @param int    $by Admin.
	 * @return array<string, mixed>|null
	 */
	public function dismiss( string $id, int $by ): ?array {
		$request = $this->get( $id );
		if ( ! $request || 'open' !== $request['status'] ) {
			return null;
		}

		return $this->set_status( $id, 'dismissed', $by, 0 );
	}

	/**
	 * Removes every request of a person, for the privacy eraser.
	 *
	 * @param int $user_id User.
	 * @return int Requests removed.
	 */
	public function erase_user( int $user_id ): int {
		delete_transient( 'axle_ai_guard_lastblock_' . $user_id );

		$items = $this->all();
		$kept  = array_values(
			array_filter(
				$items,
				static function ( array $request ) use ( $user_id ): bool {
					return (int) ( $request['user_id'] ?? 0 ) !== $user_id;
				}
			)
		);
		if ( count( $kept ) !== count( $items ) ) {
			$this->save( $kept );
		}

		return count( $items ) - count( $kept );
	}

	/**
	 * Removes requests older than the retention.
	 *
	 * @return int Requests removed.
	 */
	public function prune(): int {
		/**
		 * Filters how many days requests for more AI are kept.
		 *
		 * @param int $days Default 30.
		 */
		$days  = max( 1, (int) apply_filters( 'axle_ai_guard_request_retention_days', 30 ) );
		$cut   = time() - $days * DAY_IN_SECONDS;
		$items = $this->all();
		$kept  = array_values(
			array_filter(
				$items,
				static function ( array $request ) use ( $cut ): bool {
					return (int) ( $request['at'] ?? 0 ) >= $cut;
				}
			)
		);
		if ( count( $kept ) !== count( $items ) ) {
			$this->save( $kept );
		}

		return count( $items ) - count( $kept );
	}

	/**
	 * Changes a request's status.
	 *
	 * @param string $id     Request id.
	 * @param string $status open, allowed or dismissed.
	 * @param int    $by     Admin.
	 * @param int    $calls  Calls granted.
	 * @return array<string, mixed>
	 */
	private function set_status( string $id, string $status, int $by, int $calls ): array {
		$items = $this->all();
		$found = array();
		foreach ( $items as $index => $request ) {
			if ( (string) ( $request['id'] ?? '' ) === $id ) {
				$items[ $index ]['status']  = $status;
				$items[ $index ]['by']      = $by;
				$items[ $index ]['handled'] = 'open' === $status ? 0 : time();
				$items[ $index ]['calls']   = $calls;
				$found                      = $items[ $index ];
			}
		}
		$this->save( $items );

		return $found;
	}

	/**
	 * Saves the list, newest last, capped.
	 *
	 * @param array<int, array<string, mixed>> $items Requests.
	 */
	private function save( array $items ): void {
		update_option( self::OPTION, array_slice( array_values( $items ), -self::MAX ), false );
	}
}
