<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display-rule presets (SER-899 phase 2) — a site's saved page/post/URL
 * targeting combos, mirrored two-way with the SaaS. See
 * emblem-studio-saas/docs/DISPLAY-RULE-PRESETS.md for the full design; this
 * is the WordPress-side counterpart of App\Models\DisplayRulePreset +
 * DisplayRulePresetController.
 *
 * A preset is exactly the WHERE fields (show_mode/show_filters/hide_filters)
 * — schedule and visitor targeting are never part of one. A shell "follows"
 * a preset via a `preset_id` key inside its own display_rules entry (see
 * ES_Display_Rules::sanitize()); the shell's own show_mode/show_filters/
 * hide_filters stay the fully-resolved values throughout (write-through on
 * preset save via apply_to_forms()), so rendering and mirroring need no
 * special-case handling for presets at all.
 */
class ES_Preset_Repo {

	public static function table(): string {
		return ES_Install::table( 'display_rule_presets' );
	}

	/** @return object[] */
	public static function all(): array {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY name ASC" );
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
		return $row ?: null;
	}

	/**
	 * Create or update a preset. $data: name, show_mode, show_filters, hide_filters.
	 * Returns the preset id.
	 */
	public static function save( array $data, ?int $id = null ): int {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql' );

		$where = ES_Display_Rules::sanitize_where( $data );
		$name  = trim( (string) ( $data['name'] ?? '' ) );

		$row = [
			'name'         => $name !== '' ? $name : __( 'Untitled preset', 'emblem-studio' ),
			'show_mode'    => $where['show_mode'],
			'show_filters' => wp_json_encode( $where['show_filters'] ),
			'hide_filters' => wp_json_encode( $where['hide_filters'] ),
			'updated_at'   => $now,
		];

		if ( $id ) {
			$wpdb->update( $t, $row, [ 'id' => $id ] );
			return $id;
		}

		$row['created_at'] = $now;
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		self::decouple_forms_using( $id );
		$wpdb->delete( self::table(), [ 'id' => $id ] );
	}

	/**
	 * How many shells, across every form on this site, currently follow this
	 * preset — the "used by N shells" count in the presets admin page.
	 */
	public static function usage_count( int $presetId ): int {
		$count = 0;
		foreach ( ES_Form_Repo::all( 'all' ) as $form ) {
			$rules = ES_Display_Rules::get_all_for_form( $form );
			foreach ( ES_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Write a preset's current WHERE values through to every shell, on every
	 * form on this site, still coupled to it — called after a preset is saved.
	 * Keeps a form's display_rules fully self-contained, so nothing downstream
	 * (rendering, mirroring) ever needs a live preset lookup.
	 */
	public static function apply_to_forms( int $presetId, array $where ): void {
		foreach ( ES_Form_Repo::all( 'all' ) as $form ) {
			$rules   = ES_Display_Rules::get_all_for_form( $form );
			$changed = false;
			foreach ( ES_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$rules[ $shell ] = array_merge( $rules[ $shell ], $where );
					$changed         = true;
				}
			}
			if ( $changed ) {
				ES_Form_Repo::update_display_rules( (int) $form->id, $rules );
			}
		}
	}

	/**
	 * Un-link every shell, on every form on this site, still following a
	 * deleted preset — keeps their last-resolved WHERE values (never blanks
	 * them), matching a manual un-link.
	 */
	public static function decouple_forms_using( int $presetId ): void {
		foreach ( ES_Form_Repo::all( 'all' ) as $form ) {
			$rules   = ES_Display_Rules::get_all_for_form( $form );
			$changed = false;
			foreach ( ES_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$rules[ $shell ]['preset_id'] = null;
					$changed                      = true;
				}
			}
			if ( $changed ) {
				ES_Form_Repo::update_display_rules( (int) $form->id, $rules );
			}
		}
	}

	/**
	 * Portable JSON-shaped array for one preset — mirrors ES_Form_Repo::to_export_array.
	 * Stats/timestamps/id excluded; only what defines the preset travels.
	 */
	public static function to_export_array( object $preset ): array {
		$decode = function ( $raw ) {
			if ( is_string( $raw ) && $raw !== '' ) {
				$d = json_decode( $raw, true );
				return is_array( $d ) ? $d : null;
			}
			return null;
		};
		return [
			'name'         => (string) ( $preset->name ?? '' ),
			'show_mode'    => (string) ( $preset->show_mode ?? 'all' ),
			'show_filters' => $decode( $preset->show_filters ?? null ) ?? new stdClass(),
			'hide_filters' => $decode( $preset->hide_filters ?? null ) ?? new stdClass(),
		];
	}
}
