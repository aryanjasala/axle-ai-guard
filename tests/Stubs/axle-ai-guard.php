<?php
/**
 * Test doubles for Axle AI Guard: an in-memory $wpdb that understands the
 * plugin's own queries, and the constants the plugin reads.
 *
 * @package AryanX\Axle\AiGuard
 */

// phpcs:ignoreFile

foreach ( array(
	'ARRAY_A'         => 'ARRAY_A',
	'KB_IN_BYTES'     => 1024,
	'WP_CONTENT_DIR'  => '/srv/wp/wp-content',
	'WP_PLUGIN_DIR'   => '/srv/wp/wp-content/plugins',
	'WPMU_PLUGIN_DIR' => '/srv/wp/wp-content/mu-plugins',
	'JSON_PRETTY_PRINT'      => 128,
	'JSON_UNESCAPED_SLASHES' => 64,
) as $aag_name => $aag_value ) {
	defined( $aag_name ) || define( $aag_name, $aag_value );
}

defined( 'AryanX\\Axle\\AiGuard\\PLUGIN_FILE' ) || define( 'AryanX\\Axle\\AiGuard\\PLUGIN_FILE', '/srv/wp/wp-content/plugins/axle-ai-guard/axle-ai-guard.php' );
defined( 'AryanX\\Axle\\AiGuard\\VERSION' ) || define( 'AryanX\\Axle\\AiGuard\\VERSION', '1.0.0' );

require __DIR__ . '/ai-client.php';

if ( ! class_exists( 'WP_User' ) ) {
	#[\AllowDynamicProperties]
	class WP_User {
		/** @var int */
		public $ID = 0;
		/** @var string[] */
		public $roles = array();
		/** @var string */
		public $display_name = '';

		public function __construct( int $id = 0, array $roles = array() ) {
			$this->ID    = $id;
			$this->roles = $roles;
		}
	}
}

/**
 * A stand-in for $wpdb with an in-memory calls table and usage table.
 */
class Aag_Fake_Wpdb {

	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $options = 'wp_options';
	/** @var int */
	public $insert_id = 0;
	/** @var array<int, array> Rows of the calls table by id. */
	public $calls = array();
	/** @var array<string, array> Usage rows keyed by period|subject. */
	public $usage = array();
	/** @var array<int, array> Prompt store rows by call id. */
	public $prompts = array();
	/** @var string[] Other queries run. */
	public $queries = array();
	/** @var mixed The next get_var() answer for queries the fake does not understand. */
	public $next_var = null;
	/** @var array The next get_results() / get_col() answer. */
	public $next_results = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'sql' => $query, 'args' => $args );
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	public function insert( $table, $data, $format = null ) {
		if ( 'wp_axle_ai_guard_calls' !== $table ) {
			return false;
		}
		$this->insert_id = count( $this->calls ) + 1;
		$this->calls[ $this->insert_id ] = array_merge( self::blank_call(), $data, array( 'id' => $this->insert_id ) );
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( 'wp_axle_ai_guard_calls' === $table && isset( $this->calls[ $where['id'] ] ) ) {
			$this->calls[ $where['id'] ] = array_merge( $this->calls[ $where['id'] ], $data );
			return 1;
		}
		return false;
	}

	public function replace( $table, $data, $format = null ) {
		$this->prompts[ $data['call_id'] ] = $data;
		return 1;
	}

	public function delete( $table, $where, $format = null ) {
		unset( $this->prompts[ $where['call_id'] ?? 0 ] );
		return 1;
	}

	public function query( $query ) {
		$sql = is_array( $query ) ? $query['sql'] : (string) $query;
		if ( 0 === strpos( $sql, 'INSERT INTO %i (period' ) ) {
			list( , $period, $subject, $calls, $tokens, $cost, $blocked, , , $delta ) = $query['args'];
			$key = $period . '|' . $subject;
			$row = $this->usage[ $key ] ?? array( 'calls' => 0, 'tokens' => 0, 'cost_micros' => 0, 'blocked' => 0 );
			if ( isset( $this->usage[ $key ] ) ) {
				$row['calls']      += $calls;
				$row['tokens']     += $tokens;
				$row['cost_micros'] = max( 0, $row['cost_micros'] + $delta );
				$row['blocked']    += $blocked;
			} else {
				$row = array( 'calls' => $calls, 'tokens' => $tokens, 'cost_micros' => $cost, 'blocked' => $blocked );
			}
			$this->usage[ $key ] = $row;
			return 1;
		}
		$this->queries[] = $query;
		return 0;
	}

	public function get_row( $query, $output = null ) {
		$sql = is_array( $query ) ? $query['sql'] : (string) $query;
		if ( 0 === strpos( $sql, 'SELECT calls, tokens, cost_micros, blocked' ) ) {
			return $this->usage[ $query['args'][1] . '|' . $query['args'][2] ] ?? null;
		}
		if ( 0 === strpos( $sql, 'SELECT * FROM %i WHERE id' ) ) {
			return $this->calls[ $query['args'][1] ] ?? null;
		}
		if ( false !== strpos( $sql, "SUM(status IN ('ok','error'))" ) ) {
			$calls  = 0;
			$errors = 0;
			foreach ( $this->calls as $row ) {
				$calls  += in_array( $row['status'], array( 'ok', 'error' ), true ) ? 1 : 0;
				$errors += 'error' === $row['status'] ? 1 : 0;
			}
			return array( 'calls' => $calls, 'errors' => $errors );
		}
		$this->queries[] = $query;
		return null;
	}

	public function get_var( $query ) {
		$sql = is_array( $query ) ? $query['sql'] : (string) $query;
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $query['args'][0];
		}
		$this->queries[] = $query;
		return $this->next_var;
	}

	public function get_results( $query, $output = null ) {
		$this->queries[] = $query;
		return $this->next_results;
	}

	public function get_col( $query ) {
		$this->queries[] = $query;
		return $this->next_results;
	}

	public static function blank_call(): array {
		return array(
			'id' => 0, 'started_at' => '2026-10-30 10:00:00', 'duration_ms' => 0, 'status' => 'pending', 'block_rule' => '', 'path' => 'client',
			'caller_type' => '', 'caller_slug' => '', 'caller_file' => '', 'user_id' => 0, 'context' => '', 'ip' => '', 'provider' => '', 'model' => '',
			'capability' => '', 'prompt_tokens' => 0, 'completion_tokens' => 0, 'thought_tokens' => 0, 'total_tokens' => 0, 'estimated' => 0,
			'cost_micros' => 0, 'currency' => '', 'request_bytes' => 0, 'response_bytes' => 0, 'http_status' => 0, 'error_code' => '', 'profile' => null, 'preview' => null,
		);
	}
}

if ( ! class_exists( 'WP_AI_Client_Prompt_Builder' ) ) {
	/**
	 * Calls a callback from inside __call(), so the backtrace looks like the
	 * core prompt builder's.
	 */
	class WP_AI_Client_Prompt_Builder {
		/** @var callable */
		public $inner;

		public function __construct( callable $inner ) {
			$this->inner = $inner;
		}

		public function __call( $name, $arguments ) {
			return call_user_func( $this->inner );
		}
	}
}
