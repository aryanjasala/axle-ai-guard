<?php
/**
 * Tests for "Allow for today", requests for more AI and the wall.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Allowance;
use AryanX\Axle\AiGuard\Privacy;
use AryanX\Axle\AiGuard\Requests;
use AryanX\Axle\AiGuard\Usage;
use AryanX\Axle\AiGuard\Wall;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Allowance, the way Policy uses it, Requests and Wall.
 */
final class AllowanceTest extends GuardTestCase {

	/**
	 * A used-up budget for caller-a.
	 */
	private function use_up_budget(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'monthly_tokens' => 100 ) );
		$this->usage->add( array( 'plugin:caller-a' ), 1, 100, 0 );
	}

	/**
	 * A call by a signed-in person.
	 *
	 * @param int $id User.
	 * @return array
	 */
	private static function person_call( int $id = 5 ): array {
		return self::call( 'caller-a', array( 'user_id' => $id, 'roles' => array( 'author' ) ) );
	}

	public function test_a_grant_is_a_counted_row_for_one_day(): void {
		$allowance = new Allowance();
		$this->assertSame( 20, $allowance->grant( 5 ) );
		$this->assertSame( 20, $allowance->left( 5 ) );
		$this->assertSame( 0, $allowance->left( 6 ) );

		$row = $this->db->usage[ 'g:' . gmdate( 'Y-m-d' ) . '|allow:5' ];
		$this->assertSame( 20, $row['tokens'] );
		$this->assertSame( 0, $row['calls'] );
	}

	public function test_it_never_hands_out_more_calls_than_were_granted(): void {
		$allowance = new Allowance();
		$allowance->grant( 5, 3 );

		$taken = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$taken += $allowance->consume( 5 ) ? 1 : 0;
		}
		$this->assertSame( 3, $taken );
		$this->assertSame( 0, $allowance->left( 5 ) );
		$this->assertFalse( $allowance->consume( 6 ), 'Nobody else has a grant.' );
	}

	public function test_a_second_grant_starts_a_fresh_set_on_top_of_what_was_used(): void {
		$allowance = new Allowance();
		$allowance->grant( 5, 2 );
		$allowance->consume( 5 );
		$allowance->consume( 5 );
		$this->assertFalse( $allowance->consume( 5 ) );

		$allowance->grant( 5, 2 );
		$this->assertSame( 2, $allowance->left( 5 ) );
	}

	public function test_a_grant_ends_with_its_day(): void {
		$allowance = new Allowance();
		$yesterday = time() - DAY_IN_SECONDS;
		$allowance->grant( 5, 5, $yesterday );
		$this->assertSame( 5, $allowance->left( 5, $yesterday ) );
		$this->assertSame( 0, $allowance->left( 5 ) );
		$this->assertFalse( $allowance->consume( 5 ) );
	}

	public function test_revoking_ends_what_is_left_and_keeps_what_was_used(): void {
		$allowance = new Allowance();
		$allowance->grant( 5, 5 );
		$allowance->consume( 5 );
		$this->assertSame( 4, $allowance->revoke( 5 ) );
		$this->assertSame( 0, $allowance->left( 5 ) );
		$this->assertFalse( $allowance->consume( 5 ) );
		$this->assertSame( 1, $this->db->usage[ 'g:' . gmdate( 'Y-m-d' ) . '|allow:5' ]['calls'] );
	}

	public function test_calls_default_and_filter_are_clamped(): void {
		$this->assertSame( 20, Allowance::calls() );
		$this->assertSame( 7, Allowance::calls( 7 ) );
		Filters\expectApplied( 'axle_ai_guard_allowance_calls' )->once()->with( 20, 0 )->andReturn( 99999 );
		$this->assertSame( 1000, Allowance::calls() );
		Filters\expectApplied( 'axle_ai_guard_allowance_calls' )->once()->andReturn( -4 );
		$this->assertSame( 1, Allowance::calls() );
	}

	public function test_only_some_rules_can_be_passed(): void {
		foreach ( array( 'budget', 'rate', 'role', 'context', 'provider', 'model' ) as $rule ) {
			$this->assertTrue( Allowance::passes( $rule ), $rule );
		}
		foreach ( array( 'kill:global', 'kill:plugin', 'caller', 'files', 'data:user_email', 'scan', 'guard' ) as $rule ) {
			$this->assertFalse( Allowance::passes( $rule ), $rule );
		}
		Filters\expectApplied( 'axle_ai_guard_allowance_rules' )->once()->andReturn( array( 'budget', 'kill', 'data' ) );
		$this->assertTrue( Allowance::passes( 'budget' ) );
		Filters\expectApplied( 'axle_ai_guard_allowance_rules' )->once()->andReturn( array( 'budget', 'kill', 'data' ) );
		$this->assertFalse( Allowance::passes( 'kill:global' ), 'The filter cannot add rules that may never be passed.' );
	}

	public function test_a_grant_lets_a_blocked_person_through_and_counts_one_call(): void {
		$this->use_up_budget();
		$this->assertSame( 'budget', $this->policy->pre( self::person_call() )['rule'] );

		( new Allowance() )->grant( 5, 2 );
		Actions\expectDone( 'axle_ai_guard_allowance_used' )->once()->with( 5, 'budget', 1, \Mockery::type( 'array' ) );
		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertTrue( $this->policy->overridden() );
		$this->assertSame( 1, ( new Allowance() )->left( 5 ) );
	}

	public function test_one_call_takes_one_grant_across_the_pre_and_estimate_checks(): void {
		$this->use_up_budget();
		( new Allowance() )->grant( 5, 5 );

		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertNull( $this->policy->estimate( self::person_call(), 50, 0 ) );
		$this->assertSame( 4, ( new Allowance() )->left( 5 ), 'The same call is not charged twice.' );

		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertSame( 3, ( new Allowance() )->left( 5 ), 'The next call is.' );
	}

	public function test_an_unblocked_call_leaves_the_grant_alone(): void {
		( new Allowance() )->grant( 5, 5 );
		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertFalse( $this->policy->overridden() );
		$this->assertSame( 5, ( new Allowance() )->left( 5 ) );
	}

	public function test_a_grant_covers_a_rate_limit_but_only_for_that_person(): void {
		$this->settings->save( array( 'rate_user' => 1 ) );
		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertSame( 'rate', $this->policy->pre( self::person_call() )['rule'] );

		( new Allowance() )->grant( 5, 1 );
		$this->assertNull( $this->policy->pre( self::person_call() ) );
		$this->assertSame( 'rate', $this->policy->pre( self::person_call() )['rule'], 'The grant is spent.' );
		$this->assertNull( $this->policy->pre( self::person_call( 6 ) ) );
		$this->assertSame( 'rate', $this->policy->pre( self::person_call( 6 ) )['rule'], 'Another person has no grant.' );
	}

	public function test_a_grant_never_passes_a_kill_switch_or_a_data_rule(): void {
		( new Allowance() )->grant( 5, 5 );
		$this->kill->engage( 'plugin', 'caller-a', 'test', 1 );
		$this->assertSame( 'kill:plugin', $this->policy->pre( self::person_call() )['rule'] );

		$this->kill->release( 'plugin', 'caller-a', 1 );
		$this->settings->remember_caller( self::caller( 'caller-a' ), 'pending' );
		$this->assertSame( 'caller', $this->policy->pre( self::person_call() )['rule'] );
		$this->assertSame( 5, ( new Allowance() )->left( 5 ), 'Nothing was taken.' );
	}

	public function test_a_visitor_has_no_grant_to_use(): void {
		$this->use_up_budget();
		( new Allowance() )->grant( 0, 5 );
		$this->assertSame( 'budget', $this->policy->pre( self::call() )['rule'] );
	}

	public function test_erasing_a_person_removes_their_counters_grants_and_requests_only(): void {
		$this->usage->add( array( 'global', 'user:5', 'user:6' ), 1, 10, 100 );
		( new Allowance() )->grant( 5, 3 );
		( new Allowance() )->grant( 6, 3 );

		$this->assertGreaterThan( 0, $this->usage->erase_user( 5 ) );
		( new Allowance() )->erase( 5 );

		$keys = implode( ' ', array_keys( $this->db->usage ) );
		$this->assertStringNotContainsString( '|user:5', $keys );
		$this->assertStringNotContainsString( '|allow:5', $keys );
		$this->assertStringContainsString( '|user:6', $keys );
		$this->assertStringContainsString( '|allow:6', $keys );
		$this->assertStringContainsString( '|global', $keys );
	}

	public function test_the_eraser_clears_the_persons_usage_counters_on_its_last_batch(): void {
		$this->usage->add( array( 'user:7' ), 1, 10, 100 );
		Functions\when( 'get_user_by' )->justReturn( new \WP_User( 7 ) );
		$this->db->next_results = array();

		$result = ( new Privacy( $this->calls ) )->erase( 'jane@example.com' );
		$this->assertTrue( $result['done'] );
		$this->assertStringNotContainsString( '|user:7', implode( ' ', array_keys( $this->db->usage ) ) );
		$this->assertTrue( $result['items_removed'] );
	}

	public function test_requests_come_from_the_recorded_block_one_open_per_person(): void {
		$requests = new Requests( new Allowance(), $this->alerts );
		$this->assertNull( $requests->ask( 5 ), 'Nothing blocked, nothing to ask.' );

		Functions\when( 'get_userdata' )->alias(
			static function ( $id ) {
				$user               = new \WP_User( (int) $id );
				$user->display_name = 'Ada Author';
				return $user;
			}
		);
		$requests->remember_block( 5, 'budget', 'caller-a' );
		Actions\expectDone( 'axle_ai_guard_access_requested' )->once();
		$first = $requests->ask( 5 );
		$this->assertTrue( $first['created'] );
		$this->assertSame( 'budget', $first['request']['rule'] );
		$this->assertCount( 1, $this->mails, 'The admin is told once.' );

		$again = $requests->ask( 5 );
		$this->assertFalse( $again['created'] );
		$this->assertCount( 1, $requests->open() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_allow_undo_and_dismiss(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$requests = new Requests( new Allowance(), $this->alerts );
		$requests->remember_block( 5, 'budget', 'caller-a' );
		$id = $requests->ask( 5 )['request']['id'];

		Actions\expectDone( 'axle_ai_guard_allowance_granted' )->once()->with( 5, 20, 1, \Mockery::type( 'array' ) );
		$this->assertSame( 20, $requests->allow( $id, 1 )['calls'] );
		$this->assertSame( 'allowed', $requests->get( $id )['status'] );
		$this->assertNull( $requests->allow( $id, 1 ), 'A request is answered once.' );
		$this->assertSame( 20, ( new Allowance() )->left( 5 ) );

		Actions\expectDone( 'axle_ai_guard_allowance_revoked' )->once()->with( 5, 20, 1 );
		$this->assertSame( 'open', $requests->undo( $id, 1 )['status'] );
		$this->assertSame( 0, ( new Allowance() )->left( 5 ) );

		$this->assertSame( 'dismissed', $requests->dismiss( $id, 1 )['status'] );
		$this->assertNull( $requests->undo( $id, 1 ) );
	}

	public function test_requests_are_capped_pruned_and_erased(): void {
		Functions\when( 'get_userdata' )->justReturn( false );
		$requests = new Requests( new Allowance(), $this->alerts );
		for ( $user = 1; $user <= 55; $user++ ) {
			$requests->remember_block( $user, 'rate', 'caller-a' );
			$requests->ask( $user );
		}
		$this->assertCount( 50, $requests->all() );
		$this->assertCount( 1, $requests->for_user( 55 ) );

		$list            = $this->options[ Requests::OPTION ];
		$list[0]['at']   = time() - 40 * DAY_IN_SECONDS;
		$this->options[ Requests::OPTION ] = $list;
		$this->assertSame( 1, $requests->prune() );

		$this->assertSame( 1, $requests->erase_user( 55 ) );
		$this->assertCount( 0, $requests->for_user( 55 ) );
		$this->assertNull( $requests->last_block( 55 ) );
	}

	public function test_the_wall_says_why_without_numbers_and_offers_a_request_to_others(): void {
		Functions\stubs(
			array(
				'wp_parse_args'      => static function ( $args, $defaults ) {
					return array_merge( $defaults, (array) $args );
				},
				'wp_strip_all_tags'  => static function ( $text ) {
					return strip_tags( (string) $text );
				},
			)
		);

		Functions\when( 'current_user_can' )->justReturn( false );
		$wall = ( new Wall() )->build( 'budget', self::caller(), array() );
		$this->assertSame( 'Ask for more', $wall['action_label'] );
		$this->assertSame( 'axle-ai-guard/v1/access-requests', $wall['request_route'] );
		$this->assertSame( '', $wall['action_url'] );
		$this->assertDoesNotMatchRegularExpression( '/\d/', $wall['message'], 'No figures reach someone who cannot manage AI Guard.' );
		$this->assertStringNotContainsString( 'caller-a', wp_json_encode( $wall ) );

		$kill = ( new Wall() )->build( 'kill:global', self::caller(), array() );
		$this->assertSame( '', $kill['action_label'], 'Nothing to ask for: a switch cannot be passed.' );
		$data = ( new Wall() )->build( 'data:user_email', self::caller(), array() );
		$this->assertSame( '', $data['request_route'] );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'add_query_arg' )->justReturn( 'https://example.com/wp-admin/tools.php?page=axle-ai-guard&tab=budgets' );
		$manager = ( new Wall() )->build( 'budget', self::caller(), array() );
		$this->assertSame( 'Open AI Guard', $manager['action_label'] );
		$this->assertSame( '', $manager['request_route'] );
	}

	public function test_the_wall_is_added_to_a_rest_error_after_a_block_of_this_person(): void {
		Functions\stubs(
			array(
				'wp_parse_args'     => static function ( $args, $defaults ) {
					return array_merge( $defaults, (array) $args );
				},
				'wp_strip_all_tags' => static function ( $text ) {
					return strip_tags( (string) $text );
				},
				'get_current_user_id' => 5,
				'current_user_can'    => false,
			)
		);
		$wall = new Wall();

		$error = new class( 'some_plugin_failed', 'Nope', array( 'status' => 500 ) ) extends \WP_Error {
			/** @var mixed */
			private $extra;

			public function __construct( $code, $message, $data ) {
				parent::__construct( $code, $message, $data );
				$this->extra = $data;
			}

			public function get_error_data( $code = '' ) {
				return $this->extra;
			}

			public function add_data( $data, $code = '' ) {
				$this->extra = $data;
			}
		};
		$this->assertSame( $error, $wall->on_error( $error ), 'Nothing blocked yet: untouched.' );
		$this->assertArrayNotHasKey( 'axle_wall', (array) $error->get_error_data( 'some_plugin_failed' ) );

		$wall->on_blocked( 'budget', self::caller(), array( 'user_id' => 99 ) );
		$wall->on_error( $error );
		$this->assertArrayNotHasKey( 'axle_wall', (array) $error->get_error_data( 'some_plugin_failed' ), 'A block for someone else is not shown to this person.' );

		$wall->on_blocked( 'budget', self::caller(), array( 'user_id' => 5 ) );
		$wall->on_error( $error );
		$data = $error->get_error_data( 'some_plugin_failed' );
		$this->assertSame( 500, $data['status'] );
		$this->assertSame( 'Ask for more', $data['axle_wall']['action_label'] );
	}
}
