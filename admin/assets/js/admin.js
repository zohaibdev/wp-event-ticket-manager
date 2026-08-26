/**
 * Event Ticket Manager - admin behaviour.
 *
 * Toggles, delete confirmations, shortcode copying and the custom field manager.
 */
( function () {
	'use strict';

	var strings = window.etmAdmin || {};

	/**
	 * Toggle a target element from a checkbox.
	 */
	function initToggles() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-etm-toggle]' ), function ( checkbox ) {
			var target = document.querySelector( checkbox.getAttribute( 'data-etm-toggle' ) );

			if ( ! target ) {
				return;
			}

			checkbox.addEventListener( 'change', function () {
				target.style.display = checkbox.checked ? '' : 'none';
			} );
		} );
	}

	/**
	 * Ask before destructive links.
	 */
	function initConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.etm-delete, .etm-delete-booking' );

			if ( ! link ) {
				return;
			}

			var message = link.classList.contains( 'etm-delete-booking' )
				? ( strings.confirmDeleteBooking || 'Delete this booking?' )
				: ( strings.confirmDelete || 'Delete this permanently?' );

			if ( ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	}

	/**
	 * Click to copy shortcodes and the webhook URL.
	 */
	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var node = event.target.closest( '[data-etm-copy]' );

			if ( ! node ) {
				return;
			}

			var text = node.textContent.trim();

			function flash() {
				node.classList.add( 'is-copied' );
				window.setTimeout( function () {
					node.classList.remove( 'is-copied' );
				}, 1200 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( flash ).catch( function () {} );

				return;
			}

			var range = document.createRange();

			range.selectNodeContents( node );
			window.getSelection().removeAllRanges();
			window.getSelection().addRange( range );
			flash();
		} );
	}

	/**
	 * Add, remove and reorder custom fields.
	 */
	function initFieldManager() {
		var template = document.getElementById( 'etm-field-row-template' );

		document.addEventListener( 'click', function ( event ) {
			var addButton = event.target.closest( '.etm-add-field' );

			if ( addButton && template ) {
				event.preventDefault();

				var group = addButton.getAttribute( 'data-etm-group' );
				var index = parseInt( addButton.getAttribute( 'data-etm-next-index' ), 10 ) || 0;
				var body = document.querySelector( '[data-etm-field-group="' + group + '"] [data-etm-field-rows]' );

				if ( ! body ) {
					return;
				}

				var markup = template.innerHTML
					.split( 'fields[__GROUP__][0]' )
					.join( 'fields[' + group + '][' + index + ']' );
				var holder = document.createElement( 'tbody' );

				holder.innerHTML = markup;

				var row = holder.querySelector( 'tr' );

				if ( row ) {
					body.appendChild( row );
					addButton.setAttribute( 'data-etm-next-index', String( index + 1 ) );

					var firstInput = row.querySelector( 'input[type="text"]' );

					if ( firstInput ) {
						firstInput.focus();
					}
				}

				return;
			}

			var removeButton = event.target.closest( '.etm-remove-field' );

			if ( removeButton ) {
				event.preventDefault();

				var rowToRemove = removeButton.closest( '[data-etm-field-row]' );

				if ( rowToRemove && rowToRemove.parentNode ) {
					rowToRemove.parentNode.removeChild( rowToRemove );
				}

				return;
			}

			var moveButton = event.target.closest( '[data-etm-move]' );

			if ( moveButton ) {
				event.preventDefault();

				var row = moveButton.closest( '[data-etm-field-row]' );

				if ( ! row ) {
					return;
				}

				if ( moveButton.getAttribute( 'data-etm-move' ) === 'up' ) {
					if ( row.previousElementSibling ) {
						row.parentNode.insertBefore( row, row.previousElementSibling );
					}
				} else if ( row.nextElementSibling ) {
					row.parentNode.insertBefore( row.nextElementSibling, row );
				}
			}
		} );

		// Only choice fields need the options box.
		document.addEventListener( 'change', function ( event ) {
			var select = event.target.closest( '[data-etm-field-type]' );

			if ( ! select ) {
				return;
			}

			var row = select.closest( '[data-etm-field-row]' );
			var options = row ? row.querySelector( '.etm-options' ) : null;

			if ( options ) {
				var needsOptions = select.value === 'select' || select.value === 'radio';

				options.style.opacity = needsOptions ? '1' : '.45';
			}
		} );
	}

	/**
	 * Colour pickers on the Customizer tab, wired to the live preview.
	 */
	function initColorPickers() {
		var $ = window.jQuery;
		var fields = document.querySelectorAll( '.etm-color-field' );

		if ( ! fields.length ) {
			return;
		}

		var preview = document.querySelector( '[data-etm-preview]' );
		var varMap = {
			'primary_color': '--preview-primary',
			'secondary_color': '--preview-secondary',
			'accent_color': '--preview-accent',
			'text_color': '--preview-text',
			'background_color': '--preview-bg',
			'button_text_color': '--preview-on-primary'
		};

		function applyPreview( input, value ) {
			if ( ! preview || ! value ) {
				return;
			}

			var match = ( input.name || '' ).match( /\[([a-z_]+)\]$/ );

			if ( match && varMap[ match[ 1 ] ] ) {
				preview.style.setProperty( varMap[ match[ 1 ] ], value );
			}
		}

		if ( $ && typeof $.fn.wpColorPicker === 'function' ) {
			$( fields ).each( function () {
				var input = this;

				$( input ).wpColorPicker( {
					change: function ( event, ui ) {
						applyPreview( input, ui.color.toString() );
					},
					clear: function () {
						applyPreview( input, input.getAttribute( 'data-default-color' ) );
					}
				} );
			} );

			return;
		}

		// Graceful fallback when the colour picker script is unavailable.
		Array.prototype.forEach.call( fields, function ( input ) {
			input.type = 'color';
			input.addEventListener( 'input', function () {
				applyPreview( input, input.value );
			} );
		} );
	}

	/**
	 * Add, remove and reorder event ticket types.
	 */
	function initTicketRepeater() {
		var template = document.getElementById( 'etm-ticket-row-template' );
		var wrapper = document.querySelector( '[data-etm-tickets]' );

		if ( ! wrapper ) {
			return;
		}

		var rows = wrapper.querySelector( '[data-etm-ticket-rows]' );

		wrapper.addEventListener( 'click', function ( event ) {
			var addButton = event.target.closest( '.etm-add-ticket' );

			if ( addButton && template && rows ) {
				event.preventDefault();

				var index = parseInt( addButton.getAttribute( 'data-etm-next-ticket-index' ), 10 ) || 0;
				var markup = template.innerHTML
					.split( 'event_tickets[9999]' )
					.join( 'event_tickets[' + index + ']' );
				var holder = document.createElement( 'div' );

				holder.innerHTML = markup;

				var row = holder.querySelector( '[data-etm-ticket-row]' );

				if ( row ) {
					rows.appendChild( row );
					addButton.setAttribute( 'data-etm-next-ticket-index', String( index + 1 ) );

					var firstInput = row.querySelector( 'input[type="text"]' );

					if ( firstInput ) {
						firstInput.focus();
					}
				}

				return;
			}

			var removeButton = event.target.closest( '.etm-remove-ticket' );

			if ( removeButton ) {
				event.preventDefault();

				var doomed = removeButton.closest( '[data-etm-ticket-row]' );

				// Always leave one row so the form still submits a ticket type.
				if ( doomed && rows && rows.querySelectorAll( '[data-etm-ticket-row]' ).length > 1 ) {
					rows.removeChild( doomed );
				} else if ( doomed ) {
					Array.prototype.forEach.call( doomed.querySelectorAll( 'input' ), function ( input ) {
						if ( input.type === 'checkbox' ) {
							input.checked = true;
						} else if ( input.name.indexOf( '[id]' ) === -1 ) {
							input.value = '';
						}
					} );
				}

				return;
			}

			var moveButton = event.target.closest( '[data-etm-move]' );

			if ( moveButton && rows ) {
				event.preventDefault();

				var row = moveButton.closest( '[data-etm-ticket-row]' );

				if ( ! row ) {
					return;
				}

				if ( moveButton.getAttribute( 'data-etm-move' ) === 'up' ) {
					if ( row.previousElementSibling ) {
						rows.insertBefore( row, row.previousElementSibling );
					}
				} else if ( row.nextElementSibling ) {
					rows.insertBefore( row.nextElementSibling, row );
				}
			}
		} );
	}

	/**
	 * Radio-driven show/hide used by the coupon and fee scope pickers, plus the
	 * RSVP email field on the event editor.
	 */
	function initScopeToggles() {
		function apply( input ) {
			var show = input.getAttribute( 'data-etm-toggle-show' );
			var hide = input.getAttribute( 'data-etm-toggle-hide' );
			var target = document.querySelector( show || hide );

			if ( target ) {
				target.style.display = show ? '' : 'none';
			}
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-etm-toggle-show], [data-etm-toggle-hide]' ),
			function ( input ) {
				input.addEventListener( 'change', function () {
					if ( input.checked ) {
						apply( input );
					}
				} );
			}
		);

		// Shows/hides an element based on whether a select is set to "rsvp".
		// Nothing currently uses data-etm-toggle-target; left in place in case
		// a future field needs the same type-conditional visibility.
		Array.prototype.forEach.call( document.querySelectorAll( '[data-etm-toggle-target]' ), function ( select ) {
			var target = document.querySelector( select.getAttribute( 'data-etm-toggle-target' ) );

			if ( ! target ) {
				return;
			}

			function sync() {
				target.style.display = select.value === 'rsvp' ? '' : 'none';
			}

			select.addEventListener( 'change', sync );
			sync();
		} );
	}

	/**
	 * Verify the saved Stripe credentials without leaving the settings page.
	 *
	 * The request carries only a nonce: the keys stay on the server, and the
	 * response carries an account id and name at most. Deliberately reads the
	 * SAVED keys rather than whatever is typed in the form, because the point
	 * is to confirm what checkout will actually use.
	 */
	function initStripeTest() {
		var button = document.querySelector( '[data-etm-test-stripe]' );

		if ( ! button ) {
			return;
		}

		var config = window.etmAdmin || {};
		var label = document.querySelector( '[data-etm-conn-label]' );
		var detail = document.querySelector( '[data-etm-conn-detail]' );

		button.addEventListener( 'click', function () {
			if ( button.disabled ) {
				return;
			}

			button.disabled = true;
			button.textContent = config.testing || 'Testing...';

			var body = new FormData();

			body.append( 'action', 'etm_test_stripe_connection' );
			body.append( 'nonce', config.stripeTestNonce || '' );

			fetch( config.ajaxUrl || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			} ).then( function ( response ) {
				return response.text().then( function ( text ) {
					try {
						return JSON.parse( text );
					} catch ( e ) {
						throw new Error( 'HTTP ' + response.status );
					}
				} );
			} ).then( function ( json ) {
				var data = ( json && json.data ) || {};
				var ok = !! ( json && json.success );

				if ( label ) {
					label.textContent = data.label || '';
					label.className = 'etm-conn etm-conn--' + ( data.status || 'not_connected' );
				}

				if ( detail ) {
					if ( ok ) {
						var parts = [];

						if ( data.business_name ) {
							parts.push( data.business_name );
						}

						if ( data.account_id ) {
							parts.push( data.account_id );
						}

						detail.textContent = parts.length ? parts.join( ' — ' ) : ( data.message || '' );
					} else {
						detail.textContent = data.reason || data.message || ( config.genericError || '' );
					}
				}
			} ).catch( function ( error ) {
				if ( label ) {
					label.textContent = 'Connection Failed';
					label.className = 'etm-conn etm-conn--failed';
				}

				if ( detail ) {
					detail.textContent = ( config.genericError || 'Request failed.' ) + ' (' + error.message + ')';
				}
			} ).then( function () {
				button.disabled = false;
				button.textContent = config.testConnection || 'Test Connection';
			} );
		} );
	}

	function init() {
		initScopeToggles();
		initToggles();
		initConfirmations();
		initCopy();
		initFieldManager();
		initColorPickers();
		initTicketRepeater();
		initStripeTest();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
