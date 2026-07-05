<?php
/**
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders advanced Organization identity fields.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_organization_details( array $settings ): void {
	?>
	<details class="erankly-settings-details">
		<summary><?php esc_html_e( 'Legal information and address', 'easyrankly' ); ?></summary>
		<div class="erankly-settings-details-content">
			<div class="erankly-field">
				<label for="erankly-organization-legal-name"><strong><?php esc_html_e( 'Legal name', 'easyrankly' ); ?></strong></label>
				<input id="erankly-organization-legal-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_legal_name]" value="<?php echo esc_attr( (string) $settings['organization_legal_name'] ); ?>">
				<p class="description"><?php esc_html_e( 'Use this only when the registered name differs from the public organization name.', 'easyrankly' ); ?></p>
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-vat-id"><strong><?php esc_html_e( 'VAT ID', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-vat-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_vat_id]" value="<?php echo esc_attr( (string) $settings['organization_vat_id'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-tax-id"><strong><?php esc_html_e( 'Tax ID', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-tax-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_tax_id]" value="<?php echo esc_attr( (string) $settings['organization_tax_id'] ); ?>">
				</div>
			</div>
			<div class="erankly-field">
				<label for="erankly-organization-street-address"><strong><?php esc_html_e( 'Street address', 'easyrankly' ); ?></strong></label>
				<input id="erankly-organization-street-address" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_street_address]" value="<?php echo esc_attr( (string) $settings['organization_street_address'] ); ?>">
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-locality"><strong><?php esc_html_e( 'City / locality', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-locality" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_locality]" value="<?php echo esc_attr( (string) $settings['organization_locality'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-region"><strong><?php esc_html_e( 'Region / state', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-region" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_region]" value="<?php echo esc_attr( (string) $settings['organization_region'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-postal-code"><strong><?php esc_html_e( 'Postal code', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-postal-code" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_postal_code]" value="<?php echo esc_attr( (string) $settings['organization_postal_code'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-country"><strong><?php esc_html_e( 'Country code', 'easyrankly' ); ?></strong></label>
					<input id="erankly-organization-country" class="widefat" type="text" maxlength="2" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_country]" value="<?php echo esc_attr( (string) $settings['organization_country'] ); ?>" placeholder="IT">
					<p class="description"><?php esc_html_e( 'Two-letter ISO 3166-1 code.', 'easyrankly' ); ?></p>
				</div>
			</div>
		</div>
	</details>
	<?php
}

/**
 * Renders LocalBusiness settings.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_local_business_settings( array $settings ): void {
	$types        = erankly_get_local_business_types();
	$pages        = get_pages(
		array(
			'post_status' => 'publish',
			'sort_column' => 'menu_order,post_title',
		)
	);
	$hours        = isset( $settings['local_business_hours'] ) && is_array( $settings['local_business_hours'] ) ? $settings['local_business_hours'] : erankly_default_opening_hours();
	$enabled      = ! empty( $settings['enable_local_business'] );
	$type         = isset( $settings['local_business_type'] ) ? (string) $settings['local_business_type'] : 'LocalBusiness';
	$page_path    = isset( $settings['local_business_page_path'] ) ? (string) $settings['local_business_page_path'] : '';
	$page_options = array();

	foreach ( $pages as $page ) {
		$path = erankly_sanitize_relative_path( '/' . get_page_uri( $page ) . '/' );

		if ( '' !== $path ) {
			$page_options[ $path ] = get_the_title( $page ) . ' (' . $path . ')';
		}
	}
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-local-business" data-erankly-local-business>
		<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_local_business]" value="1" <?php checked( $enabled ); ?> data-erankly-local-business-toggle> <strong><?php esc_html_e( 'Add LocalBusiness schema for one physical location', 'easyrankly' ); ?></strong></label>
		<p class="description"><?php esc_html_e( 'Use only when the selected page visibly contains the same business details. Keep them consistent with your Google Business Profile.', 'easyrankly' ); ?></p>
		<div class="erankly-local-business-fields" data-erankly-local-business-fields <?php echo $enabled ? '' : 'hidden'; ?>>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-local-business-type"><strong><?php esc_html_e( 'Business type', 'easyrankly' ); ?></strong></label>
					<select id="erankly-local-business-type" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_type]" data-erankly-local-business-type>
						<?php foreach ( $types as $type_key => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="erankly-field">
					<label for="erankly-local-business-page"><strong><?php esc_html_e( 'Location page', 'easyrankly' ); ?></strong></label>
					<select id="erankly-local-business-page" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_page_path]">
						<option value=""><?php esc_html_e( 'Select a published page', 'easyrankly' ); ?></option>
						<?php if ( '' !== $page_path && ! isset( $page_options[ $page_path ] ) ) : ?>
							<option value="<?php echo esc_attr( $page_path ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: saved relative page path. */ __( 'Saved path unavailable on this site (%s)', 'easyrankly' ), $page_path ) ); ?></option>
						<?php endif; ?>
						<?php foreach ( $page_options as $path => $label ) : ?>
							<option value="<?php echo esc_attr( $path ); ?>" <?php selected( $page_path, $path ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The relative path is shared across Multisite sites.', 'easyrankly' ); ?></p>
				</div>
			</div>
			<details class="erankly-settings-details">
				<summary><?php esc_html_e( 'Location details and opening hours', 'easyrankly' ); ?></summary>
				<div class="erankly-settings-details-content">
					<div class="erankly-inline-fields erankly-inline-fields-two-columns">
						<div class="erankly-field">
							<label for="erankly-local-business-price-range"><strong><?php esc_html_e( 'Price range', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-price-range" class="widefat" type="text" maxlength="99" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_price_range]" value="<?php echo esc_attr( (string) $settings['local_business_price_range'] ); ?>" placeholder="€€">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-latitude"><strong><?php esc_html_e( 'Latitude', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-latitude" class="widefat" type="number" step="any" min="-90" max="90" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_latitude]" value="<?php echo esc_attr( (string) $settings['local_business_latitude'] ); ?>">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-longitude"><strong><?php esc_html_e( 'Longitude', 'easyrankly' ); ?></strong></label>
							<input id="erankly-local-business-longitude" class="widefat" type="number" step="any" min="-180" max="180" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_longitude]" value="<?php echo esc_attr( (string) $settings['local_business_longitude'] ); ?>">
						</div>
					</div>
					<div data-erankly-food-business-fields <?php echo erankly_is_food_business_type( $type ) ? '' : 'hidden'; ?>>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-local-business-menu"><strong><?php esc_html_e( 'Menu URL', 'easyrankly' ); ?></strong></label>
								<input id="erankly-local-business-menu" class="widefat" type="url" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_menu_url]" value="<?php echo esc_attr( (string) $settings['local_business_menu_url'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-local-business-cuisine"><strong><?php esc_html_e( 'Cuisine served', 'easyrankly' ); ?></strong></label>
								<input id="erankly-local-business-cuisine" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_cuisine]" value="<?php echo esc_attr( (string) $settings['local_business_cuisine'] ); ?>" placeholder="<?php esc_attr_e( 'Italian, Mediterranean', 'easyrankly' ); ?>">
							</div>
						</div>
					</div>
					<h4><?php esc_html_e( 'Opening hours', 'easyrankly' ); ?></h4>
					<p class="description"><?php esc_html_e( 'Leave both intervals empty when no hours should be published. Overnight intervals are supported.', 'easyrankly' ); ?></p>
					<?php erankly_render_opening_hours_fields( $hours ); ?>
				</div>
			</details>
		</div>
	</fieldset>
	<?php
}

