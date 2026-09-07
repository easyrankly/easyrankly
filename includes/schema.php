<?php
/** Schema.org JSON-LD graph. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/schema-content.php';

if ( ! function_exists( 'erankly_should_emit_breadcrumb_schema' ) ) {
	require_once ERANKLY_PATH . 'includes/breadcrumbs.php';
}

function erankly_render_schema(): void {
	$graph = erankly_get_schema_graph();

	if ( empty( $graph ) ) {
		return;
	}

	$schema = array(
		'@context' => 'https://schema.org',
		'@graph'   => array_values( $graph ),
	);

	// The JSON_HEX flags prevent values from closing the script element while
	// preserving valid JSON-LD for search engine parsers.
	echo '<script type="application/ld+json">';
	echo wp_json_encode(
		$schema,
		JSON_UNESCAPED_SLASHES
		| JSON_UNESCAPED_UNICODE
		| JSON_HEX_TAG
		| JSON_HEX_AMP
		| JSON_HEX_APOS
		| JSON_HEX_QUOT
	);
	echo '</script>' . "\n";
	erankly_render_schema_merge_warning_comment();
}

/** @return array<int,array<string,mixed>> */
function erankly_get_schema_graph(): array {
	erankly_clear_schema_merge_warnings();

	$post_id     = is_singular() ? get_queried_object_id() : 0;
	$schema_mode = $post_id > 0 ? erankly_get_post_meta_string( $post_id, 'schema_mode' ) : 'default';
	$schema_mode = in_array( $schema_mode, array( 'default', 'merge', 'replace', 'disabled' ), true ) ? $schema_mode : 'default';

	if ( 'disabled' === $schema_mode ) {
		return array();
	}

	// Automatic graph is skipped in "custom only" so FAQ/HowTo/video parsing is
	// not wasted. Global blocks are also excluded there: "only" means per-post.
	$automatic = 'replace' === $schema_mode ? array() : erankly_automatic_schema_graph( $post_id );
	$global    = 'replace' === $schema_mode ? array() : erankly_get_global_schema_graph();
	$custom    = ( $post_id > 0 && in_array( $schema_mode, array( 'merge', 'replace' ), true ) )
		? erankly_post_custom_schema_graph( $post_id )
		: array();

	if ( $post_id > 0 ) {
		// Suppression covers generated output for this page — automatic graph
		// and site-wide blocks — but never the JSON-LD authored on this post.
		$disabled_types = get_post_meta( $post_id, '_erankly_schema_disabled_types', true );
		$disabled_types = is_array( $disabled_types ) ? $disabled_types : array();
		$automatic      = erankly_filter_schema_graph_types( $automatic, $disabled_types );
		$global         = erankly_filter_schema_graph_types( $global, $disabled_types );
	}

	// A suppressed node leaves "@id" pointers behind. Only generated nodes are
	// pruned: authored JSON-LD may point at an entity defined elsewhere.
	$known_ids = erankly_collect_schema_node_ids( array_merge( $automatic, $custom, $global ) );
	$automatic = erankly_prune_dangling_schema_references( $automatic, $known_ids );

	// Merge order encodes precedence: automatic < global < per-post custom.
	$graph = apply_filters( 'erankly_schema', array_filter( array_merge( $automatic, $global, $custom ) ) );
	$graph = is_array( $graph ) ? erankly_dedupe_schema_graph( $graph ) : array();

	if ( function_exists( 'erankly_maybe_log_schema_merge_warnings' ) ) {
		erankly_maybe_log_schema_merge_warnings();
	}

	$warnings = function_exists( 'erankly_get_schema_merge_warnings' ) ? erankly_get_schema_merge_warnings() : array();

	if ( array() !== $warnings ) {
		do_action( 'erankly_schema_merge_warnings', $warnings );
	}

	return $graph;
}

/**
 * Builds the graph EasyRankly derives from the request on its own: the site identity, the page node, the
 * content nodes detected on singular content, and the breadcrumb trail.
 *
 * @param int $post_id Queried post ID, or 0 outside singular content.
 * @return array<int,array<string,mixed>>
 */
