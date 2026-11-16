<?php
/**
 * Tipología «Gestión de la web»: traer a esta web las imágenes que se cargan desde otra (p. ej. tras clonar una web).
 *   POST /wp-json/eg/v1/localize-media
 *   source  dominio de origen (p. ej. hotelneptuno.com); se aceptan también sus URLs con www.
 *   apply   true para copiar y cambiar las URLs; sin él solo muestra qué haría.
 *   limit   ficheros a descargar por llamada (1-60, por defecto 25). Repetir hasta que pending sea 0; lo que
 *           falla no se reintenta en 24 h y sale en failed_before.
 * Busca URLs <source>/wp-content/uploads/… (normales o escapadas en JSON, como en _elementor_data) en el contenido de
 * las entradas, sus metadatos y las opciones. Si el fichero ya está en los uploads de esta web en la misma ruta, solo
 * cambia la URL; si no, lo descarga a esa misma ruta y, si es un original (no un tamaño -WxH), lo da de alta en la
 * biblioteca de medios. Las URLs se cambian a las de esta web manteniendo el escape; los valores serializados se
 * deserializan y se vuelven a serializar. Al acabar vacía la caché CSS de Elementor de lo tocado y la de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['medios-externos'] = array( 'type' => 'gestion', 'routes' => array( 'localize-media' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'medios-externos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/localize-media', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_localize_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Expresión que encuentra las URLs de uploads del dominio de origen; el grupo 1 es la ruta relativa (con / o \/). */
function eg_pub_localize_regex( $host ) {
	$s = '(?:\\\\?/)';
	$h = preg_quote( $host, '#' );
	return '#https?:' . $s . '{2}(?:www\.)?' . $h . $s . 'wp-content' . $s . 'uploads((?:' . $s . '[A-Za-z0-9._%~+\-]+)+?\.(?:jpe?g|png|gif|webp|avif|svg|mp4|webm|pdf))(?![A-Za-z0-9])#i';
}

/** URLs de ficheros de plugins o temas del origen (p. ej. el placeholder de Elementor); grupo 1 = plugins|themes, 2 = ruta. */
function eg_pub_localize_asset_regex( $host ) {
	$s = '(?:\\\\?/)';
	$h = preg_quote( $host, '#' );
	return '#https?:' . $s . '{2}(?:www\.)?' . $h . $s . 'wp-content' . $s . '(plugins|themes)((?:' . $s . '[A-Za-z0-9._%~+\-]+)+?\.(?:jpe?g|png|gif|webp|avif|svg|css|js|woff2?|ttf|mp4))(?![A-Za-z0-9])#i';
}

/** Ruta relativa limpia (/2024/05/foto.jpg) a partir del grupo capturado; null si no es segura. */
function eg_pub_localize_path( $raw ) {
	$p = str_replace( '\\/', '/', $raw );
	if ( false !== strpos( $p, '..' ) || '' === trim( $p, '/' ) ) {
		return null;
	}
	return '/' . ltrim( $p, '/' );
}

/** Recorre un valor (array u objeto deserializado) aplicando $fn a cada cadena. */
function eg_pub_localize_walk( $value, $fn ) {
	if ( is_string( $value ) ) {
		return $fn( $value );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = eg_pub_localize_walk( $v, $fn );
		}
	}
	return $value;
}

/** Descarga el fichero del origen a la misma ruta de los uploads de esta web. Devuelve true o un mensaje de error. */
function eg_pub_localize_copy( $host, $rel, $dest ) {
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$parts = array_map( 'rawurlencode', array_map( 'rawurldecode', explode( '/', ltrim( $rel, '/' ) ) ) );
	$path  = '/wp-content/uploads/' . implode( '/', $parts );
	$error = 'sin respuesta';
	foreach ( array( 'https://' . $host, 'https://www.' . $host ) as $base ) {
		$tmp = download_url( $base . $path, 60 );
		if ( is_wp_error( $tmp ) ) {
			$error = $tmp->get_error_message();
			continue;
		}
		if ( ! wp_mkdir_p( dirname( $dest ) ) || ! @rename( $tmp, $dest ) ) {
			if ( ! @copy( $tmp, $dest ) ) {
				@unlink( $tmp );
				return 'no se pudo escribir en uploads';
			}
			@unlink( $tmp );
		}
		@chmod( $dest, 0644 );
		return true;
	}
	return $error;
}

