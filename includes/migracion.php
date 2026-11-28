<?php
/**
 * Paso de la carpeta antigua a la nueva (desde la v2.139.0): eg-publicacion/eg-publicacion.php → eg-studio/eg-studio.php.
 *
 * Las webs se actualizan como siempre: la release lleva eg-publicacion.zip para las versiones antiguas (con eg-studio.php y el
 * cargador eg-publicacion.php, que carga el plugin desde la carpeta antigua) y eg-studio.zip para las nuevas. Cuando el plugin detecta que está
 * activo desde otra carpeta u otro archivo, al final de la petición (para no cortar nada que aún tenga que cargar):
 *   1. Renombra su carpeta a eg-studio/. Si ya existe una eg-studio/ con el plugin (p. ej. subida a mano), se queda
 *      con la versión más nueva de las dos y borra la otra.
 *   2. Cambia el plugin activo (también en multisitio y en la lista de actualizaciones automáticas) al archivo nuevo.
 * No toca la base de datos del plugin: la clave X-EG-Key, los ajustes y las rutas /wp-json/eg/v1/ siguen igual.
 * Si algo falla (permisos), sigue funcionando desde la carpeta antigua y avisa en el escritorio; se reintenta cada hora.
 * Para impedirlo en una web: define( 'EG_PUB_NO_MIGRATE', true ); en wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_MIGRATE_LOG = 'eg_pub_folder_migration';

/**
 * Desde la v2.141.0 el paso está EN PAUSA por defecto (en clubdelviatger.cat el plugin desapareció tras el paso y aún no
 * se sabe por qué). Se activa por web con la opción eg_pub_migrate_enabled (POST /update {"migrate":true}) o con
 * define( 'EG_PUB_MIGRATE', true ). Mientras tanto la web sigue funcionando desde su carpeta antigua con el cargador.
 */
function eg_pub_migration_enabled() {
	if ( defined( 'EG_PUB_NO_MIGRATE' ) && EG_PUB_NO_MIGRATE ) {
		return false;
	}
	return ( defined( 'EG_PUB_MIGRATE' ) && EG_PUB_MIGRATE ) || get_option( 'eg_pub_migrate_enabled' );
}

function eg_pub_needs_migration() {
	return EG_PUB_MAIN !== EG_PUB_BASENAME && eg_pub_migration_enabled();
}

/** Bloqueo atómico (add_option no lo es: con dos peticiones a la vez las dos pueden «crearlo»). */
function eg_pub_migrate_lock() {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = 'eg_pub_migrate_lock' AND option_value < %d", time() - 300 ) );
	$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('eg_pub_migrate_lock', %s, 'off')", (string) time() ) );
	return 1 === (int) $ok;
}

add_action( 'shutdown', function () {
	if ( ! eg_pub_needs_migration() || wp_installing() || get_transient( 'eg_pub_migrate_wait' ) ) {
		return;
	}
	// No en mitad de una actualización de plugins (el código cargado es el anterior y los archivos cambian).
	if ( defined( 'WP_INSTALLING' ) || get_option( 'core_updater.lock' ) || get_option( 'auto_updater.lock' ) ) {
		return;
	}
	// Una sola petición a la vez: add_option falla si otra ya la ha creado.
	if ( ! eg_pub_migrate_lock() ) {
		return;
	}
	$result = eg_pub_migrate_folder();
	$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->options, array( 'option_name' => 'eg_pub_migrate_lock' ) );
	wp_cache_delete( 'eg_pub_migrate_lock', 'options' );
	if ( is_wp_error( $result ) ) {
		set_transient( 'eg_pub_migrate_wait', 1, HOUR_IN_SECONDS );
	}
}, 99 );

/** Versión de la cabecera de un archivo principal, o '' si no es este plugin. */
function eg_pub_file_version( $file ) {
	if ( ! is_file( $file ) ) {
		return '';
	}
	$data = get_file_data( $file, array( 'name' => 'Plugin Name', 'version' => 'Version' ) );
	return ( 'EG Studio' === $data['name'] || 'EG Publicación' === $data['name'] ) ? (string) $data['version'] : '';
}

