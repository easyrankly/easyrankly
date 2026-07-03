<?php
/**
 * Settings page — per-panel renderers.
 *
 * Each function renders one tab panel of the EasyRankly settings screen. The
 * markup was extracted verbatim from erankly_render_settings_page() to keep
 * that orchestrator small; the generated output is unchanged.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Features settings panel.
 *
 * @param array<string,mixed> $settings             Plugin settings.
 * @param bool                $redirects_enabled    Whether the redirect module is enabled.
 * @param bool                $sitemap_enabled      Whether the sitemap module is enabled.
 * @param bool                $health_enabled       Whether the Health module is enabled.
 * @param bool                $multilingual_enabled Whether the multilingual feature is enabled.
 * @param string              $active_panel         Active panel ID.
 * @return void
 */
function erankly_render_settings_panel_features( array $settings, bool $redirects_enabled, bool $sitemap_enabled, bool $health_enabled, bool $multilingual_enabled, string $active_panel ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
				<div class="erankly-tab-panel<?php echo 'settings-features' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-features" role="tabpanel" aria-labelledby="erankly-settings-tab-features" data-erankly-settings-panel="settings-features" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> <?php echo 'settings-features' === $active_panel ? '' : 'hidden'; ?>>
					<?php if ( $autosave_active ) : ?>
					<?php endif; ?>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Feature modules', 'easyrankly' ); ?></h3>
						<div class="erankly-settings-fields erankly-card">
						<fieldset class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_redirects]" value="1" <?php checked( $redirects_enabled ); ?>> <strong><?php esc_html_e( 'Enable the redirect manager', 'easyrankly' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Activates the redirect engine. Manage rules from the Redirects tab.', 'easyrankly' ); ?></p>
						</fieldset>
						<fieldset class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_sitemap]" value="1" <?php checked( $sitemap_enabled ); ?>> <strong><?php esc_html_e( 'Enable the sitemap module', 'easyrankly' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Activates the XML sitemap generator and replaces WordPress core sitemaps. Configure from the Sitemap tab.', 'easyrankly' ); ?></p>
						</fieldset>
						<fieldset class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_health]" value="1" <?php checked( $health_enabled ); ?>> <strong><?php esc_html_e( 'Enable Health monitoring', 'easyrankly' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Activates site health monitoring. Review findings from the Health tab.', 'easyrankly' ); ?></p>
						</fieldset>
						<?php if ( function_exists( 'erankly_ai_render_settings_field' ) ) : ?>
							<?php erankly_ai_render_settings_field(); ?>
						<?php endif; ?>
						<fieldset class="erankly-field erankly-checkboxes">
							<span style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_multilingual]" value="1" <?php checked( $multilingual_enabled ); ?> <?php disabled( ! is_multisite() ); ?>> <strong><?php esc_html_e( 'Enable multilingual', 'easyrankly' ); ?></strong></label>
								<?php
								if ( ! is_multisite() ) {
									erankly_render_multisite_status(); }
								?>
							</span>
							<p class="description"><?php esc_html_e( 'Lets visitors switch between translated versions of your content across sites. Requires WordPress Multisite.', 'easyrankly' ); ?></p>
						</fieldset>
						</div>
					</div>
				</div>
	<?php
}

/**
 * Renders the General settings panel.
 *
 * @param array<string,mixed> $settings                 Plugin settings.
 * @param int                 $schema_person_user_id    Selected Person schema user ID.
 * @param WP_User|false       $schema_person_user       Selected Person schema user, or false.
 * @param bool                $show_organization_fields Whether to show Organization-only fields.
 * @param string              $active_panel             Active panel ID.
 * @return void
 */
