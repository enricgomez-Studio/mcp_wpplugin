<?php
/**
 * Copias de seguridad con UpdraftPlus desde Make (antes de cambios grandes en una web):
 *   POST /wp-json/eg/v1/backup
 * Está siempre activo, sea cual sea la tipología. Sin parámetros devuelve el estado: la última copia (fecha, si
 * terminó bien y sus errores), las últimas copias del historial, si hay una en curso y el destino remoto.
 *   run: true  lanza ya una copia completa (base de datos y archivos) con los ajustes de UpdraftPlus de la web
 *              (destino remoto incluido), igual que «Hacer una copia ahora». Se ejecuta en segundo plano con el
 *              cron de WordPress; se sigue con nuevas llamadas sin parámetros.
 *   run: "db"  solo la base de datos (más rápida), también con el destino remoto de la web.
 * El estado incluye los avisos de cron pendientes de UpdraftPlus (programados o lanzados), las copias en marcha
 * (fase y progreso, según los datos de trabajo de UpdraftPlus) y los últimos ficheros de la carpeta de copias.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/backup', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_backup_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** Estado de UpdraftPlus: última copia, historial reciente y copia en curso. */
function eg_pub_backup_state() {
	$last    = (array) get_option( 'updraft_last_backup', array() );
	$history = (array) get_option( 'updraft_backup_history', array() );
	krsort( $history );
	$recent = array();
	foreach ( array_slice( $history, 0, 5, true ) as $time => $set ) {
		$set      = (array) $set;
		$recent[] = array(
			'time'     => wp_date( 'Y-m-d H:i', (int) $time ),
			'database' => ! empty( $set['db'] ),
			'files'    => array_values( array_intersect( array( 'plugins', 'themes', 'uploads', 'others' ), array_keys( $set ) ) ),
			'remote'   => array_values( (array) ( $set['service'] ?? array() ) ),
		);
	}
	// Avisos de cron de UpdraftPlus. Las copias programadas también dejan su aviso, así que solo cuenta como
	// «en curso» lo que ya tocaba ejecutar o hay un trabajo de UpdraftPlus sin terminar.
	$hooks = array( 'updraft_backup_all', 'updraft_backup_resume', 'updraft_backupnow_backup_all', 'updraft_backup', 'updraft_backup_database', 'updraft_backupnow_backup', 'updraft_backupnow_backup_database' );
	$cron  = array();
	$due   = false;
	foreach ( (array) _get_cron_array() as $ts => $events ) {
		foreach ( (array) $events as $hook => $list ) {
			if ( in_array( $hook, $hooks, true ) ) {
				$rec    = current( (array) $list );
				$cron[] = array(
					'hook'      => $hook,
					'time'      => wp_date( 'Y-m-d H:i', (int) $ts ),
					'recurring' => ! empty( $rec['schedule'] ) ? $rec['schedule'] : false,
				);
				if ( (int) $ts <= time() + 60 && ( empty( $rec['schedule'] ) || 'updraft_backup_resume' === $hook ) ) {
					$due = true;
				}
			}
		}
	}
	global $wpdb;
	$jobs = array();
	$rows = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'updraft\\_jobdata\\_%'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $rows as $row ) {
		$data = maybe_unserialize( $row['option_value'] );
		if ( ! is_array( $data ) ) {
			continue;
		}
		$status = (string) ( $data['jobstatus'] ?? '' );
		$jobs[] = array(
			'nonce'   => substr( $row['option_name'], strlen( 'updraft_jobdata_' ) ),
			'type'    => (string) ( $data['job_type'] ?? '' ),
			'status'  => $status,
			'started' => ! empty( $data['backup_time'] ) ? wp_date( 'Y-m-d H:i', (int) $data['backup_time'] ) : null,
			'entity'  => (string) ( $data['dbcreating_substatus']['t'] ?? $data['filecreating_substatus']['e'] ?? '' ),
			'errors'  => (int) ( $data['errors_so_far'] ?? 0 ),
		);
		// Un trabajo sin terminar de hace más de 6 horas está atascado (p. ej. reintentando una subida FTP que siempre falla): no bloquea una copia nueva.
		$stale = ! empty( $data['backup_time'] ) && (int) $data['backup_time'] < time() - 6 * HOUR_IN_SECONDS;
		$jobs[ count( $jobs ) - 1 ]['stale'] = $stale;
		if ( '' !== $status && 'finished' !== $status && ! $stale ) {
			$due = true;
		}
	}
	$files = array();
	$dir   = isset( $GLOBALS['updraftplus'] ) && method_exists( $GLOBALS['updraftplus'], 'backups_dir_location' ) ? $GLOBALS['updraftplus']->backups_dir_location() : WP_CONTENT_DIR . '/updraft';
	foreach ( (array) glob( trailingslashit( $dir ) . 'backup_*' ) as $f ) {
		$files[ $f ] = (int) filemtime( $f );
	}
	arsort( $files );
	$files = array_map( function ( $f ) use ( $files ) {
		return array( 'file' => basename( $f ), 'bytes' => (int) filesize( $f ), 'time' => wp_date( 'Y-m-d H:i', $files[ $f ] ) );
	}, array_slice( array_keys( $files ), 0, 8 ) );
	return array(
		'updraftplus' => defined( 'UPDRAFTPLUS_DIR' ),
		'last'        => $last ? array(
			'time'    => ! empty( $last['backup_time'] ) ? wp_date( 'Y-m-d H:i', (int) $last['backup_time'] ) : null,
			'success' => ! empty( $last['success'] ),
			'errors'  => array_values( array_map( 'eg_pub_backup_msg', (array) ( $last['errors'] ?? array() ) ) ),
		) : null,
		'recent'      => $recent,
		'in_progress' => $due,
		'cron'        => $cron,
		'jobs'        => $jobs,
		'files'       => $files,
		'remote'      => array_values( array_filter( (array) get_option( 'updraft_service', array() ), fn( $s ) => is_string( $s ) && '' !== $s && 'none' !== $s ) ),
	);
}

