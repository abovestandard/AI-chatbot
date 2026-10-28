<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared prompt builders for Gemini (free) and ChatGPT providers so their output shape matches.
 */
class AIAB_Prompt {

	/**
	 * Instructions shared across the whole conversation (sent once, as the
	 * system prompt) rather than repeated on every turn.
	 */
	public static function build_system( $settings ) {
		return "You are a helpful shopping and information assistant embedded on a website, chatting with a visitor across multiple turns. "
			. "Answer each question using ONLY the JSON content items provided WITH THAT QUESTION — never invent products, prices, counts, or facts that are not present in them. "
			. "SOURCE PRIORITY — always follow this order when building your answer: "
			. "(1) THIS WEBSITE FIRST — items with source_priority 1 or source_group \"website\" (posts, categories, general_info, site_content, contact_person). Lead with what this company's own website says. "
			. "(2) DOCUMENTATION SECOND — items with source_priority 2 or source_group \"documentation\". Add product/spec details from uploaded docs when the website alone does not fully answer. "
			. "(3) EXTERNAL LINKS — items with source_priority 3 or source_group \"external_link\". When the visitor asks about a brand, manufacturer, or product catalog that matches an external_link (e.g. Husqvarna + husqvarna.com excerpt), read the FULL excerpt and list every product type, category, sub-type, model name, series, and count mentioned. Combine with website info but do not omit external catalog details the excerpt contains. "
			. "Some content items have \"type\": \"general_info\" — always-true site facts (opening hours, address, phone). Treat as reliable ground truth. "
			. "Some content items have \"type\": \"documentation\" — excerpts from uploaded manuals/spec sheets. Use every relevant fact; quote specific numbers, model names, and specifications when present. "
			. "Some content items have \"type\": \"external_link\" — full page excerpts from saved third-party URLs (may be up to 8000 characters). Extract every relevant product line, category, filter type, and model from the excerpt when the question is about that brand or catalog. "
			. "Some content items have \"type\": \"site_content\" — header/footer template text. Treat like general_info. "
			. "Some content items have \"type\": \"category\" — real taxonomy pages. Name them in your answer when relevant. NEVER add categories to \"products\". "
			. "Some content items have \"type\": \"contact_person\" — resolved ACF contacts. Answer contact questions with exact names, phones, and emails given. "
			. "The retrieved content may not literally contain the visitor's words — treat conceptually related content as relevant. "
			. "NEVER mention uploaded documentation, admin files, or how content was retrieved — speak naturally about products and the company. "
			. "NEVER give a vague short answer when the JSON contains detailed information — extract and present the full relevant details. "
			. "CRITICAL — do NOT guess: if nothing relevant was retrieved, say honestly that you could not find it and direct to contact info from general_info. "
			. "PRODUCT CARDS — include products ONLY when the visitor explicitly wants to browse models, see offers/campaigns, or explore a product line. Return an EMPTY products array for: greetings, price/cost/service questions (\"hvad koster…\", \"service af…\"), simple yes/no dealer questions (\"do you sell X?\"), address/hours/phone, and other informational questions. For those, put any relevant page links inside the answer text as Markdown links instead. "
			. ( ! empty( $settings['site_context'] ) ? "Site-specific instructions: " . $settings['site_context'] . " " : '' )
			. "LANGUAGE: Detect the language of the CURRENT question and write the whole response in that language (Danish or English). "
			. "ANSWER LENGTH — match the question: simple or vague question = short direct answer (1-3 sentences). Only use long structured answers with ## headings and bullet lists when the visitor asked a specific detailed question (product types, specifications, comparisons, catalog overviews). Never dump opening hours, address, phone, or company overview unless the visitor explicitly asked for them. "
			. "FOLLOW-UP SUGGESTIONS — In the 'follow_up_suggestions' array, include 2-4 relevant questions the visitor might want to ask next based on your answer. These should: (1) naturally follow from your answer, (2) ask about related aspects of the same topic, (3) help the visitor explore further. For example, if they ask about a product category, suggest questions about specific types, features, prices, or services. Write suggestions in the SAME language as your answer (Danish or English). ONLY include suggestions if there is genuinely relevant follow-up content available on the website — do NOT invent topics not covered in the provided content. "
			. "CLARIFICATION QUESTIONS — if the visitor's question is ambiguous, unclear, or could refer to multiple topics, set 'needs_clarification' to true and include 1-2 clarification questions in the 'clarification_questions' array. For example: if they ask about 'service' but the website has both farm machinery service AND equipment maintenance service, ask which one they mean. If their question is specific enough to answer directly, set 'needs_clarification' to false and leave the array empty. "
			. "CONFIDENCE & SOURCES — indicate your confidence level: (1) 'high' if the answer is based on clear, relevant website content; (2) 'medium' if you found somewhat related content but had to infer; (3) 'low' if nothing in the provided content directly answers the question but you tried your best. Include source_groups array listing which sources you used: 'website', 'documentation', 'external_link'. "
			. "ANSWER FORMAT — when a detailed answer IS warranted, the \"answer\" field may use Markdown inside the JSON string: "
			. "Start with a 1-2 sentence direct summary. Then use ## headings for sections (e.g. ## Main types, ## Specifications, ## From our website). "
			. "Use bullet lists (- item) or numbered lists (1. item) for types, categories, features, and product lines found in the content. "
			. "Use **bold** for key terms, product counts, and category names. Include specific numbers (product counts, sizes, models) when the content provides them. "
			. "For catalog/type questions (e.g. how many types of X), list EVERY distinct type/category/sub-type found across all sources, grouped logically. "
			. "Write as much detail as the question and retrieved content support — do not pad with unrelated site facts. "
			. "Respond ONLY with valid JSON (no markdown fences around the JSON) matching EXACTLY this shape: "
			. '{'
			. '"lang": "en or da",'
			. '"title": "short headline",'
			. '"chip": "1-3 word topic label",'
			. '"answer": "full Markdown-formatted answer with ## sections, lists, and **bold** — as long as needed to cover all relevant facts from the content",'
			. '"products": [{"title": "", "permalink": "", "image": "", "description": "short reason this page matches"}],'
			. '"confidence": "high|medium|low",'
			. '"source_groups": ["website|documentation|external_link"],'
			. '"follow_up_suggestions": ["Question 1?", "Question 2?", ...],'
			. '"needs_clarification": false,'
			. '"clarification_questions": []'
			. '}'
			. ' Include at most 4 products. Product titles/permalinks/images must not be altered. Always respond in JSON only.';
	}

