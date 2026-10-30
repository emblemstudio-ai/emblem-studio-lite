<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-authenticated AJAX for display-rule presets (SER-899 phase 2).
 *
 * Only actions that must persist an actual PRESET row hit the server here —
 * "save current as preset", and the Manage Presets modal's list/update/delete.
 * Applying/changing/un-linking a preset onto one shell is client-side only
 * (admin.js) until the page's normal Save, matching the SaaS picker's own
 * local-state-until-Save behavior; that coupling never needs an AJAX call.
 */
class ES_Preset_Ajax {

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

	/** WHERE fields (show_mode/show_filters/hide_filters) from the posted JSON blob. */
	private function posted_where(): array {
		$raw = isset( $_POST['rules'] ) ? json_decode( wp_unslash( (string) $_POST['rules'] ), true ) : [];
		return ES_Display_Rules::sanitize_where( is_array( $raw ) ? $raw : [] );
	}

	/** Same shape form-edit.php embeds per-page, plus usage_count — one source of truth. */
	private function present( object $p ): array {
		return ES_Preset_Repo::to_admin_payload( $p ) + [
			'usage_count' => ES_Preset_Repo::usage_count( (int) $p->id ),
		];
	}

	/** For the Manage Presets modal — every preset, with a usage count. */
	public function list_presets(): void {
		$this->authorize();
		$out = array_map( [ $this, 'present' ], ES_Preset_Repo::all() );
		wp_send_json_success( [ 'presets' => array_values( $out ) ] );
	}

	/**
	 * "Save current as preset" (picker) — an existing name updates that preset
	 * in place (cascading to every other shell following it) instead of making
	 * a duplicate, same "Save As" convention as the SaaS picker.
	 */
	public function save_as(): void {
		$this->authorize();
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		if ( $name === '' ) {
			wp_send_json_error( [ 'message' => 'Name required' ], 422 );
		}
		$where = $this->posted_where();

		$existing_id = 0;
		foreach ( ES_Preset_Repo::all() as $p ) {
			if ( strcasecmp( (string) $p->name, $name ) === 0 ) {
				$existing_id = (int) $p->id;
				break;
			}
		}
		$id = ES_Preset_Repo::save( [ 'name' => $name ] + $where, $existing_id ?: null );
		ES_Preset_Repo::apply_to_forms( $id, $where );

		$preset = ES_Preset_Repo::find( $id );
		wp_send_json_success( [ 'preset' => $this->present( $preset ) ] );
	}

	/** Manage Presets modal — edit an existing preset's name/values directly. */
	public function update(): void {
		$this->authorize();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id || ! ES_Preset_Repo::find( $id ) ) {
			wp_send_json_error( [ 'message' => 'Not found' ], 404 );
		}
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		if ( $name === '' ) {
			wp_send_json_error( [ 'message' => 'Name required' ], 422 );
		}
		$where = $this->posted_where();
		ES_Preset_Repo::save( [ 'name' => $name ] + $where, $id );
		ES_Preset_Repo::apply_to_forms( $id, $where );

		wp_send_json_success( [ 'preset' => $this->present( ES_Preset_Repo::find( $id ) ) ] );
	}

	/** Manage Presets modal — delete, un-linking every shell that followed it. */
	public function delete(): void {
		$this->authorize();
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing id' ], 422 );
		}
		ES_Preset_Repo::delete( $id );
		wp_send_json_success( [ 'deleted' => true ] );
	}
}
