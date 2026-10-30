<?php
/**
 * Reads the AI Client SDK objects without depending on their classes.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The SDK events, models, messages and results are read through their public
 * getters, each checked with method_exists(), so a later SDK that renames or
 * adds something degrades to "unknown" instead of a fatal error.
 */
final class Sdk {

	/**
	 * The prompt builder method being called, from a backtrace.
	 *
	 * @param array $frames Backtrace with arguments.
	 * @return string Such as generate_text or is_supported_for_text_generation; '' when not found.
	 */
	public static function builder_method( array $frames ): string {
		foreach ( $frames as $frame ) {
			if ( '__call' === ( $frame['function'] ?? '' ) && 'WP_AI_Client_Prompt_Builder' === ( $frame['class'] ?? '' ) ) {
				return isset( $frame['args'][0] ) && is_string( $frame['args'][0] ) ? $frame['args'][0] : '';
			}
		}

		return '';
	}

	/**
	 * The prompt builder method of the current call.
	 *
	 * @return string
	 */
	public static function current_builder_method(): string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Needs the method name argument of the builder's __call frame.
		return self::builder_method( debug_backtrace( 0, 12 ) );
	}

	/**
	 * Whether a builder method is a support check (is_supported*).
	 *
	 * @param string $method Method name.
	 * @return bool
	 */
	public static function is_support_check( string $method ): bool {
		return 0 === strpos( $method, 'is_supported' );
	}

	/**
	 * The messages of an event as plain arrays.
	 *
	 * @param object $event Before or after event.
	 * @return array<int, array{role: string, texts: string[], files: array<int, array{mime: string, bytes: int}>}>
	 */
	public static function messages( object $event ): array {
		$messages = method_exists( $event, 'getMessages' ) ? $event->getMessages() : array();
		$out      = array();

		foreach ( is_array( $messages ) ? $messages : array() as $message ) {
			if ( ! is_object( $message ) ) {
				continue;
			}
			$entry = array(
				'role'  => method_exists( $message, 'getRole' ) ? (string) $message->getRole() : 'user',
				'texts' => array(),
				'files' => array(),
			);
			$parts = method_exists( $message, 'getParts' ) ? $message->getParts() : array();

			foreach ( is_array( $parts ) ? $parts : array() as $part ) {
				if ( ! is_object( $part ) ) {
					continue;
				}
				$text = method_exists( $part, 'getText' ) ? $part->getText() : null;
				if ( is_string( $text ) && '' !== $text ) {
					$entry['texts'][] = $text;
				}
				$file = method_exists( $part, 'getFile' ) ? $part->getFile() : null;
				if ( is_object( $file ) ) {
					$data             = method_exists( $file, 'getBase64Data' ) ? (string) $file->getBase64Data() : '';
					$entry['files'][] = array(
						'mime'  => method_exists( $file, 'getMimeType' ) ? (string) $file->getMimeType() : '',
						'bytes' => (int) floor( strlen( $data ) * 3 / 4 ),
					);
				}
				// Tool calls and tool results carry data too.
				foreach ( array( 'getFunctionCall', 'getFunctionResponse' ) as $getter ) {
					$object = method_exists( $part, $getter ) ? $part->$getter() : null;
					if ( is_object( $object ) && method_exists( $object, 'toArray' ) ) {
						$entry['texts'][] = (string) wp_json_encode( $object->toArray() );
					}
				}
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Provider, model, capability, system instruction and max tokens of an event.
	 *
	 * @param object $event Before or after event.
	 * @return array{provider: string, model: string, capability: string, system: string, max_tokens: int}
	 */
	public static function model( object $event ): array {
		$info  = array(
			'provider'   => '',
			'model'      => '',
			'capability' => '',
			'system'     => '',
			'max_tokens' => 0,
		);
		$model = method_exists( $event, 'getModel' ) ? $event->getModel() : null;

		if ( is_object( $model ) ) {
			$provider = method_exists( $model, 'providerMetadata' ) ? $model->providerMetadata() : null;
			$meta     = method_exists( $model, 'metadata' ) ? $model->metadata() : null;
			$config   = method_exists( $model, 'getConfig' ) ? $model->getConfig() : null;

			$info['provider'] = is_object( $provider ) && method_exists( $provider, 'getId' ) ? (string) $provider->getId() : '';
			$info['model']    = is_object( $meta ) && method_exists( $meta, 'getId' ) ? (string) $meta->getId() : '';
			if ( is_object( $config ) ) {
				$info['system']     = method_exists( $config, 'getSystemInstruction' ) ? (string) $config->getSystemInstruction() : '';
				$info['max_tokens'] = method_exists( $config, 'getMaxTokens' ) ? (int) $config->getMaxTokens() : 0;
			}
		}

		$capability         = method_exists( $event, 'getCapability' ) ? $event->getCapability() : null;
		$info['capability'] = is_object( $capability ) && method_exists( $capability, '__toString' ) ? (string) $capability : '';

		return $info;
	}

	/**
	 * Token usage, provider and model of a result.
	 *
	 * @param object $event After event.
	 * @return array{prompt: int, completion: int, thought: int, total: int, provider: string, model: string}
	 */
	public static function result( object $event ): array {
		$out    = array(
			'prompt'     => 0,
			'completion' => 0,
			'thought'    => 0,
			'total'      => 0,
			'provider'   => '',
			'model'      => '',
		);
		$result = method_exists( $event, 'getResult' ) ? $event->getResult() : null;
		if ( ! is_object( $result ) ) {
			return $out;
		}

		$usage = method_exists( $result, 'getTokenUsage' ) ? $result->getTokenUsage() : null;
		if ( is_object( $usage ) ) {
			$out['prompt']     = method_exists( $usage, 'getPromptTokens' ) ? (int) $usage->getPromptTokens() : 0;
			$out['completion'] = method_exists( $usage, 'getCompletionTokens' ) ? (int) $usage->getCompletionTokens() : 0;
			$out['thought']    = method_exists( $usage, 'getThoughtTokens' ) ? (int) $usage->getThoughtTokens() : 0;
			$out['total']      = method_exists( $usage, 'getTotalTokens' ) ? (int) $usage->getTotalTokens() : 0;
		}
		if ( $out['total'] < $out['prompt'] + $out['completion'] ) {
			$out['total'] = $out['prompt'] + $out['completion'] + $out['thought'];
		}

		$provider        = method_exists( $result, 'getProviderMetadata' ) ? $result->getProviderMetadata() : null;
		$model           = method_exists( $result, 'getModelMetadata' ) ? $result->getModelMetadata() : null;
		$out['provider'] = is_object( $provider ) && method_exists( $provider, 'getId' ) ? (string) $provider->getId() : '';
		$out['model']    = is_object( $model ) && method_exists( $model, 'getId' ) ? (string) $model->getId() : '';

		return $out;
	}

	/**
	 * Token usage from a provider's JSON response body: OpenAI, Anthropic or Google shape.
	 *
	 * @param array $body Decoded response.
	 * @return array{prompt: int, completion: int, total: int}|null
	 */
	public static function usage_from_body( array $body ): ?array {
		if ( isset( $body['usage'] ) && is_array( $body['usage'] ) ) {
			$usage      = $body['usage'];
			$prompt     = (int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 );
			$completion = (int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 );

			return array(
				'prompt'     => $prompt,
				'completion' => $completion,
				'total'      => (int) ( $usage['total_tokens'] ?? $prompt + $completion ),
			);
		}
		if ( isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ) {
			$usage      = $body['usageMetadata'];
			$prompt     = (int) ( $usage['promptTokenCount'] ?? 0 );
			$completion = (int) ( $usage['candidatesTokenCount'] ?? 0 );

			return array(
				'prompt'     => $prompt,
				'completion' => $completion,
				'total'      => (int) ( $usage['totalTokenCount'] ?? $prompt + $completion ),
			);
		}

		return null;
	}
}
