import { registerPlugin } from '@wordpress/plugins';
import { BlockSettingsMenuControls } from '@wordpress/block-editor';
import { MenuItem } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import ForkModal from './fork-modal';
import MergeModal from './merge-modal';

// The modals are rendered outside the slot-fill render prop, as siblings,
// so they stay mounted regardless of the dropdown's own open state —
// BlockSettingsMenuControls unmounts its children whenever the dropdown
// itself closes, which happens as soon as a menu item is clicked.
function ForkPlugin() {
	// { kind: 'fork' | 'merge', clientIds } while a modal is open, else null.
	const [ modal, setModal ] = useState( null );
	const close = () => setModal( null );

	return (
		<>
			<BlockSettingsMenuControls>
				{ ( { selectedClientIds, onClose } ) => (
					<>
						<MenuItem
							onClick={ () => {
								setModal( { kind: 'fork', clientIds: selectedClientIds } );
								onClose();
							} }
						>
							{ __( 'Fork to new post…', 'wp-block-forker' ) }
						</MenuItem>
						<MenuItem
							onClick={ () => {
								setModal( { kind: 'merge', clientIds: selectedClientIds } );
								onClose();
							} }
						>
							{ __( 'Merge into existing post…', 'wp-block-forker' ) }
						</MenuItem>
					</>
				) }
			</BlockSettingsMenuControls>
			{ 'fork' === modal?.kind && (
				<ForkModal clientIds={ modal.clientIds } onClose={ close } />
			) }
			{ 'merge' === modal?.kind && (
				<MergeModal clientIds={ modal.clientIds } onClose={ close } />
			) }
		</>
	);
}

registerPlugin( 'wp-block-forker', { render: ForkPlugin } );