/** Da de alta en la biblioteca un original copiado (no los tamaños -WxH) si aún no existe. Devuelve el ID o 0. */
function eg_pub_localize_attach( $rel, $dest ) {
	global $wpdb;
	$file = ltrim( $rel, '/' );
	if ( preg_match( '#-\d+x\d+\.[a-z0-9]+$#i', $file ) ) {
		return 0;
	}
	$exists = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", $file ) );
	if ( $exists ) {
		return 0;
	}
	$type = wp_check_filetype( basename( $file ) );
	if ( empty( $type['type'] ) ) {
		return 0;
	}
	$uploads = wp_upload_dir();
	$id      = wp_insert_attachment( array(
		'guid'           => $uploads['baseurl'] . '/' . $file,
		'post_mime_type' => $type['type'],
		'post_title'     => sanitize_text_field( preg_replace( '/\.[^.]+$/', '', rawurldecode( basename( $file ) ) ) ),
		'post_status'    => 'inherit',
	), $dest, 0, true );
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	if ( 0 === strpos( $type['type'], 'image/' ) && 'image/svg+xml' !== $type['type'] ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = wp_generate_attachment_metadata( $id, $dest );
		if ( $meta ) {
			wp_update_attachment_metadata( $id, $meta );
		}
	}
	return (int) $id;
}

