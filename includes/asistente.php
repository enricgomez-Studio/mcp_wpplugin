<?php
/**
 * Servicio «Asistente IA» (perfil cliente): un conector MCP propio de cada web para que el cliente gestione su
 * blog y sus productos desde su propio Claude, ChatGPT u otro asistente compatible con MCP.
 *
 *   POST /wp-json/eg/v1/mcp/<clave>   servidor MCP remoto (Streamable HTTP, sin estado). La clave identifica la
 *                                     conexión; también vale Authorization: Bearer <clave> en /wp-json/eg/v1/mcp.
 *   POST /wp-json/eg/v1/assistant     (X-EG-Key, tipología Gestión de la web) lo gestionamos nosotros desde Make:
 *       {}                                   estado: servicio, ámbitos, conexiones y últimas acciones
 *       {"enabled": true, "scopes": ["blog", "productos"], "publish": true, "notes": "…"}
 *                                            activa o apaga el servicio, qué ámbitos ha contratado la web, si el
 *                                            asistente puede publicar (si no, todo queda en borrador o pendiente)
 *                                            y notas de estilo de la web que el asistente recibe al conectarse
 *       {"create": {"label": "Claude de Marta", "user": "marta@…", "scopes": ["blog"]}}
 *                                            crea una conexión y devuelve su URL (solo esta vez)
 *       {"revoke": "<id>"}                   revoca una conexión
 *       {"log": 50}                          últimas acciones del asistente (hasta 200)
 *
 * El servicio nace apagado en todas las webs: el servidor MCP no existe (404) hasta que lo activamos. Cada
 * conexión va ligada a un usuario de WordPress y a unos ámbitos (Blog, Productos), y solo puede usar las
 * herramientas de esos ámbitos con los permisos de ese usuario. No puede borrar nada ni tocar páginas, ajustes,
 * plugins ni usuarios. El cliente crea y revoca sus conexiones en el menú «Asistente IA» del escritorio.
 * Las herramientas están en includes/asistente-herramientas.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_AI_OPTION      = 'eg_pub_ai_service';
const EG_PUB_AI_CONNECTIONS = 'eg_pub_ai_connections';
const EG_PUB_AI_LOG         = 'eg_pub_ai_log';
const EG_PUB_AI_TOKEN_PREFIX = 'egai_';
const EG_PUB_AI_RATE_LIMIT  = 60; // Llamadas por minuto y conexión.

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['asistente'] = array( 'type' => 'gestion', 'routes' => array( 'assistant' ) );
	return $modules;
} );

/** Ámbitos del servicio: slug => array( label, description, requires ). */
function eg_pub_ai_scopes() {
	return array(
		'blog'      => array(
			'label'       => 'Blog',
			'description' => 'Crear y editar posts del blog, con categorías, etiquetas, imágenes y SEO.',
		),
		'productos' => array(
			'label'       => 'Productos',
			'description' => 'Dar de alta y editar productos de la tienda: textos, precios, stock, categorías e imágenes.',
			'requires'    => 'WC_VERSION',
		),
	);
}

/** Ajustes del servicio en esta web. */
function eg_pub_ai_service() {
	$s = get_option( EG_PUB_AI_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'enabled' => ! empty( $s['enabled'] ),
		'scopes'  => array_values( array_intersect( (array) ( $s['scopes'] ?? array() ), array_keys( eg_pub_ai_scopes() ) ) ),
		'publish' => ! isset( $s['publish'] ) || ! empty( $s['publish'] ),
		'notes'   => (string) ( $s['notes'] ?? '' ),
		'updated' => (int) ( $s['updated'] ?? 0 ),
	);
}

function eg_pub_ai_enabled() {
	return eg_pub_ai_service()['enabled'];
}

/** Ámbitos contratados que esta web puede usar ahora (Productos solo con WooCommerce activo). */
function eg_pub_ai_available_scopes() {
	$scopes = eg_pub_ai_scopes();
	return array_values( array_filter( eg_pub_ai_service()['scopes'], function ( $slug ) use ( $scopes ) {
		return empty( $scopes[ $slug ]['requires'] ) || defined( $scopes[ $slug ]['requires'] );
	} ) );
}

