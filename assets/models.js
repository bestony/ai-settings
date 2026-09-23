/**
 * Turns each model field into a dropdown of the models the selected provider exposes.
 *
 * Progressive enhancement: the field is rendered as a plain text input, which stays the only
 * posted value and keeps working untouched when this script does not run. The script adds a
 * nameless select next to it, hides the input while the select holds a value, and reveals the
 * input again for a model that is not in any list.
 */
( function () {
	'use strict';

	var catalog = window.aiSettingsModelCatalog || {};
	var i18n = window.aiSettingsModelI18n || {};

	var DEFAULT_LABEL = i18n.default || '(AI plugin default)';
	var CUSTOM_LABEL = i18n.custom || 'Custom…';

	/** The sentinel option that hands control back to the text input. */
	var CUSTOM = '__custom__';

	/** Provider values that mean "no single provider", so every provider is offered. */
	var EVERY_PROVIDER = [ '', '__keep__' ];

	/**
	 * Reads the providers of one catalog key.
	 *
	 * @param {string} key The capability, or the all-capabilities sentinel.
	 * @return {Array} The providers, each with an id, a name and its models.
	 */
	function providers( key ) {
		var group = catalog[ key ] || {};

		return Object.keys( group ).map( function ( id ) {
			return { id: id, name: group[ id ].name, models: group[ id ].models };
		} );
	}

	/**
	 * Builds an option element.
	 *
	 * @param {string} value   The option value.
	 * @param {string} label   The option label.
	 * @param {string} provider The provider the model belongs to, when it is a model.
	 * @return {HTMLOptionElement} The option.
	 */
	function option( value, label, provider ) {
		var element = document.createElement( 'option' );

		element.value = value;
		element.textContent = label;

		if ( provider ) {
			element.setAttribute( 'data-provider', provider );
		}

		return element;
	}

	/**
	 * Rebuilds a picker for one provider.
	 *
	 * @param {HTMLSelectElement} select     The picker.
	 * @param {string}            key        The catalog key.
	 * @param {string}            providerId The selected provider.
	 * @param {string}            value      The model that should stay selected.
	 * @return {boolean} Whether the model is among the options.
	 */
	function fill( select, key, providerId, value ) {
		select.textContent = '';
		select.appendChild( option( '', DEFAULT_LABEL ) );

		var all = providers( key );
		var shown = EVERY_PROVIDER.indexOf( providerId ) === -1
			? all.filter( function ( provider ) {
				return provider.id === providerId;
			} )
			: all;

		var found = value === '';

		shown.forEach( function ( provider ) {
			var ids = Object.keys( provider.models );

			if ( ! ids.length ) {
				return;
			}

			// Only group when more than one provider is on offer; a single provider needs no label.
			var parent = select;

			if ( shown.length > 1 ) {
				parent = document.createElement( 'optgroup' );
				parent.label = provider.name;
				select.appendChild( parent );
			}

			ids.forEach( function ( id ) {
				if ( id === value ) {
					found = true;
				}

				parent.appendChild( option( id, provider.models[ id ], provider.id ) );
			} );
		} );

		select.appendChild( option( CUSTOM, CUSTOM_LABEL ) );

		return found;
	}

	/**
	 * Adds the picker to one model field.
	 *
	 * @param {HTMLInputElement} input The text input that holds the value.
	 * @return {void}
	 */
	function enhance( input ) {
		var key = input.getAttribute( 'data-ai-settings-model' );

		if ( ! key || ! catalog[ key ] || ! Object.keys( catalog[ key ] ).length ) {
			return;
		}

		var container = input.closest( 'tr, form' );
		var providerSelect = container ? container.querySelector( '[data-ai-settings-provider]' ) : null;

		if ( ! providerSelect ) {
			return;
		}

		var select = document.createElement( 'select' );
		select.className = 'ai-settings-model-picker';
		input.parentNode.insertBefore( select, input );

		/**
		 * Shows or hides the free-text input.
		 *
		 * The picker stays visible either way, so its other options remain reachable.
		 *
		 * @param {boolean} custom Whether the value is being typed instead of picked.
		 * @return {void}
		 */
		function show( custom ) {
			input.hidden = ! custom;

			if ( custom ) {
				input.focus();
			}
		}

		/**
		 * Rebuilds the picker against the current provider.
		 *
		 * @param {boolean} keepUnknown Whether a model that is not listed should be kept as a
		 *                              custom value, or dropped back to the default.
		 * @return {void}
		 */
		function apply( keepUnknown ) {
			var value = input.value;

			if ( fill( select, key, providerSelect.value, value ) ) {
				select.value = value;
				show( false );

				return;
			}

			if ( keepUnknown && '' !== value ) {
				select.value = CUSTOM;
				show( true );

				return;
			}

			// The model belonged to another provider, so it cannot apply any more.
			input.value = '';
			select.value = '';
			show( false );
		}

		select.addEventListener( 'change', function () {
			if ( CUSTOM === select.value ) {
				show( true );

				return;
			}

			input.value = select.value;
			show( false );
		} );

		providerSelect.addEventListener( 'change', function () {
			apply( false );
		} );

		apply( true );
	}

	function ready() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-ai-settings-model]' ),
			enhance
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ready );
	} else {
		ready();
	}
} )();
