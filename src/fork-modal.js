import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';
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

import { generateToken, postTypes, modeOptions, finishOperation } from './shared';

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
					fork_token: generateToken(),
				},
			} );

			finishOperation( {
				mode,
				clientIds,
				copyMessage: __( 'Forked to a new draft.', 'wp-block-forker' ),
				moveMessage: __(
					'Forked. The blocks were removed here — save this post to keep that change.',
					'wp-block-forker'
				),
				editUrl: response?.edit_url,
				editLabel: __( 'Edit new post', 'wp-block-forker' ),
			} );

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
				options={ modeOptions }
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
