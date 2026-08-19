/**
 * External dependencies
 */
const { resolve } = require( 'node:path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: resolve( __dirname, 'src/editor/index.tsx' ),
		welcome: resolve( __dirname, 'src/welcome/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: resolve( __dirname, 'build' ),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ '.ts', '.tsx', '...' ],
	},
};
