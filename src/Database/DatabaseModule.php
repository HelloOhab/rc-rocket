<?php
declare( strict_types=1 );

namespace RCRocket\Database;

use RCRocket\Container;
use RCRocket\Contracts\Module;
use RCRocket\Support\Logger;
use RCRocket\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module H — database housekeeping.
 *
 * Divi stores a full copy of the layout as a revision on every builder save,
 * and a page edited a few hundred times carries a few hundred copies of its
 * shortcode tree. On Kinsta the database lives on the same plan as everything
 * else, and a bloated posts table slows every query that touches it, cached
 * pages or not.
 *
 * Every cleanup works in bounded batches through WordPress's own delete
 * functions, so hooks fire and related meta goes with each row. Nothing is
 * deleted that the user did not tick: see defaults().
 */
final class DatabaseModule implements Module {

	public const CRON_HOOK = 'rc-rocket/database/cleanup';
	public const LAST_RUN  = 'rcrocket_db_last_run';

	/** Rows per item per run. Large backlogs clear over several runs. */
	private const BATCH = 500;

	public function id(): string {
		return 'database';
	}

	public function label(): string {
		return __( 'Database', 'rc-rocket' );
	}

	/**
	 * Deleting is opt-in. Revisions are Divi's undo history and comments in
	 * spam or trash are someone's to review: none of it goes, and nothing is
	 * scheduled, until a person ticks it.
	 */
	public function defaults(): array {
		return [
			'enabled'            => true,
			'revisions'          => false,
			'revisions_keep'     => 5,
			'auto_drafts'        => true,
			'trashed_posts'      => false,
			'spam_comments'      => false,
			'trashed_comments'   => false,
			'expired_transients' => true,
			'optimize_tables'    => false,
			'schedule'           => 'off',
		];
	}

	public function register( Container $container ): void {
		$container->set( 'database', fn(): self => $this );
	}

	public function boot( Container $container ): void {
		/** @var Settings $settings */
		$settings = $container->get( 'settings' );
		$schedule = (string) $settings->get( 'database.schedule', 'off' );

		add_filter( 'cron_schedules', [ self::class, 'add_monthly' ] );

		add_action(
			self::CRON_HOOK,
			function () use ( $container ): void {
				$this->run( (array) $container->get( 'settings' )->get( 'database', [] ), $container->get( 'logger' ) );
			}
		);

		$next    = wp_next_scheduled( self::CRON_HOOK );
		$current = $next ? wp_get_schedule( self::CRON_HOOK ) : false;

		if ( 'off' === $schedule || ! in_array( $schedule, [ 'daily', 'weekly', 'monthly' ], true ) ) {
			if ( $next ) {
				wp_unschedule_hook( self::CRON_HOOK );
			}

			return;
		}

		if ( $current !== $schedule ) {
			wp_unschedule_hook( self::CRON_HOOK );
			// Overnight, server time, when nobody is editing.
			wp_schedule_event( strtotime( 'tomorrow 03:30' ) ?: time() + DAY_IN_SECONDS, $schedule, self::CRON_HOOK );
		}
	}

	/** WordPress has hourly to weekly; a monthly tidy-up is the common ask. */
	public static function add_monthly( array $schedules ): array {
		$schedules['monthly'] ??= [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => 'Once a month',
		];

		return $schedules;
	}