/** Si el usuario tiene los permisos mínimos de un ámbito. */
function eg_pub_ai_user_can_scope( $user_id, $scope ) {
	if ( 'productos' === $scope ) {
		return user_can( $user_id, 'edit_products' );
	}
	return user_can( $user_id, 'edit_posts' );
}

/* ======================================================================
 * Conexiones
 * ==================================================================== */

function eg_pub_ai_connections() {
	$c = get_option( EG_PUB_AI_CONNECTIONS, array() );
	return is_array( $c ) ? $c : array();
}

function eg_pub_ai_token_hash( $token ) {
	return hash( 'sha256', (string) $token );
}

/** URL del servidor MCP de una clave. */
function eg_pub_ai_mcp_url( $token ) {
	return rest_url( 'eg/v1/mcp/' . $token );
}

/**
 * Crea una conexión. Devuelve array( 'id', 'token', 'url' ) o WP_Error.
 */
function eg_pub_ai_create_connection( $user_id, $label, $scopes ) {
	$user = get_userdata( (int) $user_id );
	if ( ! $user ) {
		return new WP_Error( 'eg_ai_user', 'No existe ese usuario.' );
	}
	$scopes = array_values( array_intersect( (array) $scopes, eg_pub_ai_available_scopes() ) );
	$scopes = array_values( array_filter( $scopes, function ( $s ) use ( $user ) {
		return eg_pub_ai_user_can_scope( $user->ID, $s );
	} ) );
	if ( ! $scopes ) {
		return new WP_Error( 'eg_ai_scopes', 'Ningún ámbito válido: el servicio no lo incluye o el usuario no tiene permisos para él.' );
	}
	$token = EG_PUB_AI_TOKEN_PREFIX . wp_generate_password( 40, false, false );
	$id    = substr( md5( $token . microtime() ), 0, 10 );
	$all   = eg_pub_ai_connections();
	$all[ $id ] = array(
		'label'     => sanitize_text_field( $label ) ?: 'Asistente',
		'user_id'   => $user->ID,
		'scopes'    => $scopes,
		'hash'      => eg_pub_ai_token_hash( $token ),
		'hint'      => substr( $token, -4 ),
		'created'   => time(),
		'last_used' => 0,
		'calls'     => 0,
	);
	update_option( EG_PUB_AI_CONNECTIONS, $all, false );
	eg_pub_ai_log_add( $id, 'connection.created', 0, $all[ $id ]['label'] . ' (' . implode( ', ', $scopes ) . ')' );
	return array( 'id' => $id, 'token' => $token, 'url' => eg_pub_ai_mcp_url( $token ) );
}

function eg_pub_ai_revoke_connection( $id ) {
	$all = eg_pub_ai_connections();
	if ( ! isset( $all[ $id ] ) ) {
		return false;
	}
	eg_pub_ai_log_add( $id, 'connection.revoked', 0, $all[ $id ]['label'] );
	unset( $all[ $id ] );
	update_option( EG_PUB_AI_CONNECTIONS, $all, false );
	return true;
}

/** Busca la conexión de una clave. Devuelve array( id, conexión ) o null. */
function eg_pub_ai_find_connection( $token ) {
	$token = (string) $token;
	if ( 0 !== strpos( $token, EG_PUB_AI_TOKEN_PREFIX ) ) {
		return null;
	}
	$hash = eg_pub_ai_token_hash( $token );
	foreach ( eg_pub_ai_connections() as $id => $c ) {
		if ( ! empty( $c['hash'] ) && hash_equals( $c['hash'], $hash ) ) {
			return array( $id, $c );
		}
	}
	return null;
}

/** Datos públicos de una conexión (sin el hash). */
function eg_pub_ai_connection_public( $id, $c ) {
	$user = get_userdata( (int) $c['user_id'] );
	return array(
		'id'        => $id,
		'label'     => $c['label'],
		'user'      => $user ? $user->user_login : null,
		'scopes'    => $c['scopes'],
		'key_end'   => '…' . $c['hint'],
		'created'   => $c['created'] ? wp_date( 'Y-m-d H:i', $c['created'] ) : null,
		'last_used' => $c['last_used'] ? wp_date( 'Y-m-d H:i', $c['last_used'] ) : null,
		'calls'     => (int) $c['calls'],
	);
}

/* ======================================================================
 * Registro de acciones
 * ==================================================================== */

