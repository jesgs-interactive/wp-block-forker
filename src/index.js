import { registerPlugin } from '@wordpress/plugins';
import { BlockSettingsMenuControls } from '@wordpress/block-editor';
import { MenuItem } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ForkModal from './fork-modal';

// The modal is rendered outside the slot-fill render prop, as a sibling,
// so it stays mounted regardless of the dropdown's own open state —
// BlockSettingsMenuControls unmounts its children whenever the dropdown
// itself closes, which happens as soon as the menu item is clicked.
function ForkPlugin() {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ modalClientIds, setModalClientIds ] = useState( [] );

	return (
		<>
			<BlockSettingsMenuControls>
				{ ( { selectedClientIds, onClose } ) => (
					<MenuItem
						onClick={ () => {
							setModalClientIds( selectedClientIds );
							setIsModalOpen( true );
							onClose();
						} }
					>
						{ __( 'Fork to new post…', 'wp-block-forker' ) }
					</MenuItem>
				) }
			</BlockSettingsMenuControls>
			{ isModalOpen && (
				<ForkModal
					clientIds={ modalClientIds }
					onClose={ () => setIsModalOpen( false ) }
				/>
			) }
		</>
	);
}

registerPlugin( 'wp-block-forker', { render: ForkPlugin } );
