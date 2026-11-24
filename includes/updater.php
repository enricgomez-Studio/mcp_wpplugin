<?php
/**
 * Actualizaciones desde las releases de GitHub (enricgomez-Studio/mcp_wpplugin).
 *
 * Cada release lleva adjuntos eg-studio.zip (el que usan las webs desde la v2.139.0) y eg-publicacion.zip (el que
 * buscan las versiones anteriores; mismo contenido en la carpeta antigua). Los genera la GitHub Action al
 * subir a main una versión nueva). Si el repositorio es privado, definir en wp-config.php:
 *   define( 'EG_PUB_GITHUB_TOKEN', 'github_pat_...' );  // permiso de solo lectura de Contents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_SLUG          = 'eg-studio';
const EG_PUB_RELEASE_CACHE = 'eg_pub_gh_release';
const EG_PUB_OPTION_HOLD   = 'eg_pub_hold_version';

/**
 * Mantener esta web en la versión instalada: se marca en Ajustes → EG Studio,
 * o en wp-config.php con define( 'EG_PUB_HOLD_VERSION', true ).
 */
function eg_pub_update_held() {
	if ( defined( 'EG_PUB_HOLD_VERSION' ) ) {
		return (bool) EG_PUB_HOLD_VERSION;
	}
	return (bool) get_option( EG_PUB_OPTION_HOLD, false );
}

function eg_pub_gh_token() {
	return defined( 'EG_PUB_GITHUB_TOKEN' ) ? (string) EG_PUB_GITHUB_TOKEN : '';
}

/**
 * Última release publicada: array( version, package, url, notes, published ) o null.
 */
function eg_pub_gh_release( $force = false ) {
	$cached = get_site_transient( EG_PUB_RELEASE_CACHE );
	if ( ! $force && is_array( $cached ) ) {
		return $cached['version'] ? $cached : null;
	}

	$res = wp_remote_get( 'https://api.github.com/repos/' . EG_PUB_REPO . '/releases/latest', array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/vnd.github+json' ),
	) );
	$data = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) )
		? json_decode( wp_remote_retrieve_body( $res ), true )
		: null;

	$release = array( 'version' => '' );
	if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( EG_PUB_SLUG . '.zip' === ( $asset['name'] ?? '' ) ) {
				// Con token (repo privado) hay que descargar por la API.
				$package = eg_pub_gh_token() ? $asset['url'] : $asset['browser_download_url'];
				break;
			}
		}
		$release = array(
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'   => $package ? $package : (string) $data['zipball_url'],
			'url'       => (string) $data['html_url'],
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}
	// Si GitHub falla, no volver a preguntar hasta dentro de una hora.
	set_site_transient( EG_PUB_RELEASE_CACHE, $release, $release['version'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release['version'] ? $release : null;
}

// Cabeceras para las peticiones a GitHub (token si el repo es privado).
add_filter( 'http_request_args', function ( $args, $url ) {
	$api = 'https://api.github.com/repos/' . EG_PUB_REPO . '/';
	if ( 0 !== strpos( $url, $api ) ) {
		return $args;
	}
	$args['headers'] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$args['headers']['User-Agent'] = 'EG-Studio/' . EG_PUB_VERSION . '; ' . home_url( '/' );
	if ( eg_pub_gh_token() ) {
		$args['headers']['Authorization'] = 'Bearer ' . eg_pub_gh_token();
	}
	if ( false !== strpos( $url, '/releases/assets/' ) ) {
		$args['headers']['Accept'] = 'application/octet-stream';
	}
	return $args;
}, 10, 2 );

// Ofrecer la actualización a WordPress.
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$release = eg_pub_gh_release();
	if ( ! $release ) {
		return $transient;
	}
	$item = (object) array(
		'id'          => 'github.com/' . EG_PUB_REPO,
		'slug'        => EG_PUB_SLUG,
		'plugin'      => EG_PUB_BASENAME,
		'new_version' => $release['version'],
		'url'         => $release['url'],
		'package'     => $release['package'],
	);
	if ( ! eg_pub_update_held() && version_compare( $release['version'], EG_PUB_VERSION, '>' ) ) {
		$transient->response[ EG_PUB_BASENAME ] = $item;
		unset( $transient->no_update[ EG_PUB_BASENAME ] );
	} else {
		$transient->no_update[ EG_PUB_BASENAME ] = $item;
		unset( $transient->response[ EG_PUB_BASENAME ] );
	}
	return $transient;
} );

// Ventana "Ver detalles" en Plugins.
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || EG_PUB_SLUG !== ( $args->slug ?? '' ) ) {
		return $result;
	}
	$release = eg_pub_gh_release();
	return (object) array(
		'name'          => 'EG Studio',
		'slug'          => EG_PUB_SLUG,
		'version'       => $release ? $release['version'] : EG_PUB_VERSION,
		'author'        => '<a href="https://www.enricgomez.com">enricgomez Studio</a>',
		'homepage'      => 'https://github.com/' . EG_PUB_REPO,
		'last_updated'  => $release ? $release['published'] : '',
		'download_link' => $release ? $release['package'] : '',
		'sections'      => array(
			'description' => 'Conexión de Make con las webs de clientes de enricgomez Studio: blog, Elementor, WooCommerce y gestión de la web.',
			'changelog'   => $release && $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : 'Sin notas.',
		),
	);
}, 10, 3 );