function erankly_automatic_schema_graph( int $post_id ): array {
	if ( is_404() ) {
		return array();
	}

	$graph         = erankly_schema_foundational_graph();
	$breadcrumbs   = function_exists( 'erankly_schema_breadcrumb_list' ) && erankly_should_emit_breadcrumb_schema()
		? erankly_schema_breadcrumb_list()
		: array();
	$breadcrumb_id = ! empty( $breadcrumbs ) && isset( $breadcrumbs['@id'] ) ? (string) $breadcrumbs['@id'] : '';

	if ( ! is_singular() ) {
		$graph[] = erankly_schema_webpage( 0, $breadcrumb_id );

		if ( ! empty( $breadcrumbs ) ) {
			$graph[] = $breadcrumbs;
		}

		return $graph;
	}

	$graph[] = erankly_schema_webpage( $post_id, $breadcrumb_id );

	$product      = erankly_get_woocommerce_product_data( $post_id );
	$article_type = erankly_get_post_type_schema_type( (string) get_post_type( $post_id ), 'article_type' );

	if ( ! empty( $product ) ) {
		$graph[] = $product;
	} elseif ( '' !== $article_type && 'none' !== strtolower( $article_type ) ) {
		$graph[] = erankly_schema_article( $post_id );
	}

	$faq = erankly_schema_faq( $post_id );

	if ( ! empty( $faq ) ) {
		$graph[] = $faq;
	}

	$local_business = erankly_schema_local_business_for_page( $post_id );

	if ( ! empty( $local_business ) ) {
		// LocalBusiness references the Organization via parentOrganization. Ensure that
		// node exists even when the primary identity is a Person (duplicates are removed
		// later by erankly_dedupe_schema_graph()).
		if ( 'person' === erankly_get_schema_identity() ) {
			$graph[] = erankly_schema_organization();
		}

		$graph[] = $local_business;
	}

	$howto = erankly_schema_howto( $post_id );

	if ( ! empty( $howto ) ) {
		$graph[] = $howto;
	}

	$event = erankly_schema_event( $post_id );

	if ( ! empty( $event ) ) {
		$graph[] = $event;
	}

	$service = erankly_schema_service_for_page( $post_id );

	if ( ! empty( $service ) ) {
		$graph[] = $service;
	}

	foreach ( erankly_schema_video_objects( $post_id ) as $video_object ) {
		$graph[] = $video_object;
	}

	if ( ! empty( $breadcrumbs ) ) {
		$graph[] = $breadcrumbs;
	}

	return $graph;
}

/**
 * Returns the JSON-LD blocks authored on one post.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_post_custom_schema_graph( int $post_id ): array {
	$blocks = get_post_meta( $post_id, '_erankly_schema_blocks', true );
	$graph  = array();

	foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}

		foreach ( erankly_schema_from_configured_block( $block, $post_id ) as $schema ) {
			if ( ! empty( $schema ) ) {
				$graph[] = $schema;
			}
		}
	}

	return $graph;
}

/**
 * Collects every "@id" a graph defines, including the ones on nested nodes.
 *
 * @param array<int,mixed> $graph Schema graph nodes.
 * @return array<string,true>
 */
function erankly_collect_schema_node_ids( array $graph ): array {
	$ids = array();

	foreach ( $graph as $node ) {
		if ( ! is_array( $node ) ) {
			continue;
		}

		// A node that carries an "@id" alongside other properties defines that
		// entity; an array holding nothing but "@id" is a reference to one.
		if ( isset( $node['@id'] ) && is_string( $node['@id'] ) && count( $node ) > 1 ) {
			$ids[ trim( $node['@id'] ) ] = true;
		}

		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$ids += erankly_collect_schema_node_ids( array( $value ) );
			}
		}
	}

	return $ids;
}

/**
 * Removes "@id" references pointing at nodes that are not part of the graph.
 *
 * @param array<int,array<string,mixed>> $graph      Nodes to clean.
 * @param array<string,true>             $known_ids  Every "@id" the final graph defines.
 * @return array<int,array<string,mixed>>
 */
function erankly_prune_dangling_schema_references( array $graph, array $known_ids ): array {
	foreach ( $graph as $index => $node ) {
		if ( is_array( $node ) ) {
			$graph[ $index ] = erankly_prune_dangling_schema_references_in_node( $node, $known_ids );
		}
	}

	return array_values( array_filter( $graph ) );
}

/**
 * @param array<string,mixed> $node      Single schema node.
 * @param array<string,true>  $known_ids Every "@id" the final graph defines.
 * @return array<string,mixed>
 */
