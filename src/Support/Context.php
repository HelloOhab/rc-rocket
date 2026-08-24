<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the current request as a set of tokens.
 *
 * Every optimization rule targets tokens rather than URLs. A rule that says
 * "drop WPForms everywhere except singular:page and post:412" survives a
 * permalink change, a new page, and a site migration — a URL list does not.
 * The signature is the same idea collapsed to one string, which is what the
 * asset registry and the error beacon index by.
 */
final class Context {

	/** @var string[]|null */
	private ?array $tokens = null;

	/** @return string[] */
	public function tokens(): array {
		if ( null !== $this->tokens ) {
			return $this->tokens;
		}

		$tokens = [ 'site' ];

		if ( is_front_page() ) {
			$tokens[] = 'front_page';
		}

		if ( is_home() ) {
			$tokens[] = 'blog_index';
		}

		if ( is_singular() ) {
			$id   = get_queried_object_id();
			$type = (string) get_post_type( $id );

			$tokens[] = 'singular';
			$tokens[] = 'singular:' . $type;
			$tokens[] = 'post:' . $id;
		}

		if ( is_archive() ) {
			$tokens[] = 'archive';
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof \WP_Term ) {
				$tokens[] = 'taxonomy:' . $term->taxonomy;
				$tokens[] = 'term:' . $term->term_id;
			}
		}

		if ( is_author() ) {
			$tokens[] = 'author:' . get_queried_object_id();
		}

		if ( is_search() ) {
			$tokens[] = 'search';
		}

		if ( is_404() ) {
			$tokens[] = 'not_found';
		}

		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			$tokens[] = 'woocommerce';
		}

		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			$tokens[] = 'woocommerce:checkout';
		}

		/** @param string[] $tokens */
		$this->tokens = array_values( array_unique( (array) apply_filters( 'rc-rocket/context/tokens', $tokens ) ) );

		return $this->tokens;
	}

	/**
	 * A stable identifier for "pages that look like this one".
	 *
	 * Deliberately coarse: singular pages collapse to their post type unless
	 * they are the front page. Indexing per post id would make the registry
	 * unbounded on a site with thousands of posts, and the asset footprint of
	 * two blog posts is virtually always identical.
	 */
	public function signature(): string {
		if ( is_front_page() ) {
			return 'front_page';
		}

		if ( is_singular() ) {
			return 'singular:' . (string) get_post_type( get_queried_object_id() );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			return 'taxonomy:' . ( $term instanceof \WP_Term ? $term->taxonomy : 'unknown' );
		}

		foreach ( [ 'blog_index', 'search', 'not_found', 'archive' ] as $token ) {
			if ( in_array( $token, $this->tokens(), true ) ) {
				return $token;
			}
		}

		return 'other';
	}

	/**
	 * Does this request match any of the given targets?
	 *
	 * Targets are tokens, or `url:/some/path`, or `regex:#^/blog/#`.
	 *
	 * @param string[] $targets
	 */
	public function matches( array $targets ): bool {
		if ( ! $targets ) {
			return false;
		}

		$tokens = $this->tokens();
		$path   = (string) ( wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ?: '/' );

		foreach ( $targets as $target ) {
			$target = trim( (string) $target );

			if ( '' === $target ) {
				continue;
			}

			if ( str_starts_with( $target, 'regex:' ) ) {
				if ( 1 === @preg_match( substr( $target, 6 ), $path ) ) {
					return true;
				}
				continue;
			}

			if ( str_starts_with( $target, 'url:' ) ) {
				if ( untrailingslashit( substr( $target, 4 ) ) === untrailingslashit( $path ) ) {
					return true;
				}
				continue;
			}

			if ( in_array( $target, $tokens, true ) ) {
				return true;
			}
		}

		return false;
	}
}
