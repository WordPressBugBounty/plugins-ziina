(function () {
	'use strict';

	const config = window.ziinaEmbeddedCheckout;

	if (!config) {
		return;
	}

	if (config.orderPaid && config.thankYouUrl) {
		window.location.replace(config.thankYouUrl);
		return;
	}

	const leftColumn = document.querySelector('.ziina-embedded-pay-layout__order_summary');
	const paymentBlock = document.getElementById('payment');

	// Move privacy block into the left column so it stacks directly under the order table.
	if (leftColumn && paymentBlock && paymentBlock.parentElement !== leftColumn) {
		leftColumn.appendChild(paymentBlock);
	}

	const iframe = document.getElementById('ziina-checkout');
	const noticeEl = document.getElementById('ziina-embedded-checkout-notice');

	if (!iframe) {
		return;
	}

	let redirecting = false;

	const showNotice = (message) => {
		if (!noticeEl) {
			return;
		}
		noticeEl.textContent = message;
		noticeEl.hidden = false;
	};

	const redirectTo = (url) => {
		if (redirecting || !url) {
			return;
		}
		redirecting = true;
		window.location.href = url;
	};

	window.addEventListener('message', (event) => {
		if (!iframe.contentWindow || event.source !== iframe.contentWindow) {
			return;
		}

		const payload = event.data || {};
		const type = payload.type;
		const data = payload.data || {};

		if (type !== 'ZIINA_PAYMENT_STATUS_CHANGE') {
			return;
		}

		const status = data.status;

		if (status === 'COMPLETED') {
			redirectTo(config.successUrl || config.thankYouUrl);
			return;
		}

		if (status === 'FAILED') {
			showNotice(config.i18n && config.i18n.paymentFailed ? config.i18n.paymentFailed : 'Payment failed.');
			return;
		}

		if (status === 'CANCELED') {
			showNotice(
				config.i18n && config.i18n.paymentCanceled
					? config.i18n.paymentCanceled
					: 'Payment was canceled.'
			);
		}
	});
})();
