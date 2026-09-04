<?php
/** Post meta persistence. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_save_meta_box( int $post_id, WP_Post $post ): void {
	if ( ! isset( $_POST['erankly_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['erankly_meta_box_nonce'] ) ), 'erankly_save_meta_box' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$fields = array(
		'_erankly_title'           => 'erankly_title',
		'_erankly_description'     => 'erankly_description',
		'_erankly_canonical'       => 'erankly_canonical',
		'_erankly_breadcrumb_name' => 'erankly_breadcrumb_name',
	);

	// The field isn't rendered when breadcrumbs are off, so its POST key is absent.
	// skip it to keep any previously stored value.
	if ( ! (bool) erankly_get_setting( 'enable_breadcrumbs', 1 ) ) {
		unset( $fields['_erankly_breadcrumb_name'] );
	}

	$fields = array_merge(
		$fields,
		array(
			'_erankly_og_title'            => 'erankly_og_title',
			'_erankly_og_description'      => 'erankly_og_description',
			'_erankly_twitter_title'       => 'erankly_twitter_title',
			'_erankly_twitter_description' => 'erankly_twitter_description',
			'_erankly_twitter_card_type'   => 'erankly_twitter_card_type',
			'_erankly_og_image_url'        => 'erankly_og_image_url',
			'_erankly_og_image_alt'        => 'erankly_og_image_alt',
			'_erankly_twitter_image_url'   => 'erankly_twitter_image_url',
			'_erankly_twitter_image_alt'   => 'erankly_twitter_image_alt',
		)
	);

	$simplified_mode = (bool) erankly_get_setting( 'simplified_mode', 1 );

	if ( $simplified_mode ) {
		unset(
			$fields['_erankly_canonical'],
			$fields['_erankly_breadcrumb_name'],
			$fields['_erankly_og_title'],
			$fields['_erankly_og_description'],
			$fields['_erankly_twitter_title'],
			$fields['_erankly_twitter_description'],
			$fields['_erankly_twitter_card_type'],
			$fields['_erankly_og_image_url'],
			$fields['_erankly_og_image_alt'],
			$fields['_erankly_twitter_image_url'],
			$fields['_erankly_twitter_image_alt']
		);
	}

	foreach ( $fields as $key => $field ) {
		$raw_value = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by erankly_sanitize_registered_meta() on the next line.
		$value     = erankly_sanitize_registered_meta( $raw_value, $key );

		if ( '' === $value || 0 === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			// update_post_meta() expects slashed data; without wp_slash() it would
			// strip literal backslashes from the sanitized value.
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
	}

	$hide_from_search_results = $simplified_mode && isset( $_POST['erankly_hide_from_search_results'] );
	$existing_index_directive = isset( $_POST['erankly_existing_index_directive'] ) ? sanitize_key( wp_unslash( $_POST['erankly_existing_index_directive'] ) ) : 'inherit';
	$existing_hide            = ! empty( $_POST['erankly_existing_hide'] );

	if ( $simplified_mode ) {
		if ( $hide_from_search_results ) {
			update_post_meta( $post_id, '_erankly_index_directive', 'noindex' );
			update_post_meta( $post_id, '_erankly_noindex', '1' );
		} elseif ( $existing_hide && 'noindex' === $existing_index_directive ) {
			update_post_meta( $post_id, '_erankly_index_directive', 'inherit' );
			delete_post_meta( $post_id, '_erankly_noindex' );
		}
	} else {
		$directive_fields = array(
			'_erankly_index_directive'   => 'erankly_index_directive',
			'_erankly_follow_directive'  => 'erankly_follow_directive',
			'_erankly_archive_directive' => 'erankly_archive_directive',
			'_erankly_snippet_directive' => 'erankly_snippet_directive',
			'_erankly_image_directive'   => 'erankly_image_directive',
			'_erankly_max_snippet'       => 'erankly_max_snippet',
			'_erankly_max_video_preview' => 'erankly_max_video_preview',
			'_erankly_max_image_preview' => 'erankly_max_image_preview',
		);

		foreach ( $directive_fields as $key => $field ) {
			$value = erankly_sanitize_registered_meta( isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '', $key ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the called field-specific sanitizer.

			if ( '' === $value || 'inherit' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}

		foreach ( array(
			'noindex'   => 'index',
			'nofollow'  => 'follow',
			'noarchive' => 'archive',
		) as $legacy => $axis ) {
			$value = isset( $_POST[ 'erankly_' . $axis . '_directive' ] ) ? sanitize_key( wp_unslash( $_POST[ 'erankly_' . $axis . '_directive' ] ) ) : 'inherit';

			if ( $legacy === $value ) {
				update_post_meta( $post_id, '_erankly_' . $legacy, '1' );
			} else {
				delete_post_meta( $post_id, '_erankly_' . $legacy );
			}
		}

		if ( isset( $_POST['erankly_indexifembedded'] ) ) {
			update_post_meta( $post_id, '_erankly_indexifembedded', '1' );
		} else {
			delete_post_meta( $post_id, '_erankly_indexifembedded' );
		}
	}

	// "Hide from search results" sets only noindex + disable_sitemap.
	$booleans = array(
		'_erankly_exclude_search'    => isset( $_POST['erankly_exclude_search'] ),
		'_erankly_exclude_archive'   => isset( $_POST['erankly_exclude_archive'] ),
		'_erankly_exclude_from_news' => isset( $_POST['erankly_exclude_from_news'] ),
	);

	if ( ! $simplified_mode || $hide_from_search_results || $existing_hide ) {
		$booleans['_erankly_disable_sitemap'] = $hide_from_search_results || ( ! $simplified_mode && isset( $_POST['erankly_disable_sitemap'] ) );
	}

	foreach ( $booleans as $key => $enabled ) {
		if ( $enabled ) {
			update_post_meta( $post_id, $key, '1' );
		} else {
			delete_post_meta( $post_id, $key );
		}
	}

	if ( ! $simplified_mode ) {
		$complex_fields = array(
			'_erankly_primary_terms'         => isset( $_POST['erankly_primary_terms'] ) ? wp_unslash( $_POST['erankly_primary_terms'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			'_erankly_schema_blocks'         => isset( $_POST['erankly_schema_blocks'] ) ? wp_unslash( $_POST['erankly_schema_blocks'] ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
			'_erankly_schema_disabled_types' => isset( $_POST['erankly_schema_disabled_types'] ) ? preg_split( '/[\r\n,]+/', wp_unslash( $_POST['erankly_schema_disabled_types'] ) ) : array(), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		);

		foreach ( $complex_fields as $key => $raw_value ) {
			$value = erankly_sanitize_registered_meta( $raw_value, $key );

			if ( empty( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				// Metadata APIs unslash values before persistence; preserve JSON-LD
				// escapes inside nested schema block arrays.
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		$schema_mode = erankly_sanitize_registered_meta( isset( $_POST['erankly_schema_mode'] ) ? wp_unslash( $_POST['erankly_schema_mode'] ) : '', '_erankly_schema_mode' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the called field-specific sanitizer.
		if ( '' === $schema_mode || 'default' === $schema_mode ) {
			delete_post_meta( $post_id, '_erankly_schema_mode' );
		} else {
			update_post_meta( $post_id, '_erankly_schema_mode', $schema_mode );
		}
	}

	/** Fires after core saves all classic meta box fields. */
	do_action( 'erankly_save_meta_box', $post_id, $post );
}
