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

	/** Drop a key entirely, so a retired setting stops round-tripping. */
	public function remove( string $path ): void {
		$this->load();

		$segments = explode( '.', $path );
		$last     = array_pop( $segments );
		$cursor   = &$this->data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				unset( $cursor );

				return;
			}
			$cursor = &$cursor[ $segment ];
		}

		unset( $cursor[ $last ] );
		unset( $cursor );
	}

	/**
	 * Merge a partial payload (e.g. from the REST endpoint) over current values.
	 *
	 * @param array<string, mixed> $incoming
	 */
	public function merge( array $incoming ): void {
		$this->load();
		$this->data = self::deep_merge( $this->data, $this->conform( $incoming ) );
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

		// The schema version describes this database, not the file: keep it,
		// or the next request re-runs every migration over imported values.
		$this->load();
		$schema = $this->data['general']['schema_version'] ?? null;

		$this->data = self::deep_merge( $this->defaults, $this->conform( $decoded ) );

		if ( null !== $schema ) {
			$this->data['general']['schema_version'] = $schema;
		}

		// Mark as loaded before saving. save() calls load(), and load() would
		// otherwise see an unloaded instance and replace everything just
		// imported with whatever is already in the database — silently turning
		// every import into a no-op.
		$this->loaded = true;

		// update_option() reports false when nothing changed. Importing the
		// same file twice is a success, not a failure.
		$this->save();

		return true;
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
	 * Settings arriving from outside — the admin, an imported file, a
	 * rollback, a preset — keep only values of the type the default has.
	 * One malformed file copied across sites must not reach code that
	 * expects a string and fatal every page. Keys with no default (stored
	 * lists of records such as hero preloads) pass through unchanged.
	 * The schema version belongs to the migrations, never to input.
	 *
	 * @param array<string, mixed> $incoming
	 * @return array<string, mixed>
	 */
	private function conform( array $incoming ): array {
		if ( isset( $incoming['general'] ) && is_array( $incoming['general'] ) ) {
			unset( $incoming['general']['schema_version'] );

			if ( [] === $incoming['general'] ) {
				unset( $incoming['general'] );
			}
		}

		return self::conform_branch( $this->defaults, $incoming );
	}

	private static function conform_branch( array $defaults, array $incoming ): array {
		$out = [];

		foreach ( $incoming as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				$out[ $key ] = $value;
				continue;
			}

			$default = $defaults[ $key ];

			if ( is_array( $default ) && ! array_is_list( $default ) ) {
				// An emptied branch is left out: deep_merge() would otherwise
				// replace the whole stored section with nothing.
				$branch = is_array( $value ) ? self::conform_branch( $default, $value ) : [];

				if ( [] !== $branch ) {
					$out[ $key ] = $branch;
				}
				continue;
			}

			if ( is_array( $default ) ) {
				if ( ! is_array( $value ) ) {
					continue;
				}

				// A list of strings stays a list of strings. An empty default
				// carries no type (a list of records, or a map keyed by
				// handle), so any array is kept as it is.
				$out[ $key ] = [] !== $default && is_scalar( reset( $default ) )
					? array_values( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) )
					: $value;
				continue;
			}

			if ( is_bool( $default ) ) {
				if ( is_bool( $value ) || in_array( $value, [ 0, 1, '0', '1', 'true', 'false' ], true ) ) {
					$out[ $key ] = is_bool( $value ) ? $value : in_array( $value, [ 1, '1', 'true' ], true );
				}
			} elseif ( is_int( $default ) || is_float( $default ) ) {
				if ( is_numeric( $value ) ) {
					$out[ $key ] = is_int( $default ) ? (int) $value : (float) $value;
				}
			} elseif ( is_string( $default ) ) {
				if ( is_scalar( $value ) ) {
					$out[ $key ] = (string) $value;
				}
			} elseif ( null === $default ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
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