/**
 * Renders weekly opening-hours controls.
 *
 * @param array<string,mixed> $hours Opening hours.
 * @return void
 */
function erankly_render_opening_hours_fields( array $hours ): void {
	$days = array(
		'monday'    => __( 'Monday', 'easyrankly' ),
		'tuesday'   => __( 'Tuesday', 'easyrankly' ),
		'wednesday' => __( 'Wednesday', 'easyrankly' ),
		'thursday'  => __( 'Thursday', 'easyrankly' ),
		'friday'    => __( 'Friday', 'easyrankly' ),
		'saturday'  => __( 'Saturday', 'easyrankly' ),
		'sunday'    => __( 'Sunday', 'easyrankly' ),
	);
	?>
	<div class="erankly-opening-hours">
		<?php foreach ( $days as $day => $label ) : ?>
			<?php
			$day_hours = isset( $hours[ $day ] ) && is_array( $hours[ $day ] ) ? $hours[ $day ] : array();
			$closed    = ! empty( $day_hours['closed'] );
			$intervals = isset( $day_hours['intervals'] ) && is_array( $day_hours['intervals'] ) ? $day_hours['intervals'] : array();
			?>
			<div class="erankly-opening-hours-row" data-erankly-opening-day>
				<strong><?php echo esc_html( $label ); ?></strong>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][closed]" value="1" <?php checked( $closed ); ?> data-erankly-day-closed> <?php esc_html_e( 'Closed', 'easyrankly' ); ?></label>
				<div class="erankly-opening-intervals" data-erankly-opening-intervals <?php echo $closed ? 'hidden' : ''; ?>>
					<?php foreach ( array( 0, 1 ) as $index ) : ?>
						<?php
						$interval = isset( $intervals[ $index ] ) && is_array( $intervals[ $index ] ) ? $intervals[ $index ] : array();
						$opens    = isset( $interval['opens'] ) ? (string) $interval['opens'] : '';
						$closes   = isset( $interval['closes'] ) ? (string) $interval['closes'] : '';
						?>
						<span>
							<label>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: 1: day, 2: interval number. */ __( '%1$s interval %2$d opens', 'easyrankly' ), $label, $index + 1 ) ); ?></span>
								<input type="time" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][intervals][<?php echo esc_attr( (string) $index ); ?>][opens]" value="<?php echo esc_attr( $opens ); ?>">
							</label>
							<span aria-hidden="true">-</span>
							<label>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: 1: day, 2: interval number. */ __( '%1$s interval %2$d closes', 'easyrankly' ), $label, $index + 1 ) ); ?></span>
								<input type="time" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][intervals][<?php echo esc_attr( (string) $index ); ?>][closes]" value="<?php echo esc_attr( $closes ); ?>">
							</label>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

