<?php
/**
 * The call log and the optional prompt store.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables; rows change on every AI call.

/**
 * One row per AI call: who made it, what it carried, what it cost and how it ended.
 */
final class Calls {

	public const STATUSES = array( 'pending', 'ok', 'error', 'blocked' );
	public const PATHS    = array( 'client', 'embedding', 'direct' );

	private const COLUMNS = array(
		'started_at'        => '%s',
		'duration_ms'       => '%d',
		'status'            => '%s',
		'block_rule'        => '%s',
		'path'              => '%s',
		'caller_type'       => '%s',
		'caller_slug'       => '%s',
		'caller_file'       => '%s',
		'user_id'           => '%d',
		'context'           => '%s',
		'ip'                => '%s',
		'provider'          => '%s',
		'model'             => '%s',
		'capability'        => '%s',
		'prompt_tokens'     => '%d',
		'completion_tokens' => '%d',
		'thought_tokens'    => '%d',
		'total_tokens'      => '%d',
		'estimated'         => '%d',
		'cost_micros'       => '%d',
		'currency'          => '%s',
		'request_bytes'     => '%d',
		'response_bytes'    => '%d',
		'http_status'       => '%d',
		'error_code'        => '%s',
		'profile'           => '%s',
		'preview'           => '%s',
	);

	/**
	 * A row for a call from its details.
	 *
	 * @param array  $call   Call details from Context::call().
	 * @param string $status pending, ok, error or blocked.
	 * @return array<string, mixed>
	 */
	public static function row_for( array $call, string $status ): array {
		$caller = (array) ( $call['caller'] ?? array() );

		return array(
			'started_at'  => gmdate( 'Y-m-d H:i:s' ),
			'status'      => $status,
			'path'        => (string) ( $call['path'] ?? 'client' ),
			'caller_type' => (string) ( $caller['type'] ?? '' ),
			'caller_slug' => (string) ( $caller['slug'] ?? '' ),
			'caller_file' => trim( (string) ( $caller['file'] ?? '' ) . ( empty( $caller['line'] ) ? '' : ':' . (int) $caller['line'] ) ),
			'user_id'     => (int) ( $call['user_id'] ?? 0 ),
			'context'     => (string) ( $call['context'] ?? '' ),
			'ip'          => (string) ( $call['ip'] ?? '' ),
			'provider'    => (string) ( $call['provider'] ?? '' ),
			'model'       => (string) ( $call['model'] ?? '' ),
			'capability'  => (string) ( $call['capability'] ?? '' ),
		);
	}

