<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIAB_Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'maybe_auto_inject' ) );
		add_shortcode( 'ai_assistant_bot', array( $this, 'shortcode' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_chat_page' ) );
		add_shortcode( 'chat_bot_search_bot', array( $this, 'chat_bot_search_bot_shortcode' ) );
	}
	
	public function chat_bot_search_bot_shortcode()
	{
		$plugin_url = plugins_url('assets/image/', dirname(__FILE__));
		$url  = esc_url( self::chat_url() );
		return sprintf(
			'<div class="custom-absd-search-widget">
				<a href="'.$url.'">
					<div class="search-bar">
						<img src="%s" class="search-icon-img" alt="Search">
						<span>Spørg mig</span>
					</div>
					<img src="/wp-content/uploads/2026/10/ChatGPT-Image-Sep-10-2026-04_03_00-PM-1.png" class="search-bot-gmc-img" alt="Search Bot">
				</a>
			</div>',
			esc_url($plugin_url . 'search-icon-img.svg'),
			esc_url($plugin_url . 'search-bot-gmc-img.png')
		);
	}
	
	/**
	 * URL for the standalone, full-page chat experience.
	 */
	public static function chat_url() {
		return add_query_arg( 'aiab_chat', '1', home_url( '/' ) );
	}

	/**
	 * If the special ?aiab_chat=1 query var is present, render the full
	 * chat page ourselves (no theme header/footer) and stop WP.
	 */
	public function maybe_render_chat_page() {
		if ( empty( $_GET['aiab_chat'] ) ) {
			return;
		}
		$this->render_chat_page();
		exit;
	}

	private function render_chat_page() {
		$settings = AIAB_Settings::get_settings();

		wp_enqueue_style( 'aiab-widget', AIAB_URL . 'assets/css/widget.css', array(), AIAB_VERSION );
		wp_enqueue_script( 'aiab-widget', AIAB_URL . 'assets/js/widget.js', array(), AIAB_VERSION, true );
		wp_localize_script( 'aiab-widget', 'AIAB_DATA', $this->localized_data( $settings ) );

		nocache_headers();
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html( $settings['bot_name'] ); ?> &mdash; <?php bloginfo( 'name' ); ?></title>
	<?php wp_print_styles( 'aiab-widget' ); ?>
	<style>html,body{margin:0;height:100%;background:#eef0f3;}</style>
</head>
<body>
	<?php echo $this->widget_markup( 'page' ); ?>
	<?php wp_print_scripts( 'aiab-widget' ); ?>
</body>
</html>
		<?php
	}

	public function admin_enqueue( $hook ) {
		// Admin assets for the settings page are loaded by AIAB_Settings.
	}

	public function enqueue() {
		wp_enqueue_style( 'aiab-widget', AIAB_URL . 'assets/css/widget.css', array(), AIAB_VERSION );
		wp_enqueue_script( 'aiab-widget', AIAB_URL . 'assets/js/widget.js', array(), AIAB_VERSION, true );

		$settings = AIAB_Settings::get_settings();
		wp_localize_script( 'aiab-widget', 'AIAB_DATA', $this->localized_data( $settings ) );
	}

	private function localized_data( $settings ) {
		return array(
			'restUrl'         => esc_url_raw( rest_url( 'aiab/v1/ask' ) ),
			'chatUrl'         => esc_url_raw( self::chat_url() ),
			'botName'         => $settings['bot_name'],
			'welcomeMessage'  => $settings['welcome_message'],
			'placeholderText' => $settings['placeholder_text'],
			'primaryColor'    => $settings['primary_color'],
			'logoUrl'         => $settings['logo_url'],
			// Kept for any old cached markup that still reads these directly;
			// the widget now picks per-turn labels from `strings` below,
			// keyed by the language the AI detected for that specific turn.
			'newChatText'     => __( 'Ny chat', 'ai-assistant-bot' ),
			'thinkingText'    => __( 'Tænker...', 'ai-assistant-bot' ),
			'errorText'       => __( 'Det var ikke muligt at få fat i assistenten. Prøv venligst igen.', 'ai-assistant-bot' ),
			'seeDetailsText'  => __( 'Se detaljer', 'ai-assistant-bot' ),
			'seeMoreText'     => __( 'Se mere', 'ai-assistant-bot' ),
			// Bilingual chrome labels for the widget. The AI reports which
			// language it answered a given question in (data.lang, "en" or
			// "da"), and widget.js picks its section headings / buttons /
			// loading text from the matching set below, per turn — so an
			// English question gets an all-English answer card even on a
			// Danish site, and vice versa, without needing two separate
			// plugin installs or a manual language switch.
			'strings'         => array(
				'en' => array(
					'newChat'      => __( 'Ny chat', 'ai-assistant-bot' ),
					'thinking'     => __( 'Tænker...', 'ai-assistant-bot' ),
					'error'        => __( 'Det var ikke muligt at få fat i assistenten. Prøv venligst igen.', 'ai-assistant-bot' ),
					'seeDetails'   => __( 'Se detaljer', 'ai-assistant-bot' ),
					'seeMore'      => __( 'Se mere', 'ai-assistant-bot' ),
					'noMatch'      => __( 'Der blev ikke fundet noget relevant indhold. Prøv at omformulere dit spørgsmål.', 'ai-assistant-bot' ),
					'genericError' => __( 'Beklager, der opstod en fejl under udarbejdelsen af svaret. Prøv venligst at stille spørgsmålet igen.', 'ai-assistant-bot' ),
					'products'     => __( 'Anbefalede modeller', 'ai-assistant-bot' ),
					'contact'      => __( 'Kontaktperson', 'ai-assistant-bot' ),
					'call'         => __( 'Ring', 'ai-assistant-bot' ),
					'email'        => __( 'Send e-mail', 'ai-assistant-bot' ),
					'sources'      => __( 'Information fra', 'ai-assistant-bot' ),
					'sourceWebsite' => __( 'Hjemmeside', 'ai-assistant-bot' ),
					'sourceDocumentation' => __( 'Dokumentation', 'ai-assistant-bot' ),
					'sourceExternal' => __( 'Eksternt link', 'ai-assistant-bot' ),
				),
				'da' => array(
					'newChat'      => 'Ny samtale',
					'thinking'     => 'Tænker...',
					'error'        => 'Kunne ikke få kontakt til assistenten. Prøv igen.',
					'seeDetails'   => 'Se detaljer',
					'seeMore'      => 'Se mere',
					'noMatch'      => 'Intet relevant indhold fundet. Prøv at omformulere dit spørgsmål.',
					'genericError' => 'Beklager, der gik noget galt. Prøv at spørge igen.',
					'products'     => 'Anbefalede modeller',
					'contact'      => 'Kontaktperson',
					'call'         => 'Ring',
					'email'        => 'Send e-mail',
					'sources'      => 'Information fra',
					'sourceWebsite' => 'Hjemmeside',
					'sourceDocumentation' => 'Dokumentation',
					'sourceExternal' => 'Eksternt link',
				),
			),
		);
	}

	/**
	 * The floating button on every page now opens the full chat page,
	 * rather than toggling an in-page popup.
	 */
	public function maybe_auto_inject() {
		$settings = AIAB_Settings::get_settings();
		if ( ! empty( $settings['auto_inject'] ) ) {
			echo $this->trigger_markup( $settings );
		}
	}

	public function shortcode() {
		return $this->widget_markup( 'inline' );
	}

	private function trigger_markup( $settings ) {
		ob_start();
		?>
		<a class="aiab-trigger-link" href="<?php echo esc_url( self::chat_url() ); ?>" style="--aiab-primary: <?php echo esc_attr( $settings['primary_color'] ); ?>;" aria-label="<?php echo esc_attr( sprintf( __( 'Open %s', 'ai-assistant-bot' ), $settings['bot_name'] ) ); ?>">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12c0 4.418-4.03 8-9 8-1.06 0-2.077-.16-3.02-.457L3 21l1.5-4.5C3.55 15.02 3 13.56 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
		</a>
		<?php
		return ob_get_clean();
	}

	/**
	 * Builds the chat UI markup.
	 *
	 * @param string $mode 'page' (standalone full page) or 'inline' (shortcode embed).
	 */
	private function widget_markup( $mode = 'inline' ) {
		$settings = AIAB_Settings::get_settings();
		ob_start();
		?>
		<div class="aiab-root aiab-<?php echo esc_attr( $mode ); ?>" style="--aiab-primary: <?php echo esc_attr( $settings['primary_color'] ); ?>;">
			<div class="aiab-shell">

				<?php if ( $mode === 'page' ) : ?>
					<a class="aiab-close" href="<?php echo esc_url( home_url( '/' ) ); ?>" data-aiab-back aria-label="<?php esc_attr_e( 'Close', 'ai-assistant-bot' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
					  <path d="M18 6L6.00081 17.9992M17.9992 18L6 6.00085" stroke="#0C0E14" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</a>
				<?php endif; ?>

				<div class="aiab-header" hidden>
					<div class="aiab-header-left">
						<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
							<img class="aiab-logo aiab-logo-sm" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="">
						<?php else : ?>
							<div class="aiab-logo-placeholder aiab-logo-sm">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							</div>
						<?php endif; ?>
						<span class="aiab-bot-name"><?php echo esc_html( $settings['bot_name'] ); ?></span>
					</div>
					<button type="button" class="aiab-newchat-btn">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
						<?php esc_html_e( 'Ny chat', 'ai-assistant-bot' ); ?>
					</button>
				</div>

				<div class="aiab-view aiab-view-search">
					<div class="aiab-search-inner">
						<?php if ( ! empty( $settings['logo_url'] ) ) : ?>
							<img class="aiab-logo aiab-logo-lg" src="<?php echo esc_url( $settings['logo_url'] ); ?>" alt="<?php echo esc_attr( $settings['bot_name'] ); ?>">
						<?php else : ?>
							<div class="aiab-logo-placeholder aiab-logo-lg">
								<svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							</div>
						<?php endif; ?>
						<h2 class="aiab-heading"><?php echo esc_html( $settings['welcome_message'] ); ?></h2>
					</div>
						<form class="aiab-form">
					<svg class="aiab-form-search-ico" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30" fill="none">
					  <path d="M20 20.625L24.9999 25.625" stroke="#0C0E14" stroke-width="1.875" stroke-linecap="round" stroke-linejoin="round"/>
					  <path d="M22.5 14.375C22.5 19.2075 18.5825 23.125 13.75 23.125C8.9175 23.125 4.99998 19.2075 4.99998 14.375C4.99998 9.54251 8.9175 5.625 13.75 5.625" stroke="#0C0E14" stroke-width="1.875" stroke-linecap="round" stroke-linejoin="round"/>
					  <path d="M19.375 4.375L19.6974 5.24629C20.1201 6.38877 20.3315 6.96002 20.7483 7.37674C21.165 7.79346 21.7362 8.00484 22.8787 8.4276L23.75 8.75L22.8787 9.0724C21.7362 9.49516 21.165 9.70655 20.7483 10.1233C20.3315 10.54 20.1201 11.1112 19.6974 12.2537L19.375 13.125L19.0526 12.2537C18.6299 11.1112 18.4185 10.54 18.0017 10.1233C17.585 9.70655 17.0137 9.49516 15.8712 9.0724L15 8.75L15.8712 8.4276C17.0137 8.00484 17.585 7.79346 18.0017 7.37674C18.4185 6.96002 18.6299 6.38877 19.0526 5.24629L19.375 4.375Z" stroke="#0C0E14" stroke-width="1.875" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<input type="text" class="aiab-input" placeholder="<?php echo esc_attr( $settings['placeholder_text'] ); ?>" autocomplete="off">
					<button type="button" class="aiab-mic" aria-label="<?php esc_attr_e( 'Voice input', 'ai-assistant-bot' ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none">
  <path d="M16.9998 6.99902V10.999C16.9998 13.7604 14.7612 15.999 11.9998 15.999C9.2384 15.999 6.99982 13.7604 6.99982 10.999V6.99902C6.99982 4.2376 9.2384 1.99902 11.9998 1.99902C14.7612 1.99902 16.9998 4.2376 16.9998 6.99902Z" stroke="#0C0E14" stroke-width="1.5"/>
  <path d="M19.9998 10.999C19.9998 15.4173 16.4181 18.999 11.9998 18.999M11.9998 18.999C7.58154 18.999 3.99982 15.4173 3.99982 10.999M11.9998 18.999V21.999M11.9998 21.999H14.9998M11.9998 21.999H8.99982" stroke="#0C0E14" stroke-width="1.5" stroke-linecap="round"/>
</svg>
					</button>
				</form>
				</div>

				<div class="aiab-view aiab-view-conversation" hidden>
					<div class="aiab-conversation-scroll"></div>
				</div>

			
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