// Instalar la versión nueva en la misma carpeta que la actual (el paso a eg-studio/ lo hace includes/migracion.php). El zip de la release trae "eg-studio/"
// y el automático de GitHub "enricgomez-mcp_wpplugin-<hash>/"; si la web lo tiene en otra carpeta
// (p. ej. "mcp_wpplugin-main", de instalarlo con el zip del repositorio), sin esto WordPress dejaría dos copias.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	if ( ( $hook_extra['plugin'] ?? '' ) !== EG_PUB_BASENAME ) {
		return $source;
	}
	$folder = dirname( EG_PUB_BASENAME );
	$wanted = trailingslashit( $remote_source ) . ( '.' === $folder ? EG_PUB_SLUG : $folder ) . '/';
	if ( trailingslashit( $source ) === $wanted ) {
		return $source;
	}
	return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
}, 10, 4 );

// Subir el zip a mano (Plugins → Añadir nuevo → Subir, ManageWP u otro gestor) en una web que ya tiene el plugin:
// WordPress lo trata como una instalación nueva y, si la carpeta del zip no coincide con la instalada, deja dos copias
// que chocan (o se para con «la carpeta ya existe»). Si el paquete es este plugin, se instala encima de la copia que
// está funcionando, en su misma carpeta, como una reinstalación: la carpeta antigua se aparta y se borra al terminar
// (o se recupera si la instalación falla). Los ajustes y la clave están en la base de datos y no se tocan.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem, $eg_pub_reinstall_backup;
	if ( ! empty( $hook_extra['plugin'] ) || ( $hook_extra['type'] ?? '' ) !== 'plugin' || ! $wp_filesystem ) {
		return $source; // Las actualizaciones normales ya las lleva el filtro anterior.
	}
	if ( ! $wp_filesystem->exists( trailingslashit( $source ) . 'eg-studio.php' ) && ! $wp_filesystem->exists( trailingslashit( $source ) . 'eg-publicacion.php' ) ) {
		return $source;
	}
	$folder = dirname( EG_PUB_BASENAME );
	if ( '.' === $folder || '' === $folder ) {
		return $source;
	}
	$current = trailingslashit( WP_PLUGIN_DIR ) . $folder;
	if ( ! $wp_filesystem->is_dir( $current ) ) {
		return $source;
	}
	// El paquete, con el nombre de la carpeta instalada.
	$wanted = trailingslashit( $remote_source ) . $folder;
	if ( untrailingslashit( $source ) !== $wanted && ! $wp_filesystem->move( $source, $wanted, true ) ) {
		return $source;
	}
	// Apartar la copia actual para que la instalación no se pare ni deje dos.
	$backup = trailingslashit( WP_CONTENT_DIR ) . 'upgrade/eg-pub-anterior-' . time();
	if ( ! $wp_filesystem->move( $current, $backup, true ) ) {
		return new WP_Error( 'eg_pub_reinstall', 'EG Studio: no se ha podido apartar la versión instalada para reinstalar el plugin. Prueba con «Reemplazar» o actualiza desde Plugins.' );
	}
	$eg_pub_reinstall_backup = array( 'backup' => $backup, 'current' => $current );
	return trailingslashit( $wanted );
}, 11, 4 );

add_filter( 'upgrader_install_package_result', function ( $result ) {
	global $wp_filesystem, $eg_pub_reinstall_backup;
	if ( empty( $eg_pub_reinstall_backup ) || ! $wp_filesystem ) {
		return $result;
	}
	$b = $eg_pub_reinstall_backup;
	$eg_pub_reinstall_backup = null;
	if ( is_wp_error( $result ) || ! $wp_filesystem->exists( trailingslashit( $b['current'] ) . basename( EG_PUB_BASENAME ) ) ) {
		$wp_filesystem->delete( $b['current'], true );
		$wp_filesystem->move( $b['backup'], $b['current'], true ); // Falló: se recupera la versión anterior.
	} else {
		$wp_filesystem->delete( $b['backup'], true );
		delete_site_transient( EG_PUB_RELEASE_CACHE );
	}
	return $result;
} );

// Actualizar solo, sin tener que pulsar el botón en cada web.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( isset( $item->plugin ) && EG_PUB_BASENAME === $item->plugin ) ? ! eg_pub_update_held() : $update;
}, 10, 2 );

// Tras actualizar, olvidar la release cacheada.
add_action( 'upgrader_process_complete', function () {
	delete_site_transient( EG_PUB_RELEASE_CACHE );
} );

// Enlaces "Ajustes" (el primero) y "Buscar actualizaciones" en la fila del plugin.
add_filter( 'plugin_action_links_' . EG_PUB_BASENAME, function ( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eg-studio' ) ) . '">Ajustes</a>' );
	}
	$url = wp_nonce_url( admin_url( 'plugins.php?eg_pub_check=1' ), 'eg_pub_check' );
	$links[] = '<a href="' . esc_url( $url ) . '">Buscar actualizaciones</a>';
	return $links;
} );

add_action( 'admin_init', function () {
	if ( empty( $_GET['eg_pub_check'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	check_admin_referer( 'eg_pub_check' );
	delete_site_transient( EG_PUB_RELEASE_CACHE );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
} );
