<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIAB_Rest {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'aiab/v1', '/ask', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_ask' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'question' => array(
					'required' => true,
					'type'     => 'string',
				),
				'history'  => array(
					'required' => false,
				),
			),
		) );

		// Feedback endpoint for answer ratings
		register_rest_route( 'aiab/v1', '/feedback', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_feedback' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'helpful'  => array(
					'required' => true,
					'type'     => 'boolean',
				),
				'question' => array(
					'required' => false,
					'type'     => 'string',
				),
				'answer'   => array(
					'required' => false,
					'type'     => 'string',
				),
			),
		) );
	}

	/**
	 * Handle feedback submission for answer ratings.
	 * Logs feedback for future model improvement analysis.
	 */
	public function handle_feedback( WP_REST_Request $request ) {
		$helpful  = (bool) $request->get_param( 'helpful' );
		$question = sanitize_text_field( (string) $request->get_param( 'question' ) );
		$answer   = sanitize_text_field( (string) $request->get_param( 'answer' ) );
		$ip       = self::get_ip();
		$timestamp = current_time( 'mysql' );

		// Log feedback to WordPress options (you could also log to a custom table or file)
		$feedback_log = get_option( 'aiab_feedback_log', array() );
		if ( ! is_array( $feedback_log ) ) {
			$feedback_log = array();
		}

		$feedback_log[] = array(
			'timestamp' => $timestamp,
			'ip'        => $ip,
			'helpful'   => $helpful,
			'question'  => mb_substr( $question, 0, 500 ),
			'answer'    => mb_substr( $answer, 0, 1000 ),
		);

		// Keep only last 1000 feedback entries to avoid unbounded growth
		$feedback_log = array_slice( $feedback_log, -1000 );
		update_option( 'aiab_feedback_log', $feedback_log );

		// Log in WordPress debug log if enabled
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AIAB feedback] helpful=' . ( $helpful ? 'true' : 'false' ) . ' ip=' . $ip );
		}

		return new WP_REST_Response( array( 'success' => true, 'message' => 'Feedback recorded.' ), 200 );
	}

	public function handle_ask( WP_REST_Request $request ) {
		$question = sanitize_text_field( $request->get_param( 'question' ) );
		$history  = self::sanitize_history( $request->get_param( 'history' ) );

		if ( empty( trim( $question ) ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Please enter a question.' ), 400 );
		}
		if ( mb_strlen( $question ) > 500 ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Question is too long.' ), 400 );
		}

		// Basic per-IP rate limiting: 20 requests / 5 minutes.
		$ip  = self::get_ip();
		$key = 'aiab_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Too many requests. Please wait a moment and try again.' ), 429 );
		}
		set_transient( $key, $count + 1, 5 * MINUTE_IN_SECONDS );

		$settings = AIAB_Settings::get_settings();

		// Only cache standalone questions (no conversation history yet) —
		// once a thread is going, the answer depends on prior turns too.
		$use_cache = $settings['cache_minutes'] > 0 && empty( $history );

		// The cache key includes a hash of every setting that can change
		// what gets searched/answered (post types, taxonomies, "search all"
		// toggle, results limit, general info, site instructions, provider/
		// model). Without this, changing a setting (e.g. removing "produkt"
		// from the post-types list) would NOT invalidate an already-cached
		// answer for the same question — the old cached answer, built under
		// the old settings, would keep being served until it naturally
		// expired. Hashing settings in means a settings change immediately
		// produces a different cache key, so the next request is always
		// answered fresh under the current configuration.
		$settings_fingerprint = md5( wp_json_encode( array(
			$settings['search_all_content'],
			$settings['post_types'],
			$settings['excluded_post_types'],
			$settings['taxonomies'],
			$settings['excluded_categories'],
			$settings['results_limit'],
			$settings['general_info'],
			$settings['site_context'],
			$settings['fallback_message'],
			$settings['provider'],
			$settings['model'],
			array_map( function( $doc ) {
				return array(
					isset( $doc['id'] ) ? $doc['id'] : '',
					isset( $doc['uploaded'] ) ? $doc['uploaded'] : '',
					isset( $doc['char_count'] ) ? $doc['char_count'] : 0,
				);
			}, AIAB_Documentation::get_all() ),
			array_map( function( $link ) {
				return array(
					isset( $link['id'] ) ? $link['id'] : '',
					isset( $link['url'] ) ? $link['url'] : '',
					isset( $link['fetched'] ) ? $link['fetched'] : '',
					isset( $link['char_count'] ) ? $link['char_count'] : 0,
					isset( $link['page_count'] ) ? $link['page_count'] : 0,
				);
			}, AIAB_External_Links::get_all() ),
		) ) );
		$cache_key = 'aiab_ans_' . md5( strtolower( $question ) . '_' . $settings_fingerprint );
		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( $cached !== false ) {
				return new WP_REST_Response( array( 'success' => true, 'data' => $cached, 'cached' => true ), 200 );
			}
		}

		// "Search all content": pull every public post type straight from
		// WordPress instead of relying on the admin having typed a complete,
		// up-to-date list into settings. This is what makes the search
		// general — any page, post, product, or custom post type on the
		// site is automatically in scope, including ones added later.
		if ( ! empty( $settings['search_all_content'] ) ) {
			$post_types = AIAB_Search::get_all_searchable_post_types();
		} else {
			$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : array();
		}

		// Excluded post types always win, regardless of whether "Søg i hele
		// databasen" is on (which would otherwise pull every public post
		// type in, including this one) or the admin's manual list happens
		// to include it. This is a hard block, applied last, so it can't
		// be bypassed by either of the two inclusion paths above.
		if ( ! empty( $settings['excluded_post_types'] ) ) {
			$post_types = array_values( array_diff( $post_types, $settings['excluded_post_types'] ) );
		}

		$taxonomies = ! empty( $settings['taxonomies'] ) ? $settings['taxonomies'] : array();
		$excluded_categories = ! empty( $settings['excluded_categories'] ) ? $settings['excluded_categories'] : array();

		// Casual messages ("hi", "how are you", "thanks") have no real search
		// intent — skip search entirely so the AI answers as small talk
		// instead of getting handed loosely-matched, unrelated content.
		$has_intent  = AIAB_Search::has_search_intent( $question );
		$is_general  = AIAB_Documentation::is_general_site_question( $question );

		// Greetings and small talk: one short reply, no search, no general info dump.
		if ( ! $has_intent ) {
			$reply  = AIAB_Search::build_casual_reply( $question, $history );
			$result = array(
				'lang'                    => $reply['lang'],
				'title'                   => '',
				'chip'                    => '',
				'answer'                  => $reply['answer'],
				'products'                => array(),
				'specifications'          => array(),
				'features'                => array(),
				'support'                 => array(),
				'category'                => null,
				'contact'                 => null,
				'sources'                 => array(),
				'confidence'              => 'high',
				'source_groups'           => array(),
				'follow_up_suggestions'   => array(),
				'needs_clarification'     => false,
				'clarification_questions' => array(),
			);

			if ( $use_cache ) {
				set_transient( $cache_key, $result, $settings['cache_minutes'] * MINUTE_IN_SECONDS );
			}

			return new WP_REST_Response( array( 'success' => true, 'data' => $result, 'cached' => false ), 200 );
		}

		// Understand the question before searching for it: ask the AI to
		// expand it into the underlying concepts a relevant page/category/
		// post would actually be built around (e.g. "mekanikere til
		// landbrugsmaskiner" → "værksted", "service", "reparation",
		// "landbrugsmaskiner"), and to classify whether the visitor's
		// underlying intent is to find a contact person. Every search step
		// below also tries the expanded terms, so a question can find
		// genuinely relevant content even when it shares no literal words
		// with it — real intent matching instead of plain keyword matching.
		// Fails open (empty terms, wants_contact=false) if the call errors,
		// so a hiccup here never blocks search.
		$expansion      = $has_intent ? AIAB_AI::expand_query( $question, $settings ) : array( 'terms' => array(), 'wants_contact' => false );
		$expanded_terms = ! empty( $expansion['terms'] ) ? $expansion['terms'] : array();
		$wants_general_contact  = AIAB_Search::question_wants_general_contact( $question );
		$wants_category_contact = ! $wants_general_contact && (
			! empty( $expansion['wants_contact'] ) || AIAB_Search::question_wants_category_contact( $question, $taxonomies )
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AIAB contact lookup] question="' . $question . '" general=' . ( $wants_general_contact ? 'true' : 'false' ) . ' category=' . ( $wants_category_contact ? 'true' : 'false' ) );
		}

		// Contact-person lookup: resolved deterministically (never
		// invented by the AI) against the matched category's ACF
		// "vaerkforer" repeater, then that category's direct children only.
		// This runs BEFORE the general post/category search below, and — per
		// the intended behaviour — a successful match replaces that general
		// search rather than running alongside it, since the visitor's
		// actual need (a person to contact) has already been answered.
		$contact = ( $has_intent && $wants_category_contact && ! empty( $taxonomies ) )
			? AIAB_Search::find_contact_person( $question, $taxonomies, $expanded_terms )
			: null;

		if ( $contact ) {
			$posts = array();
			$terms = array();
			$context_items = array();
		} else {
			$posts = ( $has_intent && ! $is_general && ! empty( $post_types ) ) ? AIAB_Search::search_posts( $question, $post_types, (int) $settings['results_limit'], $taxonomies, $expanded_terms, $excluded_categories ) : array();
			$terms = ( $has_intent && ! $is_general && ! empty( $taxonomies ) ) ? AIAB_Search::search_terms( $question, $taxonomies, 5, $expanded_terms, $excluded_categories ) : array();
			$context_items = array();
		}

		$documentation_items = array();
		if ( $has_intent && ! $is_general && ! $contact && AIAB_Documentation::has_documents() ) {
			$documentation_items = AIAB_Documentation::search( $question, $expanded_terms, 8 );
		}

		$external_link_items = array();
		if ( $has_intent && ! $is_general && ! $contact && AIAB_External_Links::has_links() ) {
			$has_website_content = ! empty( $posts ) || ! empty( $terms ) || ! empty( $documentation_items );
			$relates_external    = AIAB_External_Links::question_relates_to_links( $question, $expanded_terms );
			$is_broad_external   = AIAB_External_Links::is_broad_catalog_question( $question );

			// Fetch external pages when the question is about a saved link's brand/site,
			// is a broad catalog question, or the website found nothing — not only as fallback.
			if ( $relates_external || $is_broad_external || ! $has_website_content ) {
				@set_time_limit( 120 );
				$limit = ( $relates_external || $is_broad_external ) ? 8 : 4;
				$external_link_items = AIAB_External_Links::search( $question, $expanded_terms, $limit );
			}
		}

		if ( $contact ) {
			$context_items[] = self::tag_context_item( array(
				'type'     => 'contact_person',
				'category' => $contact['category'],
				'contacts' => $contact['contacts'],
			), 1, 'website' );
		}

		foreach ( $posts as $item ) {
			$context_items[] = self::tag_context_item( $item, 1, 'website' );
		}
		foreach ( $terms as $item ) {
			$context_items[] = self::tag_context_item( $item, 1, 'website' );
		}

		if ( ! $contact && ! empty( $settings['general_info'] ) && ( $is_general || $wants_general_contact ) ) {
			$context_items[] = self::tag_context_item( array(
				'type'    => 'general_info',
				'title'   => 'General site information',
				'excerpt' => $settings['general_info'],
			), 1, 'website' );
		}

		if ( ! $contact && ! empty( $settings['search_all_content'] ) && $is_general ) {
			foreach ( AIAB_Search::get_theme_template_content() as $item ) {
				$context_items[] = self::tag_context_item( $item, 1, 'website' );
			}
		}

		if ( ! $contact && $has_intent ) {
			foreach ( AIAB_Search::get_acf_options_content() as $item ) {
				$context_items[] = self::tag_context_item( $item, 1, 'website' );
			}
		}

		foreach ( $documentation_items as $item ) {
			$context_items[] = self::tag_context_item( $item, 2, 'documentation' );
		}

		foreach ( $external_link_items as $item ) {
			$context_items[] = self::tag_context_item( $item, 3, 'external_link' );
		}

		$has_searchable_matches = ! empty( $posts ) || ! empty( $terms ) || ! empty( $contact ) || ! empty( $documentation_items ) || ! empty( $external_link_items );

		// When the question has real search intent but nothing matched in the
		// website/database or uploaded documentation, return a deterministic
		// fallback with contact information instead of letting the AI guess.
		if ( $has_intent && ! $has_searchable_matches && ! $is_general ) {
			$lang = AIAB_Documentation::detect_lang( $question );
			$result = array(
				'lang'                    => $lang,
				'title'                   => $lang === 'da' ? 'Kontakt os' : 'Contact us',
				'chip'                    => $lang === 'da' ? 'Kontakt' : 'Contact',
				'answer'                  => AIAB_Documentation::build_fallback_answer( $settings, $lang ),
				'products'                => array(),
				'specifications'          => array(),
				'features'                => array(),
				'support'                 => array(),
				'category'                => null,
				'contact'                 => null,
				'sources'                 => array(),
				'confidence'              => 'low',
				'source_groups'           => array(),
				'follow_up_suggestions'   => array(),
				'needs_clarification'     => false,
				'clarification_questions' => array(),
			);

			if ( $use_cache ) {
				set_transient( $cache_key, $result, $settings['cache_minutes'] * MINUTE_IN_SECONDS );
			}

			return new WP_REST_Response( array( 'success' => true, 'data' => $result, 'cached' => false ), 200 );
		}

		$result = AIAB_AI::ask( $question, $context_items, $history, $settings );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 500 );
		}

		// Category match is resolved directly against real taxonomy terms,
		// not by the AI — this is what guarantees the category block only
		// ever appears when the question genuinely matches a real category,
		// and never gets invented/hallucinated.
		$result['category'] = $has_intent ? AIAB_Search::find_matched_category( $question, $taxonomies, $expanded_terms, $excluded_categories ) : null;

		if ( $contact || $is_general || $wants_general_contact ) {
			$result['category'] = null;
			$result['products'] = array();
		} elseif ( ! empty( $result['category'] ) ) {
			$result['products'] = array();
		} else {
			$result = self::finalize_products( $question, $result, $posts, $external_link_items );
		}

		$result['contact'] = $contact;
		$result['sources'] = array();

		if ( $use_cache ) {
			set_transient( $cache_key, $result, $settings['cache_minutes'] * MINUTE_IN_SECONDS );
		}

		return new WP_REST_Response( array( 'success' => true, 'data' => $result, 'cached' => false ), 200 );
	}

	/**
	 * Sanitize the client-supplied conversation history: cap the number of
	 * turns and the length of each message so a request can't be used to
	 * smuggle in an unbounded amount of text.
	 */
	private static function sanitize_history( $raw ) {
		$history = array();
		if ( ! is_array( $raw ) ) {
			return $history;
		}
		$raw = array_slice( $raw, -20 ); // last 20 messages = last 10 Q&A turns.
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['content'] ) ) {
				continue;
			}
			$role    = ( isset( $item['role'] ) && $item['role'] === 'assistant' ) ? 'assistant' : 'user';
			$content = sanitize_textarea_field( (string) $item['content'] );
			if ( $content === '' ) {
				continue;
			}
			if ( mb_strlen( $content ) > 4000 ) {
				$content = mb_substr( $content, 0, 4000 );
			}
			$history[] = array( 'role' => $role, 'content' => $content );
		}
		return $history;
	}

	/**
	 * Tag a context item with source priority for the AI prompt.
	 * 1 = this website, 2 = uploaded documentation, 3 = external links.
	 */
	private static function tag_context_item( $item, $priority, $group ) {
		if ( ! is_array( $item ) ) {
			return $item;
		}
		$item['source_priority'] = (int) $priority;
		$item['source_group']    = (string) $group;
		return $item;
	}

	/**
	 * Keep product cards when the visitor asked to browse, see offers, or when
	 * matched website/external pages clearly relate to the question.
	 */
	private static function finalize_products( $question, $result, $posts = array(), $external_link_items = array() ) {
		if ( ! AIAB_Search::question_wants_link_cards( $question ) ) {
			$result['products'] = array();
			return $result;
		}

		$products    = ( isset( $result['products'] ) && is_array( $result['products'] ) ) ? $result['products'] : array();
		$wants_cards = AIAB_Search::question_wants_product_cards( $question );
		$terms       = self::significant_question_terms( $question );

		if ( $wants_cards ) {
			$products = array_slice( $products, 0, 4 );
		} elseif ( ! empty( $terms ) ) {
			$filtered = array();
			foreach ( $products as $product ) {
				if ( empty( $product['permalink'] ) || empty( $product['title'] ) ) {
					continue;
				}
				$title = mb_strtolower( (string) $product['title'] );
				foreach ( $terms as $term ) {
					if ( mb_strlen( $term ) >= 4 && mb_stripos( $title, $term ) !== false ) {
						$filtered[] = $product;
						break;
					}
				}
				if ( count( $filtered ) >= 4 ) {
					break;
				}
			}
			$products = $filtered;
		} else {
			$products = array();
		}

		if ( $wants_cards || ! empty( $terms ) ) {
			$products = self::merge_context_product_cards( $products, $posts, $external_link_items, $terms );
		}

		$result['products'] = array_slice( $products, 0, 4 );
		return $result;
	}

	/**
	 * Add clickable cards from matched website posts and external pages.
	 */
	private static function merge_context_product_cards( $products, $posts, $external_link_items, $terms ) {
		$seen = array();
		foreach ( $products as $product ) {
			if ( ! empty( $product['permalink'] ) ) {
				$seen[ $product['permalink'] ] = true;
			}
		}

		$add = function( $title, $permalink, $image = '', $description = '' ) use ( &$products, &$seen ) {
			if ( count( $products ) >= 4 ) {
				return;
			}
			$title     = trim( (string) $title );
			$permalink = trim( (string) $permalink );
			if ( $title === '' || $permalink === '' || isset( $seen[ $permalink ] ) ) {
				return;
			}
			$seen[ $permalink ] = true;
			$products[]         = array(
				'title'       => $title,
				'permalink'   => $permalink,
				'image'       => $image,
				'description' => $description,
			);
		};

		$matches_terms = function( $haystack ) use ( $terms ) {
			$haystack = mb_strtolower( (string) $haystack );
			foreach ( $terms as $term ) {
				if ( mb_strlen( $term ) >= 4 && mb_stripos( $haystack, $term ) !== false ) {
					return true;
				}
			}
			return false;
		};

		foreach ( (array) $posts as $item ) {
			if ( empty( $item['permalink'] ) ) {
				continue;
			}
			$label = ( isset( $item['title'] ) ? $item['title'] : '' ) . ' ' . ( isset( $item['excerpt'] ) ? $item['excerpt'] : '' );
			if ( ! $matches_terms( $label ) ) {
				continue;
			}
			$desc = ! empty( $item['excerpt'] ) ? wp_trim_words( $item['excerpt'], 14, '…' ) : '';
			$add(
				isset( $item['title'] ) ? $item['title'] : '',
				$item['permalink'],
				isset( $item['image'] ) ? $item['image'] : '',
				$desc
			);
		}

		foreach ( (array) $external_link_items as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$label = ( isset( $item['title'] ) ? $item['title'] : '' ) . ' ' . ( isset( $item['excerpt'] ) ? $item['excerpt'] : '' );
			if ( ! $matches_terms( $label ) ) {
				continue;
			}
			$desc = ! empty( $item['excerpt'] ) ? wp_trim_words( $item['excerpt'], 14, '…' ) : '';
			$add(
				! empty( $item['title'] ) ? $item['title'] : self::label_from_url_rest( $item['url'] ),
				$item['url'],
				isset( $item['image'] ) ? $item['image'] : '',
				$desc
			);
		}

		return $products;
	}

	private static function significant_question_terms( $question ) {
		$stopwords = array(
			'what', 'which', 'where', 'when', 'does', 'have', 'your', 'this', 'that', 'with', 'from', 'about',
			'hvad', 'hvilke', 'hvor', 'har', 'jer', 'dig', 'den', 'det', 'for', 'som', 'til', 'kan', 'man',
			'sell', 'sælger', 'forhandler', 'dealer', 'carry', 'stock', 'available', 'please', 'give',
		);
		$words = preg_split( '/\s+/', mb_strtolower( (string) $question ) );
		$terms = array();
		foreach ( $words as $word ) {
			$clean = trim( $word, ".,!?;:¿¡'\"–-" );
			if ( $clean === '' || mb_strlen( $clean ) <= 2 ) {
				continue;
			}
			if ( in_array( $clean, $stopwords, true ) ) {
				continue;
			}
			$terms[] = $clean;
		}
		return array_values( array_unique( $terms ) );
	}

	private static function label_from_url_rest( $url ) {
		$parts = wp_parse_url( $url );
		$host  = isset( $parts['host'] ) ? $parts['host'] : $url;
		$path  = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		return $path !== '' ? $host . '/' . $path : $host;
	}

	private static function get_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			return trim( $parts[0] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}
}
