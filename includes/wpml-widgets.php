<?php
/**
 * Tipología «Gestión de la web», módulo «wpml-widgets»: que WPML muestre en su editor de traducción los textos
 * de widgets de Elementor que no reconoce (p. ej. las diapositivas de Elementor Pro), en vez de copiarlos en castellano.
 *   POST /wp-json/eg/v1/wpml-widgets
 * Sin parámetros: diagnóstico (versión de WPML, si cada widget conocido está registrado y con qué campos,
 * método de traducción por defecto y cuántas traducciones se editan a mano con Elementor).
 *   enable   ["slides"]  registra esos widgets en WPML con la definición de este plugin.
 *   disable  ["slides"]  deja de registrarlos (vuelve a lo que traiga WPML).
 * Solo previsualiza; guarda con apply:true.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_WPML_WIDGETS_OPT = 'eg_pub_wpml_widgets';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['wpml-widgets'] = array( 'type' => 'gestion', 'routes' => array( 'wpml-widgets' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'wpml-widgets' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/wpml-widgets', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_wpml_widgets_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Definiciones conocidas: widget de Elementor => campos sueltos y campos de cada elemento del repetidor. */
function eg_pub_wpml_widgets_known() {
	return array(
		'slides' => array(
			'items_field' => 'slides',
			'items'       => array(
				'heading'     => array( 'Diapositivas: título', 'LINE' ),
				'description' => array( 'Diapositivas: descripción', 'AREA' ),
				'button_text' => array( 'Diapositivas: texto del botón', 'LINE' ),
				'link'        => array( 'Diapositivas: enlace', 'LINK', 'url' ),
			),
		),
	);
}

function eg_pub_wpml_widgets_enabled() {
	$on = get_option( EG_PUB_WPML_WIDGETS_OPT, array() );
	return is_array( $on ) ? array_values( array_intersect( $on, array_keys( eg_pub_wpml_widgets_known() ) ) ) : array();
}

/** Clase de repetidor para WPML: se carga solo si WPML (con su integración de Elementor) está cargado. */
function eg_pub_wpml_widgets_define_class() {
	if ( ! class_exists( 'EG_Pub_WPML_Items_Module' ) && class_exists( 'WPML_Elementor_Module_With_Items' ) ) {
		require_once __DIR__ . '/wpml-widgets-clase.php';
	}
	return class_exists( 'EG_Pub_WPML_Items_Module' );
}

/** Lista plana de campos de get_fields() de WPML: "campo" o "campo:subcampo". */
function eg_pub_wpml_widgets_flat_fields( $fields ) {
	$out = array();
	foreach ( (array) $fields as $k => $v ) {
		$out[] = is_int( $k ) ? (string) $v : $k . ':' . implode( ',', (array) $v );
	}
	return $out;
}

add_filter( 'wpml_elementor_widgets_to_translate', function ( $widgets ) {
	$on = eg_pub_wpml_widgets_enabled();
	if ( ! $on || ! is_array( $widgets ) || ! eg_pub_wpml_widgets_define_class() ) {
		return $widgets;
	}
	foreach ( $on as $name ) {
		$widgets[ $name ] = array(
			'conditions'        => array( 'widgetType' => $name ),
			'fields'            => array(),
			'integration-class' => 'EG_Pub_WPML_Items_Module',
		);
	}
	return $widgets;
}, 99 );

/** Lo que WPML tiene registrado ahora mismo para cada widget conocido. */
function eg_pub_wpml_widgets_current() {
	$out = array();
	$all = null;
	if ( class_exists( 'WPML_Elementor_Translatable_Nodes' ) ) {
		try {
			$nodes = new WPML_Elementor_Translatable_Nodes();
			$m     = new ReflectionMethod( $nodes, 'initialize_nodes_to_translate' );
			$m->setAccessible( true );
			$m->invoke( $nodes );
			$p = new ReflectionProperty( $nodes, 'nodes_to_translate' );
			$p->setAccessible( true );
			$all = $p->getValue( $nodes );
		} catch ( Throwable $e ) {
			$all = null;
		}
	}
	if ( ! is_array( $all ) ) {
		$all = apply_filters( 'wpml_elementor_widgets_to_translate', array() );
	}
	foreach ( array_keys( eg_pub_wpml_widgets_known() ) as $name ) {
		if ( ! isset( $all[ $name ] ) ) {
			$out[ $name ] = array( 'registered' => false );
			continue;
		}
		$c    = $all[ $name ];
		$cls  = isset( $c['integration-class'] ) ? $c['integration-class'] : '';
		$cls  = is_array( $cls ) ? $cls : ( $cls ? array( $cls ) : array() );
		$item = array();
		foreach ( $cls as $one ) {
			if ( is_string( $one ) && class_exists( $one ) && method_exists( $one, 'get_fields' ) ) {
				try {
					$item = array_merge( $item, eg_pub_wpml_widgets_flat_fields( ( new $one() )->get_fields() ) );
				} catch ( Throwable $e ) {
					$item[] = '?';
				}
			}
		}
		$out[ $name ] = array(
			'registered'  => true,
			'fields'      => isset( $c['fields'] ) ? array_values( array_map( function ( $f ) {
				return isset( $f['field'] ) ? $f['field'] : '?';
			}, (array) $c['fields'] ) ) : array(),
			'classes'     => array_values( array_filter( $cls, 'is_string' ) ),
			'item_fields' => array_values( array_unique( $item ) ),
			'ours'        => in_array( 'EG_Pub_WPML_Items_Module', $cls, true ),
		);
	}
	return $out;
}

function eg_pub_wpml_widgets_handle( WP_REST_Request $r ) {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return new WP_Error( 'eg_wpml', 'WPML no está activo en esta web.', array( 'status' => 400 ) );
	}
	global $wpdb;
	$known  = array_keys( eg_pub_wpml_widgets_known() );
	$before = eg_pub_wpml_widgets_enabled();
	$after  = $before;
	foreach ( (array) $r->get_param( 'enable' ) as $w ) {
		if ( in_array( $w, $known, true ) && ! in_array( $w, $after, true ) ) {
			$after[] = $w;
		}
	}
	$after = array_values( array_diff( $after, (array) $r->get_param( 'disable' ) ) );
	$apply = (bool) $r->get_param( 'apply' );
	if ( $apply && $after !== $before ) {
		update_option( EG_PUB_WPML_WIDGETS_OPT, $after, false );
	}

	$settings = get_option( 'icl_sitepress_settings', array() );
	$tm       = isset( $settings['translation-management'] ) ? $settings['translation-management'] : array();
	$native   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wpml_post_translation_editor_native' AND meta_value = 'yes'" );

	return array(
		'ok'                   => true,
		'applied'              => $apply && $after !== $before,
		'wpml_version'         => ICL_SITEPRESS_VERSION,
		'elementor_pro'        => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null,
		'enabled_before'       => $before,
		'enabled_after'        => $after,
		'widgets_now'          => eg_pub_wpml_widgets_current(),
		'doc_translation_method' => isset( $tm['doc_translation_method'] ) ? $tm['doc_translation_method'] : null,
		'native_editor_posts'  => $native,
		'known'                => $known,
	);
}
