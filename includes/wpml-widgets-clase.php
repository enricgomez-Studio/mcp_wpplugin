<?php
/**
 * Módulo «wpml-widgets»: clase que WPML usa para los repetidores de los widgets registrados por EG Publicación.
 * Se carga desde eg_pub_wpml_widgets_define_class() solo cuando existe WPML_Elementor_Module_With_Items.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EG_Pub_WPML_Items_Module extends WPML_Elementor_Module_With_Items {

	/** Widget de Elementor que traduce esta instancia (de momento, solo «slides»). */
	protected $eg_widget = 'slides';

	protected function eg_def() {
		$known = eg_pub_wpml_widgets_known();
		return $known[ $this->eg_widget ];
	}

	public function get_items_field() {
		$def = $this->eg_def();
		return $def['items_field'];
	}

	public function get_fields() {
		$fields = array();
		foreach ( $this->eg_def()['items'] as $key => $d ) {
			if ( isset( $d[2] ) ) {
				$fields[ $key ] = array( $d[2] );
			} else {
				$fields[] = $key;
			}
		}
		return $fields;
	}

	protected function eg_field( $field ) {
		foreach ( $this->eg_def()['items'] as $key => $d ) {
			if ( $key === $field || ( isset( $d[2] ) && $d[2] === $field ) ) {
				return $d;
			}
		}
		return null;
	}

	public function get_title( $field ) {
		$d = $this->eg_field( $field );
		return $d ? $d[0] : '';
	}

	public function get_editor_type( $field ) {
		$d = $this->eg_field( $field );
		return $d ? $d[1] : 'LINE';
	}
}