function erankly_prune_dangling_schema_references_in_node( array $node, array $known_ids ): array {
	foreach ( $node as $key => $value ) {
		if ( '@id' === $key || ! is_array( $value ) ) {
			continue;
		}

		if ( erankly_is_dangling_schema_reference( $value, $known_ids ) ) {
			unset( $node[ $key ] );
			continue;
		}

		$node[ $key ] = erankly_prune_dangling_schema_references_in_node( $value, $known_ids );

		if ( array() === $node[ $key ] ) {
			unset( $node[ $key ] );
		}
	}

	return $node;
}

/**
 * @param array<mixed>       $value     Candidate reference.
 * @param array<string,true> $known_ids Every "@id" the final graph defines.
 */
function erankly_is_dangling_schema_reference( array $value, array $known_ids ): bool {
	return array( '@id' ) === array_keys( $value )
		&& is_string( $value['@id'] )
		&& ! isset( $known_ids[ trim( $value['@id'] ) ] );
}

/** @return array<int,array<string,mixed>> */
function erankly_filter_schema_graph_types( array $graph, array $disabled_types ): array {
	$disabled = array_map( 'strtolower', erankly_sanitize_schema_type_list( $disabled_types ) );

	if ( empty( $disabled ) ) {
		return $graph;
	}

	return array_values(
		array_filter(
			$graph,
			static function ( array $node ) use ( $disabled ): bool {
				$types = isset( $node['@type'] ) && is_array( $node['@type'] ) ? $node['@type'] : array( $node['@type'] ?? '' );
				$types = array_map( static fn( mixed $type ): string => strtolower( (string) $type ), $types );

				return empty( array_intersect( $disabled, $types ) );
			}
		)
	);
}

/**
 * Returns the base WebSite and identity graph nodes.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_schema_foundational_graph(): array {
	$identity = erankly_get_schema_identity();

	return array(
		'person' === $identity ? erankly_schema_person() : erankly_schema_organization(),
		erankly_schema_website(),
	);
}

/**
 * Removes duplicate schema graph nodes in the same JSON-LD graph.
 *
 * Nodes that share an "@id" are merged rather than first-wins discarded: @type
 * values are unioned, compatible objects are merged recursively, arrays are
 * concatenated and deduplicated, and later layers win scalar conflicts.
 * Identical nodes without "@id" are still collapsed by content hash.
 *
 * Precedence when this runs after array_merge( automatic, global, custom ):
 * per-post custom overlays global custom, which overlays automatic nodes.
 *
 * @return array<int,array<string,mixed>>
 */
function erankly_dedupe_schema_graph( array $graph ): array {
	$by_id  = array();
	$no_id  = array();
	$order  = array();

	foreach ( $graph as $schema ) {
		if ( ! is_array( $schema ) || empty( $schema ) ) {
			continue;
		}

		$id = isset( $schema['@id'] ) && is_string( $schema['@id'] ) ? trim( $schema['@id'] ) : '';

		if ( '' === $id ) {
			$key = 'hash:' . md5( (string) wp_json_encode( $schema ) );

			if ( isset( $no_id[ $key ] ) ) {
				continue;
			}

			$no_id[ $key ] = $schema;
			$order[]       = array( 'hash', $key );
			continue;
		}

		if ( ! isset( $by_id[ $id ] ) ) {
			$by_id[ $id ] = $schema;
			$order[]      = array( 'id', $id );
			continue;
		}

		$by_id[ $id ] = erankly_merge_schema_nodes( $by_id[ $id ], $schema );
	}

	$unique = array();

	foreach ( $order as $item ) {
		$unique[] = 'id' === $item[0] ? $by_id[ $item[1] ] : $no_id[ $item[1] ];
	}

	return $unique;
}

/**
 * The identity the stored settings can actually describe.
 *
 * A Person needs a WordPress user behind it. With Identity set to Person and no user selected, the graph used
 * to emit a Person node built from the organization name and the site home URL: a Person that is not a person,
 * handed to Google as if it were one. Falling back to Organization keeps every @id reference in the graph
 * resolvable instead of leaving a dangling publisher.
 */
