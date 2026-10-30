<?php
/**
 * Tests for the data rules.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Detectors;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Detectors.
 */
final class DetectorsTest extends GuardTestCase {

	public function test_built_in_detectors(): void {
		$this->assertSame( array( 'jane@example.com', 'x@test.org' ), Detectors::match_emails( 'Mail jane@example.com and x@test.org.' ) );
		$this->assertSame( array( 'jane@example.com' ), Detectors::match_user_emails( 'jane@example.com, stranger@example.com' ) );
		$this->assertSame( array( '+91 98765 43210', '(555) 123-4567' ), Detectors::match_phones( 'Call +91 98765 43210 or (555) 123-4567, order 123456789.' ) );
		$this->assertSame( array( '192.168.1.20', '2001:db8::1' ), Detectors::match_ips( 'From 192.168.1.20 and 2001:db8::1, not 999.1.1.1 or version 1.2.3.' ) );
		$this->assertSame( array( '4111 1111 1111 1111' ), Detectors::match_cards( 'Card 4111 1111 1111 1111, not 4111 1111 1111 1112.' ) );
		$this->assertCount( 3, Detectors::match_hashes( '$P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/ $2y$10$abcdefghijklmnopqrstuuJ8Wq8nYp9V8nU8lVfL9v8Xc3eJ1lQ7W $argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA' ) );
		$this->assertTrue( Detectors::luhn( '4111111111111111' ) );
		$this->assertFalse( Detectors::luhn( '0000000000000' ) );
	}

	public function test_salts_and_secret_options(): void {
		define( 'NONCE_SALT', 'a-very-secret-salt-value' );
		$this->assertSame( array( 'a-very-secret-salt-value' ), Detectors::match_salts( 'leak a-very-secret-salt-value here' ) );

		Functions\when( 'wp_get_connectors' )->justReturn( array( 'openai' => array() ) );
		$this->options['connectors_ai_openai_api_key'] = 'sk-live-123456789';
		$this->options['my_secret']                    = 'hunter2hunter2';
		Filters\expectApplied( 'axle_ai_guard_secret_option_names' )->atLeast()->once()->with( array( 'connectors_ai_openai_api_key' ) )->andReturn( array( 'connectors_ai_openai_api_key', 'my_secret' ) );

		$this->assertSame( array( 'sk-live-123456789', 'hunter2hunter2' ), Detectors::match_secrets( 'key sk-live-123456789 and hunter2hunter2' ) );
	}

	public function test_default_modes_saved_modes_and_the_mode_filter(): void {
		$this->assertSame( 'redact', $this->detectors->mode( 'user_email' ) );
		$this->assertSame( 'block', $this->detectors->mode( 'password_hash' ) );
		$this->assertSame( 'log', $this->detectors->mode( 'phone' ) );

		$this->detectors->save( array( 'phone' => 'redact', 'email' => 'bogus' ) );
		$this->assertSame( 'redact', $this->detectors->mode( 'phone' ) );
		$this->assertSame( 'log', $this->detectors->mode( 'email' ) );

		Filters\expectApplied( 'axle_ai_guard_rule_mode' )->once()->with( 'redact', 'phone', 'chatbot' )->andReturn( 'block' );
		$this->assertSame( 'block', $this->detectors->mode( 'phone', 'chatbot' ) );

		Filters\expectApplied( 'axle_ai_guard_rule_mode' )->once()->andReturn( 'explode' );
		$this->assertSame( 'redact', $this->detectors->mode( 'phone' ), 'An invalid mode falls back.' );
	}

	public function test_custom_rules_and_the_detectors_filter(): void {
		$this->detectors->save(
			array(),
			array(
				array(
					'label'   => 'Order numbers',
					'type'    => 'regex',
					'pattern' => '/ORD-\d{4}/',
					'mode'    => 'redact',
				),
				array(
					'type'    => 'regex',
					'pattern' => '/broken(',
				),
				array(
					'type'    => 'string',
					'pattern' => 'Project Falcon',
					'mode'    => 'block',
				),
			)
		);

		$this->assertCount( 2, $this->detectors->custom() );
		$findings = $this->detectors->scan( 'ORD-1234 and ord-9999 for project falcon' );
		$this->assertSame( 1, $findings['custom-1']['count'] );
		$this->assertSame( 'block', $findings['custom-2']['mode'] );

		Filters\expectApplied( 'axle_ai_guard_detectors' )->atLeast()->once()->with( Mockery::type( 'array' ) )->andReturnUsing(
			static function ( array $detectors ): array {
				$detectors['iban'] = array(
					'label'   => 'IBAN',
					'default' => 'redact',
					'match'   => static function ( string $text ): array {
						return preg_match_all( '/DE\d{20}/', $text, $m ) ? $m[0] : array();
					},
				);
				$detectors['broken'] = array( 'label' => 'No callback' );
				return $detectors;
			}
		);
		$all = $this->detectors->all();
		$this->assertArrayHasKey( 'iban', $all );
		$this->assertArrayNotHasKey( 'broken', $all );
	}

	public function test_redaction_and_the_placeholder_filter(): void {
		list( $text, $counts ) = $this->detectors->redact( 'Mail jane@example.com now', array( 'user_email' ) );
		$this->assertSame( 'Mail [redacted:user_email] now', $text );
		$this->assertSame( array( 'user_email' => 1 ), $counts );

		Filters\expectApplied( 'axle_ai_guard_redact_placeholder' )->once()->with( '[redacted:email]', 'email' )->andReturn( '***' );
		$this->assertSame( 'Hi ***', $this->detectors->redact( 'Hi x@y.io', array( 'email' ) )[0] );
	}
}
