import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
	new URL( '../../assets/js/admin-fields.js', import.meta.url ),
	'utf8',
);

function createClassList() {
	const values = new Set();

	return {
		add( value ) {
			values.add( value );
		},
		contains( value ) {
			return values.has( value );
		},
		remove( value ) {
			values.delete( value );
		},
		toggle( value, force ) {
			if ( force ) {
				values.add( value );
			} else {
				values.delete( value );
			}
		},
	};
}

function createNode( overrides = {} ) {
	const listeners = new Map();
	const attributes = new Map();
	const children = [];
	let html = '';

	return {
		children,
		classList: createClassList(),
		files: [],
		value: '',
		addEventListener( type, callback ) {
			listeners.set( type, [ ...( listeners.get( type ) || [] ), callback ] );
		},
		appendChild( child ) {
			children.push( child );
		},
		dispatch( type, event = {} ) {
			for ( const callback of listeners.get( type ) || [] ) {
				callback( {
					preventDefault() {},
					...event,
				} );
			}
		},
		getAttribute( name ) {
			return attributes.has( name ) ? attributes.get( name ) : null;
		},
		setAttribute( name, value ) {
			attributes.set( name, String( value ) );
		},
		get innerHTML() {
			return html;
		},
		set innerHTML( value ) {
			html = value;
			children.length = 0;
		},
		...overrides,
	};
}

function loadAdminFields( document ) {
	const window = { ERanklyAdmin: {} };
	vm.runInNewContext( source, { document, parseInt, window } );
	return window.ERanklyAdmin;
}

test( 'character counter updates text and warning state', () => {
	const counter = createNode();
	const field = createNode( { value: 'Hello' } );
	field.setAttribute( 'data-erankly-limit', '5' );
	field.setAttribute( 'data-erankly-counter', 'title-counter' );
	field.setAttribute( 'data-erankly-warning', 'Too long' );
	const ER = loadAdminFields( {
		getElementById: ( id ) => ( 'title-counter' === id ? counter : null ),
	} );

	ER.bindCharacterCounter( field );
	assert.equal( counter.textContent, '5/5' );
	assert.equal( counter.classList.contains( 'is-warning' ), false );

	field.value = 'Hello!';
	field.dispatch( 'input' );
	assert.equal( counter.textContent, '6/5 - Too long' );
	assert.equal( counter.classList.contains( 'is-warning' ), true );
} );

test( 'file dropzone exposes the dropped filename and clears drag state', () => {
	const input = createNode();
	const text = createNode();
	text.innerHTML = '<strong>Choose a file</strong>';
	const dropzone = createNode( {
		querySelector( selector ) {
			return selector.includes( 'input' ) ? input : text;
		},
	} );
	const ER = loadAdminFields( {
		createElement: () => createNode(),
	} );

	ER.bindFileDropzone( dropzone );
	dropzone.dispatch( 'dragover' );
	assert.equal( dropzone.classList.contains( 'is-dragover' ), true );

	dropzone.dispatch( 'drop', {
		dataTransfer: { files: [ { name: 'migration.json' } ] },
	} );
	assert.equal( dropzone.classList.contains( 'is-dragover' ), false );
	assert.equal( text.children.length, 1 );
	assert.equal( text.children[ 0 ].textContent, 'migration.json' );
	assert.equal( text.children[ 0 ].className, 'erankly-dropzone-filename' );
} );
