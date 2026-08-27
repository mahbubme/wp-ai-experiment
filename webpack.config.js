/**
 * WordPress dependency
 */
const [ scriptConfig ] = require( '@wordpress/scripts/config/webpack.config' );

const withTsLoader = ( config ) => {
	return {
		...config,
		module: {
			...config.module,
			rules: [
				...config.module.rules,
				{
					test: /\.tsx?$/,
					use: 'ts-loader',
					exclude: /node_modules/,
				},
			],
		},
	};
};

/*
 * The module config that used to sit alongside this one was dropped: its only
 * entry pointed at `resources/ts/my-module`, a file that has never existed, so
 * every build failed on it. Nothing here needs a script-module output - the
 * editor bundle is a classic script that imports `@wordpress/abilities` and
 * `@wordpress/core-abilities` dynamically, with the import map supplied by the
 * `module_dependencies` enqueue arg on the PHP side.
 */
module.exports = [
	withTsLoader( {
		...scriptConfig,
		entry: {
			main: './resources/ts/main',
			editor: './resources/js/editor/index',
		},
		output: {
			...scriptConfig.output,
			publicPath: './',
			path: __dirname + '/assets',
			filename: '[name].js',
		},
	} ),
];