function erankly_get_schema_identity(): string {
	if ( 'person' !== (string) erankly_get_setting( 'schema_identity', 'organization' ) ) {
		return 'organization';
	}

	return absint( erankly_get_setting( 'schema_person_user_id', 0 ) ) > 0 ? 'person' : 'organization';
}

function erankly_schema_identity_id(): string {
	$type = erankly_get_schema_identity();

	return home_url( 'person' === $type ? '/#person' : '/#organization' );
}

/** @return array<string,mixed> */
function erankly_schema_organization(): array {
	$details = erankly_get_organization_schema_details();
	$schema  = array(
		'@type' => 'Organization',
		'@id'   => home_url( '/#organization' ),
		'name'  => erankly_get_organization_name(),
		'url'   => home_url( '/' ),
	);

	foreach ( array( 'description', 'email', 'telephone', 'legalName', 'vatID', 'taxID' ) as $property ) {
		if ( ! empty( $details[ $property ] ) ) {
			$schema[ $property ] = $details[ $property ];
		}
	}

	$address = erankly_schema_organization_address();

	if ( ! empty( $address ) ) {
		$schema['address'] = $address;
	}

	$logo = erankly_schema_organization_logo();

	if ( ! empty( $logo ) ) {
		$schema['logo']  = $logo;
		$schema['image'] = array(
			'@id' => $logo['@id'],
		);
	}

	$same_as = erankly_get_social_profiles();

	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	return apply_filters( 'erankly_schema_organization', erankly_filter_empty_schema_values( $schema ) );
}

/**
 * Returns extended Organization details.
 *
 * @return array<string,string>
 */
function erankly_get_organization_schema_details(): array {
	$details = array(
		'description' => trim( (string) erankly_get_setting( 'organization_description', '' ) ),
		'email'       => sanitize_email( (string) erankly_get_setting( 'organization_email', '' ) ),
		'telephone'   => erankly_sanitize_phone( erankly_get_setting( 'organization_phone', '' ) ),
		'legalName'   => trim( (string) erankly_get_setting( 'organization_legal_name', '' ) ),
		'vatID'       => trim( (string) erankly_get_setting( 'organization_vat_id', '' ) ),
		'taxID'       => trim( (string) erankly_get_setting( 'organization_tax_id', '' ) ),
	);

	/** Filters extended Organization details before schema output. */
	$details = apply_filters( 'erankly_organization_schema_details', $details );

	return is_array( $details ) ? array_filter( $details, static fn( mixed $value ): bool => is_string( $value ) && '' !== trim( $value ) ) : array();
}

/**
 * @param bool $require_complete Whether LocalBusiness-required fields must exist.
 * @return array<string,string>
 */
function erankly_schema_organization_address( bool $require_complete = false ): array {
	$address = array(
		'@type'           => 'PostalAddress',
		'streetAddress'   => trim( (string) erankly_get_setting( 'organization_street_address', '' ) ),
		'addressLocality' => trim( (string) erankly_get_setting( 'organization_locality', '' ) ),
		'addressRegion'   => trim( (string) erankly_get_setting( 'organization_region', '' ) ),
		'postalCode'      => trim( (string) erankly_get_setting( 'organization_postal_code', '' ) ),
		'addressCountry'  => erankly_sanitize_country_code( erankly_get_setting( 'organization_country', '' ) ),
	);

	if (
		$require_complete &&
		(
			'' === $address['streetAddress'] ||
			'' === $address['addressLocality'] ||
			'' === $address['postalCode'] ||
			'' === $address['addressCountry']
		)
	) {
		return array();
	}

	$address = array_filter(
		$address,
		static fn( string $value ): bool => '' !== $value
	);

	return count( $address ) > 1 ? $address : array();
}

