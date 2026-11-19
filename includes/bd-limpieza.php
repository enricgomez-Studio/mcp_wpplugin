<?php
/**
 * Tipología «Gestión de la web», módulo «db-clean»: revisa y limpia lo que infla la base de datos.
 *   POST /wp-json/eg/v1/db-clean
 * Sin `apply` solo informa: tamaño de las tablas más grandes, metadatos repetidos (misma entrada, misma clave y
 * mismo valor, por clave), revisiones, borradores automáticos, papelera, transitorios caducados, metadatos
 * huérfanos, opciones con carga automática, acciones de Action Scheduler y registros de Redirection.
 *   parts   qué limpiar con apply:true, lista de: dup_meta (deja la fila más antigua de cada grupo idéntico; los
 *           grupos con valores distintos no se tocan), revisions (deja las `keep` últimas por entrada, 5 por
 *           defecto), auto_drafts, trash (solo lo que lleva más de 30 días en la papelera), transients (caducados),
 *           orphan_meta, scheduler (acciones terminadas, fallidas o canceladas de hace más de 30 días y sus
 *           registros), redirection_logs (registros y 404 de hace más de 30 días), optimize (OPTIMIZE TABLE de las
 *           tablas tocadas).
 *   limit   máximo de revisiones o entradas que borra por llamada (2000); `remaining` dice cuántas quedan.
 * No limpia si hay una copia de UpdraftPlus en curso. Haz antes una copia de la base de datos (/backup run:"db").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['db-clean'] = array( 'type' => 'gestion', 'routes' => array( 'db-clean' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'db-clean' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/db-clean', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_db_clean_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

function eg_pub_db_table_exists( $table ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
function eg_pub_db_report() {
	global $wpdb;
	$out = array();

	$out['tables'] = array_map( function ( $t ) {
		return array( 'table' => $t['t'], 'rows' => (int) $t['r'], 'mb' => round( $t['b'] / 1048576, 1 ), 'free_mb' => round( $t['f'] / 1048576, 1 ) );
	}, (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT TABLE_NAME t, TABLE_ROWS r, DATA_LENGTH + INDEX_LENGTH b, DATA_FREE f FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY b DESC LIMIT 15',
		DB_NAME
	), ARRAY_A ) );

	$dups = $wpdb->get_results( "SELECT meta_key, COUNT(*) grupos, SUM(c - 1) filas, SUM(b - b / c) bytes
		FROM ( SELECT post_id, meta_key, COUNT(*) c, SUM(LENGTH(meta_value)) b FROM {$wpdb->postmeta}
			GROUP BY post_id, meta_key HAVING c > 1 AND COUNT(DISTINCT MD5(meta_value)) = 1 ) d
		GROUP BY meta_key ORDER BY bytes DESC LIMIT 25", ARRAY_A );
	$out['dup_meta'] = array(
		'rows'   => (int) array_sum( wp_list_pluck( $dups, 'filas' ) ),
		'mb'     => round( array_sum( wp_list_pluck( $dups, 'bytes' ) ) / 1048576, 1 ),
		'by_key' => array_map( function ( $d ) {
			return array( 'key' => $d['meta_key'], 'posts' => (int) $d['grupos'], 'rows' => (int) $d['filas'], 'mb' => round( $d['bytes'] / 1048576, 2 ) );
		}, (array) $dups ),
		'conflicting' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' GROUP BY post_id HAVING COUNT(*) > 1 AND COUNT(DISTINCT MD5(meta_value)) > 1 ) x" ),
	);

	$rev = $wpdb->get_row( "SELECT COUNT(*) n, COALESCE(SUM(LENGTH(post_content)), 0) b FROM {$wpdb->posts} WHERE post_type = 'revision'", ARRAY_A );
	$out['revisions'] = array(
		'count'    => (int) $rev['n'],
		'mb'       => round( $rev['b'] / 1048576, 1 ),
		'meta_mb'  => round( (float) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(m.meta_value)), 0) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = 'revision'" ) / 1048576, 1 ),
	);
	$out['auto_drafts'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
	$out['trash']       = array(
		'count'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
		'older_30d'   => count( eg_pub_db_old_trash( 100000 ) ),
	);
	$now = time();
	$out['transients'] = array(
		'total'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ),
		'expired' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE '\\_transient\\_timeout\\_%%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_%%' ) AND option_value < %d", $now ) ),
	);
	$out['orphan_meta'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL" );
	$auto = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) b FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY b DESC LIMIT 10", ARRAY_A );
	$out['autoload'] = array(
		'mb'  => round( (float) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)), 0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" ) / 1048576, 2 ),
		'top' => array_map( function ( $o ) {
			return array( 'option' => $o['option_name'], 'kb' => round( $o['b'] / 1024, 1 ) );
		}, (array) $auto ),
	);
	$as = $wpdb->prefix . 'actionscheduler_actions';
	if ( eg_pub_db_table_exists( $as ) ) {
		$out['scheduler'] = array(
			'by_status' => $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$as} GROUP BY status", ARRAY_A ),
			'old'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$as} WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)" ),
		);
	}
	foreach ( array( 'redirection_logs', 'redirection_404' ) as $t ) {
		if ( eg_pub_db_table_exists( $wpdb->prefix . $t ) ) {
			$out[ $t ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$t}" );
		}
	}
	return $out;
}

function eg_pub_db_old_trash( $limit ) {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time'
		WHERE p.post_status = 'trash' AND m.meta_value < %d LIMIT %d",
		time() - 30 * DAY_IN_SECONDS,
		$limit
	) ) );
}

function eg_pub_db_clean_handle( WP_REST_Request $r ) {
	global $wpdb;
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$apply = (bool) $r->get_param( 'apply' );
	$parts = array_map( 'sanitize_key', (array) $r->get_param( 'parts' ) );
	if ( ! $apply || ! $parts ) {
		return new WP_REST_Response( array( 'ok' => true, 'applied' => false, 'report' => eg_pub_db_report() ), 200 );
	}
	if ( function_exists( 'eg_pub_backup_state' ) && defined( 'UPDRAFTPLUS_DIR' ) ) {
		$state = eg_pub_backup_state();
		if ( ! empty( $state['in_progress'] ) ) {
			return eg_pub_error( 'eg_backup_running', 'Hay una copia de UpdraftPlus en curso: espera a que termine antes de limpiar.', 409 );
		}
	}
	$limit   = max( 1, min( 10000, (int) ( $r->get_param( 'limit' ) ? $r->get_param( 'limit' ) : 2000 ) ) );
	$done    = array();
	$touched = array();

	if ( in_array( 'dup_meta', $parts, true ) ) {
		$n = $wpdb->query( "DELETE m FROM {$wpdb->postmeta} m JOIN (
			SELECT post_id, meta_key, MIN(meta_id) keep_id FROM {$wpdb->postmeta}
			GROUP BY post_id, meta_key HAVING COUNT(*) > 1 AND COUNT(DISTINCT MD5(meta_value)) = 1
		) d ON d.post_id = m.post_id AND d.meta_key = m.meta_key AND m.meta_id <> d.keep_id" );
		$done['dup_meta'] = false === $n ? $wpdb->last_error : (int) $n;
		$touched[]        = $wpdb->postmeta;
		wp_cache_flush();
	}

	if ( in_array( 'revisions', $parts, true ) ) {
		$keep = max( 0, (int) ( null !== $r->get_param( 'keep' ) ? $r->get_param( 'keep' ) : 5 ) );
		$rows = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' ORDER BY post_parent, post_date DESC, ID DESC", ARRAY_A );
		$seen = array();
		$del  = array();
		foreach ( $rows as $row ) {
			$p          = (int) $row['post_parent'];
			$seen[ $p ] = isset( $seen[ $p ] ) ? $seen[ $p ] + 1 : 1;
			if ( $seen[ $p ] > $keep ) {
				$del[] = (int) $row['ID'];
			}
		}
		$total = count( $del );
		$del   = array_slice( $del, 0, $limit );
		foreach ( array_chunk( $del, 500 ) as $chunk ) {
			$in = implode( ',', $chunk );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$in})" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'revision'" );
		}
		$done['revisions'] = array( 'deleted' => count( $del ), 'remaining' => $total - count( $del ), 'kept_per_post' => $keep );
		$touched[]         = $wpdb->posts;
		$touched[]         = $wpdb->postmeta;
	}

	if ( in_array( 'auto_drafts', $parts, true ) ) {
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT %d", $limit ) ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		$done['auto_drafts'] = count( $ids );
		$touched[]           = $wpdb->posts;
	}

	if ( in_array( 'trash', $parts, true ) ) {
		$ids = eg_pub_db_old_trash( min( $limit, 500 ) );
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
		$done['trash'] = count( $ids );
		$touched[]     = $wpdb->posts;
	}

	if ( in_array( 'transients', $parts, true ) ) {
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		delete_expired_transients( true );
		$done['transients'] = $before - (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$touched[]          = $wpdb->options;
	}

	if ( in_array( 'orphan_meta', $parts, true ) ) {
		$n = $wpdb->query( "DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL" );
		$done['orphan_meta'] = false === $n ? $wpdb->last_error : (int) $n;
		$touched[]           = $wpdb->postmeta;
	}

	if ( in_array( 'scheduler', $parts, true ) ) {
		$as  = $wpdb->prefix . 'actionscheduler_actions';
		$log = $wpdb->prefix . 'actionscheduler_logs';
		if ( eg_pub_db_table_exists( $as ) ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM {$as} WHERE status IN ('complete','failed','canceled') AND scheduled_date_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) LIMIT %d", $limit * 5 ) ) );
			foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
				$in = implode( ',', $chunk );
				if ( eg_pub_db_table_exists( $log ) ) {
					$wpdb->query( "DELETE FROM {$log} WHERE action_id IN ({$in})" );
				}
				$wpdb->query( "DELETE FROM {$as} WHERE action_id IN ({$in})" );
			}
			$done['scheduler'] = count( $ids );
			$touched[]         = $as;
			$touched[]         = $log;
		}
	}

	if ( in_array( 'redirection_logs', $parts, true ) ) {
		foreach ( array( 'redirection_logs', 'redirection_404' ) as $t ) {
			$table = $wpdb->prefix . $t;
			if ( eg_pub_db_table_exists( $table ) ) {
				$n          = $wpdb->query( "DELETE FROM {$table} WHERE created < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
				$done[ $t ] = false === $n ? $wpdb->last_error : (int) $n;
				$touched[]  = $table;
			}
		}
	}

	if ( in_array( 'optimize', $parts, true ) ) {
		$tables = array_values( array_unique( $touched ? $touched : array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) ) );
		foreach ( $tables as $t ) {
			if ( eg_pub_db_table_exists( $t ) ) {
				$wpdb->query( "OPTIMIZE TABLE {$t}" );
			}
		}
		$done['optimize'] = $tables;
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return new WP_REST_Response( array( 'ok' => true, 'applied' => true, 'done' => $done, 'report' => eg_pub_db_report() ), 200 );
}
// phpcs:enable
