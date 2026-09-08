<?php
/**
 * Settings field renderers: organization details, local business, opening hours and global defaults (post
 * types, taxonomies, special pages, social networks). Hidden inputs carry advanced-only values through
 * simplified-mode and cross-panel saves so nothing stored is ever wiped by a partial form submission.
 */
defined( 'ABSPATH' ) || exit;
function erankly_render_organization_details( array $settings ): void {
	?>
	<details class="erankly-settings-details">
		<summary><?php esc_html_e( 'Legal information and address', 'easyrankly' ); ?></summary>
		<div class="erankly-settings-details-content">
			<div class="erankly-field">
				<label for="erankly-organization-legal-name"><?php esc_html_e( 'Legal name', 'easyrankly' ); ?></label>
				<input id="erankly-organization-legal-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_legal_name]" value="<?php echo esc_attr( (string) $settings['organization_legal_name'] ); ?>">
				<p class="description"><?php esc_html_e( 'Use this only when the registered name differs from the public organization name.', 'easyrankly' ); ?></p>
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-vat-id"><?php esc_html_e( 'VAT ID', 'easyrankly' ); ?></label>
					<input id="erankly-organization-vat-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_vat_id]" value="<?php echo esc_attr( (string) $settings['organization_vat_id'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-tax-id"><?php esc_html_e( 'Tax ID', 'easyrankly' ); ?></label>
					<input id="erankly-organization-tax-id" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_tax_id]" value="<?php echo esc_attr( (string) $settings['organization_tax_id'] ); ?>">
				</div>
			</div>
			<div class="erankly-field">
				<label for="erankly-organization-street-address"><?php esc_html_e( 'Street address', 'easyrankly' ); ?></label>
				<input id="erankly-organization-street-address" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_street_address]" value="<?php echo esc_attr( (string) $settings['organization_street_address'] ); ?>">
			</div>
			<div class="erankly-inline-fields erankly-inline-fields-two-columns">
				<div class="erankly-field">
					<label for="erankly-organization-locality"><?php esc_html_e( 'City / locality', 'easyrankly' ); ?></label>
					<input id="erankly-organization-locality" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_locality]" value="<?php echo esc_attr( (string) $settings['organization_locality'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-region"><?php esc_html_e( 'Region / state', 'easyrankly' ); ?></label>
					<input id="erankly-organization-region" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_region]" value="<?php echo esc_attr( (string) $settings['organization_region'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-postal-code"><?php esc_html_e( 'Postal code', 'easyrankly' ); ?></label>
					<input id="erankly-organization-postal-code" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_postal_code]" value="<?php echo esc_attr( (string) $settings['organization_postal_code'] ); ?>">
				</div>
				<div class="erankly-field">
					<label for="erankly-organization-country"><?php esc_html_e( 'Country code', 'easyrankly' ); ?></label>
					<input id="erankly-organization-country" class="widefat" type="text" maxlength="2" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_country]" value="<?php echo esc_attr( (string) $settings['organization_country'] ); ?>" placeholder="IT">
					<p class="description"><?php esc_html_e( 'Two-letter ISO 3166-1 code.', 'easyrankly' ); ?></p>
				</div>
			</div>
		</div>
	</details>
	<?php
}
function erankly_render_local_business_settings( array $settings ): void {
	$types     = erankly_get_local_business_types();
	$hours     = isset( $settings['local_business_hours'] ) && is_array( $settings['local_business_hours'] ) ? $settings['local_business_hours'] : erankly_default_opening_hours();
	$enabled   = ! empty( $settings['enable_local_business'] );
	$type      = isset( $settings['local_business_type'] ) ? (string) $settings['local_business_type'] : 'LocalBusiness';
	$page_path = isset( $settings['local_business_page_path'] ) ? (string) $settings['local_business_page_path'] : '';
	$page_map  = isset( $settings['local_business_pages'] ) && is_array( $settings['local_business_pages'] )
		? array_map( 'absint', $settings['local_business_pages'] )
		: array();
	$choices   = function_exists( 'erankly_get_local_business_site_choices' ) ? erankly_get_local_business_site_choices() : array();
	$gaps      = function_exists( 'erankly_local_business_requirement_gaps' ) ? erankly_local_business_requirement_gaps( $settings ) : array();
	?>
	<div class="erankly-local-business" data-erankly-local-business>
		<div class="erankly-field erankly-checkboxes">
			<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_local_business]" value="1" <?php checked( $enabled ); ?> data-erankly-local-business-toggle> <?php esc_html_e( 'Add one physical business location for search engines', 'easyrankly' ); ?></label>
		</div>
		<div class="erankly-local-business-fields" data-erankly-local-business-fields <?php echo $enabled ? '' : 'hidden'; ?>>
			<?php if ( $enabled && ! empty( $gaps ) ) : ?>
				<div class="notice notice-error inline" role="alert">
					<p>
						<strong><?php esc_html_e( 'Incomplete configuration', 'easyrankly' ); ?></strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: comma-separated missing field names. */
								__( 'Local business schema will not be emitted until these fields are set: %s.', 'easyrankly' ),
								implode( ', ', $gaps )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<div class="erankly-field">
				<label for="erankly-local-business-type"><?php esc_html_e( 'Business type', 'easyrankly' ); ?></label>
				<select id="erankly-local-business-type" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_type]" data-erankly-local-business-type>
					<?php foreach ( $types as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<input type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_page_path]" value="<?php echo esc_attr( $page_path ); ?>">
			<div class="erankly-field">
				<span class="erankly-field-label"><?php esc_html_e( 'Location page', 'easyrankly' ); ?></span>
				<p class="description"><?php esc_html_e( 'Choose a published page on each site. The association uses the page ID, so changing the slug later does not break the output. Address and contact details stay shared.', 'easyrankly' ); ?></p>
				<?php foreach ( $choices as $site ) : ?>
					<?php
					$blog_id     = absint( $site['blog_id'] ?? 0 );
					$selected_id = isset( $page_map[ $blog_id ] ) ? absint( $page_map[ $blog_id ] ) : 0;
					$field_id    = 'erankly-local-business-page-' . $blog_id;
					$site_label  = sprintf(
						/* translators: 1: site name, 2: language, 3: site path. */
						__( '%1$s (%2$s) — %3$s', 'easyrankly' ),
						(string) ( $site['name'] ?? '' ),
						(string) ( $site['language'] ?? '' ),
						(string) ( $site['path'] ?? '/' )
					);
					?>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $site_label ); ?></label>
						<select id="<?php echo esc_attr( $field_id ); ?>" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_pages][<?php echo esc_attr( (string) $blog_id ); ?>]">
							<option value=""><?php esc_html_e( 'Select a published page', 'easyrankly' ); ?></option>
							<?php foreach ( (array) ( $site['pages'] ?? array() ) as $page_option ) : ?>
								<?php
								$page_id    = absint( $page_option['id'] ?? 0 );
								$page_title = (string) ( $page_option['title'] ?? '' );
								$page_path_label = (string) ( $page_option['path'] ?? '' );
								$page_label = trim( $page_title . ' (' . $page_path_label . ')' );
								?>
								<option value="<?php echo esc_attr( (string) $page_id ); ?>" <?php selected( $selected_id, $page_id ); ?>><?php echo esc_html( $page_label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endforeach; ?>
			</div>
			<details class="erankly-settings-details">
				<summary><?php esc_html_e( 'Location details and opening hours', 'easyrankly' ); ?></summary>
				<div class="erankly-settings-details-content">
					<div class="erankly-inline-fields erankly-inline-fields-two-columns">
						<div class="erankly-field">
							<label for="erankly-local-business-price-range"><?php esc_html_e( 'Price range', 'easyrankly' ); ?></label>
							<input id="erankly-local-business-price-range" class="widefat" type="text" maxlength="99" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_price_range]" value="<?php echo esc_attr( (string) $settings['local_business_price_range'] ); ?>" placeholder="€€">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-latitude"><?php esc_html_e( 'Latitude', 'easyrankly' ); ?></label>
							<input id="erankly-local-business-latitude" class="widefat" type="number" step="any" min="-90" max="90" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_latitude]" value="<?php echo esc_attr( (string) $settings['local_business_latitude'] ); ?>">
						</div>
						<div class="erankly-field">
							<label for="erankly-local-business-longitude"><?php esc_html_e( 'Longitude', 'easyrankly' ); ?></label>
							<input id="erankly-local-business-longitude" class="widefat" type="number" step="any" min="-180" max="180" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_longitude]" value="<?php echo esc_attr( (string) $settings['local_business_longitude'] ); ?>">
						</div>
					</div>
					<div data-erankly-food-business-fields <?php echo erankly_is_food_business_type( $type ) ? '' : 'hidden'; ?>>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-local-business-menu"><?php esc_html_e( 'Menu URL', 'easyrankly' ); ?></label>
								<input id="erankly-local-business-menu" class="widefat" type="url" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_menu_url]" value="<?php echo esc_attr( (string) $settings['local_business_menu_url'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-local-business-cuisine"><?php esc_html_e( 'Cuisine served', 'easyrankly' ); ?></label>
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
	</div>
	<?php
}
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
				<label>
					<input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[local_business_hours][<?php echo esc_attr( $day ); ?>][closed]" value="1" <?php checked( $closed ); ?> data-erankly-day-closed aria-label="<?php echo esc_attr( sprintf( /* translators: %s: weekday name. */ __( '%s closed', 'easyrankly' ), $label ) ); ?>">
					<?php esc_html_e( 'Closed', 'easyrankly' ); ?>
				</label>
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
	$summary_id        = $tabs_id . '-linked-summary';
	$toggle_id         = 'erankly-' . sanitize_key( $setting_key ) . '-linked';
	$tabs_label        = $is_taxonomy ? __( 'Default metadata by taxonomy', 'easyrankly' ) : __( 'Default metadata by content type', 'easyrankly' );
	$toggle_base_label = __( 'Same for all', 'easyrankly' );
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
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" <?php echo $is_linked ? 'role="group" aria-labelledby="' . esc_attr( $summary_id ) . '"' : 'role="tablist" aria-label="' . esc_attr( $tabs_label ) . '"'; ?> data-erankly-tabs-label="<?php echo esc_attr( $tabs_label ); ?>" data-erankly-linked-summary-id="<?php echo esc_attr( $summary_id ); ?>" data-erankly-sliding-tabs>
				<span class="erankly-tab erankly-linked-tabs-summary" id="<?php echo esc_attr( $summary_id ); ?>" <?php echo $is_linked ? '' : 'hidden'; ?>><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $objects as $key => $object ) :
					$label         = $object instanceof WP_Taxonomy ? erankly_get_taxonomy_admin_label( $object ) : $object->labels->singular_name;
					$tab_key       = sanitize_key( $setting_key . '-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'hidden tabindex="-1"' : ''; ?>><?php echo esc_html( $label ); ?></button>
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
			$sample_post = $is_taxonomy ? null : erankly_get_sample_post_for_type( (string) $key );
			$sample_term = $is_taxonomy ? erankly_get_sample_term_for_taxonomy( (string) $key ) : null;
			$examples    = erankly_get_admin_variable_examples( $sample_post, $sample_term );
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $is_linked && $is_first ? $summary_id : $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<?php erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap ); ?>
					<?php erankly_render_global_advanced_robot_preservation( $setting_key, (string) $key, $row ); ?>
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
 * Renders the Schema.org types used for each post type. Kept out of the post type defaults tabs on purpose:
 * those share one title and description across content types when "Same for all" is on, which hid these two
 * fields for every type but the first, while the types themselves are never shared.
 */
