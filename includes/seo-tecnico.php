<?php
/**
 * Tipología «Gestión de la web»: SEO técnico de toda la web.
 *   POST /wp-json/eg/v1/seo-tech
 * Sin parámetros devuelve el estado: negocio local guardado, líneas añadidas a robots.txt, si hay un robots.txt
 * físico (que tapa el virtual de WordPress) y si WPML imprime los hreflang en el <head>.
 *   inspect   ["/serveis/", "https://…/es/…"]  lee el HTML de esas URL de la propia web (máx. 20) y devuelve
 *             título, canonical, hreflang, meta robots, tipos de JSON-LD (también los del @graph de Yoast),
 *             preloads y las primeras imágenes con loading / fetchpriority. Con "robots_txt": true, también robots.txt.
 *   business  {"types": ["VeterinaryCare"], "name", "telephone", "address": {…}, "openingHoursSpecification": […], …}
 *             datos del negocio local en schema.org. Con Yoast se fusionan en su Organization (una sola entidad, sin
 *             duplicar); sin Yoast se imprime un JSON-LD propio en la portada. null lo quita.
 *   robots    "líneas" que se añaden al final del robots.txt virtual de WordPress (p. ej. reglas para bots de IA). "" lo quita.
 *   hreflang  true/false: activa o desactiva en WPML «mostrar idiomas alternativos en el HEAD».
 *   ai_catalog true, o {"description": "…", "queries": ["…", "…"]}: sirve /.well-known/ai-catalog.json (catálogo de
 *             Agentic Resource Discovery que revisa Lighthouse/PageSpeed) con la ficha de la web y su llms.txt si
 *             existe. false lo quita y la ruta vuelve a dar 404. Desde 2.131.0 el mismo catálogo se sirve también en
 *             /.well-known/ard.json (nombre actual de la especificación, el primero que prueba Lighthouse) y se guarda
 *             como ficheros estáticos en .well-known/ para que el servidor los sirva sin cargar WordPress (Lighthouse
 *             solo espera 2 s). Volver a enviar ai_catalog con apply rehace los ficheros aunque no cambie nada.
 *   rankmath_modules {"redirections": "off", "404-monitor": "on"}: activa o desactiva módulos de Rank Math con su
 *             propia función (al activar, Rank Math crea las tablas que le falten al módulo). Sirve para reparar un
 *             módulo activo sin tablas en la base de datos, que llena el log de errores en cada visita. El estado
 *             devuelve los módulos activos y las tablas de Rank Math que hay.
 *   rankmath_settings {"titles": {"local_business_type": "Restaurant", …}, "general": {…}, "sitemap": {…}}: cambia claves de
 *             los ajustes de Rank Math (rank-math-options-titles / -general / -sitemap), p. ej. el SEO local o el título de
 *             la portada. rankmath_get {"titles": ["local_address"]} (o "*") devuelve los valores actuales.
 *   ga4       "G-XXXXXXX": imprime en el <head> de toda la web la etiqueta de Google Analytics 4 (gtag.js) con esa ID
 *             de medición. "" o null la quita. El estado devuelve la ID guardada.
 *   hide_generator true/false: deja de publicar la versión de WordPress (meta generator, feeds y ?ver= de los
 *             scripts y estilos del núcleo) y la meta generator de WooCommerce.
 *   llms_static true/false: guarda el llms.txt de Rank Math como fichero llms.txt en la raíz de la web, para que el
 *             servidor lo sirva al instante sin cargar WordPress (el virtual tarda segundos y PageSpeed da timeout).
 *             El contenido es siempre el de Rank Math: se rehace cada hora y dos minutos después de publicar,
 *             actualizar o retirar una entrada, cambiar categorías o guardar los ajustes de Rank Math (llms.txt,
 *             títulos) o el nombre de la web. false borra el fichero (solo si es el nuestro). El estado devuelve llms_static con la fecha y el tamaño del fichero.
 * Como /seo y /rocket, los cambios solo se previsualizan; apply:true guarda y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_BUSINESS = 'eg_pub_schema_business';
const EG_PUB_OPTION_ROBOTS   = 'eg_pub_robots_extra';
const EG_PUB_OPTION_AI_CAT   = 'eg_pub_ai_catalog';
const EG_PUB_OPTION_GA4      = 'eg_pub_ga4_id';
const EG_PUB_OPTION_NO_GEN   = 'eg_pub_hide_generator';
const EG_PUB_OPTION_LLMS     = 'eg_pub_llms_static';
const EG_PUB_LLMS_HOOK       = 'eg_pub_llms_static_refresh';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['seo-tecnico'] = array( 'type' => 'gestion', 'routes' => array( 'seo-tech' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'seo-tecnico' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/seo-tech', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_seo_tech_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/**
 * llms.txt estático: copia en ABSPATH/llms.txt el llms.txt virtual de Rank Math (query var llms_txt), que se pide
 * con ?llms_txt=1 para no leer el propio fichero. Solo escribe si la respuesta es Markdown con un encabezado.
 * Devuelve array( 'ok' => bool, 'bytes' => int, 'error' => string ).
 */
