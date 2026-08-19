<?php
/**
 * AI gateway wrapping WordPress AI Client / OpenAI.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Logger;
use ChatHearth\Options;
use ChatHearth\Plugin;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WP_Error;

/**
 * Single entry point for generating chat replies.
 */
final class Ai_Gateway {

	/**
	 * Generate an assistant reply.
	 *
	 * @param string                                  $message Current user message.
	 * @param list<array{role:string,content:string}> $history Prior turns (user/assistant).
	 * @return string|WP_Error
	 */
	public function generate_reply( string $message, array $history ) {
		if ( ! Plugin::is_openai_ready() ) {
			Logger::error(
				'AI provider is not configured.',
				array( 'code' => 'chathearth_ai_unavailable' )
			);

			return new WP_Error(
				'chathearth_ai_unavailable',
				__( 'The AI provider is not configured. Please check OpenAI Connectors settings.', 'chathearth' ),
				array( 'status' => 503 )
			);
		}

		$system_prompt = (string) Options::get( 'system_prompt', '' );

		/**
		 * Filter the system prompt before generation.
		 *
		 * @param string $system_prompt System instruction.
		 * @param string $message       Current user message.
		 * @param array  $history       Conversation history.
		 */
		$system_prompt = (string) apply_filters( 'chathearth_system_prompt', $system_prompt, $message, $history );

		/**
		 * Filter conversation history before generation.
		 *
		 * @param array  $history History turns.
		 * @param string $message Current user message.
		 */
		$history = apply_filters( 'chathearth_messages', $history, $message );

		/**
		 * Fires before the AI generate call.
		 *
		 * @param string $message Current user message.
		 * @param array  $history History.
		 * @param string $system_prompt System prompt.
		 */
		do_action( 'chathearth_before_generate', $message, $history, $system_prompt );

		$history_messages = array();
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role    = isset( $turn['role'] ) ? (string) $turn['role'] : '';
			$content = isset( $turn['content'] ) ? trim( (string) $turn['content'] ) : '';
			if ( '' === $content ) {
				continue;
			}
			$part = new MessagePart( $content );
			if ( 'assistant' === $role || 'model' === $role ) {
				$history_messages[] = new ModelMessage( array( $part ) );
			} elseif ( 'user' === $role ) {
				$history_messages[] = new UserMessage( array( $part ) );
			}
		}

		$provider = (string) Options::get( 'ai_provider', 'openai' );
		if ( ! array_key_exists( $provider, Options::available_providers() ) ) {
			$provider = 'openai';
		}

		$model = (string) Options::get( 'ai_model', CHATHEARTH_DEFAULT_MODEL );
		if ( ! array_key_exists( $model, Options::available_openai_models() ) ) {
			$model = CHATHEARTH_DEFAULT_MODEL;
		}

		$builder = wp_ai_client_prompt( $message )
			->using_provider( $provider )
			->using_model_preference( $model )
			->using_system_instruction( $system_prompt );

		if ( ! empty( $history_messages ) ) {
			$builder = $builder->with_history( ...$history_messages );
		}

		$result = $builder->generate_text();

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'AI generation failed.',
				array(
					'provider' => $provider,
					'model'    => $model,
					'code'     => $result->get_error_code(),
					'message'  => $result->get_error_message(),
				)
			);

			return new WP_Error(
				'chathearth_ai_error',
				__( 'Sorry, the assistant could not generate a reply. Please try again later.', 'chathearth' ),
				array( 'status' => 502 )
			);
		}

		/**
		 * Filter the assistant reply text.
		 *
		 * @param string $text    Reply.
		 * @param string $message User message.
		 */
		return (string) apply_filters( 'chathearth_reply', $result, $message );
	}
}
