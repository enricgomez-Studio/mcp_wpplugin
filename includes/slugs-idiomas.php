<?php
/**
 * Tipología «Gestión de la web», módulo «i18n-slugs»: URLs de las traducciones de WPML que siguen con el slug
 * del idioma original, y cambio de slug con su redirección 301.
 *   POST /wp-json/eg/v1/i18n-slugs
 * Sin `set`, revisa: lista las traducciones publicadas (páginas y tipos de contenido traducibles) cuyo slug es
 * igual al del original. Parámetros: `types` (lista de tipos; por defecto todos los traducibles públicos),
 * `langs` (lista de idiomas), `all: true` (todas las traducciones, también las que ya tienen slug propio) y
 * `redirects: true` (las redirecciones del plugin Redirection con avisos: `shadows_live` si su origen es una
 * página publicada, que así no se puede ver; `chain` si su destino es origen de otra; `loop` si vuelve a sí misma).
 *   set     {"<id>": "slug-nuevo", …}: cambia el slug de esas traducciones. Para la entrada y sus hijas crea la
 *           301 de la URL vieja a la nueva, reapunta a la nueva las redirecciones que iban a la vieja y desactiva
 *           las que tenían la nueva como origen (si no, la taparían). Borra su indexable de Yoast.
 * Solo previsualiza; guarda con apply:true y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['i18n-slugs'] = array( 'type' => 'gestion', 'routes' => array( 'i18n-slugs' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'i18n-slugs' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/i18n-slugs', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_i18n_slugs_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Ruta de una URL de la web, con barra inicial y final. */
function eg_pub_slug_path( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return '/' . trim( rawurldecode( $path ), '/' ) . ( '' === trim( $path, '/' ) ? '' : '/' );
}

/** Permalink de una entrada en su idioma de WPML. */
function eg_pub_slug_permalink( $id, $lang ) {
	if ( $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}
	clean_post_cache( $id );
	$url = get_permalink( $id );
	if ( $lang ) {
		do_action( 'wpml_switch_language', null );
	}
	return (string) $url;
}

function eg_pub_slug_types( $types ) {
	$all = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library', 'e-landing-page' ) ) );
	$all = array_values( array_filter( $all, function ( $t ) {
		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $t );
	} ) );
	return $types ? array_values( array_intersect( $all, array_map( 'sanitize_key', (array) $types ) ) ) : $all;
}

/** Traducciones publicadas de esos tipos, con el slug de su original. */
function eg_pub_slug_rows( $types, $langs = array() ) {
	global $wpdb;
	if ( ! $types ) {
		return array();
	}
	$t        = $wpdb->prefix . 'icl_translations';
	$elements = implode( ',', array_map( function ( $type ) use ( $wpdb ) {
		return $wpdb->prepare( '%s', 'post_' . $type );
	}, $types ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( "SELECT t.element_id AS id, t.language_code AS lang, t.source_language_code AS src_lang, t.trid, p.post_name AS slug, p.post_type AS type, p.post_title AS title, p.post_parent AS parent
		FROM {$t} t JOIN {$wpdb->posts} p ON p.ID = t.element_id
		WHERE t.element_type IN ({$elements}) AND p.post_status = 'publish'", ARRAY_A );
	$orig = array();
	foreach ( $rows as $r ) {
		if ( null === $r['src_lang'] || '' === $r['src_lang'] ) {
			$orig[ $r['trid'] ] = $r;
		}
	}
	$out = array();
	foreach ( $rows as $r ) {
		$o           = isset( $orig[ $r['trid'] ] ) ? $orig[ $r['trid'] ] : null;
		$r['id']     = (int) $r['id'];
		$r['parent'] = (int) $r['parent'];
		$r['original']      = $o ? (int) $o['id'] : null;
		$r['original_slug'] = $o ? $o['slug'] : null;
		$r['is_original']   = $o && (int) $o['id'] === $r['id'];
		$r['same']          = $o && ! $r['is_original'] && $o['slug'] === $r['slug'];
		if ( $langs && ! in_array( $r['lang'], $langs, true ) ) {
			continue;
		}
		unset( $r['src_lang'], $r['trid'] );
		$out[] = $r;
	}
	return $out;
}

/** Redirecciones del plugin Redirection. */
function eg_pub_slug_redirects() {
	global $wpdb;
	$items = $wpdb->prefix . 'redirection_items';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $items ) ) !== $items ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( "SELECT id, url, regex, status, action_type, action_code, action_data, last_count FROM {$items} ORDER BY id", ARRAY_A );
	return array_map( function ( $r ) {
		$data = maybe_unserialize( $r['action_data'] );
		$to   = is_array( $data ) && isset( $data['url'] ) ? $data['url'] : ( is_string( $data ) ? $data : '' );
		return array(
			'id'      => (int) $r['id'],
			'from'    => $r['url'],
			'to'      => $to,
			'code'    => (int) $r['action_code'],
			'regex'   => (bool) $r['regex'],
			'enabled' => 'enabled' === $r['status'],
			'hits'    => (int) $r['last_count'],
		);
	}, (array) $rows );
}

function eg_pub_slug_same_site( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	return ! $host || strtolower( preg_replace( '/^www\./', '', $host ) ) === strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) ) );
}

