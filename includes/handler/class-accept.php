<?php
/**
 * Accept handler file.
 *
 * @package Activitypub
 */

namespace Activitypub\Handler;

use Activitypub\Notification;
use Activitypub\Collection\Actors;
use Activitypub\Collection\Following;
use Activitypub\Collection\Outbox;

use function Activitypub\object_to_uri;

/**
 * Handle Accept requests.
 */
class Accept {
	/**
	 * Initialize the class, registering WordPress hooks.
	 */
	public static function init() {
		\add_action(
			'activitypub_inbox_accept',
			array( self::class, 'handle_accept' ),
			10,
			2
		);

		\add_filter(
			'activitypub_validate_object',
			array( self::class, 'validate_object' ),
			10,
			3
		);
	}

	/**
	 * Handles "Accept" requests.
	 *
	 * @param array $accept  The activity-object.
	 * @param int   $user_id The id of the local blog-user.
	 */
	public static function handle_accept( $accept, $user_id ) {
		// Validate that there is a Follow Activity.
		$outbox_post = Outbox::get_by_guid( $accept['object']['id'] );

		if (
			\is_wp_error( $outbox_post ) ||
			'Follow' !== \get_post_meta( $outbox_post->ID, '_activitypub_activity_type', true )
		) {
			return;
		}

		$actor_post = Actors::get_remote_by_uri( object_to_uri( $accept['object']['object'] ) );

		if ( \is_wp_error( $actor_post ) ) {
			return;
		}

		Following::accept( $actor_post, $user_id );

		// Send notification.
		$notification = new Notification(
			'accept',
			$actor_post->guid,
			$accept,
			$user_id
		);
		$notification->send();
	}

	/**
	 * Validate the object.
	 *
	 * @param bool             $valid   The validation state.
	 * @param string           $param   The object parameter.
	 * @param \WP_REST_Request $request The request object.
	 *
	 * @return bool The validation state: true if valid, false if not.
	 */
	public static function validate_object( $valid, $param, $request ) {
		$json_params = $request->get_json_params();

		if ( empty( $json_params['type'] ) ) {
			return false;
		}

		if (
			'Accept' !== $json_params['type'] ||
			\is_wp_error( $request )
		) {
			return $valid;
		}

		$required_attributes = array(
			'actor',
			'object',
		);

		if ( ! empty( \array_diff( $required_attributes, \array_keys( $json_params ) ) ) ) {
			return false;
		}

		$required_object_attributes = array(
			'id',
			'type',
			'actor',
			'object',
		);

		if ( ! empty( \array_diff( $required_object_attributes, \array_keys( $json_params['object'] ) ) ) ) {
			return false;
		}

		return $valid;
	}
}
