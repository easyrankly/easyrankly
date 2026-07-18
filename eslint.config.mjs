const browserGlobals = {
	AbortController: 'readonly',
	cancelAnimationFrame: 'readonly',
	clearTimeout: 'readonly',
	console: 'readonly',
	document: 'readonly',
	DOMParser: 'readonly',
	Event: 'readonly',
	fetch: 'readonly',
	history: 'readonly',
	MutationObserver: 'readonly',
	navigator: 'readonly',
	requestAnimationFrame: 'readonly',
	setTimeout: 'readonly',
	URL: 'readonly',
	window: 'readonly',
};

export default [
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
			globals: {
				...browserGlobals,
			},
		},
		rules: {
			'no-constant-condition': 'error',
			'no-dupe-args': 'error',
			'no-dupe-else-if': 'error',
			'no-dupe-keys': 'error',
			'no-func-assign': 'error',
			'no-import-assign': 'error',
			'no-obj-calls': 'error',
			'no-redeclare': 'error',
			'no-self-assign': 'error',
			'no-unreachable': 'error',
			'no-unsafe-finally': 'error',
			'no-unused-vars': [
				'error',
				{
					args: 'after-used',
					caughtErrors: 'none',
					varsIgnorePattern: '^_',
				},
			],
			'no-undef': 'error',
			'use-isnan': 'error',
			'valid-typeof': 'error',
		},
	},
];
