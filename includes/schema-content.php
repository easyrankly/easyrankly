<?php
/** Schema.org content detection from post content and supported plugins. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'erankly_faq_items', 'erankly_faq_items_from_content', 10, 2 );

/**
 * Populates FAQ items from Gutenberg blocks when no filter has provided them.
 *
 * @return array<int,array<string,string>>
 */
function erankly_faq_items_from_content( array $items, int $post_id ): array {
	if ( ! empty( $items ) || $post_id <= 0 ) {
		return $items;
	}

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return $items;
	}

	return erankly_extract_faq_items_from_content( $post->post_content );
}

/** @return array<int,array<string,string>> */
function erankly_extract_faq_items_from_content( string $content ): array {
	$items = erankly_extract_faq_items_from_blocks( $content );

	if ( ! empty( $items ) ) {
		return $items;
	}

	return erankly_extract_faq_items_from_html( $content );
}

/** @return array<int,array<string,string>> */
function erankly_extract_faq_items_from_blocks( string $content ): array {
	if ( ! function_exists( 'parse_blocks' ) ) {
		return array();
	}

	$items  = array();
	$blocks = erankly_find_blocks_by_names(
		parse_blocks( $content ),
		array(
			'yoast/faq-block',
			'rank-math/faq-block',
		)
	);

	foreach ( $blocks as $block ) {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$rows  = array();

		if ( isset( $attrs['questions'] ) && is_array( $attrs['questions'] ) ) {
			$rows = $attrs['questions'];
		} elseif ( isset( $attrs['faqs'] ) && is_array( $attrs['faqs'] ) ) {
			$rows = $attrs['faqs'];
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = erankly_schema_plain_text_from_field(
				$row,
				array( 'question', 'jsonQuestion', 'title', 'name' )
			);
			$answer   = erankly_schema_plain_text_from_field(
				$row,
				array( 'answer', 'jsonAnswer', 'content', 'text' )
			);

			if ( '' !== $question && '' !== $answer ) {
				$items[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}
	}

	return array_merge( $items, erankly_extract_faq_items_from_accordion_blocks( $content ) );
}

/** @return array<int,array<string,string>> */
function erankly_extract_faq_items_from_accordion_blocks( string $content ): array {
	if ( ! function_exists( 'parse_blocks' ) ) {
		return array();
	}

	$items      = array();
	$accordions = erankly_find_blocks_by_names(
		parse_blocks( $content ),
		array( 'core/accordion' )
	);

	foreach ( $accordions as $accordion ) {
		$attrs = isset( $accordion['attrs'] ) && is_array( $accordion['attrs'] ) ? $accordion['attrs'] : array();

		if ( empty( $attrs['eranklyGenerateFaqSchema'] ) ) {
			continue;
		}

		$item_blocks = erankly_find_blocks_by_names(
			isset( $accordion['innerBlocks'] ) && is_array( $accordion['innerBlocks'] ) ? $accordion['innerBlocks'] : array(),
			array( 'core/accordion-item' )
		);

		foreach ( $item_blocks as $item_block ) {
			$question = erankly_extract_accordion_item_question( $item_block );
			$answer   = erankly_extract_accordion_item_answer( $item_block );

			if ( '' !== $question && '' !== $answer ) {
				$items[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}
	}

	return $items;
}

function erankly_extract_accordion_item_question( array $item_block ): string {
	$inner_blocks = isset( $item_block['innerBlocks'] ) && is_array( $item_block['innerBlocks'] )
		? $item_block['innerBlocks']
		: array();
	$headings     = erankly_find_blocks_by_names( $inner_blocks, array( 'core/accordion-heading' ) );

	if ( empty( $headings ) ) {
		return '';
	}

	$heading = $headings[0];
	$attrs   = isset( $heading['attrs'] ) && is_array( $heading['attrs'] ) ? $heading['attrs'] : array();

	if ( isset( $attrs['title'] ) && is_string( $attrs['title'] ) ) {
		$text = erankly_schema_plain_text_from_html( $attrs['title'] );

		if ( '' !== $text ) {
			return $text;
		}
	}

	if ( isset( $heading['innerHTML'] ) && is_string( $heading['innerHTML'] ) ) {
		if ( preg_match(
			'#class="[^"]*wp-block-accordion-heading__toggle-title[^"]*"[^>]*>(.*?)</span>#is',
			$heading['innerHTML'],
			$match
		) ) {
			return erankly_schema_plain_text_from_html( (string) $match[1] );
		}
	}

	return '';
}

function erankly_extract_accordion_item_answer( array $item_block ): string {
	$inner_blocks = isset( $item_block['innerBlocks'] ) && is_array( $item_block['innerBlocks'] )
		? $item_block['innerBlocks']
		: array();
	$panels       = erankly_find_blocks_by_names( $inner_blocks, array( 'core/accordion-panel' ) );

	if ( empty( $panels ) ) {
		return '';
	}

	$panel = $panels[0];

	$panel_inner = isset( $panel['innerBlocks'] ) && is_array( $panel['innerBlocks'] ) ? $panel['innerBlocks'] : array();

	return erankly_schema_plain_text_from_inner_blocks( $panel_inner );
}

function erankly_schema_plain_text_from_inner_blocks( array $blocks ): string {
	$parts = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

		foreach ( array( 'content', 'text', 'title', 'caption' ) as $key ) {
			if ( ! isset( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) {
				continue;
			}

			$text = erankly_schema_plain_text_from_html( $attrs[ $key ] );

			if ( '' !== $text ) {
				$parts[] = $text;
				continue 2;
			}
		}

		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$text = erankly_schema_plain_text_from_html( $block['innerHTML'] );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$nested = erankly_schema_plain_text_from_inner_blocks( $block['innerBlocks'] );

			if ( '' !== $nested ) {
				$parts[] = $nested;
			}
		}
	}

	return trim( implode( ' ', $parts ) );
}

/** @return array<int,array<string,string>> */
function erankly_extract_faq_items_from_html( string $content ): array {
	$items = array();

	preg_match_all(
		'#<strong[^>]*class="[^"]*schema-faq-question[^"]*"[^>]*>(.*?)</strong>\s*<p[^>]*class="[^"]*schema-faq-answer[^"]*"[^>]*>(.*?)</p>#is',
		$content,
		$matches,
		PREG_SET_ORDER
	);

	foreach ( $matches as $match ) {
		$question = erankly_schema_plain_text_from_html( (string) $match[1] );
		$answer   = erankly_schema_plain_text_from_html( (string) $match[2] );

		if ( '' !== $question && '' !== $answer ) {
			$items[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}
	}

	return $items;
}

/** @return array<string,mixed> */
function erankly_schema_howto( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$schema = erankly_schema_howto_from_blocks( $post->post_content, $post_id );

	if ( ! empty( $schema ) ) {

		return apply_filters( 'erankly_schema_howto', $schema, $post_id );
	}

	$schema = erankly_schema_howto_from_html( $post->post_content, $post_id );

	return apply_filters( 'erankly_schema_howto', $schema, $post_id );
}

/** @return array<string,mixed> */
function erankly_schema_howto_from_blocks( string $content, int $post_id ): array {
	if ( ! function_exists( 'parse_blocks' ) ) {
		return array();
	}

	$blocks = erankly_find_blocks_by_names(
		parse_blocks( $content ),
		array(
			'yoast/how-to-block',
			'rank-math/howto-block',
			'rank-math/howto',
		)
	);

	if ( empty( $blocks ) ) {
		return array();
	}

	$block  = $blocks[0];
	$attrs  = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$steps  = array();
	$rows   = isset( $attrs['steps'] ) && is_array( $attrs['steps'] ) ? $attrs['steps'] : array();
	$step_n = 1;

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$name = erankly_schema_plain_text_from_field( $row, array( 'name', 'jsonName', 'title' ) );
		$text = erankly_schema_plain_text_from_field( $row, array( 'text', 'jsonText', 'description' ) );

		if ( is_array( $row['text'] ?? null ) ) {
			$text = erankly_schema_plain_text_from_field(
				array( 'text' => implode( ' ', array_map( 'strval', $row['text'] ) ) ),
				array( 'text' )
			);
		}

		if ( '' === $name && '' === $text ) {
			continue;
		}

		$step = array(
			'@type'    => 'HowToStep',
			'position' => $step_n,
		);

		if ( '' !== $name ) {
			$step['name'] = $name;
		}

		if ( '' !== $text ) {
			$step['text'] = $text;
		}

		$steps[] = $step;
		++$step_n;
	}

	if ( empty( $steps ) ) {
		return array();
	}

	$canonical = erankly_get_canonical();
	$schema    = array(
		'@type'            => 'HowTo',
		'@id'              => $canonical . '#howto',
		'name'             => get_the_title( $post_id ),
		'step'             => $steps,
		'mainEntityOfPage' => array(
			'@id' => $canonical . '#webpage',
		),
	);

	$description = erankly_schema_plain_text_from_field( $attrs, array( 'description', 'jsonDescription' ) );

	if ( '' === $description ) {
		$description = erankly_get_description();
	}

	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	$total_time = erankly_schema_howto_total_time( $attrs );

	if ( '' !== $total_time ) {
		$schema['totalTime'] = $total_time;
	}

	return array_filter( $schema );
}

/** @return array<string,mixed> */
function erankly_schema_howto_from_html( string $content, int $post_id ): array {
	$steps = array();

	preg_match_all(
		'#<strong[^>]*class="[^"]*schema-how-to-step-name[^"]*"[^>]*>(.*?)</strong>\s*<p[^>]*class="[^"]*schema-how-to-step-text[^"]*"[^>]*>(.*?)</p>#is',
		$content,
		$matches,
		PREG_SET_ORDER
	);

	$step_n = 1;

	foreach ( $matches as $match ) {
		$name = erankly_schema_plain_text_from_html( (string) $match[1] );
		$text = erankly_schema_plain_text_from_html( (string) $match[2] );

		if ( '' === $name && '' === $text ) {
			continue;
		}

		$step = array(
			'@type'    => 'HowToStep',
			'position' => $step_n,
		);

		if ( '' !== $name ) {
			$step['name'] = $name;
		}

		if ( '' !== $text ) {
			$step['text'] = $text;
		}

		$steps[] = $step;
		++$step_n;
	}

	if ( empty( $steps ) ) {
		return array();
	}

	$canonical = erankly_get_canonical();
	$schema    = array(
		'@type'            => 'HowTo',
		'@id'              => $canonical . '#howto',
		'name'             => get_the_title( $post_id ),
		'step'             => $steps,
		'mainEntityOfPage' => array(
			'@id' => $canonical . '#webpage',
		),
	);

	if ( preg_match( '#<p[^>]*class="[^"]*schema-how-to-description[^"]*"[^>]*>(.*?)</p>#is', $content, $description_match ) ) {
		$description = erankly_schema_plain_text_from_html( (string) $description_match[1] );

		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
	}

	return array_filter( $schema );
}

function erankly_schema_howto_total_time( array $attrs ): string {
	if ( empty( $attrs['hasDuration'] ) ) {
		return '';
	}

	$days    = max( 0, (int) ( $attrs['days'] ?? 0 ) );
	$hours   = max( 0, (int) ( $attrs['hours'] ?? 0 ) );
	$minutes = max( 0, (int) ( $attrs['minutes'] ?? 0 ) );

	if ( 0 === $days && 0 === $hours && 0 === $minutes ) {
		return '';
	}

	return 'P' . ( $days > 0 ? $days . 'D' : '' ) . 'T' . $hours . 'H' . $minutes . 'M';
}

/** @return array<string,mixed> */
function erankly_schema_event( int $post_id ): array {
	$post_type = get_post_type( $post_id );

	if ( ! is_string( $post_type ) || ! erankly_is_event_post_type( $post_type ) ) {
		return array();
	}

	$schema = 'tribe_events' === $post_type
		? erankly_schema_event_from_tec( $post_id )
		: erankly_schema_event_generic( $post_id, $post_type );

	if ( empty( $schema ) ) {
		return array();
	}

	return apply_filters( 'erankly_schema_event', array_filter( $schema ), $post_id );
}

/** @return array<int,string> */
function erankly_get_event_post_types(): array {

	$post_types = apply_filters( 'erankly_event_post_types', array( 'tribe_events', 'event', 'events' ) );

	return is_array( $post_types ) ? array_values( array_filter( array_map( 'strval', $post_types ) ) ) : array();
}

function erankly_is_event_post_type( string $post_type ): bool {
	return in_array( $post_type, erankly_get_event_post_types(), true );
}

/** @return array<string,mixed> */
function erankly_schema_event_from_tec( int $post_id ): array {
	$start = (string) get_post_meta( $post_id, '_EventStartDate', true );
	$end   = (string) get_post_meta( $post_id, '_EventEndDate', true );

	if ( '' === $start ) {
		return array();
	}

	$permalink = (string) get_permalink( $post_id );
	$schema    = array(
		'@type'               => 'Event',
		'@id'                 => $permalink . '#event',
		'name'                => get_the_title( $post_id ),
		'startDate'           => erankly_schema_event_datetime( $start ),
		'url'                 => $permalink,
		'mainEntityOfPage'    => array(
			'@id' => erankly_get_canonical() . '#webpage',
		),
		'organizer'           => array(
			'@id' => erankly_schema_identity_id(),
		),
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'eventStatus'         => 'https://schema.org/EventScheduled',
	);

	if ( '' !== $end ) {
		$schema['endDate'] = erankly_schema_event_datetime( $end );
	}

	$description = erankly_get_description();

	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	$image = erankly_get_og_image();

	if ( '' !== $image ) {
		$schema['image'] = $image;
	}

	$event_url = (string) get_post_meta( $post_id, '_EventURL', true );

	if ( '' !== $event_url ) {
		$schema['sameAs'] = esc_url_raw( $event_url );
	}

	$location = erankly_schema_event_location_from_tec( $post_id );

	if ( ! empty( $location ) ) {
		$schema['location'] = $location;
	}

	return $schema;
}

/** @return array<string,mixed> */
function erankly_schema_event_generic( int $post_id, string $post_type ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Keeps the event-builder signature consistent across adapters.
	$start_keys = array( '_EventStartDate', '_event_start', 'event_start_date', 'event_start', 'start_date' );
	$end_keys   = array( '_EventEndDate', '_event_end', 'event_end_date', 'event_end', 'end_date' );
	$start      = erankly_schema_event_meta_value( $post_id, $start_keys );
	$end        = erankly_schema_event_meta_value( $post_id, $end_keys );

	if ( '' === $start ) {
		return array();
	}

	$permalink = (string) get_permalink( $post_id );
	$schema    = array(
		'@type'            => 'Event',
		'@id'              => $permalink . '#event',
		'name'             => get_the_title( $post_id ),
		'startDate'        => erankly_schema_event_datetime( $start ),
		'url'              => $permalink,
		'mainEntityOfPage' => array(
			'@id' => erankly_get_canonical() . '#webpage',
		),
		'organizer'        => array(
			'@id' => erankly_schema_identity_id(),
		),
	);

	if ( '' !== $end ) {
		$schema['endDate'] = erankly_schema_event_datetime( $end );
	}

	$description = erankly_get_description();

	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	$image = erankly_get_og_image();

	if ( '' !== $image ) {
		$schema['image'] = $image;
	}

	return $schema;
}

/** @param array<int,string> $keys    Meta keys to try. */
function erankly_schema_event_meta_value( int $post_id, array $keys ): string {
	foreach ( $keys as $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return trim( $value );
		}
	}

	return '';
}

/** Normalises an event datetime string to ISO 8601 when possible. */
function erankly_schema_event_datetime( string $value ): string {
	$value = trim( $value );

	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value );

	return false !== $timestamp ? gmdate( DATE_W3C, $timestamp ) : $value;
}

/** @return array<string,mixed> */
function erankly_schema_event_location_from_tec( int $post_id ): array {
	$venue_id = absint( get_post_meta( $post_id, '_EventVenueID', true ) );

	if ( $venue_id <= 0 ) {
		return array();
	}

	$name = get_the_title( $venue_id );
	$addr = array_filter(
		array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => trim( (string) get_post_meta( $venue_id, '_VenueAddress', true ) ),
			'addressLocality' => trim( (string) get_post_meta( $venue_id, '_VenueCity', true ) ),
			'addressRegion'   => trim( (string) get_post_meta( $venue_id, '_VenueState', true ) ),
			'postalCode'      => trim( (string) get_post_meta( $venue_id, '_VenueZip', true ) ),
			'addressCountry'  => trim( (string) get_post_meta( $venue_id, '_VenueCountry', true ) ),
		),
		static fn( $value ): bool => is_string( $value ) && '' !== $value
	);

	$location = array(
		'@type' => 'Place',
		'name'  => is_string( $name ) ? $name : '',
	);

	if ( count( $addr ) > 1 ) {
		$location['address'] = $addr;
	}

	return array_filter( $location );
}

