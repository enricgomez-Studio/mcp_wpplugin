<?php
/**
 * Tipología «Gestión de la web»: usuarios del WordPress del cliente.
 *   POST /wp-json/eg/v1/users
 *   list: true        lista los usuarios (id, login, email, nombre, roles, alta) y los roles disponibles.
 *   create: {login, email, first_name, last_name, role, notify}
 *                     crea un usuario con contraseña aleatoria (nadie la conoce). notify (por defecto true)
 *                     envía al usuario el correo estándar de WordPress con el enlace para elegir su contraseña.
 *                     No se crean administradores: el rol debe existir y no tener manage_options.
 *   Sin apply:true solo previsualiza.
 * Requiere que el autor configurado pueda create_users (administrador).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['usuarios'] = array( 'type' => 'gestion', 'routes' => array( 'users' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'usuarios' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/users', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_users_handle',
		'permission_callback' => eg_pub_module_permission( 'create_users' ),
	) );
} );

function eg_pub_users_handle( WP_REST_Request $request ) {
	$p     = (array) $request->get_json_params();
	$apply = ! empty( $p['apply'] );

	if ( ! empty( $p['list'] ) ) {
		$out = array();
		foreach ( get_users( array( 'orderby' => 'ID' ) ) as $u ) {
			$out[] = array(
				'id'         => $u->ID,
				'login'      => $u->user_login,
				'email'      => $u->user_email,
				'name'       => $u->display_name,
				'roles'      => array_values( $u->roles ),
				'registered' => $u->user_registered,
			);
		}
		$roles = array();
		foreach ( wp_roles()->roles as $slug => $r ) {
			$roles[ $slug ] = translate_user_role( $r['name'] );
		}
		return array( 'users' => $out, 'roles' => $roles );
	}

	if ( ! empty( $p['create'] ) && is_array( $p['create'] ) ) {
		$c     = $p['create'];
		$email = sanitize_email( (string) ( $c['email'] ?? '' ) );
		$login = sanitize_user( (string) ( $c['login'] ?? '' ), true );
		$role  = sanitize_key( (string) ( $c['role'] ?? 'editor' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'eg_user_email', 'Email no válido.', array( 'status' => 400 ) );
		}
		if ( '' === $login ) {
			$login = sanitize_user( strstr( $email, '@', true ), true );
		}
		$role_obj = get_role( $role );
		if ( ! $role_obj ) {
			return new WP_Error( 'eg_user_role', 'El rol no existe: ' . $role, array( 'status' => 400 ) );
		}
		if ( $role_obj->has_cap( 'manage_options' ) ) {
			return new WP_Error( 'eg_user_role', 'No se crean administradores por esta vía.', array( 'status' => 400 ) );
		}
		if ( username_exists( $login ) ) {
			return new WP_Error( 'eg_user_exists', 'Ya existe el usuario ' . $login, array( 'status' => 409 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'eg_user_exists', 'Ya existe un usuario con ese email.', array( 'status' => 409 ) );
		}
		$data   = array(
			'user_login' => $login,
			'user_email' => $email,
			'first_name' => sanitize_text_field( (string) ( $c['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( (string) ( $c['last_name'] ?? '' ) ),
			'role'       => $role,
		);
		$notify = ! isset( $c['notify'] ) || ! empty( $c['notify'] );
		if ( ! $apply ) {
			return array( 'dry_run' => true, 'would_create' => $data, 'notify' => $notify );
		}
		$data['user_pass']    = wp_generate_password( 24, true, true );
		$data['display_name'] = trim( $data['first_name'] . ' ' . $data['last_name'] ) ?: $login;
		$id                   = wp_insert_user( $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $notify ) {
			wp_new_user_notification( $id, null, 'user' );
		}
		return array( 'created' => $id, 'login' => $login, 'email' => $email, 'role' => $role, 'notified' => $notify );
	}

	return new WP_Error( 'eg_users_args', 'Usa list:true o create:{...}.', array( 'status' => 400 ) );
}