	/**
	 * How much each cleanup would remove right now.
	 *
	 * @return array<string, int>
	 */
	public function counts( int $keep_revisions ): array {
		global $wpdb;

		return [
			'revisions'          => $this->surplus_revision_count( max( 0, $keep_revisions ) ),
			'auto_drafts'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_modified_gmt < %s", $this->week_ago() ) ), // phpcs:ignore
			'trashed_posts'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ), // phpcs:ignore
			'spam_comments'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ), // phpcs:ignore
			'trashed_comments'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('trash','post-trashed')" ), // phpcs:ignore
			'expired_transients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ), // phpcs:ignore
			'optimize_tables'    => count( $this->fragmented_tables() ),
		];
	}

	/**
	 * Run every ticked cleanup, or only the ones named.
	 *
	 * @param string[]|null $only
	 * @return array<string, int> Rows removed (tables optimized) per item.
	 */
	public function run( array $config, Logger $logger, ?array $only = null ): array {
		$items = array_keys( array_filter( $this->counts_template() ) );
		$done  = [];

		foreach ( $items as $item ) {
			$wanted = null === $only ? ! empty( $config[ $item ] ) : in_array( $item, $only, true );

			if ( ! $wanted ) {
				continue;
			}

			$done[ $item ] = match ( $item ) {
				'revisions'          => $this->clean_revisions( max( 1, (int) ( $config['revisions_keep'] ?? 5 ) ) ),
				'auto_drafts'        => $this->clean_posts( "post_status = 'auto-draft' AND post_modified_gmt < '" . esc_sql( $this->week_ago() ) . "'" ),
				'trashed_posts'      => $this->clean_posts( "post_status = 'trash'" ),
				'spam_comments'      => $this->clean_comments( "comment_approved = 'spam'" ),
				'trashed_comments'   => $this->clean_comments( "comment_approved IN ('trash','post-trashed')" ),
				'expired_transients' => $this->clean_transients(),
				'optimize_tables'    => $this->optimize_tables(),
				default              => 0,
			};
		}

		update_option(
			self::LAST_RUN,
			[
				'time'    => time(),
				'removed' => $done,
			],
			false
		);

		$logger->debug( 'Database cleanup', $done );

		return $done;
	}

	public function last_run(): array {
		$last = get_option( self::LAST_RUN, [] );

		return is_array( $last ) ? $last : [];
	}

	/** @return array<string, bool> */
	private function counts_template(): array {
		return array_fill_keys( [ 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'optimize_tables' ], true );
	}

	// ------------------------------------------------------------ revisions

	private function surplus_revision_count( int $keep ): int {
		global $wpdb;

		if ( 0 === $keep ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ); // phpcs:ignore
		}

		$rows = $wpdb->get_results( "SELECT post_parent, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > " . (int) $keep ); // phpcs:ignore
		$sum  = 0;

		foreach ( (array) $rows as $row ) {
			$sum += (int) $row->n - $keep;
		}

		return $sum;
	}

	/** Keep the newest $keep revisions of every post, delete the rest. */
	private function clean_revisions( int $keep ): int {
		global $wpdb;

		$parents = $wpdb->get_col( "SELECT post_parent FROM {$wpdb->posts} WHERE post_type = 'revision' GROUP BY post_parent HAVING COUNT(*) > " . (int) $keep ); // phpcs:ignore
		$deleted = 0;

		foreach ( (array) $parents as $parent ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent = %d ORDER BY post_date_gmt DESC, ID DESC LIMIT %d, %d", // phpcs:ignore
					(int) $parent,
					$keep,
					self::BATCH
				)
			);

			foreach ( (array) $ids as $id ) {
				if ( wp_delete_post_revision( (int) $id ) ) {
					++$deleted;
				}

				if ( $deleted >= self::BATCH ) {
					return $deleted;
				}
			}
		}

		return $deleted;
	}

	// ----------------------------------------------------------- posts, etc.

	private function clean_posts( string $where ): int {
		global $wpdb;

		$ids     = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT " . self::BATCH ); // phpcs:ignore
		$deleted = 0;

		foreach ( (array) $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private function clean_comments( string $where ): int {
		global $wpdb;

		$ids     = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE {$where} LIMIT " . self::BATCH ); // phpcs:ignore
		$deleted = 0;

		foreach ( (array) $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	private function clean_transients(): int {
		global $wpdb;

		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' ) . '%' ) ); // phpcs:ignore

		// Core's own routine: it also removes the value rows and handles
		// network transients on multisite.
		delete_expired_transients( true );

		$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_timeout_' ) . '%' ) ); // phpcs:ignore

		return max( 0, $before - $after );
	}

	// --------------------------------------------------------------- tables

	/**
	 * Only this site's tables, and only those with reclaimable space. On
	 * InnoDB OPTIMIZE rebuilds the table, so a table with nothing to gain is
	 * never touched.
	 *
	 * @return string[]
	 */
	private function fragmented_tables(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, DATA_FREE AS free FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);

		$tables = [];

		foreach ( (array) $rows as $row ) {
			if ( (int) $row->free > 1024 * 1024 ) {
				$tables[] = (string) $row->name;
			}
		}

		return $tables;
	}

	private function optimize_tables(): int {
		global $wpdb;

		$done = 0;

		foreach ( $this->fragmented_tables() as $table ) {
			if ( false !== $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' ) ) { // phpcs:ignore
				++$done;
			}
		}

		return $done;
	}

	private function week_ago(): string {
		return gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );
	}
}
