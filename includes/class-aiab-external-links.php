<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stores administrator-added external URLs and fetches relevant pages on demand
 * when visitors ask questions (no slow crawl during admin save).
 */
class AIAB_External_Links {

	const OPTION_KEY          = 'aiab_external_links';
	const MAX_BODY_SIZE       = 2097152;
	const MAX_PAGES_PER_QUERY = 8;
	const MAX_PAGES_BROAD     = 15;
	const PAGE_FETCH_TIMEOUT  = 15;
	const PAGE_CACHE_TTL      = 21600; // 6 hours.
	const SITEMAP_CACHE_TTL   = 86400; // 24 hours.

	public function __construct() {
		add_action( 'wp_ajax_aiab_add_external_link', array( $this, 'ajax_add' ) );
		add_action( 'wp_ajax_aiab_refresh_external_link', array( $this, 'ajax_refresh' ) );
		add_action( 'wp_ajax_aiab_delete_external_link', array( $this, 'ajax_delete' ) );
	}

	public static function get_all() {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}

	private static function save_all( $links ) {
		update_option( self::OPTION_KEY, array_values( $links ), false );
	}

	public static function get_by_id( $id ) {
		foreach ( self::get_all() as $link ) {
			if ( isset( $link['id'] ) && $link['id'] === $id ) {
				return $link;
			}
		}
		return null;
	}

	public static function has_links() {
		return ! empty( self::get_all() );
	}

	public static function format_link_for_admin( $link ) {
		$status = isset( $link['status'] ) ? $link['status'] : 'saved';
		return array(
			'id'          => isset( $link['id'] ) ? $link['id'] : '',
			'title'       => isset( $link['title'] ) ? $link['title'] : '',
			'url'         => isset( $link['url'] ) ? $link['url'] : '',
			'char_count'  => isset( $link['char_count'] ) ? (int) $link['char_count'] : 0,
			'chunk_count' => 0,
			'page_count'  => 0,
			'fetched'     => isset( $link['fetched'] ) ? $link['fetched'] : '',
			'status'      => $status,
			'status_label'=> $status === 'saved' ? 'Gemt' : 'Indlæst',
		);
	}

	public function ajax_add() {
		check_ajax_referer( 'aiab_external_links', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse til at tilføje links.' ) );
		}

		$url   = isset( $_POST['url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['url'] ) ) ) : '';
		$label = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';

		if ( $url === '' ) {
			wp_send_json_error( array( 'message' => 'Indtast en gyldig URL.' ) );
		}

		$validated = self::validate_url( $url );
		if ( is_wp_error( $validated ) ) {
			wp_send_json_error( array( 'message' => $validated->get_error_message() ) );
		}
		$url = $validated;

		foreach ( self::get_all() as $existing ) {
			if ( isset( $existing['url'] ) && untrailingslashit( $existing['url'] ) === untrailingslashit( $url ) ) {
				wp_send_json_error( array( 'message' => 'Dette link er allerede tilføjet.' ) );
			}
		}

		$record = array(
			'id'      => 'link_' . wp_generate_password( 12, false, false ),
			'url'     => $url,
			'title'   => $label !== '' ? $label : self::label_from_url( $url ),
			'fetched' => current_time( 'mysql' ),
			'status'  => 'saved',
		);

		$links   = self::get_all();
		$links[] = $record;
		self::save_all( $links );

		wp_send_json_success( array(
			'message' => 'Eksternt link gemt. Indhold hentes automatisk, når en besøgende stiller et spørgsmål.',
			'link'    => self::format_link_for_admin( $record ),
			'links'   => array_map( array( __CLASS__, 'format_link_for_admin' ), self::get_all() ),
		) );
	}

	public function ajax_refresh() {
		check_ajax_referer( 'aiab_external_links', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse.' ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( $id === '' ) {
			wp_send_json_error( array( 'message' => 'Manglende link-id.' ) );
		}

		$link = self::get_by_id( $id );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => 'Linket blev ikke fundet.' ) );
		}