/** @return array<string,mixed> */
function erankly_schema_person(): array {
	$user_id = absint( erankly_get_setting( 'schema_person_user_id', 0 ) );
	$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

	if ( $user instanceof WP_User ) {
		$name = trim( (string) $user->display_name );

		if ( '' === $name ) {
			$name = erankly_get_organization_name();
		}

		if ( '' === trim( $name ) ) {
			$name = get_bloginfo( 'name' );
		}

		$author_url  = get_author_posts_url( $user->ID );
		$user_url    = esc_url_raw( (string) $user->user_url );
		$description = get_user_meta( $user->ID, 'description', true );
		$avatar      = get_avatar_url( $user->ID, array( 'size' => 512 ) );
		$schema      = array(
			'@type' => 'Person',
			'@id'   => home_url( '/#person' ),
			'name'  => $name,
			'url'   => $author_url,
		);

		if ( is_string( $description ) && '' !== trim( $description ) ) {
			$schema['description'] = erankly_trim_text( $description, 500 );
		}

		if ( is_string( $avatar ) && '' !== $avatar ) {
			$schema['image'] = esc_url_raw( $avatar );
		}

		if ( '' !== $user_url && esc_url_raw( $author_url ) !== $user_url ) {
			$schema['sameAs'] = array( $user_url );
		}

		return apply_filters( 'erankly_schema_person', array_filter( $schema ) );
	}

	$schema = array(
		'@type' => 'Person',
		'@id'   => home_url( '/#person' ),
		'name'  => erankly_get_organization_name(),
		'url'   => home_url( '/' ),
	);

	$same_as = erankly_get_social_profiles();

	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	return apply_filters( 'erankly_schema_person', $schema );
}

/** @return array<string,mixed> */
function erankly_schema_website(): array {
	$schema = array(
		'@type'     => 'WebSite',
		'@id'       => home_url( '/#website' ),
		'url'       => home_url( '/' ),
		'name'      => erankly_get_website_name(),
		'publisher' => array(
			'@id' => erankly_schema_identity_id(),
		),
	);

	if ( erankly_get_setting( 'enable_website_search_action', 0 ) ) {
		$schema['potentialAction'] = erankly_schema_website_search_action();
	}

	$description = erankly_get_website_description();

	if ( '' !== $description ) {
		$schema['description'] = $description;
	}

	return apply_filters( 'erankly_schema_website', erankly_filter_empty_schema_values( $schema ) );
}

/** @return array<string,mixed> */
function erankly_schema_website_search_action(): array {
	return array(
		'@type'       => 'SearchAction',
		'target'      => array(
			'@type'       => 'EntryPoint',
			'urlTemplate' => home_url( '/?s={search_term_string}' ),
		),
		'query-input' => 'required name=search_term_string',
	);
}

/**
 * @param string $breadcrumb_id Optional BreadcrumbList @id to link via the breadcrumb property.
 * @return array<string,mixed>
 */
function erankly_schema_webpage( int $post_id = 0, string $breadcrumb_id = '' ): array {
	$canonical = erankly_get_canonical();
	$type      = ( 0 === $post_id && ( is_archive() || is_search() ) ) ? 'CollectionPage' : 'WebPage';
	if ( $post_id > 0 ) {
		$configured_type = erankly_get_post_type_schema_type( (string) get_post_type( $post_id ), 'webpage_type' );
		if ( 'none' === strtolower( $configured_type ) ) {
			return array();
		}
		if ( 'QAPage' === $configured_type ) {
			// QAPage requires a Question mainEntity. Until a dedicated builder
			// exists, fall back to WebPage rather than emit an incomplete type.
			$configured_type = 'WebPage';
		}
		if ( '' !== $configured_type ) {
			$type = $configured_type;
		}
	}
	$schema    = array(
		'@type'       => $type,
		'@id'         => $canonical . '#webpage',
		'url'         => $canonical,
		'name'        => erankly_get_title(),
		'description' => erankly_get_description(),
		'isPartOf'    => array(
			'@id' => home_url( '/#website' ),
		),
		'inLanguage'  => get_bloginfo( 'language' ),
	);

	if ( $post_id > 0 ) {
		$schema['datePublished'] = get_the_date( DATE_W3C, $post_id );
		$schema['dateModified']  = get_the_modified_date( DATE_W3C, $post_id );
	}

	// Link to the BreadcrumbList node only when the caller confirms one is emitted.
	if ( '' !== $breadcrumb_id ) {
		$schema['breadcrumb'] = array(
			'@id' => $breadcrumb_id,
		);
	}

	if ( 'ProfilePage' === $type ) {
		$schema['mainEntity'] = array(
			'@id' => erankly_schema_identity_id(),
		);
	}

	return apply_filters( 'erankly_schema_webpage', array_filter( $schema ), $post_id );
}