function eg_pub_llms_static_refresh() {
	if ( ! get_option( EG_PUB_OPTION_LLMS ) ) {
		return array( 'ok' => false, 'bytes' => 0, 'error' => 'desactivado' );
	}
	$res = wp_remote_get( add_query_arg( 'llms_txt', '1', home_url( '/' ) ), array( 'timeout' => 30, 'redirection' => 2 ) );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'bytes' => 0, 'error' => $res->get_error_message() );
	}
	$body = (string) wp_remote_retrieve_body( $res );
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code || strlen( $body ) < 50 || false !== stripos( $body, '<html' ) || ! preg_match( '/^# \S/m', $body ) ) {
		return array( 'ok' => false, 'bytes' => strlen( $body ), 'error' => 'respuesta no válida (' . $code . ')' );
	}
	$file = ABSPATH . 'llms.txt';
	if ( file_exists( $file ) && ! get_option( EG_PUB_OPTION_LLMS . '_md5' ) ) {
		return array( 'ok' => false, 'bytes' => 0, 'error' => 'ya hay un llms.txt que no es nuestro' );
	}
	$tmp = $file . '.eg-tmp';
	if ( false === file_put_contents( $tmp, $body ) || ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore
		return array( 'ok' => false, 'bytes' => 0, 'error' => 'no se puede escribir ' . $file );
	}
	update_option( EG_PUB_OPTION_LLMS . '_md5', md5( $body ), false );
	return array( 'ok' => true, 'bytes' => strlen( $body ), 'error' => '' );
}
add_action( EG_PUB_LLMS_HOOK, 'eg_pub_llms_static_refresh' );

/** Borra el fichero solo si lo escribimos nosotros (mismo md5 que el guardado). */
function eg_pub_llms_static_remove() {
	$file = ABSPATH . 'llms.txt';
	$md5  = (string) get_option( EG_PUB_OPTION_LLMS . '_md5', '' );
	if ( $md5 && file_exists( $file ) && md5_file( $file ) === $md5 ) {
		@unlink( $file ); // phpcs:ignore
	}
	delete_option( EG_PUB_OPTION_LLMS . '_md5' );
	wp_clear_scheduled_hook( EG_PUB_LLMS_HOOK );
}

/** Rehace el fichero unos minutos después de un cambio (agrupa varios cambios seguidos en una sola pasada). */
function eg_pub_llms_static_schedule_soon() {
	if ( ! wp_next_scheduled( EG_PUB_LLMS_HOOK, array( 'save' ) ) ) {
		wp_schedule_single_event( time() + 120, EG_PUB_LLMS_HOOK, array( 'save' ) );
	}
}

