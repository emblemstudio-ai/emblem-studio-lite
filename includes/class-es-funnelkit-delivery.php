<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ES_FunnelKit_Delivery {
	public static function dispatch(
		string $asset_key,
		array $raw_fields,
		array $direct_lead,
		bool $direct_enabled
	): array {
		$feed = ES_FunnelKit_Form_Feed::dispatch( $asset_key, $raw_fields );
		if ( ! empty( $feed['handled'] ) ) {
			return array_merge(
				[
					'ok'         => empty( $feed['error'] ),
					'mode'       => 'form_feed',
					'contact_id' => null,
					'error'      => null,
				],
				$feed,
				[ 'mode' => 'form_feed' ]
			);
		}

		if ( ! $direct_enabled ) {
			return [
				'ok'         => false,
				'mode'       => 'disabled',
				'contact_id' => null,
				'error'      => null,
			];
		}

		return array_merge(
			[
				'ok'         => false,
				'contact_id' => null,
				'error'      => 'Unknown FunnelKit error',
			],
			ES_FunnelKit::push( $direct_lead ),
			[ 'mode' => 'direct' ]
		);
	}
}
