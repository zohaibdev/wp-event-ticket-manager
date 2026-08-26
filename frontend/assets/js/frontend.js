/**
 * Event Ticket Manager - frontend behaviour.
 *
 * One controller per [event_ticket] block. An event can offer any number of
 * ticket types; each gets its own stepper, the totals combine them, and the
 * checkout modal generates one attendee form per ticket sold - the purchaser
 * being the first attendee of the first selected type.
 *
 * The browser never sees a secret key and never decides whether a booking is
 * paid: it confirms the PaymentIntent with Stripe, then asks the server to
 * verify the result before the Order Complete step is shown. Prices here are
 * for display only; the server recalculates everything from the database.
 */
( function () {
	'use strict';

	var config = window.etmConfig || {};
	var i18n = config.i18n || {};

	/**
	 * Re-read the localised config from the page.
	 *
	 * This file is evaluated as soon as it is parsed, which is not guaranteed
	 * to be after the inline etmConfig script WordPress prints before it: a
	 * deferred, concatenated or reordered build (common once a caching or
	 * optimisation plugin is involved) can invert the two. Captured once, that
	 * leaves config permanently empty even though window.etmConfig exists a
	 * moment later - the block still renders and the steppers still work,
	 * because neither needs it, but every price loses its currency symbol and
	 * every AJAX call goes to the URL "undefined" and comes back as HTML.
	 *
	 * Re-reading instead of trusting the first look costs nothing and keeps a
	 * late definition from silently disabling the whole checkout.
	 */
	function syncConfig() {
		if ( window.etmConfig && window.etmConfig.ajaxUrl ) {
			config = window.etmConfig;
			i18n = config.i18n || {};

			return;
		}

		if ( config.ajaxUrl ) {
			return;
		}

		// The inline config never arrived. Rebuild what the checkout cannot work
		// without from the block's own markup, which is part of the shortcode
		// output and so cannot be stripped or reordered by a script optimiser.
		// Translated strings are not repeated there; every i18n lookup in this
		// file already carries an English default, so the checkout degrades to
		// untranslated copy rather than to a dead button.
		var block = document.querySelector( '[data-etm-block][data-etm-ajax-url]' );

		if ( ! block ) {
			return;
		}

		var url = block.getAttribute( 'data-etm-ajax-url' );

		if ( ! url ) {
			return;
		}

		var decimals = parseInt( block.getAttribute( 'data-etm-currency-decimals' ), 10 );

		config = {
			ajaxUrl: url,
			nonce: block.getAttribute( 'data-etm-nonce' ) || '',
			currency: {
				symbol: block.getAttribute( 'data-etm-currency-symbol' ) || '',
				decimals: isNaN( decimals ) ? 2 : decimals
			},
			appearance: ( window.etmConfig && window.etmConfig.appearance ) || {},
			i18n: ( window.etmConfig && window.etmConfig.i18n ) || i18n || {}
		};

		i18n = config.i18n || {};

		if ( window.console && console.warn ) {
			console.warn( 'etm: etmConfig was missing; recovered the checkout configuration from the block markup. A caching or script-optimisation plugin is likely stripping inline scripts.' );
		}
	}

	/**
	 * Format an amount using the configured currency.
	 *
	 * @param {number} amount Amount.
	 * @return {string} Formatted price.
	 */
	function formatPrice( amount ) {
		var currency = config.currency || {};
		var decimals = typeof currency.decimals === 'number' ? currency.decimals : 2;
		var symbol = currency.symbol || '';
		var fixed = ( Math.round( amount * 100 ) / 100 ).toFixed( decimals );
		var parts = fixed.split( '.' );

		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, ',' );

		return symbol + parts.join( '.' );
	}

	/**
	 * POST to admin-ajax and return the decoded response.
	 *
	 * @param {FormData} formData Body.
	 * @return {Promise<Object>} Parsed response.
	 */
	function postAjax( formData ) {
		syncConfig();

		if ( ! config.ajaxUrl ) {
			// Without this the request would go to the relative URL "undefined"
			// and fail as an unexplained parse error further down.
			return Promise.reject( new Error(
				'Event Ticket Manager: the page did not load its checkout configuration (etmConfig), so the request could not be sent. This is usually a caching or script-optimisation plugin reordering or stripping inline scripts.'
			) );
		}

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		} ).then( function ( response ) {
			// Read as text first: a PHP fatal, a notice printed ahead of the
			// payload, a login redirect or a security plugin blocking
			// admin-ajax all arrive as non-JSON, and collapsing every one of
			// them into the same generic message makes them indistinguishable.
			return response.text().then( function ( body ) {
				try {
					return JSON.parse( body );
				} catch ( e ) {
					var snippet = String( body || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 200 );

					if ( window.console && console.error ) {
						var action = ( formData && formData.get ) ? formData.get( 'action' ) : '';

						console.error( 'etm: non-JSON response from admin-ajax', action, response.status, body );
					}

					throw new Error(
						( i18n.genericError || 'Request failed.' ) +
						' (HTTP ' + response.status + ( snippet ? ': ' + snippet : '' ) + ')'
					);
				}
			} );
		} );
	}

	/**
	 * Build one label/value row for a summary list.
	 *
	 * @param {string} label Row label.
	 * @param {string} value Row value.
	 * @param {boolean} strong Render as the emphasised total row.
	 * @return {HTMLElement} Row element.
	 */
	function summaryRow( label, value, strong ) {
		var row = document.createElement( 'div' );
		var dt = document.createElement( 'dt' );
		var dd = document.createElement( 'dd' );

		row.className = 'etm-summary__row' + ( strong ? ' etm-summary__row--total' : '' );
		dt.textContent = label;

		if ( strong ) {
			var bold = document.createElement( 'strong' );

			bold.textContent = value;
			dd.appendChild( bold );
		} else {
			dd.textContent = value;
		}

		row.appendChild( dt );
		row.appendChild( dd );

		return row;
	}

	/**
	 * Controller for a single ticket block.
	 *
	 * @param {HTMLElement} block Block root element.
	 */
	function TicketBlock( block ) {
		this.block = block;
		this.eventId = parseInt( block.getAttribute( 'data-event-id' ), 10 ) || 0;
		this.isRsvp = block.getAttribute( 'data-is-rsvp' ) === '1';

		// -1 means the event has no participant limit of its own.
		this.eventCap = parseInt( block.getAttribute( 'data-event-cap' ), 10 );

		if ( isNaN( this.eventCap ) ) {
			this.eventCap = -1;
		}

		this.totalEl = block.querySelector( '[data-etm-total]' );
		this.openBtn = block.querySelector( '[data-etm-open]' );
		this.modal = block.querySelector( '[data-etm-modal]' );

		this.tickets = this.collectTicketRows();

		this.step = 1;
		this.busy = false;
		this.lastFocus = null;

		// Payment state.
		this.stripe = null;
		this.elements = null;
		this.paymentElement = null;
		this.clientSecret = '';
		this.token = '';
		this.billing = null;

		// Pricing state, all of it decided by the server.
		this.coupon = '';
		this.requiresPayment = ! this.isRsvp;

		this.bind();
		this.updateTotals();

		// The group starts hidden in the markup; this disables its inputs to
		// match, so they are inert before the modal is ever opened.
		this.toggleSharingRoom();
	}

	/**
	 * Read every ticket type row rendered for this event.
	 *
	 * @return {Array<Object>} Ticket row descriptors.
	 */
	TicketBlock.prototype.collectTicketRows = function () {
		var rows = [];

		Array.prototype.forEach.call( this.block.querySelectorAll( '[data-etm-ticket]' ), function ( el ) {
			var input = el.querySelector( '[data-etm-qty]' );

			if ( ! input ) {
				return;
			}

			rows.push( {
				el: el,
				id: parseInt( el.getAttribute( 'data-ticket-id' ), 10 ) || 0,
				title: el.getAttribute( 'data-ticket-title' ) || '',
				price: parseFloat( el.getAttribute( 'data-ticket-price' ) ) || 0,
				max: parseInt( el.getAttribute( 'data-ticket-max' ), 10 ) || 0,
				min: parseInt( el.getAttribute( 'data-ticket-min' ), 10 ) || 0,
				priceLabel: el.getAttribute( 'data-ticket-label' ) || '',
				variationType: el.getAttribute( 'data-ticket-variation' ) || 'standard',
				input: input,
				up: el.querySelector( '[data-etm-qty-up]' ),
				down: el.querySelector( '[data-etm-qty-down]' ),
				subtotalEl: el.querySelector( '[data-etm-line-subtotal]' )
			} );
		} );

		return rows;
	};

	TicketBlock.prototype.bind = function () {
		var self = this;

		this.tickets.forEach( function ( row ) {
			if ( row.up ) {
				row.up.addEventListener( 'click', function () {
					self.setQuantity( row, self.getQuantity( row ) + 1 );
				} );
			}

			if ( row.down ) {
				row.down.addEventListener( 'click', function () {
					self.setQuantity( row, self.getQuantity( row ) - 1 );
				} );
			}

			row.input.addEventListener( 'change', function () {
				self.setQuantity( row, parseInt( row.input.value, 10 ) );
			} );

			row.input.addEventListener( 'blur', function () {
				self.setQuantity( row, parseInt( row.input.value, 10 ) );
			} );
		} );

		if ( this.openBtn ) {
			this.openBtn.addEventListener( 'click', function () {
				self.openModal();
			} );
		}

		if ( ! this.modal ) {
			return;
		}

		this.form = this.modal.querySelector( '[data-etm-form]' );
		this.alert = this.modal.querySelector( '[data-etm-alert]' );
		this.attendeesWrap = this.modal.querySelector( '[data-etm-attendees]' );
		this.attendeeTemplate = this.block.querySelector( '[data-etm-attendee-template]' );
		this.sharingRoomFields = this.modal.querySelector( '[data-etm-sharing-room]' );
		this.purchaserTicket = this.modal.querySelector( '[data-etm-purchaser-ticket]' );
		this.reviewTickets = this.modal.querySelector( '[data-etm-review-tickets]' );
		this.reviewSummary = this.modal.querySelector( '[data-etm-review-summary]' );

		this.couponInput = this.modal.querySelector( '[data-etm-coupon-input]' );
		this.couponEntry = this.modal.querySelector( '[data-etm-coupon-entry]' );
		this.couponApplied = this.modal.querySelector( '[data-etm-coupon-applied]' );
		this.couponCodeEl = this.modal.querySelector( '[data-etm-coupon-code]' );
		this.couponMessage = this.modal.querySelector( '[data-etm-coupon-message]' );

		this.backBtn = this.modal.querySelector( '[data-etm-back]' );
		this.nextBtn = this.modal.querySelector( '[data-etm-next]' );
		this.toPaymentBtn = this.modal.querySelector( '[data-etm-to-payment]' );
		this.payBtn = this.modal.querySelector( '[data-etm-pay]' );
		this.payLabel = this.modal.querySelector( '[data-etm-pay-label]' );
		this.doneBtn = this.modal.querySelector( '[data-etm-done]' );
		this.footerTotal = this.modal.querySelector( '[data-etm-footer-total-wrap]' );

		this.paymentMount = this.modal.querySelector( '[data-etm-payment-element]' );
		this.paymentLoading = this.modal.querySelector( '[data-etm-payment-loading]' );
		this.progressBar = this.modal.querySelector( '[data-etm-progress]' );

		Array.prototype.forEach.call( this.modal.querySelectorAll( '[data-etm-close]' ), function ( el ) {
			el.addEventListener( 'click', function () {
				self.closeModal();
			} );
		} );

		if ( this.backBtn ) {
			this.backBtn.addEventListener( 'click', function () {
				self.goToStep( self.step - 1 );
			} );
		}

		if ( this.nextBtn ) {
			this.nextBtn.addEventListener( 'click', function () {
				if ( self.validateStepOne() ) {
					self.goToStep( 2 );
				}
			} );
		}

		if ( this.toPaymentBtn ) {
			this.toPaymentBtn.addEventListener( 'click', function () {
				self.startCheckout();
			} );
		}

		if ( this.payBtn ) {
			this.payBtn.addEventListener( 'click', function () {
				self.pay();
			} );
		}

		if ( this.doneBtn ) {
			this.doneBtn.addEventListener( 'click', function () {
				self.closeModal( true );
			} );
		}

		var applyBtn = this.modal.querySelector( '[data-etm-coupon-apply]' );
		var removeBtn = this.modal.querySelector( '[data-etm-coupon-remove]' );

		if ( applyBtn ) {
			applyBtn.addEventListener( 'click', function () {
				self.applyCoupon();
			} );
		}

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				self.removeCoupon();
			} );
		}

		if ( this.couponInput ) {
			// Enter inside the coupon field applies it rather than advancing.
			this.couponInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					event.stopPropagation();
					self.applyCoupon();
				}
			} );
		}

		if ( this.form ) {
			// Enter should advance the flow, never submit the page.
			this.form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				if ( self.step === 1 && self.validateStepOne() ) {
					self.goToStep( 2 );
				} else if ( self.step === 2 ) {
					self.startCheckout();
				}
			} );

			this.form.addEventListener( 'input', function ( event ) {
				self.clearFieldError( event.target );
			} );
		}

		this.modal.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				self.closeModal();
			} else if ( event.key === 'Tab' ) {
				self.trapFocus( event );
			}
		} );
	};

	/* ------------------------------------------------------ quantities -- */

	TicketBlock.prototype.getQuantity = function ( row ) {
		var value = parseInt( row.input.value, 10 );
		var min = row.min || 0;

		return isNaN( value ) || value < min ? min : value;
	};

	TicketBlock.prototype.getTotalQuantity = function ( exceptRow ) {
		var self = this;
		var total = 0;

		this.tickets.forEach( function ( row ) {
			if ( row !== exceptRow ) {
				total += self.getQuantity( row );
			}
		} );

		return total;
	};

	/**
	 * Set one ticket type's quantity, clamped by its own limit and by whatever
	 * event capacity the other types have not already claimed.
	 *
	 * @param {Object} row   Ticket row.
	 * @param {number} value Requested quantity.
	 */
	TicketBlock.prototype.setQuantity = function ( row, value ) {
		var min = row.min || 0;

		if ( isNaN( value ) || value < min ) {
			value = min;
		}

		value = Math.min( value, row.max );

		if ( this.eventCap >= 0 ) {
			var takenByOthers = this.getTotalQuantity( row );

			value = Math.min( value, Math.max( min, this.eventCap - takenByOthers ) );
		}

		row.input.value = value;

		this.updateTotals();
		this.renderAttendees();
		this.toggleSharingRoom();
	};

	/**
	 * Whether the order contains a Sharing a Room ticket.
	 *
	 * Only ever a yes or no. How many of those tickets were bought decides the
	 * price, never how many times the roommate is asked about.
	 *
	 * @return {boolean} True when a shared room is selected.
	 */
	TicketBlock.prototype.hasSharingRoom = function () {
		var self = this;
		var found = false;

		this.tickets.forEach( function ( row ) {
			if ( row.variationType === 'sharing_room' && self.getQuantity( row ) > 0 ) {
				found = true;
			}
		} );

		return found;
	};

	/**
	 * The current selection as ordered lines.
	 *
	 * @return {Array<Object>} Selected ticket lines.
	 */
	TicketBlock.prototype.getSelection = function () {
		var self = this;
		var lines = [];

		this.tickets.forEach( function ( row ) {
			var qty = self.getQuantity( row );

			if ( qty > 0 ) {
				lines.push( {
					id: row.id,
					title: row.title,
					price: row.price,
					priceLabel: row.priceLabel,
					variationType: row.variationType,
					quantity: qty,
					subtotal: row.price * qty
				} );
			}
		} );

		return lines;
	};

	TicketBlock.prototype.getTotals = function () {
		var lines = this.getSelection();
		var quantity = 0;
		var ticketSubtotal = 0;

		lines.forEach( function ( line ) {
			quantity += line.quantity;
			ticketSubtotal += line.subtotal;
		} );

		// Every variation, Sharing a Room included, is already in the subtotal.
		// Display only: the server prices the order again before it is charged.
		return {
			lines: lines,
			quantity: quantity,
			ticketSubtotal: ticketSubtotal,
			total: ticketSubtotal
		};
	};

	TicketBlock.prototype.updateTotals = function () {
		var self = this;
		var totals = this.getTotals();

		// Per-row subtotal and stepper state.
		this.tickets.forEach( function ( row ) {
			var qty = self.getQuantity( row );

			if ( row.subtotalEl ) {
				row.subtotalEl.textContent = qty > 0 ? formatPrice( row.price * qty ) : '';
			}

			row.el.classList.toggle( 'is-selected', qty > 0 );

			if ( row.down ) {
				row.down.disabled = qty <= ( row.min || 0 );
			}

			if ( row.up ) {
				var capReached = self.eventCap >= 0 && totals.quantity >= self.eventCap;

				row.up.disabled = qty >= row.max || capReached;
			}
		} );

		if ( this.totalEl ) {
			this.totalEl.textContent = formatPrice( totals.total );
		}

		// Nothing selected means nothing to check out.
		if ( this.openBtn ) {
			this.openBtn.disabled = totals.quantity < 1;
		}

		if ( ! this.modal ) {
			return;
		}

		// The block total covers tickets and the add-on only; fees and any coupon
		// are added by the server once the customer reaches the review step.
		this.setText( '[data-etm-modal-total]', formatPrice( totals.total ) );

		this.renderReviewTickets( totals );
	};

	/**
	 * Render the per-ticket-type breakdown on the review step.
	 *
	 * @param {Object} totals Current totals.
	 */
	TicketBlock.prototype.renderReviewTickets = function ( totals ) {
		if ( ! this.reviewTickets ) {
			return;
		}

		this.reviewTickets.innerHTML = '';

		totals.lines.forEach( function ( line ) {
			var suffix = line.priceLabel || i18n.each || 'each';
			var label = line.title + ' x ' + line.quantity + ' (' + formatPrice( line.price ) + ' ' + suffix + ')';

			this.reviewTickets.appendChild( summaryRow( label, formatPrice( line.subtotal ) ) );
		}, this );
	};

	TicketBlock.prototype.setText = function ( selector, text ) {
		var el = this.modal.querySelector( selector );

		if ( el ) {
			el.textContent = text;
		}
	};

	/* --------------------------------------------------------- modal ---- */

	TicketBlock.prototype.openModal = function () {
		if ( ! this.modal ) {
			return;
		}

		if ( this.getTotals().quantity < 1 ) {
			return;
		}

		this.lastFocus = document.activeElement;
		this.renderAttendees();
		this.toggleSharingRoom();
		this.updateTotals();
		this.goToStep( 1 );

		this.modal.hidden = false;
		document.body.classList.add( 'etm-modal-open' );

		var firstInput = this.modal.querySelector( '.etm-step:not([hidden]) input, .etm-step:not([hidden]) select, .etm-step:not([hidden]) textarea' );

		if ( firstInput ) {
			firstInput.focus();
		}
	};

	TicketBlock.prototype.closeModal = function ( force ) {
		if ( ! this.modal || this.modal.hidden ) {
			return;
		}

		if ( this.busy && ! force ) {
			return;
		}

		this.modal.hidden = true;
		document.body.classList.remove( 'etm-modal-open' );

		if ( this.lastFocus && typeof this.lastFocus.focus === 'function' ) {
			this.lastFocus.focus();
		}

		if ( this.step === 4 ) {
			this.resetAfterCompletion();
		}
	};

	TicketBlock.prototype.resetAfterCompletion = function () {
		var self = this;

		this.token = '';
		this.clientSecret = '';
		this.elements = null;
		this.paymentElement = null;
		this.billing = null;

		if ( this.form ) {
			this.form.reset();
		}

		if ( this.paymentMount ) {
			this.paymentMount.innerHTML = '';
		}

		this.tickets.forEach( function ( row ) {
			row.input.value = row.min || 0;
			self.updateTotals();
		} );

		if ( this.attendeesWrap ) {
			this.attendeesWrap.innerHTML = '';
		}

		this.toggleSharingRoom();
		this.updateTotals();
		this.goToStep( 1 );
	};

	TicketBlock.prototype.trapFocus = function ( event ) {
		var focusables = this.modal.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		);
		var visible = Array.prototype.filter.call( focusables, function ( el ) {
			return el.offsetParent !== null;
		} );

		if ( ! visible.length ) {
			return;
		}

		var first = visible[ 0 ];
		var last = visible[ visible.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	TicketBlock.prototype.goToStep = function ( step ) {
		step = Math.max( 1, Math.min( 4, step ) );
		this.step = step;

		Array.prototype.forEach.call( this.modal.querySelectorAll( '[data-etm-step]' ), function ( section ) {
			section.hidden = parseInt( section.getAttribute( 'data-etm-step' ), 10 ) !== step;
		} );

		Array.prototype.forEach.call( this.modal.querySelectorAll( '[data-etm-step-label]' ), function ( label ) {
			var index = parseInt( label.getAttribute( 'data-etm-step-label' ), 10 );

			label.classList.toggle( 'is-active', index === step );
			label.classList.toggle( 'is-done', index < step );
		} );

		if ( this.progressBar ) {
			this.progressBar.style.width = ( ( step - 1 ) / 3 * 100 ) + '%';
		}

		this.toggle( this.backBtn, step === 2 || step === 3 );
		this.toggle( this.nextBtn, step === 1 );
		this.toggle( this.toPaymentBtn, step === 2 );
		this.toggle( this.payBtn, step === 3 );
		this.toggle( this.doneBtn, step === 4 );
		this.toggle( this.footerTotal, step !== 4 );

		if ( step === 2 ) {
			this.renderReviewPeople();
			this.fetchQuote();
		}

		var body = this.modal.querySelector( '.etm-modal__body' );

		if ( body ) {
			body.scrollTop = 0;
		}
	};

	TicketBlock.prototype.toggle = function ( el, visible ) {
		if ( el ) {
			el.hidden = ! visible;
		}
	};

	/* ----------------------------------------------------- attendees ---- */

	/**
	 * Flatten the selection into attendee slots, in ticket display order.
	 *
	 * Slot 0 is the purchaser, so they are never asked for their details twice.
	 * Shared rooms contribute no slots: their occupants are the purchaser and
	 * the one roommate, who has a group of their own.
	 *
	 * @return {Array<Object>} Attendee slots.
	 */
	TicketBlock.prototype.getAttendeeSlots = function () {
		var slots = [];

		this.getSelection().forEach( function ( line ) {
			if ( line.variationType === 'sharing_room' ) {
				return;
			}

			for ( var i = 0; i < line.quantity; i++ ) {
				slots.push( {
					ticketId: line.id,
					title: line.title,
					index: i + 1
				} );
			}
		} );

		return slots;
	};

	/**
	 * Render an attendee form for every slot after the purchaser.
	 */
	TicketBlock.prototype.renderAttendees = function () {
		if ( ! this.attendeesWrap || ! this.attendeeTemplate ) {
			return;
		}

		var slots = this.getAttendeeSlots();

		// The purchaser's own ticket type is shown in the Your Details header.
		if ( this.purchaserTicket ) {
			this.purchaserTicket.textContent = slots.length
				? ( i18n.yourTicket || 'Your ticket' ) + ': ' + slots[ 0 ].title
				: '';
		}

		// Rebuild from scratch: a ticket type or quantity change reshuffles which
		// slot belongs to which type, so stale cards cannot be reused safely.
		var previous = this.captureAttendeeValues();

		this.attendeesWrap.innerHTML = '';

		for ( var i = 1; i < slots.length; i++ ) {
			var slot = slots[ i ];
			var markup = this.attendeeTemplate.innerHTML.split( '__INDEX__' ).join( String( i ) );
			var wrapper = document.createElement( 'div' );

			wrapper.innerHTML = markup;

			var card = wrapper.firstElementChild;

			if ( ! card ) {
				continue;
			}

			var label = card.querySelector( '[data-etm-attendee-label]' );
			var badge = card.querySelector( '[data-etm-attendee-badge]' );

			if ( label ) {
				label.textContent = slot.title + ' ' + slot.index;
			}

			if ( badge ) {
				badge.textContent = String( slot.index );
			}

			this.attendeesWrap.appendChild( card );
		}

		this.restoreAttendeeValues( previous );
	};

	/**
	 * Remember what the visitor already typed, keyed by input name.
	 *
	 * @param {HTMLElement} wrap Container to read.
	 * @return {Object} Values by field name.
	 */
	TicketBlock.prototype.captureValues = function ( wrap ) {
		var values = {};

		if ( ! wrap ) {
			return values;
		}

		Array.prototype.forEach.call( wrap.querySelectorAll( 'input, select, textarea' ), function ( field ) {
			if ( field.name && field.type !== 'checkbox' && field.value ) {
				values[ field.name ] = field.value;
			} else if ( field.name && field.type === 'checkbox' ) {
				values[ field.name ] = field.checked ? '1' : '';
			}
		} );

		return values;
	};

	/**
	 * Put remembered values back into the rebuilt forms.
	 *
	 * @param {HTMLElement} wrap   Container to write.
	 * @param {Object}      values Values by field name.
	 */
	TicketBlock.prototype.restoreValues = function ( wrap, values ) {
		if ( ! wrap ) {
			return;
		}

		Array.prototype.forEach.call( wrap.querySelectorAll( 'input, select, textarea' ), function ( field ) {
			if ( ! Object.prototype.hasOwnProperty.call( values, field.name ) ) {
				return;
			}

			if ( field.type === 'checkbox' ) {
				field.checked = values[ field.name ] === '1';
			} else {
				field.value = values[ field.name ];
			}
		} );
	};

	TicketBlock.prototype.captureAttendeeValues = function () {
		return this.captureValues( this.attendeesWrap );
	};

	TicketBlock.prototype.restoreAttendeeValues = function ( values ) {
		this.restoreValues( this.attendeesWrap, values );
	};

	/**
	 * Show or hide the single Sharing a Room group.
	 *
	 * There is exactly one of these in the markup and it is never cloned, so no
	 * quantity can ever multiply it. While hidden its inputs are disabled, which
	 * keeps them out of validation and out of the submitted form data.
	 */
	TicketBlock.prototype.toggleSharingRoom = function () {
		if ( ! this.sharingRoomFields ) {
			return;
		}

		var selected = this.hasSharingRoom();

		this.sharingRoomFields.hidden = ! selected;

		Array.prototype.forEach.call( this.sharingRoomFields.querySelectorAll( 'input, select, textarea' ), function ( field ) {
			field.disabled = ! selected;
		} );
	};

	TicketBlock.prototype.renderReviewPeople = function () {
		var target = this.modal.querySelector( '[data-etm-review-people]' );

		if ( ! target ) {
			return;
		}

		target.innerHTML = '';

		var slots = this.getAttendeeSlots();
		var names = [];
		var purchaser = this.modal.querySelector( '[name="customer[customer_name]"]' );

		if ( purchaser && purchaser.value && slots.length ) {
			names.push( purchaser.value + ' - ' + slots[ 0 ].title + ' (' + ( i18n.yourDetails || 'You' ) + ')' );
		}

		for ( var i = 1; i < slots.length; i++ ) {
			var field = this.attendeesWrap
				? this.attendeesWrap.querySelector( '[name="attendees[' + i + '][name]"]' )
				: null;

			if ( field && field.value ) {
				names.push( field.value + ' - ' + slots[ i ].title );
			}
		}

		if ( names.length ) {
			target.appendChild( this.buildPeopleList( i18n.attendees || 'Attendees', names ) );
		}

		if ( this.hasSharingRoom() && this.sharingRoomFields ) {
			var roommateField = this.sharingRoomFields.querySelector( '[name="sharing_room[roommate_name]"]' );

			if ( roommateField && roommateField.value ) {
				target.appendChild( this.buildPeopleList( i18n.roommate || 'Roommate', [ roommateField.value ] ) );
			}
		}
	};

	TicketBlock.prototype.buildPeopleList = function ( heading, names ) {
		var box = document.createElement( 'div' );
		var title = document.createElement( 'h4' );
		var list = document.createElement( 'ul' );

		box.className = 'etm-people';
		title.className = 'etm-people__title';
		title.textContent = heading;

		names.forEach( function ( name ) {
			var item = document.createElement( 'li' );

			item.textContent = name;
			list.appendChild( item );
		} );

		box.appendChild( title );
		box.appendChild( list );

		return box;
	};

	/* ---------------------------------------------------- validation ---- */

	TicketBlock.prototype.validateStepOne = function () {
		var self = this;
		var firstInvalid = null;

		this.clearAllErrors();

		if ( this.getTotals().quantity < 1 ) {
			this.showAlert( i18n.selectTicket || 'Please select at least one ticket.' );

			return false;
		}

		var fields = this.modal.querySelectorAll(
			'[data-etm-step="1"] input, [data-etm-step="1"] select, [data-etm-step="1"] textarea'
		);

		Array.prototype.forEach.call( fields, function ( field ) {
			if ( field.disabled || field.type === 'hidden' ) {
				return;
			}

			var value = ( field.value || '' ).trim();
			var message = '';

			if ( field.required && field.type === 'checkbox' && ! field.checked ) {
				message = i18n.required || 'This field is required.';
			} else if ( field.required && field.type !== 'checkbox' && value === '' ) {
				message = i18n.required || 'This field is required.';
			} else if ( field.type === 'email' && value !== '' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
				message = i18n.invalidEmail || 'Please enter a valid email address.';
			}

			if ( message ) {
				self.setFieldError( field, message );

				if ( ! firstInvalid ) {
					firstInvalid = field;
				}
			}
		} );

		if ( firstInvalid ) {
			firstInvalid.focus();

			return false;
		}

		return true;
	};

	TicketBlock.prototype.setFieldError = function ( field, message ) {
		var wrapper = field.closest( '.etm-field' );

		if ( ! wrapper ) {
			return;
		}

		wrapper.classList.add( 'has-error' );

		var error = wrapper.querySelector( '.etm-field__error' );

		if ( error ) {
			error.textContent = message;
		}
	};

	TicketBlock.prototype.setErrorByName = function ( name, message ) {
		var field = this.modal.querySelector( '[name="' + name.replace( /"/g, '\\"' ) + '"]' );

		if ( field ) {
			this.setFieldError( field, message );
		}
	};

	TicketBlock.prototype.clearFieldError = function ( field ) {
		if ( ! field || ! field.closest ) {
			return;
		}

		var wrapper = field.closest( '.etm-field' );

		if ( wrapper ) {
			wrapper.classList.remove( 'has-error' );
		}
	};

	TicketBlock.prototype.clearAllErrors = function () {
		Array.prototype.forEach.call( this.modal.querySelectorAll( '.etm-field.has-error' ), function ( el ) {
			el.classList.remove( 'has-error' );
		} );

		this.hideAlert();
	};

	TicketBlock.prototype.showAlert = function ( message ) {
		if ( ! this.alert ) {
			return;
		}

		this.alert.textContent = message;
		this.alert.hidden = false;

		var body = this.modal.querySelector( '.etm-modal__body' );

		if ( body ) {
			body.scrollTop = 0;
		}
	};

	TicketBlock.prototype.hideAlert = function () {
		if ( this.alert ) {
			this.alert.hidden = true;
			this.alert.textContent = '';
		}
	};

	TicketBlock.prototype.setBusy = function ( busy, button ) {
		this.busy = busy;

		var target = button || this.payBtn;

		if ( target ) {
			target.disabled = busy;
			target.classList.toggle( 'is-loading', busy );
		}

		if ( this.backBtn ) {
			this.backBtn.disabled = busy;
		}
	};

	/* ------------------------------------------------- server pricing --- */

	/**
	 * Ask the server to price the current selection, including fees and coupon.
	 *
	 * Display only: the same calculation runs again before anything is charged.
	 */
	TicketBlock.prototype.fetchQuote = function () {
		var self = this;

		var formData = new FormData();

		formData.append( 'action', 'etm_quote_order' );
		formData.append( 'nonce', config.nonce || '' );
		formData.append( 'event_id', String( this.eventId ) );
		formData.append( 'coupon', this.coupon );

		var email = this.modal.querySelector( '[name="customer[customer_email]"]' );

		if ( email && email.value ) {
			formData.append( 'customer_email', email.value );
		}

		this.getSelection().forEach( function ( line ) {
			formData.append( 'tickets[' + line.id + ']', String( line.quantity ) );
		} );

		return postAjax( formData ).then( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) {
				// A failed quote leaves the last known figures on screen (still
				// just the ticket subtotal, with no fees/coupon applied yet); the
				// server prices the order again before any payment is taken, so
				// this never affects what is actually charged. Logged rather than
				// silent, so a persistently wrong-looking review total is
				// debuggable instead of invisible.
				if ( window.console && console.warn ) {
					console.warn( 'etm_quote_order failed', response );
				}

				return;
			}

			self.renderQuote( response.data );
		} ).catch( function ( error ) {
			if ( window.console && console.warn ) {
				console.warn( 'etm_quote_order request failed', error );
			}
		} );
	};

	/**
	 * Paint a server quote into the review step.
	 *
	 * @param {Object} quote Response from etm_quote_order.
	 */
	TicketBlock.prototype.renderQuote = function ( quote ) {
		this.requiresPayment = !! quote.requires_payment;

		if ( this.reviewSummary ) {
			this.reviewSummary.innerHTML = '';

			( quote.rows || [] ).forEach( function ( row ) {
				var el = summaryRow( row.label, row.value );

				if ( row.kind === 'discount' ) {
					el.className += ' etm-summary__row--discount';
				}

				this.reviewSummary.appendChild( el );
			}, this );
		}

		this.setText( '[data-etm-review-total]', quote.total );
		this.setText( '[data-etm-modal-total]', quote.total );

		if ( this.payLabel ) {
			this.payLabel.textContent = ( i18n.pay || 'Pay' ) + ' ' + quote.total;
		}

		// The step 2 button says what will actually happen next.
		if ( this.toPaymentBtn ) {
			if ( ! this.requiresPayment ) {
				this.toPaymentBtn.textContent = this.isRsvp
					? ( i18n.completeRsvp || 'Confirm RSVP' )
					: ( i18n.completeOrder || 'Complete Booking' );
			} else {
				this.toPaymentBtn.textContent = i18n.continuePay || 'Continue to Payment';
			}
		}

		this.showCouponState( quote );
	};

	/**
	 * Reflect the applied or rejected coupon in the UI.
	 *
	 * @param {Object} quote Response from etm_quote_order.
	 */
	TicketBlock.prototype.showCouponState = function ( quote ) {
		var applied = !! quote.coupon_code;

		this.coupon = quote.coupon_code || '';

		if ( this.couponEntry ) {
			this.couponEntry.hidden = applied;
		}

		if ( this.couponApplied ) {
			this.couponApplied.hidden = ! applied;
		}

		if ( this.couponCodeEl ) {
			this.couponCodeEl.textContent = applied ? quote.coupon_code + '  -' + quote.discount : '';
		}

		if ( ! this.couponMessage ) {
			return;
		}

		this.couponMessage.classList.remove( 'is-error', 'is-success' );

		if ( quote.coupon_error ) {
			this.couponMessage.textContent = quote.coupon_error;
			this.couponMessage.classList.add( 'is-error' );
		} else if ( applied ) {
			this.couponMessage.textContent = i18n.couponApplied || 'Coupon applied.';
			this.couponMessage.classList.add( 'is-success' );
		} else {
			this.couponMessage.textContent = '';
		}
	};

	TicketBlock.prototype.applyCoupon = function () {
		if ( ! this.couponInput ) {
			return;
		}

		var code = ( this.couponInput.value || '' ).trim();

		if ( '' === code ) {
			return;
		}

		this.coupon = code;
		this.fetchQuote();
	};

	TicketBlock.prototype.removeCoupon = function () {
		this.coupon = '';

		if ( this.couponInput ) {
			this.couponInput.value = '';
		}

		if ( this.couponMessage ) {
			this.couponMessage.textContent = i18n.couponRemoved || 'Coupon removed.';
			this.couponMessage.classList.remove( 'is-error', 'is-success' );
		}

		this.fetchQuote();
	};

	/* --------------------------------------------- payment lifecycle ---- */

	/**
	 * Validate server-side, hold the places and load the payment form.
	 */
	TicketBlock.prototype.startCheckout = function () {
		var self = this;

		if ( this.busy ) {
			return;
		}

		if ( ! this.validateStepOne() ) {
			this.goToStep( 1 );

			return;
		}

		this.setBusy( true, this.toPaymentBtn );
		this.hideAlert();

		var formData = new FormData( this.form );

		formData.append( 'action', 'etm_start_checkout' );
		formData.append( 'nonce', config.nonce || '' );
		formData.append( 'event_id', String( this.eventId ) );
		formData.append( 'coupon', this.coupon );

		// Only IDs, quantities and a coupon code travel; the server prices them.
		this.getSelection().forEach( function ( line ) {
			formData.append( 'tickets[' + line.id + ']', String( line.quantity ) );
		} );

		if ( this.token ) {
			formData.append( 'token', this.token );
		}

		postAjax( formData ).then( function ( response ) {
			self.setBusy( false, self.toPaymentBtn );

			if ( ! response || ! response.success || ! response.data ) {
				self.handleFailure( response );

				return;
			}

			var data = response.data;

			self.token = data.token || self.token;

			if ( data.already_paid ) {
				self.goToStep( 4 );
				self.confirmPayment();

				return;
			}

			// RSVP events, and orders that came to zero, are already finished:
			// Stripe was never involved.
			if ( data.completed && data.order ) {
				self.renderComplete( data.order );

				return;
			}

			self.billing = data.billing || null;

			self.goToStep( 3 );
			self.mountPaymentElement( data );
		} ).catch( function ( error ) {
			self.setBusy( false, self.toPaymentBtn );
			self.handleFailure( { data: { message: error.message } } );
		} );
	};

	/**
	 * Create Stripe Elements and mount the Payment Element in the modal.
	 *
	 * @param {Object} data Response from etm_start_checkout.
	 */
	TicketBlock.prototype.mountPaymentElement = function ( data ) {
		var self = this;

		if ( typeof window.Stripe !== 'function' ) {
			this.showAlert( i18n.genericError || 'Payment library failed to load.' );

			return;
		}

		if ( ! this.stripe && data.publishable_key ) {
			this.stripe = window.Stripe( data.publishable_key );
		}

		if ( ! this.stripe ) {
			this.showAlert( i18n.genericError || 'Payment library failed to load.' );

			return;
		}

		if ( this.paymentElement && this.clientSecret === data.client_secret ) {
			this.hidePaymentLoader();

			return;
		}

		this.clientSecret = data.client_secret;

		if ( this.paymentElement ) {
			this.paymentElement.unmount();
			this.paymentElement = null;
		}

		var appearance = config.appearance || {};

		this.elements = this.stripe.elements( {
			clientSecret: this.clientSecret,
			appearance: {
				theme: 'stripe',
				variables: {
					colorPrimary: appearance.primary || '#4f46e5',
					colorText: appearance.text || '#111827',
					colorBackground: appearance.background || '#ffffff',
					borderRadius: '9px',
					fontSizeBase: '15px'
				}
			}
		} );

		this.paymentElement = this.elements.create( 'payment', {
			layout: 'tabs',
			// Name and email are already collected in step 1, so Stripe should not
			// render them again as optional extras. Anything opted out of here
			// MUST be supplied in confirmParams below, which is why phone is left
			// on its default: the plugin never collects one to hand back.
			fields: {
				billingDetails: {
					name: 'never',
					email: 'never'
				}
			}
		} );

		this.paymentElement.on( 'ready', function () {
			self.hidePaymentLoader();
		} );

		this.paymentElement.on( 'loaderror', function ( event ) {
			self.hidePaymentLoader();
			self.showAlert( ( event && event.error && event.error.message ) || i18n.genericError );
		} );

		this.paymentElement.mount( this.paymentMount );
	};

	TicketBlock.prototype.hidePaymentLoader = function () {
		if ( this.paymentLoading ) {
			this.paymentLoading.hidden = true;
		}
	};

	/**
	 * Confirm the card payment in place, handling any 3DS challenge inline.
	 */
	TicketBlock.prototype.pay = function () {
		var self = this;

		if ( this.busy || ! this.stripe || ! this.elements ) {
			return;
		}

		this.setBusy( true, this.payBtn );
		this.hideAlert();

		var confirmParams = {
			return_url: window.location.href.split( '#' )[ 0 ]
		};

		// Required, and unconditional: the element is told never to collect these,
		// so Stripe rejects the confirmation unless both are passed every time.
		var billing = this.billing || {};

		confirmParams.payment_method_data = {
			billing_details: {
				name: billing.name || '',
				email: billing.email || ''
			}
		};

		this.stripe.confirmPayment( {
			elements: this.elements,
			redirect: 'if_required',
			confirmParams: confirmParams
		} ).then( function ( result ) {
			if ( result.error ) {
				self.setBusy( false, self.payBtn );
				self.showAlert( result.error.message || i18n.paymentFailed );

				return;
			}

			// Stripe reports success; the server still has to verify it.
			self.confirmPayment();
		} ).catch( function ( error ) {
			self.setBusy( false, self.payBtn );
			self.showAlert( error.message || i18n.genericError );
		} );
	};

	/**
	 * Ask the server to verify the payment and return the finished order.
	 *
	 * @param {number} attempt Retry counter.
	 */
	TicketBlock.prototype.confirmPayment = function ( attempt ) {
		var self = this;

		attempt = attempt || 0;

		var formData = new FormData();

		formData.append( 'action', 'etm_confirm_payment' );
		formData.append( 'nonce', config.nonce || '' );
		formData.append( 'token', this.token );

		postAjax( formData ).then( function ( response ) {
			if ( response && response.success && response.data ) {
				if ( response.data.pending ) {
					if ( attempt < 8 ) {
						window.setTimeout( function () {
							self.confirmPayment( attempt + 1 );
						}, 2500 );

						return;
					}

					self.setBusy( false, self.payBtn );
					self.showAlert( response.data.message || i18n.stillPending );

					return;
				}

				self.setBusy( false, self.payBtn );
				self.renderComplete( response.data );

				return;
			}

			// The webhook may still be finishing; retry before giving up.
			var retryable = response && response.data && response.data.retry;

			if ( retryable && attempt < 8 ) {
				window.setTimeout( function () {
					self.confirmPayment( attempt + 1 );
				}, 2500 );

				return;
			}

			self.setBusy( false, self.payBtn );
			self.showAlert( ( response && response.data && response.data.message ) || i18n.genericError );
		} ).catch( function () {
			if ( attempt < 8 ) {
				window.setTimeout( function () {
					self.confirmPayment( attempt + 1 );
				}, 3000 );

				return;
			}

			self.setBusy( false, self.payBtn );
			self.showAlert( i18n.stillPending );
		} );
	};

	/**
	 * Fill in and show the Order Complete step.
	 *
	 * @param {Object} order Verified order summary from the server.
	 */
	TicketBlock.prototype.renderComplete = function ( order ) {
		var numberEl = this.modal.querySelector( '[data-etm-complete-number]' );
		var summaryEl = this.modal.querySelector( '[data-etm-complete-summary]' );
		var emailEl = this.modal.querySelector( '[data-etm-complete-email]' );

		if ( numberEl ) {
			numberEl.textContent = order.booking_number || '';
		}

		if ( summaryEl ) {
			summaryEl.innerHTML = '';

			var lines = document.createElement( 'dl' );

			lines.className = 'etm-summary__lines';

			if ( order.event_title ) {
				lines.appendChild( summaryRow( i18n.event || 'Event', order.event_title ) );
			}

			if ( order.event_date ) {
				lines.appendChild( summaryRow( i18n.date || 'Date', order.event_date ) );
			}

			if ( order.event_time ) {
				lines.appendChild( summaryRow( i18n.time || 'Time', order.event_time ) );
			}

			lines.appendChild( summaryRow( i18n.name || 'Name', order.customer_name || '' ) );
			lines.appendChild( summaryRow( i18n.email || 'Email', order.customer_email || '' ) );

			// One row per purchased ticket type, straight from the booking.
			( order.tickets || [] ).forEach( function ( ticket ) {
				var suffix = ticket.price_label || i18n.each || 'each';
				var label = ticket.title + ' x ' + ticket.quantity + ' (' + ticket.unit_price + ' ' + suffix + ')';

				lines.appendChild( summaryRow( label, ticket.subtotal ) );
			} );

			lines.appendChild( summaryRow( i18n.subtotal || 'Ticket subtotal', order.ticket_subtotal || '' ) );

			if ( order.has_fees ) {
				lines.appendChild( summaryRow( i18n.fees || 'Fees', order.fees_total || '' ) );
			}

			if ( order.has_discount ) {
				lines.appendChild( summaryRow(
					( i18n.coupon || 'Coupon' ) + ' ' + ( order.coupon_code || '' ),
					'-' + ( order.discount_total || '' )
				) );
			}

			( order.roommates || [] ).forEach( function ( person, index ) {
				var label = ( order.roommates.length > 1 )
					? ( i18n.roommate || 'Roommate' ) + ' ' + ( index + 1 )
					: ( i18n.roommate || 'Roommate' );

				lines.appendChild( summaryRow( label, person.name || '' ) );
			} );

			lines.appendChild( summaryRow( i18n.paymentStatus || 'Payment status', order.payment_status || '' ) );

			// Absent for RSVP and free orders, which have no Stripe payment.
			if ( order.transaction_id ) {
				lines.appendChild( summaryRow( i18n.transaction || 'Transaction ID', order.transaction_id ) );
			}

			if ( order.booking_date ) {
				lines.appendChild( summaryRow( i18n.bookingDate || 'Booking date', order.booking_date ) );
			}

			lines.appendChild( summaryRow( i18n.totalPaid || 'Total paid', order.total_paid || '', true ) );

			summaryEl.appendChild( lines );
		}

		if ( emailEl ) {
			emailEl.textContent = ( i18n.emailSent || 'A confirmation email has been sent to' ) + ' ' + ( order.customer_email || '' );
		}

		this.goToStep( 4 );
	};

	TicketBlock.prototype.handleFailure = function ( response ) {
		var data = ( response && response.data ) || {};
		var message = data.message || i18n.genericError || 'Something went wrong.';

		if ( data.errors ) {
			this.goToStep( 1 );

			for ( var name in data.errors ) {
				if ( Object.prototype.hasOwnProperty.call( data.errors, name ) ) {
					this.setErrorByName( name, data.errors[ name ] );
				}
			}
		}

		this.showAlert( message );
	};

	/* ------------------------------------------------------------ init -- */

	function init() {
		// By now every inline script in the document has run, so this picks up
		// an etmConfig that was not yet defined when this file was parsed.
		syncConfig();

		Array.prototype.forEach.call( document.querySelectorAll( '[data-etm-block]' ), function ( block ) {
			if ( block.getAttribute( 'data-etm-ready' ) === '1' ) {
				return;
			}

			block.setAttribute( 'data-etm-ready', '1' );

			new TicketBlock( block );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
