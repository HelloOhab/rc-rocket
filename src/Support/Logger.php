<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger {

	/** Past this size the log keeps only its newest half. */
	private const MAX_BYTES = 1048576;

	public function __construct( private string $file, private bool $enabled ) {}

	public function debug( string $message, array $context = [] ): void {
		$this->write( 'debug', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( 'error', $message, $context );
	}

	public function tail( int $lines = 200 ): array {
		if ( ! is_readable( $this->file ) ) {
			return [];
		}

		$contents = (string) file_get_contents( $this->file );

		return array_slice( array_filter( explode( "\n", $contents ) ), -$lines );
	}

	public function clear(): void {
		if ( file_exists( $this->file ) ) {
			@unlink( $this->file ); // phpcs:ignore
		}
	}

	private function write( string $level, string $message, array $context ): void {
		if ( ! $this->enabled && 'error' !== $level ) {
			return;
		}

		Filesystem::ensure_dir( dirname( $this->file ) );

		$line = sprintf(
			'[%s] %s: %s %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? (string) wp_json_encode( $context ) : ''
		);

		$this->rotate();

		@file_put_contents( $this->file, rtrim( $line ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore
	}

	/**
	 * A debug log left switched on for months must not fill the disk: on
	 * Kinsta that is a site that stops accepting uploads. Keep the newest
	 * half, cut on a line boundary.
	 */
	private function rotate(): void {
		clearstatcache( true, $this->file );

		if ( ! is_file( $this->file ) || (int) filesize( $this->file ) < self::MAX_BYTES ) {
			return;
		}

		$handle = @fopen( $this->file, 'rb' ); // phpcs:ignore

		if ( false === $handle ) {
			return;
		}

		fseek( $handle, -(int) ( self::MAX_BYTES / 2 ), SEEK_END );
		$tail = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore

		$newline = strpos( $tail, "\n" );
		$tail    = false === $newline ? '' : substr( $tail, $newline + 1 );

		@file_put_contents( $this->file, $tail, LOCK_EX ); // phpcs:ignore
	}
}
