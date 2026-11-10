/**
 * Modo "que el cliente elija": al enviar cualquier formulario de búsqueda del sitio,
 * ofrece ir al asistente o ver los resultados normales.
 */
( function () {
	const config = window.asiSearchChoice;
	if ( ! config ) {
		return;
	}

	function close( panel ) {
		panel.remove();
		document.removeEventListener( 'keydown', panel._onKey );
	}

	function choose( form, query ) {
		const old = document.querySelector( '.asi-choice' );
		if ( old ) {
			close( old );
		}
		const panel = document.createElement( 'div' );
		panel.className = 'asi-choice';
		panel.setAttribute( 'role', 'dialog' );
		panel.setAttribute( 'aria-label', config.ui.title );
		panel.style.cssText = config.vars;

		const box = document.createElement( 'div' );
		box.className = 'asi-choice__box';
		const title = document.createElement( 'p' );
		title.className = 'asi-choice__title';
		title.textContent = config.ui.title;

		const ask = document.createElement( 'a' );
		ask.className = 'asi-choice__primary';
		ask.textContent = config.ui.assistant;
		ask.href = config.chatUrl + ( config.chatUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'q=' + encodeURIComponent( query );

		const normal = document.createElement( 'button' );
		normal.type = 'button';
		normal.className = 'asi-choice__secondary';
		normal.textContent = config.ui.search;
		normal.addEventListener( 'click', function () {
			close( panel );
			form.dataset.asiChosen = '1';
			// Si el sitio además redirige al chat, asi_skip deja ver los resultados normales.
			const skip = document.createElement( 'input' );
			skip.type = 'hidden';
			skip.name = 'asi_skip';
			skip.value = '1';
			form.appendChild( skip );
			form.submit();
		} );

		box.append( title, ask, normal );
		panel.appendChild( box );
		panel.addEventListener( 'click', function ( event ) {
			if ( event.target === panel ) {
				close( panel );
			}
		} );
		panel._onKey = function ( event ) {
			if ( 'Escape' === event.key ) {
				close( panel );
			}
		};
		document.addEventListener( 'keydown', panel._onKey );
		document.body.appendChild( panel );
		ask.focus();
	}

	document.addEventListener(
		'submit',
		function ( event ) {
			const form = event.target;
			if ( ! form || 'FORM' !== form.tagName || form.dataset.asiChosen ) {
				return;
			}
			const input = form.querySelector( 'input[name="s"]' );
			if ( ! input || ! input.value.trim() ) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			choose( form, input.value.trim() );
		},
		true
	);
} )();