/**
 * Renders global SEO defaults for post types or taxonomies.
 *
 * @param string                                 $setting_key Settings array key.
 * @param array<string,WP_Post_Type|WP_Taxonomy> $objects     Public objects.
 * @param array<string,mixed>                    $settings    Current settings.
 * @return void
 */
function erankly_render_global_meta_defaults( string $setting_key, array $objects, array $settings ): void {
	$values             = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	$linked_setting_key = $setting_key . '_linked';
	$is_linked          = ! array_key_exists( $linked_setting_key, $settings ) || ! empty( $settings[ $linked_setting_key ] );
	$is_taxonomy        = 'global_taxonomy_meta' === $setting_key;

	if ( empty( $objects ) ) {
		echo '<p class="description">' . esc_html__( 'No public items available.', 'easyrankly' ) . '</p>';
		return;
	}

	$tabs_id           = 'erankly-' . sanitize_key( $setting_key ) . '-tabs';
	$toggle_id         = 'erankly-' . sanitize_key( $setting_key ) . '-linked';
	$toggle_base_label = $is_taxonomy ? __( 'Link taxonomy templates', 'easyrankly' ) : __( 'Link post type templates', 'easyrankly' );
	$toggle_on_label   = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: Yes', 'easyrankly' ),
		$toggle_base_label
	);
	$toggle_off_label = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: No', 'easyrankly' ),
		$toggle_base_label
	);
	$linked_panel_label = __( 'Unified', 'easyrankly' );
	?>
	<div class="erankly-default-tabs erankly-entity-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php esc_attr_e( 'Default metadata by content type', 'easyrankly' ); ?>">
				<span class="erankly-tab erankly-linked-tabs-summary" aria-hidden="true"><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $objects as $key => $object ) :
					$label         = $object instanceof WP_Taxonomy ? erankly_get_taxonomy_admin_label( $object ) : $object->labels->singular_name;
					$tab_key       = sanitize_key( $setting_key . '-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'tabindex="-1"' : ''; ?>><?php echo esc_html( $label ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
			<input id="<?php echo esc_attr( $toggle_id ); ?>-input" type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $linked_setting_key ); ?>]" value="<?php echo esc_attr( $is_linked ? '1' : '0' ); ?>" data-erankly-linked-input>
			<span class="erankly-linked-defaults-label"><?php echo esc_html( $toggle_base_label ); ?></span>
			<button type="button" class="erankly-tabs erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" aria-pressed="<?php echo esc_attr( $is_linked ? 'true' : 'false' ); ?>" title="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $toggle_off_label ); ?>">
				<span class="erankly-tab erankly-linked-defaults-option is-no" aria-hidden="true"><?php esc_html_e( 'No', 'easyrankly' ); ?></span>
				<span class="erankly-tab erankly-linked-defaults-option is-yes" aria-hidden="true"><?php esc_html_e( 'Yes', 'easyrankly' ); ?></span>
			</button>
			<span class="screen-reader-text" aria-live="polite" data-erankly-linked-status><?php echo esc_html( $is_linked ? $toggle_on_label : $toggle_off_label ); ?></span>
		</div>

		<?php
		$is_first = true;
		foreach ( $objects as $key => $object ) :
			$row             = isset( $values[ $key ] ) && is_array( $values[ $key ] ) ? $values[ $key ] : array();
			$title           = isset( $row['title'] ) ? (string) $row['title'] : '';
			$description     = isset( $row['description'] ) ? (string) $row['description'] : '';
			$noindex         = ! empty( $row['noindex'] );
			$nofollow        = ! empty( $row['nofollow'] );
			$noarchive       = ! empty( $row['noarchive'] );
			$disable_sitemap = ! empty( $row['disable_sitemap'] );
			$label           = $object instanceof WP_Taxonomy ? erankly_get_taxonomy_admin_label( $object ) : $object->labels->singular_name;
			$id_prefix       = 'erankly-' . sanitize_key( $setting_key ) . '-' . sanitize_key( $key );
			$panel_key       = sanitize_key( $setting_key . '-' . $key );
			$panel_id        = 'erankly-' . $panel_key . '-panel';
			$tab_id          = 'erankly-' . $panel_key . '-tab';
			// A sample post/term stands in for {{post_title}}/{{term_name}} etc. in
			// the preview since these fields are global templates, not tied to any
			// single post/term; the raw token stays literal when none exist yet.
			$sample_post     = $is_taxonomy ? null : erankly_get_sample_post_for_type( (string) $key );
			$sample_term     = $is_taxonomy ? erankly_get_sample_term_for_taxonomy( (string) $key ) : null;
			$examples        = erankly_get_admin_variable_examples( $sample_post, $sample_term );
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<?php erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap ); ?>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders default Open Graph / X (Twitter) templates with a linked toggle.
 *
 * Mirrors the post type defaults UI: when linked (the default), one template
 * drives both networks; when separate, each network keeps its own values.
 *
 * @param array<string,mixed> $settings Current settings.
 * @return void
 */
