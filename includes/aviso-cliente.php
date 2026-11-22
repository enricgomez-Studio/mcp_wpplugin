<?php
/**
 * Aviso al cliente de cada post nuevo de su blog.
 *
 * Cuando un post programado desde Make se publica (el mismo momento en que sale el aviso por email del
 * publisher, un par de minutos después, con las traducciones ya publicadas), la web manda los datos del post
 * a un único escenario de Make del estudio. Ese escenario busca al cliente de la web en Notion (idioma,
 * destinatarios, nombre para el saludo, si quiere avisos) y deja el correo preparado en Gmail. La web no
 * decide nada ni guarda datos del cliente: si el dominio no tiene ficha o avisos en Notion, no pasa nada.
 *
 *   POST /wp-json/eg/v1/client-notice   (siempre activo, clave X-EG-Key)
 *     sin parámetros         estado: si el aviso está activo en esta web y los últimos envíos.
 *     enabled: true|false    activa o apaga el aviso en esta web (por defecto activo).
 *     post_id | path         con dry_run (por defecto) devuelve los datos que se mandarían;
 *     send: true             los manda ya al escenario (sirve para probar o repetir un aviso). Va marcado
 *                            como prueba (test) salvo con test: false, que además lo apunta como avisado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_CLIENT_HOOK          = 'https://hook.eu2.make.com/yvfhum6jskof9wb4vkediceb65wz2evq';
const EG_PUB_OPTION_CLIENT_NOTICE = 'eg_pub_client_notice';

function eg_pub_client_notice_enabled() {
	return (bool) get_option( EG_PUB_OPTION_CLIENT_NOTICE, true );
}

/** Dominio de la web sin www, para buscar al cliente. */
function eg_pub_client_notice_domain() {
	$host = strtolower( (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) );
	return preg_replace( '/^www\./', '', $host );
}

/** Nombre de cada idioma en su propio idioma (WPML si está, si no una tabla corta). */
function eg_pub_client_notice_lang_name( $code ) {
	static $names = null;
	if ( null === $names ) {
		$names = array(
			'es' => 'Castellano',
			'ca' => 'Català',
			'en' => 'English',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'it' => 'Italiano',
			'nl' => 'Nederlands',
			'pt' => 'Português',
			'ru' => 'Русский',
		);
		$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		foreach ( (array) $active as $c => $l ) {
			if ( ! empty( $l['native_name'] ) && ! isset( $names[ $c ] ) ) {
				$names[ $c ] = $l['native_name'];
			}
		}
	}
	return $names[ $code ] ?? strtoupper( $code );
}

/** Resumen corto del post: extracto, si no la meta descripción SEO, si no el principio del texto. */
function eg_pub_client_notice_excerpt( $post ) {
	$text = has_excerpt( $post ) ? $post->post_excerpt : '';
	if ( '' === trim( $text ) ) {
		$text = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
	}
	if ( '' === trim( $text ) ) {
		$text = (string) get_post_meta( $post->ID, 'rank_math_description', true );
	}
	if ( '' === trim( $text ) || false !== strpos( $text, '%%' ) ) {
		$text = wp_trim_words( strip_shortcodes( $post->post_content ), 32, '…' );
	}
	return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
}

