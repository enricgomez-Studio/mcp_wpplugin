<?php
/**
 * Tipología «Gestión de la web», módulo «scripts»: códigos de terceros (chat, píxeles, widgets) por clave,
 * en la cabecera, al abrir el body o en el pie, con historial y vuelta atrás.
 *   POST /wp-json/eg/v1/scripts
 * Sin parámetros lista los códigos guardados (clave, posición, activo, páginas, tamaño, fecha, versiones).
 *   key       clave del código (p. ej. "hijiffy"). Con solo key devuelve el código y su historial.
 *   code      HTML tal cual lo da el proveedor (<script>…</script>, <noscript>, <img>…). La versión anterior pasa al historial (10).
 *   position  "head" (por defecto), "body" (justo tras <body>, wp_body_open) o "footer".
 *   enabled   true/false. Un código nuevo nace apagado salvo que se diga lo contrario.
 *   pages     [ids] solo en esas páginas (con WPML, también en sus traducciones); [] = en toda la web.
 *   label     descripción corta.
 *   rollback  true o número de versiones atrás.  delete  true: borra el código y su historial.
 * Solo previsualiza; guarda con apply:true. Al guardar vacía la caché de WP Rocket.
 * Prueba sin publicar: ?eg_js=<clave> pinta ese código aunque esté apagado; ?eg_js=off quita todos en esa visita.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_SCRIPTS_OPT     = 'eg_pub_scripts';
const EG_PUB_SCRIPTS_HISTORY = 10;

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['scripts'] = array( 'type' => 'gestion', 'routes' => array( 'scripts' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'scripts' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/scripts', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_scripts_handle',
		'permission_callback' => eg_pub_module_permission( 'unfiltered_html' ),
	) );
} );

function eg_pub_scripts_all() {
	$all = get_option( EG_PUB_SCRIPTS_OPT, array() );
	return is_array( $all ) ? $all : array();
}

function eg_pub_scripts_summary( $key, $s ) {
	return array(
		'key'      => $key,
		'label'    => isset( $s['label'] ) ? $s['label'] : '',
		'position' => isset( $s['position'] ) ? $s['position'] : 'head',
		'enabled'  => ! empty( $s['enabled'] ),
		'pages'    => isset( $s['pages'] ) ? array_values( $s['pages'] ) : array(),
		'bytes'    => strlen( isset( $s['code'] ) ? $s['code'] : '' ),
		'updated'  => isset( $s['updated'] ) ? $s['updated'] : '',
		'versions' => isset( $s['history'] ) ? count( $s['history'] ) : 0,
		'test_url' => add_query_arg( 'eg_js', $key, home_url( '/' ) ),
	);
}

function eg_pub_scripts_handle( WP_REST_Request $r ) {
	$all   = eg_pub_scripts_all();
	$key   = sanitize_key( (string) $r->get_param( 'key' ) );
	$apply = (bool) $r->get_param( 'apply' );

	if ( '' === $key ) {
		$list = array();
		foreach ( $all as $k => $s ) {
			$list[] = eg_pub_scripts_summary( $k, $s );
		}
		return array( 'scripts' => $list );
	}
	if ( 'off' === $key ) {
		return new WP_Error( 'eg_scripts_key', 'La clave «off» está reservada.', array( 'status' => 400 ) );
	}

	$exists  = isset( $all[ $key ] );
	$item    = $exists ? $all[ $key ] : array( 'code' => '', 'position' => 'head', 'enabled' => false, 'pages' => array(), 'label' => '', 'history' => array() );
	$changed = array();
	$now     = current_time( 'mysql' );

	if ( $r->get_param( 'delete' ) ) {
		if ( ! $exists ) {
			return new WP_Error( 'eg_scripts_missing', 'No hay ningún código con esa clave.', array( 'status' => 404 ) );
		}
		if ( $apply ) {
			unset( $all[ $key ] );
			update_option( EG_PUB_SCRIPTS_OPT, $all, true );
			eg_pub_css_purge();
		}
		return array( 'key' => $key, 'deleted' => $apply, 'applied' => $apply );
	}

	$rollback = $r->get_param( 'rollback' );
	if ( $rollback ) {
		$steps = true === $rollback || ! is_numeric( $rollback ) ? 1 : max( 1, (int) $rollback );
		$hist  = isset( $item['history'] ) ? $item['history'] : array();
		if ( count( $hist ) < $steps ) {
			return new WP_Error( 'eg_scripts_history', 'No hay tantas versiones anteriores (hay ' . count( $hist ) . ').', array( 'status' => 400 ) );
		}
		$target = $hist[ $steps - 1 ];
		$rest   = array_merge(
			array( array( 'code' => $item['code'], 'date' => isset( $item['updated'] ) ? $item['updated'] : $now ) ),
			array_slice( $hist, 0, $steps - 1 ),
			array_slice( $hist, $steps )
		);
		$item['code']    = $target['code'];
		$item['history'] = array_slice( $rest, 0, EG_PUB_SCRIPTS_HISTORY );
		$item['updated'] = $now;
		$changed[]       = 'rollback';
	}

	if ( null !== $r->get_param( 'code' ) ) {
		$code = trim( (string) $r->get_param( 'code' ) );
		if ( $code !== $item['code'] ) {
			if ( '' !== $item['code'] ) {
				$hist = isset( $item['history'] ) ? $item['history'] : array();
				array_unshift( $hist, array( 'code' => $item['code'], 'date' => isset( $item['updated'] ) ? $item['updated'] : $now ) );
				$item['history'] = array_slice( $hist, 0, EG_PUB_SCRIPTS_HISTORY );
			}
			$item['code']    = $code;
			$item['updated'] = $now;
			$changed[]       = 'code';
		}
	}
	if ( null !== $r->get_param( 'position' ) ) {
		$pos = (string) $r->get_param( 'position' );
		if ( ! in_array( $pos, array( 'head', 'body', 'footer' ), true ) ) {
			return new WP_Error( 'eg_scripts_position', 'position debe ser head, body o footer.', array( 'status' => 400 ) );
		}
		$item['position'] = $pos;
		$changed[]        = 'position';
	}
	if ( null !== $r->get_param( 'enabled' ) ) {
		$item['enabled'] = rest_sanitize_boolean( $r->get_param( 'enabled' ) );
		$changed[]       = 'enabled';
	}
	if ( null !== $r->get_param( 'pages' ) ) {
		$item['pages'] = array_values( array_filter( array_map( 'absint', (array) $r->get_param( 'pages' ) ) ) );
		$changed[]     = 'pages';
	}
	if ( null !== $r->get_param( 'label' ) ) {
		$item['label'] = sanitize_text_field( (string) $r->get_param( 'label' ) );
		$changed[]     = 'label';
	}

	if ( ! $exists && ! $changed ) {
		return new WP_Error( 'eg_scripts_missing', 'No hay ningún código con esa clave.', array( 'status' => 404 ) );
	}

	if ( $apply && $changed ) {
		$all[ $key ] = $item;
		update_option( EG_PUB_SCRIPTS_OPT, $all, true );
		eg_pub_css_purge();
	}

	$out            = eg_pub_scripts_summary( $key, $item );
	$out['code']    = $item['code'];
	$out['history'] = array_map( function ( $h ) {
		return array( 'date' => $h['date'], 'bytes' => strlen( $h['code'] ) );
	}, isset( $item['history'] ) ? $item['history'] : array() );
	$out['changed'] = $changed;
	$out['applied'] = $apply && $changed;
	return $out;
}

/* Los códigos se pintan aunque el módulo se apague después: se quitan apagándolos o borrándolos. */
function eg_pub_scripts_print( $position ) {
	if ( is_admin() ) {
		return;
	}
	$all = eg_pub_scripts_all();
	if ( ! $all ) {
		return;
	}
	$test = isset( $_GET['eg_js'] ) ? sanitize_key( wp_unslash( $_GET['eg_js'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'off' === $test ) {
		return;
	}
	foreach ( $all as $key => $s ) {
		$pos = isset( $s['position'] ) ? $s['position'] : 'head';
		if ( empty( $s['code'] ) || $pos !== $position ) {
			continue;
		}
		// eg_pub_css_on_page sirve igual: mira $s['pages'].
		$show = $key === $test || ( ! empty( $s['enabled'] ) && eg_pub_css_on_page( $s ) );
		if ( $show ) {
			echo "\n<!-- eg-scripts:" . esc_attr( $key ) . " -->\n" . $s['code'] . "\n<!-- /eg-scripts:" . esc_attr( $key ) . " -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
add_action( 'wp_head', function () { eg_pub_scripts_print( 'head' ); }, 110 );
add_action( 'wp_body_open', function () { eg_pub_scripts_print( 'body' ); }, 5 );
add_action( 'wp_footer', function () { eg_pub_scripts_print( 'footer' ); }, 100 );