		self::clear_link_cache( $id, isset( $link['url'] ) ? $link['url'] : '' );

		$links = self::get_all();
		foreach ( $links as $index => $item ) {
			if ( isset( $item['id'] ) && $item['id'] === $id ) {
				$links[ $index ]['fetched'] = current_time( 'mysql' );
				break;
			}
		}
		self::save_all( $links );

		wp_send_json_success( array(
			'message' => 'Cache ryddet. Linket hentes igen ved næste besøgendes spørgsmål.',
			'links'   => array_map( array( __CLASS__, 'format_link_for_admin' ), self::get_all() ),
		) );
	}

	public function ajax_delete() {
		check_ajax_referer( 'aiab_external_links', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse.' ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( $id === '' ) {
			wp_send_json_error( array( 'message' => 'Manglende link-id.' ) );
		}

		$links   = self::get_all();
		$removed = false;
		foreach ( $links as $index => $link ) {
			if ( isset( $link['id'] ) && $link['id'] === $id ) {
				self::clear_link_cache( $id, isset( $link['url'] ) ? $link['url'] : '' );
				unset( $links[ $index ] );
				$removed = true;
				break;
			}
		}

		if ( ! $removed ) {
			wp_send_json_error( array( 'message' => 'Linket blev ikke fundet.' ) );
		}

		self::save_all( $links );
		wp_send_json_success( array(
			'message' => 'Eksternt link slettet.',
			'links'   => array_map( array( __CLASS__, 'format_link_for_admin' ), self::get_all() ),
		) );
	}

	/**
	 * Search external links for pages relevant to a visitor question.
	 * Fetches and caches pages on demand — nothing is crawled at save time.
	 *
	 * @return array Context items with type "external_link".
	 */
	public static function search( $question, $extra_terms = array(), $limit = 5 ) {
		$links = self::get_all();
		if ( empty( $links ) ) {
			return array();
		}

		$terms = self::search_terms( $question, $extra_terms );
		if ( empty( $terms ) ) {
			return array();
		}

		@set_time_limit( 120 );

		$is_broad = self::is_broad_catalog_question( $question );
		$max_per_link = $is_broad ? self::MAX_PAGES_BROAD : self::MAX_PAGES_PER_QUERY;

		$results = array();
		foreach ( $links as $link ) {
			if ( empty( $link['url'] ) ) {
				continue;
			}
			$link_id = isset( $link['id'] ) ? $link['id'] : md5( $link['url'] );
			$pages   = self::fetch_relevant_pages( $link['url'], $link_id, $terms, $max_per_link, $is_broad );
			foreach ( $pages as $page ) {
				$results[] = array(
					'type'    => 'external_link',
					'title'   => $page['title'],
					'url'     => $page['url'],
					'image'   => isset( $page['image'] ) ? $page['image'] : '',
					'excerpt' => mb_substr( $page['text'], 0, 8000 ),
					'score'   => isset( $page['score'] ) ? (int) $page['score'] : 0,
				);
			}
		}

		if ( empty( $results ) ) {
			return array();
		}

		usort( $results, function( $a, $b ) {
			return ( isset( $b['score'] ) ? $b['score'] : 0 ) <=> ( isset( $a['score'] ) ? $a['score'] : 0 );
		} );

		$seen = array();
		$out  = array();
		foreach ( $results as $item ) {
			$key = isset( $item['url'] ) ? $item['url'] : '';
			if ( $key === '' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			unset( $item['score'] );
			$out[] = $item;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Whether the visitor's question is about a brand/site saved as an external link
	 * (e.g. question mentions "Husqvarna" and husqvarna.com is saved).
	 */
	public static function question_relates_to_links( $question, $extra_terms = array() ) {
		$links = self::get_all();
		if ( empty( $links ) ) {
			return false;
		}

		$terms = self::search_terms( $question, $extra_terms );
		foreach ( $links as $link ) {
			$url   = isset( $link['url'] ) ? $link['url'] : '';
			$title = isset( $link['title'] ) ? $link['title'] : '';
			$host  = wp_parse_url( $url, PHP_URL_HOST );
			$host  = is_string( $host ) ? strtolower( $host ) : '';
			$label = strtolower( $title . ' ' . $host . ' ' . $url );

			foreach ( $terms as $term ) {
				$term = mb_strtolower( trim( (string) $term ) );
				if ( mb_strlen( $term ) < 4 ) {
					continue;
				}
				if ( mb_stripos( $label, $term ) !== false ) {
					return true;
				}
			}

			$host_label = preg_replace( '/^www\./', '', $host );
			$host_name  = preg_replace( '/\.(com|dk|net|org|eu|io|co|uk).*/', '', $host_label );
			if ( mb_strlen( $host_name ) >= 4 ) {
				foreach ( $terms as $term ) {
					$term = mb_strtolower( trim( (string) $term ) );
					if ( mb_strlen( $term ) < 4 ) {
						continue;
					}
					if ( mb_stripos( $term, $host_name ) !== false || mb_stripos( $host_name, $term ) !== false ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Pick and fetch the most relevant pages for a root URL and search terms.
	 *
	 * @return array<int,array{url:string,title:string,text:string,image:string,score:int}>
	 */
	private static function fetch_relevant_pages( $root_url, $link_id, $terms, $max_pages, $is_broad = false ) {
		$parts = wp_parse_url( $root_url );
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( $host === '' ) {
			return array();
		}

		$candidates = array();
		$root_norm  = self::normalize_page_url( $root_url );
		$candidates[ $root_norm ] = self::score_url_for_terms( $root_norm, $terms ) + 2;

		foreach ( self::get_cached_sitemap_urls( $root_url, $link_id, $host ) as $url ) {
			$score = self::score_url_for_terms( $url, $terms );
			if ( $score > 0 ) {
				$candidates[ $url ] = isset( $candidates[ $url ] ) ? max( $candidates[ $url ], $score ) : $score;
			}
		}

		$homepage = self::get_cached_page( $root_norm, $host );
		if ( ! is_wp_error( $homepage ) && ! empty( $homepage['links'] ) ) {
			foreach ( $homepage['links'] as $child_url ) {
				$score = self::score_url_for_terms( $child_url, $terms );
				if ( $score > 0 ) {
					$candidates[ $child_url ] = isset( $candidates[ $child_url ] ) ? max( $candidates[ $child_url ], $score ) : $score;
				}
			}
		}

		if ( count( $candidates ) <= 1 || $is_broad ) {
			$sitemap_urls = self::get_cached_sitemap_urls( $root_url, $link_id, $host );
			$limit        = $is_broad ? 80 : 30;
			foreach ( $sitemap_urls as $url ) {
				if ( ! isset( $candidates[ $url ] ) ) {
					$score = self::score_url_for_terms( $url, $terms );
					$candidates[ $url ] = $score > 0 ? $score : 1;
				}
				if ( count( $candidates ) >= $limit ) {
					break;
				}
			}
		}

		arsort( $candidates );
		$to_fetch = array_slice( array_keys( $candidates ), 0, max( $max_pages, 12 ) );

		$pages = array();
		foreach ( $to_fetch as $url ) {
			$page = self::get_cached_page( $url, $host );
			if ( is_wp_error( $page ) || empty( $page['text'] ) ) {
				continue;
			}
			$content_score = self::score_text_for_terms( $page['text'], $page['title'], $terms );
			$url_score     = isset( $candidates[ $url ] ) ? (int) $candidates[ $url ] : 0;
			$total         = $content_score + $url_score;
			if ( $total < 1 && $url !== $root_norm ) {
				continue;
			}
			$pages[] = array(
				'url'   => $page['url'],
				'title' => $page['title'],
				'text'  => $page['text'],
				'image' => isset( $page['image'] ) ? $page['image'] : '',
				'score' => $total,
			);
			if ( count( $pages ) >= $max_pages ) {
				break;
			}
		}

		return $pages;
	}

	private static function get_cached_sitemap_urls( $root_url, $link_id, $host ) {
		$key    = 'aiab_el_sitemap_' . md5( $link_id . '|' . $root_url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$urls = self::discover_sitemap_urls( $root_url, $host );
		set_transient( $key, $urls, self::SITEMAP_CACHE_TTL );
		return $urls;
	}

	/**
	 * @return array|WP_Error
	 */
	private static function get_cached_page( $url, $host ) {
		$key    = 'aiab_el_page_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && ! empty( $cached['text'] ) ) {
			return $cached;
		}

		$page = self::fetch_page( $url, $host );
		if ( ! is_wp_error( $page ) && ! empty( $page['text'] ) ) {
			set_transient( $key, $page, self::PAGE_CACHE_TTL );
		}
		return $page;
	}

	private static function clear_link_cache( $link_id, $root_url ) {
		delete_transient( 'aiab_el_sitemap_' . md5( $link_id . '|' . $root_url ) );
	}

	private static function score_url_for_terms( $url, $terms ) {
		$url_lower = mb_strtolower( (string) $url );
		$slug      = preg_replace( '/[^a-z0-9æøå]+/iu', '', $url_lower );
		$score     = 0;
		foreach ( $terms as $term ) {
			$term = mb_strtolower( trim( (string) $term ) );
			if ( $term === '' || mb_strlen( $term ) <= 2 ) {
				continue;
			}
			$compact = preg_replace( '/[^a-z0-9æøå]+/iu', '', $term );
			if ( mb_stripos( $url_lower, $term ) !== false ) {
				$score += 5;
			} elseif ( $compact !== '' && mb_stripos( $slug, $compact ) !== false ) {
				$score += 6;
			}
		}
		return $score;
	}

	private static function score_text_for_terms( $text, $title, $terms ) {
		$text_lower  = mb_strtolower( (string) $text );
		$title_lower = mb_strtolower( (string) $title );
		$score       = 0;
		foreach ( $terms as $term ) {
			$term = mb_strtolower( trim( (string) $term ) );
			if ( $term === '' || mb_strlen( $term ) <= 2 ) {
				continue;
			}
			if ( mb_stripos( $text_lower, $term ) !== false ) {
				$score += mb_strlen( $term ) >= 5 ? 3 : 1;
			}
			if ( $title_lower !== '' && mb_stripos( $title_lower, $term ) !== false ) {
				$score += 2;
			}
		}
		return $score;
	}

	/**
	 * @return array{url:string,title:string,text:string,image:string,links:array}|WP_Error
	 */
	private static function fetch_page( $url, $host ) {
		$response = wp_remote_get( $url, array(
			'timeout'     => self::PAGE_FETCH_TIMEOUT,
			'redirection' => 5,
			'user-agent'  => 'AI-Assistant-Bot/1.0 WordPress; ' . home_url( '/' ),
			'headers'     => array(
				'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error( 'aiab_link_fetch', 'HTTP ' . $code );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BODY_SIZE ) {
			$body = substr( $body, 0, self::MAX_BODY_SIZE );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$text         = self::extract_text( $body, $content_type );
		if ( $text === '' ) {
			return new WP_Error( 'aiab_link_fetch', 'Empty page' );
		}

		return array(
			'url'   => $url,
			'title' => self::extract_page_title( $body ) ?: self::label_from_url( $url ),
			'text'  => $text,
			'image' => self::extract_page_image( $body, $url ),
			'links' => self::extract_internal_links( $body, $url, $host ),
		);
	}

	private static function discover_sitemap_urls( $start_url, $host ) {
		$parts = wp_parse_url( $start_url );
		$base  = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $host;
		$candidates = array(
			$base . '/sitemap.xml',
			$base . '/sitemap_index.xml',
			$base . '/wp-sitemap.xml',
		);

		$urls = array();
		foreach ( $candidates as $sitemap_url ) {
			$urls = array_merge( $urls, self::parse_sitemap_urls( $sitemap_url, $host, 0 ) );
		}

		return array_values( array_unique( $urls ) );
	}

	private static function parse_sitemap_urls( $sitemap_url, $host, $depth = 0 ) {
		if ( $depth > 2 ) {
			return array();
		}

		$response = wp_remote_get( $sitemap_url, array(
			'timeout'     => 12,
			'redirection' => 3,
			'user-agent'  => 'AI-Assistant-Bot/1.0 WordPress; ' . home_url( '/' ),
		) );
		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( ! preg_match_all( '#<loc>\s*(.*?)\s*</loc>#i', $body, $matches ) ) {
			return array();
		}

		$urls       = array();
		$child_maps = array();
		foreach ( $matches[1] as $loc ) {
			$loc = esc_url_raw( trim( html_entity_decode( $loc, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			if ( ! $loc || ! self::is_same_host( $loc, $host ) ) {
				continue;
			}
			if ( preg_match( '/sitemap[^\/]*\.xml$/i', $loc ) ) {
				$child_maps[] = $loc;
				continue;
			}
			if ( self::is_crawlable_url( $loc ) ) {
				$urls[] = self::normalize_page_url( $loc );
			}
		}

		foreach ( $child_maps as $child_url ) {
			$urls = array_merge( $urls, self::parse_sitemap_urls( $child_url, $host, $depth + 1 ) );
		}

		return $urls;
	}

	private static function extract_internal_links( $html, $page_url, $host ) {
		$links = array();
		if ( ! preg_match_all( '/\shref=["\']([^"\']+)["\']/i', (string) $html, $matches ) ) {
			return $links;
		}

		foreach ( $matches[1] as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $href === '' || strpos( $href, '#' ) === 0 || stripos( $href, 'mailto:' ) === 0 || stripos( $href, 'tel:' ) === 0 ) {
				continue;
			}
			$absolute = self::resolve_url( $page_url, $href );
			if ( $absolute && self::is_same_host( $absolute, $host ) && self::is_crawlable_url( $absolute ) ) {
				$links[] = self::normalize_page_url( $absolute );
			}
		}

		return array_values( array_unique( $links ) );
	}

	private static function resolve_url( $base_url, $href ) {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return esc_url_raw( $href );
		}
		if ( strpos( $href, '//' ) === 0 ) {
			$base_parts = wp_parse_url( $base_url );
			$scheme     = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
			return esc_url_raw( $scheme . ':' . $href );
		}
		if ( strpos( $href, '/' ) === 0 ) {
			$base_parts = wp_parse_url( $base_url );
			if ( empty( $base_parts['host'] ) ) {
				return '';
			}
			return esc_url_raw( $base_parts['scheme'] . '://' . $base_parts['host'] . $href );
		}
		$base = preg_replace( '#/[^/]*$#', '/', $base_url );
		return esc_url_raw( $base . ltrim( $href, '/' ) );
	}

	private static function is_same_host( $url, $host ) {
		$parts = wp_parse_url( $url );
		return isset( $parts['host'] ) && strtolower( $parts['host'] ) === strtolower( $host );
	}

	private static function is_crawlable_url( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$path = strtolower( (string) $path );
		$skip_ext = array( '.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.zip', '.css', '.js', '.xml', '.svg', '.ico', '.mp4', '.mp3' );
		foreach ( $skip_ext as $ext ) {
			if ( substr( $path, -strlen( $ext ) ) === $ext ) {
				return false;
			}
		}
		return true;
	}

	private static function normalize_page_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		$url = untrailingslashit( $url );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['path'] ) ) {
			return $url . '/';
		}
		return $url;
	}

	public static function validate_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( $url === '' || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'aiab_invalid_url', 'URL\'en er ugyldig. Brug http:// eller https://.' );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'aiab_invalid_url', 'Kun http- og https-links understøttes.' );
		}

		if ( ! empty( $parts['host'] ) && self::is_private_host( $parts['host'] ) ) {
			return new WP_Error( 'aiab_invalid_url', 'Lokale eller private netværksadresser er ikke tilladt.' );
		}

		return $url;
	}

	private static function is_private_host( $host ) {
		$host = strtolower( trim( $host ) );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return false;
	}

	private static function label_from_url( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : $url;
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		return $path !== '' ? $host . '/' . $path : $host;
	}

	private static function extract_page_title( $html ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', (string) $html, $matches ) ) {
			return trim( wp_strip_all_tags( html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		}
		return '';
	}

	private static function extract_page_image( $html, $page_url ) {
		$html = (string) $html;
		$candidates = array();

		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$candidates[] = $m[1];
		} elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/i', $html, $m ) ) {
			$candidates[] = $m[1];
		}
		if ( preg_match( '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$candidates[] = $m[1];
		}

		foreach ( $candidates as $src ) {
			$src = trim( html_entity_decode( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( $src === '' ) {
				continue;
			}
			if ( strpos( $src, '//' ) === 0 ) {
				$parts = wp_parse_url( $page_url );
				$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
				$src = $scheme . ':' . $src;
			} elseif ( strpos( $src, '/' ) === 0 ) {
				$parts = wp_parse_url( $page_url );
				if ( ! empty( $parts['host'] ) ) {
					$src = $parts['scheme'] . '://' . $parts['host'] . $src;
				}
			}
			$src = esc_url_raw( $src );
			if ( $src ) {
				return $src;
			}
		}

		return '';
	}

	private static function extract_text( $body, $content_type = '' ) {
		$content_type = strtolower( (string) $content_type );
		if ( $content_type !== '' && strpos( $content_type, 'text/html' ) === false && strpos( $content_type, 'text/plain' ) === false ) {
			return '';
		}

		if ( stripos( (string) $body, '<html' ) !== false || stripos( (string) $body, '<body' ) !== false ) {
			$body = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', ' ', (string) $body );
			$body = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', ' ', $body );
			$body = preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', ' ', $body );
			$body = wp_strip_all_tags( $body );
		}

		return self::normalize_text( $body );
	}

	private static function normalize_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	public static function is_broad_catalog_question( $question ) {
		$q = mb_strtolower( (string) $question );
		$patterns = array(
			'how many type', 'how many kind', 'what type', 'what kind', 'types of', 'kinds of',
			'categories', 'category', 'list of', 'all the', 'overview', 'range of',
			'hvor mange type', 'hvilke type', 'typer af', 'slags', 'kategorier',
			'plæneklipper', 'plaeneklipper', 'græsslåmaskine', 'graesslaamaskine', 'lawn mower',
		);
		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private static function search_terms( $question, $extra_terms = array() ) {
		$terms = self::significant_words( $question );
		foreach ( (array) $extra_terms as $term ) {
			$terms[] = $term;
		}
		$expanded = array();
		foreach ( $terms as $word ) {
			$expanded[] = $word;
			$parts = preg_split( '/[^a-z0-9æøå]+/iu', (string) $word );
			foreach ( (array) $parts as $part ) {
				$part = trim( $part );
				if ( mb_strlen( $part ) >= 3 ) {
					$expanded[] = $part;
				}
			}
			$compact = preg_replace( '/[^a-z0-9æøå]+/iu', '', (string) $word );
			if ( mb_strlen( $compact ) >= 3 ) {
				$expanded[] = $compact;
			}
		}
		return array_values( array_unique( array_filter( $expanded ) ) );
	}

	private static function significant_words( $question ) {
		$stopwords = array(
			'hi', 'hey', 'hello', 'thanks', 'thank', 'you', 'your', 'please', 'how', 'are', 'what', 'the', 'this', 'that',
			'hej', 'hejsa', 'tak', 'hvordan', 'går', 'det', 'du', 'dig', 'hvad', 'med', 'har', 'man', 'den', 'der',
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
}