function erankly_render_post_type_schema_types(): void {
	$post_types = erankly_get_public_post_types();

	if ( empty( $post_types ) ) {
		echo '<p class="description">' . esc_html__( 'No public content types available.', 'easyrankly' ) . '</p>';

		return;
	}

	$webpage_types = erankly_get_webpage_schema_types();
	$article_types = erankly_get_article_schema_types();
	?>
	<div class="erankly-post-type-schema">
		<?php foreach ( $post_types as $post_type => $object ) : ?>
			<?php
			$post_type    = (string) $post_type;
			$id_prefix    = 'erankly-post-type-schema-' . sanitize_key( $post_type );
			$name_prefix  = ERANKLY_OPTION . '[global_post_type_schema][' . $post_type . ']';
			$webpage_type = erankly_get_post_type_schema_type( $post_type, 'webpage_type' );
			$article_type = erankly_get_post_type_schema_type( $post_type, 'article_type' );
			?>
			<fieldset class="erankly-post-type-schema-row">
				<legend class="erankly-post-type-schema-label" id="<?php echo esc_attr( $id_prefix ); ?>-legend"><?php echo esc_html( $object->labels->singular_name ); ?></legend>
				<div class="erankly-inline-fields erankly-inline-fields-two-columns">
					<div class="erankly-field">
						<label id="<?php echo esc_attr( $id_prefix ); ?>-webpage-label" for="<?php echo esc_attr( $id_prefix ); ?>-webpage"><?php esc_html_e( 'Page type', 'easyrankly' ); ?></label>
						<?php erankly_render_schema_type_select( $id_prefix . '-webpage', $name_prefix . '[webpage_type]', $webpage_types, '' !== $webpage_type ? $webpage_type : 'WebPage', array( $id_prefix . '-legend', $id_prefix . '-webpage-label' ) ); ?>
					</div>
					<div class="erankly-field">
						<label id="<?php echo esc_attr( $id_prefix ); ?>-article-label" for="<?php echo esc_attr( $id_prefix ); ?>-article"><?php esc_html_e( 'Article type', 'easyrankly' ); ?></label>
						<?php erankly_render_schema_type_select( $id_prefix . '-article', $name_prefix . '[article_type]', $article_types, '' !== $article_type ? $article_type : 'none', array( $id_prefix . '-legend', $id_prefix . '-article-label' ) ); ?>
					</div>
				</div>
			</fieldset>
		<?php endforeach; ?>
	</div>
	<p class="description"><?php esc_html_e( 'Article, Blog posting and News article are equivalent for Google: the choice that matters is whether an article node is emitted at all. Leave it off for content that is not an article, such as landing pages.', 'easyrankly' ); ?></p>
	<?php
}

