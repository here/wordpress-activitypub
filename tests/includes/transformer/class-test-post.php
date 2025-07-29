<?php
/**
 * Test file for Post transformer.
 *
 * @package Activitypub
 */

namespace Activitypub\Tests\Transformer;

use Activitypub\Activity\Base_Object;
use Activitypub\Transformer\Post;

/**
 * Test class for Post Transformer.
 *
 * @coversDefaultClass \Activitypub\Transformer\Post
 */
class Test_Post extends \WP_UnitTestCase {
	/**
	 * Reflection method for testing protected method.
	 *
	 * @var \ReflectionMethod
	 */
	private $reflection_method;

	/**
	 * Set up the test case.
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'activitypub_object_type', 'wordpress-post-format' );

		// Set up reflection method.
		$reflection              = new \ReflectionClass( Post::class );
		$this->reflection_method = $reflection->getMethod( 'get_type' );
		$this->reflection_method->setAccessible( true );
	}

	/**
	 * Tear down the test case.
	 */
	public function tear_down() {
		// Reset options after each test.
		delete_option( 'activitypub_object_type' );

		parent::tear_down();
	}

	/**
	 * Test that the get_type method returns the configured type when the option is set.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_configured_type_when_option_set() {
		update_option( 'activitypub_object_type', 'Article' );

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Test content that is longer than the note length limit',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Article', $type );
	}

	/**
	 * Test that the get_type method returns note for short content.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_note_for_short_content() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Short content',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Note', $type );
	}

	/**
	 * Test that the get_type method returns note for posts without title.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_note_for_posts_without_title() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => '',
				'post_content' => str_repeat( 'Long content. ', 100 ),
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Note', $type );
	}

	/**
	 * Test that the get_type method returns article for standard post format.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_article_for_standard_post_format() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'post',
			)
		);
		set_post_format( $post_id, 'standard' );
		$post = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Article', $type );
	}

	/**
	 * Test that the get_type method returns page for page post type.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_page_for_page_post_type() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Page',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'page',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Page', $type );
	}

	/**
	 * Test that the get_type method returns note for non-standard post format.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_returns_note_for_non_standard_post_format() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'post',
			)
		);
		set_post_format( $post_id, 'aside' );
		$post = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Note', $type );
	}

	/**
	 * Test that the get_type method returns note for missing post format.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_handles_missing_post_format() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'post',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Article', $type );
	}

	/**
	 * Test that the get_type method returns note for post type without title support.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_respects_post_type_title_support() {
		// Create custom post type without title support.
		register_post_type(
			'no_title_type',
			array(
				'public'   => true,
				'supports' => array( 'editor' ), // Explicitly exclude 'title'.
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'no_title_type',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Note', $type );

		// Clean up.
		unregister_post_type( 'no_title_type' );
	}

	/**
	 * Test that the get_type method returns article for custom post type with post format support.
	 *
	 * @covers ::get_type
	 */
	public function test_get_type_respects_post_format_support() {
		// Create custom post type without title support.
		register_post_type(
			'no_title_type',
			array(
				'public'   => true,
				'supports' => array( 'editor', 'title', 'post-formats' ), // Needs to include 'title'.
			)
		);

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_type'    => 'no_title_type',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$type        = $this->reflection_method->invoke( $transformer );

		$this->assertSame( 'Article', $type );

		// Clean up.
		unregister_post_type( 'no_title_type' );
	}

	/**
	 * Test the to_array method.
	 *
	 * @covers ::to_object
	 */
	public function test_to_object() {
		$post = \wp_insert_post(
			array(
				'post_author'  => 1,
				'post_content' => 'test',
			)
		);

		$permalink = \get_permalink( $post );

		$activitypub_post = Post::transform( get_post( $post ) )->to_object();

		$this->assertEquals( $permalink, $activitypub_post->get_id() );

		\wp_trash_post( $post );

		$activitypub_post = Post::transform( get_post( $post ) )->to_object();

		$this->assertEquals( $permalink, $activitypub_post->get_id() );

		$cached = \get_post_meta( $post, '_activitypub_canonical_url', true );

		$this->assertEquals( $cached, $activitypub_post->get_id() );
	}