function erankly_render_settings_panel_general( array $settings, int $schema_person_user_id, $schema_person_user, bool $show_organization_fields, string $active_panel ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
				<div class="erankly-tab-panel<?php echo 'settings-general' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-general" role="tabpanel" aria-labelledby="erankly-settings-tab-general" data-erankly-settings-panel="settings-general" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> <?php echo 'settings-general' === $active_panel ? '' : 'hidden'; ?>>
					<?php if ( $autosave_active ) : ?>
					<?php endif; ?>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Site identity', 'easyrankly' ); ?></h3>
						<div class="erankly-settings-fields erankly-card">
					<div class="erankly-field">
						<label for="erankly-organization-name"><strong><?php esc_html_e( 'Organization or person name', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-organization-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_name]" value="<?php echo esc_attr( (string) $settings['organization_name'] ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
						<div class="erankly-schema-identity-fields<?php echo 'person' === $settings['schema_identity'] ? ' is-person' : ''; ?>" data-erankly-schema-identity-fields>
						<div class="erankly-field">
							<label for="erankly-schema-identity"><strong><?php esc_html_e( 'Identity type', 'easyrankly' ); ?></strong></label>
							<select id="erankly-schema-identity" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_identity]" data-erankly-schema-identity>
								<option value="organization" <?php selected( $settings['schema_identity'], 'organization' ); ?>><?php esc_html_e( 'Organization', 'easyrankly' ); ?></option>
								<option value="person" <?php selected( $settings['schema_identity'], 'person' ); ?>><?php esc_html_e( 'Person', 'easyrankly' ); ?></option>
							</select>
						</div>
						<div class="erankly-field" data-erankly-person-reference-field <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>>
							<label><strong><?php esc_html_e( 'Person reference user', 'easyrankly' ); ?></strong></label>
							<div class="erankly-user-search-wrap" data-erankly-user-search-wrap>
								<input type="hidden"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_person_user_id]"
									value="<?php echo esc_attr( (string) $schema_person_user_id ); ?>"
									data-erankly-user-id>
								<div class="erankly-autocomplete-control erankly-user-control">
									<div class="erankly-autocomplete-value erankly-user-selected" data-erankly-user-selected<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>>
										<input type="text"
											class="widefat erankly-user-selected-input"
											readonly
											value="<?php echo ( $schema_person_user instanceof WP_User ) ? esc_attr( sprintf( /* translators: 1: User display name, 2: User ID. */ __( '%1$s (ID: %2$d)', 'easyrankly' ), $schema_person_user->display_name, $schema_person_user->ID ) ) : ''; ?>"
											data-erankly-user-selected-name>
									</div>
									<div class="erankly-autocomplete-search erankly-user-search" data-erankly-user-search-input-wrap<?php echo ( $schema_person_user instanceof WP_User ) ? ' hidden' : ''; ?>>
										<input type="search"
											class="widefat erankly-user-search-input"
											placeholder="<?php esc_attr_e( 'Search users…', 'easyrankly' ); ?>"
											autocomplete="off"
											aria-autocomplete="list"
											aria-label="<?php esc_attr_e( 'Search users', 'easyrankly' ); ?>"
											data-erankly-user-search-input>
										<ul class="erankly-autocomplete-results erankly-user-results" role="listbox" hidden data-erankly-user-results></ul>
									</div>
									<button type="button" class="button erankly-user-remove" data-erankly-user-remove<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
								</div>
							</div>
							</div>
							<p class="description erankly-schema-person-reference-description" data-erankly-person-reference-description <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Uses the selected WordPress profile for the global Person JSON-LD schema.', 'easyrankly' ); ?></p>
						</div>
						<div data-erankly-organization-only <?php echo $show_organization_fields ? '' : 'hidden'; ?>>
							<div class="erankly-field">
								<label for="erankly-organization-description"><strong><?php esc_html_e( 'Organization description', 'easyrankly' ); ?></strong></label>
								<textarea id="erankly-organization-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_description]"><?php echo esc_textarea( (string) $settings['organization_description'] ); ?></textarea>
							</div>
							<div class="erankly-inline-fields erankly-inline-fields-two-columns">
								<div class="erankly-field">
									<label for="erankly-organization-email"><strong><?php esc_html_e( 'Business email', 'easyrankly' ); ?></strong></label>
									<input id="erankly-organization-email" class="widefat" type="email" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_email]" value="<?php echo esc_attr( (string) $settings['organization_email'] ); ?>">
								</div>
								<div class="erankly-field">
									<label for="erankly-organization-phone"><strong><?php esc_html_e( 'Business telephone', 'easyrankly' ); ?></strong></label>
									<input id="erankly-organization-phone" class="widefat" type="tel" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_phone]" value="<?php echo esc_attr( (string) $settings['organization_phone'] ); ?>" placeholder="+1 555 123 4567">
									<p class="description"><?php esc_html_e( 'Include country and area codes.', 'easyrankly' ); ?></p>
								</div>
							</div>
							<?php erankly_render_organization_details( $settings ); ?>
						</div>
					</div>
					</div>

				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Post type defaults', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<?php erankly_render_global_meta_defaults( 'global_post_type_meta', erankly_get_public_post_types(), $settings ); ?>
					</div>
				</div>

				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Taxonomy defaults', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<?php erankly_render_global_meta_defaults( 'global_taxonomy_meta', erankly_get_public_taxonomies(), $settings ); ?>
					</div>
				</div>

						<?php if ( is_multisite() ) : ?>
								<div class="erankly-settings-section" data-erankly-multisite-special-pages-section <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
									<h3 class="erankly-section-title"><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h3>
									<div class="erankly-card">
										<p class="description"><?php esc_html_e( 'Special pages and archives are configured individually on each site: use the Site Editor with block themes on WordPress 6.6 or later, or Settings → EasyRankly otherwise.', 'easyrankly' ); ?></p>
									</div>
								</div>
					<?php elseif ( erankly_use_site_editor_special_page_panels() ) : ?>
						<input type="hidden" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[preserve_global_special_meta]" value="1">
					<?php else : ?>
						<div class="erankly-settings-section">
							<h3 class="erankly-section-title"><?php esc_html_e( 'Special pages and archives', 'easyrankly' ); ?></h3>
							<div class="erankly-card">
								<?php erankly_render_special_page_defaults( erankly_special_page_keys(), $settings ); ?>
							</div>
						</div>
					<?php endif; ?>
			</div>
	<?php
}

