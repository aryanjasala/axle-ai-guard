<?php
/**
 * Tests for budgets, rate limits and rules.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Policy;
use AryanX\Axle\AiGuard\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Policy.
 */
final class PolicyTest extends GuardTestCase {

	public function test_a_clean_call_passes_and_counts_towards_the_rate(): void {
		$this->assertNull( $this->policy->pre( self::call() ) );
		$this->assertSame( 1, $this->db->usage[ 'n:' . gmdate( 'YmdHi' ) . '|plugin:caller-a' ]['calls'] );
	}

	public function test_kill_switch_and_caller_approval(): void {
		$this->kill->engage( 'plugin', 'caller-a', 'test', 1 );
		$this->assertSame( 'kill:plugin', $this->policy->pre( self::call() )['rule'] );
		$this->assertNull( $this->policy->pre( self::call( 'caller-b' ) ) );

		$this->settings->remember_caller( self::caller( 'caller-b' ), 'pending' );
		$this->assertSame( 'caller', $this->policy->pre( self::call( 'caller-b' ) )['rule'] );
	}

	public function test_contexts_and_roles(): void {
		$this->settings->save_policy( 'global', array( 'contexts' => array( 'admin', 'cron' ) ) );
		$this->assertSame( 'context', $this->policy->pre( self::call() )['rule'] );

		$this->settings->save_policy( 'global', array( 'contexts' => array() ) );
		$this->settings->save_policy( 'plugin:caller-a', array( 'roles' => 'administrator' ) );
		$this->assertSame( 'role', $this->policy->pre( self::call() )['rule'] );
		$this->assertNull( $this->policy->pre( self::call( 'caller-a', array( 'roles' => array( 'administrator' ) ) ) ) );
	}

