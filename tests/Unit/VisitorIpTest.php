<?php
/**
 * Tests for the visitor address: a forwarded header is never trusted unless the site names it.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Context;
use Brain\Monkey\Filters;

/**
 * Covers Context::visitor_ip(), client_ip() and ip_in().
 */
final class VisitorIpTest extends GuardTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
		parent::tearDown();
	}

	public function test_forwarded_headers_are_ignored_by_default(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.77';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.11';

		$this->assertSame( '203.0.113.77', Context::client_ip() );
		$this->assertSame( '203.0.113.0', Context::visitor_ip( 'front' ) );
	}

	public function test_a_header_the_site_names_is_read(): void {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.9';
		Filters\expectApplied( 'axle_ai_guard_ip_header' )->once()->andReturn( 'http_cf_connecting_ip' );

		$this->assertSame( '198.51.100.10', Context::client_ip() );
	}

	public function test_a_named_header_that_is_missing_or_junk_falls_back_to_the_connection(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		Filters\expectApplied( 'axle_ai_guard_ip_header' )->twice()->andReturn( 'HTTP_X_FORWARDED_FOR' );
		$this->assertSame( '203.0.113.77', Context::client_ip(), 'Header not sent.' );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, <script>';
		$this->assertSame( '203.0.113.77', Context::client_ip(), 'Header is not an address.' );
	}

	public function test_with_trusted_proxies_the_header_counts_only_from_them(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		Filters\expectApplied( 'axle_ai_guard_ip_header' )->twice()->andReturn( 'HTTP_X_FORWARDED_FOR' );
		Filters\expectApplied( 'axle_ai_guard_trusted_proxies' )->twice()->andReturn( array( '10.0.0.0/8', '2001:db8::/32' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->assertSame( '203.0.113.77', Context::client_ip(), 'A direct visitor cannot set the header.' );

		$_SERVER['REMOTE_ADDR'] = '10.1.2.3';
		$this->assertSame( '198.51.100.9', Context::client_ip(), 'A listed proxy can.' );
	}

	public function test_a_chain_is_read_from_the_nearest_proxy_and_skips_trusted_ones(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.9, 10.0.0.2';
		Filters\expectApplied( 'axle_ai_guard_ip_header' )->once()->andReturn( 'HTTP_X_FORWARDED_FOR' );
		Filters\expectApplied( 'axle_ai_guard_trusted_proxies' )->once()->andReturn( array( '10.0.0.0/24' ) );

		$this->assertSame( '198.51.100.9', Context::client_ip(), 'The spoofed first entry is not used.' );
	}

	public function test_without_a_proxy_list_the_last_entry_is_used_not_the_first(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 198.51.100.9';
		Filters\expectApplied( 'axle_ai_guard_ip_header' )->once()->andReturn( 'HTTP_X_FORWARDED_FOR' );

		$this->assertSame( '198.51.100.9', Context::client_ip() );
	}

	public function test_the_visitor_ip_filter_has_the_last_word_and_must_return_an_address(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		Filters\expectApplied( 'axle_ai_guard_visitor_ip' )->once()->with( '203.0.113.77', 'front' )->andReturn( '198.51.100.9' );
		$this->assertSame( '198.51.100.0', Context::visitor_ip( 'front' ) );

		Filters\expectApplied( 'axle_ai_guard_visitor_ip' )->once()->andReturn( 'nope' );
		$this->assertSame( '', Context::visitor_ip( 'front' ) );
	}

	public function test_ip_in_matches_addresses_and_ranges_for_both_families(): void {
		$this->assertTrue( Context::ip_in( '10.1.2.3', array( '10.0.0.0/8' ) ) );
		$this->assertFalse( Context::ip_in( '11.1.2.3', array( '10.0.0.0/8' ) ) );
		$this->assertTrue( Context::ip_in( '192.168.1.130', array( '192.168.1.128/25' ) ) );
		$this->assertFalse( Context::ip_in( '192.168.1.5', array( '192.168.1.128/25' ) ) );
		$this->assertTrue( Context::ip_in( '203.0.113.7', array( '203.0.113.7' ) ) );
		$this->assertTrue( Context::ip_in( '2001:db8::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( Context::ip_in( '2001:db9::1', array( '2001:db8::/32' ) ) );
		$this->assertFalse( Context::ip_in( '10.1.2.3', array( '2001:db8::/32', 'junk' ) ), 'Families do not mix.' );
	}
}