/**
 * Returns VideoObject schema nodes for embedded videos in a post. Uses the same extraction logic as the video
 * sitemap.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_video_objects( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$video_markers  = array( 'youtube.com/', 'youtu.be/', 'vimeo.com/', '<video', 'wp:video' );
	$video_haystack = strtolower( $post->post_content );
	$has_video      = array_reduce(
		$video_markers,
		static fn( bool $found, string $marker ): bool => $found || str_contains( $video_haystack, $marker ),
		false
	);

	if ( ! $has_video ) {
		return array();
	}

	erankly_load_video_helpers();
	$video_urls = erankly_extract_video_urls( $post->post_content );

	if ( empty( $video_urls ) ) {
		return array();
	}

	$canonical   = erankly_get_canonical();
	$title       = get_the_title( $post_id );
	$description = erankly_trim_text( wp_strip_all_tags( get_the_excerpt( $post_id ) ), 500 );
	$upload_date = get_the_date( DATE_W3C, $post_id );
	$objects     = array();

	foreach ( $video_urls as $index => $video_url ) {
		$embed_url     = erankly_get_video_embed_url( $video_url );
		$content_url   = erankly_get_video_content_url( $video_url );
		$thumbnail_url = erankly_get_video_thumbnail_url( $post_id, $video_url );

		if ( ( '' === $embed_url && '' === $content_url ) || '' === $thumbnail_url ) {
			continue;
		}

		$object = array(
			'@type'            => 'VideoObject',
			'@id'              => $canonical . '#video-' . substr( md5( $video_url ), 0, 8 ),
			'name'             => is_string( $title ) ? $title : '',
			'thumbnailUrl'     => $thumbnail_url,
			'uploadDate'       => is_string( $upload_date ) ? $upload_date : '',
			'mainEntityOfPage' => array(
				'@id' => $canonical . '#webpage',
			),
		);

		if ( '' !== $description ) {
			$object['description'] = $description;
		}

		if ( '' !== $embed_url ) {
			$object['embedUrl'] = $embed_url;
		}

		if ( '' !== $content_url ) {
			$object['contentUrl'] = esc_url_raw( $content_url );
		}

		/**
 * Filters an individual VideoObject schema node. Return an empty array to exclude the video.
 *
 * @param int                 $index     Zero-based index on the page.
 */
		$object = apply_filters( 'erankly_schema_video_object', $object, $post_id, $video_url, (int) $index );

		if ( ! empty( $object ) ) {
			$objects[] = array_filter( $object );
		}
	}

	$objects = apply_filters( 'erankly_schema_video_objects', $objects, $post_id );

	return is_array( $objects ) ? array_values( array_filter( $objects ) ) : array();
}

