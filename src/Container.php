<?php
declare( strict_types=1 );

namespace RCRocket;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deliberately tiny service locator. Services are registered as lazy factories
 * so a module that is switched off costs nothing but an array entry.
 */
final class Container {

	/** @var array<string, callable> */
	private array $factories = [];

	/** @var array<string, mixed> */
	private array $resolved = [];

	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}

	public function get( string $id ): mixed {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( sprintf( 'RC Rocket: service "%s" is not registered.', $id ) );
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}
}
