<?php
declare( strict_types=1 );

namespace RCRocket\Assets;

use RCRocket\Support\Context;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records which scripts and styles actually load, grouped by template.
 *
 * Perfmatters makes you visit each page with a toolbar open. That does not
 * scale past a handful of pages and it does not survive a plugin update. This
 * records automatically as normal traffic arrives, indexed by template
 * signature rather than URL, then throttles itself so a busy site is not
 * writing an option on every request.
 */
final class Registry {

	public const OPTION = 'rcrocket_asset_index';

	private const REFRESH_AFTER = DAY_IN_SECONDS;
	private const MAX_TEMPLATES = 60;

	public function __construct( private Context $context ) {}

	public function hooks(): void {
		// Late enough that footer scripts have been printed.
		add_action( 'wp_print_footer_scripts', [ $this, 'record' ], PHP_INT_MAX );
	}

	public function record(): void {
		$signature = $this->context->signature();
		$index     = $this->all();

		$existing = $index[ $signature ] ?? null;

		if ( is_array( $existing ) && ( time() - (int) ( $existing['seen'] ?? 0 ) ) < self::REFRESH_AFTER ) {
			return;
		}

		$index[ $signature ] = [
			'seen'    => time(),
			// REQUEST_URI already includes any subdirectory; home_url() would
			// add it a second time.
			'url'     => esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '/' ) ), // phpcs:ignore
			'scripts' => $this->collect( 'scripts' ),
			'styles'  => $this->collect( 'styles' ),
		];

		if ( count( $index ) > self::MAX_TEMPLATES ) {
			uasort( $index, static fn( array $a, array $b ): int => (int) $b['seen'] <=> (int) $a['seen'] );
			$index = array_slice( $index, 0, self::MAX_TEMPLATES, true );
		}

		update_option( self::OPTION, $index, false );
	}

	public function all(): array {
		$index = get_option( self::OPTION, [] );

		return is_array( $index ) ? $index : [];
	}

	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Every unique handle across every recorded template, with its owner and
	 * the templates it appears on. This is what the admin UI renders.
	 */
	public function handles(): array {
		$handles = [];

		foreach ( $this->all() as $signature => $record ) {
			foreach ( [ 'scripts' => 'script', 'styles' => 'style' ] as $bucket => $kind ) {
				foreach ( (array) ( $record[ $bucket ] ?? [] ) as $handle => $meta ) {
					$key = $kind . ':' . $handle;

					if ( ! isset( $handles[ $key ] ) ) {
						$handles[ $key ] = [
							'handle'    => (string) $handle,
							'kind'      => $kind,
							'src'       => (string) ( $meta['src'] ?? '' ),
							'owner'     => (string) ( $meta['owner'] ?? 'Unknown' ),
							'protected' => in_array( (string) $handle, Presets::protected_handles(), true ),
							'templates' => [],
						];
					}

					$handles[ $key ]['templates'][] = (string) $signature;
				}
			}
		}

		uasort(
			$handles,
			static fn( array $a, array $b ): int => [ $a['owner'], $a['handle'] ] <=> [ $b['owner'], $b['handle'] ]
		);

		return array_values( $handles );
	}

	private function collect( string $which ): array {
		$registry = 'scripts' === $which ? wp_scripts() : wp_styles();
		$out      = [];

		foreach ( (array) $registry->done as $handle ) {
			$handle = (string) $handle;
			$item   = $registry->registered[ $handle ] ?? null;
			$src    = $item && is_string( $item->src ) ? $item->src : '';

			$out[ $handle ] = [
				'src'   => $src,
				'owner' => Presets::owner_for( $handle, $src ),
			];
		}

		return $out;
	}
}