/** @return array<string,mixed> */
function erankly_schema_article( int $post_id = 0 ): array {
	if ( $post_id <= 0 ) {
		$post_id = get_queried_object_id();
	}

	$url       = erankly_get_canonical();
	$url       = '' !== $url ? $url : (string) get_permalink( $post_id );
	$image     = erankly_get_og_image();
	$author_id = (int) get_post_field( 'post_author', $post_id );
	$article_type = erankly_get_post_type_schema_type( (string) get_post_type( $post_id ), 'article_type' );
	$article_type = ( '' !== $article_type && 'none' !== strtolower( $article_type ) )
		? $article_type
		: ( is_singular( 'post' ) ? 'BlogPosting' : 'Article' );
	$schema       = array(
		'@type'            => $article_type,
		'@id'              => $url . '#article',
		'headline'         => erankly_get_title(),
		'description'      => erankly_get_description(),
		'url'              => $url,
		'datePublished'    => get_the_date( DATE_W3C, $post_id ),
		'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
		'author'           => erankly_schema_article_author( $author_id ),
		'publisher'        => array(
			'@id' => erankly_schema_identity_id(),
		),
		'mainEntityOfPage' => array(
			'@id' => $url . '#webpage',
		),
	);

	// erankly_get_og_image() already falls back through the post thumbnail,
	// the default OG image and finally the Organization logo.
	if ( '' !== $image ) {
		$schema['image'] = $image;
	}

	$primary_category = erankly_get_primary_term( $post_id, 'category' );
	if ( $primary_category instanceof WP_Term ) {
		$schema['articleSection'] = $primary_category->name;
	}

	return apply_filters( 'erankly_schema_article', array_filter( $schema ), $post_id );
}

/**
 * Returns the Article author node. Links to the Person identity node when the post author matches the configured
 * schema person, so the author and site identity stay connected.
 *
 * @param int $author_id Post author user ID.
 * @return array<string,mixed>
 */
function erankly_schema_article_author( int $author_id ): array {
	$author = array(
		'@type' => 'Person',
		'name'  => get_the_author_meta( 'display_name', $author_id ),
	);

	$author_url = $author_id > 0 ? get_author_posts_url( $author_id ) : '';

	if ( is_string( $author_url ) && '' !== $author_url ) {
		$author['url'] = $author_url;
	}

	$identity_user_id = absint( erankly_get_setting( 'schema_person_user_id', 0 ) );

	if (
		'person' === erankly_get_schema_identity() &&
		$identity_user_id > 0 &&
		$identity_user_id === $author_id
	) {
		$author['@id'] = home_url( '/#person' );
	}

	return array_filter( $author );
}

/** @return array<string,mixed> */
function erankly_schema_blogposting( int $post_id = 0 ): array {
	$schema          = erankly_schema_article( $post_id );
	$schema['@type'] = 'BlogPosting';

	return apply_filters( 'erankly_schema_blogposting', $schema, $post_id );
}