function eg_pub_ai_log_add( $connection_id, $action, $object_id = 0, $summary = '' ) {
	$log = get_option( EG_PUB_AI_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array(
		'time'       => time(),
		'connection' => (string) $connection_id,
		'user'       => get_current_user_id(),
		'action'     => (string) $action,
		'object'     => (int) $object_id,
		'summary'    => mb_substr( (string) $summary, 0, 200 ),
	) );
	update_option( EG_PUB_AI_LOG, array_slice( $log, 0, 200 ), false );
}

function eg_pub_ai_log( $limit = 50, $user_id = 0 ) {
	$log = get_option( EG_PUB_AI_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	if ( $user_id ) {
		$log = array_values( array_filter( $log, function ( $e ) use ( $user_id ) {
			return (int) $e['user'] === (int) $user_id;
		} ) );
	}
	$conns = eg_pub_ai_connections();
	return array_map( function ( $e ) use ( $conns ) {
		$e['when']  = wp_date( 'Y-m-d H:i', $e['time'] );
		$e['label'] = $conns[ $e['connection'] ]['label'] ?? '';
		return $e;
	}, array_slice( $log, 0, max( 1, min( 200, (int) $limit ) ) ) );
}

/* ======================================================================
 * Rutas REST
 * ==================================================================== */

add_action( 'rest_api_init', function () {
	if ( eg_pub_module_enabled( 'asistente' ) ) {
		register_rest_route( 'eg/v1', '/assistant', array(
			'methods'             => 'POST',
			'callback'            => 'eg_pub_ai_admin_handle',
			'permission_callback' => eg_pub_module_permission( 'manage_options' ),
		) );
	}
	if ( eg_pub_ai_enabled() ) {
		register_rest_route( 'eg/v1', '/mcp(?:/(?P<token>[A-Za-z0-9_]+))?', array(
			'methods'             => array( 'GET', 'POST', 'DELETE' ),
			'callback'            => 'eg_pub_ai_mcp_handle',
			'permission_callback' => '__return_true', // La clave se comprueba dentro (respuesta de error en JSON-RPC).
		) );
	}
} );

/** POST /assistant: lo gestionamos nosotros (Make) con la clave X-EG-Key. */
function eg_pub_ai_admin_handle( WP_REST_Request $r ) {
	$p     = (array) $r->get_json_params() + (array) $r->get_body_params();
	$notes = array();
	$out   = array( 'ok' => true );

	$keys = array( 'enabled', 'scopes', 'publish', 'notes' );
	if ( array_intersect( $keys, array_keys( $p ) ) ) {
		$s = get_option( EG_PUB_AI_OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		if ( array_key_exists( 'enabled', $p ) ) {
			$s['enabled'] = (bool) $p['enabled'];
		}
		if ( array_key_exists( 'scopes', $p ) ) {
			$bad = array_diff( (array) $p['scopes'], array_keys( eg_pub_ai_scopes() ) );
			if ( $bad ) {
				return eg_pub_error( 'eg_ai_scopes', 'Ámbitos desconocidos: ' . implode( ', ', $bad ) . '. Válidos: ' . implode( ', ', array_keys( eg_pub_ai_scopes() ) ) . '.' );
			}
			$s['scopes'] = array_values( array_unique( (array) $p['scopes'] ) );
		}
		if ( array_key_exists( 'publish', $p ) ) {
			$s['publish'] = (bool) $p['publish'];
		}
		if ( array_key_exists( 'notes', $p ) ) {
			$s['notes'] = sanitize_textarea_field( (string) $p['notes'] );
		}
		$s['updated'] = time();
		update_option( EG_PUB_AI_OPTION, $s, false );
		// Las conexiones pierden los ámbitos que ya no están contratados.
		$service = eg_pub_ai_service();
		$all     = eg_pub_ai_connections();
		foreach ( $all as $id => $c ) {
			$all[ $id ]['scopes'] = array_values( array_intersect( $c['scopes'], $service['scopes'] ) );
		}
		update_option( EG_PUB_AI_CONNECTIONS, $all, false );
		$out['saved'] = true;
		if ( $service['enabled'] && ! eg_pub_ai_available_scopes() ) {
			$notes[] = 'El servicio está activo pero sin ningún ámbito que esta web pueda usar: el asistente no tendrá herramientas.';
		}
	}

	if ( ! empty( $p['create'] ) && is_array( $p['create'] ) ) {
		$c   = $p['create'];
		$ref = (string) ( $c['user'] ?? '' );
		$u   = is_numeric( $ref ) ? get_userdata( (int) $ref ) : ( get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref ) );
		if ( ! $u ) {
			return eg_pub_error( 'eg_ai_user', 'Falta create.user (ID, usuario o email de un usuario de la web).' );
		}
		if ( ! eg_pub_ai_enabled() ) {
			return eg_pub_error( 'eg_ai_off', 'Activa antes el servicio con "enabled": true.' );
		}
		$created = eg_pub_ai_create_connection( $u->ID, (string) ( $c['label'] ?? '' ), $c['scopes'] ?? eg_pub_ai_available_scopes() );
		if ( is_wp_error( $created ) ) {
			return eg_pub_error( $created->get_error_code(), $created->get_error_message() );
		}
		$out['created'] = $created;
		$notes[]        = 'La URL solo se muestra ahora: pásala al cliente por un canal privado.';
	}

	if ( ! empty( $p['revoke'] ) ) {
		$out['revoked'] = eg_pub_ai_revoke_connection( sanitize_key( (string) $p['revoke'] ) );
	}

	$service = eg_pub_ai_service();
	$out['service'] = array(
		'enabled'          => $service['enabled'],
		'scopes'           => $service['scopes'],
		'available_scopes' => eg_pub_ai_available_scopes(),
		'publish'          => $service['publish'],
		'notes'            => $service['notes'],
		'updated'          => $service['updated'] ? wp_date( 'Y-m-d H:i', $service['updated'] ) : null,
		'mcp_base'         => rest_url( 'eg/v1/mcp/' ),
	);
	$out['connections'] = array();
	foreach ( eg_pub_ai_connections() as $id => $c ) {
		$out['connections'][] = eg_pub_ai_connection_public( $id, $c );
	}
	$out['log'] = eg_pub_ai_log( isset( $p['log'] ) ? (int) $p['log'] : 10 );
	if ( $notes ) {
		$out['notes'] = $notes;
	}
	return new WP_REST_Response( $out, 200 );
}

/* ======================================================================
 * Servidor MCP (Streamable HTTP, sin estado: cada POST lleva un mensaje JSON-RPC o un lote)
 * ==================================================================== */

const EG_PUB_AI_PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

function eg_pub_ai_rpc_error( $id, $code, $message ) {
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) );
}