	/**
	 * Test content visibility.
	 *
	 * @covers ::to_object
	 */
	public function test_content_visibility() {
		$post_id = \wp_insert_post(
			array(
				'post_author'  => 1,
				'post_content' => 'test content visibility',
			)
		);

		\update_post_meta( $post_id, 'activitypub_content_visibility', ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC );

		$this->assertFalse( \Activitypub\is_post_disabled( $post_id ) );
		$object = Post::transform( get_post( $post_id ) )->to_object();
		$this->assertContains( 'https://www.w3.org/ns/activitystreams#Public', $object->get_to() );

		\update_post_meta( $post_id, 'activitypub_content_visibility', ACTIVITYPUB_CONTENT_VISIBILITY_QUIET_PUBLIC );

		$this->assertFalse( \Activitypub\is_post_disabled( $post_id ) );
		$object = Post::transform( get_post( $post_id ) )->to_object();
		$this->assertContains( 'https://www.w3.org/ns/activitystreams#Public', $object->get_cc() );

		\update_post_meta( $post_id, 'activitypub_content_visibility', ACTIVITYPUB_CONTENT_VISIBILITY_LOCAL );

		$this->assertTrue( \Activitypub\is_post_disabled( $post_id ) );
		$object = Post::transform( get_post( $post_id ) )->to_object();
		$this->assertEmpty( $object->get_to() );
		$this->assertEmpty( $object->get_cc() );
	}

	/**
	 * Test different variations of Attachment parsing.
	 *
	 * @covers ::to_object
	 */
	public function test_block_attachments_with_fallback() {
		$attachment_id  = $this->create_upload_object( dirname( __DIR__, 2 ) . '/assets/test.jpg' );
		$attachment_src = \wp_get_attachment_image_src( $attachment_id );

		$post_id = \wp_insert_post(
			array(
				'post_author'  => 1,
				'post_content' => sprintf(
					'<!-- wp:image {"id": %1$d,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="%2$s" alt="" class="wp-image-%1$d"/></figure><!-- /wp:image -->',
					$attachment_id,
					$attachment_src[0]
				),
				'post_status'  => 'publish',
			)
		);

		$object = Post::transform( get_post( $post_id ) )->to_object();

		$this->assertEquals(
			array(
				array(
					'type'      => 'Image',
					'url'       => $attachment_src[0],
					'mediaType' => 'image/jpeg',
				),
			),
			$object->get_attachment()
		);

		$post_id = \wp_insert_post(
			array(
				'post_author'  => 1,
				'post_content' => sprintf(
					'<p>this is a photo</p><p><img src="%2$s" alt="" class="wp-image-%1$d"/></p>',
					$attachment_id,
					$attachment_src[0]
				),
				'post_status'  => 'publish',
			)
		);

		$object = Post::transform( get_post( $post_id ) )->to_object();

		$this->assertEquals(
			array(
				array(
					'type'      => 'Image',
					'url'       => $attachment_src[0],
					'mediaType' => 'image/jpeg',
				),
			),
			$object->get_attachment()
		);

		\wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Test get_media_from_blocks adds alt text to existing images.
	 *
	 * @covers ::get_media_from_blocks
	 */
	public function test_get_media_from_blocks_adds_alt_text_to_existing_images() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:image {"id":123} --><figure class="wp-block-image"><img src="test.jpg" alt="Test alt text" /></figure><!-- /wp:image -->',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$media       = array(
			'image' => array(
				array(
					'id'  => 123,
					'alt' => '',
				),
			),
			'audio' => array(),
			'video' => array(),
		);

		$reflection = new \ReflectionClass( Post::class );
		$method     = $reflection->getMethod( 'get_media_from_blocks' );
		$method->setAccessible( true );

