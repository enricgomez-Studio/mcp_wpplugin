<?php
/**
 * Tipología «WooCommerce»: diagnóstico de pedidos, solo lectura y sin datos personales.
 *   POST /wp-json/eg/v1/orders
 * Devuelve los pedidos recientes con estado, fecha, total, pasarela, ID de transacción, productos, notas del pedido
 * y los campos de la pasarela (p. ej. códigos de error de PAYCOMET o Redsys). No devuelve nombre, email, teléfono,
 * dirección ni IP del cliente, y en notas y registros se tapan emails y números de tarjeta.
 *   status   ["failed"] (por defecto) o cualquier estado de WooCommerce sin «wc-»; "any" para todos.
 *   ids      [123, 124] pedidos concretos (ignora status y days).
 *   days     antigüedad máxima en días (por defecto 7, máx. 90).   limit  máx. pedidos (por defecto 10, máx. 50).
 *   logs     {"source": "paytpv", "lines": 200}: últimas líneas de los registros de WooCommerce cuyo nombre
 *            contiene source (también fatal-errors), para ver qué respondió la pasarela.
 * También lista las pasarelas de pago activas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['pedidos'] = array( 'type' => 'woocommerce', 'routes' => array( 'orders' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'pedidos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/orders', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_orders_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_woocommerce' ),
	) );
} );

/** Tapa emails y números de tarjeta. */
function eg_pub_orders_mask( $text ) {
	$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', (string) $text );
	return preg_replace( '/\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{1,7}\b/', '[tarjeta]', $text );
}

/** Campos de la pasarela guardados en el pedido (nombres que suelen llevar respuesta o error). */
function eg_pub_orders_gateway_meta( WC_Order $order ) {
	$out = array();
	foreach ( $order->get_meta_data() as $meta ) {
		$key = (string) $meta->key;
		// Fuera tokens e identificadores de tarjeta o de usuario en la pasarela: no hacen falta para diagnosticar.
		if ( ! preg_match( '/paytpv|paycomet|redsys|ds_|stripe|paypal|bizum|error|response|result|status|auth|transaction/i', $key ) || preg_match( '/token|iduser|id_user|card|pan|secret/i', $key ) ) {
			continue;
		}
		$value       = is_scalar( $meta->value ) ? (string) $meta->value : wp_json_encode( $meta->value );
		$out[ $key ] = eg_pub_orders_mask( strlen( $value ) > 500 ? substr( $value, 0, 500 ) . '…' : $value );
	}
	return $out;
}

function eg_pub_orders_state( WC_Order $order ) {
	$items = array();
	foreach ( $order->get_items() as $item ) {
		$items[] = array( 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'total' => $item->get_total() );
	}
	$notes = array();
	foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'order' => 'ASC' ) ) as $note ) {
		$notes[] = array(
			'date'     => $note->date_created ? $note->date_created->date( 'Y-m-d H:i:s' ) : '',
			'by'       => 'system' === $note->added_by ? 'system' : 'usuario',
			'customer' => (bool) $note->customer_note,
			'text'     => eg_pub_orders_mask( wp_strip_all_tags( $note->content ) ),
		);
	}
	$created = $order->get_date_created();
	$paid    = $order->get_date_paid();
	return array(
		'id'             => $order->get_id(),
		'number'         => $order->get_order_number(),
		'status'         => $order->get_status(),
		'created'        => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
		'paid'           => $paid ? $paid->date( 'Y-m-d H:i:s' ) : '',
		'total'          => $order->get_total(),
		'currency'       => $order->get_currency(),
		'payment_method' => $order->get_payment_method(),
		'payment_title'  => $order->get_payment_method_title(),
		'transaction_id' => $order->get_transaction_id(),
		'created_via'    => $order->get_created_via(),
		'shipping'       => $order->get_shipping_method(),
		'country'        => $order->get_billing_country(),
		'customer_user'  => $order->get_customer_id() ? 'registrado' : 'invitado',
		'items'          => $items,
		'notes'          => $notes,
		'gateway_meta'   => eg_pub_orders_gateway_meta( $order ),
	);
}

/** Últimas líneas de los registros de WooCommerce cuyo nombre contiene $source. */
function eg_pub_orders_logs( $source, $lines ) {
	$dir = defined( 'WC_LOG_DIR' ) ? WC_LOG_DIR : trailingslashit( wp_upload_dir()['basedir'] ) . 'wc-logs/';
	$out = array();
	foreach ( array_unique( array_filter( array( sanitize_file_name( $source ), 'fatal-errors' ) ) ) as $src ) {
		$files = glob( trailingslashit( $dir ) . '*' . $src . '*.log' ) ?: array();
		usort( $files, function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );
		foreach ( array_slice( $files, 0, 3 ) as $file ) {
			$rows                      = file( $file, FILE_IGNORE_NEW_LINES ) ?: array();
			$out[ basename( $file ) ] = array_map( 'eg_pub_orders_mask', array_slice( $rows, -$lines ) );
		}
	}
	global $wpdb;
	$table = $wpdb->prefix . 'woocommerce_log';
	if ( $source && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT timestamp, level, source, message FROM {$table} WHERE source LIKE %s ORDER BY log_id DESC LIMIT %d", '%' . $wpdb->esc_like( $source ) . '%', $lines ), ARRAY_A );
		if ( $rows ) {
			$out['db:woocommerce_log'] = array_map( function ( $r ) {
				return eg_pub_orders_mask( $r['timestamp'] . ' ' . $r['level'] . ' ' . $r['source'] . ' ' . $r['message'] );
			}, $rows );
		}
	}
	return $out;
}

function eg_pub_orders_handle( WP_REST_Request $r ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return eg_pub_error( 'eg_wc', 'WooCommerce no está activo en esta web.' );
	}
	$p     = $r->get_params();
	$limit = min( 50, max( 1, (int) ( $p['limit'] ?? 10 ) ) );
	if ( ! empty( $p['ids'] ) ) {
		$orders = array_filter( array_map( 'wc_get_order', array_map( 'absint', (array) $p['ids'] ) ) );
	} else {
		$status = (array) ( $p['status'] ?? array( 'failed' ) );
		$days   = min( 90, max( 1, (int) ( $p['days'] ?? 7 ) ) );
		$orders = wc_get_orders( array(
			'status'       => in_array( 'any', $status, true ) ? array_keys( wc_get_order_statuses() ) : array_map( 'sanitize_key', $status ),
			'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
			'limit'        => $limit,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'type'         => 'shop_order',
		) );
	}

	$gateways = array();
	foreach ( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() as $id => $gw ) {
		if ( 'yes' === $gw->enabled ) {
			$gateways[ $id ] = array( 'title' => $gw->get_title(), 'test_mode' => in_array( $gw->get_option( 'testmode', $gw->get_option( 'environment', '' ) ), array( 'yes', 'test', '1', 'sandbox' ), true ) );
		}
	}

	$out = array(
		'ok'       => true,
		'count'    => count( $orders ),
		'orders'   => array_values( array_map( 'eg_pub_orders_state', $orders ) ),
		'gateways' => $gateways,
	);
	if ( ! empty( $p['logs'] ) ) {
		$out['logs'] = eg_pub_orders_logs( (string) ( $p['logs']['source'] ?? '' ), min( 1000, max( 10, (int) ( $p['logs']['lines'] ?? 200 ) ) ) );
	}
	return new WP_REST_Response( $out, 200 );
}