if ( get_option( EG_PUB_OPTION_LLMS ) ) {
	add_action( 'init', function () {
		if ( ! wp_next_scheduled( EG_PUB_LLMS_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', EG_PUB_LLMS_HOOK );
		}
	} );
	// Entrada publicada, actualizada o retirada (borrador, papelera, privada): el llms.txt de Rank Math cambia.
	add_action( 'transition_post_status', function ( $new_status, $old_status, $post ) {
		if ( ( 'publish' !== $new_status && 'publish' !== $old_status ) || wp_is_post_revision( $post ) || 'nav_menu_item' === $post->post_type ) {
			return;
		}
		eg_pub_llms_static_schedule_soon();
	}, 20, 3 );
	// Ajustes de Rank Math (opciones de llms.txt en General, nombre y descripción de la web en Títulos).
	foreach ( array( 'rank-math-options-general', 'rank-math-options-titles', 'blogname', 'blogdescription' ) as $eg_llms_opt ) {
		add_action( 'update_option_' . $eg_llms_opt, 'eg_pub_llms_static_schedule_soon' );
	}
	// Categorías y términos que Rank Math lista en el llms.txt.
	add_action( 'created_term', 'eg_pub_llms_static_schedule_soon' );
	add_action( 'edited_term', 'eg_pub_llms_static_schedule_soon' );
	add_action( 'delete_term', 'eg_pub_llms_static_schedule_soon' );
}

function eg_pub_llms_static_state() {
	$file = ABSPATH . 'llms.txt';
	return array(
		'enabled' => (bool) get_option( EG_PUB_OPTION_LLMS, false ),
		'file'    => file_exists( $file ),
		'ours'    => file_exists( $file ) && md5_file( $file ) === (string) get_option( EG_PUB_OPTION_LLMS . '_md5', '' ),
		'updated' => file_exists( $file ) ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $file ) ) : null,
		'bytes'   => file_exists( $file ) ? (int) filesize( $file ) : 0,
		'next'    => wp_next_scheduled( EG_PUB_LLMS_HOOK ) ? gmdate( 'Y-m-d H:i:s', (int) wp_next_scheduled( EG_PUB_LLMS_HOOK ) ) : null,
	);
}

/** Rutas de los ficheros estáticos del catálogo de agentes (ARD y su nombre anterior). */
function eg_pub_ai_catalog_files() {
	return array( ABSPATH . '.well-known/ard.json', ABSPATH . '.well-known/ai-catalog.json' );
}

/**
 * Escribe (o borra, con array vacío) los ficheros estáticos del catálogo. No pisa ni borra ficheros que no sean
 * nuestros (md5 guardado). Devuelve array( 'written' => [...], 'error' => string ).
 */
function eg_pub_ai_catalog_static_write( $catalog ) {
	$md5s = (array) get_option( EG_PUB_OPTION_AI_CAT . '_md5', array() );
	$out  = array( 'written' => array(), 'error' => '' );
	if ( ! $catalog ) {
		foreach ( eg_pub_ai_catalog_files() as $file ) {
			if ( isset( $md5s[ $file ] ) && file_exists( $file ) && md5_file( $file ) === $md5s[ $file ] ) {
				@unlink( $file ); // phpcs:ignore
			}
		}
		delete_option( EG_PUB_OPTION_AI_CAT . '_md5' );
		return $out;
	}
	$json = wp_json_encode( $catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	$dir  = ABSPATH . '.well-known';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		$out['error'] = 'no se puede crear ' . $dir;
		return $out;
	}
	foreach ( eg_pub_ai_catalog_files() as $file ) {
		if ( file_exists( $file ) && ( ! isset( $md5s[ $file ] ) || md5_file( $file ) !== $md5s[ $file ] ) ) {
			$out['error'] = trim( $out['error'] . ' ya hay un ' . basename( $file ) . ' que no es nuestro.' );
			continue;
		}
		$tmp = $file . '.eg-tmp';
		if ( false === file_put_contents( $tmp, $json ) || ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore
			$out['error'] = trim( $out['error'] . ' no se puede escribir ' . $file . '.' );
			continue;
		}
		$md5s[ $file ]    = md5( $json );
		$out['written'][] = basename( $file );
	}
	update_option( EG_PUB_OPTION_AI_CAT . '_md5', $md5s, false );
	return $out;
}

/** Limpia en profundidad los datos del negocio: textos sin HTML y URLs válidas. */
function eg_pub_seo_tech_clean( $value, $key = '' ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9@_]/', '', (string) $k ) ] = eg_pub_seo_tech_clean( $v, is_int( $k ) ? $key : (string) $k );
		}
		return $out;
	}
	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value;
	}
	$value = (string) $value;
	return in_array( $key, array( 'url', 'sameAs', 'image', 'logo', 'hasMap' ), true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
}