function erankly_render_social_meta_defaults( array $settings ): void {
	$networks = array(
		'og'      => array(
			'label'           => __( 'Open Graph', 'easyrankly' ),
			'title_key'       => 'default_og_title',
			'description_key' => 'default_og_description',
			'id_prefix'       => 'erankly-default-og',
		),
		'twitter' => array(
			'label'           => __( 'X (Twitter)', 'easyrankly' ),
			'title_key'       => 'default_twitter_title',
			'description_key' => 'default_twitter_description',
			'id_prefix'       => 'erankly-default-twitter',
		),
	);

	$og_title            = isset( $settings['default_og_title'] ) ? (string) $settings['default_og_title'] : '';
	$og_description      = isset( $settings['default_og_description'] ) ? (string) $settings['default_og_description'] : '';
	$twitter_title       = isset( $settings['default_twitter_title'] ) ? (string) $settings['default_twitter_title'] : '';
	$twitter_description = isset( $settings['default_twitter_description'] ) ? (string) $settings['default_twitter_description'] : '';

	// Sites saved before the toggle existed inherit the linked default only when
	// their Open Graph and X (Twitter) templates already match, so customized
	// per-network values are never silently overwritten.
	$is_linked = ( ! array_key_exists( 'social_defaults_linked', $settings ) || ! empty( $settings['social_defaults_linked'] ) )
		&& $og_title === $twitter_title
		&& $og_description === $twitter_description;

	$toggle_base_label = __( 'Link social templates', 'easyrankly' );
	$toggle_on_label   = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: Yes', 'easyrankly' ),
		$toggle_base_label
	);
	$toggle_off_label = sprintf(
		/* translators: %s: linked templates label. */
		__( '%s: No', 'easyrankly' ),
		$toggle_base_label
	);
	$linked_panel_label = __( 'Unified', 'easyrankly' );
	?>
	<div class="erankly-default-tabs <?php echo $is_linked ? 'is-linked' : ''; ?>" data-erankly-tabs-root data-erankly-linked-defaults>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-social-defaults-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Default social metadata by network', 'easyrankly' ); ?>">
				<span class="erankly-tab erankly-linked-tabs-summary" aria-hidden="true"><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $networks as $key => $network ) :
					$tab_key       = sanitize_key( 'social-defaults-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'tabindex="-1"' : ''; ?>><?php echo esc_html( $network['label'] ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
			<input id="erankly-social-defaults-linked-input" type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_defaults_linked]" value="<?php echo esc_attr( $is_linked ? '1' : '0' ); ?>" data-erankly-linked-input>
			<span class="erankly-linked-defaults-label"><?php echo esc_html( $toggle_base_label ); ?></span>
			<button type="button" class="erankly-tabs erankly-linked-defaults-toggle" aria-label="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" aria-pressed="<?php echo esc_attr( $is_linked ? 'true' : 'false' ); ?>" title="<?php echo esc_attr( $is_linked ? $toggle_on_label : $toggle_off_label ); ?>" data-erankly-linked-toggle data-erankly-linked-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-off-label="<?php echo esc_attr( $toggle_off_label ); ?>" data-erankly-linked-action-on-label="<?php echo esc_attr( $toggle_on_label ); ?>" data-erankly-linked-action-off-label="<?php echo esc_attr( $toggle_off_label ); ?>">
				<span class="erankly-tab erankly-linked-defaults-option is-no" aria-hidden="true"><?php esc_html_e( 'No', 'easyrankly' ); ?></span>
				<span class="erankly-tab erankly-linked-defaults-option is-yes" aria-hidden="true"><?php esc_html_e( 'Yes', 'easyrankly' ); ?></span>
			</button>
			<span class="screen-reader-text" aria-live="polite" data-erankly-linked-status><?php echo esc_html( $is_linked ? $toggle_on_label : $toggle_off_label ); ?></span>
		</div>

		<?php
		// These templates apply to every post regardless of type, so the most
		// recently published post (of the default "post" type) stands in as
		// the {{post_title}}-style example; the raw token stays literal if none exist yet.
		$examples = erankly_get_admin_variable_examples( erankly_get_sample_post_for_type( 'post' ) );
		$is_first = true;
		foreach ( $networks as $key => $network ) :
			$title       = isset( $settings[ $network['title_key'] ] ) ? (string) $settings[ $network['title_key'] ] : '';
			$description = isset( $settings[ $network['description_key'] ] ) ? (string) $settings[ $network['description_key'] ] : '';
			$panel_key   = sanitize_key( 'social-defaults-' . $key );
			$panel_id    = 'erankly-' . $panel_key . '-panel';
			$tab_id      = 'erankly-' . $panel_key . '-tab';
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-title"><strong><?php esc_html_e( 'Default title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $network['id_prefix'] ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['title_key'] ); ?>]" value="<?php echo esc_attr( $title ); ?>" data-erankly-linked-field="title">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-description"><strong><?php esc_html_e( 'Default description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $network['id_prefix'] ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['description_key'] ); ?>]" data-erankly-linked-field="description"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders global SEO defaults for special pages and archives.
 *
 * Special pages are singleton entities sharing the same metadata structure as
 * post types and taxonomies, but without the "linked" toggle. This settings
 * renderer is the fallback for classic themes and for block themes on WordPress
 * versions where the contextual Site Editor panels are unavailable.
 *
 * @param array<string,string> $entities Map of entity key => admin label.
 * @param array<string,mixed>  $settings Current settings.
 * @return void
 */