/** Borra una carpeta entera (sin seguir enlaces simbólicos). */
function eg_pub_rmdir( $dir ) {
	if ( is_link( $dir ) ) {
		return unlink( $dir );
	}
	if ( ! is_dir( $dir ) ) {
		return true;
	}
	foreach ( (array) scandir( $dir ) as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			eg_pub_rmdir( $path );
		} else {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/** Cambia el archivo activo en las opciones de WordPress. */
function eg_pub_switch_active( $from, $to ) {
	$active = (array) get_option( 'active_plugins', array() );
	$i      = array_search( $from, $active, true );
	if ( false !== $i ) {
		$active[ $i ] = $to;
		$active       = array_values( array_unique( $active ) );
		sort( $active );
		update_option( 'active_plugins', $active );
	}
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		if ( isset( $network[ $from ] ) ) {
			$network[ $to ] = $network[ $from ];
			unset( $network[ $from ] );
			update_site_option( 'active_sitewide_plugins', $network );
		}
	}
	$auto = (array) get_site_option( 'auto_update_plugins', array() );
	if ( in_array( $from, $auto, true ) ) {
		$auto = array_values( array_unique( array_merge( array_diff( $auto, array( $from ) ), array( $to ) ) ) );
		update_site_option( 'auto_update_plugins', $auto );
	}
}

/** Hace el paso. Devuelve true o WP_Error (y lo deja anotado para el aviso y para /status). */
function eg_pub_migrate_folder() {
	$plugins = wp_normalize_path( WP_PLUGIN_DIR );
	$from    = EG_PUB_BASENAME;
	$folder  = dirname( $from );
	$log     = array( 'time' => time(), 'from' => $from, 'to' => EG_PUB_MAIN, 'version' => EG_PUB_VERSION );

	$fail = function ( $message ) use ( $log ) {
		$log['error'] = $message;
		update_option( EG_PUB_MIGRATE_LOG, $log, false );
		return new WP_Error( 'eg_pub_migrate', $message );
	};

	if ( '.' === $folder || '' === $folder ) {
		return $fail( 'El plugin está suelto en la carpeta de plugins, sin carpeta propia: hay que reinstalarlo con el zip.' );
	}
	$old = $plugins . '/' . $folder;
	$new = $plugins . '/' . EG_PUB_FOLDER;

	if ( $folder !== EG_PUB_FOLDER ) {
		if ( file_exists( $new ) || is_link( $new ) ) {
			// Ya hay una eg-studio/: se queda la versión más nueva.
			$there = eg_pub_file_version( $new . '/eg-studio.php' );
			if ( '' === $there ) {
				return $fail( 'Ya existe una carpeta eg-studio/ que no es este plugin: renómbrala o bórrala.' );
			}
			if ( version_compare( $there, EG_PUB_VERSION, '<' ) ) {
				if ( ! eg_pub_rmdir( $new ) || file_exists( $new ) ) {
					return $fail( 'No se ha podido borrar la copia antigua de eg-studio/ (permisos).' );
				}
			} else {
				$log['kept'] = 'eg-studio/ ' . $there;
			}
		}
		if ( ! file_exists( $new ) ) {
			if ( ! @rename( $old, $new ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $fail( 'No se ha podido renombrar la carpeta ' . $folder . '/ a eg-studio/ (permisos del servidor).' );
			}
		}
	}
	if ( ! is_file( $new . '/eg-studio.php' ) ) {
		return $fail( 'Falta eg-studio/eg-studio.php tras el cambio de carpeta.' );
	}

	eg_pub_switch_active( $from, EG_PUB_MAIN );

	// Si se ha usado una eg-studio/ que ya existía, sobra la carpeta antigua. El cargador antiguo ya no hace falta.
	if ( $folder !== EG_PUB_FOLDER && is_dir( $old ) ) {
		eg_pub_rmdir( $old );
	}
	if ( is_file( $new . '/eg-publicacion.php' ) ) {
		@unlink( $new . '/eg-publicacion.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	delete_site_transient( 'update_plugins' );
	if ( function_exists( 'rocket_clean_domain' ) ) {
		// Nada en caché depende de la carpeta, pero WP Rocket guarda la lista de plugins activos.
		delete_transient( 'rocket_plugins_list' );
	}
	update_option( EG_PUB_MIGRATE_LOG, $log, false );
	return true;
}

// Aviso si el paso no ha podido hacerse.
add_action( 'admin_notices', function () {
	if ( ! eg_pub_needs_migration() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	$log = get_option( EG_PUB_MIGRATE_LOG );
	if ( empty( $log['error'] ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>EG Studio:</strong> no se ha podido pasar el plugin a su carpeta nueva <code>eg-studio/</code>: '
		. esc_html( $log['error'] ) . ' El plugin sigue funcionando igual desde <code>' . esc_html( dirname( EG_PUB_BASENAME ) ) . '/</code>; se reintenta cada hora.</p></div>';
} );


/*
 * Un solo plugin «EG Studio» en Plugins (v2.145.0). En la carpeta antigua había dos archivos con cabecera: eg-studio.php
 * y el cargador eg-publicacion.php, y WordPress listaba los dos (a veces activos los dos). Al final de la petición, sin
 * mover la carpeta: el plugin activo pasa a ser <carpeta>/eg-studio.php (también en multisitio y en las actualizaciones
 * automáticas) y se borra el cargador. La clave, los ajustes y las rutas no cambian. El paso de carpeta a eg-studio/
 * sigue en pausa (arriba).
 */
function eg_pub_loader_cleanup() {
	$folder = dirname( EG_PUB_BASENAME );
	if ( '.' === $folder || '' === $folder ) {
		return;
	}
	$dir    = wp_normalize_path( WP_PLUGIN_DIR ) . '/' . $folder;
	$main   = $folder . '/eg-studio.php';
	$loader = $folder . '/eg-publicacion.php';
	if ( ! is_file( $dir . '/eg-studio.php' ) || ! is_file( $dir . '/eg-publicacion.php' ) ) {
		return;
	}
	if ( '' === eg_pub_file_version( $dir . '/eg-studio.php' ) ) {
		return; // eg-studio.php no es este plugin: no tocar nada.
	}
	eg_pub_switch_active( $loader, $main );
	$active = (array) get_option( 'active_plugins', array() );
	if ( ! in_array( $main, $active, true ) && ! ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )[ $main ] ) ) ) {
		return; // No ha quedado activo el archivo nuevo: mejor no borrar el cargador.
	}
	@unlink( $dir . '/eg-publicacion.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_clean_plugins_cache( false );
	update_option( 'eg_pub_loader_cleanup', array( 'time' => time(), 'from' => $loader, 'to' => $main, 'version' => EG_PUB_VERSION ), false );
}

add_action( 'shutdown', function () {
	if ( wp_installing() || defined( 'WP_INSTALLING' ) || get_option( 'core_updater.lock' ) || get_option( 'auto_updater.lock' ) ) {
		return;
	}
	if ( eg_pub_needs_migration() ) {
		return; // Si el paso de carpeta está activo en esta web, ya se encarga él.
	}
	$folder = dirname( EG_PUB_BASENAME );
	if ( '.' === $folder || ! is_file( wp_normalize_path( WP_PLUGIN_DIR ) . '/' . $folder . '/eg-publicacion.php' ) ) {
		return;
	}
	if ( ! eg_pub_migrate_lock() ) {
		return;
	}
	eg_pub_loader_cleanup();
	$GLOBALS['wpdb']->delete( $GLOBALS['wpdb']->options, array( 'option_name' => 'eg_pub_migrate_lock' ) );
	wp_cache_delete( 'eg_pub_migrate_lock', 'options' );
}, 98 );
