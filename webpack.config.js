/**
 * Two React apps (member dashboard + admin) and the plain-TS membership popup, built with @wordpress/scripts.
 * WVM_ENTRY=member|admin|popup builds a single app without wiping the other's output.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const entries = {
	member: './assets/src/member/index.tsx',
	admin: './assets/src/admin/index.tsx',
	popup: './assets/src/popup/index.ts',
};
const only = process.env.WVM_ENTRY;

module.exports = {
	...defaultConfig,
	entry: only ? { [ only ]: entries[ only ] } : entries,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		clean: false,
	},
	plugins: defaultConfig.plugins.filter( ( p ) => p.constructor.name !== 'CopyPlugin' && p.constructor.name !== 'PhpFilePathsPlugin' ),
	performance: { hints: false },
};
