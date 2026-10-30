<?php
/**
 * Tests for prices and costs.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Prices;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Prices.
 */
final class PricesTest extends GuardTestCase {

	public function test_the_longest_wildcard_wins(): void {
		$this->assertSame( 0.25, $this->prices->price( 'openai', 'gpt-5-mini-2025-08-07' )['input'] );
		$this->assertSame( 1.25, $this->prices->price( 'openai', 'gpt-5-2025-08-07' )['input'] );
		$this->assertNull( $this->prices->price( 'acme', 'llm' ) );
	}

	public function test_cost_in_micro_units(): void {
		// 1,000 in at 0.25 and 500 out at 2.00 per million.
		$this->assertSame( 1250, $this->prices->cost( 'openai', 'gpt-5-mini', 1000, 500 ) );
		$this->assertSame( 0, $this->prices->cost( 'acme', 'llm', 1000, 500 ) );
	}

	public function test_set_saves_a_dated_table(): void {
		$this->assertFalse( $this->prices->edited() );
		$this->assertTrue( $this->prices->set( 'acme/llm-1', 1, 2 ) );
		$this->assertFalse( $this->prices->set( 'no slash', 1, 2 ) );
		$this->assertTrue( $this->prices->edited() );
		$this->assertSame( gmdate( 'Y-m-d' ), $this->prices->as_of() );
		$this->assertSame( 2.0, $this->prices->price( 'acme', 'llm-1' )['thought'] );
		$this->prices->reset();
		$this->assertSame( Prices::AS_OF, $this->prices->as_of() );
	}

	public function test_the_prices_filter(): void {
		Filters\expectApplied( 'axle_ai_guard_prices' )->once()->with( Mockery::type( 'array' ) )->andReturn(
			array(
				'acme/*' => array(
					'input'  => 3,
					'output' => 'x',
				),
				'bad'    => array(),
			)
		);
		$table = $this->prices->table();
		$this->assertSame( array( 'acme/*' ), array_keys( $table ) );
		$this->assertSame( 0.0, $table['acme/*']['output'] );
	}

	public function test_the_cost_and_currency_filters(): void {
		Filters\expectApplied( 'axle_ai_guard_cost' )->once()->with( 1250, 'openai', 'gpt-5-mini', Mockery::type( 'array' ) )->andReturn( -5 );
		$this->assertSame( 0, $this->prices->cost( 'openai', 'gpt-5-mini', 1000, 500 ) );

		Filters\expectApplied( 'axle_ai_guard_currency' )->once()->with( 'USD' )->andReturn( 'INR' );
		$this->assertSame( 'INR', $this->prices->currency() );
		Filters\expectApplied( 'axle_ai_guard_currency' )->once()->andReturn( 'rupees' );
		$this->assertSame( 'USD', $this->prices->currency() );
	}

	public function test_formatting_and_parsing_money(): void {
		$this->assertSame( 'USD 0.0018', Prices::format( 1800, 'USD' ) );
		$this->assertSame( 'EUR 12.50', Prices::format( 12500000, 'EUR' ) );
		$this->assertSame( 12500000, Prices::to_micros( '12.5' ) );
		$this->assertSame( 0, Prices::to_micros( 'lots' ) );
	}
}