	/**
	 * Adds a row.
	 *
	 * @param array $row Columns.
	 * @return int The new id, 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$data = $this->only_columns( $row );
		if ( ! $wpdb->insert( Schema::table( 'calls' ), $data, $this->formats( $data ) ) ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Changes a row.
	 *
	 * @param int   $id  Row id.
	 * @param array $row Columns to change.
	 * @return bool
	 */
	public function update( int $id, array $row ): bool {
		global $wpdb;

		$data = $this->only_columns( $row );
		if ( ! $data ) {
			return false;
		}

		return false !== $wpdb->update( Schema::table( 'calls' ), $data, array( 'id' => $id ), $this->formats( $data ), array( '%d' ) );
	}

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'calls' ), $id ), ARRAY_A );

		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * Rows matching filters, newest first.
	 *
	 * @param array $args status, caller, provider, model, context, user_id, since, until, path, page, per_page.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array( Schema::table( 'calls' ) );

		$exact = array(
			'status'   => 'status',
			'caller'   => 'caller_slug',
			'provider' => 'provider',
			'model'    => 'model',
			'context'  => 'context',
			'path'     => 'path',
		);
		foreach ( $exact as $arg => $column ) {
			if ( isset( $args[ $arg ] ) && '' !== (string) $args[ $arg ] ) {
				$where[]  = "{$column} = %s";
				$values[] = (string) $args[ $arg ];
			}
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'started_at >= %s';
			$values[] = (string) $args['since'];
		}
		if ( ! empty( $args['until'] ) ) {
			$where[]  = 'started_at <= %s';
			$values[] = (string) $args['until'];
		}

		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$clause   = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $clause only holds column names and placeholders, filled from $values.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$clause}", $values ) );
		$rows  = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- As above.
			$wpdb->prepare( "SELECT * FROM %i WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);

		return array(
			'rows'  => array_map( array( self::class, 'decode' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Distinct values of a column, for filters.
	 *
	 * @param string $column caller_slug, provider, model or context.
	 * @return string[]
	 */
	public function distinct( string $column ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'caller_slug', 'provider', 'model', 'context' ), true ) ) {
			return array();
		}

		$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT %i FROM %i WHERE %i <> '' ORDER BY %i LIMIT 200", $column, Schema::table( 'calls' ), $column, $column ) );

		return array_map( 'strval', is_array( $values ) ? $values : array() );
	}

	/**
	 * Totals per caller, provider and model since a time.
	 *
	 * @param string $group  caller_slug, provider, model or user_id.
	 * @param string $since  UTC datetime.
	 * @return array<int, array<string, mixed>>
	 */
	public function breakdown( string $group, string $since ): array {
		global $wpdb;

		if ( ! in_array( $group, array( 'caller_slug', 'provider', 'model', 'user_id' ), true ) ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT %i AS name, COUNT(*) AS calls, SUM(total_tokens) AS tokens, SUM(cost_micros) AS cost, SUM(status = 'blocked') AS blocked, SUM(status = 'error') AS errors, MAX(started_at) AS last_call FROM %i WHERE started_at >= %s GROUP BY %i ORDER BY calls DESC LIMIT 200",
				$group,
				Schema::table( 'calls' ),
				$since,
				$group
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Error and total counts of finished calls since a time.
	 *
	 * @param string $since UTC datetime.
	 * @return array{calls: int, errors: int}
	 */
	public function error_rate( string $since ): array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status IN ('ok','error')) AS calls, SUM(status = 'error') AS errors FROM %i WHERE started_at >= %s", Schema::table( 'calls' ), $since ), ARRAY_A );

		return array(
			'calls'  => (int) ( $row['calls'] ?? 0 ),
			'errors' => (int) ( $row['errors'] ?? 0 ),
		);
	}

	/**
	 * Call counts since a time: all, by unknown callers, and successful ones the transport never saw, per provider.
	 *
	 * @param string $since UTC datetime.
	 * @return array{calls: int, unknown: int, untransported: string[]}
	 */
	public function health( string $since ): array {
		global $wpdb;

		$table = Schema::table( 'calls' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS calls, SUM(caller_slug = 'unknown') AS unknown FROM %i WHERE started_at >= %s AND status <> 'blocked'", $table, $since ), ARRAY_A );
		$own   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT provider FROM %i WHERE started_at >= %s AND status = 'ok' AND path = 'client' AND request_bytes = 0 AND provider <> ''", $table, $since ) );

		return array(
			'calls'         => (int) ( $row['calls'] ?? 0 ),
			'unknown'       => (int) ( $row['unknown'] ?? 0 ),
			'untransported' => array_map( 'strval', is_array( $own ) ? $own : array() ),
		);
	}

	/**
	 * Successful calls since a time, for recalculating costs.
	 *
	 * @param string $since  UTC datetime.
	 * @param int    $after  Last id handled.
	 * @param int    $limit  Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function priced_batch( string $since, int $after, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, started_at, caller_slug, provider, model, user_id, prompt_tokens, completion_tokens, thought_tokens, cost_micros FROM %i WHERE status = 'ok' AND started_at >= %s AND id > %d ORDER BY id ASC LIMIT %d",
				Schema::table( 'calls' ),
				$since,
				$after,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A user's calls, for the personal data exporter.
	 *
	 * @param int $user_id User id.
	 * @param int $page    Page, from 1.
	 * @param int $per     Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id, int $page, int $per ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', Schema::table( 'calls' ), $user_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes the user, IP address, preview and stored prompt from a user's calls.
	 *
	 * @param int $user_id User id.
	 * @param int $limit   Rows per batch.
	 * @return int Rows changed.
	 */
	public function erase_user( int $user_id, int $limit ): int {
		global $wpdb;

		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d LIMIT %d', Schema::table( 'calls' ), $user_id, $limit ) );
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		foreach ( $ids as $id ) {
			$wpdb->update(
				Schema::table( 'calls' ),
				array(
					'user_id' => 0,
					'ip'      => '',
					'preview' => null,
				),
				array( 'id' => $id ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
			$wpdb->delete( Schema::table( 'prompts' ), array( 'call_id' => $id ), array( '%d' ) );
		}

		return count( $ids );
	}

	/**
	 * Stores a redacted prompt for debugging.
	 *
	 * @param int   $call_id  Call id.
	 * @param array $messages Redacted messages.
	 * @param int   $days     Days to keep it.
	 */
	public function store_prompt( int $call_id, array $messages, int $days ): void {
		global $wpdb;

		$wpdb->replace(
			Schema::table( 'prompts' ),
			array(
				'call_id'           => $call_id,
				'redacted_messages' => (string) wp_json_encode( $messages ),
				'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + max( 1, $days ) * DAY_IN_SECONDS ),
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * A stored redacted prompt.
	 *
	 * @param int $call_id Call id.
	 * @return array|null
	 */
	public function prompt( int $call_id ): ?array {
		global $wpdb;

		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT redacted_messages FROM %i WHERE call_id = %d AND expires_at > %s', Schema::table( 'prompts' ), $call_id, gmdate( 'Y-m-d H:i:s' ) ) );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Deletes up to a batch of rows older than a time.
	 *
	 * @param string $table calls or prompts.
	 * @param string $before UTC datetime.
	 * @param int    $limit Batch size.
	 * @return int Rows deleted.
	 */
	public function prune( string $table, string $before, int $limit ): int {
		global $wpdb;

		$column = 'prompts' === $table ? 'expires_at' : 'started_at';
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE %i < %s LIMIT %d', Schema::table( 'prompts' === $table ? 'prompts' : 'calls' ), $column, $before, $limit ) );

		return (int) $result;
	}

	/**
	 * Decodes the JSON columns of a row.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed>
	 */
	public static function decode( array $row ): array {
		$profile        = json_decode( (string) ( $row['profile'] ?? '' ), true );
		$row['profile'] = is_array( $profile ) ? $profile : array();
		foreach ( array( 'id', 'duration_ms', 'user_id', 'prompt_tokens', 'completion_tokens', 'thought_tokens', 'total_tokens', 'estimated', 'cost_micros', 'request_bytes', 'response_bytes', 'http_status' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}

		return $row;
	}

	/**
	 * Keeps only known columns and encodes the profile.
	 *
	 * @param array $row Columns.
	 * @return array<string, mixed>
	 */
	private function only_columns( array $row ): array {
		$data = array_intersect_key( $row, self::COLUMNS );
		if ( isset( $data['profile'] ) && is_array( $data['profile'] ) ) {
			$data['profile'] = (string) wp_json_encode( $data['profile'] );
		}

		return $data;
	}

	/**
	 * The formats of the given columns.
	 *
	 * @param array $data Columns.
	 * @return string[]
	 */
	private function formats( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMNS[ $column ];
		}

		return $formats;
	}
}
