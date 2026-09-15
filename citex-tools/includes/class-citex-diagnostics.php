<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only investigative tooling for the "Published but invisible until a
 * manual wp-admin Update click" report.
 *
 * This plugin has no code-level visibility into the separate "Citex student
 * app" — there is no trace of it anywhere in this repository (no REST route,
 * shortcode, custom table, or frontend rendering code). Citex_Populator
 * already fires every standard WordPress/ACF save-lifecycle hook a plugin
 * could reasonably listen to (save_post, save_post_{post_type}, wp_insert_post,
 * wp_after_insert_post, transition_post_status, {old}_to_{new}, publish_{post_type},
 * acf/save_post — see its class docblock), but a live re-test showed this was
 * not sufficient, and the actual mechanism the student app depends on is not
 * discoverable from source code that isn't in this repository.
 *
 * Rather than guess a third, unverified mechanism, this class gives an admin
 * two things only a live site can actually produce:
 *
 * 1. list_registered_callbacks() — introspects WordPress's real, live
 *    $wp_filter registry for the exact save-lifecycle hooks Citex fires, and
 *    reports every callback actually listening on them (with source
 *    file/line where derivable). This answers "what code reacts when a
 *    question is saved" directly from the live site's own hook registry,
 *    instead of guessing which plugin might care.
 * 2. capture_post_state() / diff_snapshots() — a full, exact dump of one
 *    post's fields, postmeta, taxonomy terms and ACF field values, so an
 *    admin can capture it once before a manual "Update" click and once
 *    after, and see precisely what changed — the same before/after
 *    comparison the underlying bug report asked for, produced from the real
 *    site rather than assumed.
 *
 * Entirely read-only: nothing here writes to a post, fires a save hook, or
 * changes any WordPress state. Snapshots are stored in a capped WordPress
 * option, never in post data.
 */
class Citex_Diagnostics {

	const NONCE_ACTION     = 'citex_diagnostics';
	const OPTION_SNAPSHOTS = 'citex_diagnostics_snapshots';
	const MAX_SNAPSHOTS    = 2;

	/**
	 * The fixed, standard WordPress/ACF hooks Citex_Populator relies on to
	 * reproduce a manual "Update" click (see its class docblock). Reported
	 * here — not assumed — so an admin can see exactly who is really
	 * listening on the live site.
	 */
	const STATIC_LIFECYCLE_HOOKS = array(
		'wp_insert_post',
		'wp_after_insert_post',
		'save_post',
		'transition_post_status',
		'acf/save_post',
		'clean_post_cache',
	);

	public function render() {
		$this->maybe_handle_submit();

		$scan      = Citex_Scanner::get_last_scan();
		$post_type = sanitize_key( (string) ( $scan['postType'] ?? '' ) );
		$snapshots = self::get_snapshots();
		$hook_report = $post_type ? self::list_registered_callbacks( self::hooks_for_post_type( $post_type ) ) : array();

		require CITEX_TOOLS_PATH . 'admin/views/diagnostics.php';
	}

	/**
	 * Called on admin_init (before any output), matching every other Citex
	 * admin handler's pattern — see class-citex-admin.php.
	 */
	public function maybe_handle_submit() {
		if ( ! empty( $_POST['citex_diagnostics_capture'] ) ) {
			check_admin_referer( self::NONCE_ACTION, 'citex_diagnostics_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to view Citex diagnostics.', 'citex-tools' ) );
			}

			$post_id = isset( $_POST['citex_diagnostics_post_id'] ) ? absint( wp_unslash( $_POST['citex_diagnostics_post_id'] ) ) : 0;
			$label   = isset( $_POST['citex_diagnostics_label'] ) ? sanitize_key( wp_unslash( $_POST['citex_diagnostics_label'] ) ) : 'before';
			if ( ! in_array( $label, array( 'before', 'after' ), true ) ) {
				$label = 'before';
			}

			$state = self::capture_post_state( $post_id );
			if ( is_wp_error( $state ) ) {
				Citex_Admin::set_notice( $state->get_error_message(), 'error' );
				$this->redirect_back();
			}

