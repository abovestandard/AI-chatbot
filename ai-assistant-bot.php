<?php

/**

 * Plugin Name: AI Assistant Bot

 * Description: An AI-powered search assistant that answers visitor questions using your products, product categories, and blog content. Supports Gemini (free) and ChatGPT.

 * Version: 1.5.8

 * Author: Custom Build

 * Text Domain: ai-assistant-bot

 */



if ( ! defined( 'ABSPATH' ) ) {

	exit; // No direct access.

}

echo "Hello from AI Assistant Bot!1";

define( 'AIAB_VERSION', '1.5.8' );

define( 'AIAB_PATH', plugin_dir_path( __FILE__ ) );

define( 'AIAB_URL', plugin_dir_url( __FILE__ ) );



require_once AIAB_PATH . 'includes/class-aiab-settings.php';

if ( file_exists( AIAB_PATH . 'vendor/smalot/pdfparser/autoload.php' ) ) {

	require_once AIAB_PATH . 'vendor/smalot/pdfparser/autoload.php';

}

require_once AIAB_PATH . 'includes/class-aiab-documentation.php';

require_once AIAB_PATH . 'includes/class-aiab-external-links.php';

require_once AIAB_PATH . 'includes/class-aiab-search.php';

require_once AIAB_PATH . 'includes/class-aiab-prompt.php';

require_once AIAB_PATH . 'includes/class-aiab-gemini.php';

require_once AIAB_PATH . 'includes/class-aiab-openai.php';

require_once AIAB_PATH . 'includes/class-aiab-ai.php';

require_once AIAB_PATH . 'includes/class-aiab-rest.php';

require_once AIAB_PATH . 'includes/class-aiab-frontend.php';

require_once AIAB_PATH . 'includes/class-aiab-block.php';



/**

 * Boot the plugin.

 */

function aiab_init() {

	new AIAB_Settings();

	new AIAB_Documentation();

	new AIAB_External_Links();

	new AIAB_Rest();

	new AIAB_Frontend();

	new AIAB_Block();

}

add_action( 'plugins_loaded', 'aiab_init' );



/**

 * Default options on activation.

 */

function aiab_activate() {

	$defaults = array(

		'provider'         => 'gemini',

		'api_key'          => '',

		'model'            => 'gemini-2.0-flash',

		'search_all_content' => 1,

		'post_types'       => array( 'product', 'post' ),

		'taxonomies'       => array( 'product_cat' ),

		'bot_name'         => 'AI Assistant',

		'logo_url'         => '',

		'welcome_message'  => 'Hvad kan vi hjælpe dig med i dag?',

		'placeholder_text' => 'Spørg om maskiner, service, reservedele eller finansiering...',

		'primary_color'    => '#1a73e8',

		'site_context'     => '',

		'auto_inject'      => 1,

		'results_limit'    => 8,

		'cache_minutes'    => 60,

		'fallback_message' => '',

	);

	if ( ! get_option( 'aiab_settings' ) ) {

		add_option( 'aiab_settings', $defaults );

	}

	AIAB_Documentation::ensure_upload_dir();

}

register_activation_hook( __FILE__, 'aiab_activate' );

