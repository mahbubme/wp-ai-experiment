/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import {
	Button,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { runAbility } from './run-ability';

/**
 * Human labels for the tones the ability accepts.
 *
 * Built on call rather than at module scope so the strings are translated when
 * the panel renders, not whenever this bundle happens to be evaluated. Any tone
 * the server adds without a label here still works - it just shows its raw slug.
 *
 * @return {Object} Tone slug to label.
 */
function toneLabels() {
	return {
		neutral: __( 'Neutral', 'wp-ai-experiment' ),
		informative: __( 'Informative', 'wp-ai-experiment' ),
		conversational: __( 'Conversational', 'wp-ai-experiment' ),
		promotional: __( 'Promotional', 'wp-ai-experiment' ),
	};
}

const IDLE = 'idle';
const SAVING = 'saving';
const DRAFTING = 'drafting';

function errorMessage( error ) {
	if ( typeof error?.message === 'string' && error.message ) {
		return error.message;
	}

	return __( 'The excerpt could not be drafted.', 'wp-ai-experiment' );
}

export default function ExcerptPanel( { config } ) {
	const [ tone, setTone ] = useState( config.defaultTone );
	const [ maxWords, setMaxWords ] = useState( config.defaultWords );
	const [ status, setStatus ] = useState( IDLE );
	const [ suggestion, setSuggestion ] = useState( null );
	const [ error, setError ] = useState( null );

	const { postId, isDirty, isSaving } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			isDirty: editor.isEditedPostDirty(),
			isSaving: editor.isSavingPost(),
		};
	}, [] );

	const { editPost, savePost } = useDispatch( editorStore );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const busy = status !== IDLE;
	const labels = toneLabels();

	if ( ! config.aiAvailable ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'No AI provider is available on this site, so excerpts cannot be drafted yet.',
					'wp-ai-experiment'
				) }
			</Notice>
		);
	}

	const generate = async () => {
		setError( null );
		setSuggestion( null );

		try {
			/*
			 * The ability reads the post from the database, so anything still
			 * sitting in the editor is invisible to it. Saving first is what
			 * makes the suggestion reflect what the author actually wrote
			 * rather than the last revision they happened to persist.
			 */
			if ( isDirty ) {
				setStatus( SAVING );
				await savePost();
			}

			setStatus( DRAFTING );

			// Annotated readonly server-side, so this travels as a GET.
			const result = await runAbility(
				config.draftAbility,
				{ post_id: postId, max_words: maxWords, tone },
				true
			);

			setSuggestion( result );
		} catch ( caught ) {
			setError( errorMessage( caught ) );
		} finally {
			setStatus( IDLE );
		}
	};

	const apply = () => {
		/*
		 * Written into the editor rather than straight to the database: the
		 * excerpt lands in the normal save flow, stays undoable, and nothing is
		 * persisted until the author says so.
		 */
		editPost( { excerpt: suggestion.excerpt } );
		setSuggestion( null );
		createSuccessNotice(
			__(
				'Excerpt applied. Update the post to save it.',
				'wp-ai-experiment'
			),
			{ type: 'snackbar' }
		);
	};

	return (
		<div className="wp-ai-experiment-excerpt">
			<SelectControl
				__nextHasNoMarginBottom
				label={ __( 'Tone', 'wp-ai-experiment' ) }
				value={ tone }
				options={ config.tones.map( ( value ) => ( {
					value,
					label: labels[ value ] ?? value,
				} ) ) }
				onChange={ setTone }
				disabled={ busy }
			/>

			<RangeControl
				__nextHasNoMarginBottom
				label={ __( 'Maximum words', 'wp-ai-experiment' ) }
				value={ maxWords }
				min={ config.minWords }
				max={ config.maxWords }
				onChange={ ( value ) =>
					setMaxWords( value ?? config.defaultWords )
				}
				disabled={ busy }
			/>

			{ error && (
				<Notice
					status="error"
					onRemove={ () => setError( null ) }
					className="wp-ai-experiment-excerpt__notice"
				>
					{ error }
				</Notice>
			) }

			<Button
				variant="secondary"
				onClick={ generate }
				isBusy={ busy }
				disabled={ busy || isSaving || ! postId }
				__next40pxDefaultSize
			>
				{ suggestion
					? __( 'Regenerate', 'wp-ai-experiment' )
					: __( 'Generate excerpt', 'wp-ai-experiment' ) }
			</Button>

			{ busy && (
				<p className="wp-ai-experiment-excerpt__status">
					<Spinner />
					<span>
						{ status === SAVING
							? __( 'Saving post…', 'wp-ai-experiment' )
							: __( 'Drafting excerpt…', 'wp-ai-experiment' ) }
					</span>
				</p>
			) }

			{ suggestion && ! busy && (
				<div className="wp-ai-experiment-excerpt__result">
					<p className="wp-ai-experiment-excerpt__suggestion">
						{ suggestion.excerpt }
					</p>

					{ /* Applying overwrites whatever is there, so say so when
					     there is something to lose. */ }
					{ suggestion.current_excerpt && (
						<p className="wp-ai-experiment-excerpt__replaces">
							{ sprintf(
								/* translators: %s: the excerpt that would be replaced. */
								__( 'Replaces: %s', 'wp-ai-experiment' ),
								suggestion.current_excerpt
							) }
						</p>
					) }

					<div className="wp-ai-experiment-excerpt__actions">
						<Button
							variant="primary"
							onClick={ apply }
							__next40pxDefaultSize
						>
							{ __( 'Apply', 'wp-ai-experiment' ) }
						</Button>
						<Button
							variant="tertiary"
							onClick={ () => setSuggestion( null ) }
							__next40pxDefaultSize
						>
							{ __( 'Dismiss', 'wp-ai-experiment' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}