/** Respuesta HTTP del servidor MCP; null = 202 sin cuerpo (solo notificaciones). */
function eg_pub_ai_http( $data, $status = 200, $headers = array() ) {
	$response = new WP_REST_Response( $data, $status );
	$response->header( 'Cache-Control', 'no-store' );
	foreach ( $headers as $k => $v ) {
		$response->header( $k, $v );
	}
	return $response;
}

// Un 202 sin cuerpo: WordPress escribiría «null».
add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
	if ( ! $served && 0 === strpos( $request->get_route(), '/eg/v1/mcp' ) && 202 === $result->get_status() && null === $result->get_data() ) {
		return true;
	}
	return $served;
}, 10, 3 );

function eg_pub_ai_mcp_handle( WP_REST_Request $r ) {
	if ( 'POST' !== $r->get_method() ) {
		// Sin flujo SSE por GET ni sesiones que cerrar: el servidor no guarda estado.
		return eg_pub_ai_http( array( 'error' => 'Usa POST.' ), 405, array( 'Allow' => 'POST' ) );
	}

	$token = (string) $r->get_param( 'token' );
	if ( '' === $token && preg_match( '/^Bearer\s+(\S+)$/i', (string) $r->get_header( 'authorization' ), $m ) ) {
		$token = $m[1];
	}
	$found = '' !== $token ? eg_pub_ai_find_connection( $token ) : null;
	if ( ! $found ) {
		return eg_pub_ai_http( eg_pub_ai_rpc_error( null, -32001, 'Conexión no válida o revocada. Crea una nueva en el escritorio de la web, menú «Asistente IA».' ), 401, array( 'WWW-Authenticate' => 'Bearer realm="eg-asistente"' ) );
	}
	list( $conn_id, $conn ) = $found;
	$user = get_userdata( (int) $conn['user_id'] );
	if ( ! $user ) {
		return eg_pub_ai_http( eg_pub_ai_rpc_error( null, -32001, 'El usuario de esta conexión ya no existe.' ), 401 );
	}
	wp_set_current_user( $user->ID );

	// Límite de llamadas por minuto.
	$rate_key = 'eg_ai_rate_' . $conn_id . '_' . gmdate( 'YmdHi' );
	$calls    = (int) get_transient( $rate_key ) + 1;
	set_transient( $rate_key, $calls, 120 );
	if ( $calls > EG_PUB_AI_RATE_LIMIT ) {
		return eg_pub_ai_http( eg_pub_ai_rpc_error( null, -32002, 'Demasiadas peticiones seguidas. Espera un minuto.' ), 429, array( 'Retry-After' => '60' ) );
	}

	$body = json_decode( $r->get_body(), true );
	if ( ! is_array( $body ) ) {
		return eg_pub_ai_http( eg_pub_ai_rpc_error( null, -32700, 'JSON no válido.' ), 400 );
	}

	$ctx = array( 'id' => $conn_id, 'conn' => $conn, 'scopes' => array_values( array_intersect( $conn['scopes'], eg_pub_ai_available_scopes() ) ) );

	$batch     = array_keys( $body ) === range( 0, count( $body ) - 1 );
	$messages  = $batch ? $body : array( $body );
	$responses = array();
	foreach ( $messages as $msg ) {
		$res = eg_pub_ai_rpc_dispatch( is_array( $msg ) ? $msg : array(), $ctx );
		if ( null !== $res ) {
			$responses[] = $res;
		}
	}

	// Uso de la conexión (una escritura por petición).
	$all = eg_pub_ai_connections();
	if ( isset( $all[ $conn_id ] ) ) {
		$all[ $conn_id ]['last_used'] = time();
		$all[ $conn_id ]['calls']     = (int) $all[ $conn_id ]['calls'] + 1;
		update_option( EG_PUB_AI_CONNECTIONS, $all, false );
	}

	if ( ! $responses ) {
		return eg_pub_ai_http( null, 202 );
	}
	return eg_pub_ai_http( $batch ? $responses : $responses[0] );
}

