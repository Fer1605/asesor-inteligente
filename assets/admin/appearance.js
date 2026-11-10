/**
 * Pestaña Apariencia: selectores de color, esquinas, nombre y avatar, con vista previa en vivo.
 */
( function ( $ ) {
	const preview = document.getElementById( 'asi-preview' );
	if ( ! preview ) {
		return;
	}

	// Mismo cálculo de contraste que ASI_Appearance::contrast().
	function contrast( hex ) {
		let value = ( hex || '' ).replace( '#', '' );
		if ( 3 === value.length ) {
			value = value.split( '' ).map( ( c ) => c + c ).join( '' );
		}
		if ( value.length < 6 ) {
			return '#ffffff';
		}
		const lum = [ 0, 2, 4 ].reduce( function ( sum, offset, i ) {
			let channel = parseInt( value.substr( offset, 2 ), 16 ) / 255;
			channel = channel <= 0.03928 ? channel / 12.92 : Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );
			return sum + [ 0.2126, 0.7152, 0.0722 ][ i ] * channel;
		}, 0 );
		return lum > 0.45 ? '#111827' : '#ffffff';
	}

	const inputs = Array.prototype.slice.call( document.querySelectorAll( '.asi-color' ) );
	const values = {}; // Color elegido por campo ('' = vacío).

	function current( key ) {
		const input = inputs.find( ( item ) => item.dataset.var === '--asi-' + key );
		if ( ! input ) {
			return '';
		}
		if ( values[ key ] ) {
			return values[ key ];
		}
		// Vacío: si deriva de otro color, sigue a ese color en vivo.
		return input.dataset.derive ? current( input.dataset.derive ) : input.dataset.fallback;
	}

	// Recalcula todas las variables de la vista previa, incluidos botones y contrastes.
	function refresh() {
		inputs.forEach( function ( input ) {
			const key = input.dataset.var.replace( '--asi-', '' );
			let value = current( key );
			if ( 'reset_text' === key && ! values.reset_text && '#ffffff' !== contrast( current( 'primary' ) ) ) {
				value = current( 'text' );
			}
			preview.style.setProperty( input.dataset.var, value );
		} );
		preview.style.setProperty( '--asi-on-primary', contrast( current( 'primary' ) ) );
		preview.style.setProperty( '--asi-on-accent', contrast( current( 'accent' ) ) );
		preview.style.setProperty( '--asi-on-button', contrast( current( 'button' ) ) );
		preview.style.setProperty( '--asi-on-button_hover', contrast( current( 'button_hover' ) ) );
	}

	inputs.forEach( function ( input ) {
		const key = input.dataset.var.replace( '--asi-', '' );
		values[ key ] = input.value;
		$( input ).wpColorPicker( {
			change: function ( event, ui ) {
				values[ key ] = ui.color.toString();
				refresh();
			},
			clear: function () {
				values[ key ] = '';
				refresh();
			},
		} );
	} );

	document.querySelectorAll( 'input[name="radius"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			preview.style.setProperty( '--asi-radius', radio.dataset.radius + 'px' );
		} );
	} );

	const nameInput = document.getElementById( 'asi-assistant-name' );
	const nameView = document.getElementById( 'asi-preview-name' );
	const avatarView = document.getElementById( 'asi-preview-avatar' );
	const avatarInput = document.getElementById( 'asi-avatar' );
	const avatarThumb = document.getElementById( 'asi-avatar-preview' );
	const avatarRemove = document.getElementById( 'asi-avatar-remove' );
	const avatarEmpty = document.getElementById( 'asi-avatar-empty' );

	function identity() {
		const anonymous = ! nameInput.value.trim() && ! avatarInput.value;
		preview.classList.toggle( 'asi-chat--anonymous', anonymous );
	}

	nameInput.addEventListener( 'input', function () {
		nameView.textContent = nameInput.value;
		identity();
	} );

	let frame;
	document.getElementById( 'asi-avatar-pick' ).addEventListener( 'click', function () {
		if ( ! frame ) {
			frame = wp.media( { title: 'Imagen del asistente', library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				const image = frame.state().get( 'selection' ).first().toJSON();
				const url = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url;
				avatarInput.value = image.id;
				avatarThumb.src = url;
				avatarThumb.style.display = '';
				avatarView.src = url;
				avatarView.style.display = '';
				avatarRemove.style.display = '';
				avatarEmpty.style.display = 'none';
				identity();
			} );
		}
		frame.open();
	} );

	avatarRemove.addEventListener( 'click', function () {
		avatarInput.value = '';
		avatarThumb.style.display = 'none';
		avatarView.style.display = 'none';
		avatarRemove.style.display = 'none';
		avatarEmpty.style.display = '';
		identity();
	} );
	avatarInput.value = '0' === avatarInput.value ? '' : avatarInput.value;
	identity();
} )( jQuery );