/**
 * Renders the Social settings panel.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_settings_panel_social( array $settings ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-social" role="tabpanel" aria-labelledby="erankly-settings-tab-social" data-erankly-settings-panel="settings-social" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
				<?php if ( $autosave_active ) : ?>
				<?php endif; ?>
				<div class="erankly-settings-fields">
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Default images', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
					<div class="erankly-field">
						<label for="erankly-organization-logo-url"><strong><?php esc_html_e( 'Organization logo', 'easyrankly' ); ?></strong></label>
						<?php
						$organization_logo_id  = absint( $settings['organization_logo'] );
						$organization_logo_url = isset( $settings['organization_logo_url'] ) ? (string) $settings['organization_logo_url'] : '';

						if ( '' === $organization_logo_url && $organization_logo_id > 0 ) {
							$organization_logo_url = erankly_get_image_url( $organization_logo_id, 'full' );
						}

						if ( '' === $organization_logo_url ) {
							$organization_logo_url = erankly_default_organization_logo_url_template();
						}

						erankly_render_media_url_field(
							'erankly-organization-logo-url',
							ERANKLY_OPTION . '[organization_logo_url]',
							$organization_logo_url,
							erankly_default_organization_logo_placeholder(),
							ERANKLY_OPTION . '[organization_logo]',
							$organization_logo_id,
							false
						);
						?>
					</div>
					<div class="erankly-field">
						<label for="erankly-default-social-image-url"><strong><?php esc_html_e( 'Default social image URL', 'easyrankly' ); ?></strong></label>
						<?php
						erankly_render_media_url_field(
							'erankly-default-social-image-url',
							ERANKLY_OPTION . '[default_social_image_url]',
							(string) $settings['default_social_image_url'],
							erankly_default_social_image_placeholder(),
							ERANKLY_OPTION . '[default_og_image]',
							absint( $settings['default_og_image'] ),
							false
						);
						?>
					</div>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Social defaults', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<?php erankly_render_social_meta_defaults( $settings ); ?>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Social profiles', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
						<div class="erankly-field">
							<label for="erankly-twitter-site"><strong><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></strong></label>
							<input id="erankly-twitter-site" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[twitter_site]" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example">
							<p class="description"><?php esc_html_e( 'Used for the twitter:site meta tag.', 'easyrankly' ); ?></p>
						</div>
						<div class="erankly-field">
							<label for="erankly-social-profiles"><strong><?php esc_html_e( 'Social profiles', 'easyrankly' ); ?></strong></label>
							<textarea id="erankly-social-profiles" class="widefat" rows="5" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_profiles]"><?php echo esc_textarea( (string) $settings['social_profiles'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One absolute URL per line.', 'easyrankly' ); ?></p>
						</div>
						</div>
					</div>
					<?php if ( empty( $settings['bloat_remove_oembed'] ) ) : ?>
					<div class="erankly-settings-section" data-erankly-oembed-json-section <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
						<h3 class="erankly-section-title"><?php esc_html_e( 'oEmbed JSON', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<p class="description"><?php esc_html_e( 'Active by default on every public page. EasyRankly outputs an oEmbed JSON discovery link (e.g. for LinkedIn) so platforms can fetch rich link-preview data. To disable it, enable "Remove oEmbed discovery links" in the Bloat tab.', 'easyrankly' ); ?></p>
						</div>
					</div>
					<?php endif; ?>
				</div>
			</div>
	<?php
}

/**
 * Renders the Schema settings panel.
 *
 * @param array<string,mixed> $settings             Plugin settings.
 * @param array<int,mixed>    $global_schema_blocks Configured global schema blocks.
 * @param string              $global_schema_name   Schema blocks field name prefix.
 * @return void
 */
