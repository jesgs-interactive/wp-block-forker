import { dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

export function generateToken() {
	if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
		return window.crypto.randomUUID();
	}
	return 'fork-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
}

export const postTypes =
	( window.wpbfConfig && window.wpbfConfig.postTypes ) || [
		{ slug: 'post', label: __( 'Post', 'wp-block-forker' ), rest_base: 'posts' },
	];

export const modeOptions = [
	{
		label: __( 'Copy — leave the blocks here too', 'wp-block-forker' ),
		value: 'copy',
	},
	{
		label: __( 'Move — remove the blocks from this post', 'wp-block-forker' ),
		value: 'move',
	},
];

/**
 * Post-success steps shared by fork and merge: for "move", drop the blocks
 * from the editor, then show the snackbar.
 */
export function finishOperation( {
	mode,
	clientIds,
	copyMessage,
	moveMessage,
	editUrl,
	editLabel,
} ) {
	if ( 'move' === mode ) {
		// Removes locally only. Persisting this to the source post
		// happens via the editor's own save — see AGENTS.md.
		dispatch( blockEditorStore ).removeBlocks( clientIds, false );
	}

	dispatch( noticesStore ).createSuccessNotice(
		'move' === mode ? moveMessage : copyMessage,
		{
			type: 'snackbar',
			actions: editUrl ? [ { label: editLabel, url: editUrl } ] : [],
		}
	);
}