function erankly_render_special_page_defaults( array $entities, array $settings ): void {
	if ( empty( $entities ) || erankly_use_site_editor_special_page_panels() ) {
		return;
	}

	$setting_key = 'global_special_meta';
	$values      = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	?>
	<p class="description"><?php esc_html_e( 'Set the default SEO metadata for WordPress contexts that are not individual posts or terms: homepage, blog, author and date archives, search results and the 404 page.', 'easyrankly' ); ?></p>
	<?php

	erankly_render_special_page_defaults_group( $entities, $values, $setting_key, 'all', __( 'Default metadata by WordPress context', 'easyrankly' ) );
}

/**
 * Renders one tab group for special page defaults.
 *
 * @param array<string,string> $entities    Map of entity key => admin label.
 * @param array<string,mixed>  $values      Current settings for the group.
 * @param string               $setting_key Settings array key.
 * @param string               $group_key   Unique group key.
 * @param string               $aria_label  Tablist label.
 * @return void
 */
function erankly_render_special_page_defaults_group( array $entities, array $values, string $setting_key, string $group_key, string $aria_label ): void {
	$tabs_id   = 'erankly-' . sanitize_key( $setting_key . '-' . $group_key ) . '-tabs';
	$is_simple = (bool) erankly_get_setting( 'simplified_mode', 1 );
	?>
	<div class="erankly-default-tabs erankly-entity-default-tabs erankly-special-page-default-tabs" data-erankly-tabs-root>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php echo esc_attr( $aria_label ); ?>">
				<?php
				$is_first = true;
				foreach ( $entities as $key => $label ) :
					$tab_key  = sanitize_key( $setting_key . '-' . $group_key . '-' . $key );
					$panel_id = 'erankly-' . $tab_key . '-panel';
					$tab_id   = 'erankly-' . $tab_key . '-tab';
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_first ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_first ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php
					$is_first = false;
				endforeach;
				?>
			</div>
		</div>

		<?php
		$is_first = true;
		foreach ( $entities as $key => $label ) :
			$row             = isset( $values[ $key ] ) && is_array( $values[ $key ] ) ? $values[ $key ] : array();
			$title           = isset( $row['title'] ) ? (string) $row['title'] : '';
			$description     = isset( $row['description'] ) ? (string) $row['description'] : '';
			$noindex         = ! empty( $row['noindex'] );
			$nofollow        = ! empty( $row['nofollow'] );
			$noarchive       = ! empty( $row['noarchive'] );
			$disable_sitemap = ! empty( $row['disable_sitemap'] );
			$id_prefix       = 'erankly-' . sanitize_key( $setting_key ) . '-' . sanitize_key( (string) $key );
			$panel_key       = sanitize_key( $setting_key . '-' . $group_key . '-' . $key );
			$panel_id        = 'erankly-' . $panel_key . '-panel';
			$tab_id          = 'erankly-' . $panel_key . '-tab';
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<h4>
						<span class="erankly-default-entity-label"><?php echo esc_html( $label ); ?></span>
					</h4>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><strong><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><strong><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<?php
					// Among special pages only the author archive ever appears in the
					// XML sitemap, so the "Disable sitemap" toggle is shown only there.
					erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap, 'author' === (string) $key );

					// Social sharing is an advanced-only panel; in simplified mode the
					// values are carried through as hidden inputs so saving never wipes them.
					erankly_render_special_page_social_defaults( $setting_key, (string) $key, $row, $id_prefix, $is_simple );
					?>
				</div>
			</div>
			<?php
			$is_first = false;
		endforeach;
		?>
	</div>
	<?php
}

