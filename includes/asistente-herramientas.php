<?php
/**
 * Herramientas del servidor MCP del servicio «Asistente IA» (ver includes/asistente.php).
 * Cada herramienta pertenece a un ámbito (blog, productos o común) y comprueba los permisos del usuario de la
 * conexión. Ninguna borra: los estados posibles son borrador, pendiente, programado, privado y publicado.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Esquema de una propiedad. */
function eg_pub_ai_p( $type, $description, $extra = array() ) {
	return array_merge( array( 'type' => $type, 'description' => $description ), $extra );
}

function eg_pub_ai_image_schema() {
	return array(
		'type'        => 'object',
		'description' => 'Imagen: {"url": "https://…"} o {"base64": "…"}, con "alt" (texto alternativo) opcional. También vale {"id": 123} de la biblioteca.',
		'properties'  => array(
			'url'    => array( 'type' => 'string' ),
			'base64' => array( 'type' => 'string' ),
			'id'     => array( 'type' => 'integer' ),
			'alt'    => array( 'type' => 'string' ),
		),
	);
}

/** Definición de las herramientas: nombre => array( scope, title, description, input, annotations, run ). */
function eg_pub_ai_tool_defs() {
	$read  = array( 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );
	$write = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false );
	$edit  = array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false );

	$status_post = eg_pub_ai_p( 'string', 'draft (borrador, por defecto), pending (pendiente de revisión), publish (publicado; con date futura queda programado) o private.', array( 'enum' => array( 'draft', 'pending', 'publish', 'private' ) ) );
	$seo         = array(
		'seo_title'       => eg_pub_ai_p( 'string', 'Título SEO (Yoast o Rank Math), máx. ~60 caracteres.' ),
		'seo_description' => eg_pub_ai_p( 'string', 'Meta descripción, máx. ~155 caracteres.' ),
		'focus_keyword'   => eg_pub_ai_p( 'string', 'Palabra clave principal.' ),
	);
	$lang        = array(
		'lang'           => eg_pub_ai_p( 'string', 'Código de idioma (es, ca, en…), solo si la web es multidioma (ver site_info).' ),
		'translation_of' => eg_pub_ai_p( 'integer', 'ID del original del que esto es la traducción (con lang).' ),
	);
	$post_fields = array_merge( array(
		'title'          => eg_pub_ai_p( 'string', 'Título.' ),
		'content'        => eg_pub_ai_p( 'string', 'Contenido en HTML (h2, h3, p, ul, ol, li, strong, em, a, img). Sin h1.' ),
		'excerpt'        => eg_pub_ai_p( 'string', 'Extracto.' ),
		'status'         => $status_post,
		'date'           => eg_pub_ai_p( 'string', 'Fecha de publicación AAAA-MM-DD HH:MM (hora de la web). Futura + status publish = programado.' ),
		'slug'           => eg_pub_ai_p( 'string', 'Slug de la URL.' ),
		'categories'     => eg_pub_ai_p( 'array', 'Nombres de categorías (las que no existan se crean si el usuario puede).', array( 'items' => array( 'type' => 'string' ) ) ),
		'tags'           => eg_pub_ai_p( 'array', 'Etiquetas.', array( 'items' => array( 'type' => 'string' ) ) ),
		'featured_image' => eg_pub_ai_image_schema(),
	), $seo, $lang );

	$product_fields = array_merge( array(
		'name'              => eg_pub_ai_p( 'string', 'Nombre del producto.' ),
		'description'       => eg_pub_ai_p( 'string', 'Descripción larga en HTML.' ),
		'short_description' => eg_pub_ai_p( 'string', 'Descripción corta en HTML.' ),
		'regular_price'     => eg_pub_ai_p( 'string', 'Precio normal, con punto decimal (p. ej. "19.90").' ),
		'sale_price'        => eg_pub_ai_p( 'string', 'Precio rebajado ("" quita la rebaja).' ),
		'sku'               => eg_pub_ai_p( 'string', 'Referencia (SKU), única.' ),
		'stock_quantity'    => eg_pub_ai_p( 'integer', 'Unidades en stock (activa la gestión de stock). null la desactiva.' ),
		'stock_status'      => eg_pub_ai_p( 'string', 'instock, outofstock u onbackorder (si no se gestiona el stock por unidades).', array( 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ) ),
		'weight'            => eg_pub_ai_p( 'string', 'Peso en la unidad de la tienda.' ),
		'status'            => $status_post,
		'slug'              => eg_pub_ai_p( 'string', 'Slug de la URL.' ),
		'categories'        => eg_pub_ai_p( 'array', 'Nombres de categorías de producto (las que no existan se crean si el usuario puede).', array( 'items' => array( 'type' => 'string' ) ) ),
		'tags'              => eg_pub_ai_p( 'array', 'Etiquetas de producto.', array( 'items' => array( 'type' => 'string' ) ) ),
		'images'            => array( 'type' => 'array', 'description' => 'Imágenes: la primera es la principal y el resto la galería. Sustituye las actuales.', 'items' => eg_pub_ai_image_schema() ),
	), $seo, $lang );

	return array(
		'site_info'      => array(
			'scope'       => '',
			'title'       => 'Datos de la web',
			'description' => 'Nombre, idiomas, zona horaria, moneda, categorías y etiquetas, qué puede hacer esta conexión y las reglas de estilo de la web. Llámala primero.',
			'input'       => array(),
			'annotations' => $read,
			'run'         => 'eg_pub_ai_t_site_info',
		),
		'upload_image'   => array(
			'scope'       => '',
			'title'       => 'Subir una imagen',
			'description' => 'Sube una imagen a la biblioteca de medios (desde una URL pública o en base64) y devuelve su ID y su URL para usarla en el contenido.',
			'input'       => array(
				'properties' => array(
					'url'      => eg_pub_ai_p( 'string', 'URL pública de la imagen.' ),
					'base64'   => eg_pub_ai_p( 'string', 'Imagen en base64 (si no hay URL).' ),
					'alt'      => eg_pub_ai_p( 'string', 'Texto alternativo.' ),
					'title'    => eg_pub_ai_p( 'string', 'Título.' ),
					'filename' => eg_pub_ai_p( 'string', 'Nombre de archivo sin extensión.' ),
				),
			),
			'annotations' => $write,
			'run'         => 'eg_pub_ai_t_upload_image',
		),
		'list_posts'     => array(
			'scope'       => 'blog',
			'title'       => 'Listar posts',
			'description' => 'Posts del blog, más recientes primero, con búsqueda y filtros.',
			'input'       => array(
				'properties' => array(
					'search'   => eg_pub_ai_p( 'string', 'Texto a buscar.' ),
					'status'   => eg_pub_ai_p( 'string', 'any (por defecto), publish, draft, pending, future o private.' ),
					'category' => eg_pub_ai_p( 'string', 'Nombre o slug de categoría.' ),
					'lang'     => eg_pub_ai_p( 'string', 'Idioma (webs multidioma).' ),
					'page'     => eg_pub_ai_p( 'integer', 'Página (desde 1).' ),
					'per_page' => eg_pub_ai_p( 'integer', 'Resultados por página (máx. 50, por defecto 20).' ),
				),
			),
			'annotations' => $read,
			'run'         => 'eg_pub_ai_t_list_posts',
		),
		'get_post'       => array(
			'scope'       => 'blog',
			'title'       => 'Ver un post',
			'description' => 'Un post completo: contenido HTML, extracto, categorías, etiquetas, imagen destacada, SEO, idioma y enlaces.',
			'input'       => array(
				'required'   => array( 'id' ),
				'properties' => array( 'id' => eg_pub_ai_p( 'integer', 'ID del post.' ) ),
			),
			'annotations' => $read,
			'run'         => 'eg_pub_ai_t_get_post',
		),
		'create_post'    => array(
			'scope'       => 'blog',
			'title'       => 'Crear un post',
			'description' => 'Crea un post del blog (en borrador salvo que se indique otro status).',
			'input'       => array(
				'required'   => array( 'title', 'content' ),
				'properties' => $post_fields,
			),
			'annotations' => $write,
			'run'         => 'eg_pub_ai_t_create_post',
		),
		'update_post'    => array(
			'scope'       => 'blog',
			'title'       => 'Editar un post',
			'description' => 'Cambia solo los campos que se envían de un post existente.',
			'input'       => array(
				'required'   => array( 'id' ),
				'properties' => array_merge( array( 'id' => eg_pub_ai_p( 'integer', 'ID del post.' ) ), $post_fields ),
			),
			'annotations' => $edit,
			'run'         => 'eg_pub_ai_t_update_post',
		),
		'list_products'  => array(
			'scope'       => 'productos',
			'title'       => 'Listar productos',
			'description' => 'Productos de la tienda con precio y stock, con búsqueda y filtros.',
			'input'       => array(
				'properties' => array(
					'search'   => eg_pub_ai_p( 'string', 'Texto a buscar en el nombre o la descripción.' ),
					'sku'      => eg_pub_ai_p( 'string', 'SKU exacto.' ),
					'status'   => eg_pub_ai_p( 'string', 'any (por defecto), publish, draft, pending o private.' ),
					'category' => eg_pub_ai_p( 'string', 'Nombre o slug de categoría de producto.' ),
					'lang'     => eg_pub_ai_p( 'string', 'Idioma (webs multidioma).' ),
					'page'     => eg_pub_ai_p( 'integer', 'Página (desde 1).' ),
					'per_page' => eg_pub_ai_p( 'integer', 'Resultados por página (máx. 50, por defecto 20).' ),
				),
			),
			'annotations' => $read,
			'run'         => 'eg_pub_ai_t_list_products',
		),
		'get_product'    => array(
			'scope'       => 'productos',
			'title'       => 'Ver un producto',
			'description' => 'Un producto completo por ID o SKU.',
			'input'       => array(
				'properties' => array(
					'id'  => eg_pub_ai_p( 'integer', 'ID del producto.' ),
					'sku' => eg_pub_ai_p( 'string', 'SKU (si no hay ID).' ),
				),
			),
			'annotations' => $read,
			'run'         => 'eg_pub_ai_t_get_product',
		),
		'create_product' => array(
			'scope'       => 'productos',
			'title'       => 'Crear un producto',
			'description' => 'Da de alta un producto simple (en borrador salvo que se indique otro status).',
			'input'       => array(
				'required'   => array( 'name' ),
				'properties' => $product_fields,
			),
			'annotations' => $write,
			'run'         => 'eg_pub_ai_t_create_product',
		),
		'update_product' => array(
			'scope'       => 'productos',
			'title'       => 'Editar un producto',
			'description' => 'Cambia solo los campos que se envían de un producto existente (por ID o SKU). En productos variables solo cambia textos, categorías, imágenes, SEO y estado.',
			'input'       => array(
				'properties' => array_merge( array(
					'id'      => eg_pub_ai_p( 'integer', 'ID del producto.' ),
					'find_sku' => eg_pub_ai_p( 'string', 'SKU del producto a editar (si no hay ID). Para cambiar el SKU usa "sku".' ),
				), $product_fields ),
			),
			'annotations' => $edit,
			'run'         => 'eg_pub_ai_t_update_product',
		),
	);
}

