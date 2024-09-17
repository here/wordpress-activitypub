<?php

namespace Activitypub\Integration;

use Activitypub\Transformer\Post;

use function Activitypub\generate_post_summary;

// Based on integration/class-seriously-simple-podcasting.php

/**
 * Compatibility with the Seriously Simple Podcasting plugin.
 *
 * This is a transformer for adding location details to ActivityPub,
 * that extends the default transformer for WordPress posts.
 *
 * @see https://herebox.org/text/activitypub-location-sharing/
 */
class Arrivals extends Post {

    /*

Integrations
 - Autoloader integration/load.php

Factory class for transformer includes/transformer/class-factory.php
Base class for transformer includes/transformer/class-base.php
Post class for transformer includes/transformer/class-post.php
...
Add a new class for transformer extending Post 
 - Similar to integration for ssp integration/class-seriously-simple-podcasting.php

includes/activity/class-base-object.php
- base set and get functions for the Activity object
includes/activity/class-activity.php
- Activity object

 ...

Renders the outgoing json - templates/post-json.php
*/

	/**
	 * Gets the object type for a podcast episode.
	 *
	 * Always returns 'Note' for the best possible compatibility with ActivityPub.
	 *
	 * @return string The object type.
	 */
	public function get_type() {
		return 'Note';
	}

	protected function get_location() {
		return [
            "type" => "Place",
            "name" => "WordCamp US",
            "longitude" => "-122.663228",
            "latitude" => "45.528283",
            "country" => "USA"
        ];
	}

}