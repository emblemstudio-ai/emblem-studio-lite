<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edition + capability gate (P5 WordPress thin client).
 *
 * The FULL plugin (the default, shipped to the real sites) enables every
 * capability, so every check here is an inert no-op — behaviour is identical to
 * before this class existed. The generated **Lite** build defines
 * `EMBLEM_STUDIO_EDITION = 'lite'` in its main file and physically ships WITHOUT
 * the gated module files; the same checks then keep those account-only features
 * hidden/disabled and steer users toward connecting a free Emblem Studio account.
 *
 * Two complementary mechanisms keep Lite safe:
 *   • `ES_Caps::has()` — INTENTIONAL gating (hide a menu/tab, skip a `require`).
 *   • `class_exists()` at incidental cross-module call sites — CRASH safety when
 *     a gated module file is absent (inert in Full, where the class exists).
 *
 * See docs/FEATURE-TIERING-CATALOGUE.md for the tier behind each capability.
 */
class ES_Caps {

	/**
	 * Capabilities per edition. Full = everything on.
	 *
	 * THREE OF THESE ARE A STATEMENT OF INTENT, NOT A LOCK (checked 2026-09-04).
	 * Nothing anywhere calls has() for `targeting`, `reports_advanced` or
	 * `lead_detail`. Lite does not expose those features today, but that is
	 * because the screens carrying them are deleted from the build, not because
	 * this table stopped anybody. If one of those screens ever comes back without
	 * a check beside it, the feature returns with it while this table still reads
	 * "false". Enforce them here before relying on them.
	 */
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

	/** The current edition ('full' by default; 'lite' in the stripped build). */
	public static function edition(): string {
		$e = defined( 'EMBLEM_STUDIO_EDITION' ) ? (string) EMBLEM_STUDIO_EDITION : 'full';

		return isset( self::MATRIX[ $e ] ) ? $e : 'full';
	}

	public static function is_lite(): bool {
		return self::edition() === 'lite';
	}

	/** True when the current edition includes the given capability. */
	public static function has( string $cap ): bool {
		$caps = self::MATRIX[ self::edition() ];

		return ! empty( $caps[ $cap ] );
	}
}
