( function ( blocks, element, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'aiab/search-trigger', {
		title: __( 'AI Assistant Search Bar', 'ai-assistant-bot' ),
		description: __( 'Shows the search icon/button that links to the AI Assistant chat.', 'ai-assistant-bot' ),
		icon: 'search',
		category: 'widgets',
		supports: { html: false, multiple: true },

		edit: function () {
			return el(
				'div',
				{
					className: 'aiab-search-trigger-block-placeholder',
					style: {
						display: 'inline-flex',
						alignItems: 'center',
						gap: '8px',
						border: '1px dashed #999',
						borderRadius: '4px',
						padding: '8px 14px',
						color: '#555',
						fontSize: '13px'
					}
				},
				__( 'AI Assistant search bar (renders on the live site)', 'ai-assistant-bot' )
			);
		},

		save: function () {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n );