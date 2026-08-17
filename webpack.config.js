/**
 * WordPress dependency
 */
const [ scriptConfig, moduleConfig ] = require( '@wordpress/scripts/config/webpack.config' );

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

module.exports = [
	withTsLoader( {
		...scriptConfig,
		entry: {
			main: './resources/ts/main',
		},
		output: {
			...scriptConfig.output,
			publicPath: './',
			path: __dirname + '/assets',
			filename: '[name].js',
		},
	} ),
	withTsLoader( {
		...moduleConfig,
		entry: {
			'my-module': './resources/ts/my-module',
		},
		output: {
			...moduleConfig.output,
			publicPath: './',
			path: __dirname + '/assets',
			filename: '[name].js',

		}
	} )
];