/** Herramientas que puede usar esta conexión. */
function eg_pub_ai_allowed_tools( $ctx ) {
	return array_filter( eg_pub_ai_tool_defs(), function ( $t ) use ( $ctx ) {
		return '' === $t['scope'] ? (bool) $ctx['scopes'] : in_array( $t['scope'], $ctx['scopes'], true );
	} );
}

function eg_pub_ai_tools_list( $ctx ) {
	$out = array();
	foreach ( eg_pub_ai_allowed_tools( $ctx ) as $name => $t ) {
		$schema = array( 'type' => 'object', 'properties' => (object) ( $t['input']['properties'] ?? array() ) );
		if ( ! empty( $t['input']['required'] ) ) {
			$schema['required'] = $t['input']['required'];
		}
		$out[] = array(
			'name'        => $name,
			'title'       => $t['title'],
			'description' => $t['description'],
			'inputSchema' => $schema,
			'annotations' => array_merge( array( 'title' => $t['title'] ), $t['annotations'] ),
		);
	}
	return $out;
}

/** Ejecuta una herramienta y la devuelve en formato MCP (los errores de la herramienta van con isError). */
function eg_pub_ai_tools_call( $name, $args, $ctx ) {
	$tools = eg_pub_ai_allowed_tools( $ctx );
	if ( ! isset( $tools[ $name ] ) ) {
		return new WP_Error( 'eg_ai_tool', isset( eg_pub_ai_tool_defs()[ $name ] ) ? 'Esta conexión no tiene permiso para ' . $name . '.' : 'Herramienta desconocida: ' . $name );
	}
	try {
		$result = call_user_func( $tools[ $name ]['run'], $args, $ctx );
	} catch ( Throwable $e ) {
		$result = new WP_Error( 'eg_ai_exception', $e->getMessage() );
	}
	if ( is_wp_error( $result ) ) {
		return array(
			'content' => array( array( 'type' => 'text', 'text' => 'Error: ' . $result->get_error_message() ) ),
			'isError' => true,
		);
	}
	return array(
		'content'           => array( array( 'type' => 'text', 'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
		'structuredContent' => (object) $result,
	);
}

/* ======================================================================
 * Utilidades
 * ==================================================================== */

/** Estado pedido, rebajado según lo que puede la web y el usuario. Devuelve array( status, nota ). */
function eg_pub_ai_status( $wanted, $publish_cap, $current = 'draft' ) {
	$wanted = null === $wanted ? $current : (string) $wanted;
	if ( ! in_array( $wanted, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
		return array( $current, 'Estado «' . $wanted . '» no válido: se deja en ' . $current . '.' );
	}
	if ( in_array( $wanted, array( 'publish', 'private', 'future' ), true ) ) {
		if ( ! eg_pub_ai_service()['publish'] ) {
			return array( 'pending', 'En esta web el asistente no publica: queda pendiente de revisión.' );
		}
		if ( ! current_user_can( $publish_cap ) ) {
			return array( 'pending', 'Tu usuario no puede publicar: queda pendiente de revisión.' );
		}
	}
	return array( $wanted, '' );
}

function eg_pub_ai_wpml_on() {
	return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' );
}

function eg_pub_ai_post_lang( $id, $type ) {
	if ( ! eg_pub_ai_wpml_on() ) {
		return null;
	}
	$info = apply_filters( 'wpml_post_language_details', null, $id );
	return is_array( $info ) ? ( $info['language_code'] ?? null ) : null;
}

/** Asigna idioma (y traducción) de WPML a una entrada nueva. */
function eg_pub_ai_set_lang( $id, $type, $lang, $translation_of, &$notes ) {
	if ( ! $lang || ! eg_pub_ai_wpml_on() ) {
		return;
	}
	$element = 'post_' . $type;
	$trid    = null;
	$source  = null;
	if ( $translation_of ) {
		if ( get_post_type( $translation_of ) !== $type ) {
			$notes[] = 'translation_of no es un ' . $type . ': se crea sin vincular.';
		} else {
			$trid   = apply_filters( 'wpml_element_trid', null, (int) $translation_of, $element );
			$source = eg_pub_ai_post_lang( (int) $translation_of, $type );
		}
	}
	do_action( 'wpml_set_element_language_details', array(
		'element_id'           => $id,
		'element_type'         => $element,
		'trid'                 => $trid ?: false,
		'language_code'        => sanitize_key( $lang ),
		'source_language_code' => $source,
	) );
}

/** IDs de términos por nombre o slug; crea los que faltan si el usuario puede. */
function eg_pub_ai_term_ids( $names, $taxonomy, &$notes ) {
	$ids = array();
	$tax = get_taxonomy( $taxonomy );
	foreach ( (array) $names as $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		if ( '' === $name ) {
			continue;
		}
		$term = get_term_by( 'name', $name, $taxonomy ) ?: get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
			continue;
		}
		if ( $tax && ( current_user_can( $tax->cap->manage_terms ) || current_user_can( $tax->cap->edit_terms ) ) ) {
			$new = wp_insert_term( $name, $taxonomy );
			if ( ! is_wp_error( $new ) ) {
				$ids[]   = (int) $new['term_id'];
				$notes[] = 'Creada la categoría o etiqueta «' . $name . '».';
				continue;
			}
		}
		$notes[] = 'No existe «' . $name . '» y tu usuario no puede crearla: se ha omitido.';
	}
	return array_values( array_unique( $ids ) );
}

/** Sube (o reutiliza) una imagen y devuelve su ID o WP_Error. */
function eg_pub_ai_image( $img, $parent, $filename ) {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'eg_ai_upload', 'Tu usuario no puede subir archivos.' );
	}
	$img = is_array( $img ) ? $img : array( 'url' => (string) $img );
	if ( ! empty( $img['id'] ) ) {
		if ( 'attachment' !== get_post_type( (int) $img['id'] ) ) {
			return new WP_Error( 'eg_img', 'No existe la imagen ' . (int) $img['id'] . ' en la biblioteca.' );
		}
		if ( ! empty( $img['alt'] ) && current_user_can( 'edit_post', (int) $img['id'] ) ) {
			update_post_meta( (int) $img['id'], '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
		}
		return (int) $img['id'];
	}
	$url = (string) ( $img['url'] ?? '' );
	if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
		return new WP_Error( 'eg_img', 'La URL de la imagen debe empezar por http(s)://.' );
	}
	$b64 = (string) ( $img['base64'] ?? $img['b64'] ?? '' );
	if ( '' === $url && '' === $b64 ) {
		return new WP_Error( 'eg_img', 'Falta la url (o el base64) de la imagen.' );
	}
	if ( strlen( $b64 ) > 14 * 1024 * 1024 ) {
		return new WP_Error( 'eg_img', 'La imagen en base64 es demasiado grande (máx. 10 MB).' );
	}
	$att = eg_pub_sideload_image( $parent, $url, $b64, array(
		'filename' => $img['filename'] ?? $filename,
		'alt'      => $img['alt'] ?? '',
		'title'    => $img['title'] ?? '',
	) );
	return is_wp_error( $att ) ? new WP_Error( 'eg_img', 'Imagen: ' . $att->get_error_message() ) : (int) $att;
}

