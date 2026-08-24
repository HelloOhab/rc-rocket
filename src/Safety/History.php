<?php
declare( strict_types=1 );

namespace RCRocket\Safety;

use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings history with diffs and rollback.
 *
 * "It broke sometime this week and I don't know what I changed" is the single
 * most common way a performance plugin wastes a day. Every save is snapshotted
 * with who did it and what changed.
 */
final class History {

	public function __construct( private Settings $settings ) {}

	/** Paths that are runtime state, never a decision anyone made. */
	private const IGNORED = [
		'general.auto_safe_mode_until',
		'general.auto_safe_mode_reason',
	];

	public function snapshot( array $new, array $old ): void {
		if ( ! $old ) {
			return; // First save; nothing to roll back to.
		}

		$changes = array_values(
			array_filter(
				$this->diff( $old, $new ),
				static fn( array $c ): bool => ! in_array( $c['path'], self::IGNORED, true )
			)
		);

		if ( ! $changes ) {
			return;
		}

		$entries = $this->all();

		array_unshift(
			$entries,
			[
				'id'      => uniqid( 'h', true ),
				'time'    => time(),
				'user'    => (string) ( wp_get_current_user()->display_name ?: 'system' ),
				'changes' => array_slice( $changes, 0, 40 ),
				'state'   => $old,
			]
		);

		update_option( SafetyModule::HISTORY_OPTION, array_slice( $entries, 0, 25 ), false );
	}

	/**
	 * Remove entries earlier versions wrote for automatic rollbacks. A history
	 * you cannot read is not a history.
	 */
	public function prune(): int {
		$entries = $this->all();
		$kept    = array_values(
			array_filter(
				$entries,
				static function ( array $entry ): bool {
					foreach ( (array) ( $entry['changes'] ?? [] ) as $change ) {
						if ( ! in_array( (string) ( $change['path'] ?? '' ), self::IGNORED, true ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);

		update_option( SafetyModule::HISTORY_OPTION, $kept, false );

		return count( $entries ) - count( $kept );
	}

	public function all(): array {
		$entries = get_option( SafetyModule::HISTORY_OPTION, [] );

		return is_array( $entries ) ? $entries : [];
	}

	/** Summaries only — the full previous state is large and rarely needed. */
	public function listing(): array {
		return array_map(
			static fn( array $e ): array => [
				'id'      => $e['id'],
				'time'    => $e['time'],
				'user'    => $e['user'],
				'changes' => $e['changes'],
			],
			$this->all()
		);
	}

	public function restore( string $id ): bool {
		foreach ( $this->all() as $entry ) {
			if ( $entry['id'] !== $id ) {
				continue;
			}

			$this->settings->merge( (array) $entry['state'] );

			return $this->settings->save();
		}

		return false;
	}

	/**
	 * Flat dot-notation diff, so the UI can render "js.delay: off to on".
	 */
	private function diff( array $old, array $new, string $prefix = '' ): array {
		$changes = [];
		$keys    = array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) );

		foreach ( $keys as $key ) {
			$path      = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			$old_value = $old[ $key ] ?? null;
			$new_value = $new[ $key ] ?? null;

			if ( is_array( $old_value ) && is_array( $new_value ) && ! array_is_list( $new_value ) ) {
				$changes = array_merge( $changes, $this->diff( $old_value, $new_value, $path ) );
				continue;
			}

			if ( $old_value === $new_value ) {
				continue;
			}

			$changes[] = [
				'path' => $path,
				'from' => $this->readable( $old_value ),
				'to'   => $this->readable( $new_value ),
			];
		}

		return $changes;
	}

	private function readable( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'on' : 'off';
		}

		if ( is_array( $value ) ) {
			return sprintf( '%d item%s', count( $value ), 1 === count( $value ) ? '' : 's' );
		}

		if ( null === $value ) {
			return 'unset';
		}

		return (string) $value;
	}
}
