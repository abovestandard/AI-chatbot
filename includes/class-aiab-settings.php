<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIAB_Settings {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_aiab_test_key', array( $this, 'ajax_test_key' ) );
	}

	public function add_menu() {
		add_options_page(
			'AI-assistentbot',
			'AI-assistentbot',
			'manage_options',
			'ai-assistant-bot',
			array( $this, 'render_page' )
		);
	}

	public function enqueue_admin_assets( $hook ) {
		if ( $hook !== 'settings_page_ai-assistant-bot' ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style(
			'aiab-admin-settings',
			AIAB_URL . 'assets/css/admin-settings.css',
			array(),
			AIAB_VERSION
		);

		wp_enqueue_media();
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script(
			'aiab-admin-settings',
			AIAB_URL . 'assets/js/admin-settings.js',
			array( 'jquery', 'wp-color-picker' ),
			AIAB_VERSION,
			true
		);

		wp_localize_script( 'aiab-admin-settings', 'AIAB_ADMIN', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'testNonce' => wp_create_nonce( 'aiab_test_key' ),
		) );
	}

	public static function get_settings() {
		$defaults = array(
			'provider'         => 'gemini',
			'api_key'          => '',
			'model'            => 'gemini-2.0-flash',
			'search_all_content' => 0,
			'post_types'       => array(),
			'excluded_post_types' => array(),
			'taxonomies'       => array(),
			'excluded_categories' => array(),
			'bot_name'         => 'AI-assistent',
			'logo_url'         => '',
			'welcome_message'  => 'Hvad kan vi hjælpe dig med i dag?',
			'placeholder_text' => 'Spørg om maskiner, service, reservedele eller finansiering...',
			'primary_color'    => '#1a73e8',
			'site_context'     => '',
			'general_info'     => '',
			'auto_inject'      => 1,
			'results_limit'    => 8,
			'cache_minutes'    => 60,
			'fallback_message' => '',
		);
		$settings = wp_parse_args( get_option( 'aiab_settings', array() ), $defaults );
		return self::normalize_settings( $settings, $defaults );
	}

	/**
	 * Migrate removed providers and invalid model names on read.
	 */
	private static function normalize_settings( $settings, $defaults ) {
		if ( isset( $settings['provider'] ) && $settings['provider'] === 'claude' ) {
			$settings['provider'] = 'gemini';
		}
		if ( ! empty( $settings['model'] ) && strpos( $settings['model'], 'claude' ) === 0 ) {
			$settings['model'] = $defaults['model'];
		}
		return $settings;
	}

	public function register_settings() {
		register_setting( 'aiab_settings_group', 'aiab_settings', array( $this, 'sanitize' ) );
	}

	public function sanitize( $input ) {
		$out = self::get_settings();
		$out['provider']         = isset( $input['provider'] ) && in_array( $input['provider'], array( 'gemini', 'openai' ), true ) ? $input['provider'] : $out['provider'];
		$out['api_key']          = isset( $input['api_key'] ) ? sanitize_text_field( trim( $input['api_key'] ) ) : $out['api_key'];
		$out['model']            = isset( $input['model'] ) ? sanitize_text_field( trim( $input['model'] ) ) : $out['model'];
		$out['search_all_content'] = isset( $input['search_all_content'] ) ? 1 : 0;
		$out['post_types']       = isset( $input['post_types'] ) ? array_map( 'sanitize_key', explode( ',', $input['post_types'] ) ) : $out['post_types'];
		$out['excluded_post_types'] = isset( $input['excluded_post_types'] ) ? array_filter( array_map( 'sanitize_key', explode( ',', $input['excluded_post_types'] ) ) ) : array();
		$out['taxonomies']       = isset( $input['taxonomies'] ) ? array_map( 'sanitize_key', explode( ',', $input['taxonomies'] ) ) : $out['taxonomies'];
		$out['excluded_categories'] = isset( $input['excluded_categories'] ) ? array_values( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', explode( ',', $input['excluded_categories'] ) ) ) ) ) : array();
		$out['bot_name']         = isset( $input['bot_name'] ) ? sanitize_text_field( $input['bot_name'] ) : $out['bot_name'];
		$out['logo_url']         = isset( $input['logo_url'] ) ? esc_url_raw( $input['logo_url'] ) : $out['logo_url'];
		$out['welcome_message']  = isset( $input['welcome_message'] ) ? sanitize_text_field( $input['welcome_message'] ) : $out['welcome_message'];
		$out['placeholder_text'] = isset( $input['placeholder_text'] ) ? sanitize_text_field( $input['placeholder_text'] ) : $out['placeholder_text'];
		$out['primary_color']    = isset( $input['primary_color'] ) ? sanitize_hex_color( $input['primary_color'] ) : $out['primary_color'];
		$out['site_context']     = isset( $input['site_context'] ) ? sanitize_textarea_field( $input['site_context'] ) : $out['site_context'];
		$out['general_info']     = isset( $input['general_info'] ) ? sanitize_textarea_field( $input['general_info'] ) : $out['general_info'];
		$out['auto_inject']      = isset( $input['auto_inject'] ) ? 1 : 0;
		$out['results_limit']    = isset( $input['results_limit'] ) ? absint( $input['results_limit'] ) : $out['results_limit'];
		$out['cache_minutes']    = isset( $input['cache_minutes'] ) ? absint( $input['cache_minutes'] ) : $out['cache_minutes'];
		$out['fallback_message'] = isset( $input['fallback_message'] ) ? sanitize_textarea_field( $input['fallback_message'] ) : $out['fallback_message'];
		return $out;
	}

	public function ajax_test_key() {
		check_ajax_referer( 'aiab_test_key', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Du har ikke tilladelse til at gøre dette.' ) );
		}

		$settings = self::get_settings();
		if ( empty( $settings['api_key'] ) ) {
			wp_send_json_error( array( 'message' => 'Der er i øjeblikket ikke gemt nogen API-nøgle. Indtast en ovenfor, og klik først på "Gem ændringer", og prøv derefter testen igen.' ) );
		}

		$result = AIAB_AI::test_key( $settings );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'API-fejl: ' . $result->get_error_message() ) );
		}

		$provider_label = AIAB_AI::provider_label( $settings['provider'] );
		wp_send_json_success( array( 'message' => 'Succes — API-nøglen virker, og ' . $provider_label . ' model "' . $settings['model'] . '" svarede korrekt.' ) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s           = self::get_settings();
		$docs        = AIAB_Documentation::get_all();
		$links       = AIAB_External_Links::get_all();
		$doc_nonce   = wp_create_nonce( 'aiab_documentation' );
		$link_nonce  = wp_create_nonce( 'aiab_external_links' );
		$has_api_key = ! empty( $s['api_key'] );
		$doc_count   = count( $docs );
		$link_count  = count( $links );
		?>
		<div class="wrap aiab-admin-wrap">
			<div class="aiab-admin-header">
				<div>
					<h1>AI-assistentbot</h1>
					<p>Administrer AI-forbindelse, søgning, chatbot-udseende, dokumentation, eksterne links og svarindstillinger ét sted.</p>
				</div>
				<span class="aiab-version-badge">Version <?php echo esc_html( AIAB_VERSION ); ?></span>
			</div>

			<div class="aiab-admin-layout">
				<div class="aiab-admin-main">
					<h2 class="nav-tab-wrapper aiab-nav-tabs">
						<a href="#aiab-tab-ai" class="nav-tab nav-tab-active" data-tab="aiab-tab-ai">AI &amp; API</a>
						<a href="#aiab-tab-search" class="nav-tab" data-tab="aiab-tab-search">Søgning &amp; indhold</a>
						<a href="#aiab-tab-appearance" class="nav-tab" data-tab="aiab-tab-appearance">Chatbot-design</a>
						<a href="#aiab-tab-docs" class="nav-tab" data-tab="aiab-tab-docs">Dokumentation</a>
						<a href="#aiab-tab-links" class="nav-tab" data-tab="aiab-tab-links">Eksterne links</a>
						<a href="#aiab-tab-advanced" class="nav-tab" data-tab="aiab-tab-advanced">Avanceret</a>
					</h2>

					<form method="post" action="options.php">
						<?php settings_fields( 'aiab_settings_group' ); ?>

						<div id="aiab-tab-ai" class="aiab-tab-panel is-active">
							<div class="aiab-info-banner">
								<p><strong>Gratis kontra betalt:</strong> Google Gemini er standard og tilbyder et gratis abonnement til de fleste forhandlersider. OpenAI ChatGPT er et betalt alternativ med stærkere modeller og højere kapacitet.</p>
							</div>

							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>AI-forbindelse</h2>
									<p>Vælg udbyder, indtast API-nøgle, og test at forbindelsen virker.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="provider">AI-udbyder</label></div>
										<div class="aiab-field__control">
											<select id="provider" name="aiab_settings[provider]">
												<option value="gemini" <?php selected( $s['provider'], 'gemini' ); ?>>Google Gemini — Gratis abonnement</option>
												<option value="openai" <?php selected( $s['provider'], 'openai' ); ?>>OpenAI ChatGPT — Betalt</option>
											</select>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="api_key">API-nøgle</label><p class="description">Gemmes i WordPress-databasen.</p></div>
										<div class="aiab-field__control">
											<input type="password" id="api_key" name="aiab_settings[api_key]" value="<?php echo esc_attr( $s['api_key'] ); ?>" class="regular-text" autocomplete="off">
											<p class="description">
												Gemini: <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com/apikey</a><br>
												ChatGPT: <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a><br>
												Status: <strong><?php echo $has_api_key ? 'Gemt (' . strlen( $s['api_key'] ) . ' tegn)' : 'Ikke gemt endnu'; ?></strong>
											</p>
											<div class="aiab-inline-actions">
												<button type="button" class="button" id="aiab-test-key-btn">Test API-nøgle</button>
												<span id="aiab-test-key-result"></span>
											</div>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="model">Modelnavn</label></div>
										<div class="aiab-field__control">
											<input type="text" id="model" name="aiab_settings[model]" value="<?php echo esc_attr( $s['model'] ); ?>" class="regular-text">
											<p class="description" id="aiab-model-hints">
												Gemini: <code>gemini-2.0-flash</code>, <code>gemini-flash-lite-latest</code><br>
												ChatGPT: <code>gpt-4o-mini</code>, <code>gpt-4o</code>
											</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div id="aiab-tab-search" class="aiab-tab-panel">
							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>Database- og ACF-søgning</h2>
									<p>Styr hvilke indholdstyper og kategorier chatbotten må søge i på hjemmesiden.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field aiab-field--checkbox">
										<div class="aiab-field__label"><label for="search_all_content">Søgeomfang</label></div>
										<div class="aiab-field__control">
											<label><input type="checkbox" id="search_all_content" name="aiab_settings[search_all_content]" value="1" <?php checked( $s['search_all_content'], 1 ); ?>> Søg i hele databasen (alle offentlige indholdstyper + ACF-felter)</label>
											<p class="description">Anbefalet for de fleste sites. Slå fra for manuel styring via feltet nedenfor.</p>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="post_types">Manuelle indlægstyper</label></div>
										<div class="aiab-field__control">
											<input type="text" id="post_types" name="aiab_settings[post_types]" value="<?php echo esc_attr( implode( ',', (array) $s['post_types'] ) ); ?>" class="regular-text" placeholder="produkt,post,blog">
											<p class="description">Kommaseparerede slugs. Bruges kun når "Søg i hele databasen" er slået fra.</p>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="excluded_post_types">Ekskluder indlægstyper</label></div>
										<div class="aiab-field__control">
											<input type="text" id="excluded_post_types" name="aiab_settings[excluded_post_types]" value="<?php echo esc_attr( implode( ',', (array) $s['excluded_post_types'] ) ); ?>" class="regular-text" placeholder="produkt">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="taxonomies">Taksonomier</label></div>
										<div class="aiab-field__control">
											<input type="text" id="taxonomies" name="aiab_settings[taxonomies]" value="<?php echo esc_attr( implode( ',', (array) $s['taxonomies'] ) ); ?>" class="regular-text" placeholder="produktkategori">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="excluded_categories">Ekskluder kategorier</label></div>
										<div class="aiab-field__control">
											<input type="text" id="excluded_categories" name="aiab_settings[excluded_categories]" value="<?php echo esc_attr( implode( ',', (array) $s['excluded_categories'] ) ); ?>" class="regular-text" placeholder="Reservedele,Udlejning">
											<p class="description">Skjuler kategorier fra almindelig browsing, men kontaktpersoner kan stadig vises ved direkte kontaktspørgsmål.</p>
										</div>
									</div>
								</div>
							</div>

							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>Fast information til svar</h2>
									<p>Fakta og instruktioner som chatbotten altid kan bruge — uanset søgeresultater.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="general_info">Generel information</label><p class="description">Åbningstider, adresse, telefon osv.</p></div>
										<div class="aiab-field__control">
											<textarea id="general_info" name="aiab_settings[general_info]" rows="6" class="large-text"><?php echo esc_textarea( $s['general_info'] ); ?></textarea>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="site_context">AI-instruktioner</label><p class="description">Tone, fokus og adfærd.</p></div>
										<div class="aiab-field__control">
											<textarea id="site_context" name="aiab_settings[site_context]" rows="4" class="large-text"><?php echo esc_textarea( $s['site_context'] ); ?></textarea>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="fallback_message">Fallback-besked</label><p class="description">Vises når intet svar findes.</p></div>
										<div class="aiab-field__control">
											<textarea id="fallback_message" name="aiab_settings[fallback_message]" rows="5" class="large-text"><?php echo esc_textarea( $s['fallback_message'] ); ?></textarea>
											<p class="description">Inkluder gerne kontaktoplysninger. Hvis tomt, bruges "Generel information".</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div id="aiab-tab-appearance" class="aiab-tab-panel">
							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>Branding &amp; tekster</h2>
									<p>Tilpas navn, logo, velkomsttekst og farver for chatbotten.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="bot_name">Botnavn</label></div>
										<div class="aiab-field__control">
											<input type="text" id="bot_name" name="aiab_settings[bot_name]" value="<?php echo esc_attr( $s['bot_name'] ); ?>" class="regular-text">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="logo_url">Logo</label></div>
										<div class="aiab-field__control">
											<div class="aiab-logo-picker">
												<div id="aiab-logo-preview" class="aiab-logo-preview<?php echo empty( $s['logo_url'] ) ? ' is-empty' : ''; ?>">
													<?php if ( ! empty( $s['logo_url'] ) ) : ?>
														<img src="<?php echo esc_url( $s['logo_url'] ); ?>" alt="">
													<?php else : ?>
														Intet logo
													<?php endif; ?>
												</div>
												<div>
													<input type="hidden" id="logo_url" name="aiab_settings[logo_url]" value="<?php echo esc_attr( $s['logo_url'] ); ?>">
													<button type="button" class="button" id="aiab-logo-select">Vælg billede</button>
													<button type="button" class="button" id="aiab-logo-remove">Fjern</button>
												</div>
											</div>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="welcome_message">Velkomstoverskrift</label></div>
										<div class="aiab-field__control">
											<input type="text" id="welcome_message" name="aiab_settings[welcome_message]" value="<?php echo esc_attr( $s['welcome_message'] ); ?>" class="regular-text">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="placeholder_text">Søgepladsholder</label></div>
										<div class="aiab-field__control">
											<input type="text" id="placeholder_text" name="aiab_settings[placeholder_text]" value="<?php echo esc_attr( $s['placeholder_text'] ); ?>" class="regular-text">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="primary_color">Primær farve</label></div>
										<div class="aiab-field__control">
											<input type="text" id="primary_color" name="aiab_settings[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>" class="aiab-color-field" data-default-color="#1a73e8">
										</div>
									</div>
								</div>
							</div>

							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>Visning på hjemmesiden</h2>
									<p>Styr om chatbotten vises automatisk eller kun via shortcodes.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field aiab-field--checkbox">
										<div class="aiab-field__label"><label for="auto_inject">Automatisk visning</label></div>
										<div class="aiab-field__control">
											<label><input type="checkbox" id="auto_inject" name="aiab_settings[auto_inject]" value="1" <?php checked( $s['auto_inject'], 1 ); ?>> Vis flydende chatbot på alle sider</label>
											<p class="description">Slå fra for kun at vise via shortcode.</p>
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label">Shortcodes</div>
										<div class="aiab-field__control">
											<div class="aiab-shortcode-box">[ai_assistant_bot]</div>
											<div class="aiab-shortcode-box">[chat_bot_search_bot]</div>
											<p class="description">Første shortcode indsætter hele chatbotten. Anden viser søgeikonet.</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div id="aiab-tab-advanced" class="aiab-tab-panel">
							<div class="aiab-settings-card">
								<div class="aiab-settings-card__header">
									<h2>Ydeevne &amp; grænser</h2>
									<p>Finjuster hvor meget data der hentes og hvor længe svar caches.</p>
								</div>
								<div class="aiab-settings-card__body">
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="results_limit">Maks. emner pr. spørgsmål</label><p class="description">Hvor mange sider/produkter fra jeres WordPress-database der søges igennem pr. spørgsmål (3–20). Påvirker ikke dokumenter eller eksterne links.</p></div>
										<div class="aiab-field__control">
											<input type="number" id="results_limit" name="aiab_settings[results_limit]" value="<?php echo esc_attr( $s['results_limit'] ); ?>" min="3" max="20">
										</div>
									</div>
									<div class="aiab-field">
										<div class="aiab-field__label"><label for="cache_minutes">Cache (minutter)</label></div>
										<div class="aiab-field__control">
											<input type="number" id="cache_minutes" name="aiab_settings[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>" min="0" max="1440">
											<p class="description">0 = ingen cache. Caching sparer API-kald på identiske spørgsmål.</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<div class="aiab-form-actions">
							<?php submit_button( 'Gem ændringer', 'primary', 'submit', false ); ?>
						</div>
					</form>

					<div id="aiab-tab-docs" class="aiab-tab-panel">
						<div class="aiab-settings-card">
							<div class="aiab-settings-card__header">
								<h2>Produkt- og websitedokumentation</h2>
								<p>Upload filer med ekstra produkt- og websiteinformation. Chatbotten søger i dem sammen med database- og ACF-indhold.</p>
							</div>
							<div class="aiab-settings-card__body">
								<div class="aiab-doc-upload-box">
									<input type="file" id="aiab-doc-file" accept=".txt,.md,.markdown,.csv,.html,.htm,.json,.xml,.docx,.pdf,.rtf">
									<button type="button" class="button button-primary" id="aiab-doc-upload-btn">Upload dokumentation</button>
									<span id="aiab-doc-upload-status"></span>
								</div>
								<p class="description">Understøttet: txt, md, csv, html, json, xml, docx, pdf, rtf (maks. 10 MB). PDF-tekst udtrækkes via AI, hvis lokal parsing fejler.</p>

								<div class="aiab-doc-table-wrap">
									<table class="aiab-doc-table" id="aiab-doc-table"<?php echo empty( $docs ) ? ' style="display:none;"' : ''; ?>>
										<thead>
											<tr>
												<th>Filnavn</th>
												<th>Størrelse</th>
												<th>Tekst</th>
												<th>Uploadet</th>
												<th></th>
											</tr>
										</thead>
										<tbody id="aiab-doc-list">
											<?php foreach ( $docs as $doc ) :
												$admin_doc = AIAB_Documentation::format_doc_for_admin( $doc );
											?>
											<tr data-doc-id="<?php echo esc_attr( $admin_doc['id'] ); ?>">
												<td><?php echo esc_html( $admin_doc['name'] ); ?></td>
												<td><?php echo esc_html( size_format( $admin_doc['size'] ) ); ?></td>
												<td><?php echo esc_html( number_format_i18n( $admin_doc['char_count'] ) ); ?> tegn (<?php echo esc_html( $admin_doc['chunk_count'] ); ?> afsnit)</td>
												<td><?php echo esc_html( $admin_doc['uploaded'] ); ?></td>
												<td class="aiab-link-actions">
													<button type="button" class="button aiab-doc-delete" data-id="<?php echo esc_attr( $admin_doc['id'] ); ?>">Slet</button>
												</td>
											</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<input type="hidden" id="aiab-doc-nonce" value="<?php echo esc_attr( $doc_nonce ); ?>">
							</div>
						</div>
					</div>

					<div id="aiab-tab-links" class="aiab-tab-panel">
						<div class="aiab-settings-card">
							<div class="aiab-settings-card__header">
								<h2>Eksterne links</h2>
								<p>Tilføj tredjeparts- eller eksterne websider. Chatbotten henter indholdet og søger i det sammen med hjemmeside, ACF og uploadet dokumentation.</p>
							</div>
							<div class="aiab-settings-card__body">
								<div class="aiab-doc-upload-box aiab-link-add-box">
									<input type="url" id="aiab-link-url" class="regular-text" placeholder="https://example.com/produktinfo">
									<input type="text" id="aiab-link-title" class="regular-text" placeholder="Valgfri titel">
									<button type="button" class="button button-primary" id="aiab-link-add-btn">Tilføj link</button>
									<span id="aiab-link-add-status"></span>
								</div>
								<p class="description">Tilføj en URL og gem med det samme. Når en besøgende stiller et spørgsmål, henter chatbotten automatisk de mest relevante sider fra linket og bruger dem i svaret.</p>

								<div class="aiab-doc-table-wrap">
									<table class="aiab-doc-table" id="aiab-link-table"<?php echo empty( $links ) ? ' style="display:none;"' : ''; ?>>
										<thead>
											<tr>
												<th>Titel / URL</th>
												<th>Status</th>
												<th>Tilføjet</th>
												<th></th>
											</tr>
										</thead>
										<tbody id="aiab-link-list">
											<?php foreach ( $links as $link ) :
												$admin_link = AIAB_External_Links::format_link_for_admin( $link );
											?>
											<tr data-link-id="<?php echo esc_attr( $admin_link['id'] ); ?>">
												<td>
													<strong><?php echo esc_html( $admin_link['title'] ); ?></strong><br>
													<a href="<?php echo esc_url( $admin_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $admin_link['url'] ); ?></a>
												</td>
												<td><?php echo esc_html( $admin_link['status_label'] ); ?></td>
												<td><?php echo esc_html( $admin_link['fetched'] ); ?></td>
												<td class="aiab-link-actions">
													<button type="button" class="button aiab-link-delete" data-id="<?php echo esc_attr( $admin_link['id'] ); ?>">Slet</button>
												</td>
											</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<input type="hidden" id="aiab-link-nonce" value="<?php echo esc_attr( $link_nonce ); ?>">
							</div>
						</div>
					</div>
				</div>

				<aside class="aiab-admin-sidebar">
					<div class="aiab-sidebar-card">
						<h3>Status</h3>
						<ul class="aiab-sidebar-list">
							<li><span>API-nøgle</span><strong><?php echo $has_api_key ? 'Gemt' : 'Mangler'; ?></strong></li>
							<li><span>Udbyder</span><strong><?php echo esc_html( AIAB_AI::provider_label( $s['provider'] ) ); ?></strong></li>
							<li><span>Model</span><strong><?php echo esc_html( $s['model'] ); ?></strong></li>
							<li><span>Dokumenter</span><strong><?php echo esc_html( (string) $doc_count ); ?></strong></li>
							<li><span>Eksterne links</span><strong><?php echo esc_html( (string) $link_count ); ?></strong></li>
							<li><span>Cache</span><strong><?php echo (int) $s['cache_minutes'] > 0 ? (int) $s['cache_minutes'] . ' min' : 'Fra'; ?></strong></li>
						</ul>
					</div>

					<div class="aiab-sidebar-card">
						<h3>Hurtig kontrol</h3>
						<p class="description">Efter du ændrer API-nøgle eller model, skal du klikke <strong>Gem ændringer</strong> og derefter teste forbindelsen under fanen "AI &amp; API".</p>
						<p>
							<span class="aiab-status-pill <?php echo $has_api_key ? 'is-success' : 'is-warning'; ?>">
								<?php echo $has_api_key ? 'API konfigureret' : 'API mangler'; ?>
							</span>
						</p>
					</div>

					<div class="aiab-sidebar-card">
						<h3>Shortcodes</h3>
						<div class="aiab-shortcode-box">[ai_assistant_bot]</div>
						<div class="aiab-shortcode-box">[chat_bot_search_bot]</div>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}
