<?php
/**
 * Acceso con OAuth al servidor MCP del servicio «Asistente IA» (ver includes/asistente.php): el cliente pega en su
 * Claude o ChatGPT la dirección /wp-json/eg/v1/mcp, sin clave, y el asistente le pide entrar con su usuario de la web
 * y autorizar qué podrá hacer. Sigue la autorización de MCP (OAuth 2.1 con PKCE S256, registro dinámico de clientes
 * RFC 7591, metadatos RFC 8414 y RFC 9728). Cada autorización crea una conexión más (tipo oauth), que se ve y se
 * revoca igual que las de URL privada.
 *
 *   GET  /.well-known/oauth-protected-resource[/wp-json/eg/v1/mcp]   (y /wp-json/eg/v1/oauth/resource)
 *   GET  /.well-known/oauth-authorization-server                     (y /wp-json/eg/v1/oauth/metadata)
 *   POST /wp-json/eg/v1/oauth/register   registro dinámico de clientes (públicos, sin secreto)
 *   GET  /wp-json/eg/v1/oauth/authorize  redirige a la pantalla de autorización (wp-admin/admin-post.php, con inicio
 *                                        de sesión de WordPress, compatible con los plugins que esconden wp-login.php)
 *   POST /wp-json/eg/v1/oauth/token      authorization_code y refresh_token (rotativo)
 *
 * Token de acceso: 1 hora. Token de refresco: 90 días, cambia en cada uso. Solo se guardan sus hashes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_AI_CLIENTS         = 'eg_pub_ai_oauth_clients';
const EG_PUB_AI_ACCESS_PREFIX   = 'egat_';
const EG_PUB_AI_REFRESH_PREFIX  = 'egrt_';
const EG_PUB_AI_ACCESS_TTL      = HOUR_IN_SECONDS;
const EG_PUB_AI_REFRESH_TTL     = 90 * DAY_IN_SECONDS;

function eg_pub_ai_issuer() {
	return untrailingslashit( home_url() );
}

function eg_pub_ai_resource() {
	return untrailingslashit( rest_url( 'eg/v1/mcp' ) );
}

function eg_pub_ai_resource_metadata_url() {
	return rest_url( 'eg/v1/oauth/resource' );
}

function eg_pub_ai_resource_metadata() {
	return array(
		'resource'                 => eg_pub_ai_resource(),
		'authorization_servers'    => array( eg_pub_ai_issuer() ),
		'scopes_supported'         => eg_pub_ai_available_scopes(),
		'bearer_methods_supported' => array( 'header' ),
		'resource_name'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
	);
}

function eg_pub_ai_as_metadata() {
	return array(
		'issuer'                                => eg_pub_ai_issuer(),
		'authorization_endpoint'                => rest_url( 'eg/v1/oauth/authorize' ),
		'token_endpoint'                        => rest_url( 'eg/v1/oauth/token' ),
		'registration_endpoint'                 => rest_url( 'eg/v1/oauth/register' ),
		'scopes_supported'                      => eg_pub_ai_available_scopes(),
		'response_types_supported'              => array( 'code' ),
		'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
		'code_challenge_methods_supported'      => array( 'S256' ),
		'token_endpoint_auth_methods_supported' => array( 'none' ),
	);
}

/* ======================================================================
 * /.well-known en la raíz de la web
 * ==================================================================== */

add_action( 'parse_request', function () {
	if ( ! eg_pub_ai_enabled() ) {
		return;
	}
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH );
	$base = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	if ( '' !== $base && 0 === strpos( $path, $base ) ) {
		$path = substr( $path, strlen( $base ) );
	}
	if ( 0 !== strpos( $path, '/.well-known/' ) ) {
		return;
	}
	$data = null;
	if ( preg_match( '#^/\.well-known/oauth-protected-resource(/.*)?$#', $path ) ) {
		$data = eg_pub_ai_resource_metadata();
	} elseif ( preg_match( '#^/\.well-known/(oauth-authorization-server|openid-configuration)/?$#', $path ) ) {
		$data = eg_pub_ai_as_metadata();
	}
	if ( null === $data ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Access-Control-Allow-Origin: *' );
	header( 'Cache-Control: max-age=3600' );
	echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
	exit;
}, 0 );