/** Datos del negocio guardados (sin la clave «types»), y sus tipos. */
function eg_pub_seo_tech_business() {
	$data = get_option( EG_PUB_OPTION_BUSINESS, array() );
	if ( ! is_array( $data ) || ! $data ) {
		return array( array(), array() );
	}
	$types = array_values( array_filter( (array) ( $data['types'] ?? array() ), 'is_string' ) );
	unset( $data['types'], $data['@type'], $data['@id'], $data['@context'] );
	return array( $data, $types );
}

// Yoast: el negocio se fusiona en su Organization, así hay una sola entidad para la web.
add_filter( 'wpseo_schema_graph', function ( $graph ) {
	list( $data, $types ) = eg_pub_seo_tech_business();
	if ( ! $data || ! is_array( $graph ) ) {
		return $graph;
	}
	foreach ( $graph as &$piece ) {
		$piece_types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
		if ( in_array( 'Organization', $piece_types, true ) ) {
			$piece['@type'] = array_values( array_unique( array_merge( $piece_types, $types ) ) );
			$piece          = array_merge( $piece, $data );
			unset( $piece );
			return $graph;
		}
	}
	unset( $piece );
	// Sin Organization en Yoast (web configurada como persona): el negocio va como pieza propia.
	$graph[] = array_merge( array( '@type' => $types ? $types : 'LocalBusiness', '@id' => home_url( '/#localbusiness' ) ), $data );
	return $graph;
} );

add_action( 'wp_head', function () {
	if ( defined( 'WPSEO_VERSION' ) || ! is_front_page() ) {
		return;
	}
	list( $data, $types ) = eg_pub_seo_tech_business();
	if ( ! $data ) {
		return;
	}
	$schema = array_merge( array( '@context' => 'https://schema.org', '@type' => $types ? $types : 'LocalBusiness' ), $data );
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
} );

// Google Analytics 4: etiqueta gtag.js en el <head> de toda la web, con la ID guardada por /seo-tech ga4.
add_action( 'wp_head', function () {
	$id = (string) get_option( EG_PUB_OPTION_GA4, '' );
	if ( '' === $id || is_admin() ) {
		return;
	}
	$id = esc_attr( $id );
	echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>' . "\n";
	echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . $id . "');</script>\n";
}, 1 );

// Sin versión de WordPress a la vista (/seo-tech hide_generator).
if ( get_option( EG_PUB_OPTION_NO_GEN ) ) {
	add_action( 'init', function () {
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wc_generator_tag' );
	}, 99 );
	add_filter( 'the_generator', '__return_empty_string', 99 );
	add_filter( 'get_the_generator_html', '__return_empty_string', 99 );
	add_filter( 'get_the_generator_xhtml', '__return_empty_string', 99 );
	$eg_strip_ver = function ( $src ) {
		if ( is_string( $src ) && false !== strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	};
	add_filter( 'style_loader_src', $eg_strip_ver, 99 );
	add_filter( 'script_loader_src', $eg_strip_ver, 99 );
}

add_filter( 'robots_txt', function ( $output ) {
	$extra = trim( (string) get_option( EG_PUB_OPTION_ROBOTS, '' ) );
	return '' === $extra ? $output : rtrim( $output ) . "\n\n" . $extra . "\n";
}, 99 );

/**
 * Catálogo de Agentic Resource Discovery para /.well-known/ai-catalog.json. Se calcula al guardar (así se sabe si
 * llms.txt existe) y se sirve tal cual: specVersion, host y entries, con representativeQueries en cada entrada.
 */
function eg_pub_seo_tech_ai_catalog_build( $cfg ) {
	$cfg     = is_array( $cfg ) ? $cfg : array();
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$name    = wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$desc    = isset( $cfg['description'] ) ? sanitize_text_field( $cfg['description'] ) : wp_strip_all_tags( html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES ) );
	$queries = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $cfg['queries'] ?? array() ) ) ) );
	if ( ! $queries ) {
		$queries = array( sprintf( '¿Qué ofrece %s?', $name ), sprintf( '¿Cómo contactar con %s?', $name ) );
	}
	$catalog = array(
		'specVersion' => '1.0',
		'host'        => array( 'displayName' => $name, 'identifier' => $host ),
		'entries'     => array(),
	);
	$llms = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 15, 'redirection' => 0 ) );
	if ( ! is_wp_error( $llms ) && 200 === (int) wp_remote_retrieve_response_code( $llms ) ) {
		$catalog['entries'][] = array(
			'identifier'            => 'urn:air:' . $host . ':docs:llms-txt',
			'displayName'           => $name . ' (llms.txt)',
			'type'                  => 'text/markdown',
			'url'                   => home_url( '/llms.txt' ),
			'description'           => '' !== $desc ? $desc : $name,
			'representativeQueries' => array_slice( $queries, 0, 5 ),
		);
	}
	return $catalog;
}

