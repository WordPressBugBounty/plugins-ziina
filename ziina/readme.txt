=== Ziina ===
Contributors: onepix
Tags: ziina, payment, gateway, woocommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.2.17
License: GPL-2.0-or-later
Plugin URI: https://ziina.com
Documentation: https://docs.ziina.com/woocommerce
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ziina accepts Apple Pay, Google Pay, Visa, and Mastercard.

== Description ==
Ziina (زينة) makes it easy for UAE businesses to begin collecting payments from their customers. Sign up for the app in just minutes, and get paid on your website, in your DMs, and face to face. Shoot out payment links on WhatsApp, Instagram, or wherever you chat with your customers. Anyone can scan your QR code to pay by card at markets, bazaars, popups, or on the go. And with Ziina’s mobile app, you can run your business wherever you are.
Documentation for this plugin can be found at https://docs.ziina.com/woocommerce

== Changelog ==
1.2.17 – Improved messaging for users who haven’t completed onboarding in the Ziina app
1.2.16 – Improved messaging when user set unsupported currency for store
1.2.15 – Assign `transaction_id` to orders and prevent users from submitting empty or zero refund amounts.
1.2.14 – Behind-the-scenes improvements only — just cleaning up some logs and keeping things tidy. No changes to your experience this time!
1.2.13 – We added more details on order details page: now "Ziina fees" are shown as part of the price breakdown and now there is a link to receipt for the payment. In addition, we implemented behind the scenes improvements: fixed potential crash when Ziina plugin is uninstalled, removed unnecessary webhook registration for localhost.
1.2.7-1.2.12 – Internal improvements to support system visibility and monitoring; no customer-facing changes — just tightening things behind the scenes
1.2.6 – Fixed a bug when order status in WooCommerce might not correspond to a payment status in the app. *Note:* this adds webhooks support for your store, so if you're using webhooks with your custom integration, they will conflict.
1.2.5 – Bug fixes
1.2.4 – Bug fixes
1.2.3 – Added refunds functionality 
1.2.2 - Declare High-Performance Order Storage compatibility
1.2.1 - New currencies support
1.2.0 - WooCommerce Blocks support
1.0.1 - Bug fixes
1.0.0 - Release