/** Procesa un mensaje JSON-RPC. Devuelve la respuesta, o null si es una notificación. */
function eg_pub_ai_rpc_dispatch( $msg, $ctx ) {
	$has_id = array_key_exists( 'id', $msg );
	$id     = $has_id ? $msg['id'] : null;
	$method = (string) ( $msg['method'] ?? '' );
	$params = is_array( $msg['params'] ?? null ) ? $msg['params'] : array();

	if ( ! $has_id ) {
		return null; // notifications/initialized, notifications/cancelled…
	}
	if ( '' === $method ) {
		return eg_pub_ai_rpc_error( $id, -32600, 'Petición no válida.' );
	}

	switch ( $method ) {
		case 'initialize':
			$asked    = (string) ( $params['protocolVersion'] ?? '' );
			$protocol = in_array( $asked, EG_PUB_AI_PROTOCOLS, true ) ? $asked : EG_PUB_AI_PROTOCOLS[0];
			$result   = array(
				'protocolVersion' => $protocol,
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
				'serverInfo'      => array(
					'name'    => 'eg-asistente',
					'title'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'version' => EG_PUB_VERSION,
				),
				'instructions'    => eg_pub_ai_instructions( $ctx ),
			);
			break;
		case 'ping':
			$result = new stdClass();
			break;
		case 'tools/list':
			$result = array( 'tools' => eg_pub_ai_tools_list( $ctx ) );
			break;
		case 'tools/call':
			$result = eg_pub_ai_tools_call( (string) ( $params['name'] ?? '' ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array(), $ctx );
			if ( is_wp_error( $result ) ) {
				return eg_pub_ai_rpc_error( $id, -32602, $result->get_error_message() );
			}
			break;
		case 'resources/list':
			$result = array( 'resources' => array() );
			break;
		case 'prompts/list':
			$result = array( 'prompts' => array() );
			break;
		default:
			return eg_pub_ai_rpc_error( $id, -32601, 'Método no disponible: ' . $method );
	}
	return array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result );
}