function eg_pub_i18n_slugs_handle( WP_REST_Request $r ) {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return eg_pub_error( 'eg_wpml', 'Esta web no tiene WPML.' );
	}
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$types = eg_pub_slug_types( $r->get_param( 'types' ) );
	$langs = array_map( 'sanitize_key', (array) ( $r->get_param( 'langs' ) ? $r->get_param( 'langs' ) : array() ) );
	$set   = $r->get_param( 'set' );
	if ( is_array( $set ) && $set ) {
		return eg_pub_slug_set( $set, (bool) $r->get_param( 'apply' ) );
	}

	$rows  = eg_pub_slug_rows( $types, array() );
	$live  = array();
	$list  = array();
	foreach ( $rows as $row ) {
		$url  = eg_pub_slug_permalink( $row['id'], $row['lang'] );
		$path = eg_pub_slug_path( $url );
		$live[ $path ] = $row['id'];
		if ( $row['is_original'] || ( $langs && ! in_array( $row['lang'], $langs, true ) ) ) {
			continue;
		}
		if ( $row['same'] || $r->get_param( 'all' ) ) {
			$row['path'] = $path;
			$list[]      = $row;
		}
	}
	usort( $list, function ( $a, $b ) {
		return strcmp( $a['type'] . $a['lang'] . $a['path'], $b['type'] . $b['lang'] . $b['path'] );
	} );
	$out = array( 'ok' => true, 'types' => $types, 'count' => count( $list ), 'translations' => $list );

	if ( $r->get_param( 'redirects' ) ) {
		$reds    = eg_pub_slug_redirects();
		$sources = array();
		foreach ( $reds as $red ) {
			if ( $red['enabled'] && ! $red['regex'] ) {
				$sources[ eg_pub_slug_path( $red['from'] ) ] = $red;
			}
		}
		foreach ( $reds as &$red ) {
			$flags = array();
			if ( $red['enabled'] && ! $red['regex'] ) {
				$from = eg_pub_slug_path( $red['from'] );
				if ( isset( $live[ $from ] ) ) {
					$flags[] = 'shadows_live';
					$red['live_id'] = $live[ $from ];
				}
				if ( $red['to'] && eg_pub_slug_same_site( $red['to'] ) ) {
					$to = eg_pub_slug_path( $red['to'] );
					if ( $to === $from ) {
						$flags[] = 'loop';
					} elseif ( isset( $sources[ $to ] ) ) {
						$flags[] = eg_pub_slug_path( $sources[ $to ]['to'] ) === $from ? 'loop' : 'chain';
					}
				}
			}
			$red['flags'] = $flags;
		}
		unset( $red );
		$out['redirects'] = $reds;
	}
	return new WP_REST_Response( $out, 200 );
}

/** La entrada y todas sus descendientes del mismo tipo. */
function eg_pub_slug_family( $id ) {
	$post = get_post( $id );
	$ids  = array( (int) $id );
	if ( $post && is_post_type_hierarchical( $post->post_type ) ) {
		$kids = get_posts( array( 'post_type' => $post->post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'post_parent' => $id, 'suppress_filters' => true ) );
		foreach ( $kids as $kid ) {
			$ids = array_merge( $ids, eg_pub_slug_family( $kid ) );
		}
	}
	return array_values( array_unique( $ids ) );
}