/**
 * Renders one Schema.org type dropdown. A stored type that is missing from the list is added as an extra
 * option, so a value imported from another SEO plugin is not dropped the first time the panel is saved.
 *
 * @param array<string,string> $choices Value => label.
 * @param array<int,string>    $labelled_by Optional element IDs for aria-labelledby.
 */
function erankly_render_schema_type_select( string $id, string $name, array $choices, string $value, array $labelled_by = array() ): void {
	if ( '' !== $value && ! isset( $choices[ $value ] ) ) {
		$choices[ $value ] = $value;
	}
	$labelled_by = array_values( array_filter( array_map( 'sanitize_html_class', $labelled_by ) ) );
	?>
	<select id="<?php echo esc_attr( $id ); ?>" class="widefat" name="<?php echo esc_attr( $name ); ?>"<?php echo ! empty( $labelled_by ) ? ' aria-labelledby="' . esc_attr( implode( ' ', $labelled_by ) ) . '"' : ''; ?>>
		<?php foreach ( $choices as $choice => $label ) : ?>
			<option value="<?php echo esc_attr( (string) $choice ); ?>" <?php selected( $value, (string) $choice ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}
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
	$is_linked = ( ! array_key_exists( 'social_defaults_linked', $settings ) || ! empty( $settings['social_defaults_linked'] ) )
		&& $og_title === $twitter_title
		&& $og_description === $twitter_description;
	$toggle_base_label = __( 'Same for all', 'easyrankly' );
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
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="erankly-social-defaults-tabs" <?php echo $is_linked ? 'role="group" aria-labelledby="erankly-social-defaults-linked-summary"' : 'role="tablist" aria-label="' . esc_attr__( 'Default social metadata by network', 'easyrankly' ) . '"'; ?> data-erankly-tabs-label="<?php esc_attr_e( 'Default social metadata by network', 'easyrankly' ); ?>" data-erankly-linked-summary-id="erankly-social-defaults-linked-summary" data-erankly-sliding-tabs>
				<span class="erankly-tab erankly-linked-tabs-summary" id="erankly-social-defaults-linked-summary" <?php echo $is_linked ? '' : 'hidden'; ?>><?php echo esc_html( $linked_panel_label ); ?></span>
				<?php
				$is_first = true;
				foreach ( $networks as $key => $network ) :
					$tab_key       = sanitize_key( 'social-defaults-' . $key );
					$panel_id      = 'erankly-' . $tab_key . '-panel';
					$tab_id        = 'erankly-' . $tab_key . '-tab';
					$is_tab_active = $is_first && ! $is_linked;
					?>
					<button type="button" class="nav-tab erankly-tab <?php echo $is_tab_active ? 'nav-tab-active is-active' : ''; ?>" id="<?php echo esc_attr( $tab_id ); ?>" role="tab" aria-selected="<?php echo $is_tab_active ? 'true' : 'false'; ?>" aria-disabled="<?php echo $is_linked ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" data-erankly-tab="<?php echo esc_attr( $tab_key ); ?>" <?php disabled( $is_linked ); ?> <?php echo $is_linked ? 'hidden tabindex="-1"' : ''; ?>><?php echo esc_html( $network['label'] ); ?></button>
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
		$examples = erankly_get_admin_variable_examples( erankly_get_sample_post_for_type( 'post' ) );
		$is_first = true;
		foreach ( $networks as $key => $network ) :
			$title       = isset( $settings[ $network['title_key'] ] ) ? (string) $settings[ $network['title_key'] ] : '';
			$description = isset( $settings[ $network['description_key'] ] ) ? (string) $settings[ $network['description_key'] ] : '';
			$panel_key   = sanitize_key( 'social-defaults-' . $key );
			$panel_id    = 'erankly-' . $panel_key . '-panel';
			$tab_id      = 'erankly-' . $panel_key . '-tab';
			?>
			<div class="erankly-tab-panel erankly-default-tab-panel <?php echo $is_first ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $panel_id ); ?>" role="tabpanel" aria-labelledby="<?php echo esc_attr( $is_linked && $is_first ? 'erankly-social-defaults-linked-summary' : $tab_id ); ?>" data-erankly-panel="<?php echo esc_attr( $panel_key ); ?>" <?php echo $is_first ? '' : 'hidden'; ?>>
				<div class="erankly-global-meta-default">
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-title"><?php esc_html_e( 'Default title', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $network['id_prefix'] ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $network['title_key'] ); ?>]" value="<?php echo esc_attr( $title ); ?>" data-erankly-linked-field="title">
							<?php erankly_render_variable_picker( $examples ); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $network['id_prefix'] ); ?>-description"><?php esc_html_e( 'Default description', 'easyrankly' ); ?></label>
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
function erankly_render_special_page_defaults( array $entities, array $settings ): void {
	if ( empty( $entities ) || erankly_use_site_editor_special_page_panels() ) {
		return;
	}
	$setting_key = 'global_special_meta';
	$values      = isset( $settings[ $setting_key ] ) && is_array( $settings[ $setting_key ] ) ? $settings[ $setting_key ] : array();
	erankly_render_special_page_defaults_group( $entities, $values, $setting_key, 'all', __( 'Default metadata by WordPress context', 'easyrankly' ) );
}
function erankly_render_special_page_defaults_group( array $entities, array $values, string $setting_key, string $group_key, string $aria_label ): void {
	$tabs_id   = 'erankly-' . sanitize_key( $setting_key . '-' . $group_key ) . '-tabs';
	$is_simple = (bool) erankly_get_setting( 'simplified_mode', 1 );
	?>
	<div class="erankly-default-tabs erankly-entity-default-tabs erankly-special-page-default-tabs" data-erankly-tabs-root>
		<div class="erankly-default-tabs-bar">
			<div class="nav-tab-wrapper wp-clearfix erankly-tabs" id="<?php echo esc_attr( $tabs_id ); ?>" role="tablist" aria-label="<?php echo esc_attr( $aria_label ); ?>" data-erankly-sliding-tabs>
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
		foreach ( array_keys( $entities ) as $key ) :
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
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-title"><?php esc_html_e( 'Meta title', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="<?php echo esc_attr( $id_prefix ); ?>-title" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $title ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="<?php echo esc_attr( $id_prefix ); ?>-description"><?php esc_html_e( 'Meta description', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="<?php echo esc_attr( $id_prefix ); ?>-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[<?php echo esc_attr( $setting_key ); ?>][<?php echo esc_attr( $key ); ?>][description]"><?php echo esc_textarea( $description ); ?></textarea>
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<?php
					erankly_render_global_visibility_defaults( $setting_key, (string) $key, $noindex, $nofollow, $noarchive, $disable_sitemap, 'author' === (string) $key );
					erankly_render_global_advanced_robot_preservation( $setting_key, (string) $key, $row );
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
	<div class="erankly-defaults-section">
		<h4><?php esc_html_e( 'Social sharing', 'easyrankly' ); ?></h4>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-title"><?php esc_html_e( 'Social title', 'easyrankly' ); ?></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-og-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[og_title]" value="<?php echo esc_attr( $og_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-og-description"><?php esc_html_e( 'Social description', 'easyrankly' ); ?></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-og-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[og_description]"><?php echo esc_textarea( $og_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-title"><?php esc_html_e( 'X (Twitter) title', 'easyrankly' ); ?></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<input id="<?php echo esc_attr( $id_prefix ); ?>-twitter-title" class="widefat" type="text" name="<?php echo esc_attr( $name ); ?>[twitter_title]" value="<?php echo esc_attr( $tw_title ); ?>">
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-twitter-description"><?php esc_html_e( 'X (Twitter) description', 'easyrankly' ); ?></label>
			<div class="erankly-variable-field" data-erankly-variable-field>
				<textarea id="<?php echo esc_attr( $id_prefix ); ?>-twitter-description" class="widefat" rows="3" name="<?php echo esc_attr( $name ); ?>[twitter_description]"><?php echo esc_textarea( $tw_description ); ?></textarea>
				<?php erankly_render_variable_picker(); ?>
			</div>
		</div>
		<div class="erankly-field">
			<label for="<?php echo esc_attr( $id_prefix ); ?>-social-image"><?php esc_html_e( 'Social image', 'easyrankly' ); ?></label>
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
function erankly_render_global_visibility_defaults( string $setting_key, string $entity_key, bool $noindex, bool $nofollow, bool $noarchive, bool $disable_sitemap, bool $show_disable_sitemap = true ): void {
	$name_prefix = ERANKLY_OPTION . '[' . $setting_key . '][' . $entity_key . ']';
	$is_simple   = (bool) erankly_get_setting( 'simplified_mode', 1 );
	$is_hidden = $show_disable_sitemap ? ( $noindex && $disable_sitemap ) : $noindex;
	?>
	<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
		<legend><?php esc_html_e( 'Visibility defaults', 'easyrankly' ); ?></legend>
		<div class="erankly-checkbox-options">
			<?php if ( $is_simple ) : ?>
				<label><input type="checkbox" class="erankly-toggle" data-erankly-linked-field="hide_from_search_results" name="<?php echo esc_attr( $name_prefix ); ?>[hide_from_search_results]" value="1" <?php checked( $is_hidden ); ?>> <?php esc_html_e( 'Hide from search results', 'easyrankly' ); ?></label>
				<input type="hidden" data-erankly-linked-field="nofollow" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="<?php echo $nofollow ? '1' : '0'; ?>">
				<input type="hidden" data-erankly-linked-field="noarchive" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="<?php echo $noarchive ? '1' : '0'; ?>">
			<?php else : ?>
				<label><input type="checkbox" class="erankly-toggle" data-erankly-linked-field="noindex" name="<?php echo esc_attr( $name_prefix ); ?>[noindex]" value="1" <?php checked( $noindex ); ?>> <?php esc_html_e( 'Noindex', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" data-erankly-linked-field="nofollow" name="<?php echo esc_attr( $name_prefix ); ?>[nofollow]" value="1" <?php checked( $nofollow ); ?>> <?php esc_html_e( 'Nofollow', 'easyrankly' ); ?></label>
				<label><input type="checkbox" class="erankly-toggle" data-erankly-linked-field="noarchive" name="<?php echo esc_attr( $name_prefix ); ?>[noarchive]" value="1" <?php checked( $noarchive ); ?>> <?php esc_html_e( 'Noarchive', 'easyrankly' ); ?></label>
				<?php if ( $show_disable_sitemap ) : ?>
				<label><input type="checkbox" class="erankly-toggle" data-erankly-linked-field="disable_sitemap" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="1" <?php checked( $disable_sitemap ); ?>> <?php esc_html_e( 'Disable sitemap', 'easyrankly' ); ?></label>
				<?php else : ?>
				<?php // No checkbox for this entity (author archives): without a field the panel would submit an empty directive and silently clear the stored flag. ?>
				<input type="hidden" data-erankly-linked-field="disable_sitemap" name="<?php echo esc_attr( $name_prefix ); ?>[disable_sitemap]" value="<?php echo $disable_sitemap ? '1' : '0'; ?>">
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</fieldset>
	<?php
}
function erankly_render_global_advanced_robot_preservation( string $setting_key, string $entity_key, array $row ): void {
	$name_prefix = ERANKLY_OPTION . '[' . $setting_key . '][' . $entity_key . ']';
	$keys        = array(
		'index_directive',
		'follow_directive',
		'archive_directive',
		'snippet_directive',
		'image_directive',
		'notranslate',
		'indexifembedded',
		'max_snippet',
		'max_video_preview',
		'max_image_preview',
	);
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $row ) || ! is_scalar( $row[ $key ] ) ) {
			continue;
		}
		?>
		<input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $row[ $key ] ); ?>">
		<?php
	}
}
