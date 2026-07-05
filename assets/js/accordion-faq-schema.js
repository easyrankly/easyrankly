/* global wp */
( function () {
	'use strict';

	const { createHigherOrderComponent } = wp.compose;
	const { InspectorControls } = wp.blockEditor;
	const { PanelBody, ToggleControl } = wp.components;
	const { createElement: el, Fragment } = wp.element;
	const { addFilter } = wp.hooks;
	const { __ } = wp.i18n;

	const BLOCK_NAME = 'core/accordion';
	const ATTRIBUTE = 'eranklyGenerateFaqSchema';

	addFilter(
		'blocks.registerBlockType',
		'easyrankly/accordion-faq-attribute',
		( settings, name ) => {
			if ( BLOCK_NAME !== name ) {
				return settings;
			}

			return {
				...settings,
				attributes: {
					...settings.attributes,
					[ ATTRIBUTE ]: {
						type: 'boolean',
						default: false,
					},
				},
			};
		}
	);

	const withAccordionFaqControl = createHigherOrderComponent( ( BlockEdit ) => {
		return ( props ) => {
			if ( BLOCK_NAME !== props.name ) {
				return el( BlockEdit, props );
			}

			const { attributes, setAttributes } = props;

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'EasyRankly', 'easyrankly' ),
							initialOpen: false,
						},
						el( ToggleControl, {
							label: __( 'Generate FAQ schema', 'easyrankly' ),
							help: __(
								'Output FAQPage JSON-LD from accordion titles and panel content.',
								'easyrankly'
							),
							checked: !! attributes[ ATTRIBUTE ],
							onChange: ( value ) => setAttributes( { [ ATTRIBUTE ]: value } ),
						} )
					)
				)
			);
		};
	}, 'withAccordionFaqControl' );

	addFilter( 'editor.BlockEdit', 'easyrankly/accordion-faq-control', withAccordionFaqControl );
}() );
