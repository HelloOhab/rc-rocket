<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All plugin settings live in one autoloaded option. One row, one query,
 * already in WP's alloptions cache — never a settings table, never one
 * option per toggle.
 */
final class Settings {

	public const OPTION = 'rcrocket_settings';

	/** @var array<string, mixed> */
	private array $data = [];

	/** @var array<string, mixed> */
	private array $defaults = [];

	private bool $loaded = false;

	/**
	 * Modules hand their defaults over at registration time, before load().
	 *
	 * @param array<string, mixed> $defaults
	 */
	public function add_defaults( string $namespace, array $defaults ): void {
		$this->defaults[ $namespace ] = $defaults;
		$this->loaded                 = false;
	}

	public function all(): array {
		$this->load();

		return $this->data;
	}

	public function defaults(): array {
		return $this->defaults;
	}

	/**
	 * Read with dot notation: get( 'cache.ttl' ).
	 */
	public function get( string $path, mixed $fallback = null ): mixed {
		$this->load();

		$cursor = $this->data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $fallback;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	public function enabled( string $path ): bool {
		return (bool) $this->get( $path, false );
	}

	public function set( string $path, mixed $value ): void {
		$this->load();

		$segments = explode( '.', $path );
		$cursor   = &$this->data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor = &$cursor[ $segment ];
		}

		$cursor = $value;
		unset( $cursor );
	}

	/**
	 * Merge a partial payload (e.g. from the REST endpoint) over current values.
	 *
	 * @param array<string, mixed> $incoming
	 */
	public function merge( array $incoming ): void {
		$this->load();
		$this->data = self::deep_merge( $this->data, $incoming );
	}

	public function save(): bool {
		$this->load();

		/**
		 * Fires before settings are persisted. The Safety Net module hooks this
		 * to snapshot the previous state for one-click rollback.
		 *
		 * @param array $new Incoming settings.
		 * @param array $old Currently stored settings.
		 */
		do_action( 'rc-rocket/settings/before_save', $this->data, get_option( self::OPTION, [] ) );

		$saved = update_option( self::OPTION, $this->data, true );

		do_action( 'rc-rocket/settings/saved', $this->data );

		return $saved;
	}

	/** Config-as-code: stable, diffable JSON for version control. */
	public function export(): string {
		return (string) wp_json_encode( $this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	public function import( string $json ): bool {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return false;
		}

		$this->data = self::deep_merge( $this->defaults, $decoded );

		// Mark as loaded before saving. save() calls load(), and load() would
		// otherwise see an unloaded instance and replace everything just
		// imported with whatever is already in the database — silently turning
		// every import into a no-op.
		$this->loaded = true;

		return $this->save();
	}

	public function reset(): bool {
		$this->data   = $this->defaults;
		$this->loaded = true;

		return $this->save();
	}

	private function load(): void {
		if ( $this->loaded ) {
			return;
		}

		$stored     = get_option( self::OPTION, [] );
		$this->data = self::deep_merge( $this->defaults, is_array( $stored ) ? $stored : [] );

		$this->loaded = true;
	}

	/**
	 * Recursive merge where numerically indexed arrays (exclusion lists) are
	 * replaced wholesale rather than concatenated — otherwise removing an
	 * exclusion in the UI would be impossible.
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ! array_is_list( $value ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}