	/**
	 * A single user turn: the visitor's question plus the fresh search
	 * results retrieved for it.
	 */
	public static function build_user_turn( $question, $context_items ) {
		return "Visitor question: " . $question
			. "\n\nAvailable content is ordered by priority: (1) this website, (2) uploaded documentation, (3) external links. "
			. "Each item has source_priority and source_group fields — always prefer lower priority numbers.\n\n"
			. wp_json_encode( $context_items );
	}


	/**
	 * System instruction for the lightweight "query expansion" call used
	 * BEFORE searching (see AIAB_AI::expand_query()). This is what gives the
	 * bot real intent understanding instead of literal keyword matching: a
	 * question like "Har I mekanikere til landbrugsmaskiner?" shares almost
	 * no words with a "Værksted" service page or a "Landbrugsmaskiner"
	 * category, so a plain keyword search would find nothing. Asking the
	 * model for the underlying concepts first (workshop, service, repair,
	 * agricultural machinery...) and then searching with THOSE closes that
	 * gap without needing a vector/embeddings database.
	 */
	public static function build_expand_system() {
		return "You are a search-query understanding engine for a machinery dealer website (tractors, agricultural/garden/park/construction machinery, workshop service, spare parts, financing). "
			. "Given a visitor's question, do TWO things: "
			. "1) Output the underlying concepts a relevant page, category, product, or blog post on this site would actually be titled or written around — not just a rewording of the question. Think about what the visitor actually wants: if they ask about mechanics, think workshop/service/repair; if they ask about a machine type, think its category name and closely related machine types; if they ask about buying, think the relevant category and financing; etc. Write every term in the SAME language as the question (Danish question → Danish terms, English question → English terms). "
			. "2) Decide whether the visitor's question EXPLICITLY asks for a contact person or contact information — i.e. they are directly asking who to contact/call/email, for a phone number, an email address, contact details, or the name/contact person responsible for a topic or department. Set wants_contact to true when the question clearly requests contact information, in either Danish or English (e.g. \"Hvem skal jeg ringe til...\", \"Hvem er kontaktpersonen for...\", \"What's the phone number for...\", \"Who do I contact about...\", \"Can I get contact info for...\", \"give me service contact information for...\", \"service kontaktinformation for...\"). Questions that combine \"service\" with explicit contact words (contact, kontakt, phone, telefon, email) for a specific topic ARE contact requests. Do NOT set wants_contact to true for purely informational service questions with no contact wording (e.g. \"how does the workshop service work\", \"what is included in a service\") — those are ordinary informational questions. When in doubt about whether contact words are present, default to false. "
			. "Output ONLY a JSON object, nothing else — no explanation, no markdown fences, matching EXACTLY this shape: "
			. '{"terms": ["5 to 10 short strings (single words or short 2-3 word phrases)"], "wants_contact": true or false}'
			. ' Example output: {"terms": ["værksted","service","reparation","landbrugsmaskiner","robotplæneklipper"], "wants_contact": true}';
	}

