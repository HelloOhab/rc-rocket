<?php
declare( strict_types=1 );

namespace RCRocket\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Direct filesystem access. WP_Filesystem is the polite API, but the cache
 * writer runs on every uncached page view and cannot afford its overhead or
 * its credential prompts.
 */
final class Filesystem {

	public static function ensure_dir( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Never serve a browsable index of the cache.
		$index = rtrim( $dir, '/' ) . '/index.html';

		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore
		}

		return true;
	}

	/**
	 * Write via temp file + rename so a concurrent reader never sees a
	 * half-written page. rename() is atomic on the same filesystem.
	 */
	public static function atomic_write( string $path, string $contents ): bool {
		if ( ! self::ensure_dir( dirname( $path ) ) ) {
			return false;
		}

		$temp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';

		if ( false === @file_put_contents( $temp, $contents, LOCK_EX ) ) { // phpcs:ignore
			return false;
		}

		if ( ! @rename( $temp, $path ) ) { // phpcs:ignore
			@unlink( $temp ); // phpcs:ignore

			return false;
		}

		@chmod( $path, 0644 ); // phpcs:ignore

		return true;
	}

	public static function delete_tree( string $dir, bool $keep_root = true ): int {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$deleted  = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore
				continue;
			}

			if ( @unlink( $item->getPathname() ) ) { // phpcs:ignore
				++$deleted;
			}
		}

		if ( ! $keep_root ) {
			@rmdir( $dir ); // phpcs:ignore
		}

		return $deleted;
	}

	/** @return array{files:int, bytes:int} */
	public static function measure( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return [
				'files' => 0,
				'bytes' => 0,
			];
		}

		$files = 0;
		$bytes = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $item ) {
			/** @var \SplFileInfo $item */
			if ( ! $item->isFile() || 'html' !== $item->getExtension() ) {
				continue;
			}

			// Every directory carries an empty index.html so the cache cannot
			// be browsed. Counting those as cached pages inflates the figure on
			// the dashboard by roughly the number of shards in use.
			if ( 'index.html' === $item->getFilename() ) {
				continue;
			}

			++$files;
			$bytes += $item->getSize();
		}

		return [
			'files' => $files,
			'bytes' => $bytes,
		];
	}
}
