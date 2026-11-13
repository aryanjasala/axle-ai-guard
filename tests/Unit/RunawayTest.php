<?php
/**
 * Tests for the runaway guard, the forecast, price undo and import, and the rule test.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Schema;
use AryanX\Axle\AiGuard\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Runaway, Forecast, Prices undo and import, and Reports::test_rules.
 */
final class RunawayTest extends GuardTestCase {

	/** @var int Fixed noon on a day in the middle of a month. */
	private const NOW = 1794571200;

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\Functions\stubs(
			array(
				'delete_transient' => true,
				'wp_date'          => static function ( $format, $time ) {
					return gmdate( $format, $time );
				},
			)
		);
	}

	/**
	 * Puts calls into hour buckets of a caller.
	 *
	 * @param string $slug  Caller.
	 * @param int    $hours Hours back from now.
	 * @param int    $calls Calls in that hour.
	 * @param int    $cost  Cost in that hour.
	 */
	private function bucket( string $slug, int $hours, int $calls, int $cost = 0 ): void {
		$this->db->usage[ 'h:' . gmdate( 'YmdH', self::NOW - $hours * HOUR_IN_SECONDS ) . '|plugin:' . $slug ] = array(
			'calls'       => $calls,
			'tokens'      => 0,
			'cost_micros' => $cost,
			'blocked'     => 0,
		);
	}

	public function test_a_quiet_caller_never_trips_the_floor(): void {
		$this->settings->save( array( 'runaway_mode' => 'pause' ) );
		$this->bucket( 'caller-a', 0, 60 );
		$this->assertNull( $this->runaway->evaluate( 'caller-a', self::NOW ) );
		$this->assertFalse( $this->kill->is_on( 'plugin', 'caller-a' ) );
	}

	public function test_pause_mode_switches_the_caller_off_and_alerts(): void {
		$this->settings->save( array( 'runaway_mode' => 'pause' ) );
		foreach ( array( 2, 3, 4 ) as $back ) {
			$this->bucket( 'caller-a', $back, 10 );
		}
		$this->bucket( 'caller-a', 0, 150 );
		Actions\expectDone( 'axle_ai_guard_runaway_detected' )->once()->with( Mockery::type( 'array' ) );

		$event = $this->runaway->evaluate( 'caller-a', self::NOW );

		$this->assertSame( 'caller-a', $event['caller'] );
		$this->assertSame( 10, $event['base_calls'] );
		$this->assertTrue( $event['paused'] );
		$this->assertTrue( $this->kill->is_on( 'plugin', 'caller-a' ) );
		$this->assertNotEmpty( $this->mails );
		$this->assertCount( 1, $this->runaway->open_events() );
		$this->assertNull( $this->runaway->evaluate( 'caller-a', self::NOW ), 'One open event per caller.' );
	}

	public function test_alert_mode_pauses_nothing(): void {
		$this->bucket( 'caller-a', 0, 150 );
		$event = $this->runaway->evaluate( 'caller-a', self::NOW );
		$this->assertFalse( $event['paused'] );
		$this->assertFalse( $this->kill->is_on( 'plugin', 'caller-a' ) );
	}

	public function test_off_and_exempt_callers_are_skipped(): void {
		$this->bucket( 'caller-a', 0, 500 );
		$this->bucket( 'core', 0, 500 );
		$this->settings->save( array( 'runaway_mode' => 'off' ) );
		$this->assertNull( $this->runaway->evaluate( 'caller-a', self::NOW ) );

		$this->settings->save( array( 'runaway_mode' => 'pause' ) );
		$this->assertNull( $this->runaway->evaluate( 'core', self::NOW ) );
		Filters\expectApplied( 'axle_ai_guard_runaway_exempt' )->atLeast()->once()->andReturn( array( 'caller-a' ) );
		$this->assertNull( $this->runaway->evaluate( 'caller-a', self::NOW ) );
	}

	public function test_resume_and_raise_the_limit(): void {
		$this->settings->save( array( 'runaway_mode' => 'pause' ) );
		$this->bucket( 'caller-a', 0, 150 );
		$event = $this->runaway->evaluate( 'caller-a', self::NOW );
		Actions\expectDone( 'axle_ai_guard_runaway_resumed' )->once()->with( Mockery::type( 'array' ), true, 3 );

		$this->assertTrue( $this->runaway->resume( $event['id'], 3, true ) );
		$this->assertFalse( $this->kill->is_on( 'plugin', 'caller-a' ) );
		$this->assertSame( 300, $this->runaway->min_calls( 'caller-a' ) );
		$this->assertSame( array(), $this->runaway->open_events() );
		$this->assertFalse( $this->runaway->resume( $event['id'], 3 ), 'Already dealt with.' );
	}

	public function test_a_finished_call_counts_in_the_hour_and_checks_every_tenth(): void {
		$this->settings->save( array( 'runaway_mode' => 'pause' ) );
		$this->settings->save( array( 'runaway_min_calls' => 10 ) );
		$call = self::call( 'caller-a' );
		for ( $i = 0; $i < 9; $i++ ) {
			$this->runaway->on_finished( 1, array( 'cost_micros' => 10 ), $call );
		}
		$this->assertFalse( $this->kill->is_on( 'plugin', 'caller-a' ) );
		$this->runaway->on_finished( 1, array( 'cost_micros' => 10 ), $call );
		$this->assertTrue( $this->kill->is_on( 'plugin', 'caller-a' ) );
		$this->assertSame( 10, $this->usage->get( 'h:' . gmdate( 'YmdH' ), 'plugin:caller-a' )['calls'] );
	}

	public function test_new_installs_pause_and_old_ones_alert(): void {
		$this->assertSame( 'alert', $this->settings->get( 'runaway_mode' ) );
		$this->assertSame( 'alert', \AryanX\Axle\AiGuard\Settings::sanitize( array( 'runaway_mode' => 'nonsense' ) )['runaway_mode'] );
		$this->assertSame( 2, \AryanX\Axle\AiGuard\Settings::sanitize( array( 'runaway_multiple' => 0 ) )['runaway_multiple'] );
		$this->assertSame( '2', Schema::DB_VERSION );
	}

	public function test_the_forecast_says_when_a_budget_runs_out(): void {
		$this->settings->save_policy( 'global', array( 'monthly_cost' => 100000000 ) );
		$periods = Usage::periods( self::NOW );
		$this->db->usage[ $periods['month'] . '|global' ] = array( 'calls' => 1, 'tokens' => 1, 'cost_micros' => 40000000, 'blocked' => 0 );
		for ( $d = 0; $d < 7; $d++ ) {
			$this->db->usage[ 'd:' . gmdate( 'Y-m-d', self::NOW - $d * DAY_IN_SECONDS ) . '|global' ] = array( 'calls' => 1, 'tokens' => 1, 'cost_micros' => 7000000, 'blocked' => 0 );
		}

		$forecast = $this->forecast->for_subject( 'global', self::NOW );

		$this->assertSame( 'cost', $forecast['unit'] );
		$this->assertEqualsWithDelta( 7000000, $forecast['per_day'], 1 );
		$this->assertTrue( $forecast['crosses'] );
		$this->assertGreaterThan( self::NOW, $forecast['runs_out'] );
		$this->assertNull( $this->forecast->for_subject( 'plugin:none', self::NOW ) );

		Actions\expectDone( 'axle_ai_guard_forecast_crossed' )->once();
		$this->assertSame( 1, $this->forecast->check( self::NOW ) );
		$this->assertSame( 0, $this->forecast->check( self::NOW ), 'Once per month.' );
	}

	public function test_price_undo_and_import(): void {
		$this->prices->set( 'acme/model-x', 1.0, 2.0 );
		$this->assertTrue( $this->prices->can_undo() );
		$this->assertTrue( $this->prices->undo() );
		$this->assertNull( $this->prices->price( 'acme', 'model-x' ) );

		$result = $this->prices->import_json( '{"models":{"acme/model-y":{"input":3,"output":4},"bad key":{"input":1,"output":1}}}' );
		$this->assertSame( array( 'imported' => 1, 'skipped' => 1 ), $result );
		$this->assertSame( 3.0, $this->prices->price( 'acme', 'model-y' )['input'] );
		$this->assertIsArray( json_decode( $this->prices->export_json(), true )['models'] );
		$this->assertTrue( $this->prices->undo() );
		$this->assertNull( $this->prices->price( 'acme', 'model-y' ) );

		\Brain\Monkey\Functions\when( 'is_wp_error' )->alias( static function ( $thing ) {
			return $thing instanceof \WP_Error;
		} );
		$this->assertInstanceOf( \WP_Error::class, $this->prices->import_json( 'not json' ) );
	}

	public function test_try_rules_names_the_outcome_the_rule_and_the_matches(): void {
		$this->detectors->save( array( 'email' => 'redact' ) );
		$text   = 'Mail bob@example.org now';
		$result = $this->reports->test_rules( $text, 'caller-a', 'front' );

		$this->assertSame( 'redacted', $result['outcome'] );
		$this->assertSame( 'data:email', $result['rule'] );
		$this->assertSame( 'front', $result['context'] );
		$this->assertSame( 5, $result['spans'][0]['start'] );
		$this->assertStringContainsString( '[redacted:email]', $result['redacted'] );

		$this->detectors->save( array( 'email' => 'block' ) );
		$blocked = $this->reports->test_rules( $text );
		$this->assertSame( 'blocked', $blocked['outcome'] );
		$this->assertSame( 'data:email', $blocked['rule'] );

		$this->detectors->save( array( 'email' => 'log' ) );
		$this->assertSame( 'sent', $this->reports->test_rules( $text )['outcome'] );
	}
}