function erankly_render_settings_panel_schema( array $settings, array $global_schema_blocks, string $global_schema_name ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-schema" role="tabpanel" aria-labelledby="erankly-settings-tab-schema" data-erankly-settings-panel="settings-schema" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
				<?php if ( $autosave_active ) : ?>
				<?php endif; ?>
				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Schema basics', 'easyrankly' ); ?></h3>
					<div class="erankly-settings-fields erankly-card">
					<fieldset class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_breadcrumbs]" value="1" <?php checked( $settings['enable_breadcrumbs'], 1 ); ?>> <strong><?php esc_html_e( 'Enable breadcrumbs function', 'easyrankly' ); ?></strong></label>
							<p class="description"><?php esc_html_e( 'Outputs BreadcrumbList schema and enables the breadcrumbs template function.', 'easyrankly' ); ?></p>
					</fieldset>
						<?php erankly_render_local_business_settings( $settings ); ?>
					</div>
				</div>
				<div class="erankly-settings-section" data-erankly-post-date-settings-section <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
					<h3 class="erankly-section-title"><?php esc_html_e( 'Post Date Settings', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<p class="description"><?php esc_html_e( 'Active by default on every page of the site. EasyRankly includes published and modified dates in automatic post schema when post data is available.', 'easyrankly' ); ?></p>
					</div>
				</div>
				<div class="erankly-settings-section" data-erankly-custom-schema-section <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
					<h3 class="erankly-section-title"><?php esc_html_e( 'Custom JSON-LD Schema', 'easyrankly' ); ?></h3>
					<div class="erankly-schema-builder erankly-card" data-erankly-schema-builder data-erankly-next-index="<?php echo esc_attr( (string) count( $global_schema_blocks ) ); ?>">
						<div class="erankly-schema-blocks <?php echo empty( $global_schema_blocks ) ? 'is-empty' : ''; ?>" data-erankly-schema-blocks>
							<?php foreach ( $global_schema_blocks as $index => $block ) : ?>
								<?php erankly_render_schema_block( is_array( $block ) ? $block : array(), (string) $index, $global_schema_name, true ); ?>
							<?php endforeach; ?>
						</div>

						<template data-erankly-schema-template>
							<?php erankly_render_schema_block( array(), '__INDEX__', $global_schema_name, true ); ?>
						</template>

						<p class="erankly-schema-actions"><button type="button" class="button button-secondary" data-erankly-add-schema><?php esc_html_e( 'Add Schema', 'easyrankly' ); ?></button></p>
					</div>
				</div>
			</div>
	<?php
}

/**
 * Renders the Sitemap settings panel.
 *
 * @param array<string,mixed> $settings    Plugin settings.
 * @param string              $sitemap_url Absolute wp-sitemap.xml URL.
 * @return void
 */