/** Instrucciones que recibe el asistente al conectarse. */
function eg_pub_ai_instructions( $ctx ) {
	$service = eg_pub_ai_service();
	$lines   = array(
		'Eres el asistente de contenidos de la web «' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '» (' . home_url( '/' ) . ').',
		'Puedes: ' . implode( '; ', array_map( function ( $s ) {
			return rtrim( eg_pub_ai_scopes()[ $s ]['description'], '.' );
		}, $ctx['scopes'] ) ) . '. No puedes borrar contenido ni cambiar páginas, ajustes, plugins o usuarios.',
		'Empieza llamando a site_info para conocer los idiomas, las categorías y las reglas de la web.',
		'Crea siempre en borrador salvo que la persona pida publicar expresamente; antes de publicar, resume lo que vas a publicar y pide confirmación.',
		'Escribe el contenido en HTML sencillo (h2, h3, p, ul, ol, li, strong, em, a, img), sin h1: el título ya es el h1. No uses Markdown.',
		'Para las imágenes usa URLs públicas (o base64) en las herramientas; se suben a la biblioteca de la web.',
		'Al terminar, da a la persona el enlace de edición o de vista previa que devuelve la herramienta.',
	);
	if ( ! $service['publish'] ) {
		$lines[] = 'En esta web no puedes publicar: todo queda en borrador o pendiente de revisión.';
	}
	if ( '' !== $service['notes'] ) {
		$lines[] = 'Reglas de esta web: ' . $service['notes'];
	}
	return implode( "\n", $lines );
}

/* ======================================================================
 * Pantalla «Asistente IA» del escritorio (para el cliente)
 * ==================================================================== */

add_action( 'admin_menu', function () {
	if ( ! eg_pub_ai_enabled() ) {
		return;
	}
	add_menu_page( 'Asistente IA', 'Asistente IA', 'edit_posts', 'eg-asistente', 'eg_pub_ai_admin_page', 'dashicons-format-chat', 58 );
} );

