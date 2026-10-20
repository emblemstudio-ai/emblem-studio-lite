<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Form_Repo {

	const CLOSE_THEMES = [ 'light', 'dark', 'auto' ];

	public static function clean_name( $raw ): string {
		$name = wp_check_invalid_utf8( is_scalar( $raw ) ? (string) $raw : '' );
		$name = preg_replace( '/[\r\n\t\x00-\x08\x0B\x0C\x0E-\x1F]+/', ' ', $name ) ?? '';
		$name = trim( preg_replace( '/ {2,}/', ' ', $name ) ?? '' );
		return mb_substr( $name, 0, 191 );
	}

	public static function close_theme( $value, string $fallback = 'light' ): string {
		return is_string( $value ) && in_array( $value, self::CLOSE_THEMES, true ) ? $value : $fallback;
	}

	public static function table(): string {
		return ES_Install::table( 'forms' );
	}

	public static function next_id(): int {
		global $wpdb;
		$t  = self::table();
		$ai = $wpdb->get_var( $wpdb->prepare(
			'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			$wpdb->dbname,
			$t
		) );
		if ( $ai ) {
			return (int) $ai;
		}
		return ( (int) $wpdb->get_var( "SELECT MAX(id) FROM $t" ) ) + 1; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function all_funnelkit_tags(): array {
		global $wpdb;
		$rows = (array) $wpdb->get_col( 'SELECT funnelkit_tags FROM ' . self::table() . " WHERE funnelkit_tags IS NOT NULL AND funnelkit_tags <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = [];
		foreach ( $rows as $raw ) {
			$tags = json_decode( (string) $raw, true );
			foreach ( (array) ( is_array( $tags ) ? $tags : [] ) as $tag ) {
				$name = trim( (string) $tag );
				$key  = self::tag_key( $name );
				if ( $name !== '' && ! isset( $out[ $key ] ) ) {
					$out[ $key ] = $name;
				}
			}
		}
		sort( $out );
		return $out;
	}

	public static function tag_key( string $name ): string {
		return mb_strtolower( trim( preg_replace( '/\s+/', ' ', $name ) ?? '' ) );
	}

	public static function all( string $filter = 'all' ): array {
		global $wpdb;
		$t = self::table();
		self::reconcile_statuses();
		if ( $filter === 'trash' ) {
			return $wpdb->get_results( "SELECT * FROM $t WHERE trashed_at IS NOT NULL ORDER BY trashed_at DESC" );
		}
		if ( $filter === 'archived' ) {
			return $wpdb->get_results( "SELECT * FROM $t WHERE trashed_at IS NULL AND archived_at IS NOT NULL ORDER BY archived_at DESC" );
		}
		$where  = 'trashed_at IS NULL AND archived_at IS NULL';
		$params = [];
		if ( in_array( $filter, [ 'active', 'inactive', 'scheduled', 'expired' ], true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filter;
		}
		$sql = "SELECT * FROM $t WHERE $where ORDER BY display_order DESC, id DESC";
		return $params
			? $wpdb->get_results( $wpdb->prepare( $sql, $params ) )
			: $wpdb->get_results( $sql );
	}

	public static function counts_by_filter(): array {
		global $wpdb;
		$t = self::table();
		self::reconcile_statuses();
		$row = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL THEN 1 ELSE 0 END)                              AS all_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'active'    THEN 1 ELSE 0 END)     AS active_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'inactive'  THEN 1 ELSE 0 END)     AS inactive_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'scheduled' THEN 1 ELSE 0 END)     AS scheduled_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NULL AND status = 'expired'   THEN 1 ELSE 0 END)     AS expired_count,
				SUM(CASE WHEN trashed_at IS NULL AND archived_at IS NOT NULL THEN 1 ELSE 0 END)                         AS archived_count,
				SUM(CASE WHEN trashed_at IS NOT NULL THEN 1 ELSE 0 END)                                                 AS trash_count
			FROM $t"
		);
		return [
			'all'       => (int) ( $row->all_count       ?? 0 ),
			'active'    => (int) ( $row->active_count    ?? 0 ),
			'inactive'  => (int) ( $row->inactive_count  ?? 0 ),
			'scheduled' => (int) ( $row->scheduled_count ?? 0 ),
			'expired'   => (int) ( $row->expired_count   ?? 0 ),
			'archived'  => (int) ( $row->archived_count  ?? 0 ),
			'trash'     => (int) ( $row->trash_count     ?? 0 ),
		];
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$t = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
		return $row ?: null;
	}

	public static function update_display_rules( int $form_id, array $rules ): void {
		if ( $form_id <= 0 ) {
			return;
		}
		global $wpdb;
		$sanitized = ES_Display_Rules::sanitize_all_shells( $rules );
		$wpdb->update(
			self::table(),
			[
				'display_rules' => wp_json_encode( $sanitized ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $form_id ]
		);
		self::flush_caches();
	}

	public static function save( array $data, ?int $id = null ): int {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql' );

		$lists = isset( $data['funnelkit_lists'] ) && is_array( $data['funnelkit_lists'] )
			? array_values( array_filter( array_map( 'intval', $data['funnelkit_lists'] ) ) )
			: [];
		$tags  = isset( $data['funnelkit_tags'] ) && is_array( $data['funnelkit_tags'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', $data['funnelkit_tags'] ) ) ) )
			: [];

		$mapping = [];
		if ( isset( $data['field_mapping'] ) && is_array( $data['field_mapping'] ) ) {
			foreach ( $data['field_mapping'] as $fk_key => $form_field ) {
				$fk_key     = sanitize_text_field( (string) $fk_key );
				$form_field = sanitize_text_field( (string) $form_field );
				if ( $fk_key !== '' && $form_field !== '' ) {
					$mapping[ $fk_key ] = $form_field;
				}
			}
		}

		$valid_types = self::valid_types();
		$types       = isset( $data['form_types'] ) && is_array( $data['form_types'] )
			? array_values( array_intersect( array_map( 'sanitize_text_field', $data['form_types'] ), $valid_types ) )
			: [ 'inline' ];
		if ( ! ES_Caps::has( 'auto_shells' ) ) {
			$types = array_values( array_diff( $types, [ 'popup-auto', 'ribbon', 'slide-in' ] ) );
			if ( empty( $types ) ) {
				$types = [ 'inline' ];
			}
		}
		$types = self::enforce_shell_exclusivity( $types );

		$enabled = array_key_exists( 'enabled', $data )
			? (bool) $data['enabled']
			: in_array( ( $data['status'] ?? 'inactive' ), [ 'active', 'scheduled', 'expired' ], true );
		$success_mode = $data['success_mode'] ?? 'inline_state';
		$success_mode = in_array( $success_mode, [ 'inline_state', 'redirect' ], true ) ? $success_mode : 'inline_state';

		$row = [
			'name'                 => $data['name'] ?? '',
			'slug'                 => $data['slug'] ?? '',
			'html'                 => ES_HTML_Sanitizer::sanitize( (string) ( $data['html'] ?? '' ) ),
			'css'                  => ES_HTML_Sanitizer::sanitize_css( (string) ( $data['css'] ?? '' ) ),
			'enabled'              => $enabled ? 1 : 0,
			'status'               => $enabled ? 'active' : 'inactive',
			'funnelkit_list_id'    => $lists ? $lists[0] : null,
			'funnelkit_lists'      => $lists ? wp_json_encode( $lists ) : null,
			'funnelkit_tags'       => $tags  ? wp_json_encode( $tags )  : null,
			'field_mapping'        => $mapping ? wp_json_encode( $mapping ) : null,
			'form_types'           => wp_json_encode( $types ),
			'success_mode'         => $success_mode,
			'success_message'      => $data['success_message'] ?? null,
			'success_redirect_url' => $data['success_redirect_url'] ?? null,
			'updated_at'           => $now,
		];

		if ( isset( $data['auto_trigger_config'] ) && is_array( $data['auto_trigger_config'] ) ) {
			$row['auto_trigger_config'] = wp_json_encode( self::sanitize_all_shell_trigger_configs( $data['auto_trigger_config'] ) );
		}

		if ( isset( $data['display_rules'] ) && is_array( $data['display_rules'] ) ) {
			$row['display_rules'] = wp_json_encode( ES_Display_Rules::sanitize_all_shells( $data['display_rules'] ) );
		}

		if ( array_key_exists( 'use_shadow_dom', $data ) ) {
			$row['use_shadow_dom'] = ! empty( $data['use_shadow_dom'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'use_design_system', $data ) ) {
			$row['use_design_system'] = ! empty( $data['use_design_system'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'popup_max_width', $data ) ) {
			$row['popup_max_width'] = max( 100, min( 2000, (int) ( $data['popup_max_width'] ?: 720 ) ) );
		}

		if ( array_key_exists( 'popup_fullscreen', $data ) ) {
			$row['popup_fullscreen'] = ! empty( $data['popup_fullscreen'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'popup_close_theme', $data ) ) {
			$row['popup_close_theme'] = self::close_theme( $data['popup_close_theme'], 'dark' );
		}

		if ( array_key_exists( 'track_clicks', $data ) ) {
			$row['track_clicks'] = ! empty( $data['track_clicks'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'track_purchases', $data ) ) {
			$row['track_purchases'] = ! empty( $data['track_purchases'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'funnelkit_enabled', $data ) ) {
			$row['funnelkit_enabled'] = ! empty( $data['funnelkit_enabled'] ) ? 1 : 0;
		}

		if ( array_key_exists( 'webhooks', $data ) && is_array( $data['webhooks'] ) && class_exists( 'ES_Webhooks' ) ) {
			$clean = ES_Webhooks::sanitize_array( $data['webhooks'] );
			$row['webhooks'] = $clean ? wp_json_encode( $clean ) : null;
		}

		if ( array_key_exists( 'connections', $data ) && class_exists( 'ES_Connections' ) ) {
			$clean = ES_Connections::sanitize( is_array( $data['connections'] ) ? $data['connections'] : [] );
			$row['connections'] = ES_Connections::is_active( $clean ) ? wp_json_encode( $clean ) : null;
			ES_Connections::flush();
		}

		if ( array_key_exists( 'active_from', $data ) ) {
			$row['active_from'] = self::normalize_datetime( $data['active_from'], false );
		}
		if ( array_key_exists( 'active_until', $data ) ) {
			$row['active_until'] = self::normalize_datetime( $data['active_until'], true );
		}

		$existing = $id ? self::find( $id ) : null;
		$resolved = (object) [
			'enabled'      => $row['enabled'],
			'trashed_at'   => $existing->trashed_at  ?? null,
			'archived_at'  => $existing->archived_at ?? null,
			'active_from'  => array_key_exists( 'active_from', $row )  ? $row['active_from']  : ( $existing->active_from  ?? null ),
			'active_until' => array_key_exists( 'active_until', $row ) ? $row['active_until'] : ( $existing->active_until ?? null ),
		];
		$row['status'] = self::resolve_status( $resolved );

		$name_blank = trim( (string) $row['name'] ) === '';
		if ( $name_blank && $id ) {
			$row['name'] = 'Asset ' . $id;
		}

		if ( $id ) {
			self::record_pre_save_revision( $id, $row, $data );
			$wpdb->update( $t, $row, [ 'id' => $id ] );
			self::flush_caches();
			if ( class_exists( 'ES_AB' ) ) {
				$after = self::find( $id );
				if ( $after ) {
					ES_AB::sync_pause_with_form( $after );
				}
			}
			return $id;
		}

		$row['created_at']    = $now;
		$max_order            = (int) $wpdb->get_var( "SELECT MAX(display_order) FROM $t" );
		$row['display_order'] = $max_order + 1;
		$wpdb->insert( $t, $row );
		$new_id = (int) $wpdb->insert_id;
		if ( $name_blank && $new_id ) {
			$wpdb->update( $t, [ 'name' => 'Asset ' . $new_id ], [ 'id' => $new_id ] );
		}
		self::flush_caches();
		return $new_id;
	}

	private static function record_pre_save_revision( int $form_id, array $row, array $data ): void {
		if ( ! array_key_exists( 'html', $data ) && ! array_key_exists( 'css', $data ) ) {
			return;
		}
		$previous = self::find( $form_id );
		if ( ! $previous ) {
			return;
		}
		$old_html = (string) ( $previous->html ?? '' );
		$old_css  = (string) ( $previous->css  ?? '' );
		$new_html = array_key_exists( 'html', $data ) ? (string) ( $row['html'] ?? '' ) : $old_html;
		$new_css  = array_key_exists( 'css',  $data ) ? (string) ( $row['css']  ?? '' ) : $old_css;
		if ( $old_html === $new_html && $old_css === $new_css ) {
			return;
		}
		$note = isset( $data['revision_note'] ) ? (string) $data['revision_note'] : null;
		if ( class_exists( 'ES_Form_Revisions' ) ) {
			ES_Form_Revisions::insert( $form_id, $old_html, $old_css, $note );
		}
	}

	public static function reorder( array $ids ): int {
		global $wpdb;
		$t       = self::table();
		$updated = 0;
		$ids     = array_values( $ids );
		$n       = count( $ids );
		foreach ( $ids as $i => $raw_id ) {
			$id = (int) $raw_id;
			if ( $id <= 0 ) continue;
			$ok = $wpdb->update(
				$t,
				[ 'display_order' => $n - $i ],
				[ 'id' => $id ]
			);
			if ( $ok ) {
				$updated++;
			}
		}
		return $updated;
	}

	public static function trash( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'trashed_at' => current_time( 'mysql' ),
				'enabled'    => 0,
				'status'     => 'inactive',
			],
			[ 'id' => $id ]
		);
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::cancel_for_form( $id, __( 'The form was moved to the trash.', 'emblem-studio' ) );
		}
		self::flush_caches();
	}

	public static function restore( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'trashed_at' => null,
				'enabled'    => 0,
				'status'     => 'inactive',
			],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	public static function archive( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'archived_at' => current_time( 'mysql' ),
				'enabled'     => 0,
				'status'      => 'inactive',
			],
			[ 'id' => $id ]
		);
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::cancel_for_form( $id, __( 'The form was archived.', 'emblem-studio' ) );
		}
		self::flush_caches();
	}

	public static function restore_from_archive( int $id ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[
				'archived_at' => null,
				'enabled'     => 0,
				'status'      => 'inactive',
			],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	public static function set_archived_at( int $id, ?string $when ): void {
		global $wpdb;
		$wpdb->update(
			self::table(),
			[ 'archived_at' => $when ],
			[ 'id' => $id ]
		);
		self::flush_caches();
	}

	public static function to_export_array( object $form ): array {
		$decode = function ( $raw ) {
			if ( is_string( $raw ) && $raw !== '' ) {
				$d = json_decode( $raw, true );
				return is_array( $d ) ? $d : null;
			}
			return null;
		};
		return [
			'name'                 => (string) ( $form->name ?? '' ),
			'slug'                 => (string) ( $form->slug ?? '' ),
			'html'                 => (string) ( $form->html ?? '' ),
			'css'                  => (string) ( $form->css ?? '' ),
			'status'               => (string) ( $form->status ?? 'inactive' ),
			'archived_at'          => ! empty( $form->archived_at ) ? (string) $form->archived_at : null,
			'display_order'        => (int) ( $form->display_order ?? 0 ),
			'success_mode'         => (string) ( $form->success_mode ?? 'inline_state' ),
			'success_message'      => (string) ( $form->success_message ?? '' ),
			'success_redirect_url' => (string) ( $form->success_redirect_url ?? '' ),
			'funnelkit_lists'      => $decode( $form->funnelkit_lists ?? null )  ?? [],
			'funnelkit_tags'       => $decode( $form->funnelkit_tags  ?? null )  ?? [],
			'field_mapping'        => $decode( $form->field_mapping   ?? null )  ?? new stdClass(),
			'form_types'           => $decode( $form->form_types      ?? null )  ?? [ 'inline' ],
			'auto_trigger_config'  => $decode( $form->auto_trigger_config ?? null ) ?? new stdClass(),
			'display_rules'        => $decode( $form->display_rules   ?? null )  ?? new stdClass(),
			'use_shadow_dom'       => ! empty( $form->use_shadow_dom ) ? 1 : 0,
			'use_design_system'    => ! empty( $form->use_design_system ) ? 1 : 0,
			'popup_max_width'      => (int) ( $form->popup_max_width ?? 720 ),
			'popup_fullscreen'     => ! empty( $form->popup_fullscreen ) ? 1 : 0,
			'popup_close_theme'    => self::close_theme( $form->popup_close_theme ?? 'light' ),
			'track_clicks'         => ! empty( $form->track_clicks ) ? 1 : 0,
			'track_purchases'      => ! empty( $form->track_purchases ) ? 1 : 0,
			'funnelkit_enabled'    => ! empty( $form->funnelkit_enabled ) ? 1 : 0,
			'webhooks'             => class_exists( 'ES_Webhooks' ) ? ES_Webhooks::for_form( $form ) : [],
			'connections'          => class_exists( 'ES_Connections' ) ? ES_Connections::for_form( $form ) : null,
			'active_from'          => $form->active_from  ? (string) $form->active_from  : null,
			'active_until'         => $form->active_until ? (string) $form->active_until : null,
		];
	}

	public static function from_import_array( array $data ): ?int {
		$decode_array = function ( $val ) {
			if ( is_array( $val ) ) return $val;
			if ( is_object( $val ) ) return (array) $val;
			if ( is_string( $val ) && $val !== '' ) {
				$d = json_decode( $val, true );
				return is_array( $d ) ? $d : [];
			}
			return [];
		};

		$name = isset( $data['name'] ) ? self::clean_name( $data['name'] ) : '';
		if ( $name === '' ) {
			$name = __( 'Imported form', 'emblem-studio' );
		}
		$slug_seed = isset( $data['slug'] ) && $data['slug'] !== ''
			? (string) $data['slug']
			: $name;
		$slug = self::unique_slug( $slug_seed );

		$popup_close_theme = $data['popup_close_theme'] ?? 'auto';

		$payload = [
			'name'                 => $name,
			'slug'                 => $slug,
			'html'                 => (string) ( $data['html'] ?? '' ),
			'css'                  => (string) ( $data['css']  ?? '' ),
			'status'               => 'inactive',
			'success_mode'         => in_array( $data['success_mode'] ?? 'inline_state', [ 'inline_state', 'redirect' ], true ) ? $data['success_mode'] : 'inline_state',
			'success_message'      => isset( $data['success_message'] ) ? (string) $data['success_message'] : '',
			'success_redirect_url' => isset( $data['success_redirect_url'] ) ? (string) $data['success_redirect_url'] : '',
			'funnelkit_lists'      => $decode_array( $data['funnelkit_lists'] ?? [] ),
			'funnelkit_tags'       => $decode_array( $data['funnelkit_tags']  ?? [] ),
			'field_mapping'        => $decode_array( $data['field_mapping']   ?? [] ),
			'form_types'           => $decode_array( $data['form_types']      ?? [ 'inline' ] ),
			'auto_trigger_config'  => $decode_array( $data['auto_trigger_config'] ?? [] ),
			'display_rules'        => $decode_array( $data['display_rules']   ?? [] ),
			'use_shadow_dom'       => ! empty( $data['use_shadow_dom'] ) ? 1 : 0,
			'use_design_system'    => array_key_exists( 'use_design_system', $data ) ? ( ! empty( $data['use_design_system'] ) ? 1 : 0 ) : 1,
			'popup_max_width'      => (int) ( $data['popup_max_width'] ?? 720 ),
			'popup_fullscreen'     => ! empty( $data['popup_fullscreen'] ) ? 1 : 0,
			'popup_close_theme'    => self::close_theme( $popup_close_theme, 'dark' ),
			'track_clicks'         => array_key_exists( 'track_clicks', $data ) ? ( ! empty( $data['track_clicks'] ) ? 1 : 0 ) : 1,
			'track_purchases'      => ! empty( $data['track_purchases'] ) ? 1 : 0,
			'funnelkit_enabled'    => array_key_exists( 'funnelkit_enabled', $data ) ? ( ! empty( $data['funnelkit_enabled'] ) ? 1 : 0 ) : 0,
			'webhooks'             => $decode_array( $data['webhooks'] ?? [] ),
			'connections'          => $decode_array( $data['connections'] ?? [] ),
			'active_from'          => isset( $data['active_from'] )  ? self::import_datetime( (string) $data['active_from'] )  : '',
			'active_until'         => isset( $data['active_until'] ) ? self::import_datetime( (string) $data['active_until'] ) : '',
		];

		$new_id = self::save( $payload );
		return $new_id ?: null;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		if ( class_exists( 'ES_Form_Revisions' ) ) {
			ES_Form_Revisions::delete_for_form( $id );
		}
		if ( class_exists( 'ES_Form_Scheduled_Revisions' ) ) {
			ES_Form_Scheduled_Revisions::delete_for_form( $id );
		}
		if ( class_exists( 'ES_Form_Schedule' ) ) {
			ES_Form_Schedule::delete_for_form( $id );
		}
		self::flush_caches();
	}

	public static function duplicate( int $id ): ?int {
		$source = self::find( $id );
		if ( ! $source ) {
			return null;
		}

		$lists         = json_decode( (string) ( $source->funnelkit_lists     ?? '' ), true );
		$tags          = json_decode( (string) ( $source->funnelkit_tags      ?? '' ), true );
		$mapping       = json_decode( (string) ( $source->field_mapping       ?? '' ), true );
		$auto_config   = json_decode( (string) ( $source->auto_trigger_config ?? '' ), true );
		$display_rules = json_decode( (string) ( $source->display_rules       ?? '' ), true );

		$data = [
			'name'                 => $source->name . ' (copy)',
			'slug'                 => self::unique_slug( $source->slug . '-copy' ),
			'html'                 => (string) $source->html,
			'css'                  => (string) $source->css,
			'status'               => 'inactive',
			'success_mode'         => (string) $source->success_mode,
			'success_message'      => $source->success_message,
			'success_redirect_url' => $source->success_redirect_url,
			'funnelkit_lists'      => is_array( $lists )   ? $lists   : [],
			'funnelkit_tags'       => is_array( $tags )    ? $tags    : [],
			'field_mapping'        => is_array( $mapping ) ? $mapping : [],
		];
		if ( is_array( $auto_config ) ) {
			$data['auto_trigger_config'] = $auto_config;
		}
		if ( is_array( $display_rules ) ) {
			$data['display_rules'] = $display_rules;
		}
		$data['use_shadow_dom']   = ! empty( $source->use_shadow_dom ) ? 1 : 0;
		$data['use_design_system'] = ! empty( $source->use_design_system ) ? 1 : 0;
		$data['popup_max_width']  = (int) ( $source->popup_max_width ?? 720 );
		$data['popup_fullscreen']   = ! empty( $source->popup_fullscreen ) ? 1 : 0;
		$data['popup_close_theme']  = self::close_theme( $source->popup_close_theme ?? 'light' );
		$data['track_clicks']     = ! empty( $source->track_clicks ) ? 1 : 0;
		$data['track_purchases']  = ! empty( $source->track_purchases ) ? 1 : 0;
		$data['funnelkit_enabled'] = ! empty( $source->funnelkit_enabled ) ? 1 : 0;
		$data['webhooks']         = class_exists( 'ES_Webhooks' ) ? ES_Webhooks::for_form( $source ) : [];
		$data['connections']      = class_exists( 'ES_Connections' ) ? ES_Connections::for_form( $source ) : null;

		$new_id = self::save( $data );
		return $new_id ?: null;
	}

	public static function valid_types(): array {
		return [ 'inline', 'popup-click', 'popup-auto', 'ribbon', 'slide-in' ];
	}

	public static function auto_shells(): array {
		return [ 'popup-auto', 'ribbon', 'slide-in' ];
	}

	private static function enforce_shell_exclusivity( array $types ): array {
		foreach ( [ 'ribbon', 'slide-in' ] as $exclusive ) {
			if ( in_array( $exclusive, $types, true ) ) {
				return [ $exclusive ];
			}
		}
		return $types;
	}

	public static function sanitize_auto_trigger_config( array $raw ): array {
		if ( isset( $raw['triggers'] ) && is_array( $raw['triggers'] ) && ! isset( $raw['trigger'] ) ) {
			$old   = $raw['triggers'];
			$first = '';
			foreach ( [ 'delay', 'scroll', 'inactivity', 'exit' ] as $name ) {
				if ( ! empty( $old[ $name ]['enabled'] ) ) {
					$first = $name;
					break;
				}
			}
			$raw = [
				'trigger'             => $first,
				'delay_seconds'       => $old['delay']['seconds']        ?? 5,
				'scroll_percent'      => $old['scroll']['percent']       ?? 50,
				'inactivity_seconds'  => $old['inactivity']['seconds']   ?? 30,
				'exit_include_hidden' => ! empty( $old['exit']['include_hidden'] ),
			];
		}

		$valid_triggers = [ 'delay', 'scroll', 'inactivity', 'exit', 'order' ];
		$trigger        = isset( $raw['trigger'] ) ? sanitize_key( (string) $raw['trigger'] ) : '';
		if ( ! in_array( $trigger, $valid_triggers, true ) ) {
			$trigger = '';
		}

		$position       = isset( $raw['position'] ) ? sanitize_key( (string) $raw['position'] ) : 'top';
		$valid_positions = [ 'top', 'bottom' ];
		if ( ! in_array( $position, $valid_positions, true ) ) {
			$position = 'top';
		}

		$corner        = isset( $raw['corner'] ) ? sanitize_key( (string) $raw['corner'] ) : 'bottom-right';
		$valid_corners = [ 'bottom-right', 'bottom-left' ];
		if ( ! in_array( $corner, $valid_corners, true ) ) {
			$corner = 'bottom-right';
		}

		$close_button     = array_key_exists( 'close_button', $raw ) ? ! empty( $raw['close_button'] ) : true;
		$valid_dismiss    = [ 'show_again', 'hide_hours', 'never' ];
		$dismiss_behavior = isset( $raw['dismiss_behavior'] ) ? sanitize_key( (string) $raw['dismiss_behavior'] ) : 'show_again';
		if ( ! in_array( $dismiss_behavior, $valid_dismiss, true ) ) {
			$dismiss_behavior = 'show_again';
		}
		$dismiss_hide_hours = max( 1, (int) ( $raw['dismiss_hide_hours'] ?? 24 ) );

		$sticky = array_key_exists( 'sticky', $raw ) ? ! empty( $raw['sticky'] ) : true;

		return [
			'trigger'             => $trigger,
			'delay_seconds'       => max( 0, (int) ( $raw['delay_seconds'] ?? 5 ) ),
			'scroll_percent'      => max( 1, min( 100, (int) ( $raw['scroll_percent'] ?? 50 ) ) ),
			'inactivity_seconds'  => max( 5, (int) ( $raw['inactivity_seconds'] ?? 30 ) ),
			'exit_include_hidden' => ! empty( $raw['exit_include_hidden'] ),
			'exit_buffer_seconds' => max( 0, (int) ( $raw['exit_buffer_seconds'] ?? 0 ) ),
			'exit_window_seconds' => max( 0, (int) ( $raw['exit_window_seconds'] ?? 0 ) ),
			'exit_window_autofire' => ! empty( $raw['exit_window_autofire'] ),
			'order_wait_minutes'  => max( 0, min( 1440, (int) ( $raw['order_wait_minutes'] ?? 5 ) ) ),
			'order_min_count'     => max( 1, min( 1000, (int) ( $raw['order_min_count'] ?? 1 ) ) ),
			'order_check_minutes' => max( 1, min( 60, (int) ( $raw['order_check_minutes'] ?? 1 ) ) ),
			'order_close_seconds' => max( 0, min( 600, (int) ( $raw['order_close_seconds'] ?? 5 ) ) ),
			'order_backlog_minutes' => max( 0, min( 1440, (int) ( $raw['order_backlog_minutes'] ?? 60 ) ) ),
			'order_landing_seconds' => max( 0, min( 600, (int) ( $raw['order_landing_seconds'] ?? 5 ) ) ),
			'position'            => $position,
			'corner'              => $corner,
			'sticky'              => $sticky,
			'close_button'        => $close_button,
			'dismiss_behavior'    => $dismiss_behavior,
			'dismiss_hide_hours'  => $dismiss_hide_hours,
		];
	}

	public static function get_auto_trigger_config( object $form ): array {
		return self::get_shell_trigger_config( $form, 'popup-auto' );
	}

	public static function get_shell_trigger_config( object $form, string $shell ): array {
		$all = self::get_all_shell_trigger_configs( $form );
		return $all[ $shell ] ?? self::sanitize_auto_trigger_config( [] );
	}

	public static function get_all_shell_trigger_configs( object $form ): array {
		$raw     = $form->auto_trigger_config ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return self::sanitize_all_shell_trigger_configs( is_array( $decoded ) ? $decoded : [] );
	}

	public static function sanitize_all_shell_trigger_configs( array $raw ): array {
		if ( isset( $raw['trigger'] ) || isset( $raw['delay_seconds'] ) || isset( $raw['triggers'] ) ) {
			$raw = [ 'popup-auto' => $raw ];
		}
		$out = [];
		foreach ( self::auto_shells() as $shell ) {
			$shell_raw     = isset( $raw[ $shell ] ) && is_array( $raw[ $shell ] ) ? $raw[ $shell ] : [];
			$out[ $shell ] = self::sanitize_auto_trigger_config( $shell_raw );
		}
		return $out;
	}

	public static function import_datetime( string $value ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			return $value;
		}
		try {
			return ( new DateTimeImmutable( $value ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return '';
		}
	}

	public static function normalize_datetime( $value, bool $end_of_day = false ): ?string {
		$s = trim( (string) ( $value ?? '' ) );
		if ( $s === '' ) {
			return null;
		}
		$s = str_replace( 'T', ' ', $s );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $s ) ) {
			$s .= $end_of_day ? ' 23:59:59' : ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s ) ) {
			$s .= $end_of_day ? ':59' : ':00';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $s, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] )
			|| (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			return null;
		}
		return $s;
	}

	public static function is_in_schedule_window( object $form ): bool {
		if ( class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' ) ) {
			return true;
		}
		return self::schedule_window_contains(
			$form->active_from  ?? null,
			$form->active_until ?? null,
			(string) current_time( 'mysql' )
		);
	}

	public static function schedule_window_contains( ?string $from, ?string $until, string $now ): bool {
		if ( ! empty( $from ) && strcmp( $now, (string) $from ) < 0 ) {
			return false;
		}
		if ( ! empty( $until ) && strcmp( $now, (string) $until ) > 0 ) {
			return false;
		}
		return true;
	}

	public static function is_currently_active( object $form ): bool {
		return self::resolve_status( $form ) === 'active';
	}

	public static function resolve_status( object $form ): string {
		$enabled = isset( $form->enabled )
			? (bool) $form->enabled
			: in_array( ( $form->status ?? 'inactive' ), [ 'active', 'scheduled', 'expired' ], true );

		$ignore_window = class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' );

		return self::resolve_status_at(
			$enabled,
			! empty( $form->trashed_at ),
			! empty( $form->archived_at ),
			$ignore_window ? null : ( isset( $form->active_from )  ? (string) $form->active_from  : null ),
			$ignore_window ? null : ( isset( $form->active_until ) ? (string) $form->active_until : null ),
			(string) current_time( 'mysql' )
		);
	}

	public static function resolve_status_at( bool $enabled, bool $trashed, bool $archived, ?string $from, ?string $until, string $now ): string {
		if ( $trashed || $archived || ! $enabled ) {
			return 'inactive';
		}
		if ( $from && $from > $now ) {
			return 'scheduled';
		}
		if ( $until && $until < $now ) {
			return 'expired';
		}

		return 'active';
	}

	public static function reconcile_statuses(): int {
		global $wpdb;
		$t = self::table();

		$rows = $wpdb->get_results(
			"SELECT * FROM $t
			 WHERE trashed_at IS NULL
			   AND archived_at IS NULL
			   AND ( active_from IS NOT NULL OR active_until IS NOT NULL OR status IN ('scheduled','expired') )"
		);
		if ( ! $rows ) {
			return 0;
		}

		$moved = 0;
		foreach ( $rows as $row ) {
			$resolved = self::resolve_status( $row );
			if ( $resolved === ( $row->status ?? '' ) ) {
				continue;
			}
			$wpdb->update( $t, [ 'status' => $resolved ], [ 'id' => (int) $row->id ] );
			$moved++;

			if ( class_exists( 'ES_AB' ) ) {
				$row->status = $resolved;
				ES_AB::sync_pause_with_form( $row );
			}
		}

		if ( $moved > 0 ) {
			self::flush_caches();
		}

		return $moved;
	}

	public static function effective_state( object $form ): string {
		$ignore_window = class_exists( 'ES_Caps' ) && ! ES_Caps::has( 'schedule_window' );

		if ( ! $ignore_window && class_exists( 'ES_Form_Schedule' ) && ! empty( $form->id ) ) {
			$from_periods = ES_Form_Schedule::state_for( (int) $form->id );
			if ( null !== $from_periods ) {
				if ( ! empty( $form->trashed_at ) || ! empty( $form->archived_at ) || 'active' !== ( $form->status ?? 'inactive' ) ) {
					return 'inactive';
				}
				if ( 'active' === $from_periods ) {
					$current = ES_Form_Schedule::current( (int) $form->id );
					return ( $current && ! empty( $current->ends_at ) ) ? 'ending' : 'active';
				}
				return $from_periods;
			}
		}

		return self::effective_state_at(
			( $form->status ?? 'inactive' ),
			! empty( $form->trashed_at ),
			$ignore_window ? null : ( $form->active_from  ?? null ),
			$ignore_window ? null : ( $form->active_until ?? null ),
			(string) current_time( 'mysql' ),
			! empty( $form->archived_at )
		);
	}

	public static function effective_state_at( ?string $status, bool $trashed, ?string $from, ?string $until, string $now, bool $archived = false ): string {
		if ( $trashed || $archived || ( $status ?? 'inactive' ) !== 'active' ) {
			return 'inactive';
		}
		if ( ! empty( $from ) && strcmp( $now, (string) $from ) < 0 ) {
			return 'scheduled';
		}
		if ( ! empty( $until ) && strcmp( $now, (string) $until ) > 0 ) {
			return 'expired';
		}
		if ( ! empty( $until ) ) {
			return 'ending';
		}
		return 'active';
	}

	public static function has_fields( string $html ): bool {
		if ( preg_match( '/<(?:textarea|select)[\s>]/i', $html ) ) {
			return true;
		}

		if ( ! preg_match_all( '/<input\b[^>]*>/i', $html, $matches ) ) {
			return false;
		}

		$non_fields = array( 'hidden', 'submit', 'button', 'image', 'reset' );

		foreach ( $matches[0] as $tag ) {
			$type = preg_match( '/\btype\s*=\s*("|\'|)([a-zA-Z-]+)\1/i', $tag, $m )
				? strtolower( $m[2] )
				: 'text';

			if ( ! in_array( $type, $non_fields, true ) ) {
				return true;
			}
		}

		return false;
	}

	public static function primary_metric( string $html ): string {
		return self::has_fields( $html ) ? 'conversions' : 'clicks';
	}

	public static function types_for( object $form ): array {
		$raw = $form->form_types ?? '';
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = null;
		}
		if ( ! is_array( $decoded ) ) {
			return [ 'inline' ];
		}
		return array_values( array_intersect( $decoded, self::valid_types() ) );
	}

	public static function unique_slug( string $base, ?int $ignore_id = null ): string {
		global $wpdb;
		$t       = self::table();
		$base    = sanitize_title( $base );
		$base    = $base !== '' ? $base : 'form';
		$slug    = $base;
		$i       = 2;
		$ignore  = $ignore_id ?: 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE slug = %s AND id <> %d", $slug, $ignore ) ) ) {
			$slug = $base . '-' . $i;
			$i++;
		}
		return $slug;
	}

	private static function flush_caches(): void {
		ES_Display_Rules::flush_visitor_state_cache();
		ES_Shortcode::flush_auto_shell_cache();
	}
}
