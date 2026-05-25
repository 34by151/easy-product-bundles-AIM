/**
 * AIM Bundle — Frontend JS
 *
 * Feature 2: Quantity from Items
 *   Sets a target item's qty to the sum of selected source items' quantities
 *   in real time.
 *
 * Feature 3: Per-item Show Qty Override
 *   When "Hide item quantity" (Feature 1) is active at the bundle level, this
 *   forces specific items' qty controls to remain visible by overriding the
 *   CSS rule with a higher-specificity inline style.
 *
 * Feature 4: Max from Items
 *   Sets a target item's max attribute to (base_max − sum_of_source_qtys),
 *   clamped to a minimum of 1. If the current qty exceeds the new max, it is
 *   automatically reduced.
 *
 * Data source: window.easyProductBundlesData (localised by base plugin).
 * Additional keys added by AIM:
 *   aim_qty_links               — Feature 2: [{ target, sources }]
 *   aim_show_item_qty_overrides — Feature 3: [itemIndex, …]
 *   aim_max_from_items          — Feature 4: [{ target, sources, base_max }]
 *   aim_logging                 — global debug flag
 *
 * Detection strategy:
 *   1. MutationObserver — class changes (asnp-disable-product toggle) AND
 *      childList changes (React unmount/remount of item elements).
 *   2. Click listener — option toggle / checkbox clicks.
 *   3. Input listener — manual qty changes on source items.
 *   4. asnpWepbPriceChanged — bundle plugin fires on any state change.
 *   5. Pre-submit capture hook — guarantees correct values reach the server.
 *   6. Periodic poll — 500 ms × 20 iterations (10 s) as fallback.
 *
 * React-controlled input strategy:
 *   A. setReactInputValue — updates React internal state (correct cart value).
 *   B. forceDOMValue — sets raw DOM display via native prototype setter, no
 *      events. Used by enforcement interval to fix visual resets after renders.
 *   C. enforceDesiredQtys (50 ms interval) — keeps qty display correct.
 *   D. enforceMaxes (50 ms interval) — keeps max attribute correct.
 *   E. enforceShowOverrides (50 ms interval) — keeps override items visible.
 */