function erankly_render_settings_panel_sitemap( array $settings, string $sitemap_url ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-sitemap" role="tabpanel" aria-labelledby="erankly-settings-tab-sitemap" data-erankly-settings-panel="settings-sitemap" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
				<?php if ( $autosave_active ) : ?>
				<?php endif; ?>
				<div class="erankly-settings-fields">
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'XML sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<fieldset class="erankly-field erankly-checkboxes">
								<legend class="screen-reader-text"><?php esc_html_e( 'XML sitemap', 'easyrankly' ); ?></legend>
								<p class="description">
									<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open wp-sitemap.xml', 'easyrankly' ); ?></a>
								</p>
								<p class="description"><?php esc_html_e( 'Author sitemap: included only when at least two authors have sitemap-eligible published content. On single-author sites it is disabled to avoid duplicate archive URLs for SEO.', 'easyrankly' ); ?></p>
								<p class="description"><?php esc_html_e( 'Image, Video and News sitemaps are integrated directly into the core wp-sitemap.xml index when enabled.', 'easyrankly' ); ?></p>
							</fieldset>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Google News sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<fieldset class="erankly-field erankly-checkboxes">
							<legend class="screen-reader-text"><?php esc_html_e( 'Google News sitemap', 'easyrankly' ); ?></legend>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_news_sitemap]" value="1" <?php checked( $settings['enable_news_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate Google News sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-news-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-news-1.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Includes only posts (post type: post) published in the last 48 hours. Submitting a News sitemap does not guarantee inclusion in Google News — editorial review by Google is still required.', 'easyrankly' ); ?></p>
							<div class="erankly-field erankly-visibility-defaults">
								<p><strong><?php esc_html_e( 'Included post types', 'easyrankly' ); ?></strong></p>
								<div class="erankly-checkbox-options">
									<?php
									$news_post_types = (array) erankly_get_setting( 'news_sitemap_post_types', array( 'post' ) );
									foreach ( erankly_get_public_post_types() as $post_type => $object ) :
										?>
										<label>
											<input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_sitemap_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $news_post_types, true ) ); ?>>
											<?php echo esc_html( $object->labels->singular_name ); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
							<div class="erankly-field">
								<label for="erankly-news-publication-name"><strong><?php esc_html_e( 'News publication name', 'easyrankly' ); ?></strong></label>
								<input
									id="erankly-news-publication-name"
									class="widefat"
									type="text"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_publication_name]"
									value="<?php echo esc_attr( (string) $settings['news_publication_name'] ); ?>"
									maxlength="200"
								>
								<p class="description">
									<?php esc_html_e( 'The publication name to include in the Google News sitemap. Leave blank to use the organization name or the site title. An empty name will prevent the sitemap from being generated.', 'easyrankly' ); ?>
								</p>
							</div>
							</fieldset>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Image sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<fieldset class="erankly-field erankly-checkboxes">
							<legend class="screen-reader-text"><?php esc_html_e( 'Image sitemap', 'easyrankly' ); ?></legend>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_image_sitemap]" value="1" <?php checked( $settings['enable_image_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate image sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-image-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-image-1.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Associates images with the public pages that contain them. Images are extracted from post content (featured image, embedded images, Gutenberg image/gallery blocks). Attachment pages are not used as page URLs.', 'easyrankly' ); ?></p>
							</fieldset>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Video sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<fieldset class="erankly-field erankly-checkboxes">
							<legend class="screen-reader-text"><?php esc_html_e( 'Video sitemap', 'easyrankly' ); ?></legend>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_video_sitemap]" value="1" <?php checked( $settings['enable_video_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate video sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-video-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-video-1.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Includes published posts that contain YouTube, Vimeo or self-hosted HTML5 videos. Multiple videos on the same page are each included. Submitting a Video sitemap does not guarantee Google indexing; the embedded player must also be crawlable.', 'easyrankly' ); ?></p>
							</fieldset>
						</div>
					</div>
				</div>
			</div>
	<?php
}

/**
 * Renders the Settings settings panel.
 *
 * @param array<string,mixed> $settings          Plugin settings.
 * @param bool                $redirects_enabled Whether the redirect module is enabled.
 * @return void
 */