add_action( 'init', function () {
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
	$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	if ( $base . '/.well-known/ai-catalog.json' !== $path && $base . '/.well-known/ard.json' !== $path ) {
		return;
	}
	$catalog = get_option( EG_PUB_OPTION_AI_CAT, array() );
	if ( ! is_array( $catalog ) || ! $catalog ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: public, max-age=86400' );
	header( 'X-Robots-Tag: noindex' );
	echo wp_json_encode( $catalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	exit;
}, 1 );

/** Lee una URL de la propia web y resume lo que importa para el SEO técnico. */
function eg_pub_seo_tech_inspect_url( $url ) {
	$started = microtime( true );
	$res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Linux; Android 12) EG-Publicacion/' . EG_PUB_VERSION ) ) );
	if ( is_wp_error( $res ) ) {
		return array( 'url' => $url, 'error' => $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$html = (string) wp_remote_retrieve_body( $res );
	$out  = array( 'url' => $url, 'status' => $code );
	if ( $code >= 300 && $code < 400 ) {
		$out['location'] = wp_remote_retrieve_header( $res, 'location' );
		return $out;
	}
	$head = preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $m ) ? $m[1] : $html;
	$attr = function ( $tag, $name ) {
		return preg_match( '/\s' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $a ) ? html_entity_decode( $a[2] ) : null;
	};
	$out['title'] = preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $t ) ? trim( html_entity_decode( wp_strip_all_tags( $t[1] ) ) ) : null;
	$out['canonical'] = array();
	$out['hreflang']  = array();
	$out['preload']   = array();
	preg_match_all( '#<link\b[^>]*>#i', $head, $links );
	foreach ( $links[0] as $tag ) {
		$rel = strtolower( (string) $attr( $tag, 'rel' ) );
		if ( 'canonical' === $rel ) {
			$out['canonical'][] = $attr( $tag, 'href' );
		} elseif ( 'alternate' === $rel && null !== $attr( $tag, 'hreflang' ) ) {
			$out['hreflang'][ $attr( $tag, 'hreflang' ) ] = $attr( $tag, 'href' );
		} elseif ( 'preload' === $rel ) {
			$out['preload'][] = array_filter( array( 'as' => $attr( $tag, 'as' ), 'href' => $attr( $tag, 'href' ), 'imagesrcset' => $attr( $tag, 'imagesrcset' ) ? 'sí' : null, 'fetchpriority' => $attr( $tag, 'fetchpriority' ) ) );
		}
	}
	$out['robots'] = array();
	preg_match_all( '#<meta\b[^>]*>#i', $head, $metas );
	foreach ( $metas[0] as $tag ) {
		$name = strtolower( (string) $attr( $tag, 'name' ) );
		if ( in_array( $name, array( 'robots', 'googlebot' ), true ) ) {
			$out['robots'][ $name ] = $attr( $tag, 'content' );
		}
	}
	$out['x_robots_tag'] = wp_remote_retrieve_header( $res, 'x-robots-tag' ) ? wp_remote_retrieve_header( $res, 'x-robots-tag' ) : null;
	// Cabeceras de caché: dicen si la página sale de Cloudflare, de WP Rocket o de PHP, y cuánto tarda el servidor.
	$out['cache'] = array();
	foreach ( array( 'cf-cache-status', 'cache-control', 'age', 'server', 'x-powered-by' ) as $h ) {
		$v = wp_remote_retrieve_header( $res, $h );
		if ( $v ) {
			$out['cache'][ $h ] = is_array( $v ) ? implode( ', ', $v ) : $v;
		}
	}
	$out['cache']['rocket'] = false !== strpos( $html, 'This website is like a Rocket' );
	$out['cache']['seconds'] = round( microtime( true ) - $started, 2 );
	$out['jsonld'] = array();
	preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $scripts );
	foreach ( $scripts[1] as $json ) {
		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			$out['jsonld'][] = 'JSON no válido';
			continue;
		}
		$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
		$block = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				$item = implode( '+', (array) $node['@type'] );
				if ( isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
					$item .= ' (' . count( $node['mainEntity'] ) . ' preguntas)';
				}
				if ( isset( $node['address'] ) ) {
					$item .= ' (con dirección)';
				}
				if ( isset( $node['openingHoursSpecification'] ) || isset( $node['openingHours'] ) ) {
					$item .= ' (con horario)';
				}
				$block[] = $item;
			}
		}
		$out['jsonld'][] = implode( ', ', $block );
	}
	$body = preg_match( '#<body[^>]*>(.*)#is', $html, $b ) ? $b[1] : $html;
	preg_match_all( '#<img\b[^>]*>#i', $body, $imgs );
	$out['images'] = array();
	foreach ( array_slice( $imgs[0], 0, 6 ) as $tag ) {
		$src = $attr( $tag, 'src' );
		if ( $src && 0 === strpos( $src, 'data:' ) ) {
			$src = 'data:… (data-lazy-src: ' . $attr( $tag, 'data-lazy-src' ) . ')';
		}
		$out['images'][] = array_filter( array(
			'src'           => $src,
			'loading'       => $attr( $tag, 'loading' ),
			'fetchpriority' => $attr( $tag, 'fetchpriority' ),
			'width'         => $attr( $tag, 'width' ),
			'height'        => $attr( $tag, 'height' ),
			'class'         => $attr( $tag, 'class' ) ? mb_substr( $attr( $tag, 'class' ), 0, 80 ) : null,
		) );
	}
	$out['bytes'] = strlen( $html );
	return $out;
}

