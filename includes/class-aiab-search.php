<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIAB_Search {

	/**
	 * Common small-talk / greeting words, Danish and English (this site
	 * serves both). Not an exhaustive dictionary — just the words that
	 * actually show up in "how are you" / "hvordan går det?" style chit-chat.
	 * Extend this list if you spot another greeting phrase slipping through.
	 */
	private static $conversational_stopwords = array(
		// English
		'hi', 'hey', 'hello', 'hiya', 'yo', 'morning', 'afternoon', 'evening',
		'good', 'thanks', 'thank', 'you', 'your', 'please', 'ok', 'okay',
		'fine', 'great', 'nice', 'cool', 'well', 'how', 'are', 'is', 'am',
		'what', 'whats', "what's", 'going', 'doing', 'today', 'day', 'there',
		'bye', 'goodbye', 'cheers', 'thx', 'sup', 'yes', 'no',
		// Danish
		'hej', 'hejsa', 'davs', 'dav', 'godmorgen', 'godaften', 'godnat',
		'tak', 'hvordan', 'går', 'det', 'du', 'dig', 'fint', 'godt', 'super',
		'farvel', 'ja', 'nej', 'hvad', 'laver', 'sker', 'med', 'har', 'man',
		'en', 'et',
	);

	/**
	 * Whether the question actually contains enough substance to search on.
	 * Greeting/small-talk messages ("how are you", "hvordan går det?",
	 * "thanks") are made up entirely of common filler words, and running
	 * them through native WP search tends to loosely match unrelated posts
	 * (those same common words show up all over ordinary site copy), which
	 * then gets handed to the AI as if it were relevant. Skipping search
	 * entirely for these keeps small talk small talk instead of triggering
	 * a random product/category answer.
	 */
	public static function has_search_intent( $question ) {
		$question = trim( (string) $question );
		if ( $question === '' ) {
			return false;
		}

		$words = array_values( array_filter( preg_split( '/\s+/', $question ), function( $w ) { return $w !== ''; } ) );

		// Single word: greetings and small-talk should not trigger search
		// ("hej", "hi", "thanks"). Product codes and model names still search.
		if ( count( $words ) <= 1 ) {
			$clean = mb_strtolower( trim( $words[0], ".,!?;:¿¡'\"–-" ) );
			if ( in_array( $clean, self::$conversational_stopwords, true ) ) {
				return false;
			}
			return true;
		}

		// Multi-word: strip punctuation and common Danish/English small-talk
		// words. If nothing meaningful is left, it's a greeting, not a
		// real search — e.g. "how are you" and "hvordan går det?" both
		// reduce to nothing.
		foreach ( $words as $word ) {
			$clean = mb_strtolower( trim( $word, ".,!?;:¿¡'\"–-" ) );
			if ( $clean !== '' && ! in_array( $clean, self::$conversational_stopwords, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a short, natural reply for greetings and small talk (no search).
	 *
	 * @param string $question Visitor message.
	 * @param array  $history  Prior conversation turns (role + content).
	 * @return array{lang: string, answer: string}
	 */
	public static function build_casual_reply( $question, $history = array() ) {
		$q = mb_strtolower( trim( (string) $question ) );
		$lang = self::detect_conversation_lang( $question, $history );

		if ( self::matches_casual_pattern( $q, array(
			'how are you', "how're you", 'how r u', 'how is it going', "how's it going",
			'how are things', 'how you doing', 'how ya doing', 'what\'s up', 'whats up', 'sup',
			'hvordan går det', 'hvordan har du det', 'hvordan står det til', 'hvordan har i det',
		) ) ) {
			return array(
				'lang'   => $lang,
				'answer' => $lang === 'da'
					? 'Tak for spørgsmålet — vi har det godt! Hvordan kan vi hjælpe dig med maskiner, service eller reservedele i dag?'
					: 'Thank you for asking — we\'re doing well! How can we help you with machines, service, or spare parts today?',
			);
		}

		if ( self::matches_casual_pattern( $q, array(
			'thank you', 'thanks', 'thx', 'cheers', 'much appreciated',
			'tak skal du have', 'mange tak', 'tusind tak', 'tak for',
		) ) || preg_match( '/^tak[!.?]*$/u', $q ) ) {
			return array(
				'lang'   => $lang,
				'answer' => $lang === 'da'
					? 'Selv tak! Sig endelig til, hvis vi kan hjælpe med mere.'
					: 'You\'re welcome! Let us know if you need anything else.',
			);
		}

		if ( self::matches_casual_pattern( $q, array(
			'goodbye', 'bye', 'see you', 'see ya', 'take care',
			'farvel', 'ha det', 'vi ses', 'på gensyn',
		) ) ) {
			return array(
				'lang'   => $lang,
				'answer' => $lang === 'da'
					? 'Farvel! Velkommen tilbage, hvis du har flere spørgsmål.'
					: 'Goodbye! Feel free to come back if you have more questions.',
			);
		}

		if ( self::matches_casual_pattern( $q, array( 'good morning', 'godmorgen' ) ) ) {
			return array(
				'lang'   => $lang,
				'answer' => $lang === 'da'
					? 'Godmorgen! Hvordan kan vi hjælpe dig i dag?'
					: 'Good morning! How can we help you today?',
			);
		}

		if ( self::matches_casual_pattern( $q, array( 'good afternoon', 'good evening', 'godaften' ) ) ) {
			return array(
				'lang'   => $lang,
				'answer' => $lang === 'da'
					? 'Godaften! Hvordan kan vi hjælpe dig i dag?'
					: 'Good evening! How can we help you today?',
			);
		}

		return array(
			'lang'   => $lang,
			'answer' => $lang === 'da'
				? 'Hej! Hvordan kan vi hjælpe dig i dag?'
				: 'Hello! How can we help you today?',
		);
	}

	/**
	 * Detect language from the current message, with history as fallback.
	 */
	public static function detect_conversation_lang( $question, $history = array() ) {
		$lang = AIAB_Documentation::detect_lang( $question );
		if ( $lang === 'da' ) {
			return 'da';
		}

		$q = mb_strtolower( trim( (string) $question ) );
		$english_only = array( 'hello', 'hi', 'hey', 'how', 'are', 'you', 'thanks', 'thank', 'bye', 'goodbye', 'morning', 'evening', 'afternoon' );
		$all_english  = true;
		$words        = preg_split( '/\s+/', $q );
		foreach ( $words as $word ) {
			$clean = trim( $word, ".,!?;:¿¡'\"–-" );
			if ( $clean === '' ) {
				continue;
			}
			if ( ! in_array( $clean, $english_only, true ) && ! in_array( $clean, self::$conversational_stopwords, true ) ) {
				$all_english = false;
				break;
			}
		}

		if ( ! $all_english ) {
			return $lang;
		}

		if ( is_array( $history ) ) {
			for ( $i = count( $history ) - 1; $i >= 0; $i-- ) {
				if ( empty( $history[ $i ]['role'] ) || $history[ $i ]['role'] !== 'user' || empty( $history[ $i ]['content'] ) ) {
					continue;
				}
				$hist_lang = AIAB_Documentation::detect_lang( $history[ $i ]['content'] );
				if ( $hist_lang === 'da' ) {
					return 'da';
				}
				break;
			}
		}

		return $lang;
	}

	/**
	 * Whether a normalized casual message matches any of the given phrases.
	 */
	private static function matches_casual_pattern( $normalized_question, $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( $normalized_question === $pattern || mb_stripos( $normalized_question, $pattern ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every public, front-end-visible post type currently registered on the
	 * site (pages, posts, products, and any custom post type a theme/plugin
	 * adds later) — minus attachments, which are media files, not content
	 * with an answer in them. Used when the admin wants the bot to search
	 * "the whole database" instead of a hand-picked list of post types, so
	 * new content types automatically get included without touching settings.
	 */
	public static function get_all_searchable_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}


	/**
	 * Site-wide text that lives OUTSIDE ordinary posts: the current block
	 * theme's template parts (header, footer, sidebar, etc. — e.g. opening
	 * hours or an address printed in the footer). These are not always rows
	 * in the database — an unedited template part exists only as a file
	 * inside the theme until someone customizes it in the Site Editor — so
	 * normal WP_Query search (and even a direct postmeta/post_content
	 * search) can miss them entirely. get_block_templates() is WordPress'
	 * own resolver: it merges theme files and any DB customization into one
	 * final, current version of each part, which is exactly what a visitor
	 * actually sees on the page.
	 *
	 * Returned as always-available context items (like general_info), not
	 * matched per-word, since there are only ever a handful of template
	 * parts per theme and each is short enough to just include outright.
	 */
	public static function get_theme_template_content() {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$parts = get_block_templates( array(), 'wp_template_part' );
		if ( empty( $parts ) ) {
			return array();
		}

		$items = array();
		foreach ( $parts as $part ) {
			$text = self::strip_block_markup( $part->content );
			if ( $text === '' ) {
				continue;
			}
			$items[] = array(
				'type'    => 'site_content',
				'title'   => ! empty( $part->title ) ? $part->title : $part->slug,
				'excerpt' => mb_substr( $text, 0, 1500 ),
			);
		}
		return $items;
	}

	/**
	 * Read public ACF options-page values as searchable site content.
	 * ACF stores options-page fields in wp_options with an "options_" prefix,
	 * so they are otherwise invisible to post and taxonomy searches.
	 */
	public static function get_acf_options_content() {
		global $wpdb;

		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'options_' ) . '%'
		) );
		if ( empty( $rows ) ) {
			return array();
		}

		$values = array();
		foreach ( $rows as $row ) {
			$value = maybe_unserialize( $row->option_value );
			$text  = self::flatten_custom_value( $value );
			if ( $text !== '' ) {
				$values[] = sanitize_text_field( str_replace( '_', ' ', preg_replace( '/^options_/', '', $row->option_name ) ) ) . ': ' . $text;
			}
		}

		if ( empty( $values ) ) {
			return array();
		}

		return array(
			array(
				'type'    => 'site_content',
				'title'   => 'Website information',
				'excerpt' => mb_substr( implode( "\n", $values ), 0, 12000 ),
			)
		);
	}


	/**
	 * Strip block comments (<!-- wp:paragraph --> etc.) and HTML tags from
	 * raw block markup, leaving just the plain visible text a visitor would
	 * actually read — e.g. turns "<!-- wp:paragraph --><p>Man - Fre : 07:30
	 * - 16:30</p><!-- /wp:paragraph -->" into "Man - Fre : 07:30 - 16:30".
	 */
	private static function strip_block_markup( $content ) {
		$content = preg_replace( '/<!--\s*\/?wp:.*?-->/s', ' ', (string) $content );
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/u', ' ', $content );
		return trim( $content );
	}


	/**
	 * Whether a taxonomy term's name is in the admin's "excluded categories"
	 * list (case-insensitive, whitespace-trimmed). Centralizing the compare
	 * here means every code path that surfaces a term — matched category,
	 * subcategory cards, contact-person lookup, and the tax_query fallback
	 * used by post search — respects the same exclusion list consistently.
	 */
	private static function is_excluded_term( $term, $excluded_categories ) {
		if ( empty( $excluded_categories ) || ! $term ) {
			return false;
		}
		$name = mb_strtolower( trim( $term->name ) );
		foreach ( (array) $excluded_categories as $excluded ) {
			if ( $name === mb_strtolower( trim( (string) $excluded ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Filter a list of WP_Term objects, dropping any whose name is in the
	 * admin's "excluded categories" list. Safe to call with a WP_Error or
	 * empty value — passes it through unchanged.
	 */
	private static function filter_excluded_terms( $terms, $excluded_categories ) {
		if ( empty( $excluded_categories ) || is_wp_error( $terms ) || empty( $terms ) ) {
			return $terms;
		}
		return array_values( array_filter( $terms, function( $t ) use ( $excluded_categories ) {
			return ! self::is_excluded_term( $t, $excluded_categories );
		} ) );
	}

	/**
	 * Search posts (products, blog, etc.) matching the user's question.
	 */
	public static function search_posts( $question, $post_types, $limit, $taxonomies = array(), $extra_terms = array(), $excluded_categories = array() ) {
		$results = array();

		$query = new WP_Query( array(
			's'              => $question,
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'relevance',
		) );

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$results[] = self::format_post( $post );
			}
		}
		wp_reset_postdata();

		if ( ! empty( $results ) ) {
			return $results;
		}

		// Fallback 1: native search found nothing (common when the question
		// uses a generic/category word, e.g. "traktor", that doesn't
		// literally appear on the actual product pages). Try resolving the
		// question to a real taxonomy term first, and pull posts actually
		// assigned to that category — this is far more reliable than
		// matching stray mentions of the word inside unrelated products'
		// body text (e.g. an implement's description that happens to say
		// "can be used with tractors").
		if ( ! empty( $taxonomies ) ) {
			$matched_terms = self::match_terms( $question, $taxonomies, $extra_terms, $excluded_categories );
			if ( ! empty( $matched_terms ) ) {
				$tax_query = array( 'relation' => 'OR' );
				foreach ( $matched_terms as $term ) {
					$tax_query[] = array(
						'taxonomy' => $term->taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					);
				}
				$query = new WP_Query( array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'tax_query'      => $tax_query,
				) );
				foreach ( $query->posts as $post ) {
					$results[] = self::format_post( $post );
				}
				wp_reset_postdata();
			}
		}

		if ( ! empty( $results ) ) {
			return $results;
		}

		// Fallback 2: last resort — loose keyword match against
		// titles/content/excerpt. Kept relevance-ordered (this was missing
		// before, which let a merely-mentions-the-word post from an
		// arbitrary post_date beat the actually best textual match).
		$words = self::significant_words( $question );
		if ( ! empty( $words ) ) {
			$query = new WP_Query( array(
				's'              => implode( ' ', array_slice( $words, 0, 5 ) ),
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'relevance',
			) );
			foreach ( $query->posts as $post ) {
				$results[] = self::format_post( $post );
			}
			wp_reset_postdata();
		}

		if ( ! empty( $results ) ) {
			return $results;
		}

		// Fallback 3: WordPress' native 's' search requires EVERY term to
		// match (AND logic), which natural-language questions almost never
		// satisfy — a real sentence like "hvad tid har i åbent i
		// værkstedet?" only has 1-2 words that actually appear verbatim on
		// the target page, so fallback 2 (still AND'd) comes back empty.
		// Search each significant word separately (OR logic) instead and
		// rank posts by how many distinct words they matched, so a page
		// that hits on "værksted"/"åbent" still surfaces even though it
		// doesn't literally contain "tid" or "hvad". Also folds in the
		// AI-expanded concept terms (e.g. "mekaniker" → "værksted",
		// "service", "reparation") so a question can match a page even when
		// it shares literally none of its own words with it — this is what
		// gives the search real intent understanding rather than requiring
		// an exact keyword hit.
		$search_words = array_slice( array_values( array_unique( array_merge( $words, (array) $extra_terms ) ) ), 0, 12 );
		if ( ! empty( $search_words ) ) {
			$scores = array();
			foreach ( $search_words as $word ) {
				$word_query = new WP_Query( array(
					's'              => $word,
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
				) );
				foreach ( $word_query->posts as $post_id ) {
					$scores[ $post_id ] = isset( $scores[ $post_id ] ) ? $scores[ $post_id ] + 1 : 1;
				}
				wp_reset_postdata();
			}
			if ( ! empty( $scores ) ) {
				arsort( $scores );
				$ids = array_slice( array_keys( $scores ), 0, $limit );
				foreach ( $ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$results[] = self::format_post( $post );
					}
				}
			}
		}

		if ( ! empty( $results ) ) {
			return $results;
		}

		// Fallback 4: nothing in the post's own title/content/excerpt matched
		// at all. A lot of real answers actually live in custom fields
		// instead — ACF specs, price tables, Q&A-style fields, imported
		// product data, etc. WordPress' native 's' search never looks at
		// postmeta, so without this, any question whose answer only exists
		// in a custom field would come back completely empty. Search
		// meta_value directly instead (question words + expanded concept
		// terms), scored the same way as fallback 3.
		if ( ! empty( $search_words ) ) {
			$meta_results = self::search_meta( $search_words, $post_types, $limit );
			foreach ( $meta_results as $post ) {
				$results[] = self::format_post( $post );
			}
		}

		return $results;
	}


	/**
	 * Full-database fallback: match the question's significant words against
	 * every custom field (postmeta) value on posts of the given types, not
	 * just their title/content/excerpt. This is what lets the bot answer
	 * from data that only lives in a custom field (ACF or otherwise) —
	 * specs, Q&A pairs, imported product attributes — rather than requiring
	 * the answer to be typed into the visible post content.
	 */
	private static function search_meta( $words, $post_types, $limit ) {
		global $wpdb;

		if ( empty( $words ) || empty( $post_types ) ) {
			return array();
		}

		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$scores = array();

		foreach ( array_slice( $words, 0, 6 ) as $word ) {
			// Skip fields WordPress uses internally (leading underscore,
			// e.g. _thumbnail_id, _edit_lock) — those never hold visitor-
			// facing content and would just add noise/false matches.
			$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_status = 'publish'
				AND p.post_type IN ($post_type_placeholders)
				AND pm.meta_key NOT LIKE %s
				AND (pm.meta_value LIKE %s OR pm.meta_key LIKE %s)
				LIMIT 200";
				$needle = '%' . $wpdb->esc_like( $word ) . '%';
				$args = array_merge( $post_types, array( '\_%', $needle, $needle ) );
			$ids  = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

			foreach ( $ids as $post_id ) {
				$scores[ $post_id ] = isset( $scores[ $post_id ] ) ? $scores[ $post_id ] + 1 : 1;
			}
		}

		if ( empty( $scores ) ) {
			return array();
		}

		arsort( $scores );
		$ids = array_slice( array_keys( $scores ), 0, $limit );

		$posts = array();
		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Try to resolve the question to one or more real taxonomy terms: first
	 * as a whole phrase, then per significant word, then per AI-expanded
	 * concept term, if those find nothing (get_terms' 'search' works best
	 * against a single fragment, not a full sentence like "New holland
	 * traktor"). Expansion terms let a question resolve to a category it
	 * shares no literal words with — e.g. "mekanikere" resolving to a
	 * "Værksted" (workshop) category via the expanded term "værksted".
	 */
	private static function match_terms( $question, $taxonomies, $extra_terms = array(), $excluded_categories = array() ) {
		$terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'search'     => $question,
			'hide_empty' => false,
		) );
		$terms = self::filter_excluded_terms( $terms, $excluded_categories );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return $terms;
		}

		$matched = array();
		$candidates = array_merge( self::significant_words( $question ), (array) $extra_terms );
		foreach ( array_unique( $candidates ) as $word ) {
			$word_terms = get_terms( array(
				'taxonomy'   => $taxonomies,
				'search'     => $word,
				'hide_empty' => false,
			) );
			$word_terms = self::filter_excluded_terms( $word_terms, $excluded_categories );
			if ( ! is_wp_error( $word_terms ) && ! empty( $word_terms ) ) {
				$matched = array_merge( $matched, $word_terms );
			}
		}
		return $matched;
	}

	private static function significant_words( $question ) {
		// Strip surrounding punctuation and drop conversational filler
		// words (same list used by has_search_intent()) so a raw natural-
		// language question like "hvad tid har i åbent i værkstedet?" reduces
		// to just the meaningful terms ("åbent", "værkstedet") instead of
		// carrying the trailing "?" or long-but-meaningless words into the
		// search query.
		$words = preg_split( '/\s+/', $question );
		$out = array();
		foreach ( $words as $w ) {
			$clean = trim( $w, ".,!?;:¿¡'\"–-" );
			if ( $clean === '' || mb_strlen( $clean ) <= 2 ) {
				continue;
			}
			if ( in_array( mb_strtolower( $clean ), self::$conversational_stopwords, true ) ) {
				continue;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Search taxonomy terms (e.g. product categories) matching the question.
	 */
	public static function search_terms( $question, $taxonomies, $limit = 5, $extra_terms = array(), $excluded_categories = array() ) {
		$results = array();
		if ( empty( $taxonomies ) ) return $results;

		$terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'search'     => $question,
			'hide_empty' => false,
			'number'     => $limit,
		) );
		$terms = self::filter_excluded_terms( $terms, $excluded_categories );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			// The whole question matched nothing verbatim — try the
			// AI-expanded concept terms instead, so a category can still
			// surface for a question that doesn't literally name it.
			$found = array();
			foreach ( (array) $extra_terms as $word ) {
				$word_terms = get_terms( array(
					'taxonomy'   => $taxonomies,
					'search'     => $word,
					'hide_empty' => false,
					'number'     => $limit,
				) );
				$word_terms = self::filter_excluded_terms( $word_terms, $excluded_categories );
				if ( ! is_wp_error( $word_terms ) && ! empty( $word_terms ) ) {
					$found = array_merge( $found, $word_terms );
				}
			}
			$terms = array_slice( $found, 0, $limit );
		}

		if ( empty( $terms ) ) {
			$terms = self::search_term_meta(
				array_slice( array_values( array_unique( array_merge( self::significant_words( $question ), (array) $extra_terms ) ) ), 0, 12 ),
				$taxonomies,
				$limit,
				$excluded_categories
			);
		}

		if ( ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$term_excerpt = $term->description;
				$term_custom_content = self::get_term_custom_content( $term );
				if ( $term_custom_content !== '' ) {
					$term_excerpt = trim( $term_excerpt . "\n" . $term_custom_content );
				}
				$results[] = array(
					'type'      => 'category',
					'title'     => $term->name,
					'permalink' => get_term_link( $term ),
					'excerpt'   => $term_excerpt,
				);
			}
		}
		return $results;
	}

	private static function search_term_meta( $words, $taxonomies, $limit, $excluded_categories ) {
		global $wpdb;
		if ( empty( $words ) || empty( $taxonomies ) ) {
			return array();
		}

		$taxonomy_placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$scores = array();
		foreach ( array_slice( $words, 0, 6 ) as $word ) {
			$needle = '%' . $wpdb->esc_like( $word ) . '%';
			$sql = "SELECT DISTINCT t.term_id FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				INNER JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id
				WHERE tt.taxonomy IN ($taxonomy_placeholders)
				AND tm.meta_key NOT LIKE %s
				AND (tm.meta_value LIKE %s OR tm.meta_key LIKE %s)
				LIMIT 200";
			$args = array_merge( $taxonomies, array( '\_%', $needle, $needle ) );
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );
			foreach ( $ids as $term_id ) {
				$scores[ $term_id ] = isset( $scores[ $term_id ] ) ? $scores[ $term_id ] + 1 : 1;
			}
		}

		arsort( $scores );
		$terms = array();
		foreach ( array_slice( array_keys( $scores ), 0, $limit ) as $term_id ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) && ! self::is_excluded_term( $term, $excluded_categories ) ) {
				$terms[] = $term;
			}
		}
		return $terms;
	}

	private static function get_term_custom_content( $term ) {
		$meta = get_term_meta( $term->term_id );
		$parts = array();
		foreach ( $meta as $key => $values ) {
			if ( strpos( (string) $key, '_' ) === 0 ) {
				continue;
			}
			$text = self::flatten_custom_value( count( $values ) === 1 ? $values[0] : $values );
			if ( $text !== '' ) {
				$parts[] = str_replace( '_', ' ', $key ) . ': ' . $text;
			}
		}
		return mb_substr( implode( "\n", $parts ), 0, 12000 );
	}

	/**
	 * Deterministically resolve the visitor's question to a single "best"
	 * taxonomy term (independent of the AI), and return everything the
	 * frontend needs to render the category block: the matched term itself,
	 * its first 4 child terms (with images), and whether there are more.
	 *
	 * Returns null when the question doesn't match any real category — the
	 * frontend must not show the category block in that case.
	 *
	 * Uses strict matching only (see find_best_term_match_for_display) so
	 * general questions like "how many products do you have?" never surface
	 * an unrelated category card such as "Traktor".
	 */
	public static function find_matched_category( $question, $taxonomies, $extra_terms = array(), $excluded_categories = array() ) {
		if ( empty( $taxonomies ) || trim( (string) $question ) === '' ) {
			return null;
		}
		if ( self::is_general_catalog_question( $question ) || self::is_informational_question( $question ) ) {
			return null;
		}
		if ( ! self::question_wants_category_browse( $question ) ) {
			return null;
		}

		$term = self::find_best_term_match_for_display( $question, $taxonomies, $excluded_categories );
		if ( ! $term ) {
			return null;
		}

		return self::format_category( $term, $excluded_categories );
	}

	/**
	 * Whether the visitor is asking for general site contact details (phone,
	 * email, how to reach the company) without naming a specific category.
	 */
	public static function question_wants_general_contact( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$patterns = array(
			'contact number', 'contact numbers', 'phone number', 'telefonnummer',
			'call you', 'ring til jer', 'ring to you', 'reach you', 'contact you',
			'your contact', 'your phone', 'your email', 'your number',
			'any contact', 'kontakt jer', 'kontakt os', 'contact us',
			'how can i contact', 'how do i contact', 'how can i reach', 'how do i reach',
			'how to contact', 'how to reach', 'get in touch', 'get hold of',
			'for inquiry', 'for an inquiry', 'for some inquiry', 'for enquiry',
			'for some enquiry', 'for a question', 'general inquiry',
			'for en henvendelse', 'for henvendelse', 'for et spørgsmål',
			'can i call', 'may i call', 'where can i call', 'number where i can call',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		if ( preg_match( '/\b(contact|kontakt|phone|telefon|email|e-mail|mail|call|ring)\b/u', $q )
			&& preg_match( '/\b(you|jer|os|us|company|firma|store|shop|butik)\b/u', $q ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the visitor is explicitly asking for a contact person or contact
	 * details for a named category/topic. Used as a deterministic fallback
	 * when the AI expand-query call does not set wants_contact.
	 */
	public static function question_wants_category_contact( $question, $taxonomies = array() ) {
		if ( self::question_wants_general_contact( $question ) ) {
			return false;
		}
		if ( ! self::question_has_contact_intent( $question ) ) {
			return false;
		}
		if ( empty( $taxonomies ) ) {
			return false;
		}
		return self::find_best_term_match_for_contact( $question, $taxonomies ) !== null;
	}

	/**
	 * Whether the question signals any contact-person intent (general or specific).
	 */
	public static function question_wants_contact( $question ) {
		return self::question_wants_general_contact( $question ) || self::question_has_contact_intent( $question );
	}

	/**
	 * Whether the visitor is asking for contact details tied to a topic.
	 */
	private static function question_has_contact_intent( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$patterns = array(
			'contact info', 'contact information', 'contact details', 'contact person',
			'who do i contact', 'who should i contact', 'who can i contact', 'who to contact',
			'phone number for', 'email for', 'email address for', 'call about',
			'give me contact', 'get contact', 'service contact', 'servicekontakt',
			'kontaktinformation', 'kontaktoplysninger', 'kontaktperson', 'kontakt person',
			'hvem skal jeg ringe', 'hvem skal jeg kontakte', 'hvem er kontaktperson',
			'hvem er kontakt', 'hvem kan jeg ringe', 'hvem kan jeg kontakte',
			'telefonnummer til', 'telefon til', 'mail til', 'e-mail til',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		if ( preg_match( '/\b(contact|kontakt)\b/u', $q ) && preg_match( '/\b(for|til|about|om|regarding|vedr\.?)\b/u', $q ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the visitor explicitly wants browse/recommend product cards shown.
	 */
	public static function question_wants_product_cards( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$patterns = array(
			'show me', 'show us', 'recommend', 'suggest', 'which model', 'which models',
			'what models', 'list models', 'see models', 'see products', 'see the product',
			'vis mig', 'anbefal', 'anbefaler', 'hvilke modeller', 'se modeller', 'se produkter',
			'link to', 'product page', 'specifications for', 'specs for', 'more about the',
			'details about', 'mere om', 'fortæl om', 'tell me about the',
			'current offer', 'current offers', 'what offer', 'offers', 'offer of',
			'tilbud', 'kampagnetilbud', 'kampagne', 'campaign', 'deals', 'deal', 'promotion', 'rabat',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the visitor is asking an informational question (price, service,
	 * how-to, facts) where auto link/cards below the answer would be noise.
	 * The AI should answer in text (with inline links when relevant) instead.
	 */
	public static function is_informational_question( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$patterns = array(
			'hvad koster', 'hvad koster det', 'hvad koster en', 'hvad koster service',
			'what does it cost', 'what is the price', 'what are the prices', 'how much does',
			'how much is', 'how much do', 'how much are', 'price of', 'cost of',
			'pris på', 'pris for', 'pris af', 'koster det', 'koster en', 'koster service', 'koster ',
			'service af', 'service på', 'service for', 'service of', 'service cost', 'service price',
			'servicering', 'vedligehold', 'maintenance', 'repair', 'reparation', 'reparere',
			'how does', 'how do i', 'how can i', 'how to', 'hvordan',
			'what is', 'what are', 'hvad er', 'hvad er der', 'hvad indeholder',
			'when does', 'when do', 'hvornår', 'opening', 'åbning', 'adresse', 'address',
			'warranty', 'garanti', 'guarantee', 'insurance', 'forsikring',
			'can you', 'can i', 'kan i', 'kan man', 'må jeg',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Short yes/no dealer question (e.g. "forhandler i pronar?") — text answer
	 * only, no product or category cards.
	 */
	public static function is_simple_dealer_question( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$dealer_signals = array(
			'forhandler', 'dealer', 'sælger', 'sell', 'har i', 'har du', 'do you have',
			'do you sell', 'do you stock', 'do you carry', 'stock', 'carry',
		);
		$has_dealer = false;
		foreach ( $dealer_signals as $signal ) {
			if ( mb_stripos( $q, $signal ) !== false ) {
				$has_dealer = true;
				break;
			}
		}
		if ( ! $has_dealer ) {
			return false;
		}

		$words = preg_split( '/\s+/', $q );
		$word_count = is_array( $words ) ? count( $words ) : 0;
		return $word_count <= 8 && mb_substr( $q, -1 ) === '?';
	}

	/**
	 * Whether clickable product/link cards should appear below the answer.
	 * Only for browse, offers, or clearly product-focused questions — not every match.
	 */
	public static function question_wants_link_cards( $question ) {
		if ( self::is_simple_dealer_question( $question ) ) {
			return false;
		}
		if ( self::question_wants_product_cards( $question ) ) {
			return true;
		}
		if ( self::is_informational_question( $question ) ) {
			return false;
		}

		$q = mb_strtolower( trim( (string) $question ) );
		$patterns = array(
			'show me', 'show us', 'vis mig', 'vis os', 'see your', 'se jeres',
			'browse', 'udvalg', 'sortiment', 'product range', 'product line',
			'types of', 'slags', 'slag af', 'kategorier', 'categories',
			'looking for', 'leder efter', 'find en', 'finde en', 'søger en',
			'which models', 'hvilke modeller', 'what models', 'hvilke produkter',
			'recommend', 'anbefal', 'suggest',
			'tell me about the', 'fortæl om', 'more about the', 'mere om den',
			'specifications for', 'specs for', 'data sheet', 'datablad',
			'link to', 'product page',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the category browse block (subcategory cards + "Se mere") should show.
	 * Stricter than link cards — only when the visitor wants to browse a category.
	 */
	public static function question_wants_category_browse( $question ) {
		if ( ! self::question_wants_link_cards( $question ) ) {
			return false;
		}

		$q = mb_strtolower( trim( (string) $question ) );
		$browse_patterns = array(
			'show me', 'show us', 'vis mig', 'vis os', 'browse', 'udvalg', 'sortiment',
			'types of', 'slags', 'slag af', 'kategorier', 'categories',
			'which models', 'hvilke modeller', 'what models', 'hvilke produkter',
			'see your', 'se jeres', 'see products', 'se produkter', 'see models', 'se modeller',
			'product range', 'product line', 'looking for', 'leder efter',
			'tilbud', 'kampagnetilbud', 'kampagne', 'offer', 'offers', 'deal', 'deals',
		);

		foreach ( $browse_patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		return self::question_wants_product_cards( $question );
	}

	/**
	 * Whether the visitor is asking a general site/catalog question where a
	 * specific category browse card (e.g. "Traktor") would be misleading.
	 */
	public static function is_general_catalog_question( $question ) {
		$q = mb_strtolower( trim( (string) $question ) );
		if ( $q === '' ) {
			return false;
		}

		$patterns = array(
			'how many', 'how much', 'number of', 'count of', 'total number', 'total products',
			'products available', 'products do you have', 'product are available', 'items available',
			'what do you sell', 'what do you offer', 'what products', 'what machines do you',
			'opening hour', 'opening time', 'open at', 'when are you open', 'store hours',
			'where are you', 'your address', 'contact you', 'phone number', 'email address',
			'about your store', 'about your shop', 'in this store', 'in the store',
			'hvor mange', 'antal produkter', 'antal maskiner', 'produkter har i', 'produkter har du',
			'maskiner har i', 'hvad sælger', 'hvad tilbyder', 'åbningstid', 'åbent', 'hvornår har i åbent',
			'hvor ligger', 'adresse', 'kontakt os', 'telefonnummer', 'e-mail adresse',
		);

		foreach ( $patterns as $pattern ) {
			if ( mb_stripos( $q, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strict category matching for the browse card shown to visitors.
	 * Only matches when the question itself clearly names the category —
	 * never via fuzzy WP term search or AI-expanded concept terms, which
	 * caused unrelated categories (e.g. "Traktor") to appear on broad
	 * questions like "how many products are available?".
	 */
	private static function find_best_term_match_for_display( $question, $taxonomies, $excluded_categories = array() ) {
		$question = trim( (string) $question );
		if ( $question === '' ) {
			return null;
		}

		$all_terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
		) );
		$all_terms = self::filter_excluded_terms( $all_terms, $excluded_categories );
		if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
			return null;
		}

		foreach ( $all_terms as $t ) {
			if ( mb_strtolower( $t->name ) === mb_strtolower( $question ) ) {
				return $t;
			}
		}

		$best     = null;
		$best_len = 0;
		foreach ( $all_terms as $t ) {
			if ( mb_strlen( $t->name ) > 2 && mb_stripos( $question, $t->name ) !== false ) {
				if ( mb_strlen( $t->name ) > $best_len ) {
					$best     = $t;
					$best_len = mb_strlen( $t->name );
				}
			}
		}
		if ( $best ) {
			return $best;
		}

		$question_words = self::significant_words( $question );
		foreach ( $all_terms as $t ) {
			$term_name = mb_strtolower( $t->name );
			foreach ( $question_words as $word ) {
				if ( mb_strtolower( $word ) === $term_name || self::terms_match_loosely( $t->name, $word ) ) {
					return $t;
				}
			}
		}

		return null;
	}

	/**
	 * Loose term/word comparison for Danish singular/plural variants
	 * (e.g. "robotplæneklipper" vs "Robotplæneklippere").
	 */
	private static function terms_match_loosely( $term_name, $word ) {
		$term_name = mb_strtolower( trim( (string) $term_name ) );
		$word      = mb_strtolower( trim( (string) $word ) );
		if ( $term_name === '' || $word === '' ) {
			return false;
		}
		if ( $term_name === $word ) {
			return true;
		}
		if ( mb_strlen( $term_name ) >= 5 && mb_strlen( $word ) >= 5 ) {
			if ( mb_stripos( $term_name, $word ) !== false || mb_stripos( $word, $term_name ) !== false ) {
				return true;
			}
			if ( self::danish_stem( $term_name ) === self::danish_stem( $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Strip common Danish plural/definite suffixes for fuzzy term matching.
	 */
	private static function danish_stem( $word ) {
		$word = mb_strtolower( trim( (string) $word ) );
		foreach ( array( 'ene', 'erne', 'ere', 'er', 'en', 'e' ) as $suffix ) {
			$suffix_len = mb_strlen( $suffix );
			if ( mb_strlen( $word ) > $suffix_len + 4 && mb_substr( $word, -$suffix_len ) === $suffix ) {
				return mb_substr( $word, 0, -$suffix_len );
			}
		}
		return $word;
	}

	/**
	 * Find the single taxonomy term that best represents the question.
	 * Priority order:
	 *  1) Exact (case-insensitive) name match — the strongest possible signal.
	 *  2) The question contains a full term name (e.g. "New Holland traktorer"
	 *     contains the term "New Holland") — pick the longest such name so a
	 *     more specific term wins over a shorter, more generic one.
	 *  3) WordPress' own fuzzy term search (matches name/slug/description)
	 *     against the question itself, same as search_terms() above.
	 *  4) An EXACT name match against one of the AI-expanded concept terms
	 *     instead of the question's own words — this is what lets a category
	 *     resolve even when the question shares no literal words with it
	 *     (e.g. "mekanikere" question → "Værksted" category via the
	 *     expanded term "værksted").
	 *
	 *  Note: unlike step 2/3 above, expanded terms are NOT given the looser
	 *  "contains"/fuzzy checks. Expansion terms are synthetic, broad concept
	 *  words (e.g. "landbrugsmaskiner") rather than the visitor's own
	 *  wording, so loose matching against them tends to fuzzy-match an
	 *  unrelated top-level category for almost any question on a site whose
	 *  whole catalog is agricultural machinery — e.g. a workshop-hours
	 *  question falsely resolving to a broad "Maskiner" category. An exact
	 *  name match is precise enough to keep the good case working without
	 *  that false-positive risk.
	 */
	private static function find_best_term_match( $question, $taxonomies, $extra_terms = array(), $excluded_categories = array() ) {
		$question = trim( (string) $question );
		if ( $question === '' ) {
			return null;
		}

		$all_terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
		) );
		$all_terms = self::filter_excluded_terms( $all_terms, $excluded_categories );

		if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
			foreach ( $all_terms as $t ) {
				if ( mb_strtolower( $t->name ) === mb_strtolower( $question ) ) {
					return $t;
				}
			}

			$best     = null;
			$best_len = 0;
			foreach ( $all_terms as $t ) {
				if ( mb_strlen( $t->name ) > 2 && mb_stripos( $question, $t->name ) !== false ) {
					if ( mb_strlen( $t->name ) > $best_len ) {
						$best     = $t;
						$best_len = mb_strlen( $t->name );
					}
				}
			}
			if ( $best ) {
				return $best;
			}

			$question_words = self::significant_words( $question );
			$loose_best     = null;
			foreach ( $all_terms as $t ) {
				foreach ( $question_words as $word ) {
					if ( self::terms_match_loosely( $t->name, $word ) ) {
						if ( ! $loose_best || mb_strlen( $t->name ) > mb_strlen( $loose_best->name ) ) {
							$loose_best = $t;
						}
					}
				}
			}
			if ( $loose_best ) {
				return $loose_best;
			}
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'search'     => $question,
			'hide_empty' => false,
			'number'     => 1,
		) );
		$terms = self::filter_excluded_terms( $terms, $excluded_categories );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			return $terms[0];
		}

		// Nothing matched the question itself — try an EXACT name match
		// against the AI-expanded concept terms only (see note above for
		// why the looser "contains"/fuzzy checks are deliberately not
		// applied here).
		if ( ! empty( $extra_terms ) && ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
			foreach ( (array) $extra_terms as $word ) {
				foreach ( $all_terms as $t ) {
					if ( mb_strtolower( $t->name ) === mb_strtolower( $word ) || self::terms_match_loosely( $t->name, $word ) ) {
						return $t;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Words that signal a general contact request, not a product/category name.
	 */
	private static function generic_contact_words() {
		return array(
			'inquiry', 'inquiries', 'enquiry', 'enquiries', 'inqquiri', 'inqury',
			'question', 'questions', 'help', 'assistance', 'support',
			'henvendelse', 'henvendelser', 'spørgsmål', 'hjaelp', 'hjælp',
			'some', 'any', 'general', 'generel', 'call', 'ring', 'contact', 'kontakt',
			'number', 'nummer', 'phone', 'telefon', 'email', 'mail', 'informat',
		);
	}

	/**
	 * Strict taxonomy matching for ACF contact-person lookup. Unlike
	 * find_best_term_match(), never uses WordPress fuzzy term search on the
	 * full question — that caused general inquiries to match unrelated
	 * categories like "Entreprenørmaskiner".
	 */
	private static function find_best_term_match_for_contact( $question, $taxonomies, $extra_terms = array() ) {
		$question = trim( (string) $question );
		if ( $question === '' || empty( $taxonomies ) ) {
			return null;
		}

		$all_terms = get_terms( array(
			'taxonomy'   => $taxonomies,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $all_terms ) || empty( $all_terms ) ) {
			return null;
		}

		foreach ( $all_terms as $t ) {
			if ( mb_strtolower( $t->name ) === mb_strtolower( $question ) ) {
				return $t;
			}
		}

		$best     = null;
		$best_len = 0;
		foreach ( $all_terms as $t ) {
			if ( mb_strlen( $t->name ) > 2 && mb_stripos( $question, $t->name ) !== false ) {
				if ( mb_strlen( $t->name ) > $best_len ) {
					$best     = $t;
					$best_len = mb_strlen( $t->name );
				}
			}
		}
		if ( $best ) {
			return $best;
		}

		$generic_words = self::generic_contact_words();
		$question_words = array_filter(
			self::significant_words( $question ),
			function( $word ) use ( $generic_words ) {
				return ! in_array( mb_strtolower( $word ), $generic_words, true );
			}
		);

		$loose_best = null;
		foreach ( $all_terms as $t ) {
			foreach ( $question_words as $word ) {
				if ( self::terms_match_loosely( $t->name, $word ) ) {
					if ( ! $loose_best || mb_strlen( $t->name ) > mb_strlen( $loose_best->name ) ) {
						$loose_best = $t;
					}
				}
			}
		}
		if ( $loose_best ) {
			return $loose_best;
		}

		if ( ! empty( $extra_terms ) ) {
			foreach ( (array) $extra_terms as $word ) {
				if ( in_array( mb_strtolower( trim( (string) $word ) ), $generic_words, true ) ) {
					continue;
				}
				foreach ( $all_terms as $t ) {
					if ( mb_strtolower( $t->name ) === mb_strtolower( $word ) || self::terms_match_loosely( $t->name, $word ) ) {
						return $t;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Resolve the visitor's question to a contact person, following this
	 * logic (see plugin spec):
	 *  1. Resolve the question to the single best-matching taxonomy term,
	 *     using the exact same semantic/AI-driven matching as the category
	 *     block (find_best_term_match) — so "who has robot mowers" and
	 *     "who repairs robot mowers" resolve to the same category.
	 *  2. If that category has contact info in its ACF "vaerkforer"
	 *     repeater, return it.
	 *  3. Otherwise, and ONLY THEN, check that category's direct child
	 *     categories (never unrelated categories, never grandchildren) for
	 *     contact info, and return the first child that has any.
	 *  4. If nothing is found anywhere in that branch, return null so the
	 *     caller falls back to normal website search.
	 *
	 * Deliberately does NOT apply the admin's "excluded categories" list:
	 * that setting is meant to hide a category from normal browsing (the
	 * matched-category chip, subcategory cards, and general category/post
	 * search) — not to hide its contact person. A category can be a
	 * "service department" that shouldn't be presented as a browsable
	 * catalog category, while still being exactly the right contact to
	 * surface when a visitor's question is about it. So contact lookup
	 * always considers every category, hidden or not.
	 */
	public static function find_contact_person( $question, $taxonomies, $extra_terms = array() ) {
		if ( empty( $taxonomies ) || trim( (string) $question ) === '' ) {
			return null;
		}
		if ( ! function_exists( 'get_field' ) ) {
			// ACF isn't active — there is no "vaerkforer" data to read.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AIAB contact lookup] ACF (get_field) not available — is Advanced Custom Fields active?' );
			}
			return null;
		}

		// No excluded-categories filtering here — see note above.
		$term = self::find_best_term_match_for_contact( $question, $taxonomies, $extra_terms );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AIAB contact lookup] question="' . $question . '" matched term (excluded-categories list not applied): ' . ( $term ? $term->name . ' (id=' . $term->term_id . ')' : 'NONE' ) );
		}
		if ( ! $term ) {
			return null;
		}

		$contacts = self::get_term_contacts( $term );
		if ( ! empty( $contacts ) ) {
			return array(
				'category'  => $term->name,
				'permalink' => get_term_link( $term ),
				'contacts'  => $contacts,
			);
		}

		// Matched category itself has no contact info — look at ITS direct
		// children only (never unrelated/sibling categories, never deeper
		// descendants). Again, hidden/excluded children are still checked —
		// a hidden category can itself be the contact for its parent.
		$children = get_terms( array(
			'taxonomy'   => $term->taxonomy,
			'parent'     => $term->term_id,
			'hide_empty' => false,
		) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$names = is_wp_error( $children ) ? array() : wp_list_pluck( $children, 'name' );
			error_log( '[AIAB contact lookup] "' . $term->name . '" direct children checked: [' . implode( ', ', $names ) . ']' );
		}

		if ( is_wp_error( $children ) || empty( $children ) ) {
			return null;
		}

		foreach ( $children as $child ) {
			$child_contacts = self::get_term_contacts( $child );
			if ( ! empty( $child_contacts ) ) {
				return array(
					'category'  => $child->name,
					'permalink' => get_term_link( $child ),
					'contacts'  => $child_contacts,
				);
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AIAB contact lookup] no vaerkforer data found on "' . $term->name . '" or its ' . count( $children ) . ' direct child term(s).' );
		}

		return null;
	}

	/**
	 * Read the ACF "vaerkforer" repeater field on a taxonomy term (its rows
	 * are expected to have "navn", "telefon", and "email" sub-fields) and
	 * normalize it into a clean array of contact rows. Any row missing all
	 * three values is dropped as empty/unset.
	 */
	/**
	 * Read the ACF "vaerkforer" repeater field on a taxonomy term and
	 * normalize it into a clean array of contact rows: navn/telefon/email.
	 *
	 * Sub-field keys are matched tolerantly (case-insensitive, and against
	 * a few common naming variants) rather than requiring the exact strings
	 * "navn"/"telefon"/"email", since ACF field groups are hand-configured
	 * per site and small naming differences (e.g. "telefonnummer", "phone",
	 * "e-mail") are common and would otherwise silently return nothing.
	 *
	 * When WP_DEBUG is on, logs what was actually found for the matched
	 * term — this is the fastest way to tell "field is genuinely empty"
	 * apart from "field/sub-field name doesn't match what the code expects".
	 */
	private static function get_term_contacts( $term ) {
		$field_selector = $term->taxonomy . '_' . $term->term_id;
		$rows = get_field( 'vaerkforer', $field_selector );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[AIAB contact lookup] term="%s" (id=%d, taxonomy=%s) selector="%s" vaerkforer field: %s',
				$term->name,
				$term->term_id,
				$term->taxonomy,
				$field_selector,
				empty( $rows ) ? 'EMPTY/NOT SET' : ( is_array( $rows ) ? ( count( $rows ) . ' row(s), keys: ' . implode( ',', array_keys( (array) reset( $rows ) ) ) ) : 'non-array value: ' . wp_json_encode( $rows ) )
			) );
		}

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$contacts = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$navn    = self::get_row_value( $row, array( 'navn', 'name', 'navn_paa_kontakt' ) );
			$telefon = self::get_row_value( $row, array( 'telefon', 'telefonnummer', 'phone', 'tlf' ) );
			$email   = self::get_row_value( $row, array( 'email', 'e-mail', 'e_mail', 'mail' ) );

			if ( $navn === '' && $telefon === '' && $email === '' ) {
				continue;
			}

			$contacts[] = array(
				'navn'    => $navn,
				'telefon' => $telefon,
				'email'   => $email,
			);
		}
		return $contacts;
	}

	/**
	 * Look up a repeater row's value by trying several possible sub-field
	 * key spellings, case-insensitively, since ACF sub-field names are
	 * hand-configured per site and can vary (e.g. "telefon" vs "Telefon"
	 * vs "telefonnummer").
	 */
	private static function get_row_value( $row, $possible_keys ) {
		// Fast path: exact key match, most common case.
		foreach ( $possible_keys as $key ) {
			if ( isset( $row[ $key ] ) && $row[ $key ] !== '' ) {
				return trim( wp_strip_all_tags( (string) $row[ $key ] ) );
			}
		}
		// Slow path: case-insensitive match against whatever keys the row
		// actually has, in case casing differs from what's listed above.
		$lower_map = array();
		foreach ( $row as $k => $v ) {
			$lower_map[ mb_strtolower( (string) $k ) ] = $v;
		}
		foreach ( $possible_keys as $key ) {
			$k = mb_strtolower( $key );
			if ( isset( $lower_map[ $k ] ) && $lower_map[ $k ] !== '' ) {
				return trim( wp_strip_all_tags( (string) $lower_map[ $k ] ) );
			}
		}
		return '';
	}

	/**
	 * Build the compact "category" payload sent to the frontend: the parent
	 * term plus up to 4 of its child terms. If it has no children, the
	 * frontend falls back to linking the parent term itself.
	 */
	private static function format_category( $term, $excluded_categories = array() ) {
		$children = get_terms( array(
			'taxonomy'   => $term->taxonomy,
			'parent'     => $term->term_id,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $children ) ) {
			$children = array();
		}
		$children = self::filter_excluded_terms( $children, $excluded_categories );

		$children_count = count( $children );
		$shown_children = array_slice( $children, 0, 4 );

		$subcategories = array();
		foreach ( $shown_children as $child ) {
			$subcategories[] = array(
				'id'        => $child->term_id,
				'title'     => $child->name,
				'permalink' => get_term_link( $child ),
				'image'     => self::get_term_image( $child ),
			);
		}

		return array(
			'id'            => $term->term_id,
			'title'         => $term->name,
			'permalink'     => get_term_link( $term ),
			'more_link'     => self::get_more_link_override( $term ),
			'image'         => self::get_term_image( $term ),
			'description'   => $term->description,
			'subcategories' => $subcategories,
			'has_more'      => $children_count > 4,
			'more_count'    => max( 0, $children_count - 4 ),
		);
	}

	/**
	 * The "Se mere" button on the category block normally links to the
	 * matched term's own permalink (get_term_link). For a few specific
	 * top-level categories we instead want it to point at a fixed site
	 * page, so we override it here. home_url() automatically prepends the
	 * current site's domain, so this keeps working across environments
	 * (staging/production) without hardcoding a host.
	 *
	 * Matching is done with a loose "contains" check (case-insensitive)
	 * against the term name, so it still matches whether the term is
	 * named "Landbrug" or "Landbrugs", "Entreprenør" or "Entreprenor",
	 * etc. Falls back to the term's own permalink when nothing matches.
	 */
	private static function get_more_link_override( $term ) {
		$name = mb_strtolower( trim( $term->name ) );

		if ( mb_stripos( $name, 'have og park' ) !== false ) {
			return home_url( '/have-og-park-maskiner/' );
		}

		if ( mb_stripos( $name, 'entreprenør' ) !== false || mb_stripos( $name, 'entreprenor' ) !== false ) {
			return home_url( '/entreprenor-maskiner/' );
		}

		// "landbrug" also matches "landbrugs" since it's a substring.
		if ( mb_stripos( $name, 'landbrug' ) !== false ) {
			return home_url( '/landbrugsmaskiner/' );
		}

		return get_term_link( $term );
	}

	/**
	 * Fetch a term's image from the ACF field "billede". Supports ACF's
	 * Image field returned as an array (Image Array), an ID (Image ID), or
	 * a URL string (Image URL) — whichever return format the field is set to.
	 */
	private static function get_term_image( $term ) {
		if ( ! function_exists( 'get_field' ) ) {
			return '';
		}

		$image = get_field( 'billede', $term->taxonomy . '_' . $term->term_id );

		if ( is_array( $image ) && ! empty( $image['url'] ) ) {
			return $image['url'];
		}
		if ( is_numeric( $image ) ) {
			$url = wp_get_attachment_image_url( (int) $image, 'medium' );
			return $url ? $url : '';
		}
		if ( is_string( $image ) && $image !== '' ) {
			return $image;
		}

		return '';
	}

	/**
	 * Normalize a WP_Post into a compact array for the AI context and for rendering cards.
	 */
	private static function format_post( $post ) {
		$image = get_the_post_thumbnail_url( $post->ID, 'medium' );

		$price = '';
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$price = $product->get_price_html();
			}
		}

		$excerpt = has_excerpt( $post->ID )
			? get_the_excerpt( $post )
			: wp_trim_words( wp_strip_all_tags( $post->post_content ), 40 );
		$acf_content = self::get_post_custom_content( $post->ID );
		if ( $acf_content !== '' ) {
			$excerpt = trim( $excerpt . "\n" . $acf_content );
		}

		$terms_str = array();
		$taxonomies = get_object_taxonomies( $post->post_type );
		foreach ( $taxonomies as $tax ) {
			$terms = get_the_terms( $post->ID, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$terms_str[] = $t->name;
				}
			}
		}

		return array(
			'type'       => $post->post_type,
			'id'         => $post->ID,
			'title'      => get_the_title( $post ),
			'excerpt'    => $excerpt,
			'permalink'  => get_permalink( $post ),
			'image'      => $image ? $image : '',
			'price'      => $price,
			'categories' => $terms_str,
		);
	}

	/**
	 * Flatten public custom-field values, including ACF repeaters and groups,
	 * into plain text that can be searched and supplied to the AI.
	 */
	private static function get_post_custom_content( $post_id ) {
		$meta = get_post_meta( $post_id );
		$parts = array();
		foreach ( $meta as $key => $values ) {
			if ( strpos( (string) $key, '_' ) === 0 ) {
				continue;
			}
			$text = self::flatten_custom_value( count( $values ) === 1 ? $values[0] : $values );
			if ( $text !== '' ) {
				$parts[] = str_replace( '_', ' ', $key ) . ': ' . $text;
			}
		}
		return mb_substr( implode( "\n", $parts ), 0, 12000 );
	}

	private static function flatten_custom_value( $value ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$text = self::flatten_custom_value( $item );
				if ( $text !== '' ) {
					$parts[] = $text;
				}
			}
			return implode( ', ', $parts );
		}
		if ( is_scalar( $value ) ) {
			$text = trim( wp_strip_all_tags( (string) $value ) );
			return preg_replace( '/\s+/u', ' ', $text );
		}
		return '';
	}
}
