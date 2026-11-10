<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Preset_Ajax {

	public function __construct() {
		add_action( 'wp_ajax_es_preset_list', [ $this, 'list_presets' ] );
		add_action( 'wp_ajax_es_preset_save_as', [ $this, 'save_as' ] );
		add_action( 'wp_ajax_es_preset_update', [ $this, 'update' ] );
		add_action( 'wp_ajax_es_preset_delete', [ $this, 'delete' ] );
	}

	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		check_ajax_referer( 'es_preset_ajax', 'nonce' );
	}

	private function posted_where(): array {
		$raw = isset( $_POST['rules'] ) ? json_decode( wp_unslash( (string) $_POST['rules'] ), true ) : [];
		return Emblem_Studio_Display_Rules::sanitize_where( is_array( $raw ) ? $raw : [] );
	}

	private function present( object $p ): array {
		return Emblem_Studio_Preset_Repo::to_admin_payload( $p ) + [
			'usage_count' => Emblem_Studio_Preset_Repo::usage_count( (int) $p->id ),
		];
	}

	public function list_presets(): void {
		$this->authorize();
		$out = array_map( [ $this, 'present' ], Emblem_Studio_Preset_Repo::all() );
		wp_send_json_success( [ 'presets' => array_values( $out ) ] );
	}

	public function save_as(): void {
		$this->authorize();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		if ( $name === '' ) {
			wp_send_json_error( [ 'message' => 'Name required' ], 422 );
		}
		$where = $this->posted_where();

		$existing_id = 0;
		foreach ( Emblem_Studio_Preset_Repo::all() as $p ) {
			if ( strcasecmp( (string) $p->name, $name ) === 0 ) {
				$existing_id = (int) $p->id;
				break;
			}
		}
		$id = Emblem_Studio_Preset_Repo::save( [ 'name' => $name ] + $where, $existing_id ?: null );
		Emblem_Studio_Preset_Repo::apply_to_forms( $id, $where );

		$preset = Emblem_Studio_Preset_Repo::find( $id );
		wp_send_json_success( [ 'preset' => $this->present( $preset ) ] );
	}

	public function update(): void {
		$this->authorize();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! Emblem_Studio_Preset_Repo::find( $id ) ) {
			wp_send_json_error( [ 'message' => 'Not found' ], 404 );
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		if ( $name === '' ) {
			wp_send_json_error( [ 'message' => 'Name required' ], 422 );
		}
		$where = $this->posted_where();
		Emblem_Studio_Preset_Repo::save( [ 'name' => $name ] + $where, $id );
		Emblem_Studio_Preset_Repo::apply_to_forms( $id, $where );

		wp_send_json_success( [ 'preset' => $this->present( Emblem_Studio_Preset_Repo::find( $id ) ) ] );
	}

	public function delete(): void {
		$this->authorize();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing id' ], 422 );
		}
		Emblem_Studio_Preset_Repo::delete( $id );
		wp_send_json_success( [ 'deleted' => true ] );
	}
}
