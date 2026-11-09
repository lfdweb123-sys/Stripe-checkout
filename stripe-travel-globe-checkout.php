<?php
/**
 * Plugin Name: Stripe Travel Globe Checkout
 * Description: Passerelle WooCommerce minimaliste : redirection vers Stripe Checkout avec une description générique unique (aucune donnée produit/client envoyée).
 * Version: 1.0.2
 * Requires Plugins: woocommerce
 * Text Domain: stripe-travel-globe-checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'STGC_VERSION', '1.0.2' );

function stgc_debug_on() {
	$s = get_option( 'woocommerce_stripe_travel_globe_settings', array() );
	return is_array( $s ) && isset( $s['debug'] ) && 'yes' === $s['debug'];
}

function stgc_log( $msg ) {
	if ( ! stgc_debug_on() ) {
		return;
	}
	$log = get_option( 'stgc_debug_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[] = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $msg;
	update_option( 'stgc_debug_log', array_slice( $log, -300 ), false );
}

function stgc_shutdown_log() {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
		stgc_log( 'FATAL: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] );
	}
}

// Compatibilité HPOS + checkout par blocs.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
	$gateways[] = 'STGC_Gateway';
	return $gateways;
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class STGC_Gateway extends WC_Payment_Gateway {

		const ZERO_DECIMAL  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		const THREE_DECIMAL = array( 'BHD', 'JOD', 'KWD', 'OMR', 'TND' );

		public function __construct() {
			$this->id                 = 'stripe_travel_globe';
			$this->method_title       = 'Stripe Travel Globe Checkout';
			$this->method_description = 'Redirige le client vers Stripe Checkout. Seul le montant et une description générique sont envoyés à Stripe.';
			$this->has_fields         = false;
			$this->supports           = array( 'products' );

			$this->init_form_fields();
			$this->init_settings();

			$this->title       = $this->get_option( 'title' );
			$this->description = $this->get_option( 'description' );
			$this->enabled     = $this->get_option( 'enabled' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_api_stgc_return', array( $this, 'handle_return' ) );
			add_action( 'woocommerce_api_stgc_webhook', array( $this, 'handle_webhook' ) );
		}

		public function init_form_fields() {
			$this->form_fields = array(
				'enabled'      => array( 'title' => 'Activer', 'type' => 'checkbox', 'label' => 'Activer ce moyen de paiement', 'default' => 'no' ),
				'title'        => array( 'title' => 'Titre (checkout)', 'type' => 'text', 'default' => 'Carte bancaire (Stripe)' ),
				'description'  => array( 'title' => 'Description (checkout)', 'type' => 'textarea', 'default' => 'Vous serez redirigé vers Stripe pour payer en toute sécurité.' ),
				'testmode'     => array( 'title' => 'Mode test', 'type' => 'checkbox', 'label' => 'Utiliser les clés de test', 'default' => 'yes' ),
				'test_secret'  => array( 'title' => 'Clé secrète de test', 'type' => 'password', 'description' => 'sk_test_… (Dashboard Stripe > Développeurs > Clés API)' ),
				'live_secret'  => array( 'title' => 'Clé secrète live', 'type' => 'password', 'description' => 'sk_live_…' ),
				'webhook_secret' => array(
					'title'       => 'Secret du webhook (recommandé)',
					'type'        => 'password',
					'description' => 'whsec_… — Endpoint à créer dans Stripe : ' . esc_url( home_url( '/?wc-api=stgc_webhook' ) ) . ' (événements : checkout.session.completed, checkout.session.async_payment_succeeded).',
				),
				'pay_name'     => array( 'title' => 'Nom affiché sur Stripe', 'type' => 'text', 'default' => 'Travel globe payment' ),
				'pay_desc'     => array( 'title' => 'Description affichée sur Stripe', 'type' => 'text', 'default' => 'Travel fees' ),
				'debug'        => array( 'title' => 'Débogage', 'type' => 'checkbox', 'label' => 'Activer le journal de débogage (à désactiver en production)', 'default' => 'no' ),
				'clear_log'    => array( 'title' => 'Vider le journal', 'type' => 'checkbox', 'label' => 'Vider le journal à l’enregistrement', 'default' => 'no' ),
				'debug_view'   => array( 'type' => 'debug_view' ),
				'pay_full'     => array( 'title' => 'Description du paiement (dashboard Stripe)', 'type' => 'text', 'default' => 'Travel globe payment, travel fees' ),
			);
		}

		public function generate_debug_view_html( $key, $data ) {
			$log  = get_option( 'stgc_debug_log', array() );
			$text = ( is_array( $log ) && $log ) ? implode( "\n", array_reverse( $log ) ) : 'Journal vide.';
			ob_start();
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><label>Journal de débogage</label></th>
				<td class="forminp">
					<textarea readonly rows="18" style="width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea( $text ); ?></textarea>
					<p class="description">Le plus récent est en haut. Faites un test, puis rechargez cette page.</p>
				</td>
			</tr>
			<?php
			return ob_get_clean();
		}

		public function process_admin_options() {
			$saved = parent::process_admin_options();
			if ( 'yes' === $this->get_option( 'clear_log' ) ) {
				delete_option( 'stgc_debug_log' );
				$this->update_option( 'clear_log', 'no' );
			}
			return $saved;
		}

		private function secret_key() {
			return trim( 'yes' === $this->get_option( 'testmode' ) ? $this->get_option( 'test_secret' ) : $this->get_option( 'live_secret' ) );
		}

		public function is_available() {
			return 'yes' === $this->enabled && '' !== $this->secret_key();
		}

		private function api( $method, $path, $body = null ) {
			$args = array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->secret_key() ),
			);
			if ( null !== $body ) {
				$args['body'] = $body;
			}
			$res = wp_remote_request( 'https://api.stripe.com' . $path, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( wp_remote_retrieve_response_code( $res ) >= 400 || ! is_array( $data ) ) {
				$msg = $data['error']['message'] ?? 'Stripe API error';
				return new WP_Error( 'stgc_api', $msg );
			}
			return $data;
		}

		private function to_minor( $amount, $currency ) {
			$currency = strtoupper( $currency );
			if ( in_array( $currency, self::ZERO_DECIMAL, true ) ) {
				return (int) round( $amount );
			}
			if ( in_array( $currency, self::THREE_DECIMAL, true ) ) {
				return (int) ( round( $amount * 100 ) * 10 );
			}
			return (int) round( $amount * 100 );
		}

		public function process_payment( $order_id ) {
			$order    = wc_get_order( $order_id );
			$currency = strtolower( $order->get_currency() );
			$amount   = $this->to_minor( (float) $order->get_total(), $currency );

			$return = home_url( '/?wc-api=stgc_return&order_id=' . $order->get_id() . '&key=' . rawurlencode( $order->get_order_key() ) . '&session_id={CHECKOUT_SESSION_ID}' );

			stgc_log( 'PAY: order ' . $order->get_id() . ' amount=' . $amount . ' currency=' . $currency . ' mode=' . ( 'yes' === $this->get_option( 'testmode' ) ? 'test' : 'live' ) );
			$session = $this->api( 'POST', '/v1/checkout/sessions', array(
				'mode'                => 'payment',
				'client_reference_id' => (string) $order->get_id(),
				'success_url'         => $return,
				'cancel_url'          => wc_get_checkout_url(),
				'line_items'          => array(
					array(
						'quantity'   => 1,
						'price_data' => array(
							'currency'     => $currency,
							'unit_amount'  => $amount,
							'product_data' => array(
								'name'        => $this->get_option( 'pay_name' ) ?: 'Travel globe payment',
								'description' => $this->get_option( 'pay_desc' ) ?: 'Travel fees',
							),
						),
					),
				),
				'payment_intent_data' => array(
					'description' => $this->get_option( 'pay_full' ) ?: 'Travel globe payment, travel fees',
				),
			) );

			if ( is_wp_error( $session ) || empty( $session['url'] ) ) {
				wc_add_notice( 'Le paiement n’a pas pu être initialisé. Veuillez réessayer.', 'error' );
				stgc_log( 'PAY: ERROR ' . ( is_wp_error( $session ) ? $session->get_error_message() : 'no session url' ) );
				if ( is_wp_error( $session ) ) {
					wc_get_logger()->error( $session->get_error_message(), array( 'source' => 'stgc' ) );
				}
				return array( 'result' => 'failure' );
			}

			stgc_log( 'PAY: session created ' . $session['id'] );
			$order->update_meta_data( '_stgc_session_id', $session['id'] );
			$order->add_order_note( 'Redirection vers Stripe Checkout (session ' . $session['id'] . ').' );
			$order->save();

			return array( 'result' => 'success', 'redirect' => $session['url'] );
		}

		/** Valide un paiement à partir d'une session Stripe récupérée côté serveur. */
		private function complete_from_session( $session ) {
			$order = wc_get_order( absint( $session['client_reference_id'] ?? 0 ) );
			if ( ! $order || $order->get_payment_method() !== $this->id ) {
				return false;
			}
			if ( $order->is_paid() ) {
				return $order;
			}
			if ( $order->get_meta( '_stgc_session_id' ) !== ( $session['id'] ?? '' ) ) {
				stgc_log( 'COMPLETE: session id mismatch (order has ' . $order->get_meta( '_stgc_session_id' ) . ')' );
				return false;
			}
			if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
				stgc_log( 'COMPLETE: payment_status=' . ( $session['payment_status'] ?? '?' ) );
				return false;
			}
			$currency = $order->get_currency();
			if ( strtolower( $currency ) !== ( $session['currency'] ?? '' ) || $this->to_minor( (float) $order->get_total(), $currency ) !== (int) ( $session['amount_total'] ?? -1 ) ) {
				$order->add_order_note( 'Stripe : montant ou devise différent, paiement non validé.' );
				return false;
			}
			stgc_log( 'COMPLETE: calling payment_complete on order ' . $order->get_id() );
			$order->payment_complete( is_string( $session['payment_intent'] ?? null ) ? $session['payment_intent'] : '' );
			stgc_log( 'COMPLETE: payment_complete done' );
			$order->add_order_note( 'Paiement Stripe confirmé.' );
			return $order;
		}

		public function handle_return() {
			register_shutdown_function( 'stgc_shutdown_log' );
			$order_id   = absint( $_GET['order_id'] ?? 0 );
			$key        = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
			$session_id = sanitize_text_field( wp_unslash( $_GET['session_id'] ?? '' ) );
			stgc_log( 'RETURN: order_id=' . $order_id . ' session_id=' . $session_id );
			$order = null;

			try {
				$order = wc_get_order( $order_id );
				if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $key ) || ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ) {
					stgc_log( 'RETURN: commande, clé ou session invalide' );
					wp_safe_redirect( wc_get_checkout_url() );
					exit;
				}
				stgc_log( 'RETURN: statut commande=' . $order->get_status() . ' moyen=' . $order->get_payment_method() );

				$session = $this->api( 'GET', '/v1/checkout/sessions/' . $session_id );
				if ( is_wp_error( $session ) ) {
					stgc_log( 'RETURN: erreur API Stripe : ' . $session->get_error_message() );
				} else {
					stgc_log( 'RETURN: payment_status=' . ( $session['payment_status'] ?? '?' ) . ' amount_total=' . ( $session['amount_total'] ?? '?' ) . ' currency=' . ( $session['currency'] ?? '?' ) );
					if ( $this->complete_from_session( $session ) ) {
						stgc_log( 'RETURN: commande validée, vidage du panier' );
						if ( WC()->cart ) {
							WC()->cart->empty_cart();
						}
						stgc_log( 'RETURN: redirection vers la page de remerciement' );
						wp_safe_redirect( $order->get_checkout_order_received_url() );
						exit;
					}
				}
			} catch ( \Throwable $e ) {
				stgc_log( 'EXCEPTION: ' . get_class( $e ) . ' : ' . $e->getMessage() . ' dans ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() );
				// Le paiement est peut-être déjà validé : on envoie quand même le client sur la page de remerciement.
				if ( $order && $order->is_paid() ) {
					wp_safe_redirect( $order->get_checkout_order_received_url() );
					exit;
				}
			}

			wc_add_notice( 'Paiement non confirmé. Si vous avez été débité, contactez-nous.', 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		public function handle_webhook() {
			register_shutdown_function( 'stgc_shutdown_log' );
			$payload = file_get_contents( 'php://input' );
			$secret  = trim( $this->get_option( 'webhook_secret' ) );
			$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
			stgc_log( 'WEBHOOK: reçu (' . strlen( $payload ) . ' octets)' );

			if ( '' === $secret || '' === $sig ) {
				stgc_log( 'WEBHOOK: secret ou signature manquant' );
				status_header( 400 );
				exit;
			}
			$t = ''; $v1 = array();
			foreach ( explode( ',', $sig ) as $part ) {
				$kv = explode( '=', trim( $part ), 2 );
				if ( 2 === count( $kv ) ) {
					if ( 't' === $kv[0] ) { $t = $kv[1]; }
					if ( 'v1' === $kv[0] ) { $v1[] = $kv[1]; }
				}
			}
			$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
			$ok       = false;
			foreach ( $v1 as $candidate ) {
				$ok = $ok || hash_equals( $expected, $candidate );
			}
			if ( ! $ok || abs( time() - (int) $t ) > 300 ) {
				stgc_log( 'WEBHOOK: signature invalide (mauvais secret whsec_ ?)' );
				status_header( 400 );
				exit;
			}

			try {
				$event = json_decode( $payload, true );
				stgc_log( 'WEBHOOK: événement ' . ( $event['type'] ?? '?' ) );
				if ( in_array( $event['type'] ?? '', array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) ) {
					$this->complete_from_session( $event['data']['object'] ?? array() );
				}
			} catch ( \Throwable $e ) {
				stgc_log( 'EXCEPTION webhook: ' . $e->getMessage() . ' dans ' . $e->getFile() . ':' . $e->getLine() );
			}
			status_header( 200 );
			exit;
		}
	}
}, 11 );

// Support du checkout par blocs.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	class STGC_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
		protected $name = 'stripe_travel_globe';

		public function initialize() {
			$this->settings = get_option( 'woocommerce_stripe_travel_globe_settings', array() );
		}
		public function is_active() {
			return 'yes' === ( $this->settings['enabled'] ?? 'no' );
		}
		public function get_payment_method_script_handles() {
			wp_register_script( 'stgc-blocks', plugins_url( 'blocks.js', __FILE__ ), array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ), STGC_VERSION, true );
			return array( 'stgc-blocks' );
		}
		public function get_payment_method_data() {
			return array(
				'title'       => $this->settings['title'] ?? '',
				'description' => $this->settings['description'] ?? '',
				'supports'    => array( 'products' ),
			);
		}
	}
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new STGC_Blocks() );
	} );
} );