/**
 * Renders the advanced-only social sharing defaults for one special page.
 *
 * In simplified mode the panel is hidden, but the stored values are carried
 * through as hidden inputs so saving in simplified mode never wipes them
 * (mirrors how the visibility panel preserves nofollow/noarchive).
 *
 * @param string              $setting_key Settings array key.
 * @param string              $key         Entity key.
 * @param array<string,mixed> $row         Current values for this entity.
 * @param string              $id_prefix   Field id prefix.
 * @param bool                $is_simple   Whether simplified mode is active.
 * @return void
 */
function erankly_render_special_page_social_defaults( string $setting_key, string $key, array $row, string $id_prefix, bool $is_simple ): void {
	$name           = ERANKLY_OPTION . '[' . $setting_key . '][' . $key . ']';
	$og_title       = isset( $row['og_title'] ) ? (string) $row['og_title'] : '';
	$og_description = isset( $row['og_description'] ) ? (string) $row['og_description'] : '';
	$tw_title       = isset( $row['twitter_title'] ) ? (string) $row['twitter_title'] : '';
	$tw_description = isset( $row['twitter_description'] ) ? (string) $row['twitter_description'] : '';
	$image_url      = isset( $row['social_image_url'] ) ? (string) $row['social_image_url'] : '';
	$image_id       = isset( $row['og_image_id'] ) ? absint( $row['og_image_id'] ) : 0;

	if ( $is_simple ) {
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_title]" value="<?php echo esc_attr( $og_title ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_description]" value="<?php echo esc_attr( $og_description ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[twitter_title]" value="<?php echo esc_attr( $tw_title ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[twitter_description]" value="<?php echo esc_attr( $tw_description ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[social_image_url]" value="<?php echo esc_attr( $image_url ); ?>">
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>[og_image_id]" value="<?php echo esc_attr( (string) $image_id ); ?>">
		<?php
		return;
	}
	?>
	<div class="erankly-defaults-section erankly-special-social-defaults">
		<h4><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></h4>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-title"><strong><?php esc_html_e( 'Social title', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-og-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[og_title]" value="<?php echo esc_attr( $og_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-description"><strong><?php esc_html_e( 'Social description', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-og-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[og_description]"><?php echo esc_textarea( $og_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-title"><strong><?php esc_html_e( 'X (Twitter) title', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-twitter-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[twitter_title]" value="<?php echo esc_attr( $tw_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-description"><strong><?php esc_html_e( 'X (Twitter) description', 'easyrankly' ); ?></strong></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-twitter-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[twitter_description]"><?php echo esc_textarea( $tw_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-social-image"><strong><?php esc_html_e( 'Social image', 'easyrankly' ); ?></strong></label>
			<?php
			erankly_render_media_url_field(
				$id_prefix . '-social-image',
				$name . '[social_image_url]',
				$image_url,
				'',
				$name . '[og_image_id]',
				$image_id,
				true
			);
			?>
		</div>
	</div>
	<?php
}

/**
 * Renders global visibility defaults.
 *
 * @param string $setting_key     Settings array key.
 * @param string $entity_key      Entity key.
 * @param bool   $noindex              Noindex default.
 * @param bool   $nofollow             Nofollow default.
 * @param bool   $noarchive            Noarchive default.
 * @param bool   $disable_sitemap      Disable sitemap default.
 * @param bool   $show_disable_sitemap Whether the entity can appear in a sitemap.
 *                                     When false the "Disable sitemap" control is
 *                                     hidden (e.g. special pages other than the
 *                                     author archive, the only one the XML sitemap
 *                                     consumes this flag for).
 * @return void
 */
function erankly_render_global_visibility_defaults( string $setting_key, string $entity_key, bool $noindex, bool $nofollow, bool $noarchive, bool $disable_sitemap, bool $show_disable_sitemap = true ): void {
	$name_prefix = ERANKLY_OPTION . '[' . $setting_key . '][' . $entity_key . ']';
	$is_simple   = (bool) erankly_get_setting( 'simplified_mode', 1 );
	// When the sitemap toggle does not apply to this entity, "hide from search
	// results" is driven by noindex alone.
	$is_hidden = $show_disable_sitemap ? ( $noindex && $disable_sitemap ) : $noindex;
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
		<legend><strong><?php esc_html_e( 'Visibility defaults', 'easyrankly' ); ?></strong></legend>
		<div class="erankly-checkbox-options">
			<?php if ( $is_simple ) : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[hide_from_search_results]" value="1" <?php checked( $is_hidden ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
				<?php // The simplified control only drives noindex + disable_sitemap; carry the advanced-only directives through so saving in simplified mode never wipes them. ?>
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="<?php echo $nofollow ? '1' : '0'; ?>">
				<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="<?php echo $noarchive ? '1' : '0'; ?>">
			<?php else : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[noindex]" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label>
				<?php if ( $show_disable_sitemap ) : ?>
				<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</fieldset>
	<?php
}