function eg_pub_ai_image_out( $id ) {
	if ( ! $id ) {
		return null;
	}
	return array(
		'id'  => (int) $id,
		'url' => wp_get_attachment_url( $id ),
		'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
	);
}

function eg_pub_ai_seo_out( $id ) {
	$out = array();
	foreach ( eg_pub_seo_meta_map() as $field => $plugins ) {
		if ( 'canonical_url' === $field ) {
			continue;
		}
		$v = '';
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$v = (string) get_post_meta( $id, $plugins['rank_math'], true );
		}
		if ( '' === $v && defined( 'WPSEO_VERSION' ) ) {
			$v = (string) get_post_meta( $id, $plugins['yoast'], true );
		}
		$out[ $field ] = $v;
	}
	return $out;
}

function eg_pub_ai_seo_in( $id, $args ) {
	$seo = array_intersect_key( $args, array_flip( array( 'seo_title', 'seo_description', 'focus_keyword' ) ) );
	if ( $seo ) {
		eg_pub_seo_save( $id, $seo );
	}
}

/** Fecha local AAAA-MM-DD HH:MM → array( post_date, post_date_gmt ) o null. */
function eg_pub_ai_date( $date ) {
	$date = trim( (string) $date );
	if ( '' === $date ) {
		return null;
	}
	$ts = strtotime( $date . ' ' . wp_timezone_string() );
	if ( ! $ts ) {
		return null;
	}
	return array( wp_date( 'Y-m-d H:i:s', $ts ), gmdate( 'Y-m-d H:i:s', $ts ) );
}