		$blocks = parse_blocks( $post->post_content );
		$result = $method->invoke( $transformer, $blocks, $media );

		$this->assertSame( 'Test alt text', $result['image'][0]['alt'] );
		$this->assertSame( 123, $result['image'][0]['id'] );
	}

	/**
	 * Test get_attachments with zero max_media_attachments.
	 *
	 * @covers ::get_attachment
	 */
	public function test_get_attachments_with_zero_max_media_attachments() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:image {"id":123} --><figure class="wp-block-image"><img src="test.jpg" alt="Test alt text" /></figure><!-- /wp:image -->',
			)
		);

		\update_post_meta( $post_id, 'activitypub_max_image_attachments', 0 );
		$post = get_post( $post_id );

		$transformer = new Post( $post );

		$reflection = new \ReflectionClass( Post::class );
		$method     = $reflection->getMethod( 'get_attachment' );
		$method->setAccessible( true );

		$result = $method->invoke( $transformer );

		$this->assertEmpty( $result );
		$this->assertFalse( (bool) \did_filter( 'activitypub_attachment_ids' ) );

		\delete_post_meta( $post_id, 'activitypub_max_image_attachments' );

		$result = $method->invoke( $transformer );
		$this->assertTrue( (bool) \did_filter( 'activitypub_attachment_ids' ) );

		\wp_delete_post( $post_id );
	}

	/**
	 * Test get_media_from_blocks adds new image when none exist.
	 *
	 * @covers ::get_media_from_blocks
	 */
	public function test_get_media_from_blocks_adds_new_image() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:image {"id":123} --><figure class="wp-block-image"><img src="test.jpg" alt="Test alt text" /></figure><!-- /wp:image -->',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$media       = array(
			'image' => array(),
			'audio' => array(),
			'video' => array(),
		);

		$reflection = new \ReflectionClass( Post::class );
		$method     = $reflection->getMethod( 'get_media_from_blocks' );
		$method->setAccessible( true );

		$blocks = parse_blocks( $post->post_content );
		$result = $method->invoke( $transformer, $blocks, $media );

		$this->assertCount( 1, $result['image'] );
		$this->assertSame( 123, $result['image'][0]['id'] );
		$this->assertSame( 'Test alt text', $result['image'][0]['alt'] );
	}

	/**
	 * Test get_media_from_blocks handles multiple blocks correctly.
	 *
	 * @covers ::get_media_from_blocks
	 */
	public function test_get_media_from_blocks_handles_multiple_blocks() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:image {"id":123} --><figure class="wp-block-image"><img src="test1.jpg" alt="Test alt 1" /></figure><!-- /wp:image --><!-- wp:image {"id":456} --><figure class="wp-block-image"><img src="test2.jpg" alt="Test alt 2" /></figure><!-- /wp:image -->',
			)
		);
		$post    = get_post( $post_id );

		$transformer = new Post( $post );
		$media       = array(
			'image' => array(),
			'audio' => array(),
			'video' => array(),
		);

		$reflection = new \ReflectionClass( Post::class );
		$method     = $reflection->getMethod( 'get_media_from_blocks' );
		$method->setAccessible( true );

		$blocks = parse_blocks( $post->post_content );
		$result = $method->invoke( $transformer, $blocks, $media );

		$this->assertCount( 2, $result['image'] );
		$this->assertSame( 123, $result['image'][0]['id'] );
		$this->assertSame( 'Test alt 1', $result['image'][0]['alt'] );
		$this->assertSame( 456, $result['image'][1]['id'] );
		$this->assertSame( 'Test alt 2', $result['image'][1]['alt'] );
	}

	/**
	 * Test get_icon method.
	 *
	 * @covers ::get_icon
	 */
	public function test_get_icon() {
		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Test Post',
				'post_content' => 'Test content',
			)
		);
		$post    = get_post( $post_id );

		// Create test image.
		$attachment_id = $this->create_upload_object( dirname( __DIR__, 2 ) . '/assets/test.jpg' );

		// Set up reflection method.
		$reflection = new \ReflectionClass( Post::class );
		$method     = $reflection->getMethod( 'get_icon' );
		$method->setAccessible( true );

		// Test with featured image.
		set_post_thumbnail( $post_id, $attachment_id );

		$transformer = new Post( $post );
		$icon        = $method->invoke( $transformer );

		$this->assertIsArray( $icon );
		$this->assertEquals( 'Image', $icon['type'] );
		$this->assertArrayHasKey( 'url', $icon );
		$this->assertArrayHasKey( 'mediaType', $icon );
		$this->assertEquals( get_post_mime_type( $attachment_id ), $icon['mediaType'] );

		// Test with site icon.
		delete_post_thumbnail( $post_id );
		update_option( 'site_icon', $attachment_id );

		$icon = $method->invoke( $transformer );

		$this->assertIsArray( $icon );
		$this->assertEquals( 'Image', $icon['type'] );
		$this->assertArrayHasKey( 'url', $icon );
		$this->assertArrayHasKey( 'mediaType', $icon );
		$this->assertEquals( get_post_mime_type( $attachment_id ), $icon['mediaType'] );

		// Test with alt text.
		$alt_text = 'Test Alt Text';
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$icon = $method->invoke( $transformer );

		$this->assertIsArray( $icon );
		$this->assertEquals( 'Image', $icon['type'] );
		$this->assertArrayHasKey( 'name', $icon );
		$this->assertEquals( $alt_text, $icon['name'] );

		// Test without any images.
		delete_post_thumbnail( $post_id );
		delete_option( 'site_icon' );
		delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );

		$icon = $method->invoke( $transformer );
		$this->assertNull( $icon );

		// Test with invalid image.
		set_post_thumbnail( $post_id, 99999 );
		$icon = $method->invoke( $transformer );
		$this->assertNull( $icon );

		// Cleanup.
		wp_delete_post( $post_id, true );
		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Saves an attachment.
	 *
	 * @param string $file      The file name to create attachment object for.
	 * @param int    $parent_id ID of the post to attach the file to.
	 * @return int|\WP_Error The attachment ID on success. The value 0 or WP_Error on failure.
	 */
	public function create_upload_object( $file, $parent_id = 0 ) {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$dest = dirname( $file ) . DIRECTORY_SEPARATOR . 'test-temp.jpg';
		$fs   = new \WP_Filesystem_Direct( array() );
		$fs->copy( $file, $dest );

		$file = $dest;

		$file_array = array(
			'name'     => wp_basename( $file ),
			'tmp_name' => $file,
		);

		$upload = wp_handle_sideload( $file_array, array( 'test_form' => false ) );

		$type = '';
		if ( ! empty( $upload['type'] ) ) {
			$type = $upload['type'];
		} else {
			$mime = wp_check_filetype( $upload['file'] );
			if ( $mime ) {
				$type = $mime['type'];
			}
		}

		$attachment = array(
			'post_title'     => wp_basename( $upload['file'] ),
			'post_content'   => '',
			'post_type'      => 'attachment',
			'post_parent'    => $parent_id,
			'post_mime_type' => $type,
			'guid'           => $upload['url'],
		);

		// Save the data.
		$id = wp_insert_attachment( $attachment, $upload['file'], $parent_id );
		wp_update_attachment_metadata( $id, @wp_generate_attachment_metadata( $id, $upload['file'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $id;
	}

	/**
	 * Test preview property generation.
	 *
	 * @covers ::get_preview
	 */
	public function test_preview_property() {
		// Create a test post of type "Article".
		$post = $this->factory->post->create_and_get(
			array(
				'post_title'   => 'Test Article',
				'post_content' => str_repeat( 'Long content. ', 100 ),
				'post_status'  => 'publish',
			)
		);

		$transformer = new Post( $post );
		$preview     = $transformer->get_preview();

		// Check if the preview for an Article is correctly generated.
		$this->assertIsArray( $preview );
		$this->assertEquals( 'Note', $preview['type'] );
		$this->assertArrayHasKey( 'content', $preview );
		$this->assertNotEmpty( $preview['content'] );

		// Create a test post of type "Note" (short content).
		$note_post = $this->factory->post->create_and_get(
			array(
				'post_title'   => '',
				'post_content' => 'Short note content',
				'post_status'  => 'publish',
			)
		);

		$note_transformer = new Post( $note_post );
		$note_preview     = $note_transformer->get_preview();

		// Check if the preview for a Note is null.
		$this->assertNull( $note_preview );
	}

	/**
	 * Test reply link generation.
	 *
	 * Pleroma prepends `acct:` to the webfinger identifier, which we'd want to normalize.
	 *
	 * @covers ::generate_reply_link
	 */
	public function test_generate_reply_link() {
		\add_filter( 'activitypub_pre_http_get_remote_object', array( $this, 'filter_pleroma_object' ), 10, 2 );

		$transformer = new Post( self::factory()->post->create_and_get() );
		$reply_link  = $transformer->generate_reply_link( '', array( 'attrs' => array( 'url' => 'https://devs.live/notice/AQ8N0Xl57y8bUQAb6e' ) ) );

		$this->assertSame( '<p class="ap-reply-mention"><a rel="mention ugc" href="https://devs.live/notice/AQ8N0Xl57y8bUQAb6e" title="tester@devs.live">@tester</a></p>', $reply_link );

		\remove_filter( 'activitypub_pre_http_get_remote_object', array( $this, 'filter_pleroma_object' ) );
	}

	/**
	 * Filter pleroma object.
	 *
	 * @param array|string|null $response The response.
	 * @param array|string|null $url      The Object URL.
	 * @return string[]
	 */
	public function filter_pleroma_object( $response, $url ) {
		if ( 'https://devs.live/notice/AQ8N0Xl57y8bUQAb6e' === $url ) {
			$response = array(
				'type'         => 'Note',
				'attributedTo' => 'https://devs.live/users/tester',
				'content'      => 'Cake day it is',
			);
		}
		if ( 'https://devs.live/users/tester' === $url ) {
			$response = array(
				'id'                => 'https://devs.live/users/tester',
				'type'              => 'Person',
				'preferredUsername' => 'tester',
				'url'               => 'https://devs.live/users/tester',
				'webfinger'         => 'acct:tester@devs.live',
			);
		}

		return $response;
	}

	/**
	 * Test get_content method.
	 *
	 * @covers ::get_content
	 */
	public function test_get_content() {
		$follow_me = '<!-- wp:activitypub/follow-me -->
<div class="wp-block-activitypub-follow-me"><!-- wp:button -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Follow</a></div>
<!-- /wp:button --></div>
<!-- /wp:activitypub/follow-me -->';

		$followers = '<!-- wp:activitypub/followers -->
<div class="wp-block-activitypub-followers"><!-- wp:heading {"level":3,"placeholder":"Fediverse Followers"} -->
<h3 class="wp-block-heading">Fediverse Followers</h3>
<!-- /wp:heading --></div>
<!-- /wp:activitypub/followers -->';

		$reactions = '<!-- wp:activitypub/reactions -->
<div class="wp-block-activitypub-reactions"><!-- wp:heading {"level":3,"placeholder":"Fediverse Reactions"} -->
<h3 class="wp-block-heading">Fediverse Reactions</h3>
<!-- /wp:heading --></div>
<!-- /wp:activitypub/reactions -->';

		$post = self::factory()->post->create_and_get(
			array(
				'post_content' => implode( PHP_EOL, array( $follow_me, $followers, $reactions ) ),
				'post_title'   => '',
			)
		);

		$object      = new Base_Object();
		$get_content = new \ReflectionMethod( Post::class, 'transform_object_properties' );

		$get_content->setAccessible( true );

		$object = $get_content->invoke( new Post( $post ), $object );

		$this->assertEmpty( $object->get_content() );
	}
}
