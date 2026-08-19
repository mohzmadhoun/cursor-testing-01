<?php
/**
 * Resolve the OpenAI API key from the AI Client registry.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Ai;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Logger;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Shared helper so chat, moderation, and embeddings never read Connectors options.
 */
final class Openai_Credentials {

	/**
	 * OpenAI API key from the AI Client registry, or empty string.
	 */
	public static function api_key(): string {
		if ( ! class_exists( '\\WordPress\\AiClient\\AiClient' ) ) {
			return '';
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			$auth     = $registry->getProviderRequestAuthentication( 'openai' );
			if ( ! $auth instanceof ApiKeyRequestAuthentication ) {
				return '';
			}
			return trim( (string) $auth->getApiKey() );
		} catch ( \Throwable $e ) {
			Logger::error(
				'OpenAI key resolve failed.',
				array( 'error' => $e->getMessage() )
			);
			return '';
		}
	}
}
