import { useState, useEffect } from '@wordpress/element';
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
	Spinner,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import apiFetch from '@wordpress/api-fetch';

import { generateToken, postTypes, modeOptions, finishOperation } from './shared';

const statusLabels = {
	publish: __( 'Published', 'wp-block-forker' ),
	draft: __( 'Draft', 'wp-block-forker' ),
	pending: __( 'Pending', 'wp-block-forker' ),
	future: __( 'Scheduled', 'wp-block-forker' ),
	private: __( 'Private', 'wp-block-forker' ),
};

const positionOptions = [
	{ label: __( 'End of the post', 'wp-block-forker' ), value: 'append' },
	{ label: __( 'Start of the post', 'wp-block-forker' ), value: 'prepend' },
];

function postTitle( post ) {
	return (
		post.title?.raw ||
		post.title?.rendered ||
		__( '(no title)', 'wp-block-forker' )
	);
}

export default function MergeModal( { clientIds, onClose } ) {
	const { selectedBlocks, sourcePostId } = useSelect(
		( select ) => ( {
			selectedBlocks: select( blockEditorStore )
				.getBlocksByClientId( clientIds )
				.filter( Boolean ),
			sourcePostId: select( editorStore ).getCurrentPostId(),
		} ),
		[ clientIds ]
	);

	const [ postType, setPostType ] = useState( postTypes[ 0 ]?.slug || 'post' );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isSearching, setIsSearching ] = useState( false );
	const [ target, setTarget ] = useState( null );
	const [ position, setPosition ] = useState( 'append' );
	const [ mode, setMode ] = useState( 'copy' );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ error, setError ] = useState( '' );
	// One token per modal instance, deliberately not per click. The server
	// treats a repeated token as "already merged", so a retry after a dropped
	// response can't append the blocks twice. The modal closes on success, so
	// a token is never reused for a second, intentional merge.
	const [ mergeToken ] = useState( generateToken );

	const restBase =
		postTypes.find( ( pt ) => pt.slug === postType )?.rest_base || postType;

	useEffect( () => {
		let cancelled = false;
		setIsSearching( true );

		// Debounce typing; also covers the immediate post-type switch.
		const timer = setTimeout( async () => {
			try {
				const posts = await apiFetch( {
					path: addQueryArgs( `/wp/v2/${ restBase }`, {
						search: query || undefined,
						status: 'any',
						context: 'edit',
						exclude: sourcePostId,
						per_page: 10,
						_fields: 'id,title,status',
					} ),
				} );
				if ( ! cancelled ) {
					setResults( posts );
					setError( '' );
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setResults( [] );
					setError(
						err?.message ||
							__( 'Could not load posts to merge into.', 'wp-block-forker' )
					);
				}
			} finally {
				if ( ! cancelled ) {
					setIsSearching( false );
				}
			}
		}, 300 );

		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ restBase, query, sourcePostId ] );

	const handleSubmit = async () => {
		setIsSubmitting( true );
		setError( '' );

		try {
			const response = await apiFetch( {
				path: '/wpbf/v1/merge',
				method: 'POST',
				data: {
					source_post_id: sourcePostId,
					target_post_id: target.id,
					extracted_content: serialize( selectedBlocks ),
					position,
					merge_token: mergeToken,
				},
			} );

			const name = postTitle( target );

			finishOperation( {
				mode,
				clientIds,
				copyMessage: sprintf(
					/* translators: %s: title of the post the blocks were merged into. */
					__( 'Merged into “%s”.', 'wp-block-forker' ),
					name
				),
				moveMessage: sprintf(
					/* translators: %s: title of the post the blocks were merged into. */
					__(
						'Merged into “%s”. The blocks were removed here — save this post to keep that change.',
						'wp-block-forker'
					),
					name
				),
				editUrl: response?.edit_url,
				editLabel: __( 'Edit post', 'wp-block-forker' ),
			} );

			onClose();
		} catch ( err ) {
			setError(
				err?.message || __( 'Something went wrong merging these blocks.', 'wp-block-forker' )
			);
		} finally {
			setIsSubmitting( false );
		}
	};

	return (
		<Modal
			title={ __( 'Merge into existing post', 'wp-block-forker' ) }
			onRequestClose={ onClose }
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ postTypes.length > 1 && (
				<SelectControl
					label={ __( 'Post type', 'wp-block-forker' ) }
					value={ postType }
					options={ postTypes.map( ( pt ) => ( {
						label: pt.label,
						value: pt.slug,
					} ) ) }
					onChange={ ( value ) => {
						setPostType( value );
						setTarget( null );
					} }
				/>
			) }

			<TextControl
				label={ __( 'Search posts', 'wp-block-forker' ) }
				value={ query }
				onChange={ setQuery }
			/>

			<div
				style={ { minHeight: '120px', maxHeight: '220px', overflowY: 'auto' } }
				aria-busy={ isSearching }
			>
				{ isSearching && <Spinner /> }
				{ ! isSearching && results.length === 0 && ! error && (
					<p>{ __( 'No matching posts.', 'wp-block-forker' ) }</p>
				) }
				{ ! isSearching && results.length > 0 && (
					<ul style={ { margin: 0, padding: 0, listStyle: 'none' } }>
						{ results.map( ( post ) => (
							<li key={ post.id }>
								<Button
									variant={ target?.id === post.id ? 'primary' : 'tertiary' }
									onClick={ () => setTarget( post ) }
									style={ { width: '100%', justifyContent: 'space-between' } }
								>
									<span>{ postTitle( post ) }</span>
									<span>{ statusLabels[ post.status ] || post.status }</span>
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</div>

			<RadioControl
				label={ __( 'Add the blocks at', 'wp-block-forker' ) }
				selected={ position }
				options={ positionOptions }
				onChange={ setPosition }
			/>

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
					disabled={ isSubmitting || ! target }
				>
					{ __( 'Merge', 'wp-block-forker' ) }
				</Button>
				<Button variant="tertiary" onClick={ onClose } disabled={ isSubmitting }>
					{ __( 'Cancel', 'wp-block-forker' ) }
				</Button>
			</div>
		</Modal>
	);
}
