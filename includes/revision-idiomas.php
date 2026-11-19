<?php
/**
 * Tipología «Gestión de la web», módulo «revision-idiomas»: encuentra textos de Elementor sin traducir.
 *   POST /wp-json/eg/v1/i18n-check
 * Solo lee. Recorre las traducciones de WPML hechas con Elementor (páginas, entradas, tipos propios y plantillas)
 * y compara cada widget con el mismo widget (mismo id de elemento) del original en el idioma principal. Devuelve
 * los textos que siguen idénticos al original, es decir, que se ven en el idioma principal en esa traducción
 * (p. ej. los textos de un carrusel de diapositivas que WPML no tradujo).
 *   langs    opcional: solo esos idiomas (["en", "fr"]).
 *   ids      opcional: solo esas traducciones (o las traducciones de esos originales).
 *   widgets  opcional: solo esos tipos de widget (["slides", "heading"]).
 *   full     opcional: true devuelve el texto completo de cada campo (por defecto, los 120 primeros caracteres).
 * Cada página va una sola vez aunque tenga varias filas _elementor_data (meta_rows dice cuántas).
 * Los arreglos se hacen después con /template patch en cada traducción.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['revision-idiomas'] = array( 'type' => 'gestion', 'routes' => array( 'i18n-check' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'revision-idiomas' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/i18n-check', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_i18n_check_handle',
		'permission_callback' => eg_pub_module_permission( 'edit_pages' ),
	) );
} );

/** ¿Es un ajuste de texto visible (y no de estilo o configuración)? */
function eg_pub_i18n_is_text( $key, $value ) {
	if ( ! is_string( $value ) || '' === $key || '_' === $key[0] ) {
		return false;
	}
	if ( preg_match( '/typography|font|color|css|class|animation|icon|image|background|url|link|html_tag|anchor|align|position|size|width|height|template|shortcode|source|selected/i', $key ) ) {
		return false;
	}
	$plain = trim( wp_strip_all_tags( $value ) );
	if ( mb_strlen( $plain ) < 4 || ! preg_match( '/\p{L}/u', $plain ) ) {
		return false;
	}
	// Valores de configuración: una sola palabra en minúsculas (yes, center, h2, custom…).
	return (bool) preg_match( '/\s|\p{Lu}/u', $plain );
}

/** Mapa id de elemento => elemento, para todo el árbol. */
function eg_pub_i18n_index( $elements, &$map ) {
	foreach ( (array) $elements as $el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( ! empty( $el['id'] ) ) {
			$map[ (string) $el['id'] ] = $el;
		}
		if ( ! empty( $el['elements'] ) ) {
			eg_pub_i18n_index( $el['elements'], $map );
		}
	}
}

/** Campos de texto iguales entre dos ajustes (también dentro de repetidores como las diapositivas). */
function eg_pub_i18n_same( $orig, $tr, $prefix, $full, &$out ) {
	foreach ( (array) $tr as $key => $value ) {
		if ( ! array_key_exists( $key, (array) $orig ) ) {
			continue;
		}
		$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
		if ( is_array( $value ) && is_array( $orig[ $key ] ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) {
			foreach ( $value as $i => $item ) {
				if ( is_array( $item ) && isset( $orig[ $key ][ $i ] ) && is_array( $orig[ $key ][ $i ] ) ) {
					eg_pub_i18n_same( $orig[ $key ][ $i ], $item, $path . '.' . $i, $full, $out );
				}
			}
			continue;
		}
		if ( eg_pub_i18n_is_text( (string) $key, $value ) && $value === $orig[ $key ] ) {
			$plain        = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
			$out[ $path ] = $full ? $value : mb_substr( $plain, 0, 120 );
		}
	}
}

function eg_pub_i18n_check_handle( WP_REST_Request $r ) {
	global $wpdb;
	$default = (string) apply_filters( 'wpml_default_language', null );
	if ( '' === $default ) {
		return eg_pub_error( 'eg_no_wpml', 'WPML no está activo en esta web.' );
	}
	$langs   = array_filter( array_map( 'sanitize_key', (array) $r->get_param( 'langs' ) ) );
	$only    = array_filter( array_map( 'intval', (array) $r->get_param( 'ids' ) ) );
	$widgets = array_filter( array_map( 'sanitize_key', (array) $r->get_param( 'widgets' ) ) );
	$full    = (bool) $r->get_param( 'full' );

	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_type, MIN(t.language_code) AS lang, MIN(t.trid) AS trid, COUNT(DISTINCT m.meta_id) AS metas
		FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		JOIN {$wpdb->prefix}icl_translations t ON t.element_id = p.ID AND t.element_type = CONCAT('post_', p.post_type)
		WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision', 'nav_menu_item')
		GROUP BY p.ID, p.post_type",
		ARRAY_A
	);
	$by_trid = array();
	foreach ( $rows as $row ) {
		$by_trid[ $row['trid'] ][ $row['lang'] ] = (int) $row['ID'];
	}

	$pages    = array();
	$checked  = 0;
	$fields_n = 0;
	foreach ( $rows as $row ) {
		$id   = (int) $row['ID'];
		$lang = $row['lang'];
		if ( $lang === $default || ( $langs && ! in_array( $lang, $langs, true ) ) ) {
			continue;
		}
		$orig_id = $by_trid[ $row['trid'] ][ $default ] ?? 0;
		if ( ! $orig_id || ( $only && ! in_array( $id, $only, true ) && ! in_array( $orig_id, $only, true ) ) ) {
			continue;
		}
		$tr_data   = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
		$orig_data = json_decode( (string) get_post_meta( $orig_id, '_elementor_data', true ), true );
		if ( ! is_array( $tr_data ) || ! is_array( $orig_data ) ) {
			continue;
		}
		++$checked;
		$tr_map   = array();
		$orig_map = array();
		eg_pub_i18n_index( $tr_data, $tr_map );
		eg_pub_i18n_index( $orig_data, $orig_map );
		$found = array();
		foreach ( $tr_map as $eid => $el ) {
			$wtype = (string) ( $el['widgetType'] ?? '' );
			if ( '' === $wtype || ! isset( $orig_map[ $eid ] ) || ( $widgets && ! in_array( $wtype, $widgets, true ) ) ) {
				continue;
			}
			$same = array();
			eg_pub_i18n_same( (array) ( $orig_map[ $eid ]['settings'] ?? array() ), (array) ( $el['settings'] ?? array() ), '', $full, $same );
			if ( $same ) {
				$found[]   = array( 'element' => $eid, 'widget' => $wtype, 'fields' => $same );
				$fields_n += count( $same );
			}
		}
		if ( $found ) {
			$pages[] = array(
				'id'       => $id,
				'lang'     => $lang,
				'type'     => $row['post_type'],
				'title'    => get_the_title( $id ),
				'url'      => 'elementor_library' === $row['post_type'] ? '' : get_permalink( $id ),
				'original' => $orig_id,
				'widgets'  => $found,
				// Más de una fila _elementor_data en la misma entrada: Elementor solo usa la primera.
				'meta_rows' => (int) $row['metas'],
			);
		}
	}
	return array(
		'ok'           => true,
		'default'      => $default,
		'checked'      => $checked,
		'with_issues'  => count( $pages ),
		'fields'       => $fields_n,
		'pages'        => $pages,
	);
}
