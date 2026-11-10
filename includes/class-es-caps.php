<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Emblem_Studio_Caps {

	private const MATRIX = [
		'full' => [
			'ab'               => true,
			'webhooks'         => true,
			'linked_forms'     => true,
			'revisions'        => true,
			'auto_shells'      => true,
			'targeting'        => true,
			'reports_advanced' => true,
			'lead_detail'      => true,
			'schedule_window'  => true,
			'design_system'    => true,
		],
		'lite' => [
			'ab'               => false,
			'webhooks'         => false,
			'linked_forms'     => false,
			'revisions'        => false,
			'auto_shells'      => false,
			'targeting'        => false,
			'reports_advanced' => false,
			'lead_detail'      => false,
			'schedule_window'  => false,
			'design_system'    => false,
		],
	];

	public static function edition(): string {
		$e = defined( 'EMBLEM_STUDIO_EDITION' ) ? (string) EMBLEM_STUDIO_EDITION : 'full';

		return isset( self::MATRIX[ $e ] ) ? $e : 'full';
	}

	public static function is_lite(): bool {
		return self::edition() === 'lite';
	}

	public static function has( string $cap ): bool {
		$caps = self::MATRIX[ self::edition() ];

		return ! empty( $caps[ $cap ] );
	}
}
