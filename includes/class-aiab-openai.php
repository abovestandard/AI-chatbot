<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OpenAI ChatGPT provider.
 * Get an API key at https://platform.openai.com/api-keys
 */
class AIAB_OpenAI {

	const API_URL = 'https://api.openai.com/v1/chat/completions';

	public static function ask( $question, $context_items, $history, $settings ) {
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'aiab_no_key', 'AI Assistant Bot: no OpenAI API key configured.' );
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => AIAB_Prompt::build_system( $settings ),
			),
		);

		foreach ( (array) $history as $turn ) {
			$role = ( isset( $turn['role'] ) && $turn['role'] === 'assistant' ) ? 'assistant' : 'user';
			$messages[] = array(
				'role'    => $role,
				'content' => (string) $turn['content'],
			);
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => AIAB_Prompt::build_user_turn( $question, $context_items ),
		);

		$body = array(
			'model'           => $settings['model'],
			'messages'        => $messages,
			'temperature'     => 0.3,
			'max_tokens'      => 8192,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = self::request( $body, $settings['api_key'], 60 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = self::extract_message_text( $response );
		if ( $text === '' ) {
			return new WP_Error( 'aiab_api_error', 'OpenAI returned an empty response.' );
		}

		return AIAB_Prompt::parse_json_response( $text );
	}

	public static function expand_query( $question, $settings ) {
		$fallback = array( 'terms' => array(), 'wants_contact' => false );
		if ( empty( $settings['api_key'] ) ) {
			return $fallback;
		}

		$body = array(
			'model'           => $settings['model'],
			'messages'        => array(
				array( 'role' => 'system', 'content' => AIAB_Prompt::build_expand_system() ),
				array( 'role' => 'user', 'content' => (string) $question ),
			),
			'temperature'     => 0.2,
			'max_tokens'      => 200,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = self::request( $body, $settings['api_key'], 15 );
		if ( is_wp_error( $response ) ) {
			return $fallback;
		}

		$text = self::extract_message_text( $response );
		return AIAB_Prompt::parse_expand_response( $text );
	}

	public static function test_key( $settings ) {
		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'aiab_api_error', 'No API key configured.' );
		}

		$body = array(
			'model'      => $settings['model'],
			'messages'   => array(
				array( 'role' => 'user', 'content' => 'Say "ok".' ),
			),
			'max_tokens' => 10,
		);

		$response = self::request( $body, $settings['api_key'], 20 );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( self::extract_message_text( $response ) !== '' ) {
			return true;
		}

		return new WP_Error( 'aiab_api_error', 'OpenAI returned an empty test response.' );
	}

	/**
	 * @return array{text:string,error:string}
	 */
	public static function extract_document_text( $path, $mime_type, $settings ) {
		if ( $mime_type !== 'application/pdf' ) {
			return array( 'text' => '', 'error' => 'Unsupported document type.' );
		}
		if ( empty( $settings['api_key'] ) ) {
			return array( 'text' => '', 'error' => 'Ingen API-nøgle er gemt. Indtast og gem din OpenAI API-nøgle ovenfor, og prøv upload igen.' );
		}

		$data = file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			return array( 'text' => '', 'error' => 'PDF-filen er tom eller ulæselig.' );
		}
		if ( strlen( $data ) > 15 * 1024 * 1024 ) {
			return array( 'text' => '', 'error' => 'PDF-filen er for stor til AI-udtrækning (maks. 15 MB).' );
		}

		$filename = basename( $path );
		if ( ! preg_match( '/\.pdf$/i', $filename ) ) {
			$filename .= '.pdf';
		}

		$body = array(
			'model'    => self::pdf_capable_model( $settings['model'] ),
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'file',
							'file' => array(
								'filename'  => $filename,
								'file_data' => 'data:application/pdf;base64,' . base64_encode( $data ),
							),
						),
						array(
							'type' => 'text',
							'text' => 'Extract every piece of readable text from this PDF document. Return plain text only. Do not summarize. Do not use markdown. If the document truly has no readable text, return exactly: EMPTY',
						),
					),
				),
			),
			'max_tokens'  => 16384,
			'temperature' => 0,
		);

		$response = self::request( $body, $settings['api_key'], 120 );
		if ( is_wp_error( $response ) ) {
			return array( 'text' => '', 'error' => 'OpenAI: ' . $response->get_error_message() );
		}

		$text = trim( self::extract_message_text( $response ) );
		if ( $text === '' || strtoupper( $text ) === 'EMPTY' ) {
			return array( 'text' => '', 'error' => 'OpenAI fandt ingen læsbar tekst i PDF\'en.' );
		}

		return array( 'text' => $text, 'error' => '' );
	}

	private static function pdf_capable_model( $model ) {
		$model = trim( (string) $model );
		if ( $model !== '' && preg_match( '/^gpt-4o/i', $model ) ) {
			return $model;
		}
		return 'gpt-4o-mini';
	}

	private static function request( $body, $api_key, $timeout ) {
		$response = wp_remote_post( self::API_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unexpected response from OpenAI API (HTTP ' . $code . ').';
			return new WP_Error( 'aiab_api_error', $msg );
		}

		return $data;
	}

	private static function extract_message_text( $data ) {
		if ( ! is_array( $data ) || empty( $data['choices'][0]['message']['content'] ) ) {
			return '';
		}
		$content = $data['choices'][0]['message']['content'];
		return is_string( $content ) ? trim( $content ) : '';
	}
}
