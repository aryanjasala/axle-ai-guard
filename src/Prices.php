<?php
/**
 * The price table and what a call cost.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Prices per million tokens, per provider and model. Core knows no prices, so
 * the plugin ships a dated table of list prices that the site can edit. Costs
 * are stored as integer micro-units of the site currency (1 USD = 1,000,000).
 */
final class Prices {

	public const OPTION = 'axle_ai_guard_prices';

	/**
	 * The date the default table was checked against the providers' price pages.
	 */
	public const AS_OF = '2026-06-01';

	/**
	 * Settings, for the currency.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the table.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * List prices in USD per million tokens. A key ending in * matches every
	 * model id that starts with the rest, such as dated snapshots.
	 *
	 * @return array<string, array{input: float, output: float, thought: float}>
	 */
	public static function defaults(): array {
		$rows = array(
			'openai/gpt-5-nano*'             => array( 0.05, 0.40 ),
			'openai/gpt-5-mini*'             => array( 0.25, 2.00 ),
			'openai/gpt-5*'                  => array( 1.25, 10.00 ),
			'openai/gpt-4.1-nano*'           => array( 0.10, 0.40 ),
			'openai/gpt-4.1-mini*'           => array( 0.40, 1.60 ),
			'openai/gpt-4.1*'                => array( 2.00, 8.00 ),
			'openai/gpt-4o-mini*'            => array( 0.15, 0.60 ),
			'openai/gpt-4o*'                 => array( 2.50, 10.00 ),
			'openai/text-embedding-3-small*' => array( 0.02, 0.00 ),
			'openai/text-embedding-3-large*' => array( 0.13, 0.00 ),
			'anthropic/claude-opus-4-1*'     => array( 15.00, 75.00 ),
			'anthropic/claude-opus-4*'       => array( 5.00, 25.00 ),
			'anthropic/claude-sonnet-4*'     => array( 3.00, 15.00 ),
			'anthropic/claude-haiku-4*'      => array( 1.00, 5.00 ),
			'anthropic/claude-3-5-haiku*'    => array( 0.80, 4.00 ),
			'google/gemini-2.5-pro*'         => array( 1.25, 10.00 ),
			'google/gemini-2.5-flash-lite*'  => array( 0.10, 0.40 ),
			'google/gemini-2.5-flash*'       => array( 0.30, 2.50 ),
			'google/gemini-2.0-flash*'       => array( 0.10, 0.40 ),
			'google/gemini-embedding*'       => array( 0.15, 0.00 ),
		);

		$table = array();
		foreach ( $rows as $key => $row ) {
			$table[ $key ] = array(
				'input'   => $row[0],
				'output'  => $row[1],
				'thought' => $row[1],
			);
		}

		return $table;
	}

	/**
	 * The price table in use: the edited one, or the defaults.
	 *
	 * @return array<string, array{input: float, output: float, thought: float}>
	 */
	public function table(): array {
		$saved = get_option( self::OPTION, null );
		$table = is_array( $saved ) && isset( $saved['models'] ) && is_array( $saved['models'] ) ? $saved['models'] : self::defaults();

		/**
		 * Filters the price table: input, output and thought price per million
		 * tokens, in the site currency, keyed by provider/model. A key ending in
		 * * matches every model id that starts with the rest.
		 *
		 * @param array $table Prices.
		 */
		$filtered = apply_filters( 'axle_ai_guard_prices', $table );

		return self::sanitize( Hooks::arr( $filtered, $table ) );
	}

	/**
	 * When the table in use was last checked or edited.
	 *
	 * @return string Y-m-d.
	 */
	public function as_of(): string {
		$saved = get_option( self::OPTION, null );

		return is_array( $saved ) && ! empty( $saved['as_of'] ) ? (string) $saved['as_of'] : self::AS_OF;
	}

	/**
	 * Whether the table was edited on this site.
	 *
	 * @return bool
	 */
	public function edited(): bool {
		$saved = get_option( self::OPTION, null );

		return is_array( $saved ) && isset( $saved['models'] );
	}

	/**
	 * Saves the whole table.
	 *
	 * @param array $table Prices keyed by provider/model.
	 */
	public function save( array $table ): void {
		update_option(
			self::OPTION,
			array(
				'as_of'  => gmdate( 'Y-m-d' ),
				'models' => self::sanitize( $table ),
			),
			false
		);
	}

	/**
	 * Sets the price of one model.
	 *
	 * @param string     $key     provider/model.
	 * @param float      $input   Input price per million tokens.
	 * @param float      $output  Output price per million tokens.
	 * @param float|null $thought Thought price, or null for the output price.
	 * @return bool Whether the key was valid.
	 */
	public function set( string $key, float $input, float $output, ?float $thought = null ): bool {
		$key = self::key( $key );
		if ( '' === $key ) {
			return false;
		}

		$table         = $this->table();
		$table[ $key ] = array(
			'input'   => $input,
			'output'  => $output,
			'thought' => null === $thought ? $output : $thought,
		);
		$this->save( $table );

		return true;
	}