/** Precio con coma o punto decimal («12,50», «1.234,50», «12.5») al formato de WooCommerce. */
function eg_pub_ai_price( $value ) {
	$v = preg_replace( '/[^\d,.\-]/', '', (string) $value );
	if ( false !== strpos( $v, ',' ) ) {
		$v = str_replace( array( '.', ',' ), array( '', '.' ), $v ); // La coma es la decimal: los puntos son de miles.
	}
	return wc_format_decimal( $v );
}

function eg_pub_ai_paging( $args ) {
	return array( max( 1, (int) ( $args['page'] ?? 1 ) ), max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) ) );
}

/* ======================================================================
 * Comunes
 * ==================================================================== */

function eg_pub_ai_t_site_info( $args, $ctx ) {
	$terms = function ( $tax ) {
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		$list = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 200, 'orderby' => 'count', 'order' => 'DESC' ) );
		return is_wp_error( $list ) ? array() : array_map( function ( $t ) {
			return array( 'name' => html_entity_decode( $t->name ), 'slug' => $t->slug, 'count' => (int) $t->count );
		}, $list );
	};
	$service = eg_pub_ai_service();
	$out     = array(
		'name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'description' => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
		'url'         => home_url( '/' ),
		'timezone'    => wp_timezone_string(),
		'now'         => wp_date( 'Y-m-d H:i' ),
		'language'    => get_locale(),
		'user'        => wp_get_current_user()->display_name,
		'scopes'      => $ctx['scopes'],
		'can_publish' => $service['publish'],
		'rules'       => $service['notes'],
	);
	if ( eg_pub_ai_wpml_on() ) {
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$out['languages']        = array_values( array_map( function ( $l ) {
			return array( 'code' => $l['code'], 'name' => $l['native_name'] ?? $l['code'] );
		}, (array) $langs ) );
		$out['default_language'] = apply_filters( 'wpml_default_language', null );
	}
	if ( in_array( 'blog', $ctx['scopes'], true ) ) {
		$out['post_categories'] = $terms( 'category' );
		$out['post_tags']       = array_slice( $terms( 'post_tag' ), 0, 50 );
	}
	if ( in_array( 'productos', $ctx['scopes'], true ) && function_exists( 'get_woocommerce_currency' ) ) {
		$out['currency']           = get_woocommerce_currency();
		$out['prices_include_tax'] = wc_prices_include_tax();
		$out['weight_unit']        = get_option( 'woocommerce_weight_unit' );
		$out['product_categories'] = $terms( 'product_cat' );
		$out['product_tags']       = array_slice( $terms( 'product_tag' ), 0, 50 );
	}
	return $out;
}

