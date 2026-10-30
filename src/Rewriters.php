<?php
/**
 * Rewrites provider request bodies to redact personal data.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Prompts can only be changed at the HTTP layer, in the JSON body of the
 * provider request. Each rewriter knows one body format and returns null
 * when the body is not in its format. The first one that answers wins.
 */
final class Rewriters {

	/**
	 * The rewriters: name => callable( array $body, callable $redact ): ?array.
	 *
	 * @return array<string, callable>
	 */
	public static function all(): array {
		$rewriters = array(
			'google'    => array( self::class, 'google' ),
			'anthropic' => array( self::class, 'anthropic' ),
			'openai'    => array( self::class, 'openai' ),
		);

		/**
		 * Filters the request body rewriters that redact personal data, one per provider format.
		 * Each gets the decoded JSON body and a callback that redacts a string, and returns the
		 * new body, or null when the body is not in its format.
		 *
		 * @param array $rewriters Callables keyed by format name. Built in: google, anthropic, openai.
		 */
		$filtered = apply_filters( 'axle_ai_guard_provider_body_rewriters', $rewriters );

		return array_filter( Hooks::arr( $filtered, $rewriters ), 'is_callable' );
	}

	/**
	 * Runs the first rewriter that knows the format, else redacts every string in the usual text fields.
	 *
	 * @param array    $body   Decoded body.
	 * @param callable $redact Callback: string in, string out.
	 * @return array
	 */
	public static function apply( array $body, callable $redact ): array {
		foreach ( self::all() as $rewriter ) {
			$result = call_user_func( $rewriter, $body, $redact );
			if ( is_array( $result ) ) {
				return $result;
			}
		}

		return self::walk( $body, $redact, array( 'content', 'text', 'system', 'input', 'prompt', 'instructions' ) );
	}

	/**
	 * OpenAI chat completions and responses: messages[].content, input, instructions.
	 *
	 * @param array    $body   Body.
	 * @param callable $redact Callback.
	 * @return array|null
	 */
	public static function openai( array $body, callable $redact ): ?array {
		if ( ! isset( $body['messages'] ) && ! isset( $body['input'] ) ) {
			return null;
		}

		return self::walk( $body, $redact, array( 'content', 'text', 'input', 'instructions' ) );
	}

	/**
	 * Anthropic messages: system, messages[].content.
	 *
	 * @param array    $body   Body.
	 * @param callable $redact Callback.
	 * @return array|null
	 */
	public static function anthropic( array $body, callable $redact ): ?array {
		if ( ! isset( $body['messages'] ) || ( ! isset( $body['system'] ) && ! isset( $body['anthropic_version'] ) ) ) {
			return null;
		}

		return self::walk( $body, $redact, array( 'content', 'text', 'system' ) );
	}

	/**
	 * Google Gemini: contents[].parts[].text and systemInstruction.
	 *
	 * @param array    $body   Body.
	 * @param callable $redact Callback.
	 * @return array|null
	 */
	public static function google( array $body, callable $redact ): ?array {
		if ( ! isset( $body['contents'] ) ) {
			return null;
		}

		return self::walk( $body, $redact, array( 'text' ) );
	}

	/**
	 * Redacts strings under text keys, at any depth.
	 *
	 * @param array    $data      Data.
	 * @param callable $redact    Callback.
	 * @param string[] $text_keys Keys whose string values (or lists of strings) are text.
	 * @return array
	 */
	public static function walk( array $data, callable $redact, array $text_keys ): array {
		foreach ( $data as $key => $value ) {
			$is_text = is_string( $key ) && in_array( $key, $text_keys, true );
			if ( is_string( $value ) && $is_text ) {
				$data[ $key ] = (string) call_user_func( $redact, $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $is_text && array_values( $value ) === $value && array_filter( $value, 'is_string' ) === $value
					? array_map(
						static function ( string $item ) use ( $redact ): string {
							return (string) call_user_func( $redact, $item );
						},
						$value
					)
					: self::walk( $value, $redact, $text_keys );
			}
		}

		return $data;
	}
}
