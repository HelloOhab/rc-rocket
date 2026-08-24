<?php
declare( strict_types=1 );

namespace RCRocket\Contracts;

use RCRocket\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Module {

	/** Stable machine id. Doubles as the settings namespace key. */
	public function id(): string;

	/** Human label for the admin UI. */
	public function label(): string;

	/** Register services on the container. Runs for every module, always. */
	public function register( Container $container ): void;

	/** Attach hooks. Only runs when the module is enabled. */
	public function boot( Container $container ): void;

	/** Default settings for this module. */
	public function defaults(): array;
}
