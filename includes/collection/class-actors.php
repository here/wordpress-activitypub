<?php
/**
 * Actors collection file.
 *
 * @package Activitypub
 */

namespace Activitypub\Collection;

use Activitypub\Http;
use Activitypub\Model\User;
use Activitypub\Model\Blog;
use Activitypub\Model\Application;
use Activitypub\Activity\Actor;

use function Activitypub\get_remote_metadata_by_actor;
use function Activitypub\object_to_uri;
use function Activitypub\normalize_url;
use function Activitypub\normalize_host;
use function Activitypub\url_to_authorid;
use function Activitypub\is_user_type_disabled;
use function Activitypub\user_can_activitypub;

/**
 * Actors collection.
 *
 * Provides methods to retrieve, create, update, and manage ActivityPub actors (users, blogs, applications, and remote actors).
 */
class Actors {
	/**
	 * The ID of the Blog User.
	 *
	 * @var int
	 */
	const BLOG_USER_ID = 0;

	/**
	 * The ID of the Application User.
	 *
	 * @var int
	 */
	const APPLICATION_USER_ID = -1;

	/**
	 * Post type for storing remote actors.
	 *
	 * @var string
	 */
	const POST_TYPE = 'ap_actor';

	/**
	 * Get the Actor by ID.
	 *
	 * @param int $user_id The user ID.
	 *
	 * @return Actor|User|Blog|Application|\WP_Error Actor object or WP_Error if not found or not permitted.
	 */
	public static function get_by_id( $user_id ) {
		if ( is_numeric( $user_id ) ) {
			$user_id = (int) $user_id;
		}

		if ( ! user_can_activitypub( $user_id ) ) {
			return new \WP_Error(
				'activitypub_user_not_found',
				\__( 'Actor not found', 'activitypub' ),
				array( 'status' => 404 )
			);
		}

		switch ( $user_id ) {
			case self::BLOG_USER_ID:
				return new Blog();
			case self::APPLICATION_USER_ID:
				return new Application();
			default:
				return User::from_wp_user( $user_id );
		}
	}

