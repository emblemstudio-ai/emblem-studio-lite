<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Preset_Repo {

	public static function table(): string {
		return Emblem_Studio_Install::table( 'display_rule_presets' );
	}

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

	public static function save( array $data, ?int $id = null ): int {
		global $wpdb;
		$t   = self::table();
		$now = current_time( 'mysql' );

		$where = Emblem_Studio_Display_Rules::sanitize_where( $data );
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

	public static function usage_count( int $presetId ): int {
		$count = 0;
		foreach ( Emblem_Studio_Form_Repo::all( 'all' ) as $form ) {
			$rules = Emblem_Studio_Display_Rules::get_all_for_form( $form );
			foreach ( Emblem_Studio_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$count++;
				}
			}
		}
		return $count;
	}

	public static function apply_to_forms( int $presetId, array $where ): void {
		foreach ( Emblem_Studio_Form_Repo::all( 'all' ) as $form ) {
			$rules   = Emblem_Studio_Display_Rules::get_all_for_form( $form );
			$changed = false;
			foreach ( Emblem_Studio_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$rules[ $shell ] = array_merge( $rules[ $shell ], $where );
					$changed         = true;
				}
			}
			if ( $changed ) {
				Emblem_Studio_Form_Repo::update_display_rules( (int) $form->id, $rules );
			}
		}
	}

	public static function decouple_forms_using( int $presetId ): void {
		foreach ( Emblem_Studio_Form_Repo::all( 'all' ) as $form ) {
			$rules   = Emblem_Studio_Display_Rules::get_all_for_form( $form );
			$changed = false;
			foreach ( Emblem_Studio_Form_Repo::auto_shells() as $shell ) {
				if ( ( $rules[ $shell ]['preset_id'] ?? null ) === $presetId ) {
					$rules[ $shell ]['preset_id'] = null;
					$changed                      = true;
				}
			}
			if ( $changed ) {
				Emblem_Studio_Form_Repo::update_display_rules( (int) $form->id, $rules );
			}
		}
	}

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

	public static function lookup_post_titles( string $post_type, array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$posts = get_posts( [
			'post_type'      => $post_type,
			'post__in'       => $ids,
			'posts_per_page' => count( $ids ),
			'orderby'        => 'post__in',
			'post_status'    => [ 'publish', 'private', 'draft', 'pending' ],
			'no_found_rows'  => true,
		] );
		$out = [];
		foreach ( $posts as $p ) {
			$out[ (int) $p->ID ] = $p->post_title !== '' ? $p->post_title : __( '(no title)', 'emblem-studio' );
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = sprintf( __( '#%d (not found)', 'emblem-studio' ), $id );
			}
		}
		return $out;
	}

	public static function lookup_term_names( string $taxonomy, array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}
		$terms = get_terms( [
			'taxonomy'   => $taxonomy,
			'include'    => $ids,
			'hide_empty' => false,
			'orderby'    => 'include',
		] );
		$out = [];
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$out[ (int) $term->term_id ] = $term->name;
			}
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = sprintf( __( '#%d (not found)', 'emblem-studio' ), $id );
			}
		}
		return $out;
	}

	public static function titles_for_filters( array $filters ): array {
		return [
			'posts' => self::lookup_post_titles( 'post', (array) ( $filters['posts'] ?? [] ) ),
			'pages' => self::lookup_post_titles( 'page', (array) ( $filters['pages'] ?? [] ) ),
			'cats'  => self::lookup_term_names( 'category', (array) ( $filters['categories'] ?? [] ) ),
			'tags'  => self::lookup_term_names( 'post_tag', (array) ( $filters['tags'] ?? [] ) ),
		];
	}

	public static function to_admin_payload( object $preset ): array {
		$decode = function ( $raw ) {
			if ( is_string( $raw ) && $raw !== '' ) {
				$d = json_decode( $raw, true );
				return is_array( $d ) ? $d : [];
			}
			return [];
		};
		$show = $decode( $preset->show_filters ?? null );
		$hide = $decode( $preset->hide_filters ?? null );
		return [
			'id'           => (int) $preset->id,
			'name'         => (string) ( $preset->name ?? '' ),
			'show_mode'    => (string) ( $preset->show_mode ?? 'all' ),
			'show_filters' => $show,
			'hide_filters' => $hide,
			'titles'       => [
				'show' => self::titles_for_filters( $show ),
				'hide' => self::titles_for_filters( $hide ),
			],
		];
	}
}
