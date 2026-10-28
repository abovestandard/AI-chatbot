<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Routes AI requests to whichever provider is configured.
 */
class AIAB_AI {

	public static function ask( $question, $context_items, $history, $settings ) {
		if ( $settings['provider'] === 'openai' ) {
			return AIAB_OpenAI::ask( $question, $context_items, $history, $settings );
		}
		return AIAB_Gemini::ask( $question, $context_items, $history, $settings );
	}

	/**
	 * Expand a visitor's question into the underlying search concepts, and
	 * classify whether the visitor's intent is to find a contact person
	 * (see AIAB_Prompt::build_expand_system() for why). Always fails safe:
	 * returns array('terms' => array(), 'wants_contact' => false) on any
	 * error instead of a WP_Error, so a problem with this optional step
	 * never blocks the main answer.
	 */
	public static function expand_query( $question, $settings ) {
		$empty = array( 'terms' => array(), 'wants_contact' => false );

		if ( empty( $settings['api_key'] ) ) {
			return $empty;
		}

		if ( $settings['provider'] === 'openai' ) {
			return AIAB_OpenAI::expand_query( $question, $settings );
		}
		return AIAB_Gemini::expand_query( $question, $settings );
	}

	public static function test_key( $settings ) {
		if ( $settings['provider'] === 'openai' ) {
			return AIAB_OpenAI::test_key( $settings );
		}
		return AIAB_Gemini::test_key( $settings );
	}

	/**
	 * Extract plain text from an uploaded document (PDF) using the configured
	 * AI provider. Returns array( 'text' => string, 'error' => string ).
	 */
	public static function extract_document_text( $path, $mime_type, $settings ) {
		if ( ! file_exists( $path ) ) {
			return array( 'text' => '', 'error' => 'Filen blev ikke fundet på serveren.' );
		}

		if ( $settings['provider'] === 'openai' ) {
			return AIAB_OpenAI::extract_document_text( $path, $mime_type, $settings );
		}
		return AIAB_Gemini::extract_document_text( $path, $mime_type, $settings );
	}

	/**
	 * Human-readable provider label for admin messages.
	 */
	public static function provider_label( $provider ) {
		return $provider === 'openai' ? 'ChatGPT' : 'Gemini';
	}
}