function eg_pub_ai_admin_page() {
	if ( ! current_user_can( 'edit_posts' ) || ! eg_pub_ai_enabled() ) {
		return;
	}
	$me      = get_current_user_id();
	$admin   = current_user_can( 'manage_options' );
	$scopes  = eg_pub_ai_scopes();
	$mine    = array_values( array_filter( eg_pub_ai_available_scopes(), function ( $s ) use ( $me ) {
		return eg_pub_ai_user_can_scope( $me, $s );
	} ) );
	$created = null;
	$error   = '';

	if ( isset( $_POST['eg_ai_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eg_ai_nonce'] ) ), 'eg_ai' ) ) {
		if ( ! empty( $_POST['eg_ai_create'] ) ) {
			$res = eg_pub_ai_create_connection( $me, sanitize_text_field( wp_unslash( $_POST['eg_ai_label'] ?? '' ) ), array_map( 'sanitize_key', (array) wp_unslash( $_POST['eg_ai_scopes'] ?? array() ) ) );
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
			} else {
				$created = $res;
			}
		}
		if ( ! empty( $_POST['eg_ai_revoke'] ) ) {
			$id  = sanitize_key( wp_unslash( $_POST['eg_ai_revoke'] ) );
			$all = eg_pub_ai_connections();
			if ( isset( $all[ $id ] ) && ( $admin || (int) $all[ $id ]['user_id'] === $me ) ) {
				eg_pub_ai_revoke_connection( $id );
				echo '<div class="updated"><p>Conexión revocada. Ese asistente ya no puede entrar en la web.</p></div>';
			}
		}
	}

	$conns = array_filter( eg_pub_ai_connections(), function ( $c ) use ( $admin, $me ) {
		return $admin || (int) $c['user_id'] === $me;
	} );
	?>
	<div class="wrap">
		<h1>Asistente IA</h1>
		<p style="max-width:52em">Conecta tu asistente (Claude, ChatGPT u otro compatible con conectores MCP) para que pueda <?php echo esc_html( implode( ' y ', array_map( function ( $s ) use ( $scopes ) {
			return lcfirst( rtrim( $scopes[ $s ]['description'], '.' ) );
		}, $mine ?: array( 'blog' ) ) ) ); ?>. Lo que haga queda registrado abajo y, salvo que se lo pidas, lo deja en borrador para que lo revises.</p>

		<?php if ( $error ) : ?>
			<div class="error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>

		<?php if ( $created ) : ?>
			<div class="notice notice-success" style="padding:12px 16px">
				<p><strong>Conexión creada.</strong> Copia esta dirección ahora: por seguridad no se vuelve a mostrar. Es personal, no la compartas.</p>
				<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $created['url'] ); ?>" onclick="this.select()"></p>
				<p><strong>En Claude</strong> (web o app de escritorio): Ajustes → Conectores → «Añadir conector personalizado». Ponle un nombre, pega la dirección y pulsa «Añadir». Después actívalo en el chat desde el menú de herramientas.</p>
				<p><strong>En ChatGPT</strong>: Ajustes → Aplicaciones (o Conectores) → crear una aplicación o conector personalizado, pega la dirección y elige «Sin autenticación». Según tu plan, puede que tengas que activar antes el modo desarrollador en Configuración avanzada.</p>
				<p>Para empezar, prueba a pedirle: «Dime qué categorías tiene mi web y prepárame un borrador de post sobre…».</p>
			</div>
		<?php endif; ?>

		<h2>Conectar un asistente</h2>
		<?php if ( ! $mine ) : ?>
			<p>Tu usuario no tiene permisos para ningún apartado de este servicio. Pide a un administrador de la web que te dé acceso.</p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'eg_ai', 'eg_ai_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="eg_ai_label">Nombre</label></th>
						<td><input type="text" id="eg_ai_label" name="eg_ai_label" class="regular-text" placeholder="p. ej. Claude de Marta"></td>
					</tr>
					<tr>
						<th>Qué podrá hacer</th>
						<td>
							<?php foreach ( $mine as $s ) : ?>
								<label style="display:block;margin-bottom:6px"><input type="checkbox" name="eg_ai_scopes[]" value="<?php echo esc_attr( $s ); ?>" checked> <strong><?php echo esc_html( $scopes[ $s ]['label'] ); ?></strong>: <?php echo esc_html( $scopes[ $s ]['description'] ); ?></label>
							<?php endforeach; ?>
							<p class="description">El asistente trabaja con tu usuario (<?php echo esc_html( wp_get_current_user()->user_login ); ?>) y sus permisos. Nunca puede borrar contenido ni tocar páginas, ajustes o usuarios.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Crear conexión', 'primary', 'eg_ai_create' ); ?>
			</form>
		<?php endif; ?>

		<h2>Conexiones</h2>
		<?php if ( ! $conns ) : ?>
			<p>Todavía no hay ninguna.</p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'eg_ai', 'eg_ai_nonce' ); ?>
				<table class="widefat striped" style="max-width:60em">
					<thead><tr><th>Nombre</th><th>Usuario</th><th>Puede</th><th>Creada</th><th>Último uso</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $conns as $id => $c ) : $pub = eg_pub_ai_connection_public( $id, $c ); ?>
						<tr>
							<td><?php echo esc_html( $pub['label'] ); ?> <code><?php echo esc_html( $pub['key_end'] ); ?></code></td>
							<td><?php echo esc_html( (string) $pub['user'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( function ( $s ) use ( $scopes ) {
								return $scopes[ $s ]['label'] ?? $s;
							}, $pub['scopes'] ) ) ); ?></td>
							<td><?php echo esc_html( (string) $pub['created'] ); ?></td>
							<td><?php echo esc_html( $pub['last_used'] ?: '—' ); ?></td>
							<td><button class="button" name="eg_ai_revoke" value="<?php echo esc_attr( $id ); ?>" onclick="return confirm('¿Revocar esta conexión? El asistente dejará de tener acceso.')">Revocar</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>
		<?php endif; ?>

		<h2>Últimas acciones</h2>
		<?php $log = eg_pub_ai_log( 30, $admin ? 0 : $me ); ?>
		<?php if ( ! $log ) : ?>
			<p>Sin actividad.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr><th>Cuándo</th><th>Conexión</th><th>Acción</th><th>Detalle</th></tr></thead>
				<tbody>
				<?php foreach ( $log as $e ) : ?>
					<tr>
						<td><?php echo esc_html( $e['when'] ); ?></td>
						<td><?php echo esc_html( $e['label'] ); ?></td>
						<td><code><?php echo esc_html( $e['action'] ); ?></code></td>
						<td>
							<?php if ( $e['object'] && get_post( $e['object'] ) ) : ?>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $e['object'] ) ); ?>"><?php echo esc_html( $e['summary'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $e['summary'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
