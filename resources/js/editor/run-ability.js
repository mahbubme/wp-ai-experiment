/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Errors that mean "the client-side registry could not help", as opposed to
 * "the server said no".
 *
 * The distinction matters: retrying a permission failure over REST just earns a
 * second 403 and replaces a precise message with a vague one. Only fall through
 * when the ability was genuinely missing from the registry or never got a
 * callback attached - which is what happens if the hydration fetch failed, or if
 * this bundle loaded before `core-abilities` finished.
 */
const RECOVERABLE_CODES = [ 'ability_not_found', 'ability_invalid_input' ];
const RECOVERABLE_MESSAGES = [ 'Ability not found', 'missing callback' ];

function isRecoverable( error ) {
	if ( ! error || typeof error !== 'object' ) {
		return false;
	}

	const code = typeof error.code === 'string' ? error.code : '';

	if ( RECOVERABLE_CODES.includes( code ) ) {
		return true;
	}

	const message = typeof error.message === 'string' ? error.message : '';

	return RECOVERABLE_MESSAGES.some( ( part ) => message.includes( part ) );
}

/**
 * Loads the abilities modules, waiting for the server registry to hydrate.
 *
 * `core-abilities` exports a single `ready` promise that resolves once it has
 * fetched every REST-visible ability and registered it with a callback. Awaiting
 * it before touching `executeAbility` is what stops a fast click from racing the
 * hydration fetch.
 *
 * Both imports are dynamic because these are script modules and this bundle is a
 * classic script: the specifiers only resolve once WordPress has printed the
 * import map, which the `module_dependencies` enqueue arg arranges.
 *
 * `webpackIgnore` is load-bearing. Without it the dependency extraction plugin
 * rewrites both specifiers to `window.wp.abilities` / `window.wp.coreAbilities`
 * and adds `wp-abilities` / `wp-core-abilities` to the asset file's script
 * handles. Neither is real - these ship as script modules, not classic scripts,
 * so the globals are undefined and the handles resolve to nothing. The comment
 * makes webpack leave the calls alone so the browser resolves them natively
 * against the import map.
 */
async function loadAbilities() {
	try {
		const { ready } = await import(
			/* webpackIgnore: true */ '@wordpress/core-abilities'
		);
		await ready;

		return await import( /* webpackIgnore: true */ '@wordpress/abilities' );
	} catch {
		return null;
	}
}

/**
 * Mirrors the verb core derives from an ability's annotations.
 *
 * `WP_REST_Abilities_V1_Run_Controller::validate_request_method()` checks
 * `readonly` first, then `destructive && idempotent`, and answers 405 on a
 * mismatch. Only the read side is needed here - the excerpt abilities are
 * read-or-update, never destructive.
 *
 * @param {string}  name       Fully qualified ability name.
 * @param {Object}  input      Input matching the ability's schema.
 * @param {boolean} isReadonly Whether the ability is annotated readonly.
 * @return {Object} An apiFetch request descriptor.
 */
function restRequestFor( name, input, isReadonly ) {
	const path = `/wp-abilities/v1/abilities/${ name }/run`;

	if ( isReadonly ) {
		return {
			method: 'GET',
			path: input === undefined ? path : addQueryArgs( path, { input } ),
		};
	}

	return {
		method: 'POST',
		path,
		...( input === undefined ? {} : { data: { input } } ),
	};
}

/**
 * Runs a registered ability, preferring the client registry over raw REST.
 *
 * Going through `executeAbility` buys input and output validation against the
 * ability's own JSON Schema before anything leaves the browser, and shares one
 * code path with every other abilities consumer on the page. The REST call is
 * kept as a fallback so a hydration failure degrades to a working panel rather
 * than a broken one.
 *
 * @param {string}  name       Fully qualified ability name.
 * @param {Object}  input      Input matching the ability's schema.
 * @param {boolean} isReadonly Whether the ability is annotated readonly.
 * @return {Promise<Object>} The ability's output.
 */
export async function runAbility( name, input, isReadonly = false ) {
	try {
		const abilities = await loadAbilities();

		if ( typeof abilities?.executeAbility === 'function' ) {
			return await abilities.executeAbility( name, input );
		}
	} catch ( error ) {
		if ( ! isRecoverable( error ) ) {
			throw error;
		}
	}

	return apiFetch( restRequestFor( name, input, isReadonly ) );
}
