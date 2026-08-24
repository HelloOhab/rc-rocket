<?php
declare( strict_types=1 );

namespace RCRocket\Cache;

use RCRocket\Integrations\Divi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WordPress-aware half of the cacheability decision. Key::bypass_reason()
 * handles what can be known from the raw request; this handles what can only
 * be known once the query has run.
 */
final class Rules {

	public function __construct( private array $config, private ?Divi $divi = null ) {}

	/**
	 * @return string|null Reason to skip caching, or null when cacheable.
	 */
	public function bypass_reason(): ?string {
		$raw = Key::bypass_reason( $_SERVER, $_COOKIE, $this->config ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( null !== $raw ) {
			return $raw;
		}

		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'donotcachepage';
		}

		if ( wp_doing_ajax() || wp_doing_cron() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return 'non-frontend';
		}

		if ( is_user_logged_in() && empty( $this->config['cache_logged_in'] ) ) {
			return 'logged-in';
		}

		if ( is_404() ) {
			return '404';
		}

		if ( is_search() ) {
			return 'search';
		}

		if ( is_preview() || is_customize_preview() ) {
			return 'preview';
		}

		if ( is_trackback() || is_robots() ) {
			return 'special-request';
		}

		if ( post_password_required() ) {
			return 'password-protected';
		}

		if ( 200 !== http_response_code() ) {
			return 'status:' . http_response_code();
		}

		$commerce = $this->commerce_bypass();

		if ( null !== $commerce ) {
			return $commerce;
		}

		if ( $this->divi instanceof Divi && $this->divi->is_active() ) {
			$divi_reason = $this->divi->bypass_reason();

			if ( null !== $divi_reason ) {
				return $divi_reason;
			}
		}

		/**
		 * Last word on cacheability. Return a non-empty string to bypass.
		 *
		 * @param string|null $reason
		 */
		return apply_filters( 'rc-rocket/cache/bypass_reason', null );
	}

	/**
	 * Store pages that are personal by definition. Getting this wrong shows a
	 * stranger's cart to a customer, so the check is deliberately blunt.
	 */
	private function commerce_bypass(): ?string {
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return 'woocommerce-dynamic';
		}

		if ( function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty() ) {
			return 'woocommerce-cart-filled';
		}

		if ( function_exists( 'edd_is_checkout' ) && edd_is_checkout() ) {
			return 'edd-checkout';
		}

		return null;
	}

	/**
	 * Surrogate keys for the current response: every content object that, when
	 * edited, should invalidate this page.
	 *
	 * @return string[]
	 */
	public function surrogate_keys(): array {
		$keys = [ 'site' ];

		if ( is_front_page() || is_home() ) {
			$keys[] = 'home';
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$keys[]  = 'post-' . $post_id;
			$keys[]  = 'type-' . get_post_type( $post_id );
			$keys[]  = 'author-' . (int) get_post_field( 'post_author', $post_id );

			foreach ( (array) get_object_taxonomies( (string) get_post_type( $post_id ) ) as $taxonomy ) {
				foreach ( (array) get_the_terms( $post_id, (string) $taxonomy ) as $term ) {
					if ( $term instanceof \WP_Term ) {
						$keys[] = 'term-' . $term->term_id;
					}
				}
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$keys[] = 'term-' . get_queried_object_id();
			$keys[] = 'archive';
		}

		if ( is_author() ) {
			$keys[] = 'author-' . get_queried_object_id();
			$keys[] = 'archive';
		}

		if ( is_date() || is_post_type_archive() ) {
			$keys[] = 'archive';
		}

		if ( is_feed() ) {
			$keys[] = 'feed';
		}

		if ( $this->divi instanceof Divi && $this->divi->is_active() ) {
			$keys = array_merge( $keys, $this->divi->surrogate_keys() );
		}

		/**
		 * @param string[] $keys
		 */
		return array_values( array_unique( (array) apply_filters( 'rc-rocket/cache/surrogate_keys', $keys ) ) );
	}
}