	/**
	 * Back to the shipped table.
	 */
	public function reset(): void {
		delete_option( self::OPTION );
	}

	/**
	 * A clean provider/model key, or ''.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	public static function key( string $key ): string {
		$key = strtolower( trim( $key ) );

		return preg_match( '#^[a-z0-9_\-]+/[a-z0-9_.:\-]*\*?$#', $key ) && '/' !== substr( $key, -1 ) ? $key : '';
	}

	/**
	 * Cleans a table.
	 *
	 * @param array $table Raw table.
	 * @return array<string, array{input: float, output: float, thought: float}>
	 */
	public static function sanitize( array $table ): array {
		$clean = array();
		foreach ( $table as $key => $row ) {
			$key = self::key( (string) $key );
			if ( '' === $key || ! is_array( $row ) ) {
				continue;
			}
			$output        = max( 0.0, (float) ( $row['output'] ?? 0 ) );
			$clean[ $key ] = array(
				'input'   => max( 0.0, (float) ( $row['input'] ?? 0 ) ),
				'output'  => $output,
				'thought' => isset( $row['thought'] ) && '' !== $row['thought'] ? max( 0.0, (float) $row['thought'] ) : $output,
			);
		}

		return $clean;
	}

	/**
	 * The price row for a model: an exact key first, then the longest wildcard.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @return array{input: float, output: float, thought: float}|null
	 */
	public function price( string $provider, string $model ): ?array {
		$table = $this->table();
		$exact = strtolower( $provider . '/' . $model );
		if ( isset( $table[ $exact ] ) ) {
			return $table[ $exact ];
		}

		$best = null;
		$size = -1;
		foreach ( $table as $key => $row ) {
			if ( '*' !== substr( $key, -1 ) ) {
				continue;
			}
			$prefix = substr( $key, 0, -1 );
			if ( 0 === strpos( $exact, $prefix ) && strlen( $prefix ) > $size ) {
				$best = $row;
				$size = strlen( $prefix );
			}
		}

		return $best;
	}

	/**
	 * What a call cost, in micro-units of the site currency.
	 *
	 * @param string $provider   Provider id.
	 * @param string $model      Model id.
	 * @param int    $prompt     Prompt tokens.
	 * @param int    $completion Completion tokens.
	 * @param int    $thought    Thought tokens.
	 * @return int
	 */
	public function cost( string $provider, string $model, int $prompt, int $completion, int $thought = 0 ): int {
		$row = $this->price( $provider, $model );
		// A price per million tokens is the price per token in micro-units.
		$cost = null === $row ? 0 : (int) round( $prompt * $row['input'] + $completion * $row['output'] + $thought * $row['thought'] );

		/**
		 * Filters what a call cost, in micro-units of the site currency.
		 *
		 * @param int    $cost     Cost.
		 * @param string $provider Provider id.
		 * @param string $model    Model id.
		 * @param array  $tokens   prompt, completion and thought tokens.
		 */
		$filtered = apply_filters(
			'axle_ai_guard_cost',
			$cost,
			$provider,
			$model,
			array(
				'prompt'     => $prompt,
				'completion' => $completion,
				'thought'    => $thought,
			)
		);

		return max( 0, Hooks::int( $filtered, $cost ) );
	}

	/**
	 * The site currency, an ISO 4217 code.
	 *
	 * @return string
	 */
	public function currency(): string {
		$currency = (string) $this->settings->get( 'currency' );

		/**
		 * Filters the currency of prices, costs and budgets.
		 *
		 * @param string $currency ISO 4217 code. Default from the settings, USD.
		 */
		$filtered = apply_filters( 'axle_ai_guard_currency', $currency );

		$filtered = Hooks::str( $filtered, $currency );

		return preg_match( '/^[A-Z]{3}$/', $filtered ) ? $filtered : $currency;
	}

	/**
	 * Micro-units as a money string.
	 *
	 * @param int    $micros   Micro-units.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function format( int $micros, string $currency ): string {
		$amount   = $micros / 1000000;
		$decimals = 0 !== $micros && abs( $amount ) < 0.01 ? 4 : 2;

		return $currency . ' ' . number_format( $amount, $decimals, '.', ',' );
	}

	/**
	 * A money amount typed by a person as micro-units.
	 *
	 * @param mixed $amount Such as 12.50.
	 * @return int
	 */
	public static function to_micros( $amount ): int {
		$amount = str_replace( array( ',', ' ' ), '', is_scalar( $amount ) ? (string) $amount : '' );

		return is_numeric( $amount ) ? max( 0, (int) round( (float) $amount * 1000000 ) ) : 0;
	}
}
