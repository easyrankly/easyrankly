/**
 * Shared JSON-LD validation for the settings screen and the block editor.
 * Mirrors erankly_validate_custom_json_ld() in includes/schema-jsonld.php.
 */
( function ( root ) {
	'use strict';

	var i18n = root.wp && root.wp.i18n ? root.wp.i18n : null;

	function __( text ) {
		return i18n && typeof i18n.__ === 'function' ? i18n.__( text, 'easyrankly' ) : text;
	}

	function sprintf() {
		if ( i18n && typeof i18n.sprintf === 'function' ) {
			return i18n.sprintf.apply( i18n, arguments );
		}

		var args = Array.prototype.slice.call( arguments );
		var text = String( args.shift() || '' );

		return text.replace( /%([sd])/g, function () {
			return String( args.shift() || '' );
		} );
	}

	function isPlainObject( value ) {
		return !! value && typeof value === 'object' && ! Array.isArray( value );
	}

	function isValidTypeName( type ) {
		var trimmed = String( type || '' ).trim();

		if ( trimmed === '' ) {
			return false;
		}

		if ( /^[A-Za-z][A-Za-z0-9._:-]*$/.test( trimmed ) ) {
			return true;
		}

		try {
			var url = new URL( trimmed );
			return url.protocol === 'http:' || url.protocol === 'https:';
		} catch ( error ) {
			return false;
		}
	}

	function hasValidType( node ) {
		var type = node[ '@type' ];

		if ( typeof type === 'string' ) {
			return isValidTypeName( type );
		}

		if ( ! Array.isArray( type ) || type.length === 0 ) {
			return false;
		}

		return type.every( function ( item ) {
			return typeof item === 'string' && isValidTypeName( item );
		} );
	}

	function hasValidId( node ) {
		return typeof node[ '@id' ] === 'string' && node[ '@id' ].trim() !== '';
	}

	function nodeError( node, index ) {
		/* translators: %d: 1-based node index. */
		var label = sprintf( __( 'Node %d', 'easyrankly' ), index + 1 );
		var keys;

		if ( ! isPlainObject( node ) ) {
			return sprintf(
				/* translators: %s: node label such as "Node 1". */
				__( '%s must be a JSON object.', 'easyrankly' ),
				label
			);
		}

		keys = Object.keys( node );

		if ( keys.length === 0 || ( keys.length === 1 && keys[ 0 ] === '@context' ) ) {
			return sprintf(
				/* translators: %s: node label such as "Node 1". */
				__( '%s is empty and is not valid JSON-LD.', 'easyrankly' ),
				label
			);
		}

		if ( Object.prototype.hasOwnProperty.call( node, '@type' ) && ! hasValidType( node ) ) {
			return sprintf(
				/* translators: %s: node label such as "Node 1". */
				__( '%s has an invalid @type. Use a non-empty type name or a list of type names.', 'easyrankly' ),
				label
			);
		}

		if ( Object.prototype.hasOwnProperty.call( node, '@id' ) && ! hasValidId( node ) ) {
			return sprintf(
				/* translators: %s: node label such as "Node 1". */
				__( '%s has an invalid @id. Use a non-empty string.', 'easyrankly' ),
				label
			);
		}

		if ( ! hasValidType( node ) && ! hasValidId( node ) ) {
			return sprintf(
				/* translators: %s: node label such as "Node 1". */
				__( '%s needs a valid @type or @id to be JSON-LD.', 'easyrankly' ),
				label
			);
		}

		return '';
	}

	function validateNodes( nodes ) {
		var index;
		var error;

		if ( ! Array.isArray( nodes ) || nodes.length === 0 ) {
			return {
				valid: false,
				code: 'structure',
				message: __( 'JSON-LD must contain at least one node.', 'easyrankly' ),
			};
		}

		for ( index = 0; index < nodes.length; index += 1 ) {
			error = nodeError( nodes[ index ], index );

			if ( error ) {
				return {
					valid: false,
					code: 'semantic',
					message: error,
				};
			}
		}

		return {
			valid: true,
			code: '',
			message: '',
		};
	}

	function validate( value ) {
		var text = String( value || '' ).replace( /{{\s*[a-z0-9_]+\s*}}/gi, 'x' ).trim();
		var parsed;

		if ( text === '' ) {
			return {
				valid: true,
				code: '',
				message: '',
			};
		}

		try {
			parsed = JSON.parse( text );
		} catch ( error ) {
			return {
				valid: false,
				code: 'syntax',
				message: __( 'This is not valid JSON, so it cannot be used as JSON-LD.', 'easyrankly' ),
			};
		}

		if ( Array.isArray( parsed ) ) {
			return validateNodes( parsed );
		}

		if ( ! isPlainObject( parsed ) ) {
			return {
				valid: false,
				code: 'structure',
				message: __( 'JSON-LD must be an object, an array of objects, or an object with @graph.', 'easyrankly' ),
			};
		}

		if ( Object.prototype.hasOwnProperty.call( parsed, '@graph' ) ) {
			if ( ! Array.isArray( parsed[ '@graph' ] ) ) {
				return {
					valid: false,
					code: 'structure',
					message: __( '@graph must be an array of JSON-LD nodes.', 'easyrankly' ),
				};
			}

			return validateNodes( parsed[ '@graph' ] );
		}

		return validateNodes( [ parsed ] );
	}

	root.eranklyJsonLd = {
		validate: validate,
		isValid: function ( value ) {
			return validate( value ).valid;
		},
	};
}( window ) );
