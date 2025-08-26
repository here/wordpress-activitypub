<?php
/**
 * Blocks file.
 *
 * @package Activitypub
 */

namespace Activitypub;

use Activitypub\Collection\Actors;

/**
 * Block class.
 */
class Blocks {
	/**
	 * Initialize the class, registering WordPress hooks.
	 */
	public static function init() {
		// This is already being called on the init hook, so just add it.
		self::register_blocks();

		\add_action( 'load-post-new.php', array( self::class, 'handle_in_reply_to_get_param' ) );
		// Add editor plugin.
		\add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
		\add_action( 'init', array( self::class, 'register_postmeta' ), 11 );
		\add_action( 'rest_api_init', array( self::class, 'register_rest_fields' ) );

		\add_filter( 'activitypub_import_mastodon_post_data', array( self::class, 'filter_import_mastodon_post_data' ), 10, 2 );
	}

	/**
	 * Register post meta for content warnings.
	 */
	public static function register_postmeta() {
		$ap_post_types = \get_post_types_by_support( 'activitypub' );
		foreach ( $ap_post_types as $post_type ) {
			\register_post_meta(
				$post_type,
				'activitypub_content_warning',
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			\register_post_meta(
				$post_type,
				'activitypub_content_visibility',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => function ( $value ) {
						$schema = array(
							'type'    => 'string',
							'enum'    => array( ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC, ACTIVITYPUB_CONTENT_VISIBILITY_QUIET_PUBLIC, ACTIVITYPUB_CONTENT_VISIBILITY_PRIVATE, ACTIVITYPUB_CONTENT_VISIBILITY_LOCAL ),
							'default' => ACTIVITYPUB_CONTENT_VISIBILITY_PUBLIC,
						);

						if ( is_wp_error( rest_validate_enum( $value, $schema, '' ) ) ) {
							return $schema['default'];
						}

						return $value;
					},
				)
			);

			\register_post_meta(
				$post_type,
				'activitypub_max_image_attachments',
				array(
					'type'              => 'integer',
					'single'            => true,
					'show_in_rest'      => true,
					'default'           => \get_option( 'activitypub_max_image_attachments', ACTIVITYPUB_MAX_IMAGE_ATTACHMENTS ),
					'sanitize_callback' => 'absint',
				)
			);
		}
	}

	/**
	 * Enqueue the block editor assets.
	 */
	public static function enqueue_editor_assets() {
		$data = array(
			'namespace'        => ACTIVITYPUB_REST_NAMESPACE,
			'defaultAvatarUrl' => ACTIVITYPUB_PLUGIN_URL . 'assets/img/mp.jpg',
			'enabled'          => array(
				'blog'  => ! is_user_type_disabled( 'blog' ),
				'users' => ! is_user_type_disabled( 'user' ),
			),
		);
		wp_localize_script( 'wp-editor', '_activityPubOptions', $data );

		// Check for our supported post types.
		$current_screen = \get_current_screen();
		$ap_post_types  = \get_post_types_by_support( 'activitypub' );
		if ( ! $current_screen || ! in_array( $current_screen->post_type, $ap_post_types, true ) ) {
			return;
		}

		$asset_data = include ACTIVITYPUB_PLUGIN_DIR . 'build/editor-plugin/plugin.asset.php';
		$plugin_url = plugins_url( 'build/editor-plugin/plugin.js', ACTIVITYPUB_PLUGIN_FILE );
		wp_enqueue_script( 'activitypub-block-editor', $plugin_url, $asset_data['dependencies'], $asset_data['version'], true );
	}

	/**
	 * Enqueue the reply handle script if the in_reply_to GET param is set.
	 */
	public static function handle_in_reply_to_get_param() {
		// Only load the script if the in_reply_to GET param is set, action happens there, not here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['in_reply_to'] ) ) {
			return;
		}

