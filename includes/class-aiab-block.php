<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AIAB_Block {

	public function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block() {
		wp_register_script(
			'aiab-search-trigger-block',
			AIAB_URL . 'assets/js/block-search-trigger.js',
			array( 'wp-blocks', 'wp-element', 'wp-i18n' ),
			AIAB_VERSION,
			true
		);

		register_block_type( 'aiab/search-trigger', array(
			'editor_script'   => 'aiab-search-trigger-block',
			'render_callback' => array( $this, 'render' ),
		) );
	}

	public function render( $attributes = array(), $content = '' ) {
		return do_shortcode( '[chat_bot_search_bot]' );
	}
}