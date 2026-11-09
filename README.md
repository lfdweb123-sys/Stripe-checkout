# Stripe Travel Globe Checkout

Passerelle de paiement WooCommerce minimaliste : redirection vers Stripe Checkout avec une description générique unique
(« Travel globe payment, travel fees »). Aucune donnée produit ou client du site n'est envoyée à Stripe.

## Installation
1. Copier `stripe-travel-globe-checkout.php` et `blocks.js` dans `wp-content/plugins/stripe-travel-globe-checkout/`.
2. Activer l'extension, puis WooCommerce > Réglages > Paiements > *Stripe Travel Globe Checkout*.
3. Renseigner les clés secrètes Stripe (test / live) et, de préférence, le secret du webhook.

Webhook Stripe : `https://VOTRE-SITE/?wc-api=stgc_webhook`
Événements : `checkout.session.completed`, `checkout.session.async_payment_succeeded`