/** @return array<string,mixed> */
function erankly_schema_faq( int $post_id = 0 ): array {
	$schema = array();

	/** Filters FAQ items for a post. Expected item shape: array( 'question' => '...', 'answer' => '...' ). */
	$items = apply_filters( 'erankly_faq_items', array(), $post_id );

	if ( is_array( $items ) && ! empty( $items ) ) {
		$entities = array();

		foreach ( $items as $item ) {
			$question = isset( $item['question'] ) ? trim( (string) $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? trim( (string) $item['answer'] ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		if ( ! empty( $entities ) ) {
			$schema = array(
				'@type'      => 'FAQPage',
				'@id'        => erankly_get_canonical() . '#faqpage',
				'mainEntity' => $entities,
			);
		}
	}

	return apply_filters( 'erankly_schema_faq', $schema, $post_id );
}

/** @return array<string,mixed> */
function erankly_schema_service( array $args = array() ): array {
	$schema = wp_parse_args(
		$args,
		array(
			'@type'       => 'Service',
			'name'        => erankly_get_title(),
			'description' => erankly_get_description(),
			'url'         => erankly_get_canonical(),
			'provider'    => array(
				'@id' => erankly_schema_identity_id(),
			),
		)
	);

	return apply_filters( 'erankly_schema_service', array_filter( $schema ), $args );
}

/** @return array<string,mixed> */
function erankly_schema_localbusiness( array $args = array() ): array {
	$schema = wp_parse_args(
		$args,
		array(
			'@type' => 'LocalBusiness',
			'@id'   => home_url( '/#localbusiness' ),
			'name'  => erankly_get_organization_name(),
			'url'   => home_url( '/' ),
		)
	);

	return apply_filters( 'erankly_schema_localbusiness', array_filter( $schema ), $args );
}

/**
 * @param int $post_id Current singular post ID.
 * @return array<string,mixed>
 */
function erankly_schema_local_business_for_page( int $post_id ): array {
	if ( ! erankly_get_setting( 'enable_local_business', 0 ) || 'page' !== get_post_type( $post_id ) ) {
		return array();
	}

	$page_id = erankly_get_local_business_page_id( $post_id );

	if ( $page_id <= 0 || $page_id !== $post_id ) {
		return array();
	}

	$page = get_post( $page_id );

	if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
		return array();
	}

	$name    = trim( erankly_get_organization_name() );
	$address = erankly_schema_organization_address( true );

	if ( '' === $name || empty( $address ) ) {
		return array();
	}

	$types = erankly_get_local_business_types();
	$type  = (string) erankly_get_setting( 'local_business_type', 'LocalBusiness' );
	$type  = isset( $types[ $type ] ) ? $type : 'LocalBusiness';
	$url   = get_permalink( $page );

	if ( ! is_string( $url ) || '' === $url ) {
		return array();
	}

	$schema = erankly_schema_localbusiness(
		array(
			'@type'              => $type,
			'@id'                => trailingslashit( $url ) . '#localbusiness',
			'name'               => $name,
			'url'                => $url,
			'inLanguage'         => get_bloginfo( 'language' ),
			'address'            => $address,
			'parentOrganization' => array(
				'@id' => home_url( '/#organization' ),
			),
		)
	);
	$logo   = erankly_get_organization_logo_url();

	if ( '' !== $logo ) {
		$schema['image'] = $logo;
	}

	$email = sanitize_email( (string) erankly_get_setting( 'organization_email', '' ) );

	if ( '' !== $email ) {
		$schema['email'] = $email;
	}

	$telephone = erankly_sanitize_phone( erankly_get_setting( 'organization_phone', '' ) );

	if ( '' !== $telephone ) {
		$schema['telephone'] = $telephone;
	}

	$price_range = trim( (string) erankly_get_setting( 'local_business_price_range', '' ) );

	if ( '' !== $price_range ) {
		$schema['priceRange'] = $price_range;
	}

	$latitude  = erankly_sanitize_coordinate( erankly_get_setting( 'local_business_latitude', '' ), -90, 90 );
	$longitude = erankly_sanitize_coordinate( erankly_get_setting( 'local_business_longitude', '' ), -180, 180 );

	if ( '' !== $latitude && '' !== $longitude ) {
		$schema['geo'] = array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $latitude,
			'longitude' => (float) $longitude,
		);
	}

	$opening_hours = erankly_schema_opening_hours();

	if ( ! empty( $opening_hours ) ) {
		$schema['openingHoursSpecification'] = $opening_hours;
	}

	if ( erankly_is_food_business_type( $type ) ) {
		$menu = erankly_sanitize_url( erankly_get_setting( 'local_business_menu_url', '' ) );

		if ( '' !== $menu ) {
			$schema['menu'] = $menu;
		}

		$cuisine = trim( (string) erankly_get_setting( 'local_business_cuisine', '' ) );

		if ( '' !== $cuisine ) {
			$schema['servesCuisine'] = array_values( array_filter( array_map( 'trim', explode( ',', $cuisine ) ) ) );
		}
	}

	$schema = apply_filters( 'erankly_schema_local_business', $schema, $post_id );

	return is_array( $schema ) ? array_filter( $schema ) : array();
}

/** @return array<int,array<string,mixed>> */
function erankly_schema_opening_hours( ?array $configured_hours = null ): array {
	$hours  = erankly_sanitize_opening_hours( null === $configured_hours ? erankly_get_setting( 'local_business_hours', array() ) : $configured_hours );
	$days   = array(
		'monday'    => 'Monday',
		'tuesday'   => 'Tuesday',
		'wednesday' => 'Wednesday',
		'thursday'  => 'Thursday',
		'friday'    => 'Friday',
		'saturday'  => 'Saturday',
		'sunday'    => 'Sunday',
	);
	$groups = array();

	foreach ( $days as $day_key => $schema_day ) {
		$day_hours = $hours[ $day_key ];

		if ( ! empty( $day_hours['closed'] ) ) {
			continue;
		}

		$schedule = array_values(
			array_filter(
				$day_hours['intervals'],
				static fn( array $interval ): bool => '' !== $interval['opens'] && '' !== $interval['closes']
			)
		);

		if ( empty( $schedule ) ) {
			continue;
		}

		$key = (string) wp_json_encode( $schedule );

		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'days'     => array(),
				'schedule' => $schedule,
			);
		}

		$groups[ $key ]['days'][] = $schema_day;
	}

	$specifications = array();

	foreach ( $groups as $group ) {
		foreach ( $group['schedule'] as $interval ) {
			$specifications[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $group['days'],
				'opens'     => $interval['opens'],
				'closes'    => $interval['closes'],
			);
		}
	}

	return $specifications;
}

