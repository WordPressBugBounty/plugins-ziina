(function () {
	'use strict';

	const CHECKOUT_MODE_EMBEDDED = 'embedded';

	function getCheckoutModeSelect() {
		return document.getElementById('woocommerce_ziina_checkout_mode');
	}

	function getApplePaySection() {
		const field = document.getElementById('woocommerce_ziina_embedded_prerequisites');
		return field ? field.closest('tr.ziina-apple-pay-embedded-row') : null;
	}

	function getEmbeddedLocaleRow() {
		const field = document.getElementById('woocommerce_ziina_embedded_locale');
		return field ? field.closest('tr.ziina-embedded-only-row') : null;
	}

	function toggleEmbeddedSections() {
		const select = getCheckoutModeSelect();

		if (!select) {
			return;
		}

		const show = select.value === CHECKOUT_MODE_EMBEDDED;
		const display = show ? 'table-row' : 'none';

		[getApplePaySection(), getEmbeddedLocaleRow()].forEach(function (section) {
			if (!section) {
				return;
			}

			section.classList.toggle('ziina-hidden', !show);
			section.style.display = display;
		});
	}

	function allowNavigationWithoutWarning() {
		// WooCommerce marks the settings form dirty on any input change and sets
		// window.onbeforeunload. Intentional action links should not show that prompt.
		window.onbeforeunload = null;
	}

	document.addEventListener('DOMContentLoaded', function () {
		const select = getCheckoutModeSelect();

		if (select) {
			toggleEmbeddedSections();
			select.addEventListener('change', toggleEmbeddedSections);
		}

		document.querySelectorAll('.ziina-navigate-without-warning').forEach(function (link) {
			link.addEventListener('click', allowNavigationWithoutWarning);
		});
	});
})();
