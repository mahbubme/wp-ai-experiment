/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginDocumentSettingPanel,
	PostTypeSupportCheck,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ExcerptPanel from './excerpt-panel';
import './style.scss';

/*
 * Localized by `Editor\Module`. Every value in here is derived from the
 * ability's own input schema on the server, so the controls cannot offer a tone
 * or a word count that the ability would reject.
 */
const config = window.wpAiExperimentEditor;

if ( config ) {
	registerPlugin( 'wp-ai-experiment-excerpt', {
		render: () => (
			// Belt and braces with the PHP-side `post_type_supports()` check:
			// that one decides whether to load this bundle at all, this one
			// keeps the panel honest if the editor switches post type without
			// a reload.
			<PostTypeSupportCheck supportKeys="excerpt">
				<PluginDocumentSettingPanel
					name="wp-ai-experiment-excerpt"
					title={ __( 'AI Excerpt', 'wp-ai-experiment' ) }
					className="wp-ai-experiment-excerpt-panel"
				>
					<ExcerptPanel config={ config } />
				</PluginDocumentSettingPanel>
			</PostTypeSupportCheck>
		),
	} );
}
