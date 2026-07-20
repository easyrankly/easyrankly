import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
	new URL( '../../assets/js/editor-shared.js', import.meta.url ),
	'utf8',
);

function sprintf( template, ...values ) {
	return values.reduce(
		( output, value, index ) => output.replace( `%${ index + 1 }$s`, String( value ) ),
		template,
	);
}

function loadEditorShared() {
	const noop = () => {};
	const window = {};
	const components = new Proxy( {}, { get: ( target, property ) => property } );
	const wp = {
		blockEditor: components,
		components,
		data: { useSelect: noop },
		element: {
			Fragment: 'Fragment',
			createElement: ( type, props, ...children ) => ( { children, props, type } ),
			useEffect: noop,
			useRef: ( value ) => ( { current: value } ),
			useState: ( value ) => [ value, noop ],
		},
		i18n: {
			__: ( value ) => value,
			sprintf,
		},
	};

	vm.runInNewContext( source, { Set, String, window, wp } );

	return window.eranklyShared;
}

const shared = loadEditorShared();

test( 'robots suggestions expand as soon as the token field receives focus', () => {
	const data = {
		get: ( key ) => ( {
			archive_directive: 'archive',
			follow_directive: 'nofollow',
			image_directive: 'imageindex',
			index_directive: 'index',
			snippet_directive: 'snippet',
		} )[ key ],
		set: () => {},
	};
	const fields = shared.visibilityFields( {
		config: { simplifiedMode: false },
		data,
		features: { disableSitemap: false, triStateRobots: true },
	} );
	const robotsControl = fields[ 0 ].type( fields[ 0 ].props );
	const tokenField = robotsControl.children[ 0 ];

	assert.equal( tokenField.type, 'FormTokenField' );
	assert.equal( tokenField.props.__experimentalExpandOnFocus, true );
} );

test( 'robots tokens are normalized and opposite rules replace one another', () => {
	assert.equal( shared.normalizeRobotsDirectiveToken( '  NoIndex  ' ), 'noindex' );
	assert.equal( shared.normalizeRobotsDirectiveToken( { value: 'FOLLOW' } ), 'follow' );

	assert.deepEqual(
		{ ...shared.selectRobotsDirectiveToken( [], 'index', [ 'index', 'noindex' ] ) },
		{ conflict: false, value: 'inherit' },
	);
	assert.deepEqual(
		{ ...shared.selectRobotsDirectiveToken( [ 'index', 'noindex' ], 'index', [ 'index', 'noindex' ] ) },
		{ conflict: true, value: 'noindex' },
	);
	assert.deepEqual(
		{ ...shared.selectRobotsDirectiveToken( [ 'noindex', 'index' ], 'noindex', [ 'index', 'noindex' ] ) },
		{ conflict: true, value: 'index' },
	);
} );

test( 'one robots field preserves compatible tags and replaces only their opposite', () => {
	const directives = [
		{ allow: 'index', deny: 'noindex', key: 'index_directive' },
		{ allow: 'follow', deny: 'nofollow', key: 'follow_directive' },
		{ allow: 'archive', deny: 'noarchive', key: 'archive_directive' },
		{ allow: 'snippet', deny: 'nosnippet', key: 'snippet_directive' },
		{ allow: 'imageindex', deny: 'noimageindex', key: 'image_directive' },
	];
	const current = {
		archive_directive: 'archive',
		follow_directive: 'follow',
		image_directive: 'imageindex',
		index_directive: 'index',
		snippet_directive: 'snippet',
	};
	const result = shared.resolveRobotsDirectiveTokens(
		[ 'index', 'follow', 'archive', 'snippet', 'imageindex', 'noindex' ],
		current,
		directives,
	);

	assert.deepEqual(
		{ ...result.selections },
		{
			archive_directive: 'archive',
			follow_directive: 'follow',
			image_directive: 'imageindex',
			index_directive: 'noindex',
			snippet_directive: 'snippet',
		},
	);
	assert.deepEqual(
		Array.from( result.conflicts, ( conflict ) => ( { ...conflict } ) ),
		[ { current: 'index', key: 'index_directive', value: 'noindex' } ],
	);
} );

test( 'robots inconsistencies are reported as soon as settings conflict', () => {
	assert.deepEqual(
		Array.from( shared.getRobotsDirectiveInconsistencies( {
			image: 'noimageindex',
			index: 'index',
			indexIfEmbedded: true,
			maxImagePreview: 'large',
			maxSnippet: '120',
			snippet: 'nosnippet',
		} ) ),
		[
			'nosnippet_max_snippet',
			'noimageindex_max_image_preview',
			'index_indexifembedded',
		],
	);

	assert.deepEqual(
		Array.from( shared.getRobotsDirectiveInconsistencies( {
			image: 'imageindex',
			index: 'noindex',
			indexIfEmbedded: true,
			maxImagePreview: 'large',
			maxSnippet: 0,
			snippet: 'snippet',
		} ) ),
		[ 'snippet_zero' ],
	);
} );