			self::store_snapshot( $post_id, $label, $state );
			Citex_Admin::set_notice(
				sprintf(
					/* translators: 1: before/after label, 2: post ID */
					__( 'Citex Diagnostics: captured "%1$s" snapshot for post #%2$d.', 'citex-tools' ),
					$label,
					$post_id
				),
				'success'
			);
			$this->redirect_back();
		}

		if ( ! empty( $_POST['citex_diagnostics_clear'] ) ) {
			check_admin_referer( self::NONCE_ACTION, 'citex_diagnostics_nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You are not allowed to view Citex diagnostics.', 'citex-tools' ) );
			}
			$post_id = isset( $_POST['citex_diagnostics_post_id'] ) ? absint( wp_unslash( $_POST['citex_diagnostics_post_id'] ) ) : 0;
			self::clear_snapshots( $post_id );
			Citex_Admin::set_notice( __( 'Citex Diagnostics: snapshots cleared.', 'citex-tools' ), 'success' );
			$this->redirect_back();
		}
	}

	/**
	 * Every hook name relevant to one post type, including the dynamic ones
	 * ({$post_type}-specific and status-transition-specific) that
	 * Citex_Populator's save lifecycle also triggers.
	 */
	public static function hooks_for_post_type( $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		$hooks     = self::STATIC_LIFECYCLE_HOOKS;
		$hooks[]   = 'save_post_' . $post_type;
		$hooks[]   = 'publish_' . $post_type;
		foreach ( array( 'new', 'draft', 'pending', 'private', 'future', 'auto-draft', 'publish', 'trash' ) as $old_status ) {
			$hooks[] = $old_status . '_to_publish';
		}
		return array_values( array_unique( $hooks ) );
	}

	/**
	 * Introspect WordPress's real, live hook registry ($wp_filter) for the
	 * given hook names and report every callback actually registered — never
	 * a guess at what "might" be listening.
	 *
	 * @return array<string, array<int, array{priority:int, callback:string}>>
	 */
	public static function list_registered_callbacks( array $hook_names ) {
		global $wp_filter;
		$report = array();

		foreach ( $hook_names as $hook ) {
			$callbacks = array();
			$registered = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ] : null;

			$by_priority = array();
			if ( is_object( $registered ) && isset( $registered->callbacks ) && is_array( $registered->callbacks ) ) {
				$by_priority = $registered->callbacks;
			} elseif ( is_array( $registered ) ) {
				$by_priority = $registered;
			}

			ksort( $by_priority );
			foreach ( $by_priority as $priority => $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				foreach ( $group as $entry ) {
					$callable = is_array( $entry ) && array_key_exists( 'function', $entry ) ? $entry['function'] : null;
					if ( null === $callable ) {
						continue;
					}
					$callbacks[] = array(
						'priority' => (int) $priority,
						'callback' => self::describe_callback( $callable ),
					);
				}
			}

			$report[ $hook ] = $callbacks;
		}

		return $report;
	}

	/**
	 * Best-effort, non-fatal human-readable description of a registered
	 * callback, including its declaring source file/line when PHP's
	 * Reflection API can resolve it — this is what turns "something is
	 * listening" into "this class, in this file, is listening."
	 */
	private static function describe_callback( $callable ) {
		try {
			if ( is_array( $callable ) && 2 === count( $callable ) ) {
				list( $target, $method ) = $callable;
				$class = is_object( $target ) ? get_class( $target ) : (string) $target;
				if ( is_string( $method ) && method_exists( $class, $method ) ) {
					$ref = new ReflectionMethod( $class, $method );
					return self::format_location( $class . '::' . $method, $ref );
				}
				return $class . '::' . (string) $method;
			}
			if ( $callable instanceof Closure ) {
				$ref = new ReflectionFunction( $callable );
				return self::format_location( 'Closure', $ref );
			}
			if ( is_string( $callable ) && function_exists( $callable ) ) {
				$ref = new ReflectionFunction( $callable );
				return self::format_location( $callable, $ref );
			}
			if ( is_object( $callable ) && method_exists( $callable, '__invoke' ) ) {
				$ref = new ReflectionMethod( $callable, '__invoke' );
				return self::format_location( get_class( $callable ) . '::__invoke', $ref );
			}
		} catch ( ReflectionException $e ) {
			// Fall through to a best-effort plain description below.
		}
		return is_string( $callable ) ? $callable : 'unknown callback';
	}

	private static function format_location( $name, $reflection ) {
		$file = $reflection->getFileName();
		$line = $reflection->getStartLine();
		if ( $file && $line ) {
			return $name . ' (' . $file . ':' . $line . ')';
		}
		return $name;
	}

	/**
	 * A full, exact snapshot of one post's state: core fields, every
	 * postmeta key (raw, unfiltered), every taxonomy term across every
	 * taxonomy attached to its post type, and every ACF field value. Purely
	 * a read — never writes to the post or fires any hook.
	 *
	 * @return array|WP_Error
	 */
	public static function capture_post_state( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return new WP_Error( 'citex_diagnostics_missing_post_id', __( 'Enter a WordPress post ID to capture.', 'citex-tools' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'citex_diagnostics_post_not_found', sprintf( __( 'No post found with ID %d.', 'citex-tools' ), $post_id ) );
		}

		$core = array(
			'ID'                => (int) $post->ID,
			'post_type'         => (string) $post->post_type,
			'post_status'       => (string) $post->post_status,
			'post_title'        => (string) $post->post_title,
			'post_name'         => (string) $post->post_name,
			'post_date'         => (string) $post->post_date,
			'post_modified'     => (string) $post->post_modified,
			'post_modified_gmt' => (string) $post->post_modified_gmt,
			'guid'              => (string) $post->guid,
			'menu_order'        => (int) $post->menu_order,
		);

		$raw_meta = function_exists( 'get_post_meta' ) ? get_post_meta( $post_id ) : array();
		$meta     = array();
		if ( is_array( $raw_meta ) ) {
			ksort( $raw_meta );
			foreach ( $raw_meta as $key => $values ) {
				$decoded = array_map(
					function ( $v ) {
						return function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $v ) : $v;
					},
					(array) $values
				);
				sort( $decoded );
				$meta[ $key ] = $decoded;
			}
		}

		$terms = array();
		if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
			if ( is_array( $taxonomies ) ) {
				sort( $taxonomies );
				foreach ( $taxonomies as $taxonomy ) {
					$found = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
					$names = is_wp_error( $found ) ? array() : array_values( (array) $found );
					sort( $names );
					$terms[ $taxonomy ] = $names;
				}
			}
		}

		$acf = array();
		if ( function_exists( 'get_fields' ) ) {
			$fetched = get_fields( $post_id, false );
			if ( is_array( $fetched ) ) {
				ksort( $fetched );
				$acf = $fetched;
			}
		}

		return array(
			'core'       => $core,
			'meta'       => $meta,
			'terms'      => $terms,
			'acf'        => $acf,
			'capturedAt' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Flatten two snapshots and report only the keys whose value actually
	 * differs (dot-notation path => [before, after]) — this is what makes
	 * "click Update and see what changed" answerable without manually
	 * eyeballing two large dumps.
	 *
	 * @return array<string, array{before: mixed, after: mixed}>
	 */
	public static function diff_snapshots( $before, $after ) {
		$flat_before = array();
		$flat_after  = array();
		self::flatten( is_array( $before ) ? $before : array(), '', $flat_before );
		self::flatten( is_array( $after ) ? $after : array(), '', $flat_after );

		unset( $flat_before['capturedAt'], $flat_after['capturedAt'] );

		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $flat_before ), array_keys( $flat_after ) ) ) as $path ) {
			$before_value = $flat_before[ $path ] ?? null;
			$after_value  = $flat_after[ $path ] ?? null;
			if ( $before_value !== $after_value ) {
				$diff[ $path ] = array( 'before' => $before_value, 'after' => $after_value );
			}
		}
		ksort( $diff );
		return $diff;
	}

	private static function flatten( array $data, $prefix, array &$out ) {
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					$out[ $path ] = array();
					continue;
				}
				self::flatten( $value, $path, $out );
				continue;
			}
			$out[ $path ] = $value;
		}
	}

	public static function get_snapshots( $post_id = 0 ) {
		$all = get_option( self::OPTION_SNAPSHOTS, array() );
		$all = is_array( $all ) ? $all : array();
		if ( ! $post_id ) {
			return $all;
		}
		return $all[ (int) $post_id ] ?? array();
	}

	private static function store_snapshot( $post_id, $label, array $state ) {
		$all = get_option( self::OPTION_SNAPSHOTS, array() );
		$all = is_array( $all ) ? $all : array();
		if ( ! isset( $all[ $post_id ] ) || ! is_array( $all[ $post_id ] ) ) {
			$all[ $post_id ] = array();
		}
		$all[ $post_id ][ $label ] = $state;
		// Cap stored snapshots to keep this option small: only ever the
		// two most-recently-captured labels for a given post are kept.
		if ( count( $all[ $post_id ] ) > self::MAX_SNAPSHOTS ) {
			$all[ $post_id ] = array_slice( $all[ $post_id ], -self::MAX_SNAPSHOTS, self::MAX_SNAPSHOTS, true );
		}
		update_option( self::OPTION_SNAPSHOTS, $all, false );
	}

	private static function clear_snapshots( $post_id ) {
		$all = get_option( self::OPTION_SNAPSHOTS, array() );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ $post_id ] );
		update_option( self::OPTION_SNAPSHOTS, $all, false );
	}

	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=citex-diagnostics' ) );
		exit;
	}
}