function eg_pub_localize_handle( WP_REST_Request $r ) {
	global $wpdb;
	$host = strtolower( trim( (string) $r->get_param( 'source' ) ) );
	$host = preg_replace( '#^https?://#', '', $host );
	$host = preg_replace( '#^www\.#', '', trim( $host, '/' ) );
	if ( ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
		return new WP_Error( 'eg_pub_source', 'source debe ser un dominio, p. ej. hotelneptuno.com', array( 'status' => 400 ) );
	}
	$own = preg_replace( '#^www\.#', '', wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( $own === $host ) {
		return new WP_Error( 'eg_pub_source', 'source es esta misma web', array( 'status' => 400 ) );
	}
	$apply   = rest_sanitize_boolean( $r->get_param( 'apply' ) );
	$limit   = max( 1, min( 60, (int) ( $r->get_param( 'limit' ) ?: 25 ) ) );
	$re      = eg_pub_localize_regex( $host );
	$like    = '%' . $wpdb->esc_like( $host ) . '%wp-content%';
	$are     = eg_pub_localize_asset_regex( $host );
	$uploads = wp_upload_dir();
	$base    = $uploads['basedir'];
	$baseurl = $uploads['baseurl'];

	// 1. Dónde aparecen.
	$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s", $like ) );
	$metas = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type <> 'revision' AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key NOT LIKE %s AND m.meta_key NOT LIKE %s AND m.meta_value LIKE %s", $wpdb->esc_like( '_eg_pub_elementor_backup' ) . '%', $wpdb->esc_like( '_wp_old' ) . '%', $like ) );
	$opts  = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_value LIKE %s", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $like ) );

	$paths = array();
	$scan  = function ( $text ) use ( $re, &$paths ) {
		if ( preg_match_all( $re, $text, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$p = eg_pub_localize_path( $raw );
				if ( $p ) {
					$paths[ $p ] = true;
				}
			}
		}
	};
	foreach ( $posts as $row ) {
		$scan( $row->post_content );
	}
	foreach ( $metas as $row ) {
		$scan( $row->meta_value );
	}
	foreach ( $opts as $row ) {
		$scan( $row->option_value );
	}

	// 2. Qué está ya aquí y qué hay que copiar.
	$ready   = array();
	$missing = array();
	foreach ( array_keys( $paths ) as $p ) {
		if ( file_exists( $base . rawurldecode( $p ) ) ) {
			$ready[ $p ] = true;
		} else {
			$missing[] = $p;
		}
	}
	$already = count( $ready );
	$copied  = array();
	$attach  = 0;
	$failed  = array();
	// Los que fallaron en una llamada anterior no se reintentan durante un día (para que no tapen al resto).
	$fkey   = 'eg_pub_localize_failed_' . md5( $host );
	$before = (array) get_transient( $fkey );
	if ( $apply ) {
		$todo = array_values( array_diff( $missing, $before ) );
		foreach ( array_slice( $todo, 0, $limit ) as $p ) {
			$dest = $base . rawurldecode( $p );
			$res  = eg_pub_localize_copy( $host, $p, $dest );
			if ( true === $res ) {
				$ready[ $p ] = true;
				$copied[]    = $p;
				if ( eg_pub_localize_attach( rawurldecode( $p ), $dest ) ) {
					$attach++;
				}
			} else {
				$failed[] = array( 'path' => $p, 'error' => $res );
			}
		}
	}
	$failed_paths = array_values( array_unique( array_merge( array_intersect( $before, $missing ), wp_list_pluck( $failed, 'path' ) ) ) );
	if ( $apply ) {
		set_transient( $fkey, $failed_paths, DAY_IN_SECONDS );
	}
	$pending = array_values( array_diff( $missing, array_keys( $ready ), $failed_paths ) );

	$out = array(
		'source'       => $host,
		'apply'        => $apply,
		'found'        => count( $paths ),
		'already_here' => $already,
		'to_copy'      => count( $missing ),
		'where'        => array( 'posts' => count( $posts ), 'meta' => count( $metas ), 'options' => wp_list_pluck( $opts, 'option_name' ) ),
	);
	if ( ! $apply ) {
		$out['sample_to_copy'] = array_slice( $missing, 0, 15 );
		return rest_ensure_response( $out );
	}

	// 3. Cambiar las URLs de lo que ya está aquí, manteniendo el escape.
	$swap = function ( $text ) use ( $re, $are, $ready, $baseurl ) {
		// Ficheros de plugins y temas: si existen aquí en la misma ruta, se apunta a los de esta web (no se copian).
		$text = preg_replace_callback( $are, function ( $m ) {
			$p = eg_pub_localize_path( $m[2] );
			if ( ! $p || ! file_exists( WP_CONTENT_DIR . '/' . strtolower( $m[1] ) . rawurldecode( $p ) ) ) {
				return $m[0];
			}
			$url = content_url( strtolower( $m[1] ) . $p );
			return ( false !== strpos( $m[0], '\\/' ) ) ? str_replace( '/', '\\/', $url ) : $url;
		}, $text );
		return preg_replace_callback( $re, function ( $m ) use ( $ready, $baseurl ) {
			$p = eg_pub_localize_path( $m[1] );
			if ( ! $p || empty( $ready[ $p ] ) ) {
				return $m[0];
			}
			$url = $baseurl . $p;
			return ( false !== strpos( $m[0], '\\/' ) ) ? str_replace( '/', '\\/', $url ) : $url;
		}, $text );
	};
	$fix = function ( $value ) use ( $swap ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( false !== $data || 'b:0;' === $value ) {
				return serialize( eg_pub_localize_walk( $data, $swap ) );
			}
		}
		return $swap( $value );
	};
	$changed = array( 'posts' => 0, 'meta' => 0, 'options' => 0 );
	$touched = array();
	foreach ( $posts as $row ) {
		$new = $swap( $row->post_content );
		if ( $new !== $row->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $row->ID ) );
			clean_post_cache( $row->ID );
			$touched[ $row->ID ] = true;
			$changed['posts']++;
		}
	}
	foreach ( $metas as $row ) {
		$new = $fix( $row->meta_value );
		if ( $new !== $row->meta_value ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => $row->meta_id ) );
			wp_cache_delete( $row->post_id, 'post_meta' );
			$touched[ $row->post_id ] = true;
			$changed['meta']++;
		}
	}
	foreach ( $opts as $row ) {
		$new = $fix( $row->option_value );
		if ( $new !== $row->option_value ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_id' => $row->option_id ) );
			wp_cache_delete( $row->option_name, 'options' );
			$changed['options']++;
		}
	}
	if ( $changed['options'] ) {
		wp_cache_delete( 'alloptions', 'options' );
	}

	// Lo que sigue apuntando al origen dentro de wp-content (para revisarlo a mano).
	$residual = array();
	$needle   = '#' . preg_quote( $host, '#' ) . '(?:\\\\?/)wp-content#i';
	foreach ( array( 'posts' => $posts, 'meta' => $metas ) as $kind => $rows ) {
		foreach ( $rows as $row ) {
			$id   = 'posts' === $kind ? $row->ID : $row->post_id;
			$text = 'posts' === $kind ? $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ) : $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $row->meta_id ) );
			if ( count( $residual ) < 15 && preg_match( $needle, (string) $text, $mm, PREG_OFFSET_CAPTURE ) ) {
				$residual[] = array( 'where' => $kind, 'id' => (int) $id, 'text' => substr( $text, max( 0, $mm[0][1] - 20 ), 160 ) );
			}
		}
	}

	// 4. Cachés.
	foreach ( array_keys( $touched ) as $id ) {
		delete_post_meta( $id, '_elementor_css' );
		delete_post_meta( $id, '_elementor_element_cache' );
	}
	if ( $touched && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	$out['copied']      = count( $copied );
	$out['attachments'] = $attach;
	$out['failed']      = $failed;
	$out['failed_before'] = array_values( array_intersect( $before, $missing ) );
	$out['pending']     = count( $pending );
	$out['changed']     = $changed;
	$out['residual']    = $residual;
	$out['next']        = $pending ? 'Repetir con apply:true para copiar los siguientes ' . min( $limit, count( $pending ) ) . '.' : ( $failed ? 'Quedan fallidos: revisar si existen en el origen.' : 'Hecho.' );
	return rest_ensure_response( $out );
}