/** Datos que se mandan al escenario. Textos en plano y también ya escapados para HTML. */
function eg_pub_client_notice_payload( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	$ids = array( $post_id => '' );
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post_id, 'element_type' => 'post' ) );
		$trid    = apply_filters( 'wpml_element_trid', null, $post_id, 'post_post' );
		$trans   = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_post' ) : array();
		$ids     = array();
		foreach ( (array) $trans as $code => $t ) {
			$ids[ (int) $t->element_id ] = (string) $code;
		}
		$own = $details && ! empty( $details->language_code ) ? $details->language_code : '';
		$ids = array( $post_id => $ids[ $post_id ] ?? $own ) + $ids;
	}
	$default = defined( 'ICL_SITEPRESS_VERSION' ) ? (string) apply_filters( 'wpml_default_language', null ) : '';
	if ( '' === $default ) {
		$default = substr( (string) get_locale(), 0, 2 );
	}

	$items = array();
	$links = array();
	foreach ( $ids as $id => $code ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$code  = '' === $code ? $default : $code;
		$p     = get_post( $id );
		$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
		$url   = eg_pub_permalink_lang( $id, defined( 'ICL_SITEPRESS_VERSION' ) ? $code : '' );
		$exc   = eg_pub_client_notice_excerpt( $p );

		$items[ $code ] = array(
			'title'        => $title,
			'title_html'   => esc_html( $title ),
			'excerpt_html' => esc_html( $exc ),
			'url'          => esc_url_raw( $url ),
		);
		$links[] = '<a href="' . esc_url( $url ) . '" style="color:#1F5F8B;font-weight:bold;text-decoration:none;">' . esc_html( eg_pub_client_notice_lang_name( $code ) ) . '</a>';
	}
	if ( ! $items ) {
		return null;
	}
	$main_lang = array_key_first( $items );
	$image     = get_the_post_thumbnail_url( $post_id, 'large' );

	return array(
		'domain'            => eg_pub_client_notice_domain(),
		'site_name'         => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
		'site_url'          => home_url( '/' ),
		'post_id'           => (int) $post_id,
		'date'              => get_the_date( 'Y-m-d', $post_id ),
		'lang'              => $main_lang,
		'title'             => $items[ $main_lang ]['title'],
		'url'               => $items[ $main_lang ]['url'],
		'image'             => $image ? esc_url_raw( $image ) : '',
		'image_html'        => $image ? '<tr><td style="padding:0;"><a href="' . esc_url( $items[ $main_lang ]['url'] ) . '"><img src="' . esc_url( $image ) . '" width="526" alt="' . esc_attr( $items[ $main_lang ]['title'] ) . '" style="display:block;width:100%;max-width:526px;height:auto;border:0;"></a></td></tr>' : '',
		'items'             => $items,
		'langs'             => array_keys( $items ),
		// Fila con los enlaces de todos los idiomas; el escenario cambia {label} por el texto en el idioma del cliente.
		'translations_html' => count( $links ) > 1 ? '<tr><td style="padding:10px 36px 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:22px;color:#45535B;"><strong style="color:#18242B;">{label}</strong> ' . implode( ' &middot; ', $links ) . '</td></tr>' : '',
		'plugin_version'    => EG_PUB_VERSION,
	);
}

/** Manda el aviso. Devuelve true o el texto del error. */
function eg_pub_client_notice_send( $post_id, $test = false ) {
	$payload = eg_pub_client_notice_payload( $post_id );
	if ( ! $payload ) {
		return 'El post no está publicado.';
	}
	$payload['test'] = (bool) $test;
	$res = wp_remote_post( EG_PUB_CLIENT_HOOK, array(
		'timeout' => 15,
		'headers' => array( 'Content-Type' => 'application/json' ),
		'body'    => wp_json_encode( $payload ),
	) );
	$ok  = ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) < 300;
	$msg = $ok ? true : ( is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );

	$log = (array) get_option( 'eg_pub_client_notice_log', array() );
	array_unshift( $log, array(
		'time'    => current_time( 'mysql' ),
		'post_id' => (int) $post_id,
		'title'   => $payload['title'],
		'test'    => (bool) $test,
		'result'  => true === $msg ? 'ok' : $msg,
	) );
	update_option( 'eg_pub_client_notice_log', array_slice( $log, 0, 10 ), false );
	if ( true === $msg && ! $test ) {
		update_post_meta( $post_id, '_eg_client_noticed', current_time( 'mysql' ) );
	}
	return $msg;
}

// Mismo evento que el aviso por email del publisher (solo posts de Make, original, una vez por post).
add_action( 'eg_pub_notify_published', function ( $post_id ) {
	if ( ! eg_pub_client_notice_enabled() || get_post_meta( $post_id, '_eg_client_noticed', true ) ) {
		return;
	}
	if ( 'publish' !== get_post_status( $post_id ) ) {
		return;
	}
	eg_pub_client_notice_send( $post_id );
}, 20 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/client-notice', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_client_notice_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

function eg_pub_client_notice_handle( WP_REST_Request $r ) {
	$p = (array) $r->get_json_params();
	if ( array_key_exists( 'enabled', $p ) ) {
		update_option( EG_PUB_OPTION_CLIENT_NOTICE, (bool) $p['enabled'], false );
	}
	$post_id = (int) ( $p['post_id'] ?? 0 );
	if ( ! $post_id && ! empty( $p['path'] ) ) {
		$post_id = url_to_postid( home_url( '/' . ltrim( (string) $p['path'], '/' ) ) );
	}
	$out = array(
		'enabled' => eg_pub_client_notice_enabled(),
		'domain'  => eg_pub_client_notice_domain(),
		'log'     => array_values( (array) get_option( 'eg_pub_client_notice_log', array() ) ),
	);
	if ( $post_id ) {
		$out['payload'] = eg_pub_client_notice_payload( $post_id );
		if ( ! $out['payload'] ) {
			return eg_pub_error( 'eg_not_published', 'El post no existe o no está publicado.', 404 );
		}
		if ( ! empty( $p['send'] ) ) {
			$sent        = eg_pub_client_notice_send( $post_id, (bool) ( $p['test'] ?? true ) );
			$out['sent'] = true === $sent ? 'ok' : $sent;
		}
	}
	return rest_ensure_response( $out );
}