/* ======================================================================
 * Rutas REST
 * ==================================================================== */

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_ai_enabled() ) {
		return;
	}
	$open = array( 'permission_callback' => '__return_true' );
	register_rest_route( 'eg/v1', '/oauth/resource', $open + array(
		'methods'  => 'GET',
		'callback' => function () {
			return new WP_REST_Response( eg_pub_ai_resource_metadata(), 200 );
		},
	) );
	register_rest_route( 'eg/v1', '/oauth/metadata', $open + array(
		'methods'  => 'GET',
		'callback' => function () {
			return new WP_REST_Response( eg_pub_ai_as_metadata(), 200 );
		},
	) );
	register_rest_route( 'eg/v1', '/oauth/register', $open + array(
		'methods'  => 'POST',
		'callback' => 'eg_pub_ai_oauth_register',
	) );
	register_rest_route( 'eg/v1', '/oauth/authorize', $open + array(
		'methods'  => 'GET',
		'callback' => function ( WP_REST_Request $r ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', array_diff_key( array_filter( $r->get_query_params(), 'is_scalar' ), array( 'rest_route' => 1 ) ) ) ), admin_url( 'admin-post.php' ) );
			$url = add_query_arg( 'action', 'eg_ai_authorize', $url );
			$res = new WP_REST_Response( null, 302 );
			$res->header( 'Location', $url );
			return $res;
		},
	) );
	register_rest_route( 'eg/v1', '/oauth/token', $open + array(
		'methods'  => 'POST',
		'callback' => 'eg_pub_ai_oauth_token',
	) );
} );

function eg_pub_ai_oauth_error( $error, $description, $status = 400 ) {
	$res = new WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), $status );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/* ======================================================================
 * Registro dinámico de clientes
 * ==================================================================== */

function eg_pub_ai_oauth_clients() {
	$c = get_option( EG_PUB_AI_CLIENTS, array() );
	return is_array( $c ) ? $c : array();
}

/** Redirecciones admitidas: https, o http solo en localhost (clientes de escritorio). */
function eg_pub_ai_valid_redirect( $uri ) {
	$p = wp_parse_url( (string) $uri );
	if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || ! empty( $p['fragment'] ) ) {
		return false;
	}
	if ( 'https' === strtolower( $p['scheme'] ) ) {
		return true;
	}
	return 'http' === strtolower( $p['scheme'] ) && in_array( $p['host'], array( 'localhost', '127.0.0.1', '[::1]' ), true );
}

function eg_pub_ai_oauth_register( WP_REST_Request $r ) {
	$p    = (array) $r->get_json_params() + (array) $r->get_body_params();
	$uris = array_values( array_filter( (array) ( $p['redirect_uris'] ?? array() ), 'is_string' ) );
	if ( ! $uris ) {
		return eg_pub_ai_oauth_error( 'invalid_redirect_uri', 'Falta redirect_uris.' );
	}
	foreach ( $uris as $uri ) {
		if ( ! eg_pub_ai_valid_redirect( $uri ) ) {
			return eg_pub_ai_oauth_error( 'invalid_redirect_uri', 'redirect_uri no admitida: ' . $uri );
		}
	}
	$clients = eg_pub_ai_oauth_clients();
	// Como máximo 200: se descartan los más antiguos que nunca han llegado a autorizarse.
	if ( count( $clients ) >= 200 ) {
		uasort( $clients, function ( $a, $b ) {
			return ( $a['used'] ?? 0 ) <=> ( $b['used'] ?? 0 ) ?: $a['created'] <=> $b['created'];
		} );
		$clients = array_slice( $clients, -150, null, true );
	}
	$id             = 'egc_' . wp_generate_password( 24, false, false );
	$name           = mb_substr( sanitize_text_field( (string) ( $p['client_name'] ?? '' ) ), 0, 80 ) ?: 'Asistente';
	$clients[ $id ] = array(
		'name'          => $name,
		'redirect_uris' => $uris,
		'created'       => time(),
		'used'          => 0,
	);
	update_option( EG_PUB_AI_CLIENTS, $clients, false );
	$res = new WP_REST_Response( array(
		'client_id'                  => $id,
		'client_id_issued_at'        => time(),
		'client_name'                => $name,
		'redirect_uris'              => $uris,
		'grant_types'                => array( 'authorization_code', 'refresh_token' ),
		'response_types'             => array( 'code' ),
		'token_endpoint_auth_method' => 'none',
	), 201 );
	$res->header( 'Cache-Control', 'no-store' );
	return $res;
}

/* ======================================================================
 * Pantalla de autorización (admin-post.php?action=eg_ai_authorize)
 * ==================================================================== */