function erankly_render_settings_panel_settings( array $settings, bool $redirects_enabled ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-settings" role="tabpanel" aria-labelledby="erankly-settings-tab-settings" data-erankly-settings-panel="settings-settings" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
				<?php if ( $autosave_active ) : ?>
				<?php endif; ?>
				<?php if ( function_exists( 'erankly_reset_render_notice' ) ) : ?>
					<?php erankly_reset_render_notice(); ?>
				<?php endif; ?>
				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Preferences', 'easyrankly' ); ?></h3>
					<div class="erankly-settings-fields erankly-card">
					<fieldset class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[simplified_mode]" value="1" <?php checked( $settings['simplified_mode'], 1 ); ?>> <strong><?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Shows the essential controls and automates advanced SEO defaults.', 'easyrankly' ); ?></p>
					</fieldset>
					<fieldset class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_seo_checklist]" value="1" <?php checked( erankly_seo_checklist_preference_enabled() ); ?>> <strong><?php esc_html_e( 'Show the SEO checklist', 'easyrankly' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Shows a floating checklist (meta title, meta description, featured image) in the editor and frontend. Requires Simplified mode.', 'easyrankly' ); ?></p>
					</fieldset>
					<fieldset class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[add_head_credit]" value="1" <?php checked( empty( $settings['hide_head_credit'] ) ); ?>> <strong><?php esc_html_e( 'Add the "optimized with EasyRankly" comment to the page source', 'easyrankly' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Wraps the plugin\'s <head> output in an HTML comment that identifies EasyRankly in the page source.', 'easyrankly' ); ?></p>
					</fieldset>
					<fieldset class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[resolve_placeholders]" value="1" <?php checked( ! empty( $settings['resolve_placeholders'] ) ); ?>> <strong><?php esc_html_e( 'Show resolved values for variables like {{site_name}}', 'easyrankly' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Displays the resolved value (e.g. your site name) in editor fields instead of the raw {{variable}} tag. Click the field to edit the raw tag again.', 'easyrankly' ); ?></p>
					</fieldset>
					<?php if ( $redirects_enabled ) : ?>
					<fieldset class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[redirect_exclude_admins]" value="1" <?php checked( ! empty( $settings['redirect_exclude_admins'] ) ); ?>> <strong><?php esc_html_e( 'Do not apply any redirect to administrators', 'easyrankly' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Exempts users with the "manage_options" capability (typically Administrators) from all redirects.', 'easyrankly' ); ?></p>
					</fieldset>
					<?php endif; ?>
					</div>
				</div>

				<?php if ( function_exists( 'erankly_reset_render_panel' ) ) : ?>
					<?php erankly_reset_render_panel(); ?>
				<?php endif; ?>
			</div>
	<?php
}

/**
 * Renders the Advanced settings panel.
 *
 * @param array<string,mixed> $settings Plugin settings.
 * @return void
 */
