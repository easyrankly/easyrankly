<?php
/**
 * Multilingual module — relation repository.
 *
 * CRUD for hreflang translation groups in the network-wide table.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database access for the erankly_ml_relations table.
 */
final class ERankly_ML_Repository {

	/**
	 * Object cache group.
	 */
	private const CACHE_GROUP = 'erankly_ml';

	/**
	 * Returns the full table name (uses base_prefix so it is network-wide).
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'erankly_ml_relations';
	}

	// Read.

	/**
	 * Returns all members of a translation group.
	 *
	 * @param int $group_id Group ID.
	 * @return array<int,array{id:int,group_id:int,blog_id:int,object_type:string,object_id:int,updated_at:string}>
	 */
	public function get_group_members( int $group_id ): array {
		$cache_key = 'group_' . $group_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::get_table_name();
		$sql   = $wpdb->prepare(
			'SELECT * FROM %i WHERE group_id = %d ORDER BY blog_id ASC',
			$table,
			$group_id
		);
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom ML table query prepared above; cached below.

		$result = is_array( $rows ) ? array_map( array( $this, 'cast_row' ), $rows ) : array();
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Finds the group ID for a given object.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type 'post', 'term', or 'home'.
	 * @param int    $object_id   Post/term ID (0 for home).
	 * @return int Group ID, or 0 if not found.
	 */
	public function find_group_id( int $blog_id, string $object_type, int $object_id ): int {
		$cache_key = "gid_{$blog_id}_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$table = self::get_table_name();
		$sql   = $wpdb->prepare(
			'SELECT group_id FROM %i WHERE blog_id = %d AND object_type = %s AND object_id = %d LIMIT 1',
			$table,
			$blog_id,
			$object_type,
			$object_id
		);
		$row   = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom ML table query prepared above; cached below.

		$result = $row ? (int) $row : 0;
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Returns the full group (members) for a given object.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_group_for_object( int $blog_id, string $object_type, int $object_id ): array {
		$group_id = $this->find_group_id( $blog_id, $object_type, $object_id );

		if ( 0 === $group_id ) {
			return array();
		}

		return $this->get_group_members( $group_id );
	}

	/**
	 * Returns the single row for an object, or null.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string,mixed>|null
	 */
	public function find_row( int $blog_id, string $object_type, int $object_id ): ?array {
		global $wpdb;
		$table = self::get_table_name();
		$sql   = $wpdb->prepare(
			'SELECT * FROM %i WHERE blog_id = %d AND object_type = %s AND object_id = %d LIMIT 1',
			$table,
			$blog_id,
			$object_type,
			$object_id
		);
		$row   = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom ML table single-row lookup.

		return is_array( $row ) ? $this->cast_row( $row ) : null;
	}

	// Write.

	/**
	 * Links an object into a group.
	 *
	 * If the object is already in a group, it is first removed from the old one.
	 * If group_id is 0, a new group is created using the next available sequence.
	 *
	 * @param int    $group_id    Existing group ID, or 0 to create.
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int The group ID used (existing or newly created).
	 */
	public function link( int $group_id, int $blog_id, string $object_type, int $object_id ): int {
		global $wpdb;
		$table = self::get_table_name();

		// Remove this exact object from any group it currently belongs to.
		$this->unlink( $blog_id, $object_type, $object_id );

		if ( 0 === $group_id ) {
			$group_id = $this->next_group_id();
		}

		// One member per blog per group. The UNIQUE key stops the same object appearing
		// twice, but not two different objects from the same blog (e.g. a translation
		// re-pointed to another post) — that would show the same language twice in the
		// switcher, so drop any stale slot for this blog first.
		$this->clear_blog_slot( $group_id, $blog_id, $object_type, $object_id );

		$sql = $wpdb->prepare(
			'INSERT INTO %i (group_id, blog_id, object_type, object_id, updated_at) VALUES (%d, %d, %s, %d, %s)',
			$table,
			$group_id,
			$blog_id,
			$object_type,
			$object_id,
			current_time( 'mysql' )
		);
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation; cache invalidated below.

		$this->invalidate_object( $blog_id, $object_type, $object_id );
		$this->invalidate_group( $group_id );

		return $group_id;
	}

	/**
	 * Removes an object from its translation group.
	 *
	 * The remaining members of the group are kept intact (group still valid with
	 * fewer members). The caller is responsible for linking the removed object
	 * to a new group if needed.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return void
	 */
	public function unlink( int $blog_id, string $object_type, int $object_id ): void {
		global $wpdb;
		$table    = self::get_table_name();
		$group_id = $this->find_group_id( $blog_id, $object_type, $object_id );

		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE blog_id = %d AND object_type = %s AND object_id = %d',
			$table,
			$blog_id,
			$object_type,
			$object_id
		);
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation; cache invalidated below.

		$this->invalidate_object( $blog_id, $object_type, $object_id );

		if ( $group_id > 0 ) {
			$this->invalidate_group( $group_id );
		}
	}

	/**
	 * Removes any member of a group that belongs to a blog, except the object
	 * that is about to be (re)linked.
	 *
	 * Guarantees that a translation group never lists the same blog twice, which
	 * would surface as a duplicated language in the frontend switcher.
	 *
	 * @param int    $group_id       Target group ID.
	 * @param int    $blog_id        Blog ID whose slot must be cleared.
	 * @param string $object_type    Object type ('post' or 'term').
	 * @param int    $keep_object_id Object ID that should remain linked.
	 * @return void
	 */
	private function clear_blog_slot( int $group_id, int $blog_id, string $object_type, int $keep_object_id ): void {
		global $wpdb;
		$table = self::get_table_name();

		// Collect the object IDs that will be removed so their per-object group
		// caches can be invalidated alongside the group cache.
		$sql   = $wpdb->prepare(
			'SELECT object_id FROM %i WHERE group_id = %d AND blog_id = %d AND object_type = %s AND object_id <> %d',
			$table,
			$group_id,
			$blog_id,
			$object_type,
			$keep_object_id
		);
		$stale = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup before targeted delete; caches invalidated below.

		if ( empty( $stale ) ) {
			return;
		}

		$del = $wpdb->prepare(
			'DELETE FROM %i WHERE group_id = %d AND blog_id = %d AND object_type = %s AND object_id <> %d',
			$table,
			$group_id,
			$blog_id,
			$object_type,
			$keep_object_id
		);
		$wpdb->query( $del ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation; caches invalidated below.

		foreach ( $stale as $oid ) {
			$this->invalidate_object( $blog_id, $object_type, (int) $oid );
		}
		$this->invalidate_group( $group_id );
	}

	/**
	 * Deletes all relations for a blog (e.g. when a site is deleted).
	 *
	 * @param int $blog_id Blog ID.
	 * @return void
	 */
	public function delete_blog( int $blog_id ): void {
		global $wpdb;
		$table = self::get_table_name();

		// Collect affected group IDs before deleting.
		$sql       = $wpdb->prepare( 'SELECT DISTINCT group_id FROM %i WHERE blog_id = %d', $table, $blog_id );
		$group_ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup before mass delete.

		$del_sql = $wpdb->prepare( 'DELETE FROM %i WHERE blog_id = %d', $table, $blog_id );
		$wpdb->query( $del_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Mass delete with prepared query.

		foreach ( (array) $group_ids as $gid ) {
			$this->invalidate_group( (int) $gid );
		}
	}

	// Cache helpers.

	/**
	 * Invalidates cached data for a group.
	 *
	 * @param int $group_id Group ID.
	 * @return void
	 */
	private function invalidate_group( int $group_id ): void {
		wp_cache_delete( 'group_' . $group_id, self::CACHE_GROUP );
	}

	/**
	 * Invalidates cached group-ID lookup for a specific object.
	 *
	 * @param int    $blog_id     Blog ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return void
	 */
	private function invalidate_object( int $blog_id, string $object_type, int $object_id ): void {
		wp_cache_delete( "gid_{$blog_id}_{$object_type}_{$object_id}", self::CACHE_GROUP );
	}

	// Utilities.

	/**
	 * Returns the next available group ID (max existing + 1).
	 *
	 * @return int
	 */
	private function next_group_id(): int {
		global $wpdb;
		$table = self::get_table_name();
		$max   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(group_id) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap aggregate on custom table.
		return $max + 1;
	}

	/**
	 * Casts a raw DB row to typed values.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function cast_row( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'group_id'    => (int) $row['group_id'],
			'blog_id'     => (int) $row['blog_id'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'updated_at'  => (string) $row['updated_at'],
		);
	}
}