	public function test_a_used_up_budget_blocks_and_the_budget_filter(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'monthly_tokens' => 100 ) );
		$this->usage->add( array( 'plugin:caller-a' ), 1, 100, 0 );
		$this->assertSame( 'budget', $this->policy->pre( self::call() )['rule'] );

		Filters\expectApplied( 'axle_ai_guard_budget' )->atLeast()->once()->with( Mockery::type( 'array' ), Mockery::type( 'string' ) )->andReturn( array( 'monthly_tokens' => '1000' ) );
		$this->assertNull( $this->policy->pre( self::call() ) );
	}

	public function test_estimates_count_against_the_budget(): void {
		$this->settings->save_policy( 'global', array( 'daily_cost' => 1000 ) );
		$this->usage->add( array( 'global' ), 1, 0, 900 );
		$this->assertNull( $this->policy->estimate( self::call(), 10, 100 ) );
		$this->assertSame( 'budget', $this->policy->estimate( self::call(), 10, 101 )['rule'] );
	}

	public function test_rate_limits_and_their_filter_and_action(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'per_minute' => 2 ) );
		Actions\expectDone( 'axle_ai_guard_rate_limited' )->once()->with( 'plugin:caller-a', 2, Mockery::type( 'array' ) );

		$this->assertNull( $this->policy->pre( self::call() ) );
		$this->assertNull( $this->policy->pre( self::call() ) );
		$this->assertSame( 'rate', $this->policy->pre( self::call() )['rule'] );

		$this->assertSame( 5, $this->policy->rate_limit( 'ip:203.0.113.0' ) );
		$this->assertSame( 20, $this->policy->rate_limit( 'user:4' ) );
		Filters\expectApplied( 'axle_ai_guard_rate_limit' )->once()->with( 120, 'global' )->andReturn( -3 );
		$this->assertSame( 0, $this->policy->rate_limit( 'global' ) );
	}

	public function test_visitors_share_the_ip_limit(): void {
		$this->settings->save( array( 'rate_ip' => 1 ) );
		$call = self::call( 'caller-a', array( 'ip' => '203.0.113.0' ) );
		$this->assertNull( $this->policy->pre( $call ) );
		$this->assertSame( 'rate', $this->policy->pre( self::call( 'caller-b', array( 'ip' => '203.0.113.0' ) ) )['rule'] );
	}

	public function test_provider_and_model_lists(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'providers' => 'anthropic' ) );
		$this->assertSame( 'provider', $this->policy->model( self::call() )['rule'] );

		$this->settings->save_policy( 'plugin:caller-a', array( 'providers' => '', 'models' => 'openai/gpt-5-nano*' ) );
		$this->assertSame( 'model', $this->policy->model( self::call() )['rule'] );
		$this->assertTrue( Policy::model_allowed( 'openai', 'gpt-5-nano-2025', array( 'gpt-5-nano*' ) ) );

		$this->kill->engage( 'provider', 'openai', '', 1 );
		$this->assertSame( 'kill:provider', $this->policy->model( self::call() )['rule'] );
	}

	public function test_the_policy_filter_has_the_final_say(): void {
		Filters\expectApplied( 'axle_ai_guard_policy' )->once()->with( Mockery::on( static function ( $decision ) {
			return true === $decision['allow'];
		} ), Mockery::type( 'array' ), 'model' )->andReturn(
			array(
				'allow' => false,
				'rule'  => 'Night Time!',
			)
		);
		$block = $this->policy->model( self::call() );
		$this->assertSame( 'nighttime', $block['rule'] );
		$this->assertStringContainsString( 'blocked by Axle AI Guard', $block['message'] );

		$this->kill->engage( 'global', '', '', 1 );
		Filters\expectApplied( 'axle_ai_guard_policy' )->once()->andReturn( array( 'allow' => true ) );
		$this->assertNull( $this->policy->model( self::call() ) );
	}

	public function test_the_block_message_filter(): void {
		$this->assertStringContainsString( 'Rule: password_hash.', Policy::message( 'data:password_hash', self::call() ) );
		Filters\expectApplied( 'axle_ai_guard_block_message' )->once()->with( Mockery::type( 'string' ), 'budget', Mockery::type( 'array' ) )->andReturn( 'Out of credits.' );
		$this->assertSame( 'Out of credits.', Policy::message( 'budget', self::call() ) );
	}

	public function test_crossing_soft_and_hard_limits_fires_both_and_alerts(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'monthly_cost' => 1000 ) );
		Actions\expectDone( 'axle_ai_guard_budget_soft_reached' )->once()->with( 'plugin:caller-a', 'monthly_cost', 1000, 1000 );
		Actions\expectDone( 'axle_ai_guard_budget_hard_reached' )->once()->with( 'plugin:caller-a', 'monthly_cost', 1000, 1000 );

		$this->policy->record( self::call(), 100, 1000 );

		$this->assertCount( 2, $this->mails );
		$this->assertSame( 1000, $this->db->usage[ Usage::periods()['month'] . '|plugin:caller-a' ]['cost_micros'] );
		$this->assertSame( 1, $this->db->usage[ Usage::periods()['day'] . '|user:0' ]['calls'] ?? 1, 'Anonymous calls have no user subject.' );
	}

	public function test_the_soft_ratio_filter(): void {
		$this->settings->save_policy( 'global', array( 'monthly_tokens' => 100 ) );
		Filters\expectApplied( 'axle_ai_guard_soft_limit_ratio' )->atLeast()->once()->with( 0.8, Mockery::type( 'string' ) )->andReturn( 0.5 );
		Actions\expectDone( 'axle_ai_guard_budget_soft_reached' )->once()->with( 'global', 'monthly_tokens', 60, 100 );

		$this->policy->record( self::call(), 60, 0 );
	}

	public function test_the_monthly_money_budget_trips_the_global_switch(): void {
		$this->settings->save( array( 'autotrip_budget' => true ) );
		$this->settings->save_policy( 'global', array( 'monthly_cost' => 500 ) );
		$this->policy->record( self::call(), 10, 600 );

		$this->assertTrue( $this->kill->global_on() );
	}

	public function test_blocked_calls_are_counted(): void {
		$this->policy->record_block( self::call() );
		$this->assertSame( 1, $this->db->usage[ Usage::periods()['day'] . '|global' ]['blocked'] );
		$this->assertSame( array( 'global', 'plugin:caller-a', 'provider:openai', 'user:4' ), Policy::subjects( self::call( 'caller-a', array( 'user_id' => 4 ) ) ) );
	}
}
