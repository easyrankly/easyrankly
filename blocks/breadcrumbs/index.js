( function () {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;

	registerBlockType( 'easyrankly/breadcrumbs', {
		edit: function EditBreadcrumbs() {
			const blockProps = useBlockProps( {
				className: 'erankly-breadcrumbs-editor',
			} );

			return el(
				'nav',
				blockProps,
				el(
					'p',
					{ className: 'erankly-breadcrumbs-editor__hint' },
					__( 'Breadcrumb trail. The visible path is rendered on the front end.', 'easyrankly' )
				)
			);
		},
		save: function SaveBreadcrumbs() {
			return null;
		},
	} );
}() );
