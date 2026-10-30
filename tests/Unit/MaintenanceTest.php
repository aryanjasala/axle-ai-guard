<?php
/**
 * Tests for clean-up, recalculation, privacy and reports.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Cleanup;
use AryanX\Axle\AiGuard\Privacy;
use AryanX\Axle\AiGuard\Usage;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Cleanup, Privacy and Reports.
 */
final class MaintenanceTest extends GuardTestCase {

	/** @var Cleanup */
	private $cleanup;

	protected function setUp(): void {
		parent::setUp();
		$this->cleanup = new Cleanup( $this->settings, $this->calls, $this->usage, $this->prices );
	}

	public function test_retention_and_its_filter(): void {
		$this->assertSame( 90, $this->cleanup->retention( 'calls' ) );
		$this->assertSame( 400, $this->cleanup->retention( 'usage' ) );
		Filters\expectApplied( 'axle_ai_guard_retention_days' )->once()->with( 7, 'prompts' )->andReturn( 0 );
		$this->assertSame( 1, $this->cleanup->retention( 'prompts' ) );
	}

	public function test_prune_runs_in_batches_and_fires_logs_pruned(): void {
		Actions\expectDone( 'axle_ai_guard_logs_pruned' )->once()->with( Mockery::type( 'array' ) );
		$counts = $this->cleanup->prune( 30 );

		$this->assertSame( array( 'calls', 'usage', 'prompts' ), array_keys( $counts ) );
		$deletes = array_filter(
			$this->db->queries,
			static function ( $query ) {
				return is_array( $query ) && 0 === strpos( $query['sql'], 'DELETE' );
			}
		);
		$this->assertCount( 5, $deletes );
		foreach ( $deletes as $query ) {
			$this->assertContains( end( $query['args'] ), array( Cleanup::BATCH, Cleanup::BATCH * Cleanup::MAX_BATCHES ) );
		}
	}

	public function test_recalculate_moves_the_difference_into_usage(): void {
		$this->db->next_results = array(
			array(
				'id'                => 1,
				'started_at'        => gmdate( 'Y-m-d H:i:s' ),
				'caller_slug'       => 'caller-a',
				'provider'          => 'openai',
				'model'             => 'gpt-5-mini',
				'user_id'           => 0,
				'prompt_tokens'     => 1000,
				'completion_tokens' => 500,
				'thought_tokens'    => 0,
				'cost_micros'       => 250,
			),
		);
		$this->db->calls[1] = \Aag_Fake_Wpdb::blank_call();
		$this->usage->add( array( 'global' ), 1, 1500, 250 );

		$summary = $this->cleanup->recalculate( 30 );
		$this->assertSame( array( 'rows' => 1, 'changed' => 1, 'delta' => 1000 ), $summary );
		$this->assertSame( 1250, $this->db->calls[1]['cost_micros'] );
		$this->assertSame( 1250, $this->db->usage[ Usage::periods()['month'] . '|global' ]['cost_micros'] );
	}

	public function test_privacy_exporter_and_eraser(): void {
		$privacy = new Privacy( $this->calls );
		$this->assertArrayHasKey( 'axle-ai-guard', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'axle-ai-guard', $privacy->add_eraser( array() ) );

		Functions\when( 'get_user_by' )->justReturn( new \WP_User( 5 ) );
		$this->db->next_results = array( array_merge( \Aag_Fake_Wpdb::blank_call(), array( 'id' => 9, 'status' => 'ok', 'caller_slug' => 'caller-a', 'ip' => '203.0.113.0' ) ) );
		$export = $privacy->export( 'jane@example.com' );
		$this->assertSame( 'axle-ai-guard-call-9', $export['data'][0]['item_id'] );
		$this->assertTrue( $export['done'] );

		$this->db->next_results = array( 9 );
		$this->db->calls[9]     = array_merge( \Aag_Fake_Wpdb::blank_call(), array( 'user_id' => 5, 'ip' => '203.0.113.0', 'preview' => 'Hi' ) );
		$erase                  = $privacy->erase( 'jane@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertSame( 0, $this->db->calls[9]['user_id'] );
		$this->assertSame( '', $this->db->calls[9]['ip'] );
		$this->assertNull( $this->db->calls[9]['preview'] );
	}

	public function test_reports_test_rules_and_limits(): void {
		$result = $this->reports->test_rules( 'Mail jane@example.com, hash $P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/' );
		$this->assertTrue( $result['blocked'] );
		$this->assertStringContainsString( '[redacted:user_email]', $result['redacted'] );

		$this->kill->engage( 'plugin', 'caller-a', 'x', 1 );
		$this->settings->save_policy( 'plugin:caller-a', array( 'daily_tokens' => 10 ) );
		$limits = $this->reports->limits();
		$this->assertSame( 'USD', $limits['currency'] );
		$this->assertSame( array( 'global', 'plugin:caller-a' ), array_column( $limits['budgets'], 'subject' ) );
		$this->assertSame( 'caller-a', $limits['kill'][0]['subject'] );
	}
}
