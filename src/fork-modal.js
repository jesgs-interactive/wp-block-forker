import { useState } from '@wordpress/element';
import { useSelect, dispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { serialize } from '@wordpress/blocks';
import {
	Modal,
	TextControl,
	SelectControl,
	RadioControl,
	Button,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

function stripTags( html ) {
	const el = document.createElement( 'div' );
	el.innerHTML = html;
	return ( el.textContent || el.innerText || '' ).trim();
}

function deriveTitle( blocks ) {
	for ( const block of blocks ) {
		const text = stripTags( serialize( [ block ] ) );
		if ( text ) {
			return text.length > 80 ? text.slice( 0, 80 ) + '…' : text;
		}
	}
	return __( 'Untitled fragment', 'wp-block-forker' );
}

function generateForkToken() {
	if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
		return window.crypto.randomUUID();
	}
	return 'fork-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
}

const postTypes =
	( window.wpbfConfig && window.wpbfConfig.postTypes ) || [
		{ slug: 'post', label: __( 'Post', 'wp-block-forker' ) },
	];

export default function ForkModal( { clientIds, onClose } ) {
	const { selectedBlocks, sourcePostId } = useSelect(
		( select ) => ( {
			selectedBlocks: select( blockEditorStore )
				.getBlocksByClientId( clientIds )
				.filter( Boolean ),
			sourcePostId: select( editorStore ).getCurrentPostId(),
		} ),
		[ clientIds ]
	);

	const [ title, setTitle ] = useState( () => deriveTitle( selectedBlocks ) );
	const [ postType, setPostType ] = useState( postTypes[ 0 ]?.slug || 'post' );
	const [ mode, setMode ] = useState( 'copy' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );

	const handleSubmit = async () => {
		setIsSubmitting( true );
		setError( '' );

		const extractedContent = serialize( selectedBlocks );

		try {
			const response = await apiFetch( {
				path: '/wpbf/v1/fork',
				method: 'POST',
				data: {
					source_post_id: sourcePostId,
					post_type: postType,
					title,
					extracted_content: extractedContent,
					mode,
					fork_token: generateForkToken(),
				},
			} );

			if ( 'move' === mode ) {
				// Removes locally only. Persisting this to the source post
				// happens via the editor's own save — see AGENTS.md.
				dispatch( blockEditorStore ).removeBlocks( clientIds, false );
			}

			dispatch( noticesStore ).createSuccessNotice(
				'move' === mode
					? __(
							'Forked. The blocks were removed here — save this post to keep that change.',
							'wp-block-forker'
					  )
					: __( 'Forked to a new draft.', 'wp-block-forker' ),
				{
					type: 'snackbar',
					actions: response?.edit_url
						? [
								{
									label: __( 'Edit new post', 'wp-block-forker' ),
									url: response.edit_url,
								},
						  ]
						: [],
				}
			);

			onClose();
		} catch ( err ) {
			setError(
				err?.message || __( 'Something went wrong forking these blocks.', 'wp-block-forker' )
			);
		} finally {
			setIsSubmitting( false );
		}
	};

	return (
		<Modal title={ __( 'Fork to new post', 'wp-block-forker' ) } onRequestClose={ onClose }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			<TextControl
				label={ __( 'Title', 'wp-block-forker' ) }
				value={ title }
				onChange={ setTitle }
			/>

			{ postTypes.length > 1 && (
				<SelectControl
					label={ __( 'Post type', 'wp-block-forker' ) }
					value={ postType }
					options={ postTypes.map( ( pt ) => ( {
						label: pt.label,
						value: pt.slug,
					} ) ) }
					onChange={ setPostType }
				/>
			) }

			<RadioControl
				label={ __( 'Mode', 'wp-block-forker' ) }
				selected={ mode }
				options={ [
					{
						label: __( 'Copy — leave the blocks here too', 'wp-block-forker' ),
						value: 'copy',
					},
					{
						label: __( 'Move — remove the blocks from this post', 'wp-block-forker' ),
						value: 'move',
					},
				] }
				onChange={ setMode }
			/>

			<div style={ { marginTop: '16px', display: 'flex', gap: '8px' } }>
				<Button
					variant="primary"
					onClick={ handleSubmit }
					isBusy={ isSubmitting }
					disabled={ isSubmitting || ! title.trim() }
				>
					{ __( 'Fork', 'wp-block-forker' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose } disabled={ isSubmitting }>
					{ __( 'Cancel', 'wp-block-forker' ) }
				</Button>
			</div>
		</Modal>
	);
}