function eg_pub_ai_t_upload_image( $args, $ctx ) {
	$id = eg_pub_ai_image( $args, 0, $args['filename'] ?? ( $args['title'] ?? 'imagen' ) );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	eg_pub_ai_log_add( $ctx['id'], 'upload_image', 0, 'Imagen ' . $id );
	return eg_pub_ai_image_out( $id );
}

/* ======================================================================
 * Blog
 * ==================================================================== */

function eg_pub_ai_post_out( WP_Post $p, $full = false ) {
	$out = array(
		'id'         => $p->ID,
		'title'      => $p->post_title,
		'status'     => $p->post_status,
		'date'       => $p->post_date,
		'slug'       => $p->post_name,
		'categories' => wp_get_post_terms( $p->ID, 'category', array( 'fields' => 'names' ) ),
		'link'       => 'publish' === $p->post_status ? get_permalink( $p ) : get_preview_post_link( $p ),
		'edit_link'  => get_edit_post_link( $p->ID, 'raw' ),
	);
	$lang = eg_pub_ai_post_lang( $p->ID, 'post' );
	if ( $lang ) {
		$out['lang'] = $lang;
	}
	if ( $full ) {
		$out['content']        = $p->post_content;
		$out['excerpt']        = $p->post_excerpt;
		$out['tags']           = wp_get_post_terms( $p->ID, 'post_tag', array( 'fields' => 'names' ) );
		$out['featured_image'] = eg_pub_ai_image_out( get_post_thumbnail_id( $p ) );
		$out['seo']            = eg_pub_ai_seo_out( $p->ID );
		$out['elementor']      = 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true );
	}
	return $out;
}