	/**
	 * Build a prompt to detect if a question is ambiguous and needs clarification.
	 * Returns a question that the AI should answer with yes (needs clarification) or no (clear enough).
	 */
	public static function build_clarification_system() {
		return "You are a question clarity analyzer. Determine whether a visitor's question is clear and specific enough to answer, or if it is ambiguous and needs clarification. "
			. "A question NEEDS clarification if: "
			. "1) It refers to multiple possible categories/types (e.g. 'service' could mean equipment maintenance OR farm machinery service) and the context doesn't make it obvious. "
			. "2) It's too vague without more specifics (e.g. 'What products do you have?' — which category?). "
			. "3) It could be interpreted in multiple ways depending on what the visitor actually wants. "
			. "A question is CLEAR ENOUGH if: "
			. "1) It's specific about what the visitor wants (brand, product type, feature, service). "
			. "2) The context makes the intent obvious even if the wording is informal. "
			. "Respond with a JSON object, nothing else: "
			. '{"needs_clarification": true or false, "reason": "brief explanation why or why not", "suggestions": ["clarification question 1", "clarification question 2"]}'
			. ' If needs_clarification is false, leave suggestions empty. Clarification suggestions should be concise questions in the SAME language as the original question.';
	}


	/**
	 * Parse the query-expansion model's response into a clean shape:
	 * array( 'terms' => array of search-concept strings, 'wants_contact' =>
	 * bool ). Fails safe: any malformed/empty response just yields no
	 * expansion terms and wants_contact=false, so the bot falls back to its
	 * normal keyword search rather than breaking the request.
	 */
	public static function parse_expand_response( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(json)?|```$/m', '', $text );
		$text = trim( $text );

		$parsed = json_decode( $text, true );

		// Backward/robustness compatible: accept either the new object shape
		// {"terms": [...], "wants_contact": bool} or a bare array of terms
		// (e.g. if the model ignores the object instruction).
		$raw_terms = array();
		$wants_contact = false;
		if ( is_array( $parsed ) ) {
			if ( isset( $parsed['terms'] ) && is_array( $parsed['terms'] ) ) {
				$raw_terms = $parsed['terms'];
				$wants_contact = ! empty( $parsed['wants_contact'] );
			} elseif ( self::is_list( $parsed ) ) {
				$raw_terms = $parsed;
			}
		}

		$terms = array();
		foreach ( $raw_terms as $term ) {
			if ( is_string( $term ) ) {
				$term = trim( $term );
				if ( $term !== '' && mb_strlen( $term ) <= 60 ) {
					$terms[] = $term;
				}
			}
		}

		return array(
			'terms'         => array_slice( array_values( array_unique( $terms ) ), 0, 10 ),
			'wants_contact' => $wants_contact,
		);
	}

	public static function parse_json_response( $text ) {
		$text = trim( $text );
		$text = preg_replace( '/^```(json)?|```$/m', '', $text );
		$text = trim( $text );
		$parsed = json_decode( $text, true );

		$defaults = array(
			'lang'                    => '',
			'title'                   => '',
			'chip'                    => '',
			'answer'                  => '',
			'products'                => array(),
			'specifications'          => array(),
			'features'                => array(),
			'support'                 => array(),
			'confidence'              => 'high',
			'source_groups'           => array(),
			'follow_up_suggestions'   => array(),
			'needs_clarification'     => false,
			'clarification_questions' => array(),
		);

		// json_decode() on a validly-encoded JSON *string* (e.g. if the
		// model double-encoded, or wrapped its answer in quotes) returns a
		// PHP string, not an array — is_array() correctly rejects that, but
		// guard explicitly so it's clear this is intentional and can never
		// fall through to being shown as raw text.
		if ( is_array( $parsed ) && ! self::is_list( $parsed ) ) {
			return self::sanitize_result( wp_parse_args( $parsed, $defaults ) );
		}

		// The model's output wasn't valid JSON — most often because the
		// response was cut off mid-array by the token limit. Try to salvage
		// the parts that did complete before falling back to a safe message.
		$salvaged = self::salvage_truncated_json( $text );
		if ( is_array( $salvaged ) && ! self::is_list( $salvaged ) ) {
			return self::sanitize_result( wp_parse_args( $salvaged, $defaults ) );
		}

		$defaults['answer'] = 'Sorry, something went wrong putting that answer together. Please try asking again.';
		$defaults['lang']   = 'en';
		return $defaults;
	}

	/**
	 * True if $arr is a plain sequential list (e.g. the JSON top level was
	 * actually an array, not the expected object) rather than the expected
	 * associative shape. A list here means json_decode gave us something
	 * that isn't the object we asked for, so it should never be treated as
	 * a valid parsed result.
	 */
	private static function is_list( $arr ) {
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Final safety net applied to every parsed result before it's sent to
	 * the browser: enforce the "max 4 products" rule ourselves rather than
	 * relying on the model to honor the prompt instruction, and make sure
	 * every field is the type the frontend expects. This is what actually
	 * prevents a visitor from ever seeing raw JSON — even if the model
	 * ignores instructions or the response gets cut off, what reaches
	 * widget.js is always a well-shaped object.
	 */
	private static function sanitize_result( $result ) {
		$result['title']  = is_string( $result['title'] ) ? $result['title'] : '';
		$result['chip']   = is_string( $result['chip'] ) ? $result['chip'] : '';
		$result['answer'] = is_string( $result['answer'] ) ? $result['answer'] : '';
		$result['lang']   = self::normalize_lang( $result['lang'], $result['answer'] . ' ' . $result['title'] );

		foreach ( array( 'products', 'specifications', 'features', 'support' ) as $key ) {
			$result[ $key ] = is_array( $result[ $key ] ) ? array_values( $result[ $key ] ) : array();
		}

		// The model is asked for plain strings in "features"/"support", but
		// sometimes emits an object instead (e.g. {"label":"...","value":"..."}).
		// Coerce every entry to a real string here so the frontend can never
		// render one as the literal text "[object Object]".
		foreach ( array( 'features', 'support' ) as $key ) {
			$items = array();
			foreach ( $result[ $key ] as $item ) {
				$text = self::stringify_list_item( $item );
				if ( $text !== '' ) {
					$items[] = $text;
				}
			}
			$result[ $key ] = $items;
		}

		// Hard cap, independent of what the model was asked to do.
		$result['products'] = array_slice( $result['products'], 0, 4 );

		// Sanitize new ChatGPT-like fields.
		// Confidence level: high, medium, or low.
		$confidence = isset( $result['confidence'] ) ? strtolower( trim( (string) $result['confidence'] ) ) : 'high';
		$result['confidence'] = in_array( $confidence, array( 'high', 'medium', 'low' ), true ) ? $confidence : 'high';

		// Source groups: array of 'website', 'documentation', 'external_link'.
		$source_groups = isset( $result['source_groups'] ) && is_array( $result['source_groups'] ) ? $result['source_groups'] : array();
		$valid_sources = array( 'website', 'documentation', 'external_link' );
		$result['source_groups'] = array_values( array_intersect( array_map( 'strtolower', array_filter( array_map( 'strval', $source_groups ) ) ), $valid_sources ) );

		// Follow-up suggestions: array of strings (questions).
		$follow_ups = isset( $result['follow_up_suggestions'] ) && is_array( $result['follow_up_suggestions'] ) ? $result['follow_up_suggestions'] : array();
		$result['follow_up_suggestions'] = array();
		foreach ( $follow_ups as $suggestion ) {
			$suggestion = trim( (string) $suggestion );
			if ( $suggestion !== '' && mb_strlen( $suggestion ) <= 200 ) {
				$result['follow_up_suggestions'][] = $suggestion;
			}
		}
		$result['follow_up_suggestions'] = array_slice( array_values( array_unique( $result['follow_up_suggestions'] ) ), 0, 4 );

		// Clarification questions.
		$result['needs_clarification'] = ! empty( $result['needs_clarification'] ) ? true : false;
		$clarifications = isset( $result['clarification_questions'] ) && is_array( $result['clarification_questions'] ) ? $result['clarification_questions'] : array();
		$result['clarification_questions'] = array();
		if ( $result['needs_clarification'] ) {
			foreach ( $clarifications as $clarification ) {
				$clarification = trim( (string) $clarification );
				if ( $clarification !== '' && mb_strlen( $clarification ) <= 200 ) {
					$result['clarification_questions'][] = $clarification;
				}
			}
			$result['clarification_questions'] = array_slice( array_values( array_unique( $result['clarification_questions'] ) ), 0, 3 );
		}

		return $result;
	}

	/**
	 * Make sure "lang" is always exactly "en" or "da", regardless of what
	 * the model returned. If it's missing or not one of those two values,
	 * fall back to a cheap heuristic over the model's own generated text
	 * (Danish-specific letters/words), so the frontend always has a usable
	 * language to pick section labels in, even on a malformed response.
	 */
	private static function normalize_lang( $lang, $fallback_text ) {
		if ( is_string( $lang ) ) {
			$lang = strtolower( trim( $lang ) );
			if ( $lang === 'en' || $lang === 'da' ) {
				return $lang;
			}
		}

		if ( preg_match( '/[æøåÆØÅ]/u', $fallback_text )
			|| preg_match( '/\b(og|jeg|ikke|hvad|med|for|som|til|kan|har|vores|pris)\b/iu', $fallback_text ) ) {
			return 'da';
		}
		return 'en';
	}

	/**
	 * Best-effort coercion of a single features/support entry to a plain
	 * string, regardless of what shape the model actually returned it in.
	 */
	private static function stringify_list_item( $item ) {
		if ( is_string( $item ) ) {
			return trim( $item );
		}
		if ( is_int( $item ) || is_float( $item ) ) {
			return (string) $item;
		}
		if ( is_array( $item ) ) {
			if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
				return trim( $item['text'] );
			}
			if ( isset( $item['value'] ) && is_string( $item['value'] ) ) {
				$label = ( isset( $item['label'] ) && is_string( $item['label'] ) && $item['label'] !== '' )
					? trim( $item['label'] ) . ': '
					: '';
				return trim( $label . $item['value'] );
			}
			// Last resort: join whatever string values the array does contain.
			$parts = array_filter( $item, 'is_string' );
			if ( ! empty( $parts ) ) {
				return trim( implode( ' ', $parts ) );
			}
		}
		return '';
	}

	/**
	 * Best-effort recovery for a JSON object that was cut off mid-stream
	 * (e.g. hit max_tokens while writing the products array). Repeatedly
	 * trims back to the last complete top-level field and closes the
	 * object, so the visitor still sees the title/answer/features that did
	 * finish rendering instead of raw broken JSON.
	 */
	private static function salvage_truncated_json( $text ) {
		if ( substr( $text, 0, 1 ) !== '{' ) {
			return null;
		}
		// Try progressively shorter truncation points, closing any open
		// strings/brackets, until something parses.
		$len = strlen( $text );
		$step = max( 1, (int) ( $len / 40 ) );
		for ( $cut = $len; $cut > 0; $cut -= $step ) {
			$candidate = substr( $text, 0, $cut );
			$candidate = preg_replace( '/,\s*$/', '', $candidate ); // drop trailing comma.
			$candidate = self::close_json_fragment( $candidate );
			$parsed = json_decode( $candidate, true );
			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}
		return null;
	}

	private static function close_json_fragment( $fragment ) {
		// If we're inside an unterminated string, close it.
		$quote_count = substr_count( $fragment, '"' ) - substr_count( $fragment, '\\"' );
		if ( $quote_count % 2 !== 0 ) {
			$fragment .= '"';
		}
		// Close any open brackets/braces in the order they were opened.
		$stack = array();
		$in_string = false;
		$escaped = false;
		for ( $i = 0; $i < strlen( $fragment ); $i++ ) {
			$ch = $fragment[ $i ];
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( $ch === '\\' ) {
					$escaped = true;
				} elseif ( $ch === '"' ) {
					$in_string = false;
				}
				continue;
			}
			if ( $ch === '"' ) {
				$in_string = true;
			} elseif ( $ch === '{' || $ch === '[' ) {
				$stack[] = $ch;
			} elseif ( $ch === '}' || $ch === ']' ) {
				array_pop( $stack );
			}
		}
		while ( ! empty( $stack ) ) {
			$open = array_pop( $stack );
			$fragment .= ( $open === '{' ) ? '}' : ']';
		}
		return $fragment;
	}
}