/** UpdraftPlus guarda los errores como texto o como array('message' => …). */
function eg_pub_backup_msg( $e ) {
	if ( is_array( $e ) ) {
		$e = $e['message'] ?? ( $e['msg'] ?? wp_json_encode( $e ) );
	}
	return wp_strip_all_tags( (string) $e );
}

function eg_pub_backup_handle( WP_REST_Request $r ) {
	if ( ! defined( 'UPDRAFTPLUS_DIR' ) ) {
		return eg_pub_error( 'eg_updraft', 'UpdraftPlus no está activo en esta web.' );
	}
	$out = array( 'ok' => true, 'site' => home_url( '/' ), 'started' => false );
	if ( ! empty( $r['run'] ) ) {
		$state = eg_pub_backup_state();
		if ( $state['in_progress'] ) {
			$out['message'] = 'Ya hay una copia en curso.';
		} else {
			// Los eventos del botón «Copia de seguridad ahora» de UpdraftPlus. Los de las copias programadas
			// (updraft_backup_database) no hacen nada cuando la base de datos va con el mismo horario que los ficheros.
			// nocloud: true deja la copia solo en el servidor, sin subirla al almacenamiento remoto.
			$db_only = 'db' === $r['run'];
			$opts    = array( 'nocloud' => empty( $r['nocloud'] ) ? 0 : 1 );
			wp_schedule_single_event( time(), $db_only ? 'updraft_backupnow_backup_database' : 'updraft_backupnow_backup_all', array( $opts ) );
			spawn_cron();
			$out['started'] = true;
			$out['message'] = ( $db_only ? 'Copia de la base de datos' : 'Copia completa' ) . ' lanzada en segundo plano. Llama sin parámetros para ver cuándo termina.';
		}
	}
	$out['state'] = eg_pub_backup_state();
	if ( ! empty( $r['log'] ) ) {
		// Final del registro más reciente de UpdraftPlus (log.<nonce>.txt), para ver por qué no arranca o dónde se para.
		$dir  = isset( $GLOBALS['updraftplus'] ) && method_exists( $GLOBALS['updraftplus'], 'backups_dir_location' ) ? $GLOBALS['updraftplus']->backups_dir_location() : WP_CONTENT_DIR . '/updraft';
		$logs = (array) glob( trailingslashit( $dir ) . 'log.*.txt' );
		usort( $logs, function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		} );
		if ( $logs ) {
			$lines      = (array) file( $logs[0], FILE_IGNORE_NEW_LINES );
			$out['log'] = array(
				'file'  => basename( $logs[0] ),
				'time'  => wp_date( 'Y-m-d H:i', filemtime( $logs[0] ) ),
				'lines' => array_slice( $lines, -1 * max( 10, min( 300, (int) $r['log'] > 1 ? (int) $r['log'] : 60 ) ) ),
			);
		}
	}
	return new WP_REST_Response( $out, 200 );
}