		$asset_data = include ACTIVITYPUB_PLUGIN_DIR . 'build/reply-intent/plugin.asset.php';
		$plugin_url = plugins_url( 'build/reply-intent/plugin.js', ACTIVITYPUB_PLUGIN_FILE );
		wp_enqueue_script( 'activitypub-reply-intent', $plugin_url, $asset_data['dependencies'], $asset_data['version'], true );
	}

	/**
	 * Register the blocks.
	 */
	public static function register_blocks() {
		\register_block_type_from_metadata( ACTIVITYPUB_PLUGIN_DIR . '/build/follow-me' );
		\register_block_type_from_metadata( ACTIVITYPUB_PLUGIN_DIR . '/build/followers' );
		\register_block_type_from_metadata( ACTIVITYPUB_PLUGIN_DIR . '/build/reactions' );

		\register_block_type_from_metadata(
			ACTIVITYPUB_PLUGIN_DIR . '/build/reply',
			array(
				'render_callback' => array( self::class, 'render_reply_block' ),
			)
		);
	}

	/**
	 * Register REST fields needed for blocks.
	 */
	public static function register_rest_fields() {
		// Register the post_count field for Follow Me block.
		register_rest_field(
			'user',
			'post_count',
			array(
				/**
				 * Get the number of published posts.
				 *
				 * @param array            $response   Prepared response array.
				 * @param string           $field_name The field name.
				 * @param \WP_REST_Request $request    The request object.
				 * @return int The number of published posts.
				 */
				'get_callback' => function ( $response, $field_name, $request ) {
					return (int) count_user_posts( $request->get_param( 'id' ), 'post', true );
				},
				'schema'       => array(
					'description' => 'Number of published posts',
					'type'        => 'integer',
					'context'     => array( 'activitypub' ),
				),
			)
		);
	}

	/**
	 * Get the user ID from a user string.
	 *
	 * @param string $user_string The user string. Can be a user ID, 'blog', or 'inherit'.
	 * @return int|null The user ID, or null if the 'inherit' string is not supported in this context.
	 */
	public static function get_user_id( $user_string ) {
		if ( is_numeric( $user_string ) ) {
			return absint( $user_string );
		}

		// If the user string is 'blog', return the Blog User ID.
		if ( 'blog' === $user_string ) {
			return Actors::BLOG_USER_ID;
		}

		// The only other value should be 'inherit', which means to use the query context to determine the User.
		if ( 'inherit' !== $user_string ) {
			return null;
		}

		// For a homepage/front page, if the Blog User is active, use it.
		if ( ( is_front_page() || is_home() ) && ! is_user_type_disabled( 'blog' ) ) {
			return Actors::BLOG_USER_ID;
		}

		// If we're in a loop, use the post author.
		$author_id = get_the_author_meta( 'ID' );
		if ( $author_id ) {
			return $author_id;
		}

		// For other pages, the queried object will clue us in.
		$queried_object = get_queried_object();
		if ( ! $queried_object ) {
			return null;
		}

		// If we're on a user archive page, use that user's ID.
		if ( is_a( $queried_object, 'WP_User' ) ) {
			return $queried_object->ID;
		}

		// For a single post, use the post author's ID.
		if ( is_a( $queried_object, 'WP_Post' ) ) {
			return get_the_author_meta( 'ID' );
		}

		// We won't properly account for some conditions, like tag archives.
		return null;
	}

	/**
	 * Render the reply block.
	 *
	 * @param array $attrs The block attributes.
	 *
	 * @return string The HTML to render.
	 */
	public static function render_reply_block( $attrs ) {
		if ( is_activitypub_request() ) {
			return null;
		}

		// Return early if no URL is provided.
		if ( empty( $attrs['url'] ) ) {
			return null;
		}

		$show_embed = isset( $attrs['embedPost'] ) && $attrs['embedPost'];

		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'aria-label'       => __( 'Reply', 'activitypub' ),
				'class'            => 'activitypub-reply-block',
				'data-in-reply-to' => $attrs['url'],
			)
		);

		$html = '<div ' . $wrapper_attrs . '>';

		// Try to get and append the embed if requested.
		if ( $show_embed ) {
			$embed = wp_oembed_get( $attrs['url'] );
			if ( $embed ) {
				$html .= $embed;
			}
		}

		// Only show the link if we're not showing the embed.
		if ( ! $show_embed ) {
			$html .= sprintf(
				'<p><a title="%2$s" aria-label="%2$s" href="%1$s" class="u-in-reply-to" target="_blank">%3$s</a></p>',
				esc_url( $attrs['url'] ),
				esc_attr__( 'This post is a response to the referenced content.', 'activitypub' ),
				// translators: %s is the URL of the post being replied to.
				sprintf( __( '&#8620;%s', 'activitypub' ), \str_replace( array( 'https://', 'http://' ), '', esc_url( $attrs['url'] ) ) )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Renders a modal component that can be used by different blocks.
	 *
	 * @param array $args Arguments for the modal.
	 */
	public static function render_modal( $args = array() ) {
		$defaults = array(
			'content'    => '',
			'id'         => '',
			'is_compact' => false,
			'title'      => '',
		);

		$args = \wp_parse_args( $args, $defaults );
		?>

		<div
			class="activitypub-modal__overlay<?php echo \esc_attr( $args['is_compact'] ? ' compact' : '' ); ?>"
			data-wp-bind--hidden="!context.modal.isOpen"
			data-wp-watch="callbacks.handleModalEffects"
			role="dialog"
			aria-modal="true"
			hidden
		>
			<div class="activitypub-modal__frame">
				<?php if ( ! $args['is_compact'] || ! empty( $args['title'] ) ) : ?>
					<div class="activitypub-modal__header">
						<h2 
							class="activitypub-modal__title"
							<?php if ( ! empty( $args['id'] ) ) : ?>
								id="<?php echo \esc_attr( $args['id'] . '-title' ); ?>"
							<?php endif; ?>
						><?php echo \esc_html( $args['title'] ); ?></h2>
						<button
							type="button"
							class="activitypub-modal__close wp-element-button wp-block-button__link"
							data-wp-on--click="actions.closeModal"
							aria-label="<?php echo \esc_attr__( 'Close dialog', 'activitypub' ); ?>"
						>
							<svg fill="currentColor" width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
								<path d="M13 11.8l6.1-6.3-1-1-6.1 6.2-6.1-6.2-1 1 6.1 6.3-6.5 6.7 1 1 6.5-6.6 6.5 6.6 1-1z"></path>
							</svg>
						</button>
					</div>
				<?php endif; ?>
				<div class="activitypub-modal__content">
					<?php echo $args['content']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Converts content to blocks before saving to the database.
	 *
	 * @param array  $data The post data to be inserted.
	 * @param object $post The Mastodon Create activity.
	 *
	 * @return array
	 */
	public static function filter_import_mastodon_post_data( $data, $post ) {
		// Convert paragraphs to blocks.
		\preg_match_all( '#<p>.*?</p>#is', $data['post_content'], $matches );
		$blocks = \array_map(
			function ( $paragraph ) {
				return '<!-- wp:paragraph -->' . PHP_EOL . $paragraph . PHP_EOL . '<!-- /wp:paragraph -->' . PHP_EOL;
			},
			$matches[0] ?? array()
		);

		$data['post_content'] = \rtrim( \implode( PHP_EOL, $blocks ), PHP_EOL );

		// Add reply block if it's a reply.
		if ( null !== $post->object->inReplyTo ) {
			$reply_block          = \sprintf( '<!-- wp:activitypub/reply {"url":"%1$s","embedPost":true} /-->' . PHP_EOL, \esc_url( $post->object->inReplyTo ) );
			$data['post_content'] = $reply_block . $data['post_content'];
		}

		return $data;
	}

	/**
	 * Add Interactivity directions to the specified element.
	 *
	 * @param string   $content    The block content.
	 * @param string[] $selector   The selector for the element to add directions to.
	 * @param string[] $attributes The attributes to add to the element.
	 *
	 * @return string The updated content.
	 */
	public static function add_directions( $content, $selector, $attributes ) {
		$tags = new \WP_HTML_Tag_Processor( $content );

		while ( $tags->next_tag( $selector ) ) {
			foreach ( $attributes as $key => $value ) {
				if ( 'class' === $key ) {
					$tags->add_class( $value );
					continue;
				}

				$tags->set_attribute( $key, $value );
			}
		}

		return $tags->get_updated_html();
	}
}