	/**
	 * Get the Actor by username.
	 *
	 * @param string $username Name of the actor.
	 *
	 * @return User|Blog|Application|\WP_Error Actor object or WP_Error if not found.
	 */
	public static function get_by_username( $username ) {
		/**
		 * Filter the username before we do anything else.
		 *
		 * @param null   $pre      The pre-existing value.
		 * @param string $username The username.
		 */
		$pre = apply_filters( 'activitypub_pre_get_by_username', null, $username );
		if ( null !== $pre ) {
			return $pre;
		}

		// Check for blog user.
		if (
			Blog::get_default_username() === $username ||
			\get_option( 'activitypub_blog_identifier' ) === $username
		) {
			if ( is_user_type_disabled( 'blog' ) ) {
				return new \WP_Error(
					'activitypub_user_not_found',
					\__( 'Actor not found', 'activitypub' ),
					array( 'status' => 404 )
				);
			}

			return new Blog();
		}

		// Check for application user.
		if ( 'application' === $username ) {
			return new Application();
		}

		// Check for 'activitypub_username' meta.
		$user = new \WP_User_Query(
			array(
				'count_total' => false,
				'number'      => 1,
				'hide_empty'  => true,
				'fields'      => 'ID',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'     => '_activitypub_user_identifier',
						'value'   => $username,
						'compare' => 'LIKE',
					),
				),
			)
		);

		if ( $user->get_results() ) {
			$actor = self::get_by_id( $user->get_results()[0] );
			if ( ! \is_wp_error( $actor ) ) {
				return $actor;
			}
		}

		$username = str_replace( array( '*', '%' ), '', $username );

		// Check for login or nicename.
		$user = new \WP_User_Query(
			array(
				'count_total'    => false,
				'search'         => $username,
				'search_columns' => array( 'user_login', 'user_nicename' ),
				'number'         => 1,
				'hide_empty'     => true,
				'fields'         => 'ID',
			)
		);

		if ( $user->get_results() ) {
			$actor = self::get_by_id( $user->get_results()[0] );
			if ( ! \is_wp_error( $actor ) ) {
				return $actor;
			}
		}

		return new \WP_Error(
			'activitypub_user_not_found',
			\__( 'Actor not found', 'activitypub' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Get the Actor by resource URI (acct, http(s), etc).
	 *
	 * @param string $uri The actor resource URI.
	 *
	 * @return User|Blog|Application|\WP_Error Actor object or WP_Error if not found.
	 */
	public static function get_by_resource( $uri ) {
		$uri = object_to_uri( $uri );

		if ( ! $uri ) {
			return new \WP_Error(
				'activitypub_no_uri',
				\__( 'No URI provided', 'activitypub' ),
				array( 'status' => 404 )
			);
		}

		$scheme = 'acct';
		$match  = array();
		// Try to extract the scheme and the host.
		if ( preg_match( '/^([a-zA-Z^:]+):(.*)$/i', $uri, $match ) ) {
			// Extract the scheme.
			$scheme = \esc_attr( $match[1] );
		}

		// @todo: handle old domain URIs here before we serve a new domain below when we shouldn't.
		// Although maybe passing through to ::get_by_username() is enough?

		switch ( $scheme ) {
			// Check for http(s) URIs.
			case 'http':
			case 'https':
				// Check locally stored remote Actor.
				$post = self::get_remote_by_uri( $uri );

				if ( ! \is_wp_error( $post ) ) {
					return self::get_actor( $post );
				}

				// Check for http(s)://blog.example.com/@username.
				$resource_path = \wp_parse_url( $uri, PHP_URL_PATH );

				if ( $resource_path ) {
					$blog_path = \wp_parse_url( \home_url(), PHP_URL_PATH );

					if ( $blog_path ) {
						$resource_path = \str_replace( $blog_path, '', $resource_path );
					}

					$resource_path = \trim( $resource_path, '/' );

					if ( str_starts_with( $resource_path, '@' ) ) {
						$identifier = \str_replace( '@', '', $resource_path );
						$identifier = \trim( $identifier, '/' );

						return self::get_by_username( $identifier );
					}
				}

				// Check for http(s)://blog.example.com/author/username.
				$user_id = url_to_authorid( $uri );

				if ( \is_int( $user_id ) ) {
					return self::get_by_id( $user_id );
				}

				// Check for http(s)://blog.example.com/.
				$normalized_uri = normalize_url( $uri );

				if (
					normalize_url( site_url() ) === $normalized_uri ||
					normalize_url( home_url() ) === $normalized_uri
				) {
					return self::get_by_id( self::BLOG_USER_ID );
				}

				return new \WP_Error(
					'activitypub_no_user_found',
					\__( 'Actor not found', 'activitypub' ),
					array( 'status' => 404 )
				);
			// Check for acct URIs.
			case 'acct':
				$uri        = \str_replace( 'acct:', '', $uri );
				$identifier = \substr( $uri, 0, \strrpos( $uri, '@' ) );
				$host       = normalize_host( \substr( \strrchr( $uri, '@' ), 1 ) );
				$blog_host  = normalize_host( \wp_parse_url( \home_url( '/' ), \PHP_URL_HOST ) );

				if ( $blog_host !== $host && get_option( 'activitypub_old_host' ) !== $host ) {
					return new \WP_Error(
						'activitypub_wrong_host',
						\__( 'Resource host does not match blog host', 'activitypub' ),
						array( 'status' => 404 )
					);
				}

				// Prepare wildcards https://github.com/mastodon/mastodon/issues/22213.
				if ( in_array( $identifier, array( '_', '*', '' ), true ) ) {
					return self::get_by_id( self::BLOG_USER_ID );
				}

				return self::get_by_username( $identifier );
			default:
				return new \WP_Error(
					'activitypub_wrong_scheme',
					\__( 'Wrong scheme', 'activitypub' ),
					array( 'status' => 404 )
				);
		}
	}

	/**
	 * Get the Actor by various identifier types (ID, URI, username, or email).
	 *
	 * @param string|int $id Actor identifier (user ID, URI, username, or email).
	 *
	 * @return User|Blog|Application|\WP_Error Actor object or WP_Error if not found.
	 */
	public static function get_by_various( $id ) {
		if ( is_numeric( $id ) ) {
			$user = self::get_by_id( $id );
		} elseif (
			// Is URL.
			filter_var( $id, FILTER_VALIDATE_URL ) ||
			// Is acct.
			str_starts_with( $id, 'acct:' ) ||
			// Is email.
			filter_var( $id, FILTER_VALIDATE_EMAIL )
		) {
			$user = self::get_by_resource( $id );
		} else {
			$user = self::get_by_username( $id );
		}

		return $user;
	}

	/**
	 * Get the collection of all local user actors.
	 *
	 * @return Actor[] Array of User actor objects.
	 */
	public static function get_collection() {
		if ( is_user_type_disabled( 'user' ) ) {
			return array();
		}

		$users = \get_users(
			array(
				'capability__in' => array( 'activitypub' ),
			)
		);

		$return = array();

		foreach ( $users as $user ) {
			$actor = User::from_wp_user( $user->ID );

			if ( \is_wp_error( $actor ) ) {
				continue;
			}

			$return[] = $actor;
		}

		return $return;
	}

	/**
	 * Get all active actors, including the Blog actor if enabled.
	 *
	 * @return array Array of User and Blog actor objects.
	 */
	public static function get_all() {
		$return = array();

		if ( ! is_user_type_disabled( 'user' ) ) {
			$users = \get_users(
				array(
					'capability__in' => array( 'activitypub' ),
				)
			);

			foreach ( $users as $user ) {
				$actor = User::from_wp_user( $user->ID );

				if ( \is_wp_error( $actor ) ) {
					continue;
				}

				$return[] = $actor;
			}
		}

		// Also include the blog actor if active.
		if ( ! is_user_type_disabled( 'blog' ) ) {
			$blog_actor = self::get_by_id( self::BLOG_USER_ID );
			if ( ! \is_wp_error( $blog_actor ) ) {
				$return[] = $blog_actor;
			}
		}

		return $return;
	}

	/**
	 * Returns the actor type based on the user ID.
	 *
	 * @param int $user_id The user ID to check.
	 *
	 * @return string Actor type: 'user', 'blog', or 'application'.
	 */
	public static function get_type_by_id( $user_id ) {
		$user_id = (int) $user_id;

		if ( self::APPLICATION_USER_ID === $user_id ) {
			return 'application';
		}

		if ( self::BLOG_USER_ID === $user_id ) {
			return 'blog';
		}

		return 'user';
	}

	/**
	 * Upsert (insert or update) a remote actor as a custom post type.
	 *
	 * @param array|Actor $actor ActivityPub actor object (array or actor, must include 'id').
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function upsert( $actor ) {
		if ( \is_array( $actor ) ) {
			$actor = Actor::init_from_array( $actor );
		}

		$post = self::get_remote_by_uri( $actor->get_id() );

		if ( ! \is_wp_error( $post ) ) {
			return self::update( $post, $actor );
		}

		return self::create( $actor );
	}

	/**
	 * Create a remote actor as a custom post type.
	 *
	 * @param array|Actor $actor ActivityPub actor object (array or Actor, must include 'id').
	 *
	 * @return int|\WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create( $actor ) {
		if ( \is_array( $actor ) ) {
			$actor = Actor::init_from_array( $actor );
		}

		$args = self::prepare_custom_post_type( $actor );

		if ( \is_wp_error( $args ) ) {
			return $args;
		}

		$has_kses = false !== \has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $has_kses ) {
			// Prevent KSES from corrupting JSON in post_content.
			\kses_remove_filters();
		}

		$post_id = \wp_insert_post( $args );

		if ( $has_kses ) {
			// Restore KSES filters.
			\kses_init_filters();
		}

		return $post_id;
	}

	/**
	 * Update a remote Actor object by actor URL (guid).
	 *
	 * @param int|\WP_Post $post  The post ID or object.
	 * @param array|Actor  $actor The ActivityPub actor object as associative array (must include 'id').
	 *
	 * @return int|\WP_Error The post ID or WP_Error.
	 */
	public static function update( $post, $actor ) {
		if ( \is_array( $actor ) ) {
			$actor = Actor::init_from_array( $actor );
		}

		$post = \get_post( $post, ARRAY_A );

		if ( ! $post ) {
			return new \WP_Error(
				'activitypub_actor_not_found',
				\__( 'Actor not found', 'activitypub' ),
				array( 'status' => 404 )
			);
		}

		$args = self::prepare_custom_post_type( $actor );

		if ( \is_wp_error( $args ) ) {
			return $args;
		}

		$args = \wp_parse_args( $args, $post );

		$has_kses = false !== \has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $has_kses ) {
			// Prevent KSES from corrupting JSON in post_content.
			\kses_remove_filters();
		}

		$post_id = \wp_update_post( $args );

		if ( $has_kses ) {
			// Restore KSES filters.
			\kses_init_filters();
		}

		return $post_id;
	}

	/**
	 * Delete a remote actor object by actor URL (guid).
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $post_id ) {
		return \wp_delete_post( $post_id );
	}

	/**
	 * Get a remote actor post by actor URI (guid).
	 *
	 * @param string $actor_uri The actor URI.
	 *
	 * @return \WP_Post|\WP_Error Post object or WP_Error if not found.
	 */
	public static function get_remote_by_uri( $actor_uri ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE guid=%s AND post_type=%s",
				esc_sql( $actor_uri ),
				esc_sql( self::POST_TYPE )
			)
		);

		if ( ! $post_id ) {
			return new \WP_Error(
				'activitypub_actor_not_found',
				\__( 'Actor not found', 'activitypub' ),
				array( 'status' => 404 )
			);
		}

		return \get_post( $post_id );
	}

	/**
	 * Lookup a remote actor post by actor URI (guid), fetching from remote if not found locally.
	 *
	 * @param string $actor_uri The actor URI.
	 *
	 * @return \WP_Post|\WP_Error Post object or WP_Error if not found.
	 */
	public static function fetch_remote_by_uri( $actor_uri ) {
		$post = self::get_remote_by_uri( $actor_uri );

		if ( ! \is_wp_error( $post ) ) {
			return $post;
		}

		$object = Http::get_remote_object( $actor_uri, false );

		if ( \is_wp_error( $object ) ) {
			return $object;
		}

		$post_id = self::upsert( $object );

		if ( \is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return \get_post( $post_id );
	}

	/**
	 * Store an error that occurred when sending an ActivityPub message to a follower.
	 *
	 * The error will be stored in post meta.
	 *
	 * @param int              $post_id The ID of the WordPress Custom-Post-Type.
	 * @param string|\WP_Error $error   The error message.
	 *
	 * @return int|false The meta ID on success, false on failure.
	 */
	public static function add_error( $post_id, $error ) {
		if ( \is_string( $error ) ) {
			$error_message = $error;
		} elseif ( \is_wp_error( $error ) ) {
			$error_message = $error->get_error_message();
		} else {
			$error_message = \__(
				'Unknown Error or misconfigured Error-Message',
				'activitypub'
			);
		}

		return \add_post_meta(
			$post_id,
			'_activitypub_errors',
			$error_message
		);
	}

	/**
	 * Count the errors for an actor.
	 *
	 * @param int $post_id The ID of the WordPress Custom-Post-Type.
	 *
	 * @return int The number of errors.
	 */
	public static function count_errors( $post_id ) {
		return \count( \get_post_meta( $post_id, '_activitypub_errors', false ) );
	}

	/**
	 * Get all error messages for an actor.
	 *
	 * @param int $post_id The post ID.
	 *
	 * @return string[] Array of error messages.
	 */
	public static function get_errors( $post_id ) {
		return \get_post_meta( $post_id, '_activitypub_errors', false );
	}

	/**
	 * Clear all errors for an actor.
	 *
	 * @param int $post_id The ID of the WordPress Custom-Post-Type.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function clear_errors( $post_id ) {
		return \delete_post_meta( $post_id, '_activitypub_errors' );
	}

	/**
	 * Get all remote actors (Custom Post Type) that had errors.
	 *
	 * @param int $number Optional. Number of actors to return. Default 20.
	 *
	 * @return \WP_Post[] Array of faulty actor posts.
	 */
	public static function get_faulty( $number = 20 ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $number,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_activitypub_errors',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_activitypub_inbox',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_activitypub_inbox',
					'value'   => '',
					'compare' => '=',
				),
			),
		);

		return ( new \WP_Query() )->query( $args );
	}

	/**
	 * Get all remote actor posts not updated for a given time.
	 *
	 * @param int $number     Optional. Limits the result. Default 50.
	 * @param int $older_than Optional. The time in seconds. Default DAY_IN_SECONDS.
	 *
	 * @return \WP_Post[] The list of actors.
	 */
	public static function get_outdated( $number = 50, $older_than = DAY_IN_SECONDS ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => $number,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'post_status'    => 'any', // 'any' includes 'trash'.
			'date_query'     => array(
				array(
					'column' => 'post_modified_gmt',
					'before' => \gmdate( 'Y-m-d', \time() - $older_than ),
				),
			),
		);

		return ( new \WP_Query() )->query( $args );
	}

	/**
	 * Convert a custom post type input to an Activitypub\Activity\Actor.
	 *
	 * @param int|\WP_Post $post The post ID or object.
	 *
	 * @return Actor|\WP_Error The actor object or WP_Error on failure.
	 */
	public static function get_actor( $post ) {
		$post = \get_post( $post );

		if ( ! $post ) {
			return new \WP_Error(
				'activitypub_actor_not_found',
				\__( 'Actor not found', 'activitypub' ),
				array( 'status' => 404 )
			);
		}

		$json = $post->post_content;

		if ( empty( $json ) ) {
			$json = \get_post_meta( $post->ID, '_activitypub_actor_json', true );
		}

		$actor = Actor::init_from_json( $json );

		if ( \is_wp_error( $actor ) ) {
			self::add_error( $post->ID, $actor );
		}

		return $actor;
	}

	/**
	 * Prepare actor object for insert or update as a custom post type.
	 *
	 * @param Actor $actor The actor data.
	 *
	 * @return array|\WP_Error Array of post arguments or WP_Error on failure.
	 */
	private static function prepare_custom_post_type( $actor ) {
		if ( ! $actor instanceof Actor ) {
			return new \WP_Error(
				'activitypub_invalid_actor_data',
				\__( 'Invalid actor data', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		if ( ! empty( $actor->get_endpoints()['sharedInbox'] ) ) {
			$inbox = $actor->get_endpoints()['sharedInbox'];
		} elseif ( ! empty( $actor->get_inbox() ) ) {
			$inbox = $actor->get_inbox();
		} else {
			return new \WP_Error(
				'activitypub_invalid_actor_data',
				\__( 'Invalid actor data', 'activitypub' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'guid'         => \esc_url_raw( $actor->get_id() ),
			'post_title'   => \wp_strip_all_tags( \wp_slash( $actor->get_name() ?? $actor->get_preferred_username() ) ),
			'post_author'  => 0,
			'post_type'    => self::POST_TYPE,
			'post_content' => \wp_slash( $actor->to_json() ),
			'post_excerpt' => \wp_kses( \wp_slash( $actor->get_summary() ), 'user_description' ),
			'post_status'  => 'publish',
			'meta_input'   => array(
				'_activitypub_inbox' => $inbox,
			),
		);
	}

	/**
	 * Return the public key for a given actor.
	 *
	 * @param int  $user_id The WordPress User ID.
	 * @param bool $force   Optional. Force the generation of a new key pair. Default false.
	 *
	 * @return string The public key.
	 */
	public static function get_public_key( $user_id, $force = false ) {
		if ( $force ) {
			self::generate_key_pair( $user_id );
		}

		$key_pair = self::get_keypair( $user_id );

		return $key_pair['public_key'];
	}

	/**
	 * Return the private key for a given actor.
	 *
	 * @param int  $user_id The WordPress User ID.
	 * @param bool $force   Optional. Force the generation of a new key pair. Default false.
	 *
	 * @return string The private key.
	 */
	public static function get_private_key( $user_id, $force = false ) {
		if ( $force ) {
			self::generate_key_pair( $user_id );
		}

		$key_pair = self::get_keypair( $user_id );

		return $key_pair['private_key'];
	}

	/**
	 * Return the key pair for a given actor.
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return array The key pair.
	 */
	public static function get_keypair( $user_id ) {
		$option_key = self::get_signature_options_key( $user_id );
		$key_pair   = \get_option( $option_key );

		if ( ! $key_pair ) {
			$key_pair = self::generate_key_pair( $user_id );
		}

		return $key_pair;
	}

	/**
	 * Get public key from key_id.
	 *
	 * @param string $key_id The URL to the public key.
	 *
	 * @return resource|\WP_Error The public key resource or WP_Error.
	 */
	public static function get_remote_key( $key_id ) {
		$actor = get_remote_metadata_by_actor( strip_fragment_from_url( $key_id ) );
		if ( \is_wp_error( $actor ) ) {
			return new \WP_Error( 'activitypub_no_remote_profile_found', 'No Profile found or Profile not accessible', array( 'status' => 401 ) );
		}

		if ( isset( $actor['publicKey']['publicKeyPem'] ) ) {
			$key_resource = \openssl_pkey_get_public( \rtrim( $actor['publicKey']['publicKeyPem'] ) );
			if ( $key_resource ) {
				return $key_resource;
			}
		}

		return new \WP_Error( 'activitypub_no_remote_key_found', 'No Public-Key found', array( 'status' => 401 ) );
	}

	/**
	 * Generates the pair of keys.
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return array The key pair.
	 */
	protected static function generate_key_pair( $user_id ) {
		$option_key = self::get_signature_options_key( $user_id );
		$key_pair   = self::check_legacy_key_pair( $user_id );

		if ( $key_pair ) {
			\add_option( $option_key, $key_pair );

			return $key_pair;
		}

		$config = array(
			'digest_alg'       => 'sha512',
			'private_key_bits' => 2048,
			'private_key_type' => \OPENSSL_KEYTYPE_RSA,
		);

		$key         = \openssl_pkey_new( $config );
		$private_key = null;
		$detail      = array();
		if ( $key ) {
			\openssl_pkey_export( $key, $private_key );

			$detail = \openssl_pkey_get_details( $key );
		}

		// Check if keys are valid.
		if (
			empty( $private_key ) || ! is_string( $private_key ) ||
			! isset( $detail['key'] ) || ! is_string( $detail['key'] )
		) {
			return array(
				'private_key' => null,
				'public_key'  => null,
			);
		}

		$key_pair = array(
			'private_key' => $private_key,
			'public_key'  => $detail['key'],
		);

		// Persist keys.
		\add_option( $option_key, $key_pair );

		return $key_pair;
	}

	/**
	 * Return the option key for a given user.
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return string The option key.
	 */
	protected static function get_signature_options_key( $user_id ) {
		if ( $user_id > 0 ) {
			$user = \get_userdata( $user_id );
			// Sanitize username because it could include spaces and special chars.
			$user_id = \sanitize_title( $user->user_login );
		}

		return 'activitypub_keypair_for_' . $user_id;
	}

	/**
	 * Check if there is a legacy key pair
	 *
	 * @param int $user_id The WordPress User ID.
	 *
	 * @return array|bool The key pair or false.
	 */
	protected static function check_legacy_key_pair( $user_id ) {
		switch ( $user_id ) {
			case 0:
				$public_key  = \get_option( 'activitypub_blog_user_public_key' );
				$private_key = \get_option( 'activitypub_blog_user_private_key' );
				break;
			case -1:
				$public_key  = \get_option( 'activitypub_application_user_public_key' );
				$private_key = \get_option( 'activitypub_application_user_private_key' );
				break;
			default:
				$public_key  = \get_user_meta( $user_id, 'magic_sig_public_key', true );
				$private_key = \get_user_meta( $user_id, 'magic_sig_private_key', true );
				break;
		}

		if ( ! empty( $public_key ) && is_string( $public_key ) && ! empty( $private_key ) && is_string( $private_key ) ) {
			return array(
				'private_key' => $private_key,
				'public_key'  => $public_key,
			);
		}

		return false;
	}
}