/** Ajuste de WPML «mostrar idiomas alternativos en el HEAD» (null si WPML no está). */
function eg_pub_seo_tech_wpml_hreflang() {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return null;
	}
	$settings = get_option( 'icl_sitepress_settings', array() );
	return ! empty( $settings['seo']['head_langs'] );
}

function eg_pub_seo_tech_handle( WP_REST_Request $r ) {
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	$out   = array( 'ok' => true, 'applied' => false, 'changes' => array() );

	if ( isset( $p['inspect'] ) ) {
		$urls = array_slice( array_values( array_filter( (array) $p['inspect'], 'is_string' ) ), 0, 20 );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out['pages'] = array();
		foreach ( $urls as $url ) {
			$url = 0 === strpos( $url, '/' ) ? untrailingslashit( get_option( 'home' ) ) . $url : $url;
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				$out['pages'][] = array( 'url' => $url, 'error' => 'Solo URLs de esta web (' . $host . ').' );
				continue;
			}
			$out['pages'][] = eg_pub_seo_tech_inspect_url( $url );
		}
	}
	if ( ! empty( $p['robots_txt'] ) ) {
		$res = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 15 ) );
		$out['robots_txt'] = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res );
	}

	if ( array_key_exists( 'business', $p ) ) {
		$before = get_option( EG_PUB_OPTION_BUSINESS, array() );
		$after  = is_array( $p['business'] ) ? eg_pub_seo_tech_clean( $p['business'] ) : array();
		if ( $before !== $after ) {
			$out['changes']['business'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				update_option( EG_PUB_OPTION_BUSINESS, $after, false );
			}
		}
	}
	if ( array_key_exists( 'robots', $p ) ) {
		$before = (string) get_option( EG_PUB_OPTION_ROBOTS, '' );
		$after  = trim( implode( "\n", array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $p['robots'] ) ) ) );
		if ( $before !== $after ) {
			$out['changes']['robots'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				update_option( EG_PUB_OPTION_ROBOTS, $after, false );
			}
		}
	}
	if ( array_key_exists( 'hreflang', $p ) ) {
		$before = eg_pub_seo_tech_wpml_hreflang();
		if ( null === $before ) {
			return eg_pub_error( 'eg_wpml', 'WPML no está activo en esta web.' );
		}
		$after = rest_sanitize_boolean( $p['hreflang'] );
		if ( $before !== $after ) {
			$out['changes']['hreflang'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				$settings                       = get_option( 'icl_sitepress_settings', array() );
				$settings['seo']['head_langs'] = $after ? 1 : 0;
				update_option( 'icl_sitepress_settings', $settings );
			}
		}
	}
	if ( array_key_exists( 'ai_catalog', $p ) ) {
		$before = get_option( EG_PUB_OPTION_AI_CAT, array() );
		$after  = ( false === $p['ai_catalog'] || null === $p['ai_catalog'] || 'false' === $p['ai_catalog'] ) ? array() : eg_pub_seo_tech_ai_catalog_build( $p['ai_catalog'] );
		if ( $before !== $after ) {
			$out['changes']['ai_catalog'] = array( 'before' => $before, 'after' => $after );
		}
		if ( $apply ) {
			if ( $after ) {
				update_option( EG_PUB_OPTION_AI_CAT, $after, false );
			} else {
				delete_option( EG_PUB_OPTION_AI_CAT );
			}
			$out['ai_catalog_static'] = eg_pub_ai_catalog_static_write( $after );
		}
	}
	if ( array_key_exists( 'ga4', $p ) ) {
		$before = (string) get_option( EG_PUB_OPTION_GA4, '' );
		$after  = strtoupper( trim( (string) $p['ga4'] ) );
		if ( '' !== $after && ! preg_match( '/^G-[A-Z0-9]{4,20}$/', $after ) ) {
			return eg_pub_error( 'eg_ga4', 'La ID de medición de Google Analytics 4 tiene que ser del tipo G-XXXXXXX.' );
		}
		if ( $before !== $after ) {
			$out['changes']['ga4'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				if ( '' === $after ) {
					delete_option( EG_PUB_OPTION_GA4 );
				} else {
					update_option( EG_PUB_OPTION_GA4, $after, true );
				}
			}
		}
	}
	if ( array_key_exists( 'hide_generator', $p ) ) {
		$before = (bool) get_option( EG_PUB_OPTION_NO_GEN, false );
		$after  = rest_sanitize_boolean( $p['hide_generator'] );
		if ( $before !== $after ) {
			$out['changes']['hide_generator'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				update_option( EG_PUB_OPTION_NO_GEN, $after ? 1 : 0, true );
			}
		}
	}
	if ( array_key_exists( 'llms_static', $p ) ) {
		$before = (bool) get_option( EG_PUB_OPTION_LLMS, false );
		$after  = rest_sanitize_boolean( $p['llms_static'] );
		if ( $before !== $after ) {
			$out['changes']['llms_static'] = array( 'before' => $before, 'after' => $after );
		}
		if ( $apply ) {
			if ( $after ) {
				update_option( EG_PUB_OPTION_LLMS, 1, true );
				$out['llms_static'] = eg_pub_llms_static_refresh();
				if ( ! wp_next_scheduled( EG_PUB_LLMS_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', EG_PUB_LLMS_HOOK );
				}
			} else {
				delete_option( EG_PUB_OPTION_LLMS );
				eg_pub_llms_static_remove();
			}
		}
	}
	if ( array_key_exists( 'rankmath_modules', $p ) ) {
		if ( ! class_exists( '\\RankMath\\Helper' ) ) {
			return eg_pub_error( 'eg_rankmath', 'Rank Math no está activo en esta web.' );
		}
		$before = array_values( (array) get_option( 'rank_math_modules', array() ) );
		$after  = $before;
		$wanted = array();
		foreach ( (array) $p['rankmath_modules'] as $mod => $state ) {
			$mod = sanitize_key( $mod );
			if ( '' === $mod ) {
				continue;
			}
			$on             = in_array( $state, array( true, 1, '1', 'on', 'true' ), true );
			$wanted[ $mod ] = $on ? 'on' : 'off';
			$after          = $on ? array_values( array_unique( array_merge( $after, array( $mod ) ) ) ) : array_values( array_diff( $after, array( $mod ) ) );
		}
		if ( $wanted ) {
			// Siempre cuenta como cambio: activar un módulo ya activo sirve para que Rank Math cree sus tablas.
			$out['changes']['rankmath_modules'] = array( 'before' => $before, 'after' => $after, 'set' => $wanted );
			if ( $apply ) {
				if ( is_callable( array( '\\RankMath\\Helper', 'update_modules' ) ) ) {
					\RankMath\Helper::update_modules( $wanted );
				} else {
					update_option( 'rank_math_modules', $after );
				}
				$on_mods = array_keys( array_filter( $wanted, function ( $v ) {
					return 'on' === $v;
				} ) );
				if ( $on_mods && is_callable( array( '\\RankMath\\Installer', 'create_tables' ) ) ) {
					\RankMath\Installer::create_tables( $on_mods );
				}
			}
		}
	}
	if ( array_key_exists( 'rankmath_settings', $p ) || array_key_exists( 'rankmath_get', $p ) ) {
		if ( ! class_exists( '\\RankMath\\Helper' ) ) {
			return eg_pub_error( 'eg_rankmath', 'Rank Math no está activo en esta web.' );
		}
		$groups = array( 'titles' => 'rank-math-options-titles', 'general' => 'rank-math-options-general', 'sitemap' => 'rank-math-options-sitemap' );
		foreach ( (array) ( $p['rankmath_settings'] ?? array() ) as $group => $values ) {
			if ( ! isset( $groups[ $group ] ) || ! is_array( $values ) ) {
				continue;
			}
			$opt    = (array) get_option( $groups[ $group ], array() );
			$before = array();
			$after  = array();
			foreach ( $values as $key => $value ) {
				if ( ! preg_match( '/^[a-z0-9_]+$/', (string) $key ) ) {
					continue;
				}
				$before[ $key ] = $opt[ $key ] ?? null;
				$after[ $key ]  = eg_pub_seo_tech_clean( $value, (string) $key );
				$opt[ $key ]    = $after[ $key ];
			}
			if ( $after && $before !== $after ) {
				$out['changes'][ 'rankmath_' . $group ] = array( 'before' => $before, 'after' => $after );
				if ( $apply ) {
					update_option( $groups[ $group ], $opt );
				}
			}
		}
		foreach ( (array) ( $p['rankmath_get'] ?? array() ) as $group => $keys ) {
			if ( isset( $groups[ $group ] ) ) {
				$opt = (array) get_option( $groups[ $group ], array() );
				$out['rankmath'][ $group ] = '*' === $keys ? $opt : array_intersect_key( $opt, array_flip( array_map( 'strval', (array) $keys ) ) );
			}
		}
	}
	if ( $apply && $out['changes'] ) {
		$out['applied'] = true;
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$out['state'] = array(
		'business'        => get_option( EG_PUB_OPTION_BUSINESS, array() ),
		'robots'          => (string) get_option( EG_PUB_OPTION_ROBOTS, '' ),
		'robots_physical' => file_exists( ABSPATH . 'robots.txt' ),
		'ai_catalog'      => get_option( EG_PUB_OPTION_AI_CAT, array() ) ? home_url( '/.well-known/ard.json' ) : null,
		'ai_catalog_files' => array_map( 'basename', array_values( array_filter( eg_pub_ai_catalog_files(), 'file_exists' ) ) ),
		'wpml_hreflang'   => eg_pub_seo_tech_wpml_hreflang(),
		'ga4'             => (string) get_option( EG_PUB_OPTION_GA4, '' ),
		'hide_generator'  => (bool) get_option( EG_PUB_OPTION_NO_GEN, false ),
		'llms_static'     => eg_pub_llms_static_state(),
		'yoast'           => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null,
		'yoast_company'   => defined( 'WPSEO_VERSION' ) ? ( (array) get_option( 'wpseo_titles', array() ) )['company_or_person'] ?? null : null,
	);
	if ( class_exists( '\\RankMath\\Helper' ) ) {
		global $wpdb;
		$out['state']['rank_math_modules'] = array_values( (array) get_option( 'rank_math_modules', array() ) );
		$out['state']['rank_math_tables']  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'rank_math' ) . '%' ) );
	}
	return new WP_REST_Response( $out, 200 );
}
