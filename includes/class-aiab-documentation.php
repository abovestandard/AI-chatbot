<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stores, indexes, and searches administrator-uploaded documentation files.
 */
class AIAB_Documentation {

	const OPTION_KEY   = 'aiab_documentation';
	const UPLOAD_SUBDIR = 'aiab-documentation';
	const CHUNK_SIZE   = 1500;
	const CHUNK_OVERLAP = 200;
	const MAX_FILE_SIZE = 10485760; // 10 MB.

	private static $allowed_extensions = array(
		'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json', 'xml', 'docx', 'pdf', 'rtf',
	);

	public function __construct() {
		add_action( 'wp_ajax_aiab_upload_documentation', array( $this, 'ajax_upload' ) );
		add_action( 'wp_ajax_aiab_delete_documentation', array( $this, 'ajax_delete' ) );
	}

	/**
	 * Absolute path to the documentation upload directory.
	 */
	public static function get_upload_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Public URL base for stored documentation files.
	 */
	public static function get_upload_url() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::UPLOAD_SUBDIR;
	}

	/**
	 * Ensure the upload directory exists and is protected from direct listing.
	 */
	public static function ensure_upload_dir() {
		$dir = self::get_upload_dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" );
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * All stored documentation records.
	 */
	public static function get_all() {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Persist documentation records.
	 */
	private static function save_all( $docs ) {
		update_option( self::OPTION_KEY, array_values( $docs ), false );
	}

	/**
	 * Find one record by id.
	 */
	public static function get_by_id( $id ) {
		foreach ( self::get_all() as $doc ) {
			if ( isset( $doc['id'] ) && $doc['id'] === $id ) {
				return $doc;
			}
		}
		return null;
	}

	/**
	 * Handle admin AJAX upload of a documentation file.
	 */
	public function ajax_upload() {
		check_ajax_referer( 'aiab_documentation', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse til at uploade dokumentation.' ) );
		}

		if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => 'Ingen fil blev modtaget.' ) );
		}

		$file = $_FILES['file'];
		if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => 'Filupload mislykkedes (fejlkode ' . (int) $file['error'] . ').' ) );
		}

		if ( (int) $file['size'] > self::MAX_FILE_SIZE ) {
			wp_send_json_error( array( 'message' => 'Filen er for stor. Maksimum er 10 MB.' ) );
		}

		$original_name = sanitize_file_name( wp_unslash( $file['name'] ) );
		$extension     = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::$allowed_extensions, true ) ) {
			wp_send_json_error( array(
				'message' => 'Filtypen understøttes ikke. Tilladte typer: ' . implode( ', ', self::$allowed_extensions ) . '.',
			) );
		}

		self::ensure_upload_dir();

		$doc_id   = 'doc_' . wp_generate_password( 12, false, false );
		$stored   = $doc_id . '.' . $extension;
		$dest     = self::get_upload_dir() . '/' . $stored;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			wp_send_json_error( array( 'message' => 'Kunne ikke gemme den uploadede fil.' ) );
		}

		@set_time_limit( 180 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$settings = AIAB_Settings::get_settings();
		if ( $extension === 'pdf' ) {
			$result = self::extract_pdf_complete( $dest, $settings );
			$text   = $result['text'];
			if ( $text === '' ) {
				@unlink( $dest );
				wp_send_json_error( array( 'message' => $result['message'] ) );
			}
		} else {
			$text = self::extract_text( $dest, $extension );
			if ( $text === '' ) {
				@unlink( $dest );
				wp_send_json_error( array( 'message' => 'Kunne ikke læse tekst fra filen. Prøv en almindelig tekstfil (.txt, .md) eller en PDF/DOCX med valgbar tekst.' ) );
			}
		}

		$record = array(
			'id'         => $doc_id,
			'name'       => $original_name,
			'stored'     => $stored,
			'extension'  => $extension,
			'size'       => (int) $file['size'],
			'uploaded'   => current_time( 'mysql' ),
			'char_count' => mb_strlen( $text ),
			'chunks'     => self::chunk_text( $text ),
		);

		$docs   = self::get_all();
		$docs[] = $record;
		self::save_all( $docs );

		wp_send_json_success( array(
			'message' => 'Dokumentation uploadet.',
			'doc'     => self::format_doc_for_admin( $record ),
			'docs'    => array_map( array( __CLASS__, 'format_doc_for_admin' ), self::get_all() ),
		) );
	}

	/**
	 * Handle admin AJAX deletion of a documentation file.
	 */
	public function ajax_delete() {
		check_ajax_referer( 'aiab_documentation', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse til at slette dokumentation.' ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( $id === '' ) {
			wp_send_json_error( array( 'message' => 'Manglende dokument-id.' ) );
		}

		$docs    = self::get_all();
		$removed = false;
		foreach ( $docs as $index => $doc ) {
			if ( isset( $doc['id'] ) && $doc['id'] === $id ) {
				if ( ! empty( $doc['stored'] ) ) {
					$path = self::get_upload_dir() . '/' . $doc['stored'];
					if ( file_exists( $path ) ) {
						@unlink( $path );
					}
				}
				unset( $docs[ $index ] );
				$removed = true;
				break;
			}
		}

		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => 'Dokumentet blev ikke fundet.' ) );
		}

		self::save_all( $docs );
		wp_send_json_success( array(
			'message' => 'Dokumentation slettet.',
			'docs'    => array_map( array( __CLASS__, 'format_doc_for_admin' ), self::get_all() ),
		) );
	}

	/**
	 * Strip sensitive chunk data for the admin list table.
	 */
	public static function format_doc_for_admin( $doc ) {
		return array(
			'id'         => isset( $doc['id'] ) ? $doc['id'] : '',
			'name'       => isset( $doc['name'] ) ? $doc['name'] : '',
			'extension'  => isset( $doc['extension'] ) ? $doc['extension'] : '',
			'size'       => isset( $doc['size'] ) ? (int) $doc['size'] : 0,
			'uploaded'   => isset( $doc['uploaded'] ) ? $doc['uploaded'] : '',
			'char_count' => isset( $doc['char_count'] ) ? (int) $doc['char_count'] : 0,
			'chunk_count'=> isset( $doc['chunks'] ) && is_array( $doc['chunks'] ) ? count( $doc['chunks'] ) : 0,
		);
	}

	/**
	 * Extract text from a PDF using every available method.
	 *
	 * @return array{text:string,message:string}
	 */
	public static function extract_pdf_complete( $path, $settings ) {
		$attempts = array();

		// 1) AI extraction first — most reliable for complex/scanned PDFs.
		if ( ! empty( $settings['api_key'] ) ) {
			$ai = AIAB_AI::extract_document_text( $path, 'application/pdf', $settings );
			if ( ! empty( $ai['text'] ) ) {
				return array(
					'text'    => self::normalize_text( $ai['text'] ),
					'message' => '',
				);
			}
			if ( ! empty( $ai['error'] ) ) {
				$attempts[] = $ai['error'];
			}
		} else {
			$attempts[] = 'Ingen API-nøgle gemt — AI-udtrækning blev sprunget over.';
		}

		// 2) Local parsers.
		$local = self::extract_pdf_text( $path );
		if ( $local !== '' ) {
			return array( 'text' => $local, 'message' => '' );
		}
		$attempts[] = 'Lokal PDF-parsing fandt ingen tekst.';

		$message = 'Kunne ikke udtrække tekst fra PDF-filen.';
		if ( ! empty( $settings['api_key'] ) ) {
			$message .= ' Detaljer: ' . implode( ' | ', $attempts );
		} else {
			$message .= ' Gem din API-nøgle under indstillinger (kræves til PDF-upload), eller upload en .txt/.docx-fil i stedet.';
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AIAB PDF upload] failed for ' . basename( $path ) . ' — ' . $message );
		}

		return array( 'text' => '', 'message' => $message );
	}

	/**
	 * Extract plain text from a stored file.
	 */
	public static function extract_text( $path, $extension ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		switch ( $extension ) {
			case 'txt':
			case 'md':
			case 'markdown':
			case 'csv':
			case 'json':
			case 'xml':
				$raw = file_get_contents( $path );
				return self::normalize_text( $raw );

			case 'html':
			case 'htm':
				$raw = file_get_contents( $path );
				return self::normalize_text( wp_strip_all_tags( $raw ) );

			case 'rtf':
				$raw = file_get_contents( $path );
				$raw = preg_replace( '/\{\\\\\*\\\\[^{}]+}|[{}]|\\\\[a-z]+-?\d* ?/i', ' ', $raw );
				return self::normalize_text( $raw );

			case 'docx':
				return self::extract_docx_text( $path );

			case 'pdf':
				return self::extract_pdf_text( $path );

			default:
				return '';
		}
	}

	/**
	 * Read text from a DOCX file (ZIP archive with word/document.xml).
	 */
	private static function extract_docx_text( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}
		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return '';
		}
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		if ( $xml === false ) {
			return '';
		}
		$xml = preg_replace( '/<w:tab[^>]*\/>/', "\t", $xml );
		$xml = preg_replace( '/<\/w:p>/', "\n", $xml );
		$text = wp_strip_all_tags( $xml );
		return self::normalize_text( $text );
	}

	/**
	 * Best-effort plain-text extraction from PDF files.
	 *
	 * Tries, in order: pdftotext (if installed), literal strings in the raw
	 * file, and text inside FlateDecode-compressed content streams (the
	 * format used by most modern PDF generators).
	 */
	private static function extract_pdf_text( $path ) {
		$text = self::extract_pdf_with_pdftotext( $path );
		if ( $text !== '' ) {
			return self::normalize_text( $text );
		}

		$text = self::extract_pdf_with_pdfparser( $path );
		if ( $text !== '' ) {
			return self::normalize_text( $text );
		}

		$raw = file_get_contents( $path );
		if ( $raw === false || $raw === '' ) {
			return '';
		}

		$parts = self::parse_pdf_text_from_content( $raw );

		if ( preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $raw, $stream_matches ) ) {
			foreach ( $stream_matches[1] as $stream_data ) {
				$decompressed = self::decompress_pdf_stream( $stream_data );
				if ( $decompressed !== '' ) {
					$parts = array_merge( $parts, self::parse_pdf_text_from_content( $decompressed ) );
				}
			}
		}

		return self::finalize_pdf_parts( $parts );
	}
	/**
	 * @return string
	 */
	private static function finalize_pdf_parts( $parts ) {
		$filtered = self::filter_pdf_text_parts( $parts );
		if ( $filtered !== '' ) {
			return self::normalize_text( $filtered );
		}
		if ( ! empty( $parts ) ) {
			return self::normalize_text( implode( ' ', $parts ) );
		}
		return '';
	}

	/**
	 * Use the bundled smalot/pdfparser library for robust PDF text extraction.
	 */
	private static function extract_pdf_with_pdfparser( $path ) {
		if ( ! class_exists( '\Smalot\PdfParser\Parser' ) ) {
			return '';
		}

		try {
			$parser = new \Smalot\PdfParser\Parser();
			$pdf    = $parser->parseFile( $path );
			$text   = $pdf->getText();
			return is_string( $text ) ? trim( $text ) : '';
		} catch ( Exception $e ) {
			return '';
		}
	}

	/**
	 * Use the pdftotext binary when available (poppler-utils).
	 */
	private static function extract_pdf_with_pdftotext( $path ) {
		if ( ! function_exists( 'exec' ) || self::is_function_disabled( 'exec' ) ) {
			return '';
		}

		$binary = self::find_pdftotext_binary();
		if ( $binary === '' ) {
			return '';
		}

		$output = array();
		$code   = 0;
		$cmd    = escapeshellarg( $binary ) . ' -enc UTF-8 -layout -nopgbrk ' . escapeshellarg( $path ) . ' - 2>/dev/null';
		@exec( $cmd, $output, $code );
		if ( $code !== 0 || empty( $output ) ) {
			return '';
		}

		return trim( implode( "\n", $output ) );
	}

	/**
	 * Locate pdftotext on common Linux/macOS paths.
	 */
	private static function find_pdftotext_binary() {
		$candidates = array( '/usr/bin/pdftotext', '/usr/local/bin/pdftotext' );
		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		if ( ! self::is_function_disabled( 'exec' ) ) {
			$output = array();
			$code   = 0;
			@exec( 'which pdftotext 2>/dev/null', $output, $code );
			if ( $code === 0 && ! empty( $output[0] ) ) {
				$path = trim( $output[0] );
				if ( $path !== '' && is_executable( $path ) ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a PHP function is disabled via disable_functions.
	 */
	private static function is_function_disabled( $function ) {
		$disabled = ini_get( 'disable_functions' );
		if ( ! is_string( $disabled ) || $disabled === '' ) {
			return false;
		}
		return in_array( $function, array_map( 'trim', explode( ',', $disabled ) ), true );
	}

	/**
	 * Decompress a PDF FlateDecode stream using several zlib/deflate strategies.
	 */
	private static function decompress_pdf_stream( $data ) {
		$data = ltrim( (string) $data, "\x00\r\n " );
		if ( $data === '' ) {
			return '';
		}

		$attempts = array(
			function( $d ) { return @gzuncompress( $d ); },
			function( $d ) { return @gzinflate( $d ); },
			function( $d ) {
				if ( function_exists( 'zlib_decode' ) ) {
					return @zlib_decode( $d );
				}
				return false;
			},
			function( $d ) { return strlen( $d ) > 2 ? @gzinflate( substr( $d, 2 ) ) : false; },
			function( $d ) {
				$ztmp = @tmpfile();
				if ( ! $ztmp ) {
					return false;
				}
				fwrite( $ztmp, "\x1f\x8b\x08\x00\x00\x00\x00\x00" . $d );
				$uri = stream_get_meta_data( $ztmp )['uri'];
				$out = @file_get_contents( 'compress.zlib://' . $uri );
				fclose( $ztmp );
				return $out;
			},
		);

		foreach ( $attempts as $attempt ) {
			$result = $attempt( $data );
			if ( is_string( $result ) && $result !== '' ) {
				return $result;
			}
		}

		return '';
	}

	/**
	 * Pull literal and hex-encoded strings out of PDF page content.
	 */
	private static function parse_pdf_text_from_content( $content ) {
		$parts = array();

		if ( preg_match_all( '/\(((?:[^\\\\\(\)]|\\\\.)*)\)/s', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$decoded = self::decode_pdf_literal_string( $match );
				if ( $decoded !== '' ) {
					$parts[] = $decoded;
				}
			}
		}

		if ( preg_match_all( '/<([0-9A-Fa-f\s]+)>/', (string) $content, $hex_matches ) ) {
			foreach ( $hex_matches[1] as $hex ) {
				$decoded = self::decode_pdf_hex_string( $hex );
				if ( $decoded !== '' ) {
					$parts[] = $decoded;
				}
			}
		}

		return $parts;
	}

	/**
	 * Decode a PDF literal string with escape sequences.
	 */
	private static function decode_pdf_literal_string( $str ) {
		$out = '';
		$len = strlen( $str );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $str[ $i ];
			if ( $ch !== '\\' || $i + 1 >= $len ) {
				$out .= $ch;
				continue;
			}

			$next = $str[ $i + 1 ];
			switch ( $next ) {
				case 'n':
					$out .= "\n";
					$i++;
					break;
				case 'r':
					$out .= "\r";
					$i++;
					break;
				case 't':
					$out .= "\t";
					$i++;
					break;
				case 'b':
					$out .= "\b";
					$i++;
					break;
				case 'f':
					$out .= "\f";
					$i++;
					break;
				case '(':
				case ')':
				case '\\':
					$out .= $next;
					$i++;
					break;
				default:
					if ( $next >= '0' && $next <= '7' ) {
						$oct = $next;
						if ( $i + 2 < $len && $str[ $i + 2 ] >= '0' && $str[ $i + 2 ] <= '7' ) {
							$oct .= $str[ $i + 2 ];
							$i++;
							if ( $i + 2 < $len && $str[ $i + 2 ] >= '0' && $str[ $i + 2 ] <= '7' ) {
								$oct .= $str[ $i + 2 ];
								$i++;
							}
						}
						$out .= chr( octdec( $oct ) );
						$i++;
					} else {
						$out .= $next;
						$i++;
					}
			}
		}
		return $out;
	}

	/**
	 * Decode a PDF hex string, including UTF-16BE with BOM.
	 */
	private static function decode_pdf_hex_string( $hex ) {
		$hex = preg_replace( '/\s+/', '', (string) $hex );
		if ( $hex === '' ) {
			return '';
		}
		if ( strlen( $hex ) % 2 === 1 ) {
			$hex .= '0';
		}

		$binary = @hex2bin( $hex );
		if ( $binary === false || $binary === '' ) {
			return '';
		}

		if ( strlen( $binary ) >= 2 && substr( $binary, 0, 2 ) === "\xFE\xFF" ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$converted = @mb_convert_encoding( substr( $binary, 2 ), 'UTF-8', 'UTF-16BE' );
				if ( is_string( $converted ) && $converted !== '' ) {
					return $converted;
				}
			}
		}

		if ( function_exists( 'mb_check_encoding' ) && @mb_check_encoding( $binary, 'UTF-8' ) ) {
			return $binary;
		}

		// Heuristic: UTF-16BE without BOM (alternating null bytes).
		if ( preg_match( '/(\x00[\x20-\x7E]){3,}/', $binary ) && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $binary, 'UTF-8', 'UTF-16BE' );
			if ( is_string( $converted ) && $converted !== '' ) {
				return $converted;
			}
		}

		return preg_replace( '/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '', $binary );
	}

	/**
	 * Drop PDF-internal noise and keep human-readable fragments.
	 */
	private static function filter_pdf_text_parts( $parts ) {
		$meaningful = array();
		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' || mb_strlen( $part ) < 2 ) {
				continue;
			}
			if ( ! preg_match( '/[a-zA-ZæøåÆØÅ]{2,}/u', $part ) && ! preg_match( '/\d{2,}/', $part ) ) {
				continue;
			}
			if ( preg_match( '/^(Arial|Helvetica|Times|Calibri|Type\d|Font|WinAnsi|Identity|CID|Droid|F\d)$/i', $part ) ) {
				continue;
			}
			$meaningful[] = $part;
		}
		return implode( ' ', $meaningful );
	}

	/**
	 * Collapse whitespace and trim extracted text.
	 */
	private static function normalize_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Split long text into overlapping chunks for search.
	 */
	public static function chunk_text( $text ) {
		$text = self::normalize_text( $text );
		if ( $text === '' ) {
			return array();
		}

		$chunks = array();
		$len    = mb_strlen( $text );
		$start  = 0;
		while ( $start < $len ) {
			$chunk = mb_substr( $text, $start, self::CHUNK_SIZE );
			$chunk = trim( $chunk );
			if ( $chunk !== '' ) {
				$chunks[] = $chunk;
			}
			if ( $start + self::CHUNK_SIZE >= $len ) {
				break;
			}
			$start += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
		}
		return $chunks;
	}

	/**
	 * Search uploaded documentation for chunks relevant to a question.
	 *
	 * @return array Context items with type "documentation".
	 */
	public static function search( $question, $extra_terms = array(), $limit = 5 ) {
		$docs = self::get_all();
		if ( empty( $docs ) ) {
			return array();
		}

		$words = self::significant_words( $question );
		$terms = array_values( array_unique( array_merge( $words, (array) $extra_terms ) ) );
		if ( empty( $terms ) ) {
			return array();
		}

		$scores = array();
		foreach ( $docs as $doc ) {
			if ( empty( $doc['chunks'] ) || ! is_array( $doc['chunks'] ) ) {
				continue;
			}
			$doc_name = isset( $doc['name'] ) ? $doc['name'] : 'Documentation';
			foreach ( $doc['chunks'] as $index => $chunk ) {
				$chunk_lower = mb_strtolower( $chunk );
				$score       = 0;
				foreach ( $terms as $term ) {
					$term = mb_strtolower( trim( (string) $term ) );
					if ( $term === '' || mb_strlen( $term ) <= 2 ) {
						continue;
					}
					if ( mb_stripos( $chunk_lower, $term ) !== false ) {
						$score += mb_strlen( $term ) >= 5 ? 3 : 1;
					}
				}
				if ( $score > 0 ) {
					$key = $doc['id'] . ':' . $index;
					$scores[ $key ] = array(
						'score' => $score,
						'doc'   => $doc_name,
						'chunk' => $chunk,
					);
				}
			}
		}

		if ( empty( $scores ) ) {
			return array();
		}

		uasort( $scores, function( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		$results = array();
		foreach ( array_slice( $scores, 0, $limit, true ) as $item ) {
			$results[] = array(
				'type'    => 'documentation',
				'title'   => $item['doc'],
				'excerpt' => mb_substr( $item['chunk'], 0, 4000 ),
			);
		}
		return $results;
	}

	/**
	 * Whether any documentation has been uploaded.
	 */
	public static function has_documents() {
		return ! empty( self::get_all() );
	}

	/**
	 * Significant words from a question (mirrors AIAB_Search logic).
	 */
	private static function significant_words( $question ) {
		$stopwords = array(
			'hi', 'hey', 'hello', 'thanks', 'thank', 'you', 'your', 'please', 'how', 'are', 'what',
			'hej', 'hejsa', 'tak', 'hvordan', 'går', 'det', 'du', 'dig', 'hvad', 'med', 'har', 'man',
		);
		$words = preg_split( '/\s+/', (string) $question );
		$out   = array();
		foreach ( $words as $w ) {
			$clean = trim( $w, ".,!?;:¿¡'\"–-" );
			if ( $clean === '' || mb_strlen( $clean ) <= 2 ) {
				continue;
			}
			if ( in_array( mb_strtolower( $clean ), $stopwords, true ) ) {
				continue;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Build the fallback answer shown when no website or documentation content matches.
	 */
	public static function build_fallback_answer( $settings, $lang = '' ) {
		if ( ! empty( $settings['fallback_message'] ) ) {
			return trim( $settings['fallback_message'] );
		}

		$contact_bits = array();
		if ( ! empty( $settings['general_info'] ) ) {
			$contact_bits[] = trim( $settings['general_info'] );
		}

		$is_danish = ( $lang === 'da' );
		if ( ! $is_danish && $lang !== 'en' ) {
			$is_danish = preg_match( '/[æøåÆØÅ]/u', (string) $settings['general_info'] );
		}

		if ( $is_danish ) {
			$intro = 'Desværre har jeg ikke fundet oplysninger, der kan besvare dit spørgsmål på hjemmesiden.';
			$cta   = 'Kontakt os gerne direkte, så hjælper vi dig videre:';
		} else {
			$intro = 'Unfortunately, I could not find information on our website to answer your question.';
			$cta   = 'Please contact us directly and we will be happy to help:';
		}

		$message = $intro . ' ' . $cta;
		if ( ! empty( $contact_bits ) ) {
			$message .= "\n\n" . implode( "\n", $contact_bits );
		}
		return trim( $message );
	}

	/**
	 * Detect the visitor's language for a deterministic fallback response.
	 */
	public static function detect_lang( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );

		if ( preg_match( '/[æøåÆØÅ]/u', $q ) ) {
			return 'da';
		}

		$danish_signals = array(
			'hej', 'hejsa', 'davs', 'dav', 'godmorgen', 'godaften', 'godnat', 'farvel',
			'tak', 'hvordan', 'går', 'hvad', 'med', 'jeg', 'ikke', 'og', 'for', 'som',
			'til', 'kan', 'har', 'vores', 'pris', 'søg', 'mange', 'hilsen', 'jer', 'os',
			'maskiner', 'service', 'reservedele', 'finansiering', 'forhandler', 'sælger',
		);

		foreach ( $danish_signals as $signal ) {
			if ( preg_match( '/\b' . preg_quote( $signal, '/' ) . '\b/iu', $q ) ) {
				return 'da';
			}
		}

		return 'en';
	}

	/**
	 * Whether the question is about general site facts (hours, address, phone)
	 * that should be answered from general_info even when search finds nothing.
	 */
	public static function is_general_site_question( $question ) {
		if ( AIAB_Search::question_wants_general_contact( $question ) ) {
			return true;
		}

		$q = mb_strtolower( (string) $question );
		$patterns = array(
			'åbning', 'aabning', 'opening', 'hours', 'hour', 'adresse', 'address',
			'telefon', 'phone', 'email', 'e-mail',
			'hvor ligger', 'where are you', 'where is', 'lokation', 'location',
			'værksted', 'vaerksted', 'workshop', 'butik', 'shop', 'store',
			'give me address', 'give me the address', 'what is the address', 'what is your address',
			'store address', 'shop address', 'where is the store', 'where is your store',
			'general contact', 'company contact', 'firma kontakt',
		);
		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
