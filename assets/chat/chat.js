/**
 * Chat del Asesor inteligente.
 *
 * - Conversa con POST /wp-json/asi/v1/chat enviando el estado de la conversación.
 * - Guarda el historial en localStorage por idioma/país (24 h), así al volver sigue donde quedó.
 * - Sin dependencias: JavaScript plano.
 */
( function () {
	const config = window.asiChatConfig;
	const root = document.getElementById( 'asi-chat' );
	if ( ! config || ! root ) {
		return;
	}

	const ui = config.ui || {};
	const HISTORY_TTL = 24 * 60 * 60 * 1000;
	const MAX_ITEMS = 60;
	let state = {};
	let items = []; // Historial: { who: 'user'|'bot', data }.
	let busy = false;

	// ---------- Utilidades ----------

	function el( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function safeUrl( url ) {
		return /^(https?:\/\/|tel:|mailto:)/i.test( url || '' ) ? url : '#';
	}

	function isExternal( url ) {
		try {
			return new URL( url, window.location.href ).host !== window.location.host;
		} catch ( e ) {
			return false;
		}
	}

	function load() {
		try {
			const saved = JSON.parse( window.localStorage.getItem( config.storageKey ) || 'null' );
			if ( saved && Date.now() - saved.savedAt < HISTORY_TTL ) {
				state = saved.state || {};
				items = saved.items || [];
			}
		} catch ( e ) {
			// Sin almacenamiento disponible: la conversación no se recuerda, pero funciona.
		}
	}

	function save() {
		try {
			window.localStorage.setItem( config.storageKey, JSON.stringify( { state: state, items: items.slice( -MAX_ITEMS ), savedAt: Date.now() } ) );
		} catch ( e ) {}
	}

	// ---------- Estructura ----------

	const header = el( 'div', 'asi-chat__header' );
	const identity = el( 'div', 'asi-chat__identity' );
	if ( config.assistant.avatar ) {
		const avatar = el( 'img', 'asi-chat__avatar' );
		avatar.src = config.assistant.avatar;
		avatar.alt = '';
		identity.appendChild( avatar );
	}
	if ( config.assistant.name ) {
		identity.appendChild( el( 'span', 'asi-chat__name', config.assistant.name ) );
	}
	header.append( identity );

	// "Nueva conversación" va junto a Enviar: así está a la mano también cuando la barra flota.
	const reset = el( 'button', 'asi-chat__reset' );
	reset.type = 'button';
	reset.title = ui.new;
	reset.setAttribute( 'aria-label', ui.new );
	reset.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M19 11h-6V5h-2v6H5v2h6v6h2v-6h6z"/></svg>';
	reset.appendChild( el( 'span', 'asi-chat__reset-label', ui.new ) );

	const log = el( 'div', 'asi-chat__log' );
	log.setAttribute( 'role', 'log' );
	log.setAttribute( 'aria-live', 'polite' );

	const form = el( 'form', 'asi-chat__form' );
	const input = el( 'input', 'asi-chat__input' );
	input.type = 'text';
	input.maxLength = 300;
	input.placeholder = ui.placeholder;
	input.setAttribute( 'aria-label', ui.placeholder );
	input.autocomplete = 'off';
	const send = el( 'button', 'asi-chat__send' );
	send.type = 'submit';
	send.setAttribute( 'aria-label', ui.send );
	send.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg>';
	form.append( input, send, reset );

	// Ocupa el lugar de la barra mientras flota, para que nada salte.
	const spacer = el( 'div', 'asi-chat__spacer' );
	spacer.hidden = true;

	root.textContent = '';
	if ( config.assistant.name || config.assistant.avatar ) {
		root.append( header );
	} else {
		root.classList.add( 'asi-chat--anonymous' );
	}
	root.append( log, spacer, form );

	// ---------- Mensajes ----------

	function bubble( who, text ) {
		const row = el( 'div', 'asi-msg asi-msg--' + who );
		row.dataset.who = who;
		if ( 'bot' === who && config.assistant.avatar ) {
			const avatar = el( 'img', 'asi-msg__avatar' );
			avatar.src = config.assistant.avatar;
			avatar.alt = '';
			row.appendChild( avatar );
		}
		row.appendChild( el( 'div', 'asi-msg__bubble', text ) );
		log.appendChild( row );
		return row;
	}

	function card( data, number ) {
		const link = el( 'a', 'asi-card' + ( data.suggested ? ' asi-card--suggested' : '' ) );
		link.href = safeUrl( data.url );
		if ( data.id ) {
			link.dataset.asiId = data.id;
		}

		const media = el( 'div', 'asi-card__media' );
		// Número de la tarjeta: para pedir "cotízame la 1 y la 3" sin escribir nombres.
		if ( number ) {
			media.appendChild( el( 'span', 'asi-card__num', String( number ) ) );
		}
		if ( data.image ) {
			const img = el( 'img' );
			img.src = data.image;
			img.alt = data.title || '';
			img.loading = 'lazy';
			media.appendChild( img );
		} else {
			media.classList.add( 'asi-card__media--empty' );
		}
		// Una sugerencia (no es lo que pidió) lleva su etiqueta en lugar de "Nuevo" u "Oferta".
		if ( data.suggested ) {
			media.appendChild( el( 'span', 'asi-card__flag asi-card__flag--suggested', data.badge || '' ) );
		} else {
			( data.flags || [] ).slice( 0, 2 ).forEach( function ( flag ) {
				media.appendChild( el( 'span', 'asi-card__flag', flag ) );
			} );
		}
		link.appendChild( media );

		const body = el( 'div', 'asi-card__body' );
		const meta = [ data.category, data.brand ].filter( Boolean ).join( ' · ' );
		if ( meta ) {
			body.appendChild( el( 'span', 'asi-card__meta', meta ) );
		}
		body.appendChild( el( 'strong', 'asi-card__title', data.title ) );
		if ( data.code ) {
			body.appendChild( el( 'span', 'asi-card__code', ( ui.code || '' ) + ': ' + data.code ) );
		}
		( data.specs || [] ).slice( 0, 2 ).forEach( function ( spec ) {
			body.appendChild( el( 'span', 'asi-card__spec', spec.label + ': ' + spec.value ) );
		} );
		if ( data.price ) {
			const price = el( 'span', 'asi-card__price', data.price );
			if ( data.price_alt ) {
				price.appendChild( el( 'small', 'asi-card__price-alt', data.price_alt ) );
			}
			body.appendChild( price );
		}
		if ( data.over ) {
			body.appendChild( el( 'span', 'asi-card__over', data.over ) );
		}
		link.appendChild( body );
		return link;
	}


	function places( list ) {
		if ( ! list || ! list.length ) {
			return;
		}
		const grid = el( 'div', 'asi-places' );
		list.forEach( function ( item ) {
			const a = el( 'a', 'asi-place' );
			a.href = safeUrl( item.url );
			const icon = el( 'span', 'asi-place__icon' );
			icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>';
			a.appendChild( icon );
			a.appendChild( el( 'strong', 'asi-place__title', item.title ) );
			if ( item.summary ) {
				a.appendChild( el( 'span', 'asi-place__summary', item.summary ) );
			}
			a.appendChild( el( 'span', 'asi-place__cta', ( ui.view_place || 'Ver' ) + ' →' ) );
			grid.appendChild( a );
		} );
		log.appendChild( grid );
	}

	// Aclaración aparte de la respuesta ("las fotos de los nuevos pueden no ser del color que pides").
	function note( text ) {
		if ( ! text ) {
			return;
		}
		const box = el( 'div', 'asi-note' );
		const icon = el( 'span', 'asi-note__icon' );
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v6M12 7.5v.01" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>';
		box.appendChild( icon );
		box.appendChild( el( 'p', 'asi-note__text', text ) );
		log.appendChild( box );
	}

	// La frase exacta del sitio que responde (horario, teléfono, dato de la ficha).
	function quote( lines ) {
		if ( ! lines || ! lines.length ) {
			return;
		}
		const block = el( 'blockquote', 'asi-quote' );
		lines.forEach( function ( line ) {
			block.appendChild( el( 'p', 'asi-quote__line', line ) );
		} );
		log.appendChild( block );
	}

	// Dos productos lado a lado.
	function compare( data ) {
		if ( ! data || ! data.rows || ! data.rows.length ) {
			return;
		}
		const wrap = el( 'div', 'asi-compare' );
		const table = el( 'table', 'asi-compare__table' );
		const head = el( 'tr' );
		head.appendChild( el( 'th', 'asi-compare__corner' ) );
		data.columns.forEach( function ( title, index ) {
			const th = el( 'th' );
			const a = el( 'a', '', title );
			a.href = safeUrl( data.urls[ index ] );
			th.appendChild( a );
			head.appendChild( th );
		} );
		const thead = el( 'thead' );
		thead.appendChild( head );
		table.appendChild( thead );
		const body = el( 'tbody' );
		data.rows.forEach( function ( row ) {
			const tr = el( 'tr' );
			tr.appendChild( el( 'th', '', row[ 0 ] ) );
			tr.appendChild( el( 'td', '', row[ 1 ] ) );
			tr.appendChild( el( 'td', '', row[ 2 ] ) );
			body.appendChild( tr );
		} );
		table.appendChild( body );
		wrap.appendChild( table );
		log.appendChild( wrap );
	}

	function tagQuery( node, data ) {
		if ( data.clickq ) {
			node.dataset.asiQ = data.clickq;
		}
	}

	// Documento generado en el chat (cotización, comparación): se ve aquí mismo y se descarga.
	function file( data ) {
		if ( ! data || ! data.url ) {
			return;
		}
		const url = safeUrl( data.url );
		const box = el( 'div', 'asi-file' );
		const head = el( 'div', 'asi-file__head' );
		head.appendChild( el( 'span', 'asi-file__icon', 'PDF' ) );
		const info = el( 'div', 'asi-file__info' );
		info.appendChild( el( 'strong', 'asi-file__label', data.label || 'PDF' ) );
		info.appendChild( el( 'span', 'asi-file__name', data.name || '' ) );
		head.appendChild( info );
		box.appendChild( head );
		if ( window.innerWidth >= 700 ) {
			const frame = el( 'iframe', 'asi-file__preview' );
			// Sin barra ni miniaturas del visor, ajustado al ancho (Chrome, Edge, Brave, Firefox).
			frame.src = url + '#toolbar=0&navpanes=0&scrollbar=1&view=FitH';
			frame.title = data.label || 'PDF';
			frame.loading = 'lazy';
			box.appendChild( frame );
		}
		const actions = el( 'div', 'asi-file__actions' );
		const open = el( 'a', 'asi-file__btn asi-file__btn--primary', data.open || 'Ver' );
		open.href = url;
		open.target = '_blank';
		open.rel = 'noopener';
		const down = el( 'a', 'asi-file__btn', data.download || 'Descargar' );
		down.href = url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'download=1';
		down.setAttribute( 'download', data.name || 'documento.pdf' );
		actions.appendChild( open );
		actions.appendChild( down );
		box.appendChild( actions );
		log.appendChild( box );
	}

	// Tabla sencilla (cuotas).
	function table( data ) {
		if ( ! data || ! data.rows || ! data.rows.length ) {
			return;
		}
		const wrap = el( 'div', 'asi-table' );
		const t = el( 'table' );
		if ( data.head ) {
			const tr = el( 'tr' );
			data.head.forEach( function ( cell ) {
				tr.appendChild( el( 'th', '', cell ) );
			} );
			const thead = el( 'thead' );
			thead.appendChild( tr );
			t.appendChild( thead );
		}
		const body = el( 'tbody' );
		const info = data.emphasis || 0;
		data.rows.forEach( function ( row, rowIndex ) {
			// Las primeras filas son datos (precio, enganche); el resto, las cuotas.
			const tr = el( 'tr', rowIndex < info ? 'asi-table__info' + ( rowIndex === info - 1 ? ' asi-table__info--last' : '' ) : '' );
			row.forEach( function ( cell, index ) {
				tr.appendChild( el( 0 === index ? 'th' : 'td', '', cell ) );
			} );
			body.appendChild( tr );
		} );
		t.appendChild( body );
		wrap.appendChild( t );
		log.appendChild( wrap );
	}

	// Aviso de que lo que sigue no es lo que pidió ("No tenemos sedán de hasta Q 80,000").
	function notice( data ) {
		const box = el( 'div', 'asi-notice' );
		box.setAttribute( 'role', 'status' );
		const icon = el( 'span', 'asi-notice__icon' );
		icon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/></svg>';
		const copy = el( 'div', 'asi-notice__copy' );
		copy.appendChild( el( 'strong', 'asi-notice__title', data.title || '' ) );
		if ( data.text ) {
			copy.appendChild( el( 'span', 'asi-notice__text', data.text ) );
		}
		box.append( icon, copy );
		log.appendChild( box );
		if ( data.heading ) {
			log.appendChild( el( 'div', 'asi-suggest-head', data.heading ) );
		}
	}

	// Cómo contactar al negocio (al despedirse): WhatsApp, teléfono, correo, dirección y horario.
	const CONTACT_ICONS = {
		whatsapp: '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M8.5 9.5c.3 2.7 3.3 5.7 6 6l1.5-1.5-2-1-1 1c-1-.4-2.6-2-3-3l1-1-1-2z"/>',
		phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
		email: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		address: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		hours: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
	};
	function contact( data ) {
		if ( ! data || ! data.items || ! data.items.length ) {
			return;
		}
		const box = el( 'div', 'asi-contact' );
		if ( data.title ) {
			box.appendChild( el( 'p', 'asi-contact__title', data.title ) );
		}
		const list = el( 'div', 'asi-contact__list' );
		data.items.forEach( function ( item ) {
			const row = el( item.url ? 'a' : 'div', 'asi-contact__item asi-contact__item--' + item.type );
			if ( item.url ) {
				row.href = safeUrl( item.url );
				if ( /^https?:/.test( item.url ) ) {
					row.target = '_blank';
					row.rel = 'noopener';
				}
			}
			const icon = el( 'span', 'asi-contact__icon' );
			icon.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( CONTACT_ICONS[ item.type ] || '' ) + '</svg>';
			const copy = el( 'span', 'asi-contact__copy' );
			copy.appendChild( el( 'span', 'asi-contact__label', item.label || '' ) );
			copy.appendChild( el( 'span', 'asi-contact__value', item.value || '' ) );
			row.append( icon, copy );
			list.appendChild( row );
		} );
		box.appendChild( list );
		if ( data.page && data.page.url ) {
			const page = el( 'a', 'asi-contact__page', data.page.label || '' );
			page.href = safeUrl( data.page.url );
			box.appendChild( page );
		}
		log.appendChild( box );
	}

	function renderBot( data ) {
		if ( data.text && ! ( data.notice && data.notice.replace ) ) {
			bubble( 'bot', data.text );
		}
		if ( data.notice ) {
			notice( data.notice );
		}
		quote( data.quote );
		places( data.places );
		compare( data.compare );
		table( data.table );
		file( data.file );
		if ( data.cards && data.cards.length ) {
			const grid = el( 'div', 'asi-cards' );
			data.cards.forEach( function ( item, index ) {
				grid.appendChild( card( item, index + 1 ) );
			} );
			tagQuery( grid, data );
			log.appendChild( grid );
		}
		note( data.note );
		if ( data.extra && data.extra.cards && data.extra.cards.length ) {
			if ( data.extra.text ) {
				bubble( 'bot', data.extra.text );
			}
			const similar = el( 'div', 'asi-cards asi-cards--similar' );
			data.extra.cards.forEach( function ( item ) {
				similar.appendChild( card( item ) );
			} );
			tagQuery( similar, data );
			log.appendChild( similar );
		}
		contact( data.contact );
		if ( data.followup ) {
			bubble( 'bot', data.followup );
		}
		if ( data.links && data.links.length ) {
			const list = el( 'div', 'asi-links' );
			data.links.forEach( function ( item ) {
				const a = el( 'a', 'asi-link' );
				a.href = safeUrl( item.url );
				// Otros sitios (y WhatsApp) se abren en otra pestaña: el cliente no pierde la conversación.
				if ( item.external || isExternal( a.href ) ) {
					a.target = '_blank';
					a.rel = 'noopener';
				}
				if ( item.id ) {
					a.dataset.asiId = item.id;
				}
				// Botón con su flecha: el texto de la página ya no se repite aquí (la respuesta lo dice).
				a.appendChild( el( 'span', 'asi-link__title', item.title ) );
				const arrow = el( 'span', 'asi-link__arrow' );
				arrow.setAttribute( 'aria-hidden', 'true' );
				arrow.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14"><path fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6"/></svg>';
				a.appendChild( arrow );
				list.appendChild( a );
			} );
			tagQuery( list, data );
			log.appendChild( list );
		}
	}

	function renderChips( data ) {
		const old = log.querySelector( '.asi-chips' );
		if ( old ) {
			old.remove();
		}
		const chips = ( data.suggestions || [] ).slice( 0, 5 );
		if ( data.more && data.more_label ) {
			chips.unshift( data.more_label );
		}
		if ( ! chips.length ) {
			return;
		}
		const bar = el( 'div', 'asi-chips' );
		chips.forEach( function ( text, index ) {
			const chip = el( 'button', 'asi-chip' + ( 0 === index && data.more ? ' asi-chip--primary' : '' ), text );
			chip.type = 'button';
			chip.addEventListener( 'click', function () {
				ask( text );
			} );
			bar.appendChild( chip );
		} );
		log.appendChild( bar );
	}

	function renderAll() {
		log.style.scrollBehavior = 'auto';
		log.textContent = '';
		let last = null;
		items.forEach( function ( item ) {
			if ( 'user' === item.who ) {
				bubble( 'user', item.text );
			} else {
				renderBot( item.data );
				last = item.data;
			}
		} );
		if ( last ) {
			renderChips( last );
		}
		scrollDown();
		log.style.scrollBehavior = '';
	}

	function typing( show ) {
		const old = log.querySelector( '.asi-typing' );
		if ( old ) {
			old.remove();
		}
		if ( show ) {
			const row = el( 'div', 'asi-msg asi-msg--bot asi-typing' );
			const dots = el( 'div', 'asi-msg__bubble' );
			dots.setAttribute( 'aria-label', ui.typing );
			dots.innerHTML = '<span></span><span></span><span></span>';
			row.appendChild( dots );
			log.appendChild( row );
			scrollDown();
		}
	}

	function scrollDown() {
		log.scrollTop = log.scrollHeight;
	}

	// Con una respuesta larga (tarjetas), se muestra desde la pregunta: se lee de arriba hacia abajo.
	function scrollToQuestion( row ) {
		const target = row.offsetTop - 12; // El registro es position: relative.
		log.scrollTop = Math.min( target, log.scrollHeight );
	}

	// ---------- La página alrededor del chat ----------

	// Alto del encabezado fijo del sitio (si lo hay), para no dejar el chat debajo de él.
	function headerOffset() {
		let offset = 0;
		const x = Math.round( window.innerWidth / 2 );
		for ( let y = 2; y <= 240; y += 8 ) {
			let node = document.elementFromPoint( x, y );
			while ( node && node !== document.body && node !== document.documentElement ) {
				if ( root.contains( node ) ) {
					break;
				}
				const position = window.getComputedStyle( node ).position;
				if ( 'fixed' === position || 'sticky' === position ) {
					const rect = node.getBoundingClientRect();
					if ( rect.top <= 4 && rect.bottom > offset ) {
						offset = rect.bottom;
					}
				}
				node = node.parentElement;
			}
		}
		return Math.min( Math.round( offset ), 240 );
	}

	// Al escribir y al responder: el chat completo a la vista, justo debajo del encabezado del sitio,
	// esté donde esté el usuario en la página.
	function bringIntoView( jump ) {
		const top = headerOffset();
		const box = root.getBoundingClientRect();
		if ( box.top >= top - 2 && box.bottom <= window.innerHeight + 2 ) {
			return;
		}
		const target = Math.max( 0, window.pageYOffset + box.top - top - 12 );
		try {
			window.scrollTo( { top: target, behavior: true === jump ? 'instant' : 'smooth' } );
		} catch ( e ) {
			window.scrollTo( 0, target );
		}
	}

	// Barra de escribir flotante: si el pie del chat queda debajo de la pantalla, la barra se queda
	// abajo a la vista y se asienta en su lugar al llegar al final del chat.
	let docked = false;
	let dockReady = 'complete' === document.readyState;
	let dockTicking = false;

	// Si algún contenedor tiene transform, "fixed" se mediría contra él y no contra la pantalla.
	function canFloat() {
		for ( let node = root.parentElement; node && node !== document.documentElement; node = node.parentElement ) {
			const style = window.getComputedStyle( node );
			if ( 'none' !== style.transform || 'none' !== style.filter || 'none' !== style.perspective ) {
				return false;
			}
		}
		return true;
	}

	// Botones flotantes del sitio (WhatsApp, volver arriba…) que quedarían encima de la barra: se suben
	// todos juntos con margin-bottom, que no rompe cómo se apilan entre ellos. La lista se arma una vez
	// (y al cambiar el tamaño de la ventana); en cada scroll solo se ajusta cuánto suben.
	let floaters = null;

	function findFloaters() {
		floaters = [];
		document.querySelectorAll( 'body *' ).forEach( function ( node ) {
			if ( root.contains( node ) || 'fixed' !== window.getComputedStyle( node ).position ) {
				return;
			}
			const rect = node.getBoundingClientRect();
			// Botones pequeños de la mitad de abajo; no barras de ancho completo (avisos de cookies).
			if ( rect.height > 0 && rect.width < window.innerWidth * 0.6 && rect.top > window.innerHeight / 2 ) {
				floaters.push( { node: node, margin: node.style.marginBottom, base: parseFloat( window.getComputedStyle( node ).marginBottom ) || 0 } );
			}
		} );
	}

	function liftFloaters() {
		if ( null === floaters ) {
			findFloaters();
		}
		const bar = form.getBoundingClientRect();
		// Dónde estaría cada botón sin subirlo, y cuánto hay que subirlos (lo mismo a todos, para que
		// sigan apilados igual) para que el más bajo quede justo arriba de la barra.
		let need = 0;
		const beside = floaters.filter( function ( item ) {
			const rect = item.node.getBoundingClientRect();
			item.bottom = rect.bottom + ( ( parseFloat( item.node.style.marginBottom ) || item.base ) - item.base );
			item.top    = item.bottom - rect.height;
			return rect.right > bar.left && rect.left < bar.right;
		} );
		beside.forEach( function ( item ) {
			if ( item.top < bar.bottom && item.bottom > bar.top - 8 ) {
				need = Math.max( need, Math.round( item.bottom - bar.top + 8 ) );
			}
		} );
		floaters.forEach( function ( item ) {
			item.node.style.marginBottom = need > 0 && beside.indexOf( item ) > -1 ? ( item.base + need ) + 'px' : item.margin;
		} );
	}

	function setDocked( on ) {
		if ( on === docked ) {
			if ( on ) {
				placeDock();
			}
			return;
		}
		docked = on;
		if ( on ) {
			spacer.style.height = form.offsetHeight + 'px';
			spacer.hidden = false;
			form.classList.add( 'is-floating' );
			placeDock();
		} else {
			form.classList.remove( 'is-floating' );
			form.style.left = '';
			form.style.width = '';
			spacer.hidden = true;
		}
	}

	// Mismo ancho y misma posición que el chat.
	function placeDock() {
		const box = root.getBoundingClientRect();
		form.style.left = box.left + 'px';
		form.style.width = box.width + 'px';
	}

	function updateDock() {
		dockTicking = false;
		if ( ! dockReady ) {
			return;
		}
		const box    = root.getBoundingClientRect();
		const height = docked ? spacer.offsetHeight : form.offsetHeight;
		const view   = window.innerHeight;
		// Flota mientras el chat está en pantalla pero su pie (la barra) todavía no.
		setDocked( box.bottom > view + 1 && box.top < view - height - 24 );
		liftFloaters();
	}

	function requestDock() {
		if ( ! dockTicking ) {
			dockTicking = true;
			window.requestAnimationFrame( updateDock );
		}
	}

	if ( canFloat() ) {
		window.addEventListener( 'scroll', requestDock, { passive: true } );
		window.addEventListener( 'resize', function () {
			// Al cambiar de tamaño los botones pueden ser otros o estar en otro lugar: se vuelven a buscar
			// (antes se dejan como estaban, para medir su margen original).
			( floaters || [] ).forEach( function ( item ) {
				item.node.style.marginBottom = item.margin;
			} );
			floaters = null;
			requestDock();
		} );
		if ( 'function' === typeof window.ResizeObserver ) {
			new window.ResizeObserver( requestDock ).observe( root );
		}
		// Hasta que carga la página (imágenes, encabezado) no se decide: así no da saltos al abrir.
		if ( ! dockReady ) {
			const ready = function () {
				dockReady = true;
				requestDock();
			};
			window.addEventListener( 'load', ready );
			window.setTimeout( ready, 2500 );
		}
	}

	// ---------- Conversación ----------

	function ask( message ) {
		message = ( message || '' ).trim();
		if ( ! message || busy ) {
			return;
		}
		busy = true;
		root.classList.add( 'is-busy' );
		const chips = log.querySelector( '.asi-chips' );
		if ( chips ) {
			chips.remove();
		}
		items.push( { who: 'user', text: message } );
		const question = bubble( 'user', message );
		typing( true );
		bringIntoView();

		const headers = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}
		// Si la red corta el mensaje (no llegó respuesta), se reintenta una vez solo. No mientras pide
		// datos para una cotización: ese envío podría haber llegado y se duplicaría el aviso a ventas.
		const body  = JSON.stringify( { message: message, lang: config.lang, state: state } );
		const retry = ! ( state && state.pending && state.pending.action );
		const post  = function () {
			return fetch( config.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: body,
			} );
		};
		post()
			.catch( function ( error ) {
				if ( ! retry ) {
					throw error;
				}
				return new Promise( function ( resolve ) {
					setTimeout( resolve, 1500 );
				} ).then( post );
			} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { status: response.status, data: data };
				} );
			} )
			.then( function ( result ) {
				typing( false );
				if ( 200 !== result.status ) {
					bubble( 'bot', 429 === result.status ? ui.rate_limited : ui.error );
					return;
				}
				state = result.data.state || {};
				delete result.data.state;
				// Búsqueda que produjo estos resultados: si abre uno, el asistente aprende.
				result.data.clickq = state.q || message;
				items.push( { who: 'bot', data: result.data } );
				renderBot( result.data );
				renderChips( result.data );
				save();
			} )
			.catch( function () {
				typing( false );
				bubble( 'bot', ui.error );
			} )
			.finally( function () {
				busy = false;
				root.classList.remove( 'is-busy' );
				scrollToQuestion( question );
				bringIntoView();
			} );
	}

	// Aprendizaje por clics: qué resultado abrió después de qué búsqueda (sin datos personales).
	log.addEventListener( 'click', function ( event ) {
		const link = event.target.closest( 'a[data-asi-id]' );
		const group = link ? link.closest( '[data-asi-q]' ) : null;
		// Reportería: la ficha que se abre sabe que viene del chat.
		if ( link ) {
			try {
				localStorage.setItem( 'asi_from_chat', JSON.stringify( { id: Number( link.dataset.asiId ), t: Date.now() } ) );
			} catch ( e ) {}
		}
		if ( ! link || ! group || ! config.clickEndpoint ) {
			return;
		}
		try {
			fetch( config.clickEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: config.nonce ? { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce } : { 'Content-Type': 'application/json' },
				body: JSON.stringify( { q: group.dataset.asiQ, id: Number( link.dataset.asiId ), lang: config.lang } ),
			} ).catch( function () {} );
		} catch ( e ) {}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		const message = input.value;
		input.value = '';
		ask( message );
	} );

	reset.addEventListener( 'click', function () {
		state = {};
		items = [ { who: 'bot', data: config.initial } ];
		save();
		renderAll();
		input.focus();
	} );

	// ---------- Inicio ----------

	load();
	if ( ! items.length ) {
		items = [ { who: 'bot', data: config.initial } ];
	}
	renderAll();

	// ¿Llegó buscando desde el sitio? Una recarga o un atrás/adelante no es una búsqueda nueva.
	function incomingSearch() {
		try {
			const entry = window.performance.getEntriesByType( 'navigation' )[ 0 ];
			if ( entry && entry.type ) {
				return 'reload' !== entry.type && 'back_forward' !== entry.type;
			}
		} catch ( e ) {}
		const legacy = window.performance && window.performance.navigation ? window.performance.navigation.type : 0;
		return 1 !== legacy && 2 !== legacy;
	}

	// Llegó desde el buscador del sitio: conversación nueva solo con esa búsqueda (la anterior se
	// quedaba arriba y parecía que no había pasado nada), y se limpia la URL.
	if ( config.query && incomingSearch() ) {
		state = {};
		items = [ { who: 'bot', data: config.initial } ];
		renderAll();
		ask( config.query );
		// Al llegar, sin animación (y otra vez al terminar de cargar: el navegador puede devolver el
		// scroll arriba).
		bringIntoView( true );
		if ( 'complete' !== document.readyState ) {
			window.addEventListener( 'load', function () {
				bringIntoView( true );
			} );
		}
	}
	if ( config.query ) {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'q' );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
	}
} )();
