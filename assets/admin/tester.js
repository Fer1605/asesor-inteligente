/**
 * Pestaña Probar: conversación de prueba con los estilos reales del chat.
 * Mantiene el estado en memoria y lo envía en cada mensaje, igual que el chat público.
 */
( function () {
	const log = document.getElementById( 'asi-log' );
	const form = document.getElementById( 'asi-form' );
	const input = document.getElementById( 'asi-input' );
	const lang = document.getElementById( 'asi-lang' );
	const json = document.getElementById( 'asi-json' );
	if ( ! log || ! form ) {
		return;
	}
	let state = {};

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

	function bubble( who, text ) {
		const row = el( 'div', 'asi-msg asi-msg--' + who );
		row.appendChild( el( 'div', 'asi-msg__bubble', text ) );
		log.appendChild( row );
		return row;
	}

	function render( data ) {
		if ( data.text ) {
			bubble( 'bot', data.text );
		}
		if ( data.quote && data.quote.length ) {
			const block = el( 'blockquote', 'asi-quote' );
			data.quote.forEach( function ( line ) {
				block.appendChild( el( 'p', 'asi-quote__line', line ) );
			} );
			log.appendChild( block );
		}
		if ( data.compare && data.compare.rows && data.compare.rows.length ) {
			const wrap = el( 'div', 'asi-compare' );
			const table = el( 'table', 'asi-compare__table' );
			const head = el( 'tr' );
			head.appendChild( el( 'th' ) );
			data.compare.columns.forEach( function ( title, index ) {
				const th = el( 'th' );
				const a = el( 'a', '', title );
				a.href = data.compare.urls[ index ];
				a.target = '_blank';
				th.appendChild( a );
				head.appendChild( th );
			} );
			const thead = el( 'thead' );
			thead.appendChild( head );
			const body = el( 'tbody' );
			data.compare.rows.forEach( function ( row ) {
				const tr = el( 'tr' );
				tr.appendChild( el( 'th', '', row[ 0 ] ) );
				tr.appendChild( el( 'td', '', row[ 1 ] ) );
				tr.appendChild( el( 'td', '', row[ 2 ] ) );
				body.appendChild( tr );
			} );
			table.append( thead, body );
			wrap.appendChild( table );
			log.appendChild( wrap );
		}
		cards( data.cards );
		if ( data.places && data.places.length ) {
			const grid = el( 'div', 'asi-places' );
			data.places.forEach( function ( item ) {
				const a = el( 'a', 'asi-place' );
				a.href = item.url;
				a.target = '_blank';
				a.appendChild( el( 'strong', 'asi-place__title', item.title ) );
				if ( item.summary ) {
					a.appendChild( el( 'span', 'asi-place__summary', item.summary ) );
				}
				grid.appendChild( a );
			} );
			log.appendChild( grid );
		}
		if ( data.extra && data.extra.cards && data.extra.cards.length ) {
			if ( data.extra.text ) {
				bubble( 'bot', data.extra.text );
			}
			cards( data.extra.cards );
		}
		renderRest( data );
	}

	function cards( list ) {
		if ( list && list.length ) {
			const grid = el( 'div', 'asi-cards' );
			list.forEach( function ( card ) {
				const link = el( 'a', 'asi-card' );
				link.href = card.url;
				link.target = '_blank';
				const media = el( 'div', 'asi-card__media' + ( card.image ? '' : ' asi-card__media--empty' ) );
				if ( card.image ) {
					const img = el( 'img' );
					img.src = card.image;
					img.alt = '';
					media.appendChild( img );
				}
				( card.flags || [] ).slice( 0, 2 ).forEach( function ( flag ) {
					media.appendChild( el( 'span', 'asi-card__flag', flag ) );
				} );
				const body = el( 'div', 'asi-card__body' );
				const meta = [ card.category, card.brand ].filter( Boolean ).join( ' · ' );
				if ( meta ) {
					body.appendChild( el( 'span', 'asi-card__meta', meta ) );
				}
				body.appendChild( el( 'strong', 'asi-card__title', card.title ) );
				if ( card.code ) {
					body.appendChild( el( 'span', 'asi-card__code', card.code ) );
				}
				if ( card.price ) {
					body.appendChild( el( 'span', 'asi-card__price', card.price ) );
				}
				link.append( media, body );
				grid.appendChild( link );
			} );
			log.appendChild( grid );
		}
	}

	function renderRest( data ) {
		if ( data.followup ) {
			bubble( 'bot', data.followup );
		}
		if ( data.links && data.links.length ) {
			const list = el( 'div', 'asi-links' );
			data.links.forEach( function ( item ) {
				const a = el( 'a', 'asi-link' );
				a.href = item.url;
				a.target = '_blank';
				a.appendChild( el( 'span', 'asi-link__title', item.title ) );
				if ( item.summary ) {
					a.appendChild( el( 'span', 'asi-link__summary', item.summary ) );
				}
				list.appendChild( a );
			} );
			log.appendChild( list );
		}
		const chips = ( data.suggestions || [] ).slice( 0, 5 );
		if ( data.more && data.more_label ) {
			chips.unshift( data.more_label );
		}
		if ( chips.length ) {
			const bar = el( 'div', 'asi-chips' );
			chips.forEach( function ( text, index ) {
				const chip = el( 'button', 'asi-chip' + ( 0 === index && data.more ? ' asi-chip--primary' : '' ), text );
				chip.type = 'button';
				chip.addEventListener( 'click', function () {
					bar.remove();
					send( text );
				} );
				bar.appendChild( chip );
			} );
			log.appendChild( bar );
		}
		log.scrollTop = log.scrollHeight;
	}

	function send( message ) {
		bubble( 'user', message );
		const waiting = el( 'div', 'asi-msg asi-msg--bot asi-typing' );
		const dots = el( 'div', 'asi-msg__bubble' );
		dots.innerHTML = '<span></span><span></span><span></span>';
		waiting.appendChild( dots );
		log.appendChild( waiting );
		log.scrollTop = log.scrollHeight;
		const started = performance.now();

		fetch( window.asiTester.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.asiTester.nonce },
			body: JSON.stringify( { message: message, lang: lang.value, state: state } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				waiting.remove();
				json.textContent = Math.round( performance.now() - started ) + ' ms\n\n' + JSON.stringify( result.data, null, 2 );
				if ( ! result.ok ) {
					bubble( 'bot', 'Error: ' + ( result.data.message || 'sin respuesta' ) );
					return;
				}
				state = result.data.state || {};
				render( result.data );
			} )
			.catch( function ( error ) {
				waiting.remove();
				bubble( 'bot', 'Error de red: ' + error.message );
			} );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		const message = input.value.trim();
		if ( message ) {
			input.value = '';
			send( message );
		}
	} );

	function reset() {
		state = {};
		log.textContent = '';
		json.textContent = '';
		send( 'hola' );
	}
	lang.addEventListener( 'change', reset );
	document.getElementById( 'asi-reset' ).addEventListener( 'click', reset );
	reset();
} )();