function erankly_render_settings_panel_advanced( array $settings ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
			<div class="erankly-tab-panel" id="erankly-settings-panel-advanced" role="tabpanel" aria-labelledby="erankly-settings-tab-advanced" data-erankly-settings-panel="settings-advanced" data-erankly-advanced-panel <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
				<?php if ( $autosave_active ) : ?>
				<?php endif; ?>
				<div class="erankly-settings-fields">
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Indexing & robots directives', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<?php if ( is_multisite() ) : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is configured per site: in the Site Editor for block themes on WordPress 6.6 or later, or under Settings → EasyRankly otherwise.', 'easyrankly' ); ?></p>
							<?php elseif ( erankly_use_site_editor_special_page_panels() ) : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is configured in the corresponding Site Editor template.', 'easyrankly' ); ?></p>
							<?php else : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is now configured per page under General → Special pages and archives.', 'easyrankly' ); ?></p>
					<?php endif; ?>
					<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Robots preview directives', 'easyrankly' ); ?></strong></legend>
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_image_preview_large]" value="1" <?php checked( $settings['robots_max_image_preview_large'], 1 ); ?>> <?php esc_html_e( 'Allow max-image-preview:large', 'easyrankly' ); ?></label>
						<div class="erankly-inline-fields erankly-inline-fields-two-columns">
							<div class="erankly-field">
								<label for="erankly-robots-max-snippet"><?php esc_html_e( 'max-snippet', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-snippet" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_snippet]" value="<?php echo esc_attr( (string) $settings['robots_max_snippet'] ); ?>">
							</div>
							<div class="erankly-field">
								<label for="erankly-robots-max-video-preview"><?php esc_html_e( 'max-video-preview', 'easyrankly' ); ?></label>
								<input id="erankly-robots-max-video-preview" class="widefat" type="number" step="1" min="-1" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_video_preview]" value="<?php echo esc_attr( (string) $settings['robots_max_video_preview'] ); ?>">
							</div>
						</div>
						<div class="erankly-checkbox-options">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_nosnippet]" value="1" <?php checked( $settings['robots_nosnippet'], 1 ); ?>> <?php esc_html_e( 'Add nosnippet', 'easyrankly' ); ?></label>
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_indexifembedded]" value="1" <?php checked( $settings['robots_indexifembedded'], 1 ); ?>> <?php esc_html_e( 'Add indexifembedded when noindex is active', 'easyrankly' ); ?></label>
						</div>
					</fieldset>
						</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'robots.txt', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<div class="erankly-field">
						<label for="erankly-robots-txt-extra"><strong><?php esc_html_e( 'robots.txt — custom rules', 'easyrankly' ); ?></strong></label>
						<textarea id="erankly-robots-txt-extra" class="widefat code" rows="12" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_txt_extra]"><?php echo esc_textarea( (string) $settings['robots_txt_extra'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Extra directives appended to the virtual robots.txt after the auto-generated rules (User-agent, Disallow, Sitemap). One directive per line.', 'easyrankly' ); ?></p>
						</div>
						<div class="erankly-field">
						<strong><?php esc_html_e( 'robots.txt preview', 'easyrankly' ); ?></strong>
						<textarea class="widefat code" rows="12" readonly aria-label="<?php esc_attr_e( 'robots.txt preview', 'easyrankly' ); ?>"><?php echo esc_textarea( erankly_filter_robots_txt( '', (bool) get_option( 'blog_public' ) ) ); ?></textarea>
						<p class="description">
							<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open robots.txt', 'easyrankly' ); ?></a>
						</p>
						</div>
					</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Pagination', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<fieldset class="erankly-field erankly-checkboxes">
						<legend><strong><?php esc_html_e( 'Paginated archive pages', 'easyrankly' ); ?></strong></legend>
						<div class="erankly-checkbox-options">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_paginated]" value="1" <?php checked( $settings['noindex_paginated'], 1 ); ?>> <?php esc_html_e( 'Noindex page 2, 3, … of archives', 'easyrankly' ); ?></label>
						</div>
						<p class="description"><?php esc_html_e( 'When enabled, pages beyond the first of any archive (category, tag, author, date, blog) receive a noindex directive. Canonical URLs are already self-referencing; leave this off unless you have a specific reason to block crawling of deep pagination.', 'easyrankly' ); ?></p>
						</fieldset>
						<div class="erankly-field">
						<label for="erankly-paginated-title-format"><strong><?php esc_html_e( 'Paginated title suffix', 'easyrankly' ); ?></strong></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-paginated-title-format" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[paginated_title_format]" value="<?php echo esc_attr( (string) $settings['paginated_title_format'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Page {{page_number}} of {{max_pages}}', 'easyrankly' ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
						<p class="description"><?php esc_html_e( 'Appended to the SEO title on page 2, 3, … — separated by a dash. Leave empty to keep the base title unchanged. Available variables: {{page_number}}, {{max_pages}}.', 'easyrankly' ); ?></p>
						</div>
					</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Attachment pages', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<fieldset class="erankly-field">
						<legend><strong><?php esc_html_e( 'Redirect attachment pages', 'easyrankly' ); ?></strong></legend>
						<select name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[attachment_redirect]" id="erankly-attachment-redirect" class="widefat erankly-field-full-width">
							<option value="parent" <?php selected( $settings['attachment_redirect'], 'parent' ); ?>><?php esc_html_e( 'Redirect to parent post (fallback: media file)', 'easyrankly' ); ?></option>
							<option value="file" <?php selected( $settings['attachment_redirect'], 'file' ); ?>><?php esc_html_e( 'Redirect to media file', 'easyrankly' ); ?></option>
							<option value="none" <?php selected( $settings['attachment_redirect'], 'none' ); ?>><?php esc_html_e( 'Leave attachment pages unchanged', 'easyrankly' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Attachment pages are thin-content pages WordPress generates for each uploaded file. Redirecting them is the recommended SEO practice.', 'easyrankly' ); ?></p>
						</fieldset>
					</div>
					</div>
				</div>
			</div>
	<?php
}

/**
 * Renders the Bloat settings panel.
 *
 * @param array<string,mixed> $settings          Plugin settings.
 * @param bool                $safe_bloat_active Whether all safe bloat cleanups are active.
 * @return void
 */
