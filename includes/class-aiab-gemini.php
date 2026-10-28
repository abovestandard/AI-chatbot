<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Free-tier AI provider using Google's Gemini API.
 * Get a free key at https://aistudio.google.com/apikey
 */
class AIAB_Gemini {

	public static function api_url( $model, $api_key ) {
		return 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $api_key );
	}

	/**
	 * Models known to accept inline PDF input, tried in order as fallbacks.
	 */
	private static function pdf_capable_models( $preferred_model ) {
		$models = array(
			$preferred_model,
			'gemini-2.0-flash',
			'gemini-1.5-flash',
			'gemini-1.5-flash-8b',
			'gemini-flash-lite-latest',
		);
		return array_values( array_unique( array_filter( array_map( 'trim', $models ) ) ) );
	}

	public static function ask( $question, $context_items, $history, $settings ) {

		if ( empty( $settings['api_key'] ) ) {
			return new WP_Error( 'aiab_no_key', 'AI Assistant Bot: no Gemini API key configured.' );
		}

		$contents = array();
		foreach ( (array) $history as $turn ) {
			$role = ( isset( $turn['role'] ) && $turn['role'] === 'assistant' ) ? 'model' : 'user';
			$contents[] = array( 'role' => $role, 'parts' => array( array( 'text' => (string) $turn['content'] ) ) );
		}
		$contents[] = array(
			'role'  => 'user',
			'parts' => array( array( 'text' => AIAB_Prompt::build_user_turn( $question, $context_items ) ) ),
		);

		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => AIAB_Prompt::build_system( $settings ) ) ) ),
			'contents'          => $contents,
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'maxOutputTokens'  => 8192,
				'temperature'      => 0.3,
			),
		);

		$response = wp_remote_post( self::api_url( $settings['model'], $settings['api_key'] ), array(
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unexpected response from Gemini API (HTTP ' . $code . ').';
			return new WP_Error( 'aiab_api_error', $msg );
		}

		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? $data['candidates'][0]['content']['parts'][0]['text'] : '';
		if ( empty( $text ) ) {
			return new WP_Error( 'aiab_api_error', 'Gemini returned an empty response.' );
		}

		return AIAB_Prompt::parse_json_response( $text );
	}

	/**
	 * Lightweight call (small token budget, no context items) that turns the
	 * visitor's question into a handful of underlying search concepts.
	 * See AIAB_Prompt::build_expand_system() for the reasoning.
	 */
	public static function expand_query( $question, $settings ) {
		$body = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => AIAB_Prompt::build_expand_system() ) ) ),
			'contents'          => array(
				array( 'role' => 'user', 'parts' => array( array( 'text' => $question ) ) ),
			),
			'generationConfig'  => array(
				'responseMimeType' => 'application/json',
				'maxOutputTokens'  => 200,
				'temperature'      => 0.2,
			),
		);

		$response = wp_remote_post( self::api_url( $settings['model'], $settings['api_key'] ), array(
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		$fallback = array( 'terms' => array(), 'wants_contact' => false );

		if ( is_wp_error( $response ) ) {
			return $fallback;
		}
		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $fallback;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ? $data['candidates'][0]['content']['parts'][0]['text'] : '';
		return AIAB_Prompt::parse_expand_response( $text );
	}

	/**
	 * Minimal ping used by the "Test API Key" button.
	 */
	public static function test_key( $settings ) {
		$response = wp_remote_post( self::api_url( $settings['model'], $settings['api_key'] ), array(
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'contents' => array( array( 'parts' => array( array( 'text' => 'Say "ok".' ) ) ) ),
				'generationConfig' => array( 'maxOutputTokens' => 10 ),
			) ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 200 ) {
			return true;
		}
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error (HTTP ' . $code . ').';
		return new WP_Error( 'aiab_api_error', $msg );
	}

	/**
	 * Ask Gemini to extract all readable text from a PDF document.
	 *
	 * @return array{text:string,error:string}
	 */
	public static function extract_document_text( $path, $mime_type, $settings ) {
		$empty = array( 'text' => '', 'error' => '' );

		if ( $mime_type !== 'application/pdf' ) {
			return array( 'text' => '', 'error' => 'Unsupported document type.' );
		}
		if ( empty( $settings['api_key'] ) ) {
			return array( 'text' => '', 'error' => 'Ingen API-nøgle er gemt. Indtast og gem din Gemini API-nøgle ovenfor, og prøv upload igen.' );
		}
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array( 'text' => '', 'error' => 'PDF-filen kunne ikke læses fra serveren.' );
		}

		$data = file_get_contents( $path );
		if ( $data === false || $data === '' ) {
			return array( 'text' => '', 'error' => 'PDF-filen er tom eller ulæselig.' );
		}
		if ( strlen( $data ) > 15 * 1024 * 1024 ) {
			return array( 'text' => '', 'error' => 'PDF-filen er for stor til AI-udtrækning (maks. 15 MB).' );
		}

		$last_error = 'Gemini kunne ikke udtrække tekst fra PDF\'en.';
		foreach ( self::pdf_capable_models( isset( $settings['model'] ) ? $settings['model'] : '' ) as $model ) {
			$result = self::extract_pdf_with_model( $data, $settings['api_key'], $model );
			if ( $result['text'] !== '' ) {
				return $result;
			}
			if ( ! empty( $result['error'] ) ) {
				$last_error = $result['error'];
			}
		}

		return array( 'text' => '', 'error' => $last_error );
	}

	/**
	 * @return array{text:string,error:string}
	 */
	private static function extract_pdf_with_model( $pdf_binary, $api_key, $model ) {
		$prompt = 'Extract every piece of readable text from this PDF document. '
			. 'Return plain text only. Do not summarize. Do not use markdown. '
			. 'Preserve headings, paragraphs, bullet lists, and tables as plain text. '
			. 'If the document truly has no readable text, return exactly: EMPTY';

		$body = array(
			'contents' => array(
				array(
					'parts' => array(
						array(
							'inlineData' => array(
								'mimeType' => 'application/pdf',
								'data'     => base64_encode( $pdf_binary ),
							),
						),
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 16384,
				'temperature'     => 0,
			),
		);

		$response = wp_remote_post( self::api_url( $model, $api_key ), array(
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'text' => '', 'error' => 'Netværksfejl mod Gemini (' . $model . '): ' . $response->get_error_message() );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = isset( $payload['error']['message'] ) ? $payload['error']['message'] : 'HTTP ' . $code;
			return array( 'text' => '', 'error' => 'Gemini (' . $model . '): ' . $msg );
		}

		$text = self::collect_response_text( $payload );
		$text = trim( $text );
		if ( $text === '' || strtoupper( $text ) === 'EMPTY' ) {
			$reason = isset( $payload['candidates'][0]['finishReason'] ) ? $payload['candidates'][0]['finishReason'] : 'unknown';
			return array( 'text' => '', 'error' => 'Gemini (' . $model . ') fandt ingen læsbar tekst (årsag: ' . $reason . ').' );
		}

		return array( 'text' => $text, 'error' => '' );
	}

	/**
	 * Concatenate all text parts from a Gemini response payload.
	 */
	private static function collect_response_text( $payload ) {
		$text = '';
		if ( empty( $payload['candidates'][0]['content']['parts'] ) || ! is_array( $payload['candidates'][0]['content']['parts'] ) ) {
			return $text;
		}
		foreach ( $payload['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
				$text .= $part['text'];
			}
		}
		return $text;
	}
}