function eg_pub_slug_set( $set, $apply ) {
	global $wpdb;
	$results = array();
	$moves   = array(); // ruta vieja => ruta nueva
	foreach ( $set as $id => $slug ) {
		$id   = (int) $id;
		$post = get_post( $id );
		$slug = sanitize_title( (string) $slug );
		if ( ! $post || '' === $slug ) {
			$results[] = array( 'id' => $id, 'ok' => false, 'message' => 'No existe o el slug está vacío.' );
			continue;
		}
		$lang   = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $id, 'element_type' => $post->post_type ) );
		$family = eg_pub_slug_family( $id );
		$before = array();
		foreach ( $family as $fid ) {
			$before[ $fid ] = eg_pub_slug_path( eg_pub_slug_permalink( $fid, $lang ) );
		}
		$unique = wp_unique_post_slug( $slug, $id, $post->post_status, $post->post_type, $post->post_parent );
		$row    = array( 'id' => $id, 'lang' => $lang, 'old_slug' => $post->post_name, 'new_slug' => $unique, 'ok' => true );
		if ( $unique !== $slug ) {
			$row['note'] = 'El slug «' . $slug . '» ya lo usa otra entrada; quedaría «' . $unique . '».';
		}
		if ( $unique === $post->post_name ) {
			$row['message'] = 'Sin cambios.';
			$results[]      = $row;
			continue;
		}
		if ( $apply ) {
			$wpdb->update( $wpdb->posts, array( 'post_name' => $unique ), array( 'ID' => $id ) );
			add_post_meta( $id, '_wp_old_slug', $post->post_name );
			foreach ( $family as $fid ) {
				clean_post_cache( $fid );
			}
			if ( defined( 'WPSEO_VERSION' ) ) {
				$table = $wpdb->prefix . 'yoast_indexable';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
					$wpdb->query( "DELETE FROM {$table} WHERE object_type = 'post' AND object_id IN (" . implode( ',', array_map( 'intval', $family ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
			$row['paths'] = array();
			foreach ( $family as $fid ) {
				$after = eg_pub_slug_path( eg_pub_slug_permalink( $fid, $lang ) );
				$row['paths'][] = array( 'id' => $fid, 'from' => $before[ $fid ], 'to' => $after );
				if ( $after !== $before[ $fid ] ) {
					$moves[ $before[ $fid ] ] = $after;
				}
			}
		} else {
			// Sin guardar: la ruta nueva se calcula cambiando el último tramo de la vieja.
			$row['paths'] = array();
			foreach ( $family as $fid ) {
				$old = $before[ $fid ];
				$new = str_replace( '/' . $post->post_name . '/', '/' . $unique . '/', $old );
				$row['paths'][] = array( 'id' => $fid, 'from' => $old, 'to' => $new );
				$moves[ $old ] = $new;
			}
		}
		$results[] = $row;
	}

	// Redirecciones: 301 de cada ruta vieja, reapuntar las que iban a ella y desactivar las que taparían la nueva.
	$redirects = array();
	$reds      = eg_pub_slug_redirects();
	$home      = untrailingslashit( get_option( 'home' ) );
	foreach ( $moves as $old => $new ) {
		$action = array( 'from' => $old, 'to' => $new, 'action' => 'create' );
		foreach ( $reds as $red ) {
			if ( $red['regex'] || ! $red['enabled'] ) {
				continue;
			}
			$from = eg_pub_slug_path( $red['from'] );
			$to   = $red['to'] && eg_pub_slug_same_site( $red['to'] ) ? eg_pub_slug_path( $red['to'] ) : '';
			if ( $from === $new ) {
				$redirects[] = array( 'id' => $red['id'], 'from' => $red['from'], 'action' => 'disable', 'why' => 'su origen es la URL nueva' );
				if ( $apply && class_exists( 'Red_Item' ) && ( $item = Red_Item::get_by_id( $red['id'] ) ) ) {
					$item->disable();
				}
			} elseif ( $to === $old && $from !== $old ) {
				$redirects[] = array( 'id' => $red['id'], 'from' => $red['from'], 'action' => 'retarget', 'to' => $new );
				if ( $apply ) {
					eg_pub_redirect_redirection( trim( $from, '/' ), $home . $new, $red['code'] ? $red['code'] : 301 );
				}
			}
		}
		if ( $apply ) {
			$res = class_exists( 'Red_Item' )
				? eg_pub_redirect_redirection( trim( $old, '/' ), $home . $new, 301 )
				: eg_pub_redirect_own( trim( $old, '/' ), $home . $new, 301 );
			$data           = $res instanceof WP_REST_Response ? $res->get_data() : array();
			$action['id']   = isset( $data['id'] ) ? $data['id'] : null;
			$action['ok']   = ! empty( $data['ok'] );
		}
		$redirects[] = $action;
	}
	if ( $apply && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return new WP_REST_Response( array( 'ok' => true, 'applied' => $apply, 'items' => $results, 'redirects' => $redirects ), 200 );
}