( function () {
	'use strict';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	// Unconditional load-check: always visible in console regardless of logging flag.
	// eslint-disable-next-line no-console
	console.log( '[EPB-AIM] aim-frontend.js loaded. easyProductBundlesData keys:',
		window.easyProductBundlesData ? Object.keys( window.easyProductBundlesData ) : 'DATA MISSING' );

	var data = window.easyProductBundlesData;
	if ( ! data ) {
		return;
	}

	var qtyLinks        = data.aim_qty_links                  || [];
	var showOverrides   = data.aim_show_item_qty_overrides     || [];
	var maxLinks        = data.aim_max_from_items              || [];
	var maxMsg          = ( typeof data.aim_max_qty_message === 'string' )
		? data.aim_max_qty_message.trim()
		: '';
	var bundleQtyMsg    = ( typeof data.aim_bundle_qty_message === 'string' )
		? data.aim_bundle_qty_message.trim()
		: '';
	var noSelectionMsg  = ( typeof data.aim_no_selection_message === 'string' )
		? data.aim_no_selection_message.trim()
		: '';
	var qtyTotal           = !! data.aim_qty_total;
	var imageSwapRules     = Array.isArray( data.aim_image_swap_rules )    ? data.aim_image_swap_rules    : [];
	var nonOptionalOrder   = Array.isArray( data.aim_non_optional_order )  ? data.aim_non_optional_order  : [];
	// eslint-disable-next-line no-console
	console.log( '[EPB-AIM] aim_image_swap_rules:', imageSwapRules, 'aim_non_optional_order:', nonOptionalOrder );
	var qtyPlusMinus    = !! data.aim_qty_plusminus;
	var rawPmSize       = parseInt( data.aim_qty_pm_size, 10 );
	var qtyPmSize       = ( ! isNaN( rawPmSize ) ) ? Math.max( 50, Math.min( 100, rawPmSize ) ) : 100;
	var swapPositions   = !! data.aim_swap_positions;
	var autowidthRow    = !! data.aim_autowidth_row;

	// Nothing to do if no features are active.
	if ( ! qtyLinks.length && ! showOverrides.length && ! maxLinks.length && ! bundleQtyMsg && ! noSelectionMsg && ! qtyTotal && ! imageSwapRules.length && ! qtyPlusMinus && ! swapPositions && ! autowidthRow ) {
		// eslint-disable-next-line no-console
		console.log( '[EPB-AIM] aim-frontend.js: no aim_* data in easyProductBundlesData — exiting early.',
			'aim_qty_links:', data.aim_qty_links,
			'aim_show_item_qty_overrides:', data.aim_show_item_qty_overrides,
			'aim_max_from_items:', data.aim_max_from_items,
			'aim_bundle_qty_message:', data.aim_bundle_qty_message,
			'aim_no_selection_message:', data.aim_no_selection_message,
			'aim_image_swap_rules:', data.aim_image_swap_rules );
		return;
	}

	// wp_localize_script serialises PHP true as integer 1, not boolean true,
	// so a truthy coercion is needed rather than strict === true.
	var logging = !! data.aim_logging;

	// Always visible so we know whether logging is on regardless of how the
	// PHP boolean was serialised.
	// eslint-disable-next-line no-console
	console.log( '[EPB-AIM] aim_logging raw:', data.aim_logging,
		'| type:', typeof data.aim_logging, '| logging active:', logging );

	function log() {
		if ( ! logging ) {
			return;
		}
		var args = Array.prototype.slice.call( arguments );
		args.unshift( '[EPB-AIM]' );
		console.log.apply( console, args ); // eslint-disable-line no-console
	}

	log( 'Initialized.',
		'qtyLinks:', JSON.stringify( qtyLinks ),
		'showOverrides:', JSON.stringify( showOverrides ),
		'maxLinks:', JSON.stringify( maxLinks )
	);

	// ── DOM helpers ───────────────────────────────────────────────────────────

	/**
	 * Find the quantity <input> for the bundle item at the given index.
	 * Name pattern: asnp_wepb_bundle[ N ][…quantity…]
	 *
	 * The base plugin renders TWO inputs per item:
	 *   • productList_quantity        — interactive, NOT disabled
	 *   • simple_productList_quantity — display-only, disabled=true, always
	 *     inside an asnp-disable-product container (even for selected items)
	 *
	 * We must prefer the non-disabled input so that isItemSelected() reads
	 * the correct selection state and setReactInputValue() can update React.
	 * Fall back to the first match if all candidates happen to be disabled.
	 */
	function getQtyInput( index ) {
		var all       = document.querySelectorAll( 'input[name*="asnp_wepb_bundle"][name*="quantity"]' );
		var firstMatch = null;

		for ( var i = 0; i < all.length; i++ ) {
			var inp   = all[ i ];
			var name  = inp.getAttribute( 'name' ) || '';
			var match = name.match( /asnp_wepb_bundle\[\s*(\d+)\s*\]/ );

			if ( match && parseInt( match[ 1 ], 10 ) === index ) {
				if ( firstMatch === null ) {
					firstMatch = inp;
				}
				// Return immediately when we find a non-disabled input —
				// that is always the interactive (React-controlled) one.
				if ( ! inp.disabled ) {
					return inp;
				}
			}
		}

		return firstMatch; // all candidates disabled — best we can do
	}

	/**
	 * Walk up from a qty input to find its .asnp-bundleListItem-product-detail
	 * container (the element the max-qty message is inserted after).
	 */
	function getItemDetailElement( index ) {
		var input = getQtyInput( index );
		if ( ! input ) {
			return null;
		}
		var el = input.parentElement;
		while ( el ) {
			if ( el.classList && el.classList.contains( 'asnp-bundleListItem-product-detail' ) ) {
				return el;
			}
			if ( el.tagName === 'FORM' || el.tagName === 'BODY' ) {
				break;
			}
			el = el.parentElement;
		}
		return null;
	}

	/**
	 * Walk up from a qty input to find its .asnp-product-quantity-field wrapper.
	 */
	function getQtyFieldWrapper( index ) {
		var input = getQtyInput( index );
		if ( ! input ) {
			return null;
		}
		var el = input.parentElement;
		while ( el ) {
			if ( el.classList && el.classList.contains( 'asnp-product-quantity-field' ) ) {
				return el;
			}
			if ( el.tagName === 'FORM' || el.tagName === 'BODY' ) {
				break;
			}
			el = el.parentElement;
		}
		return null;
	}

	/**
	 * Return true if the item is currently selected / active.
	 * Deselected optional items have 'asnp-disable-product' on an ancestor.
	 */
	function isItemSelected( input ) {
		if ( ! input ) {
			return false;
		}
		var el = input.parentElement;
		while ( el ) {
			if ( el.classList && el.classList.contains( 'asnp-disable-product' ) ) {
				return false;
			}
			if ( el.tagName === 'FORM' || el.tagName === 'BODY' ) {
				break;
			}
			el = el.parentElement;
		}
		return true;
	}

	// ── Native setter cache ───────────────────────────────────────────────────

	var nativeSetter = ( function () {
		var descriptor = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' );
		return ( descriptor && descriptor.set ) ? descriptor.set : null;
	}() );

	// ── forceDOMValue — visual display only ───────────────────────────────────

	/**
	 * Set the raw DOM display value WITHOUT dispatching any events.
	 * Does NOT update React's internal state.
	 */
	function forceDOMValue( input, value ) {
		var strVal = String( value );
		if ( input.value === strVal ) {
			return;
		}
		if ( nativeSetter ) {
			nativeSetter.call( input, strVal );
		} else {
			input.value = strVal;
		}
		log( 'forceDOMValue: set to', strVal, '— DOM now:', input.value );
	}

	// ── setReactInputValue — React state update ───────────────────────────────

	/**
	 * Update a React-controlled input's value so React's internal state reflects
	 * the new number. This ensures the correct qty is serialised into the hidden
	 * cart JSON input that the bundle plugin builds from React state.
	 *
	 * The bundle plugin does NOT read form-field DOM values at submission time.
	 * Instead it reads each item's `quantity` from its React state and writes the
	 * result as JSON into a hidden input (`disableAddToCart` utility). Therefore
	 * only a genuine React state update (not forceDOMValue) affects the cart.
	 *
	 * Strategy — try in order:
	 *   1. __reactProps approach: call the input's React onChange prop directly.
	 *      This invokes the component's `p` validation wrapper, which calls the
	 *      parent's `onChange("quantity", value)` state-setter, triggering a full
	 *      React re-render and updating the hidden cart input. Bypasses the event
	 *      system entirely, so display:none and _valueTracker are irrelevant.
	 *   2. _valueTracker + native events fallback (older React / edge cases).
	 *      If the wrapper is display:none we temporarily reveal it so React's
	 *      event delegation can see the bubbled input event.
	 */
	function setReactInputValue( input, value ) {
		var strVal = String( value );

		// ── Strategy 1: direct React props call ─────────────────────────────
		// React 16+ stores the component's current props on the DOM node under a
		// key that starts with '__reactProps' (React 17+) or '__reactEventHandlers'
		// (React 16 legacy). Calling props.onChange() directly invokes the
		// component's handler without going through the synthetic event system.
		var reactPropsKey = null;
		// Object.keys() only returns enumerable own properties, but in some
		// browsers React 17 stores __reactProps$xxx as non-enumerable.
		// Object.getOwnPropertyNames() returns all own properties regardless.
		var domKeys = Object.getOwnPropertyNames ? Object.getOwnPropertyNames( input ) : Object.keys( input );
		for ( var ki = 0; ki < domKeys.length; ki++ ) {
			var k = domKeys[ ki ];
			if ( k.startsWith( '__reactProps' ) || k.startsWith( '__reactEventHandlers' ) ) {
				reactPropsKey = k;
				break;
			}
		}

		if ( reactPropsKey ) {
			var reactProps = input[ reactPropsKey ];
			if ( reactProps && typeof reactProps.onChange === 'function' ) {
				// Also update native DOM value so the visual display is correct
				// while React schedules its async re-render.
				if ( nativeSetter ) {
					nativeSetter.call( input, strVal );
				} else {
					input.value = strVal;
				}
				reactProps.onChange( { target: { value: strVal, name: input.name } } );
				log( 'setReactInputValue (reactProps): called onChange with', strVal );
				return;
			}
		}

		// ── Strategy 2: _valueTracker + synthetic events ─────────────────────
		log( 'setReactInputValue (events fallback): set to', strVal );

		var tracker = input._valueTracker;
		if ( tracker ) {
			tracker.setValue( '' );
		}

		if ( nativeSetter ) {
			nativeSetter.call( input, strVal );
		} else {
			input.value = strVal;
		}

		// Temporarily reveal a CSS-hidden wrapper so React's event delegation
		// (attached to the React root or document) can process the event.
		var wrapper   = null;
		var wasHidden = false;
		var el = input.parentElement;
		while ( el ) {
			if ( el.classList && el.classList.contains( 'asnp-product-quantity-field' ) ) {
				wrapper = el;
				break;
			}
			if ( el.tagName === 'FORM' || el.tagName === 'BODY' ) {
				break;
			}
			el = el.parentElement;
		}
		if ( wrapper && window.getComputedStyle( wrapper ).display === 'none' ) {
			wasHidden = true;
			wrapper.style.setProperty( 'display', 'flex', 'important' );
		}

		input.dispatchEvent( new Event( 'input',  { bubbles: true, cancelable: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true, cancelable: true } ) );

		if ( wasHidden && wrapper ) {
			wrapper.style.removeProperty( 'display' );
		}

		log( 'setReactInputValue: DOM now:', input.value );
	}

	// ── Feature 2 — Quantity from Items ──────────────────────────────────────

	// Maps targetIndex → desired quantity. The enforcement interval re-applies
	// forceDOMValue from this map to fix React re-render resets.
	var desiredQtys = {};

	function recalculateQtyLinks() {
		if ( ! qtyLinks.length ) {
			return;
		}
		log( 'recalculateQtyLinks()' );

		qtyLinks.forEach( function ( link ) {
			var targetIdx  = link.target;
			var sourceIdxs = link.sources;
			var total      = 0;

			sourceIdxs.forEach( function ( srcIdx ) {
				var srcInput = getQtyInput( srcIdx );
				var selected = isItemSelected( srcInput );
				var qty      = srcInput ? ( parseInt( srcInput.value, 10 ) || 0 ) : 0;
				var contrib  = selected ? qty : 0;
				total       += contrib;
				log( '  source[' + srcIdx + ']: found=' + !! srcInput +
				     ' selected=' + selected + ' qty=' + qty + ' contributes=' + contrib );
			} );

			// If every source is deselected the sum is 0. Trying to set the
			// target to 0 is rejected by the bundle plugin's min-qty validation
			// (minimum = 1), causing React to immediately reset the value and
			// triggering an infinite recalculate loop. Leave the target alone
			// until at least one source is active.
			if ( total === 0 ) {
				log( '  target[' + targetIdx + ']: total=0 (all sources deselected) — skipping update' );
				delete desiredQtys[ targetIdx ];
				return;
			}

			var targetInput = getQtyInput( targetIdx );
			if ( ! targetInput ) {
				log( '  target[' + targetIdx + ']: input MISSING — skipping' );
				desiredQtys[ targetIdx ] = total; // still record for enforcement
				return;
			}

			var current = parseInt( targetInput.value, 10 ) || 0;
			log( '  target[' + targetIdx + ']: current=' + current + ' new=' + total );

			desiredQtys[ targetIdx ] = total;

			if ( current !== total ) {
				setReactInputValue( targetInput, total );
				forceDOMValue( targetInput, total );
			}
		} );
	}

	/**
	 * Get the value that React's internal state holds for a controlled input.
	 * React stores the current props (including `value`) on the DOM node under
	 * a key starting with '__reactProps' (React 17+) or '__reactEventHandlers'
	 * (React 16). Falls back to the raw DOM value if unavailable.
	 */
	function getReactStateValue( input ) {
		var keys = Object.keys( input );
		for ( var ki = 0; ki < keys.length; ki++ ) {
			var k = keys[ ki ];
			if ( k.startsWith( '__reactProps' ) || k.startsWith( '__reactEventHandlers' ) ) {
				var v = input[ k ] && input[ k ].value;
				if ( v !== undefined && v !== null ) {
					return parseInt( v, 10 );
				}
				break;
			}
		}
		return parseInt( input.value, 10 );
	}

	function enforceDesiredQtys() {
		var indices = Object.keys( desiredQtys );
		for ( var i = 0; i < indices.length; i++ ) {
			var idx     = parseInt( indices[ i ], 10 );
			var desired = desiredQtys[ idx ];
			var input   = getQtyInput( idx );
			if ( ! input ) {
				continue;
			}
			var domVal   = parseInt( input.value, 10 );
			var reactVal = getReactStateValue( input );

			// Keep DOM display correct (visual enforcement).
			if ( domVal !== desired ) {
				log( 'enforce qty: target[' + idx + '] DOM=' + domVal + ' desired=' + desired );
				forceDOMValue( input, desired );
			}

			// Also keep React state correct so the hidden cart JSON input is right.
			// The bundle plugin builds cart data from React state, not DOM values.
			if ( reactVal !== desired ) {
				log( 'enforce qty: target[' + idx + '] React state=' + reactVal + ' desired=' + desired + ' — updating React state' );
				setReactInputValue( input, desired );
			}
		}
	}

	// ── Feature 3 — Per-item Show Qty Override ────────────────────────────────

	/**
	 * Force specific item qty wrappers to be visible even when CSS hides all
	 * .asnp-product-quantity-field elements.
	 *
	 * We use inline style with !important which takes precedence over the
	 * stylesheet's !important rule.
	 */
	function enforceShowOverrides() {
		if ( ! showOverrides.length ) {
			return;
		}
		showOverrides.forEach( function ( idx ) {
			var wrapper = getQtyFieldWrapper( idx );
			if ( ! wrapper ) {
				return;
			}
			// Only act if the element is currently hidden (avoids re-setting every tick).
			var display = wrapper.style.getPropertyValue( 'display' );
			var priority = wrapper.style.getPropertyPriority( 'display' );
			if ( display !== 'flex' || priority !== 'important' ) {
				wrapper.style.setProperty( 'display', 'flex', 'important' );
				log( 'enforceShowOverrides: showed wrapper for index', idx );
			}
		} );
	}

	// ── Feature 4 — Max from Items ────────────────────────────────────────────

	// Maps targetIndex → desired effective max.
	var desiredMaxes = {};

	function recalculateMaxLinks() {
		if ( ! maxLinks.length ) {
			return;
		}
		log( 'recalculateMaxLinks()' );

		maxLinks.forEach( function ( link ) {
			var targetIdx  = link.target;
			var sourceIdxs = link.sources;
			var baseMax    = link.base_max || 0;

			if ( ! baseMax ) {
				log( '  max-link target[' + targetIdx + ']: no base_max configured — skipping' );
				return;
			}

			var sum = 0;
			sourceIdxs.forEach( function ( srcIdx ) {
				var srcInput = getQtyInput( srcIdx );
				var selected = isItemSelected( srcInput );
				var qty      = srcInput ? ( parseInt( srcInput.value, 10 ) || 0 ) : 0;
				var contrib  = selected ? qty : 0;
				sum         += contrib;
				log( '  max-source[' + srcIdx + ']: found=' + !! srcInput +
				     ' selected=' + selected + ' qty=' + qty + ' contributes=' + contrib );
			} );

			var effectiveMax = Math.max( 1, baseMax - sum );
			log( '  max-target[' + targetIdx + ']: baseMax=' + baseMax +
			     ' sum=' + sum + ' effectiveMax=' + effectiveMax );

			desiredMaxes[ targetIdx ] = effectiveMax;

			// Track for message enforcement (also updates on every recalculate).
			if ( maxMsg ) {
				desiredMessages[ targetIdx ] = effectiveMax;
			}

			var targetInput = getQtyInput( targetIdx );
			if ( ! targetInput ) {
				return;
			}

			// Set max attribute.
			targetInput.setAttribute( 'max', effectiveMax );

			// Clamp current qty if it exceeds the new max.
			var currentVal = parseInt( targetInput.value, 10 ) || 0;
			if ( currentVal > effectiveMax ) {
				log( '  max-target[' + targetIdx + ']: clamping ' + currentVal + ' → ' + effectiveMax );
				setReactInputValue( targetInput, effectiveMax );
				forceDOMValue( targetInput, effectiveMax );
				// NOTE: intentionally do NOT set desiredQtys[targetIdx] here.
				// Feature 4 constrains the *max*, not the value. The user must be
				// free to choose any quantity ≤ effectiveMax using the +/- buttons.
				// Setting desiredQtys would lock the display at effectiveMax and
				// override user +/- interactions via enforceDesiredQtys().
			}
		} );
	}

	function enforceMaxes() {
		var indices = Object.keys( desiredMaxes );
		for ( var i = 0; i < indices.length; i++ ) {
			var idx    = parseInt( indices[ i ], 10 );
			var maxVal = desiredMaxes[ idx ];
			var input  = getQtyInput( idx );
			if ( ! input ) {
				continue;
			}
			// Re-enforce max attribute (React may reset it on re-render).
			var attrMax = parseInt( input.getAttribute( 'max' ), 10 );
			if ( attrMax !== maxVal ) {
				input.setAttribute( 'max', maxVal );
			}
			// Clamp if current value exceeds the enforced max.
			// Call setReactInputValue so React's internal state also moves to
			// maxVal — otherwise React re-renders the component with its old
			// (above-max) state and immediately overrides our forceDOMValue,
			// causing visible flickering/delay before the display settles.
			var currentVal = parseInt( input.value, 10 ) || 0;
			if ( currentVal > maxVal ) {
				log( 'enforceMaxes: clamp target[' + idx + '] ' + currentVal + ' → ' + maxVal );
				setReactInputValue( input, maxVal );
				forceDOMValue( input, maxVal );
			}
		}
	}

	// ── Feature 4 message — Max Qty Message ──────────────────────────────────

	// CSS class applied to injected message elements so we can find/update them.
	var MSG_CLASS = 'epb-aim-max-msg';

	// Maps targetIndex → the effective max to display in the message.
	// Populated by recalculateMaxLinks(); read by enforceMessages().
	var desiredMessages = {};

	/**
	 * Inject or update the max-qty message sibling for each Feature 4 target.
	 *
	 * The message is a <p class="epb-aim-max-msg"> inserted immediately after
	 * the target item's .asnp-bundleListItem-product-detail element. React
	 * may remove it on re-render; this function is called from the 50ms
	 * enforcement interval so it is quickly re-injected if that happens.
	 */
	function enforceMessages() {
		if ( ! maxMsg ) {
			return; // message text not configured — nothing to do.
		}

		var indices = Object.keys( desiredMessages );
		for ( var i = 0; i < indices.length; i++ ) {
			var idx        = parseInt( indices[ i ], 10 );
			var effectMax  = desiredMessages[ idx ];
			var detail     = getItemDetailElement( idx );
			if ( ! detail ) {
				continue;
			}

			var fullText = maxMsg + ' ' + effectMax;
			var parent   = detail.parentElement;
			if ( ! parent ) {
				continue;
			}

			// Look for an existing message element immediately after detail.
			var existing = null;
			var sibling  = detail.nextSibling;
			while ( sibling ) {
				if (
					sibling.nodeType === 1 &&
					sibling.classList &&
					sibling.classList.contains( MSG_CLASS )
				) {
					existing = sibling;
					break;
				}
				// Stop searching if we hit another bundle-item element.
				if (
					sibling.nodeType === 1 &&
					sibling.classList &&
					sibling.classList.contains( 'asnp-bundleListItem-product-detail' )
				) {
					break;
				}
				sibling = sibling.nextSibling;
			}

			if ( existing ) {
				if ( existing.textContent !== fullText ) {
					existing.textContent = fullText;
					log( 'enforceMessages: updated message for index', idx, '→', fullText );
				}
			} else {
				var msgEl = document.createElement( 'p' );
				msgEl.className   = MSG_CLASS;
				msgEl.textContent = fullText;
				parent.insertBefore( msgEl, detail.nextSibling );
				log( 'enforceMessages: injected message for index', idx, '→', fullText );
			}
		}
	}

	// ── Feature 5 — Bundle Qty Message label ─────────────────────────────────

	/**
	 * Insert a text label immediately before the .quantity div inside form.cart.
	 *
	 * We use JS injection rather than a PHP hook so that the label is always
	 * placed in the correct DOM position regardless of theme flex/order CSS.
	 * order: -1 on the span also guarantees it appears first visually in any
	 * flexbox layout the theme may apply to form.cart.
	 *
	 * Called once on page load (with a few staggered delays to catch async
	 * React renders) and is idempotent — re-running it when the label already
	 * exists is safe.
	 */
	function injectBundleQtyLabel() {
		if ( ! bundleQtyMsg ) {
			return;
		}

		var form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return;
		}

		// Already injected — nothing to do.
		if ( form.querySelector( '.epb-aim-qty-wrapper' ) ) {
			return;
		}

		var qtyDiv = form.querySelector( '.quantity' );
		if ( ! qtyDiv ) {
			return;
		}

		// Build the label + qty wrapper.
		var wrapper = document.createElement( 'div' );
		wrapper.className = 'epb-aim-qty-wrapper';

		var span = document.createElement( 'span' );
		span.className   = 'epb-aim-qty-label';
		span.textContent = bundleQtyMsg;

		wrapper.appendChild( span );
		wrapper.appendChild( qtyDiv ); // moves qtyDiv out of form into wrapper

		var submitBtn = form.querySelector( 'button[type="submit"], input[type="submit"], .single_add_to_cart_button' );
		if ( submitBtn ) {
			// Wrap BOTH the qty-wrapper AND the submit button in a single row
			// container that we control.  This bypasses whatever flex-direction,
			// order, or float rules the theme applies to form.cart itself —
			// our row always uses flex-direction:row so the visual order is
			// exactly: [label + qty]  [Add to cart button].
			var row = document.createElement( 'div' );
			row.className = 'epb-aim-form-row';
			row.appendChild( wrapper );   // label+qty FIRST
			row.appendChild( submitBtn ); // button SECOND (moved from form into row)
			form.appendChild( row );      // row appended after any remaining hidden inputs
		} else {
			form.appendChild( wrapper );
		}

		log( 'injectBundleQtyLabel: built epb-aim-form-row(wrapper+button) for "' + bundleQtyMsg + '"' );
	}

	// ── Feature 6 — No Selection Message ─────────────────────────────────────

	// The default text produced by the base plugin when no items are selected.
	var NO_SELECTION_DEFAULT = 'Please select a product for all items.';

	/**
	 * Replace the text inside .asnp-alert elements when they show the default
	 * "Please select a product for all items." string with the admin-configured
	 * custom message.  Any other alert text is left unchanged.
	 *
	 * Called from the 50 ms enforcement interval so React re-renders are caught
	 * quickly.
	 */
	function enforceNoSelectionMessage() {
		if ( ! noSelectionMsg ) {
			return;
		}

		var alerts = document.querySelectorAll( '.asnp-alert' );
		for ( var i = 0; i < alerts.length; i++ ) {
			var el = alerts[ i ];
			// The alert element may contain child nodes (e.g. a dashicons span).
			// We look at the text content of each TEXT_NODE child rather than
			// the full textContent of the element so we do not disturb the icon.
			var nodes = el.childNodes;
			for ( var j = 0; j < nodes.length; j++ ) {
				var node = nodes[ j ];
				if ( node.nodeType === 3 ) { // TEXT_NODE
					var trimmed = node.nodeValue.trim();
					if ( trimmed === NO_SELECTION_DEFAULT ) {
						// Preserve any leading/trailing whitespace around the text.
						var before = node.nodeValue.slice( 0, node.nodeValue.indexOf( trimmed ) );
						var after  = node.nodeValue.slice( node.nodeValue.indexOf( trimmed ) + trimmed.length );
						node.nodeValue = before + noSelectionMsg + after;
						log( 'enforceNoSelectionMessage: replaced default text with custom message' );
					}
				}
			}
		}
	}

	// ── Feature 7 — Qty × Unit Price 3-row display ───────────────────────────

	// Message labels sourced from admin settings (with fallbacks for safety).
	var eachBundleMessage  = ( typeof data.aim_each_bundle_message === 'string' && data.aim_each_bundle_message.trim() )
		? data.aim_each_bundle_message.trim() : 'Each Item:';
	var bundleTotalMessage = ( typeof data.aim_bundle_total_message === 'string' && data.aim_bundle_total_message.trim() )
		? data.aim_bundle_total_message.trim() : 'Total:';
	var quantityLabel      = ( typeof data.aim_quantity_label === 'string' && data.aim_quantity_label.trim() )
		? data.aim_quantity_label.trim() : 'Quantity:';

	// Stores the discounted unit price for 1 bundle, set on each
	// asnpWepbPriceChanged event. Null until the first event fires.
	var aimUnitPrice = null;

	/**
	 * Return the bundle-level quantity <input> from form.cart.
	 * Exact name="quantity" match excludes bundle-item inputs whose names
	 * are like "asnp_wepb_bundle[0][productList_quantity]".
	 */
	function getBundleQtyInput() {
		var form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return null;
		}
		return form.querySelector( 'input[name="quantity"]' );
	}

	/**
	 * Format a numeric price as HTML matching the bundle plugin's native output.
	 *
	 * Builds the same structure React's Price component produces:
	 *   <span class="woocommerce-Price-amount amount">
	 *     <bdi><span class="woocommerce-Price-currencySymbol">$</span>49.00</bdi>
	 *   </span>
	 *
	 * Uses easyProductBundlesData fields directly (the same source the bundle
	 * plugin's formatPrice utility reads from) so currency symbol, decimal
	 * separator, thousand separator, and price format are always consistent.
	 */
	function formatAimPrice( amount ) {
		var decimals = parseInt( data.number_of_decimals, 10 );
		if ( isNaN( decimals ) || decimals < 0 ) {
			decimals = 2;
		}
		var decSep      = typeof data.decimal_separator  === 'string' ? data.decimal_separator  : '.';
		var thouSep     = typeof data.thousand_separator === 'string' ? data.thousand_separator : ',';
		var symbol      = typeof data.currency           === 'string' ? data.currency           : '';
		var priceFormat = typeof data.price_format       === 'string' ? data.price_format       : '%1$s%2$s';

		var fixed   = amount.toFixed( decimals );
		var parts   = fixed.split( '.' );
		var intPart = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, thouSep );
		var decPart = parts[ 1 ] || '';
		var number  = decPart ? intPart + decSep + decPart : intPart;

		var symHtml   = '<span class="woocommerce-Price-currencySymbol">' + symbol + '</span>';
		var priceHtml = priceFormat.replace( '%1$s', symHtml ).replace( '%2$s', number );

		return '<span class="woocommerce-Price-amount amount"><bdi>' + priceHtml + '</bdi></span>';
	}

	/**
	 * Replace the original .asnp-totalPrice-title text with eachBundleMessage.
	 * Targets only the non-injected title (no .epb-aim-row-label class).
	 * Idempotent — safe to call every 50 ms.
	 */
	function enforceTitle() {
		if ( ! qtyTotal ) {
			return;
		}
		var wrapper = document.querySelector( '.asnp-totalPrice-wrapper' );
		if ( ! wrapper ) {
			return;
		}
		var title = wrapper.querySelector( '.asnp-totalPrice-title:not(.epb-aim-row-label)' );
		if ( ! title ) {
			return;
		}
		if ( title.textContent !== eachBundleMessage ) {
			title.textContent = eachBundleMessage;
			log( 'Feature 7: set title to "' + eachBundleMessage + '"' );
		}
	}

	/**
	 * Inject or update the Quantity and Total rows inside .asnp-totalPrice-wrapper.
	 * Rows are hidden when aimUnitPrice is unknown or zero.
	 * Idempotent — safe to call every 50 ms.
	 * Pure display — does not affect cart data.
	 */
	function updateQtyTotalRows() {
		if ( ! qtyTotal ) {
			return;
		}
		var wrapper = document.querySelector( '.asnp-totalPrice-wrapper' );
		if ( ! wrapper ) {
			return;
		}

		// Allow injected rows to wrap onto their own lines inside the wrapper.
		if ( wrapper.style.flexWrap !== 'wrap' ) {
			wrapper.style.flexWrap = 'wrap';
		}

		// ── Quantity row ──────────────────────────────────────────────────────
		var qtyRow = wrapper.querySelector( '.epb-aim-price-row[data-aim="qty"]' );
		if ( ! qtyRow ) {
			qtyRow = document.createElement( 'div' );
			qtyRow.className = 'epb-aim-price-row';
			qtyRow.setAttribute( 'data-aim', 'qty' );
			qtyRow.innerHTML =
				'<span class="asnp-totalPrice-title epb-aim-row-label"></span>' +
				'<span class="asnp-totalPrice-section epb-aim-row-value"></span>';
			wrapper.appendChild( qtyRow );
			log( 'Feature 7: injected qty row' );
		}

		// ── Total row ─────────────────────────────────────────────────────────
		var totalRow = wrapper.querySelector( '.epb-aim-price-row[data-aim="total"]' );
		if ( ! totalRow ) {
			totalRow = document.createElement( 'div' );
			totalRow.className = 'epb-aim-price-row';
			totalRow.setAttribute( 'data-aim', 'total' );
			totalRow.innerHTML =
				'<span class="asnp-totalPrice-title epb-aim-row-label"></span>' +
				'<span class="asnp-totalPrice-section epb-aim-row-value"></span>';
			wrapper.appendChild( totalRow );
			log( 'Feature 7: injected total row' );
		}

		// ── Show / hide based on price availability ───────────────────────────
		if ( aimUnitPrice === null || aimUnitPrice <= 0 ) {
			qtyRow.style.display   = 'none';
			totalRow.style.display = 'none';
			return;
		}

		qtyRow.style.display   = '';
		totalRow.style.display = '';

		// ── Populate values ───────────────────────────────────────────────────
		var qtyInput  = getBundleQtyInput();
		var qty       = qtyInput ? ( parseInt( qtyInput.value, 10 ) || 1 ) : 1;
		var total     = aimUnitPrice * qty;

		var qtyLabel  = qtyRow.querySelector( '.epb-aim-row-label' );
		var qtyValue  = qtyRow.querySelector( '.epb-aim-row-value' );
		var totLabel  = totalRow.querySelector( '.epb-aim-row-label' );
		var totValue  = totalRow.querySelector( '.epb-aim-row-value' );
		var totalHtml = formatAimPrice( total );

		if ( qtyLabel.textContent !== quantityLabel ) {
			qtyLabel.textContent = quantityLabel;
		}
		if ( qtyValue.textContent !== String( qty ) ) {
			qtyValue.textContent = String( qty );
			log( 'Feature 7: qty row → ' + qty );
		}
		if ( totLabel.textContent !== bundleTotalMessage ) {
			totLabel.textContent = bundleTotalMessage;
		}
		if ( totValue.innerHTML !== totalHtml ) {
			totValue.innerHTML = totalHtml;
			log( 'Feature 7: total row → ' + total );
		}
	}

	var qtyTotalTimer = null;
	function scheduleQtyTotalUpdate( delay ) {
		if ( qtyTotalTimer ) {
			clearTimeout( qtyTotalTimer );
		}
		qtyTotalTimer = setTimeout( function () {
			enforceTitle();
			updateQtyTotalRows();
		}, delay !== undefined ? delay : 50 );
	}

	if ( qtyTotal ) {
		// Capture the unit price whenever the bundle recalculates its price.
		document.addEventListener( 'asnpWepbPriceChanged', function ( e ) {
			var detail = e && e.detail;
			if ( detail && typeof detail.price === 'number' ) {
				aimUnitPrice = detail.price;
				log( 'Feature 7: asnpWepbPriceChanged → aimUnitPrice =', aimUnitPrice );
			}
			scheduleQtyTotalUpdate( 10 );
		}, false );

		// Update rows when the bundle quantity input changes.
		document.addEventListener( 'input', function ( e ) {
			if ( e.target && e.target.getAttribute( 'name' ) === 'quantity' ) {
				log( 'Feature 7: bundle qty input changed — scheduling update' );
				scheduleQtyTotalUpdate( 0 );
			}
		}, false );

		document.addEventListener( 'change', function ( e ) {
			if ( e.target && e.target.getAttribute( 'name' ) === 'quantity' ) {
				scheduleQtyTotalUpdate( 0 );
			}
		}, false );

		// Initial runs — React renders the price wrapper asynchronously.
		setTimeout( function () { enforceTitle(); updateQtyTotalRows(); }, 300 );
		setTimeout( function () { enforceTitle(); updateQtyTotalRows(); }, 700 );
		setTimeout( function () { enforceTitle(); updateQtyTotalRows(); }, 1500 );
	}

	// =========================================================================
	// Feature 8 — Image Swap Rules
	// =========================================================================

	/**
	 * Returns true when the bundle item at itemIdx is currently selected.
	 * Uses #asnp-bundle-item-{N} element IDs (present in both grid and list view)
	 * to locate the image box and check for the asnp-disable-product class.
	 * Falls back to qty-input / isItemSelected when the element is not found.
	 */
	function isSwapOptionalSelected( itemIdx ) {
		var item = document.getElementById( 'asnp-bundle-item-' + itemIdx );
		if ( item ) {
			var imageBox = item.querySelector( '.asnp-bundleListItem-imageBox, .asnp-BundleGridItem-imageBox' );
			if ( imageBox ) {
				return ! imageBox.classList.contains( 'asnp-disable-product' );
			}
		}
		// Fallback: qty input ancestor check.
		var input = getQtyInput( itemIdx );
		if ( input ) {
			return isItemSelected( input );
		}
		return true; // Assume selected when state cannot be determined.
	}

	/**
	 * Returns the current qty of the optional bundle item at itemIdx.
	 * Reads the React-controlled (non-disabled) qty input via getQtyInput().
	 * Falls back to 1 when the input is not found or has no valid value.
	 */
	function getOptionalItemQty( itemIdx ) {
		var input = getQtyInput( itemIdx );
		if ( ! input ) {
			return 1;
		}
		var val = parseInt( input.value, 10 );
		return ( isNaN( val ) || val < 1 ) ? 1 : val;
	}

	/**
	 * Returns the .woocommerce-product-gallery__image div for the given required
	 * bundle item index.
	 *
	 * - If nonOptionalOrder is empty (backward compat) or targetIdx is the first
	 *   non-optional item, returns the div containing img.wp-post-image (slide 0).
	 * - Otherwise returns the AIM-injected slide carrying data-aim-item-idx=targetIdx.
	 */
	function getGallerySlideDiv( targetIdx ) {
		if ( nonOptionalOrder.length === 0 || nonOptionalOrder[ 0 ] === targetIdx ) {
			var mainImg = document.querySelector( 'img.wp-post-image' );
			return mainImg ? mainImg.closest( '.woocommerce-product-gallery__image' ) : null;
		}
		return document.querySelector( '.woocommerce-product-gallery__image[data-aim-item-idx="' + targetIdx + '"]' );
	}

	/**
	 * Returns the 0-based position of slideDiv inside its .woocommerce-product-gallery__wrapper.
	 * Used to target the matching <li> in the FlexSlider thumbnail strip.
	 */
	function getGallerySlideIndex( slideDiv ) {
		if ( ! slideDiv || ! slideDiv.parentElement ) {
			return -1;
		}
		var siblings = slideDiv.parentElement.querySelectorAll( '.woocommerce-product-gallery__image' );
		for ( var si = 0; si < siblings.length; si++ ) {
			if ( siblings[ si ] === slideDiv ) {
				return si;
			}
		}
		return -1;
	}

	/**
	 * Returns the <img> element inside the bundle item image box at targetIdx.
	 * Uses #asnp-bundle-item-{N} element IDs (present in both grid and list view).
	 */
	function getSwapTargetImg( targetIdx ) {
		var item = document.getElementById( 'asnp-bundle-item-' + targetIdx );
		if ( item ) {
			var imageBox = item.querySelector( '.asnp-bundleListItem-imageBox, .asnp-BundleGridItem-imageBox' );
			if ( imageBox ) {
				return imageBox.querySelector( 'img' );
			}
		}
		return null;
	}

	/**
	 * For each configured swap target, determine the current selection state
	 * of the monitored optional items, find the matching rule (if any), and
	 * update the target item's <img> src accordingly.
	 *
	 * Called from the 50 ms enforcement interval and recalculate().
	 * The original src is saved in data-aim-orig-src before the first swap so
	 * it can be restored when no rule matches.
	 */
	function enforceImageSwap() {
		if ( ! imageSwapRules.length ) {
			return;
		}

		// Group rules by target item index.
		var byTarget = {};
		for ( var i = 0; i < imageSwapRules.length; i++ ) {
			var rule = imageSwapRules[ i ];
			var t    = rule.target;
			if ( typeof t !== 'number' ) {
				continue;
			}
			if ( ! byTarget[ t ] ) {
				byTarget[ t ] = [];
			}
			byTarget[ t ].push( rule );
		}

		var targetKeys = Object.keys( byTarget );
		for ( var k = 0; k < targetKeys.length; k++ ) {
			var targetIdx = parseInt( targetKeys[ k ], 10 );
			var rules     = byTarget[ targetIdx ];

			// Compute "monitored" optional items = union of all items appearing
			// in any rule's selected array for this target.
			var monitoredMap = {};
			for ( var r = 0; r < rules.length; r++ ) {
				var sel = rules[ r ].selected || [];
				for ( var s = 0; s < sel.length; s++ ) {
					// Support old format (integer) and new format ({index, qty} object).
					var selIdx = ( typeof sel[ s ] === 'number' ) ? sel[ s ] : sel[ s ].index;
					monitoredMap[ selIdx ] = true;
				}
			}
			var monitored = Object.keys( monitoredMap ).map( Number );

			// Current selection state of monitored optional items.
			var currentSelected = [];
			for ( var m = 0; m < monitored.length; m++ ) {
				if ( isSwapOptionalSelected( monitored[ m ] ) ) {
					currentSelected.push( monitored[ m ] );
				}
			}
			currentSelected.sort( function ( a, b ) { return a - b; } );

			// Find matching rule: exact match on the selected set, then qty match.
			var matchedRule = null;
			for ( var j = 0; j < rules.length; j++ ) {
				// Normalise: each selected entry may be an integer (old) or {index, qty} (new).
				var ruleItems   = ( rules[ j ].selected || [] ).map( function ( s ) {
					return ( typeof s === 'number' ) ? { index: s, qty: null } : s;
				} );
				var ruleIndices = ruleItems.map( function ( s ) { return s.index; } )
					.sort( function ( a, b ) { return a - b; } );

				// Step 1 — exact set match.
				if ( ruleIndices.length !== currentSelected.length ) {
					continue;
				}
				var setMatch = true;
				for ( var n = 0; n < ruleIndices.length; n++ ) {
					if ( ruleIndices[ n ] !== currentSelected[ n ] ) {
						setMatch = false;
						break;
					}
				}
				if ( ! setMatch ) {
					continue;
				}

				// Step 2 — qty match: for items with a specified qty, the item's
				// current qty must match exactly. No qty (null) matches any qty.
				var qtyMatch = true;
				for ( var qi = 0; qi < ruleItems.length; qi++ ) {
					var ruleItem = ruleItems[ qi ];
					if ( ruleItem.qty !== null && ruleItem.qty !== undefined ) {
						if ( getOptionalItemQty( ruleItem.index ) !== ruleItem.qty ) {
							qtyMatch = false;
							break;
						}
					}
				}
				if ( ! qtyMatch ) {
					continue;
				}

				matchedRule = rules[ j ];
				break;
			}

			var hasMatch    = !! ( matchedRule && matchedRule.image_url );
			var scope       = hasMatch ? ( matchedRule.scope || 'item' ) : '';
			var swapItem    = hasMatch && ( scope === 'item' || scope === 'both' );
			// 'gallery' is the current name; 'main' is supported for backward compat.
			var swapGallery = hasMatch && ( scope === 'gallery' || scope === 'main' || scope === 'both' );

			// ── Bundle item image ────────────────────────────────────────
			var img = getSwapTargetImg( targetIdx );
			if ( img ) {
				if ( swapItem ) {
					if ( ! img.hasAttribute( 'data-aim-orig-src' ) ) {
						img.setAttribute( 'data-aim-orig-src', img.getAttribute( 'src' ) || '' );
					}
					if ( img.getAttribute( 'src' ) !== matchedRule.image_url ) {
						img.setAttribute( 'src', matchedRule.image_url );
						log( 'Feature 8: item', targetIdx, '→ item image swapped to', matchedRule.image_url );
					}
				} else {
					var origSrc = img.getAttribute( 'data-aim-orig-src' );
					if ( origSrc !== null ) {
						if ( img.getAttribute( 'src' ) !== origSrc ) {
							img.setAttribute( 'src', origSrc );
							log( 'Feature 8: item', targetIdx, '→ item image restored' );
						}
						img.removeAttribute( 'data-aim-orig-src' );
					}
				}
			}

			// ── Gallery slide image (includes thumbnail strip and zoomImg) ──
			var slideDiv = getGallerySlideDiv( targetIdx );
			if ( slideDiv ) {
				var slideImg  = slideDiv.querySelector( 'a > img' );
				var slideA    = slideDiv.querySelector( 'a' );
				var slideZoom = slideDiv.querySelector( 'img.zoomImg' );
				var slideIdx  = getGallerySlideIndex( slideDiv );

				if ( swapGallery ) {
					var newUrl    = matchedRule.image_url;
					var thumbUrl  = matchedRule.image_thumb_url || newUrl;

					// Save originals on first swap.
					if ( slideImg && ! slideImg.hasAttribute( 'data-aim-orig-gallery-src' ) ) {
						slideImg.setAttribute( 'data-aim-orig-gallery-src',    slideImg.getAttribute( 'src' )              || '' );
						slideImg.setAttribute( 'data-aim-orig-gallery-srcset', slideImg.getAttribute( 'srcset' )           || '' );
						slideImg.setAttribute( 'data-aim-orig-gallery-dsrc',   slideImg.getAttribute( 'data-src' )         || '' );
						slideImg.setAttribute( 'data-aim-orig-gallery-large',  slideImg.getAttribute( 'data-large_image' ) || '' );
					}
					if ( slideA && ! slideA.hasAttribute( 'data-aim-orig-gallery-href' ) ) {
						slideA.setAttribute( 'data-aim-orig-gallery-href', slideA.getAttribute( 'href' ) || '' );
					}

					// Update slide image.
					if ( slideImg && slideImg.getAttribute( 'src' ) !== newUrl ) {
						slideImg.setAttribute( 'src',              newUrl );
						slideImg.setAttribute( 'srcset',           newUrl );
						slideImg.setAttribute( 'data-src',         newUrl );
						slideImg.setAttribute( 'data-large_image', newUrl );
						log( 'Feature 8: item', targetIdx, '→ gallery slide', slideIdx, 'swapped to', newUrl );
					}
					// Update PhotoSwipe anchor (opens correct full-size image on click).
					if ( slideA ) {
						slideA.setAttribute( 'href', newUrl );
					}
					// Fix zoomImg — the zoom plugin caches its URL at init time and
					// does not react to data-large_image changes; update directly.
					if ( slideZoom && slideZoom.getAttribute( 'src' ) !== newUrl ) {
						slideZoom.setAttribute( 'src', newUrl );
					}
					// Fix FlexSlider thumbnail strip.
					if ( slideIdx >= 0 ) {
						var thumbLi = document.querySelectorAll( '.flex-control-thumbs li' )[ slideIdx ];
						if ( thumbLi ) {
							var thumbImgEl = thumbLi.querySelector( 'img' );
							if ( thumbImgEl && thumbImgEl.getAttribute( 'src' ) !== thumbUrl ) {
								thumbImgEl.setAttribute( 'src',    thumbUrl );
								thumbImgEl.setAttribute( 'srcset', thumbUrl );
							}
						}
					}
				} else {
					// Restore gallery slide to its original image.
					if ( slideImg ) {
						var origGalSrc = slideImg.getAttribute( 'data-aim-orig-gallery-src' );
						if ( origGalSrc !== null ) {
							var origGalSrcset = slideImg.getAttribute( 'data-aim-orig-gallery-srcset' ) || '';
							var origGalDsrc   = slideImg.getAttribute( 'data-aim-orig-gallery-dsrc'   ) || '';
							var origGalLarge  = slideImg.getAttribute( 'data-aim-orig-gallery-large'  ) || '';
							slideImg.setAttribute( 'src',              origGalSrc    );
							slideImg.setAttribute( 'srcset',           origGalSrcset );
							slideImg.setAttribute( 'data-src',         origGalDsrc   );
							slideImg.setAttribute( 'data-large_image', origGalLarge  );
							slideImg.removeAttribute( 'data-aim-orig-gallery-src'    );
							slideImg.removeAttribute( 'data-aim-orig-gallery-srcset' );
							slideImg.removeAttribute( 'data-aim-orig-gallery-dsrc'   );
							slideImg.removeAttribute( 'data-aim-orig-gallery-large'  );
							log( 'Feature 8: item', targetIdx, '→ gallery slide', slideIdx, 'restored' );
							// Restore zoomImg.
							if ( slideZoom ) {
								slideZoom.setAttribute( 'src', origGalSrc );
							}
							// Restore FlexSlider thumbnail.
							if ( slideIdx >= 0 ) {
								var thumbLiR = document.querySelectorAll( '.flex-control-thumbs li' )[ slideIdx ];
								if ( thumbLiR ) {
									var thumbImgR = thumbLiR.querySelector( 'img' );
									if ( thumbImgR ) {
										thumbImgR.setAttribute( 'src',    origGalSrc    );
										thumbImgR.setAttribute( 'srcset', origGalSrcset );
									}
								}
							}
						}
					}
					// Restore anchor href.
					if ( slideA ) {
						var origGalHref = slideA.getAttribute( 'data-aim-orig-gallery-href' );
						if ( origGalHref !== null ) {
							slideA.setAttribute( 'href', origGalHref );
							slideA.removeAttribute( 'data-aim-orig-gallery-href' );
						}
					}
				}
			}
		}
	}

	// =========================================================================
	// Feature 9 — Bundle Qty +/- Buttons
	// =========================================================================

	/**
	 * Replaces the browser spin arrows on the main bundle quantity input
	 * (form.cart input[name="quantity"]) with − and + buttons that match the
	 * existing .asnp-product-quantity-button style used by bundle item qtys.
	 *
	 * Idempotent: if .epb-aim-qty-pm-active is already on the .quantity wrapper
	 * the function returns immediately. Safe to call on every enforcement tick.
	 */
	function injectQtyPlusMinus() {
		if ( ! qtyPlusMinus ) {
			return;
		}

		var form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return;
		}

		var input = form.querySelector( 'input[name="quantity"]' );
		if ( ! input ) {
			return;
		}

		var wrapper = input.closest( '.quantity' );
		if ( ! wrapper ) {
			return;
		}

		// Idempotency check.
		if ( wrapper.classList.contains( 'epb-aim-qty-pm-active' ) ) {
			return;
		}

		wrapper.classList.add( 'epb-aim-qty-pm-active' );

		function makeBtn( type ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'asnp-product-quantity-button epb-aim-qty-pm-btn epb-aim-qty-pm-' + type;
			var icon = document.createElement( 'span' );
			icon.className = 'dashicons ' + ( type === 'minus' ? 'dashicons-minus' : 'dashicons-plus-alt2' );
			btn.appendChild( icon );

			btn.addEventListener( 'click', function () {
				var step = parseFloat( input.step ) || 1;
				var min  = parseFloat( input.min );
				if ( isNaN( min ) ) {
					min = 1;
				}
				var current = parseFloat( input.value ) || min;
				var next;
				if ( type === 'minus' ) {
					next = Math.max( min, current - step );
				} else {
					var max = parseFloat( input.max );
					next = isNaN( max ) ? current + step : Math.min( max, current + step );
				}
				if ( next === current ) {
					return;
				}
				// Use native setter so React / WooCommerce JS pick up the change.
				var nativeSetter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' );
				if ( nativeSetter && nativeSetter.set ) {
					nativeSetter.set.call( input, String( next ) );
				} else {
					input.value = String( next );
				}
				input.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
				input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				log( 'Feature 9: qty ' + type + ' → ' + next );
			} );

			return btn;
		}

		wrapper.insertBefore( makeBtn( 'minus' ), input );
		wrapper.appendChild( makeBtn( 'plus' ) );

		// Apply zoom scaling if size < 100.
		if ( qtyPlusMinus && qtyPmSize < 100 ) {
			var styleId  = 'epb-aim-pm-size-style';
			if ( ! document.getElementById( styleId ) ) {
				var zoomVal  = ( qtyPmSize / 100 ).toFixed( 2 );
				var styleEl  = document.createElement( 'style' );
				styleEl.id   = styleId;
				styleEl.textContent = '.epb-aim-qty-pm-btn { zoom: ' + zoomVal + '; }';
				document.head.appendChild( styleEl );
				log( 'Feature 9: injected pm-size style zoom=' + zoomVal );
			}
		}

		log( 'Feature 9: injected qty +/- buttons' );
	}

	// =========================================================================
	// Feature 10 — Swap price block and qty row positions
	// =========================================================================

	/**
	 * Moves .asnp-totalPrice-wrapper so it appears immediately after form.cart
	 * in the DOM, reversing the default order where the price block precedes
	 * the qty/add-to-cart row.
	 *
	 * Idempotent: checks for a data-aim-pos-swapped attribute on the price
	 * element before acting so repeated calls on the enforcement interval are
	 * free.  If React re-renders and inserts a fresh price element the
	 * attribute will be absent and the function re-applies the move.
	 */
	function enforcePositionSwap() {
		if ( ! swapPositions ) {
			return;
		}

		var priceEl = document.querySelector( '.asnp-totalPrice-wrapper' );
		if ( ! priceEl ) {
			return;
		}

		// Already moved on this render cycle — nothing to do.
		if ( priceEl.getAttribute( 'data-aim-pos-swapped' ) === '1' ) {
			return;
		}

		var form = document.querySelector( 'form.cart' );
		if ( ! form ) {
			return;
		}

		// Insert priceEl immediately after form.cart.
		form.parentNode.insertBefore( priceEl, form.nextSibling );
		priceEl.setAttribute( 'data-aim-pos-swapped', '1' );

		log( 'Feature 10: moved .asnp-totalPrice-wrapper after form.cart' );
	}

	// =========================================================================
	// Feature 11 — Auto-fit qty row to single line
	// =========================================================================

	var adjustFormRowTimer = null;

	/**
	 * Measures the available width inside .epb-aim-form-row after the qty
	 * wrapper occupies its natural space, then sets the Add to Cart button
	 * width to fill that remaining space — but never narrower than the
	 * button's own text content width (i.e. text always on one line).
	 *
	 * If the available width is smaller than the minimum text width the
	 * adjustment is skipped entirely (requirement 2.1.8).
	 *
	 * Debounced: rapid resize events share a single 100 ms timer.
	 */
	function adjustFormRowWidth() {
		if ( ! autowidthRow ) {
			return;
		}

		var row = document.querySelector( '.epb-aim-form-row' );
		if ( ! row ) {
			return;
		}

		var btn = row.querySelector( '.single_add_to_cart_button' );
		if ( ! btn ) {
			return;
		}

		var qtyWrapper = row.querySelector( '.epb-aim-qty-wrapper' );
		if ( ! qtyWrapper ) {
			return;
		}

		// Reset any previously applied styles so measurements are fresh.
		btn.style.width     = '';
		row.style.flexWrap  = '';

		// Measure the minimum button width using an off-screen clone so
		// there is no visual flash during the measurement.
		var clone = btn.cloneNode( true );
		clone.style.cssText = 'position:absolute;visibility:hidden;width:auto;' +
			'white-space:nowrap;left:-9999px;top:-9999px;';
		document.body.appendChild( clone );
		var minBtnWidth = clone.getBoundingClientRect().width;
		document.body.removeChild( clone );

		// Available space = row width minus qty wrapper width minus the gap (8px).
		var rowWidth  = row.getBoundingClientRect().width;
		var qtyWidth  = qtyWrapper.getBoundingClientRect().width;
		var gap       = 8; // matches gap in .epb-aim-form-row CSS
		var available = Math.floor( rowWidth - qtyWidth - gap );

		if ( available < Math.ceil( minBtnWidth ) ) {
			// Even at minimum text width the row would still wrap — do not apply.
			log( 'Feature 11: skip — available(' + available + ') < min(' + Math.round( minBtnWidth ) + ')' );
			return;
		}

		// Set button to fill remaining space; lock row to prevent sub-pixel wrapping.
		btn.style.width    = available + 'px';
		row.style.flexWrap = 'nowrap';
		log( 'Feature 11: button width → ' + available + 'px (min=' + Math.round( minBtnWidth ) + ' row=' + Math.round( rowWidth ) + ' qty=' + Math.round( qtyWidth ) + ')' );
	}

	function scheduleAdjustFormRow() {
		clearTimeout( adjustFormRowTimer );
		adjustFormRowTimer = setTimeout( adjustFormRowWidth, 100 );
	}

	// ── Combined recalculate ──────────────────────────────────────────────────

	function recalculate() {
		recalculateQtyLinks();
		recalculateMaxLinks();
		enforceImageSwap();
	}

	// ── Enforcement interval ──────────────────────────────────────────────────

	// Runs every 50 ms. Keeps DOM in sync after React re-renders reset values.
	setInterval( function () {
		enforceDesiredQtys();
		enforceMaxes();
		enforceShowOverrides();
		enforceMessages();
		enforceNoSelectionMessage();
		enforceTitle();
		updateQtyTotalRows();
		enforceImageSwap();
		injectQtyPlusMinus();
		enforcePositionSwap();
	}, 50 );

	// ── Debounce helper ───────────────────────────────────────────────────────

	var recalcTimer = null;
	function scheduleRecalc( delay ) {
		if ( recalcTimer ) {
			clearTimeout( recalcTimer );
		}
		recalcTimer = setTimeout( recalculate, delay !== undefined ? delay : 50 );
	}

	// ── Scope ─────────────────────────────────────────────────────────────────
	// The bundle widget is rendered by React AFTER this script executes, so
	// any CSS-class-based querySelector would return null at this point and
	// fall through to form.cart.  The bundle is also typically inserted
	// BEFORE form.cart (position = before_css_selector), so form.cart does
	// not contain the bundle items.  Use document.body so the MutationObserver
	// and click listener cover the entire page.

	var scope = document.body;

	log( 'Scope: document.body (full-page observation)' );

	// ── MutationObserver ──────────────────────────────────────────────────────

	var observer = new MutationObserver( function ( mutations ) {
		var relevant = false;

		for ( var i = 0; i < mutations.length; i++ ) {
			var m = mutations[ i ];

			if ( m.type === 'attributes' && m.attributeName === 'class' ) {
				var el  = m.target;
				var now = el.classList && el.classList.contains( 'asnp-disable-product' );
				var was = m.oldValue && m.oldValue.split( ' ' ).indexOf( 'asnp-disable-product' ) !== -1;
				if ( now !== was ) {
					log( 'MutationObserver: asnp-disable-product toggled on', el.className );
					relevant = true;
					break;
				}
			} else if ( m.type === 'childList' ) {
				var nodes = Array.prototype.slice.call( m.addedNodes ).concat(
					Array.prototype.slice.call( m.removedNodes )
				);
				for ( var j = 0; j < nodes.length; j++ ) {
					var node = nodes[ j ];
					if ( node.nodeType !== 1 ) {
						continue;
					}
					if (
						( node.name && node.name.indexOf( 'asnp_wepb_bundle' ) !== -1 ) ||
						( node.querySelector && node.querySelector( 'input[name*="asnp_wepb_bundle"]' ) )
					) {
						log( 'MutationObserver: childList change involving bundle input nodes' );
						relevant = true;
						break;
					}
					if ( node.className && typeof node.className === 'string' &&
					     node.className.indexOf( 'asnp-' ) !== -1 ) {
						log( 'MutationObserver: asnp- element added/removed:', node.className.split( ' ' )[ 0 ] );
						relevant = true;
						break;
					}
				}
				if ( relevant ) {
					break;
				}
			}
		}

		if ( relevant ) {
			// Use 10 ms — short enough to feel instant, long enough to batch
			// the burst of mutations React emits in a single frame.
			scheduleRecalc( 10 );
		}
	} );

	observer.observe( scope, {
		subtree          : true,
		attributes       : true,
		attributeFilter  : [ 'class' ],
		attributeOldValue: true,
		childList        : true,
	} );

	// ── Event listeners ───────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		if ( scope !== document.body && ! scope.contains( e.target ) ) {
			return;
		}
		if ( e.target && e.target.matches &&
		     e.target.matches( 'input[name*="asnp_wepb_bundle"][name*="quantity"]' ) ) {
			return;
		}
		log( 'Click — scheduling recalculate' );
		scheduleRecalc( 50 );
	}, false );

	document.addEventListener( 'input', function ( e ) {
		if (
			e.target &&
			e.target.matches &&
			e.target.matches( 'input[name*="asnp_wepb_bundle"][name*="quantity"]' )
		) {
			log( 'Input event on bundle qty input — scheduling recalculate' );
			scheduleRecalc( 0 );
		}
	}, false );

	document.addEventListener( 'asnpWepbPriceChanged', function () {
		log( 'asnpWepbPriceChanged — scheduling recalculate' );
		scheduleRecalc( 10 );
	}, false );

	// ── Feature 11 resize listener ────────────────────────────────────────────
	if ( autowidthRow ) {
		window.addEventListener( 'resize', scheduleAdjustFormRow, false );
	}

	// ── Pre-submit hook ───────────────────────────────────────────────────────

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form ) {
			return;
		}
		var hasBundleInput = form.querySelector( 'input[name*="asnp_wepb_bundle"]' );
		if ( ! hasBundleInput ) {
			return;
		}
		log( 'Pre-submit: running synchronous recalculate before form POST' );
		recalculate();
	}, true );

	// ── Polling fallback ──────────────────────────────────────────────────────

	var pollCount = 0;
	var maxPolls  = 20;
	var pollTimer = setInterval( function () {
		pollCount++;
		log( 'Poll #' + pollCount );
		recalculate();
		enforceShowOverrides();
		if ( pollCount >= maxPolls ) {
			clearInterval( pollTimer );
			log( 'Polling stopped after ' + maxPolls + ' iterations' );
		}
	}, 500 );

	// ── Initial runs ─────────────────────────────────────────────────────────

	setTimeout( recalculate, 300 );
	setTimeout( recalculate, 800 );
	setTimeout( recalculate, 1500 );
	// Extra early run for show-overrides (the CSS is applied immediately but
	// React renders asynchronously — run as soon as the DOM is likely ready).
	setTimeout( enforceShowOverrides, 100 );
	setTimeout( enforceShowOverrides, 500 );
	// Bundle qty label injection — run early and again after async renders.
	// injectBundleQtyLabel() is idempotent so repeated calls are safe.
	injectBundleQtyLabel();
	setTimeout( injectBundleQtyLabel, 300 );
	setTimeout( injectBundleQtyLabel, 800 );
	// Extra early runs for no-selection message (React renders the alert async).
	setTimeout( enforceNoSelectionMessage, 300 );
	setTimeout( enforceNoSelectionMessage, 800 );
	// Image swap — run after React renders the bundle grid items.
	setTimeout( enforceImageSwap, 300 );
	setTimeout( enforceImageSwap, 800 );
	setTimeout( enforceImageSwap, 1500 );
	// Bundle qty +/- injection — idempotent; run early and after async renders.
	injectQtyPlusMinus();
	setTimeout( injectQtyPlusMinus, 300 );
	setTimeout( injectQtyPlusMinus, 800 );
	// Position swap — run after React renders both elements.
	enforcePositionSwap();
	setTimeout( enforcePositionSwap, 300 );
	setTimeout( enforcePositionSwap, 800 );
	// Auto-width row — measure after React renders epb-aim-form-row.
	adjustFormRowWidth();
	setTimeout( adjustFormRowWidth, 300 );
	setTimeout( adjustFormRowWidth, 800 );

}() );
