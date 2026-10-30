<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_Templates {

	public static function root(): string {
		return EMBLEM_STUDIO_PATH . 'templates';
	}

	public static function bundles(): array {
		$out  = [];
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		$dirs = glob( $root . '/*', GLOB_ONLYDIR );
		foreach ( (array) $dirs as $dir ) {
			$slug = basename( $dir );
			$b    = self::bundle( $slug );
			if ( $b ) {
				$out[ $slug ] = $b;
			}
		}
		return $out;
	}

	public static function bundle( string $slug ): ?array {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) {
			return null;
		}
		$dir      = self::root() . '/' . $slug;
		$manifest = $dir . '/bundle.php';
		if ( ! is_dir( $dir ) || ! is_readable( $manifest ) ) {
			return null;
		}
		$data = include $manifest;
		if ( ! is_array( $data ) || empty( $data['forms'] ) ) {
			return null;
		}
		$forms = [];
		foreach ( (array) $data['forms'] as $form_slug ) {
			$form = self::load_form( $slug, (string) $form_slug );
			if ( $form ) {
				$forms[ $form_slug ] = $form;
			}
		}
		$data['slug']            = $slug;
		$data['resolved_forms']  = $forms;
		return $data;
	}

	public static function load_form( string $bundle_slug, string $form_slug ): ?array {
		$bundle_slug = sanitize_key( $bundle_slug );
		$form_slug   = sanitize_key( $form_slug );
		$file        = self::root() . '/' . $bundle_slug . '/' . $form_slug . '.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = include $file;
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( ! isset( $data['name'], $data['shell'], $data['html'], $data['css'] ) ) {
			return null;
		}
		$data['source_slug'] = $form_slug;
		return $data;
	}

	public static function install_form( string $bundle_slug, string $form_slug ): ?int {
		$bundle = self::bundle( $bundle_slug );
		if ( ! $bundle ) {
			return null;
		}
		$form = self::load_form( $bundle_slug, $form_slug );
		if ( ! $form ) {
			return null;
		}
		$payload = self::form_to_import_payload( $form, $bundle );
		$id      = ES_Form_Repo::from_import_array( $payload );
		return $id ? (int) $id : null;
	}

	public static function install_bundle( string $slug ): array {
		$bundle = self::bundle( $slug );
		if ( ! $bundle ) {
			return [];
		}
		$ids = [];
		foreach ( (array) $bundle['resolved_forms'] as $form ) {
			$payload = self::form_to_import_payload( $form, $bundle );
			$id      = ES_Form_Repo::from_import_array( $payload );
			if ( $id ) {
				$ids[] = (int) $id;
			}
		}
		return $ids;
	}

	private static function form_to_import_payload( array $form, array $bundle ): array {
		$shell = (string) $form['shell'];
		$name  = isset( $form['name'] ) ? (string) $form['name'] : __( 'Form', 'emblem-studio' );
		$display_name = ! empty( $bundle['name'] )
			? $bundle['name'] . ' — ' . $name
			: $name;

		return [
			'name'                => $display_name,
			'slug'                => sanitize_title( ( $bundle['slug'] ?? '' ) . '-' . ( $form['source_slug'] ?? sanitize_title( $name ) ) ),
			'html'                => (string) $form['html'],
			'css'                 => (string) $form['css'],
			'form_types'          => [ $shell ],
			'success_mode'        => $form['success_mode']    ?? 'inline_state',
			'success_message'     => $form['success_message'] ?? null,
			'use_shadow_dom'      => ! empty( $form['use_shadow_dom'] ) ? 1 : 0,
			'auto_trigger_config' => isset( $form['auto_trigger_config'] ) && is_array( $form['auto_trigger_config'] )
				? $form['auto_trigger_config']
				: [],
			'display_rules'       => [],
			'popup_max_width'     => $form['popup_max_width']    ?? 720,
			'popup_fullscreen'    => ! empty( $form['popup_fullscreen'] ) ? 1 : 0,
			'popup_close_theme'   => $form['popup_close_theme']  ?? 'auto',
			'track_clicks'        => 1,
		];
	}
}
