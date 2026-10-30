<?php
/**
 * Tests for the capability, requirements, abilities and REST routes.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Abilities;
use AryanX\Axle\AiGuard\Plugin;
use AryanX\Axle\AiGuard\Requirements;
use AryanX\Axle\AiGuard\Rest\Controller;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Plugin::capability(), Requirements, Abilities and the REST controller.
 */
final class RegistrationTest extends GuardTestCase {

	public function test_the_capability_filter(): void {
		$this->assertSame( 'axle_ai_guard_manage', Plugin::capability() );
		Filters\expectApplied( 'axle_ai_guard_capability' )->once()->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Plugin::capability() );
		Filters\expectApplied( 'axle_ai_guard_capability' )->once()->andReturn( '' );
		$this->assertSame( 'axle_ai_guard_manage', Plugin::capability() );
	}

	public function test_requirements(): void {
		Functions\when( 'wp_ai_client_prompt' )->justReturn( null );
		$this->assertTrue( Requirements::met( '7.0.8' ) );
		$this->assertFalse( Requirements::met( '6.9.4' ) );
	}

	public function test_abilities_are_registered_with_the_right_annotations(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);
		$abilities = new Abilities( $this->reports, $this->kill, $this->calls );
		$abilities->register();
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', array( $abilities, 'register_abilities' ) ) );
		$abilities->register_abilities();

		$this->assertSame( array( 'axle-ai-guard/get-usage', 'axle-ai-guard/list-calls', 'axle-ai-guard/get-limits', 'axle-ai-guard/engage-kill-switch', 'axle-ai-guard/test-data-rules' ), array_keys( $registered ) );
		foreach ( $registered as $name => $args ) {
			$readonly = 'axle-ai-guard/engage-kill-switch' !== $name;
			$this->assertSame( $readonly, $args['meta']['annotations']['readonly'], $name );
			$this->assertFalse( $args['meta']['annotations']['destructive'], $name );
			$this->assertTrue( $args['meta']['mcp']['public'], $name );
		}
	}

	public function test_the_kill_switch_ability_only_turns_switches_on(): void {
		$abilities = new Abilities( $this->reports, $this->kill, $this->calls );
		$result    = $abilities->engage_kill_switch(
			array(
				'scope'   => 'plugin',
				'subject' => 'chatbot',
				'reason'  => 'Loop',
			)
		);
		$this->assertSame( array( 'scope' => 'plugin', 'subject' => 'chatbot', 'changed' => true, 'on' => true ), $result );
		$this->assertInstanceOf( \WP_Error::class, $abilities->engage_kill_switch( array( 'scope' => 'provider' ) ) );
		$this->assertTrue( $abilities->engage_kill_switch( array( 'reason' => 'All off' ) )['on'] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertFalse( $abilities->can() );
	}

	public function test_ability_reads(): void {
		$abilities = new Abilities( $this->reports, $this->kill, $this->calls );
		$this->usage->add( array( 'global', 'plugin:caller-a' ), 2, 300, 1800 );
		$this->db->next_results = array(
			array(
				'subject'     => 'plugin:caller-a',
				'calls'       => 2,
				'tokens'      => 300,
				'cost_micros' => 1800,
				'blocked'     => 0,
			),
		);
		$usage = $abilities->get_usage( array( 'period' => 'day' ) );
		$this->assertSame( 2, $usage['total']['calls'] );
		$this->assertSame( 'caller-a', $usage['groups'][0]['name'] );

		$this->assertFalse( $abilities->test_data_rules( array( 'text' => 'nothing here' ) )['blocked'] );
	}

	public function test_rest_routes_and_kill(): void {
		$controller = new Controller( $this->reports, $this->kill, $this->calls );
		$routes     = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route ) use ( &$routes ) {
				$routes[] = $ns . $route;
			}
		);
		$controller->routes();
		$this->assertSame( array( 'axle-ai-guard/v1/calls', 'axle-ai-guard/v1/calls/(?P<id>\d+)', 'axle-ai-guard/v1/kill', 'axle-ai-guard/v1/rules/test' ), $routes );

		Functions\when( 'rest_ensure_response' )->returnArg();
		$request = new class() {
			/** @var array */
			public $params = array(
				'scope'   => 'context',
				'subject' => 'front',
				'on'      => true,
				'reason'  => '',
			);
			public function get_param( $key ) {
				return $this->params[ $key ] ?? null;
			}
		};
		$this->assertSame( array( 'scope' => 'context', 'subject' => 'front', 'on' => true, 'changed' => true ), $controller->kill( $request ) );
		$request->params['on'] = false;
		$this->assertFalse( $controller->kill( $request )['on'] );
	}
}