function erankly_render_settings_panel_bloat( array $settings, bool $safe_bloat_active ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
				<div class="erankly-tab-panel" id="erankly-settings-panel-bloat" role="tabpanel" aria-labelledby="erankly-settings-tab-bloat" data-erankly-settings-panel="settings-bloat" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> hidden>
					<?php if ( $autosave_active ) : ?>
					<?php endif; ?>

					<div class="erankly-bloat-view erankly-bloat-view-simple" data-erankly-bloat-view="simple" <?php echo ! empty( $settings['simplified_mode'] ) ? '' : 'hidden'; ?>>
						<div class="erankly-settings-section">
							<h3 class="erankly-section-title"><?php esc_html_e( 'WordPress cleanup', 'easyrankly' ); ?></h3>
							<div class="erankly-settings-fields erankly-card">
								<fieldset class="erankly-field erankly-checkboxes">
									<label><input type="checkbox" class="erankly-toggle" data-erankly-bloat-master <?php checked( $safe_bloat_active ); ?>> <strong><?php esc_html_e( 'Lighten WordPress', 'easyrankly' ); ?></strong></label>
									<p class="description"><?php esc_html_e( 'Removes safe WordPress bloat in one click. Turn off Simplified mode for advanced cleanups.', 'easyrankly' ); ?></p>
								</fieldset>
							</div>
						</div>
					</div>

					<div class="erankly-bloat-view erankly-bloat-view-advanced" data-erankly-bloat-view="advanced" <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
						<div class="erankly-settings-section">
							<h3 class="erankly-section-title"><?php esc_html_e( 'WordPress cleanup', 'easyrankly' ); ?></h3>
							<div class="erankly-settings-fields erankly-card">

							<fieldset class="erankly-field erankly-checkboxes">
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_emoji]" value="1" <?php checked( $settings['bloat_remove_emoji'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove WordPress emojis', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Stops the emoji script and styles from loading on every page. Browsers already render emojis natively, so this just removes an unused request.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_generator]" value="1" <?php checked( $settings['bloat_remove_generator'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove WP Generator meta tag', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Hides your WordPress version number from the page source, so it is not an easy clue for bots scanning for known vulnerabilities.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_feed_links]" value="1" <?php checked( $settings['bloat_remove_feed_links'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Remove RSS feed links', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Removes the RSS feed links from the page header. The feeds still work — only the auto-discovery hints are hidden.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_rsd_link]" value="1" <?php checked( $settings['bloat_remove_rsd_link'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove Really Simple Discovery link', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Removes an old XML-RPC discovery link that almost no modern tool uses. Safe to remove.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_wlwmanifest]" value="1" <?php checked( $settings['bloat_remove_wlwmanifest'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove Windows Live Writer manifest link', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Removes a link left over for the long-discontinued Windows Live Writer editor. No current software needs it.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_shortlink]" value="1" <?php checked( $settings['bloat_remove_shortlink'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove shortlink', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Removes the ?p=123 short URL hint from the page header and HTTP headers. Your normal permalinks keep working.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_rest_link]" value="1" <?php checked( $settings['bloat_remove_rest_link'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Remove REST API discovery link', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Hides the link that advertises your REST API location. The API itself keeps working — only the public hint is removed.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_oembed]" value="1" <?php checked( $settings['bloat_remove_oembed'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Remove oEmbed discovery links', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Stops WordPress from auto-embedding other sites and from loading the related embed script. Leave on if you paste other WordPress posts as live embeds.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_jquery_migrate]" value="1" <?php checked( $settings['bloat_remove_jquery_migrate'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Remove jQuery Migrate', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Drops the compatibility script that patches outdated jQuery code. Modern themes do not need it, but test your site afterward in case an older theme or plugin does.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_remove_dashicons]" value="1" <?php checked( $settings['bloat_remove_dashicons'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Remove Dashicons for guests', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Stops loading the admin icon font for visitors who are not logged in. Admins still see it. Skip this if your theme uses Dashicons on the frontend.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_self_pingbacks]" value="1" <?php checked( $settings['bloat_disable_self_pingbacks'], 1 ); ?> data-erankly-bloat-item data-erankly-bloat-safe> <strong><?php esc_html_e( 'Disable self-pingbacks', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Stops WordPress from notifying itself every time you link to one of your own posts, avoiding useless self-comments.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_heartbeat]" value="1" <?php checked( $settings['bloat_disable_heartbeat'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Disable Heartbeat on frontend', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Stops the background script that repeatedly pings the server on public pages, lowering server load. Admin autosave and post-locking are unaffected.', 'easyrankly' ); ?></p>
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[bloat_disable_xmlrpc]" value="1" <?php checked( $settings['bloat_disable_xmlrpc'], 1 ); ?> data-erankly-bloat-item> <strong><?php esc_html_e( 'Disable XML-RPC', 'easyrankly' ); ?></strong></label>
								<p class="description"><?php esc_html_e( 'Turns off the legacy remote-access interface, a common target for brute-force and pingback-spam attacks. Leave on if you use the WordPress mobile app, Jetpack, or remote publishing tools.', 'easyrankly' ); ?></p>
							</fieldset>

							</div>
						</div>
					</div>
				</div>
	<?php
}