/** @return array<int,array<string,mixed>> */
function erankly_get_global_schema_graph(): array {
	$blocks = erankly_get_setting( 'global_schema_blocks', array() );

	if ( ! is_array( $blocks ) ) {
		return array();
	}

	$graph   = array();
	$post_id = is_singular() ? get_queried_object_id() : 0;

	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) || empty( $block['type'] ) ) {
			continue;
		}

		if ( ! erankly_global_schema_block_matches_request( $block ) ) {
			continue;
		}

		$schemas = erankly_schema_from_configured_block( $block, $post_id );

		foreach ( $schemas as $schema ) {
			if ( ! empty( $schema ) ) {
				$graph[] = $schema;
			}
		}
	}

	return $graph;
}

function erankly_global_schema_block_matches_request( array $block ): bool {
	return erankly_targeted_block_matches_request( $block );
}

function erankly_global_schema_matches_post_type_archive( array $block ): bool {
	return erankly_targeted_block_matches_post_type_archive( $block );
}

function erankly_global_schema_matches_singular( array $block ): bool {
	return erankly_targeted_block_matches_singular( $block );
}

function erankly_schema_target_list_contains_post( string $value, int $post_id ): bool {
	return erankly_target_list_contains_item( $value, 'post', $post_id );
}

/** @return array<int,array<string,mixed>> */
function erankly_schema_from_configured_block( array $block, int $post_id ): array {
	$type    = isset( $block['type'] ) ? (string) $block['type'] : '';
	$schemas = 'custom' === $type ? erankly_configured_custom_schemas( $block, $post_id ) : array();

	$filtered = apply_filters( 'erankly_schema_from_configured_block', $schemas, $block, $post_id );

	return is_array( $filtered ) ? $filtered : array();
}

/** @return array<int,array<string,mixed>> */
function erankly_configured_custom_schemas( array $block, int $post_id ): array {
	$json = erankly_schema_block_field( $block, 'custom_json', $post_id, true );

	if ( '' === $json ) {
		return array();
	}

	$schemas = array();
	$replaced = erankly_replace_json_ld_variables( $json, $post_id );
	$decoded  = erankly_decode_custom_json_ld( $replaced );

	foreach ( $decoded as $schema ) {
		if ( ! empty( $schema ) ) {
			$schemas[] = erankly_filter_empty_schema_values( $schema );
		}
	}

	return array_filter( $schemas );
}

/** @param bool                $raw_value Whether to return the raw stored value. */
function erankly_schema_block_field( array $block, string $field, int $post_id, bool $raw_value = false ): string {
	$fields = isset( $block['fields'] ) && is_array( $block['fields'] ) ? $block['fields'] : array();
	$value  = isset( $fields[ $field ] ) ? trim( (string) $fields[ $field ] ) : '';

	if ( $raw_value || '' === $value ) {
		return $value;
	}

	return trim( wp_strip_all_tags( erankly_replace_variables( $value, $post_id ) ) );
}

/**
 * Recursively removes empty schema values.
 *
 * @return array<string,mixed>
 */
function erankly_filter_empty_schema_values( array $schema ): array {
	foreach ( $schema as $key => $value ) {
		if ( is_array( $value ) ) {
			$value = erankly_filter_empty_schema_values( $value );
		}

		if ( array() === $value || '' === $value || null === $value ) {
			unset( $schema[ $key ] );
			continue;
		}

		$schema[ $key ] = $value;
	}

	return $schema;
}
