<?php
/**
 * Test file for Scheduler class.
 *
 * @package Activitypub
 */

namespace Activitypub\Tests;

use Activitypub\Activity\Activity;
use Activitypub\Activity\Base_Object;
use Activitypub\Collection\Actors;
use Activitypub\Collection\Outbox;
use Activitypub\Dispatcher;
use Activitypub\Scheduler;

use function Activitypub\add_to_outbox;

/**
 * Test class for Scheduler.
 *
 * @coversDefaultClass \Activitypub\Scheduler
 */
class Test_Scheduler extends \WP_UnitTestCase {
	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	protected static $user_id;

	/**
	 * Create fake data before tests run.
	 *
	 * @param \WP_UnitTest_Factory $factory Helper that creates fake data.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		self::$user_id = $factory->user->create(
			array(
				'role' => 'author',
			)
		);
	}

	/**
	 * Clean up after tests.
	 */
	public static function wpTearDownAfterClass() {
		wp_delete_user( self::$user_id );
	}

	/**
	 * Test unschedule events for item.
	 *
	 * @covers ::unschedule_events_for_item
	 */
	public function test_unschedule_events_for_item() {
		// Create test activity objects.
		$activity = new Activity();
		$activity->set_type( 'Create' );
		$activity->set_id( 'https://example.com/test-id' );
		$activity->set_object(
			array(
				'id'      => 'https://example.com/test-id',
				'type'    => 'Note',
				'content' => 'Test Content',
			)
		);

		// Add pending activity.
		$create_item_id = add_to_outbox( $activity, null, self::$user_id );

		// Track scheduled events.
		$scheduled_events = array();
		\add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_events ) {
				if ( 'activitypub_retry_activity' === $event->hook ) {
					$scheduled_events[] = $event->args[1];
				}
				return $event;
			}
		);

		$schedule_retry = new \ReflectionMethod( Dispatcher::class, 'schedule_retry' );
		$schedule_retry->setAccessible( true );

		// Invoke the method.
		$schedule_retry->invoke( null, array( 'https://example.com/inbox' ), $create_item_id ); // null for static methods.

		$this->assertCount( 1, $scheduled_events, 'Should schedule 1 retry event.' );
		$this->assertContains( $create_item_id, $scheduled_events, "Activity $create_item_id should be scheduled" );

		// Track unscheduled events.
		\add_filter(
			'pre_unschedule_event',
			function ( $pre, $timestamp, $hook, $args ) use ( &$scheduled_events ) {
				if ( 'activitypub_retry_activity' === $hook ) {
					$scheduled_events = \array_diff( $scheduled_events, array( $args[1] ) );
				}
				return $pre;
			},
			10,
			4
		);

		Scheduler::unschedule_events_for_item( $create_item_id );

		$this->assertCount( 0, $scheduled_events, 'Should have no retry events.' );
		$this->assertNotContains( $create_item_id, $scheduled_events, "Activity $create_item_id should no longer be scheduled" );

		\remove_all_filters( 'schedule_event' );
		\remove_all_filters( 'pre_unschedule_event' );
	}

	/**
	 * Test reprocess_outbox method.
	 *
	 * @covers ::reprocess_outbox
	 */
	public function test_reprocess_outbox() {
		// Create test activity objects.
		$activity = new Activity();
		$activity->set_type( 'Create' );
		$activity->set_id( 'https://example.com/test-id' );
		$activity->set_object(
			array(
				'id'      => 'https://example.com/test-id',
				'type'    => 'Note',
				'content' => 'Test Content',
			)
		);

		// Add multiple pending activities.
		$pending_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$pending_ids[] = Outbox::add( $activity, self::$user_id );
		}

		$activity->set_type( 'Update' );
		$pending_ids[] = Outbox::add( $activity, self::$user_id );

		// Track scheduled events.
		$scheduled_events = array();
		add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_events ) {
				if ( 'activitypub_process_outbox' === $event->hook ) {
					$scheduled_events[] = $event->args[0];
				}
				return $event;
			}
		);

		// Run reprocess_outbox.
		Scheduler::reprocess_outbox();

		// Verify each pending activity was scheduled.
		$this->assertCount( 2, $scheduled_events, 'Should schedule 2 activities for processing' );
		$this->assertNotContains( $pending_ids[0], $scheduled_events, "Activity $pending_ids[0] should be scheduled" );
		$this->assertContains( $pending_ids[3], $scheduled_events, "Activity $pending_ids[3] should be scheduled" );

		// Test with published activities (should not be scheduled).
		$published_id = Outbox::add( $activity, self::$user_id );
		wp_update_post(
			array(
				'ID'          => $published_id,
				'post_status' => 'publish',
			)
		);

		// Reset tracked events.
		$scheduled_events = array();

		// Run reprocess_outbox again.
		Scheduler::reprocess_outbox();

		// Verify published activity was not scheduled.
		$this->assertNotContains( $published_id, $scheduled_events, 'Published activity should not be scheduled' );

		// Clean up.
		foreach ( $pending_ids as $id ) {
			wp_delete_post( $id, true );
		}
		wp_delete_post( $published_id, true );
		remove_all_filters( 'schedule_event' );
	}

	/**
	 * Test reprocess_outbox with no pending activities.
	 *
	 * @covers ::reprocess_outbox
	 */
	public function test_reprocess_outbox_no_pending() {
		$scheduled_events = array();
		add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_events ) {
				if ( 'activitypub_process_outbox' === $event->hook ) {
					$scheduled_events[] = $event->args[0];
				}
				return $event;
			}
		);

		// Run reprocess_outbox with no pending activities.
		Scheduler::reprocess_outbox();

		// Verify no events were scheduled.
		$this->assertEmpty( $scheduled_events, 'No events should be scheduled when there are no pending activities' );

		remove_all_filters( 'schedule_event' );
	}

	/**
	 * Test reprocess_outbox scheduling behavior.
	 *
	 * @covers ::reprocess_outbox
	 */
	public function test_reprocess_outbox_scheduling() {
		// Create a test activity.
		$activity = new Activity();
		$activity->set_type( 'Create' );
		$activity->set_id( 'https://example.com/test-id' );
		$activity->set_object(
			array(
				'id'      => 'https://example.com/test-id',
				'type'    => 'Note',
				'content' => 'Test Content',
			)
		);

		$pending_id = Outbox::add( $activity, self::$user_id );

		// Track scheduled events and their timing.
		$scheduled_time = 0;
		add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_time ) {
				if ( 'activitypub_process_outbox' === $event->hook ) {
					$scheduled_time = $event->timestamp;
				}
				return $event;
			}
		);

		// Run reprocess_outbox.
		Scheduler::reprocess_outbox();

		// Verify scheduling time.
		$this->assertSame( $scheduled_time, wp_next_scheduled( 'activitypub_process_outbox', array( $pending_id ) ) );

		// Clean up.
		wp_delete_post( $pending_id, true );
		remove_all_filters( 'schedule_event' );
	}

	/**
	 * Test purge_outbox method with more than 20 posts.
	 *
	 * @covers ::purge_outbox
	 */
	public function test_purge_outbox_more_than_20_posts() {
		// Create 25 posts, 5 older than 6 months.
		self::factory()->post->create_many(
			25,
			array(
				'post_type'   => Outbox::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-1 month' ) ),
				'meta_input'  => array(
					'_activitypub_activity_type' => wp_rand( 0, 1 ) ? 'Create' : 'Update',
				),
			)
		);
		self::factory()->post->create_many(
			5,
			array(
				'post_type'   => Outbox::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months' ) ),
				'meta_input'  => array(
					'_activitypub_activity_type' => wp_rand( 0, 1 ) ? 'Create' : 'Update',
				),
			)
		);
		self::factory()->post->create_many(
			5,
			array(
				'post_type'   => Outbox::POST_TYPE,
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months' ) ),
				'post_status' => 'publish',
				'meta_input'  => array(
					'_activitypub_activity_type' => 'Follow',
				),
			)
		);

		Scheduler::purge_outbox();
		wp_cache_delete( _count_posts_cache_key( Outbox::POST_TYPE ), 'counts' );

		// Assert that 5 posts were deleted, leaving 25.
		$this->assertEquals( 30, wp_count_posts( Outbox::POST_TYPE )->publish );
	}

	/**
	 * Test purge_outbox method with 20 or fewer posts.
	 *
	 * @covers ::purge_outbox
	 */
	public function test_purge_outbox_20_or_fewer_posts() {
		// Create 20 posts, all older than 6 months.
		self::factory()->post->create_many(
			20,
			array(
				'post_type'   => Outbox::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-7 months' ) ),
			)
		);

		Scheduler::purge_outbox();
		wp_cache_delete( _count_posts_cache_key( Outbox::POST_TYPE ), 'counts' );

		// Assert that no posts were deleted.
		$this->assertEquals( 20, wp_count_posts( Outbox::POST_TYPE )->publish );
	}

	/**
	 * Test purge_outbox method with changing activitypub_outbox_purge_days option.
	 *
	 * @covers ::purge_outbox
	 */
	public function test_purge_outbox_with_different_purge_days() {
		// Create posts older than initial_days.
		self::factory()->post->create_many(
			25,
			array(
				'post_type'   => Outbox::POST_TYPE,
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-4 months' ) ),
				'post_status' => 'publish',
				'meta_input'  => array(
					'_activitypub_activity_type' => wp_rand( 0, 1 ) ? 'Create' : 'Update',
				),
			)
		);

		// Run purge_outbox with initial_days.
		Scheduler::purge_outbox();
		wp_cache_delete( _count_posts_cache_key( Outbox::POST_TYPE ), 'counts' );

		// Verify posts are not deleted.
		$this->assertEquals( 25, wp_count_posts( Outbox::POST_TYPE )->publish );

		// Change the purge days option.
		update_option( 'activitypub_outbox_purge_days', 90 );

		// Run purge_outbox with changed_days.
		Scheduler::purge_outbox();
		wp_cache_delete( _count_posts_cache_key( Outbox::POST_TYPE ), 'counts' );

		// Verify posts are deleted.
		$this->assertEquals( 0, wp_count_posts( Outbox::POST_TYPE )->publish );
	}

	/**
	 * Test async_batch method.
	 *
	 * @covers ::async_batch
	 */
	public function test_async_batch_with_invalid_callback() {
		// Set up expectations for _doing_it_wrong notice.
		$this->setExpectedIncorrectUsage( 'Activitypub\Scheduler::async_batch' );

		// Create a mock callback that implements __invoke but is not in the allowed list.
		$mock_class = $this->getMockBuilder( 'stdClass' )
			->addMethods( array( 'callback' ) )
			->getMock();

		$mock_class->expects( $this->never() )
			->method( 'callback' );

		// Run async_batch without registered callback.
		Scheduler::async_batch();
	}

	/**
	 * Test schedule_announce_activity method.
	 *
	 * @covers ::schedule_announce_activity
	 */
	public function test_schedule_announce_activity() {
		// Set the actor mode to both blog and user mode.
		\update_option( 'activitypub_actor_mode', ACTIVITYPUB_ACTOR_AND_BLOG_MODE );

		$activity = new Activity();
		$activity->set_type( 'Create' );
		$activity->set_id( 'https://example.com/test-id' );

		// Create a Note object for the activity.
		$note = new Base_Object();
		$note->set_type( 'Note' );
		$note->set_content( 'Test content' );
		$note->set_id( 'https://example.com/note/1' );
		$activity->set_object( $note );

		$outbox_activity_id = Outbox::add( $activity, self::$user_id );

		$scheduled_events = array();
		add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_events ) {
				if ( 'activitypub_process_outbox' === $event->hook ) {
					$scheduled_events[] = $event->args[0];
				}
				return $event;
			}
		);

		Scheduler::schedule_announce_activity( $outbox_activity_id, $activity, self::$user_id, ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC );

		// Get the most recent outbox item for the blog actor.
		$announce_outbox_items = get_posts(
			array(
				'post_type'      => Outbox::POST_TYPE,
				'post_author'    => Actors::BLOG_USER_ID,
				'post_status'    => 'pending',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'posts_per_page' => 1,
			)
		);

		$this->assertNotEmpty( $announce_outbox_items, 'No announce outbox items found' );
		$announce_outbox_id = $announce_outbox_items[0]->ID;

		$this->assertCount( 1, $scheduled_events, 'Should schedule 1 event' );
		$this->assertContains( $announce_outbox_id, $scheduled_events, 'Should schedule the announce outbox activity' );

		// Check for Announce activity in the outbox.
		$announce_post     = get_post( $announce_outbox_id );
		$announce_activity = json_decode( $announce_post->post_content, true );
		$this->assertEquals( 'Announce', $announce_activity['type'] );

		// Clean up.
		wp_delete_post( $outbox_activity_id, true );
		wp_delete_post( $announce_outbox_id, true );
		remove_all_filters( 'schedule_event' );
	}

	/**
	 * Test cleanup_remote_actors method.
	 *
	 * @covers ::cleanup_remote_actors
	 */
	public function test_cleanup_remote_actors() {
		// Mock actor metadata.
		\add_filter(
			'activitypub_pre_http_get_remote_object',
			function () {
				return array(
					'type'              => 'Person',
					'name'              => 'Test User',
					'preferredUsername' => 'test',
					'id'                => 'https://example.com/users/test',
					'url'               => 'https://example.com/@test',
					'inbox'             => 'https://example.com/users/test/inbox',
				);
			}
		);

		$actor = Actors::fetch_remote_by_uri( 'https://example.com/users/test' );

		for ( $i = 0; $i < 6; $i++ ) {
			Actors::add_error( $actor->ID, 'Failed to fetch or parse metadata ' . $i );
		}

		// Track scheduled events.
		$scheduled_events = array();
		\add_filter(
			'schedule_event',
			function ( $event ) use ( &$scheduled_events ) {
				if ( 'activitypub_delete_actor_interactions' === $event->hook ) {
					$scheduled_events[] = array(
						'hook' => $event->hook,
						'args' => $event->args,
						'time' => $event->timestamp,
					);
				}
				return $event;
			}
		);
		\add_filter(
			'pre_get_remote_metadata_by_actor',
			function () {
				return new \WP_Error( 'no_actor', 'No actor found' );
			}
		);

		// Run the cleanup function.
		Scheduler::cleanup_remote_actors();

		// Verify that the event was scheduled with the actor URL as parameter.
		$this->assertCount( 1, $scheduled_events, 'Should schedule 1 event' );
		$this->assertEquals( 'activitypub_delete_actor_interactions', $scheduled_events[0]['hook'], 'Should schedule the correct hook' );
		$this->assertCount( 1, $scheduled_events[0]['args'], 'Should have 1 argument' );
		$this->assertEquals( 'https://example.com/users/test', $scheduled_events[0]['args'][0], 'Should pass actor URL as parameter' );

		// Verify the actor was deleted.
		$this->assertNull( \get_post( $actor->ID ), 'Actor should be deleted' );

		// Clean up.
		\remove_all_filters( 'activitypub_pre_http_get_remote_object' );
		\remove_all_filters( 'pre_get_remote_metadata_by_actor' );
		\remove_all_filters( 'schedule_event' );
	}
}