/** @return array<string,mixed> */
function erankly_schema_service_for_page( int $post_id ): array {
	/** Filters Service schema arguments for the current page. Return a non-empty array to emit Service schema via erankly_schema_service(). */
	$args = apply_filters( 'erankly_schema_service_args', array(), $post_id );

	if ( empty( $args ) || ! is_array( $args ) ) {
		return array();
	}

	return erankly_schema_service( $args );
}

/**
 * @param array<int,string>              $names  Block names to match.
 * @return array<int,array<string,mixed>>
 */
function erankly_find_blocks_by_names( array $blocks, array $names ): array {
	$found = array();

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( '' !== $block_name && in_array( $block_name, $names, true ) ) {
			$found[] = $block;
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			$found = array_merge( $found, erankly_find_blocks_by_names( $block['innerBlocks'], $names ) );
		}
	}

	return $found;
}

function erankly_schema_plain_text_from_field( array $row, array $keys ): string {
	foreach ( $keys as $key ) {
		if ( ! isset( $row[ $key ] ) ) {
			continue;
		}

		$value = $row[ $key ];

		if ( is_string( $value ) ) {
			$text = erankly_schema_plain_text_from_html( $value );

			if ( '' !== $text ) {
				return $text;
			}
		}
	}

	return '';
}

function erankly_schema_plain_text_from_html( string $html ): string {
	return trim( wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}
