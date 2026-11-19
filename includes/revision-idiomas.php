<?php
/**
 * Tipología «Gestión de la web», módulo «revision-idiomas»: encuentra textos de Elementor sin traducir.
 *   POST /wp-json/eg/v1/i18n-check
 * Sin fix solo lee. Recorre las traducciones de WPML hechas con Elementor (páginas, entradas, tipos propios y plantillas)
 * y compara cada widget con el mismo widget (mismo id de elemento) del original en el idioma principal. Devuelve
 * los textos que siguen idénticos al original, es decir, que se ven en el idioma principal en esa traducción
 * (p. ej. los textos de un carrusel de diapositivas que WPML no tradujo).
 *   langs    opcional: solo esos idiomas (["en", "fr"]).
 *   ids      opcional: solo esas traducciones (o las traducciones de esos originales).
 *   widgets  opcional: solo esos tipos de widget (["slides", "heading"]).
 *   full     opcional: true devuelve el texto completo de cada campo (por defecto, los 120 primeros caracteres).
 *   rows     opcional, con ids: si la entrada tiene varias filas _elementor_data, detalla cada una (meta_id, bytes,
 *            md5 y cuántos textos siguen iguales al original con esa fila). Elementor solo usa la primera.
 * Cada página va una sola vez aunque tenga varias filas _elementor_data (meta_rows dice cuántas).
 *   fix      { "<id de traducción>": { "<id de elemento>": { "slides.0.heading": "Texto traducido", … } } }: escribe esos
 *            textos en la traducción, por ruta (la misma que devuelve la revisión). Solo cambia rutas que ya existen
 *            y son texto. Solo previsualiza; guarda con apply:true (copia en _eg_pub_elementor_backup, como
 *            /template patch, y vacía la caché de CSS de Elementor y de WP Rocket de esa página).
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
	if ( ! is_string( $value ) || '' === $key || '_' === $key[0] || 'slides_name' === $key ) {
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

/** Widgets de una traducción con textos iguales al original: array( lista, número de campos ). */
function eg_pub_i18n_compare( $tr_data, $orig_map, $widgets, $full ) {
	$tr_map = array();
	eg_pub_i18n_index( $tr_data, $tr_map );
	$found = array();
	$n     = 0;
	foreach ( $tr_map as $eid => $el ) {
		$wtype = (string) ( $el['widgetType'] ?? '' );
		if ( '' === $wtype || ! isset( $orig_map[ $eid ] ) || ( $widgets && ! in_array( $wtype, $widgets, true ) ) ) {
			continue;
		}
		$same = array();
		eg_pub_i18n_same( (array) ( $orig_map[ $eid ]['settings'] ?? array() ), (array) ( $el['settings'] ?? array() ), '', $full, $same );
		if ( $same ) {
			$found[] = array( 'element' => (string) $eid, 'widget' => $wtype, 'fields' => $same );
			$n      += count( $same );
		}
	}
	return array( $found, $n );
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
	$rows_detail = (bool) $r->get_param( 'rows' );
	$fix         = $r->get_param( 'fix' );
	if ( is_array( $fix ) && $fix ) {
		return eg_pub_i18n_fix( $fix, (bool) $r->get_param( 'apply' ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

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
		$orig_map = array();
		eg_pub_i18n_index( $orig_data, $orig_map );
		list( $found, $n ) = eg_pub_i18n_compare( $tr_data, $orig_map, $widgets, $full );
		$fields_n         += $n;
		$meta_rows         = array();
		if ( $rows_detail && $only && (int) $row['metas'] > 1 ) {
			// Cada fila _elementor_data por separado: cuántos textos siguen iguales al original con esa fila.
			$metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' ORDER BY meta_id", $id ), ARRAY_A );
			foreach ( $metas as $m ) {
				$data        = json_decode( (string) $m['meta_value'], true );
				$res         = is_array( $data ) ? eg_pub_i18n_compare( $data, $orig_map, $widgets, false ) : array( array(), null );
				$meta_rows[] = array(
					'meta_id' => (int) $m['meta_id'],
					'bytes'   => strlen( (string) $m['meta_value'] ),
					'md5'     => md5( (string) $m['meta_value'] ),
					'same'    => $res[1],
				);
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
				'rows'      => $meta_rows,
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

/** Pone un texto en una ruta «a.0.b» de los ajustes, solo si ya existe y es texto. */
function eg_pub_i18n_set_path( &$settings, $path, $value ) {
	$ref = &$settings;
	foreach ( explode( '.', (string) $path ) as $part ) {
		if ( ! is_array( $ref ) || ! array_key_exists( $part, $ref ) ) {
			return false;
		}
		$ref = &$ref[ $part ];
	}
	if ( ! is_string( $ref ) ) {
		return false;
	}
	$changed = $ref !== $value;
	$ref     = $value;
	return $changed ? 'changed' : 'same';
}

/** Escribe textos traducidos en las traducciones (fix). */
function eg_pub_i18n_fix( $fix, $apply ) {
	$out = array();
	foreach ( $fix as $pid => $elements ) {
		$pid = (int) $pid;
		$res = array( 'id' => $pid, 'changed' => array(), 'same' => array(), 'missing' => array() );
		if ( ! $pid || ! get_post( $pid ) || ! is_array( $elements ) ) {
			$res['error'] = 'No existe esa entrada o faltan los elementos.';
			$out[]        = $res;
			continue;
		}
		if ( ! current_user_can( 'edit_post', $pid ) ) {
			$res['error'] = 'No se puede editar esa entrada.';
			$out[]        = $res;
			continue;
		}
		$old  = (string) get_post_meta( $pid, '_elementor_data', true );
		$data = json_decode( $old, true );
		if ( ! is_array( $data ) ) {
			$res['error'] = 'Esa entrada no tiene JSON de Elementor.';
			$out[]        = $res;
			continue;
		}
		$walk = function ( &$els ) use ( &$walk, $elements, &$res ) {
			foreach ( $els as &$el ) {
				if ( ! is_array( $el ) ) {
					continue;
				}
				$eid = (string) ( $el['id'] ?? '' );
				if ( '' !== $eid && isset( $elements[ $eid ] ) && is_array( $elements[ $eid ] ) ) {
					if ( ! isset( $el['settings'] ) || ! is_array( $el['settings'] ) ) {
						$el['settings'] = array();
					}
					foreach ( $elements[ $eid ] as $path => $text ) {
						$done = eg_pub_i18n_set_path( $el['settings'], $path, (string) $text );
						$res[ $done ? $done : 'missing' ][] = $eid . ':' . $path;
					}
					$res['seen'][] = $eid;
				}
				if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
					$walk( $el['elements'] );
				}
			}
			unset( $el );
		};
		$walk( $data );
		foreach ( array_keys( $elements ) as $eid ) {
			if ( ! in_array( (string) $eid, $res['seen'] ?? array(), true ) ) {
				$res['missing'][] = (string) $eid;
			}
		}
		unset( $res['seen'] );
		$res['applied'] = false;
		if ( $apply && $res['changed'] ) {
			update_post_meta( $pid, '_eg_pub_elementor_backup', wp_slash( wp_json_encode( array( 'time' => time(), 'title' => get_the_title( $pid ), 'data' => $old ) ) ) );
			update_post_meta( $pid, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			delete_post_meta( $pid, '_elementor_css' );
			delete_post_meta( $pid, '_elementor_element_cache' );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $pid );
			}
			$res['applied'] = true;
		}
		$out[] = $res;
	}
	return array( 'ok' => true, 'applied' => $apply, 'pages' => $out );
}