function eg_pub_ai_t_list_posts( $args, $ctx ) {
	list( $page, $per ) = eg_pub_ai_paging( $args );
	$q = array(
		'post_type'      => 'post',
		'post_status'    => in_array( $args['status'] ?? '', array( 'publish', 'draft', 'pending', 'future', 'private' ), true ) ? $args['status'] : array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'posts_per_page' => $per,
		'paged'          => $page,
		's'              => (string) ( $args['search'] ?? '' ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( ! empty( $args['category'] ) ) {
		$term = get_term_by( 'name', $args['category'], 'category' ) ?: get_term_by( 'slug', sanitize_title( $args['category'] ), 'category' );
		$q['cat'] = $term ? $term->term_id : -1;
	}
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		$q['author'] = get_current_user_id();
	}
	if ( eg_pub_ai_wpml_on() && ! empty( $args['lang'] ) ) {
		do_action( 'wpml_switch_language', sanitize_key( $args['lang'] ) );
	} elseif ( eg_pub_ai_wpml_on() ) {
		do_action( 'wpml_switch_language', 'all' );
	}
	$query = new WP_Query( $q );
	return array(
		'total' => (int) $query->found_posts,
		'page'  => $page,
		'pages' => (int) $query->max_num_pages,
		'posts' => array_map( 'eg_pub_ai_post_out', $query->posts ),
	);
}

function eg_pub_ai_get_editable_post( $id, $type ) {
	$p = get_post( (int) $id );
	if ( ! $p || $p->post_type !== $type || in_array( $p->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
		return new WP_Error( 'eg_ai_404', 'No existe ' . ( 'post' === $type ? 'el post' : 'el producto' ) . ' ' . (int) $id . '.' );
	}
	if ( ! current_user_can( 'edit_post', $p->ID ) ) {
		return new WP_Error( 'eg_ai_403', 'Tu usuario no puede editar ' . ( 'post' === $type ? 'este post' : 'este producto' ) . '.' );
	}
	return $p;
}

function eg_pub_ai_t_get_post( $args, $ctx ) {
	$p = eg_pub_ai_get_editable_post( $args['id'] ?? 0, 'post' );
	return is_wp_error( $p ) ? $p : eg_pub_ai_post_out( $p, true );
}

function eg_pub_ai_t_create_post( $args, $ctx ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return new WP_Error( 'eg_ai_403', 'Tu usuario no puede crear posts.' );
	}
	if ( '' === trim( (string) ( $args['title'] ?? '' ) ) ) {
		return new WP_Error( 'eg_ai_args', 'Falta el título.' );
	}
	return eg_pub_ai_save_post( null, $args, $ctx );
}

function eg_pub_ai_t_update_post( $args, $ctx ) {
	$p = eg_pub_ai_get_editable_post( $args['id'] ?? 0, 'post' );
	return is_wp_error( $p ) ? $p : eg_pub_ai_save_post( $p, $args, $ctx );
}

function eg_pub_ai_save_post( $existing, $args, $ctx ) {
	$notes = array();
	$data  = array( 'post_type' => 'post' );
	if ( $existing ) {
		$data['ID'] = $existing->ID;
		if ( isset( $args['content'] ) && 'builder' === get_post_meta( $existing->ID, '_elementor_edit_mode', true ) ) {
			return new WP_Error( 'eg_ai_elementor', 'Este post está maquetado con Elementor: su contenido no se puede cambiar desde aquí sin romper el diseño. Cambia el resto de campos o pide el cambio a la agencia.' );
		}
	} else {
		$data['post_author'] = get_current_user_id();
	}
	foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' ) as $in => $field ) {
		if ( isset( $args[ $in ] ) && is_scalar( $args[ $in ] ) ) {
			$data[ $field ] = 'slug' === $in ? sanitize_title( $args[ $in ] ) : (string) $args[ $in ];
		}
	}
	$date = isset( $args['date'] ) ? eg_pub_ai_date( $args['date'] ) : null;
	if ( isset( $args['date'] ) && ! $date ) {
		$notes[] = 'Fecha no válida: se ignora.';
	}
	if ( $date ) {
		list( $data['post_date'], $data['post_date_gmt'] ) = $date;
		$data['edit_date'] = true;
	}
	list( $status, $note ) = eg_pub_ai_status( $args['status'] ?? null, 'publish_posts', $existing ? $existing->post_status : 'draft' );
	if ( $note ) {
		$notes[] = $note;
	}
	if ( 'publish' === $status && $date && strtotime( $date[1] . ' UTC' ) > time() ) {
		$status = 'future';
	}
	if ( ! $existing || isset( $args['status'] ) ) {
		$data['post_status'] = $status;
	}

	if ( ! $existing && eg_pub_ai_wpml_on() && ! empty( $args['lang'] ) ) {
		do_action( 'wpml_switch_language', sanitize_key( $args['lang'] ) ); // Categorías del idioma correcto.
	}
	$id = $existing ? wp_update_post( wp_slash( $data ), true ) : wp_insert_post( wp_slash( $data ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( ! $existing ) {
		eg_pub_ai_set_lang( $id, 'post', $args['lang'] ?? '', (int) ( $args['translation_of'] ?? 0 ), $notes );
	}
	if ( isset( $args['categories'] ) ) {
		wp_set_post_terms( $id, eg_pub_ai_term_ids( $args['categories'], 'category', $notes ), 'category' );
	}
	if ( isset( $args['tags'] ) ) {
		wp_set_post_terms( $id, eg_pub_ai_term_ids( $args['tags'], 'post_tag', $notes ), 'post_tag' );
	}
	if ( ! empty( $args['featured_image'] ) ) {
		$img = eg_pub_ai_image( $args['featured_image'], $id, get_post_field( 'post_name', $id ) ?: sanitize_title( $args['title'] ?? 'post' ) );
		if ( is_wp_error( $img ) ) {
			$notes[] = $img->get_error_message();
		} else {
			set_post_thumbnail( $id, $img );
		}
	}
	eg_pub_ai_seo_in( $id, $args );
	$post = get_post( $id );
	eg_pub_ai_log_add( $ctx['id'], $existing ? 'update_post' : 'create_post', $id, $post->post_title . ' (' . $post->post_status . ')' );
	$out = eg_pub_ai_post_out( $post );
	if ( $notes ) {
		$out['notes'] = $notes;
	}
	return $out;
}

/* ======================================================================
 * Productos (WooCommerce)
 * ==================================================================== */

function eg_pub_ai_product_out( WC_Product $p, $full = false ) {
	$out = array(
		'id'             => $p->get_id(),
		'name'           => $p->get_name(),
		'type'           => $p->get_type(),
		'status'         => $p->get_status(),
		'sku'            => $p->get_sku(),
		'regular_price'  => $p->get_regular_price(),
		'sale_price'     => $p->get_sale_price(),
		'price'          => $p->get_price(),
		'stock_status'   => $p->get_stock_status(),
		'stock_quantity' => $p->get_manage_stock() ? $p->get_stock_quantity() : null,
		'categories'     => wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
		'link'           => 'publish' === $p->get_status() ? $p->get_permalink() : get_preview_post_link( $p->get_id() ),
		'edit_link'      => get_edit_post_link( $p->get_id(), 'raw' ),
	);
	$lang = eg_pub_ai_post_lang( $p->get_id(), 'product' );
	if ( $lang ) {
		$out['lang'] = $lang;
	}
	if ( $full ) {
		$out['description']       = $p->get_description();
		$out['short_description'] = $p->get_short_description();
		$out['slug']              = $p->get_slug();
		$out['weight']            = $p->get_weight();
		$out['tags']              = wp_get_post_terms( $p->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		$out['images']            = array_values( array_filter( array_map( 'eg_pub_ai_image_out', array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) ) ) );
		$out['seo']               = eg_pub_ai_seo_out( $p->get_id() );
		if ( $p->is_type( 'variable' ) ) {
			$out['variations'] = array_map( function ( $vid ) {
				$v = wc_get_product( $vid );
				return $v ? array( 'id' => $vid, 'attributes' => $v->get_attributes(), 'sku' => $v->get_sku(), 'price' => $v->get_price(), 'stock_status' => $v->get_stock_status() ) : null;
			}, array_slice( $p->get_children(), 0, 50 ) );
		}
	}
	return $out;
}

function eg_pub_ai_t_list_products( $args, $ctx ) {
	list( $page, $per ) = eg_pub_ai_paging( $args );
	$q = array(
		'post_type'      => 'product',
		'post_status'    => in_array( $args['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $args['status'] : array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => $per,
		'paged'          => $page,
		's'              => (string) ( $args['search'] ?? '' ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( ! empty( $args['sku'] ) ) {
		$q['meta_query'] = array( array( 'key' => '_sku', 'value' => (string) $args['sku'] ) );
	}
	if ( ! empty( $args['category'] ) ) {
		$term = get_term_by( 'name', $args['category'], 'product_cat' ) ?: get_term_by( 'slug', sanitize_title( $args['category'] ), 'product_cat' );
		$q['tax_query'] = array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term ? $term->term_id : -1 ) );
	}
	if ( eg_pub_ai_wpml_on() ) {
		do_action( 'wpml_switch_language', ! empty( $args['lang'] ) ? sanitize_key( $args['lang'] ) : 'all' );
	}
	$query = new WP_Query( $q );
	$items = array();
	foreach ( $query->posts as $post ) {
		$p = wc_get_product( $post );
		if ( $p ) {
			$items[] = eg_pub_ai_product_out( $p );
		}
	}
	return array( 'total' => (int) $query->found_posts, 'page' => $page, 'pages' => (int) $query->max_num_pages, 'products' => $items );
}

/** Producto editable por id o SKU. */
function eg_pub_ai_find_product( $id, $sku ) {
	if ( ! $id && '' !== (string) $sku ) {
		$id = wc_get_product_id_by_sku( (string) $sku );
		if ( ! $id ) {
			return new WP_Error( 'eg_ai_404', 'No hay ningún producto con el SKU ' . $sku . '.' );
		}
	}
	if ( ! $id ) {
		return new WP_Error( 'eg_ai_args', 'Indica el id o el SKU del producto.' );
	}
	$post = eg_pub_ai_get_editable_post( (int) $id, 'product' );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	return wc_get_product( $post->ID ) ?: new WP_Error( 'eg_ai_404', 'No existe el producto ' . (int) $id . '.' );
}

function eg_pub_ai_t_get_product( $args, $ctx ) {
	$p = eg_pub_ai_find_product( (int) ( $args['id'] ?? 0 ), (string) ( $args['sku'] ?? '' ) );
	return is_wp_error( $p ) ? $p : eg_pub_ai_product_out( $p, true );
}

function eg_pub_ai_t_create_product( $args, $ctx ) {
	if ( ! current_user_can( 'edit_products' ) ) {
		return new WP_Error( 'eg_ai_403', 'Tu usuario no puede crear productos.' );
	}
	if ( '' === trim( (string) ( $args['name'] ?? '' ) ) ) {
		return new WP_Error( 'eg_ai_args', 'Falta el nombre del producto.' );
	}
	return eg_pub_ai_save_product( null, $args, $ctx );
}

function eg_pub_ai_t_update_product( $args, $ctx ) {
	$p = eg_pub_ai_find_product( (int) ( $args['id'] ?? 0 ), (string) ( $args['find_sku'] ?? '' ) );
	return is_wp_error( $p ) ? $p : eg_pub_ai_save_product( $p, $args, $ctx );
}

function eg_pub_ai_save_product( $product, $args, $ctx ) {
	$notes    = array();
	$existing = (bool) $product;
	if ( ! $existing ) {
		if ( eg_pub_ai_wpml_on() && ! empty( $args['lang'] ) ) {
			do_action( 'wpml_switch_language', sanitize_key( $args['lang'] ) );
		}
		$product = new WC_Product_Simple();
	}
	$variable = $product->is_type( 'variable' );

	if ( isset( $args['name'] ) ) {
		$product->set_name( wp_strip_all_tags( (string) $args['name'] ) );
	}
	foreach ( array( 'description', 'short_description' ) as $f ) {
		if ( isset( $args[ $f ] ) ) {
			$html = current_user_can( 'unfiltered_html' ) ? (string) $args[ $f ] : wp_kses_post( (string) $args[ $f ] );
			$product->{'set_' . $f}( $html );
		}
	}
	if ( isset( $args['slug'] ) ) {
		$product->set_slug( sanitize_title( $args['slug'] ) );
	}
	$price_keys = array( 'regular_price', 'sale_price', 'stock_quantity', 'stock_status', 'weight', 'sku' );
	if ( $variable && array_intersect( array_diff( $price_keys, array( 'sku' ) ), array_keys( $args ) ) ) {
		$notes[] = 'Es un producto variable: el precio, el stock y el peso van en cada variación y no se han cambiado.';
	} else {
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( eg_pub_ai_price( $args['regular_price'] ) );
		}
		if ( array_key_exists( 'sale_price', $args ) ) {
			$product->set_sale_price( '' === (string) $args['sale_price'] ? '' : eg_pub_ai_price( $args['sale_price'] ) );
		}
		if ( array_key_exists( 'stock_quantity', $args ) ) {
			if ( null === $args['stock_quantity'] || '' === $args['stock_quantity'] ) {
				$product->set_manage_stock( false );
			} else {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( (int) $args['stock_quantity'] );
			}
		}
		if ( ! empty( $args['stock_status'] ) && ! $product->get_manage_stock() ) {
			$product->set_stock_status( (string) $args['stock_status'] );
		}
		if ( isset( $args['weight'] ) ) {
			$product->set_weight( eg_pub_ai_price( $args['weight'] ) );
		}
	}
	if ( isset( $args['sku'] ) ) {
		try {
			$product->set_sku( wc_clean( (string) $args['sku'] ) );
		} catch ( WC_Data_Exception $e ) {
			return new WP_Error( 'eg_ai_sku', 'SKU: ' . $e->getMessage() );
		}
	}
	list( $status, $note ) = eg_pub_ai_status( $args['status'] ?? null, 'publish_products', $existing ? $product->get_status() : 'draft' );
	if ( $note ) {
		$notes[] = $note;
	}
	if ( ! $existing || isset( $args['status'] ) ) {
		$product->set_status( 'future' === $status ? 'publish' : $status );
	}
	if ( isset( $args['categories'] ) ) {
		$product->set_category_ids( eg_pub_ai_term_ids( $args['categories'], 'product_cat', $notes ) );
	}
	if ( isset( $args['tags'] ) ) {
		$product->set_tag_ids( eg_pub_ai_term_ids( $args['tags'], 'product_tag', $notes ) );
	}

	$id = $product->save();
	if ( ! $id ) {
		return new WP_Error( 'eg_ai_save', 'No se ha podido guardar el producto.' );
	}
	if ( ! $existing ) {
		eg_pub_ai_set_lang( $id, 'product', $args['lang'] ?? '', (int) ( $args['translation_of'] ?? 0 ), $notes );
	}

	if ( isset( $args['images'] ) && is_array( $args['images'] ) ) {
		$ids  = array();
		$base = $product->get_slug() ?: sanitize_title( $product->get_name() );
		foreach ( array_values( $args['images'] ) as $i => $img ) {
			$att = eg_pub_ai_image( $img, $id, $base . ( $i ? '-' . ( $i + 1 ) : '' ) );
			if ( is_wp_error( $att ) ) {
				$notes[] = $att->get_error_message();
			} else {
				$ids[] = $att;
			}
		}
		if ( $ids || ! $args['images'] ) {
			$product->set_image_id( $ids ? array_shift( $ids ) : '' );
			$product->set_gallery_image_ids( $ids );
			$product->save();
		}
	}
	eg_pub_ai_seo_in( $id, $args );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $id );
	}
	$product = wc_get_product( $id );
	eg_pub_ai_log_add( $ctx['id'], $existing ? 'update_product' : 'create_product', $id, $product->get_name() . ' (' . $product->get_status() . ')' );
	$out = eg_pub_ai_product_out( $product );
	if ( $notes ) {
		$out['notes'] = $notes;
	}
	return $out;
}