add_action( 'admin_post_nopriv_eg_ai_authorize', function () {
	// Sin sesión: a iniciar sesión y volver aquí con los mismos parámetros.
	$back = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', array_filter( wp_unslash( $_GET ), 'is_scalar' ) ) ), admin_url( 'admin-post.php' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	wp_safe_redirect( wp_login_url( $back ) );
	exit;
} );

add_action( 'admin_post_eg_ai_authorize', 'eg_pub_ai_oauth_authorize_page' );

/** Valida la petición de autorización. Devuelve array con los datos o WP_Error (sin redirección posible). */
function eg_pub_ai_oauth_request( $q ) {
	if ( ! eg_pub_ai_enabled() ) {
		return new WP_Error( 'off', 'El servicio Asistente IA no está activo en esta web.' );
	}
	$clients = eg_pub_ai_oauth_clients();
	$cid     = (string) ( $q['client_id'] ?? '' );
	if ( ! isset( $clients[ $cid ] ) ) {
		return new WP_Error( 'client', 'Aplicación desconocida. Vuelve a añadir el conector en tu asistente.' );
	}
	$redirect = (string) ( $q['redirect_uri'] ?? '' );
	if ( '' === $redirect && 1 === count( $clients[ $cid ]['redirect_uris'] ) ) {
		$redirect = $clients[ $cid ]['redirect_uris'][0];
	}
	if ( ! in_array( $redirect, $clients[ $cid ]['redirect_uris'], true ) ) {
		return new WP_Error( 'redirect', 'La dirección de vuelta no coincide con la registrada.' );
	}
	return array(
		'client_id'      => $cid,
		'client'         => $clients[ $cid ],
		'redirect_uri'   => $redirect,
		'state'          => (string) ( $q['state'] ?? '' ),
		'response_type'  => (string) ( $q['response_type'] ?? '' ),
		'challenge'      => (string) ( $q['code_challenge'] ?? '' ),
		'method'         => (string) ( $q['code_challenge_method'] ?? '' ),
		'scope'          => preg_split( '/[\s,+]+/', (string) ( $q['scope'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ),
		'resource'       => (string) ( $q['resource'] ?? '' ),
	);
}

/** Vuelve a la aplicación con un error OAuth. */
function eg_pub_ai_oauth_redirect_error( $req, $error, $description ) {
	$url = add_query_arg( array_map( 'rawurlencode', array_filter( array(
		'error'             => $error,
		'error_description' => $description,
		'state'             => $req['state'],
		'iss'               => eg_pub_ai_issuer(),
	), 'strlen' ) ), $req['redirect_uri'] );
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- redirect_uri registrada por la aplicación.
	exit;
}

function eg_pub_ai_oauth_authorize_page() {
	$q   = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ? wp_unslash( $_POST ) : wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
	$req = eg_pub_ai_oauth_request( $q );
	if ( is_wp_error( $req ) ) {
		wp_die( esc_html( $req->get_error_message() ), 'Asistente IA', array( 'response' => 400 ) );
	}
	if ( 'code' !== $req['response_type'] ) {
		eg_pub_ai_oauth_redirect_error( $req, 'unsupported_response_type', 'Solo response_type=code.' );
	}
	if ( '' === $req['challenge'] || 'S256' !== $req['method'] ) {
		eg_pub_ai_oauth_redirect_error( $req, 'invalid_request', 'Hace falta PKCE con S256.' );
	}

	$user   = wp_get_current_user();
	$scopes = eg_pub_ai_scopes();
	$mine   = array_values( array_filter( eg_pub_ai_available_scopes(), function ( $s ) use ( $user ) {
		return eg_pub_ai_user_can_scope( $user->ID, $s );
	} ) );
	$asked  = array_values( array_intersect( $req['scope'], $mine ) );
	$asked  = $asked ?: $mine;

	if ( isset( $q['eg_ai_decision'] ) ) {
		check_admin_referer( 'eg_ai_authorize_' . $req['client_id'] );
		if ( 'allow' !== $q['eg_ai_decision'] ) {
			eg_pub_ai_oauth_redirect_error( $req, 'access_denied', 'La persona ha cancelado la autorización.' );
		}
		$chosen = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $q['eg_ai_scopes'] ?? array() ) ), $mine ) );
		if ( ! $chosen ) {
			eg_pub_ai_oauth_redirect_error( $req, 'access_denied', 'No se ha autorizado ningún permiso.' );
		}
		$code = wp_generate_password( 48, false, false );
		set_transient( 'eg_ai_code_' . hash( 'sha256', $code ), array(
			'client_id'    => $req['client_id'],
			'redirect_uri' => $req['redirect_uri'],
			'challenge'    => $req['challenge'],
			'scopes'       => $chosen,
			'user_id'      => $user->ID,
		), 10 * MINUTE_IN_SECONDS );
		wp_redirect( add_query_arg( array_map( 'rawurlencode', array_filter( array( 'code' => $code, 'state' => $req['state'], 'iss' => eg_pub_ai_issuer() ), 'strlen' ) ), $req['redirect_uri'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	header( 'X-Frame-Options: DENY' );
	header( 'Content-Security-Policy: frame-ancestors \'none\'' );
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$host = (string) wp_parse_url( $req['redirect_uri'], PHP_URL_HOST );
	?>
	<!doctype html>
	<html <?php language_attributes(); ?>>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex">
		<title>Autorizar asistente · <?php echo esc_html( $site ); ?></title>
		<style>
			body{margin:0;background:#f0f0f1;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}
			.box{max-width:440px;margin:8vh auto;background:#fff;border-radius:10px;padding:28px 28px 22px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
			h1{font-size:20px;margin:0 0 6px}.muted{color:#646970;font-size:13px}
			label{display:block;margin:10px 0;padding:10px 12px;border:1px solid #dcdcde;border-radius:6px}
			.actions{display:flex;gap:10px;margin-top:20px}
			button{flex:1;padding:10px;border-radius:6px;border:1px solid #2271b1;font-size:15px;cursor:pointer}
			.allow{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}
		</style>
	</head>
	<body>
		<div class="box">
			<h1>Conectar «<?php echo esc_html( $req['client']['name'] ); ?>»</h1>
			<p class="muted">con <?php echo esc_html( $site ); ?> como <strong><?php echo esc_html( $user->display_name ); ?></strong> · vuelve a <?php echo esc_html( $host ); ?></p>
			<?php if ( ! $mine ) : ?>
				<p>Tu usuario no tiene permisos para ningún apartado de este servicio. Pide acceso a un administrador de la web.</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'eg_ai_authorize_' . $req['client_id'] ); ?>
					<input type="hidden" name="action" value="eg_ai_authorize">
					<?php foreach ( array( 'client_id', 'redirect_uri', 'state', 'response_type', 'resource' ) as $k ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $req[ $k ] ); ?>">
					<?php endforeach; ?>
					<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $req['challenge'] ); ?>">
					<input type="hidden" name="code_challenge_method" value="S256">
					<p>Tu asistente podrá:</p>
					<?php foreach ( $mine as $s ) : ?>
						<label><input type="checkbox" name="eg_ai_scopes[]" value="<?php echo esc_attr( $s ); ?>" <?php checked( in_array( $s, $asked, true ) ); ?>> <strong><?php echo esc_html( $scopes[ $s ]['label'] ); ?></strong>: <?php echo esc_html( $scopes[ $s ]['description'] ); ?></label>
					<?php endforeach; ?>
					<p class="muted">Nunca podrá borrar contenido ni tocar páginas, ajustes o usuarios. Puedes revocar el acceso cuando quieras en el escritorio, menú «Asistente IA».</p>
					<div class="actions">
						<button class="deny" name="eg_ai_decision" value="deny">Cancelar</button>
						<button class="allow" name="eg_ai_decision" value="allow">Autorizar</button>
					</div>
				</form>
			<?php endif; ?>
		</div>
	</body>
	</html>
	<?php
	exit;
}

/* ======================================================================
 * Tokens
 * ==================================================================== */

/** Emite un par de tokens nuevo para una conexión oauth y lo guarda (solo hashes). */
function eg_pub_ai_oauth_issue( $id ) {
	$access  = EG_PUB_AI_ACCESS_PREFIX . wp_generate_password( 40, false, false );
	$refresh = EG_PUB_AI_REFRESH_PREFIX . wp_generate_password( 48, false, false );
	$all     = eg_pub_ai_connections();
	$all[ $id ]['hash']        = eg_pub_ai_token_hash( $access );
	$all[ $id ]['expires']     = time() + EG_PUB_AI_ACCESS_TTL;
	$all[ $id ]['refresh']     = eg_pub_ai_token_hash( $refresh );
	$all[ $id ]['refresh_exp'] = time() + EG_PUB_AI_REFRESH_TTL;
	$all[ $id ]['hint']        = substr( $access, -4 );
	update_option( EG_PUB_AI_CONNECTIONS, $all, false );
	$res = new WP_REST_Response( array(
		'access_token'  => $access,
		'token_type'    => 'Bearer',
		'expires_in'    => EG_PUB_AI_ACCESS_TTL,
		'refresh_token' => $refresh,
		'scope'         => implode( ' ', $all[ $id ]['scopes'] ),
	), 200 );
	$res->header( 'Cache-Control', 'no-store' );
	$res->header( 'Pragma', 'no-cache' );
	return $res;
}

function eg_pub_ai_oauth_token( WP_REST_Request $r ) {
	$p     = (array) $r->get_body_params() + (array) $r->get_json_params();
	$grant = (string) ( $p['grant_type'] ?? '' );
	$cid   = (string) ( $p['client_id'] ?? '' );
	if ( '' === $cid && preg_match( '/^Basic\s+(\S+)$/i', (string) $r->get_header( 'authorization' ), $m ) ) {
		$cid = (string) strtok( (string) base64_decode( $m[1] ), ':' );
	}

	if ( 'authorization_code' === $grant ) {
		$key  = 'eg_ai_code_' . hash( 'sha256', (string) ( $p['code'] ?? '' ) );
		$code = get_transient( $key );
		delete_transient( $key ); // Un solo uso, también si falla.
		if ( ! is_array( $code ) ) {
			return eg_pub_ai_oauth_error( 'invalid_grant', 'Código caducado o ya usado.' );
		}
		if ( $code['client_id'] !== $cid || ( isset( $p['redirect_uri'] ) && $code['redirect_uri'] !== (string) $p['redirect_uri'] ) ) {
			return eg_pub_ai_oauth_error( 'invalid_grant', 'El código no corresponde a esta aplicación.' );
		}
		$verifier  = (string) ( $p['code_verifier'] ?? '' );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( '' === $verifier || ! hash_equals( $code['challenge'], $challenge ) ) {
			return eg_pub_ai_oauth_error( 'invalid_grant', 'code_verifier no válido.' );
		}
		$clients = eg_pub_ai_oauth_clients();
		if ( ! isset( $clients[ $cid ] ) ) {
			return eg_pub_ai_oauth_error( 'invalid_client', 'Aplicación desconocida.' );
		}
		$clients[ $cid ]['used'] = time();
		update_option( EG_PUB_AI_CLIENTS, $clients, false );
		// Nueva conexión de tipo oauth.
		$id  = substr( md5( $cid . microtime() . wp_rand() ), 0, 10 );
		$all = eg_pub_ai_connections();
		$all[ $id ] = array(
			'label'     => $clients[ $cid ]['name'],
			'type'      => 'oauth',
			'client_id' => $cid,
			'user_id'   => (int) $code['user_id'],
			'scopes'    => $code['scopes'],
			'hash'      => '',
			'hint'      => '',
			'created'   => time(),
			'last_used' => 0,
			'calls'     => 0,
		);
		update_option( EG_PUB_AI_CONNECTIONS, $all, false );
		wp_set_current_user( (int) $code['user_id'] );
		eg_pub_ai_log_add( $id, 'connection.authorized', 0, $clients[ $cid ]['name'] . ' (' . implode( ', ', $code['scopes'] ) . ')' );
		return eg_pub_ai_oauth_issue( $id );
	}

	if ( 'refresh_token' === $grant ) {
		$hash = eg_pub_ai_token_hash( (string) ( $p['refresh_token'] ?? '' ) );
		foreach ( eg_pub_ai_connections() as $id => $c ) {
			if ( 'oauth' === ( $c['type'] ?? '' ) && ! empty( $c['refresh'] ) && hash_equals( $c['refresh'], $hash ) ) {
				if ( ( $c['refresh_exp'] ?? 0 ) < time() ) {
					return eg_pub_ai_oauth_error( 'invalid_grant', 'Sesión caducada: vuelve a conectar.' );
				}
				if ( '' !== $cid && $cid !== $c['client_id'] ) {
					return eg_pub_ai_oauth_error( 'invalid_grant', 'El token no corresponde a esta aplicación.' );
				}
				if ( ! get_userdata( (int) $c['user_id'] ) ) {
					return eg_pub_ai_oauth_error( 'invalid_grant', 'El usuario ya no existe.' );
				}
				return eg_pub_ai_oauth_issue( $id );
			}
		}
		return eg_pub_ai_oauth_error( 'invalid_grant', 'Token de refresco no válido o revocado.' );
	}

	return eg_pub_ai_oauth_error( 'unsupported_grant_type', 'grant_type admitidos: authorization_code, refresh_token.' );
}
