<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ES_REST {

	public const NAMESPACE_V1 = 'emblem-studio/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE_V1, '/search-content', [
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => [ $this, 'search_content' ],
			'args'                => [
				'type' => [ 'required' => true, 'type' => 'string' ],
				'q'    => [ 'required' => false, 'type' => 'string', 'default' => '' ],
			],
		] );
	}

	public function search_content( WP_REST_Request $req ) {
		$type = sanitize_key( (string) $req->get_param( 'type' ) );
		$q    = trim( (string) $req->get_param( 'q' ) );

		$taxonomy = null;
		if ( $type === 'category' ) {
			$taxonomy = 'category';
		} elseif ( $type === 'tag' ) {
			$taxonomy = 'post_tag';
		}
		if ( $taxonomy ) {
			$terms = get_terms( [
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 20,
				'name__like' => $q !== '' ? $q : '',
				'orderby'    => 'name',
				'order'      => 'ASC',
			] );
			if ( is_wp_error( $terms ) ) {
				return rest_ensure_response( [] );
			}
			$out = [];
			foreach ( (array) $terms as $term ) {
				$out[] = [
					'id'    => (int) $term->term_id,
					'title' => $term->name . ' (' . (int) $term->count . ')',
				];
			}
			return rest_ensure_response( $out );
		}

		if ( ! in_array( $type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'bad_type', 'Invalid type', [ 'status' => 400 ] );
		}
		$args = [
			'post_type'      => $type,
			'post_status'    => [ 'publish', 'private' ],
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];
		if ( $q !== '' ) {
			$args['s'] = $q;
		}
		$found = get_posts( $args );
		$out   = [];
		foreach ( $found as $p ) {
			$out[] = [
				'id'    => (int) $p->ID,
				'title' => $p->post_title !== '' ? $p->post_title : __( '(no title)', 'emblem-studio' ),
			];
		}
		return rest_ensure_response( $out );
	}
}
