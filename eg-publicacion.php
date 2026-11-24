<?php
/**
 * Plugin Name:       EG Studio (cargador antiguo)
 * Description:       Paso automático de la carpeta eg-publicacion/ a eg-studio/. Se borra solo en cuanto el plugin se pasa a su carpeta nueva.
 * Version:           2.140.0
 * Author:            enricgomez Studio
 * Update URI:        https://github.com/enricgomez-Studio/mcp_wpplugin
 * License:           GPL-3.0-or-later
 *
 * Hasta la v2.138.0 el plugin vivía en eg-publicacion/ con este archivo como principal. Solo va en eg-publicacion.zip
 * (el que descargan las versiones antiguas): las webs que aún lo tienen activo así cargan EG Studio desde aquí y, al
 * final de la siguiente petición, se pasan solas a eg-studio/eg-studio.php y lo borran (ver includes/migracion.php).
 * Lleva cabecera para que WordPress pueda reactivarlo tras actualizar desde el escritorio.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'EG_PUB_LEGACY_LOADER' ) ) {
	define( 'EG_PUB_LEGACY_LOADER', __FILE__ );
}
require_once __DIR__ . '/eg-studio.php';
