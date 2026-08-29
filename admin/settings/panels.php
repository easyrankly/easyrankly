<?php
/**
 * Settings page: per-panel renderers.
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
 * @param array<string,mixed> $settings          Plugin settings.
 * @param bool                $redirects_enabled Whether the redirect module is enabled.
 * @param bool                $sitemap_enabled   Whether the sitemap module is enabled.
 * @param string              $active_panel      Active panel ID.
 * @return void
 */
function erankly_render_settings_panel_features( array $settings, bool $redirects_enabled, bool $sitemap_enabled, string $active_panel ): void {
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
				<div class="erankly-tab-panel<?php echo 'settings-features' === $active_panel ? ' is-active' : ''; ?>" id="erankly-settings-panel-features" role="tabpanel" aria-labelledby="erankly-settings-tab-features" data-erankly-settings-panel="settings-features" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> <?php echo 'settings-features' === $active_panel ? '' : 'hidden'; ?>>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Feature modules', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_redirects]" value="1" <?php checked( $redirects_enabled ); ?>> <?php esc_html_e( 'Enable the redirect manager', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_sitemap]" value="1" <?php checked( $sitemap_enabled ); ?>> <?php esc_html_e( 'Enable the sitemap module', 'easyrankly' ); ?></label>
						</div>
						<?php
						/**
						 * Prints extra feature-module toggles after Redirects and Sitemap.
						 *
						 * @param array<string,mixed> $settings Plugin settings.
						 */
						do_action( 'erankly_settings_features_modules', $settings );
						?>
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
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Site identity', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
					<div class="erankly-field">
						<label for="erankly-organization-name"><?php esc_html_e( 'Organization or person name', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-organization-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_name]" value="<?php echo esc_attr( (string) $settings['organization_name'] ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
					</div>
					<div class="erankly-field">
						<label for="erankly-website-name"><?php esc_html_e( 'Website name', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-website-name" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[website_name]" value="<?php echo esc_attr( (string) $settings['website_name'] ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
						<p class="description"><?php esc_html_e( 'Leave blank to fall back to the WordPress site title.', 'easyrankly' ); ?></p>
					</div>
					<div class="erankly-field">
						<label for="erankly-website-description"><?php esc_html_e( 'Website description', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<textarea id="erankly-website-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[website_description]"><?php echo esc_textarea( (string) $settings['website_description'] ); ?></textarea>
							<?php erankly_render_variable_picker(); ?>
						</div>
						<p class="description"><?php esc_html_e( 'Empty taglines are omitted from schema output.', 'easyrankly' ); ?></p>
					</div>
						<div class="erankly-schema-identity-fields" data-erankly-schema-identity-fields>
						<div class="erankly-field">
							<label for="erankly-schema-identity"><?php esc_html_e( 'Identity type', 'easyrankly' ); ?></label>
							<select id="erankly-schema-identity" class="widefat" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_identity]" data-erankly-schema-identity>
								<option value="organization" <?php selected( $settings['schema_identity'], 'organization' ); ?>><?php esc_html_e( 'Organization', 'easyrankly' ); ?></option>
								<option value="person" <?php selected( $settings['schema_identity'], 'person' ); ?>><?php esc_html_e( 'Person', 'easyrankly' ); ?></option>
							</select>
						</div>
						<div class="erankly-field" data-erankly-person-reference-field <?php echo 'person' === $settings['schema_identity'] ? '' : 'hidden'; ?>>
							<span class="erankly-field-label" id="erankly-person-reference-label"><?php esc_html_e( 'Person reference user', 'easyrankly' ); ?></span>
							<div data-erankly-user-search-wrap>
								<input type="hidden"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[schema_person_user_id]"
									value="<?php echo esc_attr( (string) $schema_person_user_id ); ?>"
									data-erankly-user-id>
								<div class="erankly-autocomplete-control">
									<div class="erankly-autocomplete-value" data-erankly-user-selected<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>>
										<input type="text"
											class="widefat erankly-user-selected-input"
											readonly
											aria-labelledby="erankly-person-reference-label"
											value="<?php echo ( $schema_person_user instanceof WP_User ) ? esc_attr( sprintf( /* translators: 1: User display name, 2: User ID. */ __( '%1$s (ID: %2$d)', 'easyrankly' ), $schema_person_user->display_name, $schema_person_user->ID ) ) : ''; ?>"
											data-erankly-user-selected-name>
									</div>
									<div class="erankly-autocomplete-search" data-erankly-user-search-input-wrap<?php echo ( $schema_person_user instanceof WP_User ) ? ' hidden' : ''; ?>>
										<input type="search"
											class="widefat erankly-user-search-input"
											placeholder="<?php esc_attr_e( 'Search users…', 'easyrankly' ); ?>"
											autocomplete="off"
											aria-autocomplete="list"
											aria-labelledby="erankly-person-reference-label"
											data-erankly-user-search-input>
										<ul class="erankly-autocomplete-results" role="listbox" hidden data-erankly-user-results></ul>
									</div>
									<button type="button" class="button" data-erankly-user-remove<?php echo ( $schema_person_user instanceof WP_User ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove', 'easyrankly' ); ?></button>
								</div>
							</div>
							</div>
						</div>
						<div data-erankly-organization-only <?php echo $show_organization_fields ? '' : 'hidden'; ?>>
							<div class="erankly-field">
								<label for="erankly-organization-description"><?php esc_html_e( 'Organization description', 'easyrankly' ); ?></label>
								<textarea id="erankly-organization-description" class="widefat" rows="3" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_description]"><?php echo esc_textarea( (string) $settings['organization_description'] ); ?></textarea>
							</div>
							<div class="erankly-inline-fields erankly-inline-fields-two-columns">
								<div class="erankly-field">
									<label for="erankly-organization-email"><?php esc_html_e( 'Business email', 'easyrankly' ); ?></label>
									<input id="erankly-organization-email" class="widefat" type="email" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_email]" value="<?php echo esc_attr( (string) $settings['organization_email'] ); ?>">
								</div>
								<div class="erankly-field">
									<label for="erankly-organization-phone"><?php esc_html_e( 'Business telephone', 'easyrankly' ); ?></label>
									<input id="erankly-organization-phone" class="widefat" type="tel" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[organization_phone]" value="<?php echo esc_attr( (string) $settings['organization_phone'] ); ?>" placeholder="+1 555 123 4567">
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
								<div class="erankly-settings-section" <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
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
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-social" role="tabpanel" aria-labelledby="erankly-settings-tab-social" data-erankly-settings-panel="settings-social" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?>>
				<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Default images', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
					<div class="erankly-field">
						<label for="erankly-organization-logo-url"><?php esc_html_e( 'Organization logo', 'easyrankly' ); ?></label>
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
						<label for="erankly-default-social-image-url"><?php esc_html_e( 'Default social image URL', 'easyrankly' ); ?></label>
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
							<label for="erankly-twitter-site"><?php esc_html_e( 'X (Twitter) Site', 'easyrankly' ); ?></label>
							<input id="erankly-twitter-site" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[twitter_site]" value="<?php echo esc_attr( (string) $settings['twitter_site'] ); ?>" placeholder="@example">
						</div>
						<div class="erankly-field">
							<label for="erankly-social-profiles"><?php esc_html_e( 'Social profiles', 'easyrankly' ); ?></label>
							<textarea id="erankly-social-profiles" class="widefat" rows="5" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[social_profiles]"><?php echo esc_textarea( (string) $settings['social_profiles'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One absolute URL per line.', 'easyrankly' ); ?></p>
						</div>
						</div>
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
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-schema" role="tabpanel" aria-labelledby="erankly-settings-tab-schema" data-erankly-settings-panel="settings-schema" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?>>
				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Information for Google and other search engines', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
					<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_breadcrumbs]" value="1" <?php checked( $settings['enable_breadcrumbs'], 1 ); ?>> <?php esc_html_e( 'Show search engines how your pages are organized', 'easyrankly' ); ?></label>
							<p class="description"><?php esc_html_e( 'Example: Home → Blog → Article. Helps search engines understand your site structure. Visible to visitors only if your theme supports breadcrumbs.', 'easyrankly' ); ?></p>
					</div>
						<?php erankly_render_local_business_settings( $settings ); ?>
					</div>
				</div>
				<div class="erankly-settings-section" <?php echo ! empty( $settings['simplified_mode'] ) ? 'hidden' : ''; ?>>
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
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-sitemap" role="tabpanel" aria-labelledby="erankly-settings-tab-sitemap" data-erankly-settings-panel="settings-sitemap" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?>>
				<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'XML sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<p class="description">
								<a href="<?php echo esc_url( $sitemap_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open wp-sitemap.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Author sitemap: included only when at least two authors have sitemap-eligible published content. On single-author sites it is disabled to avoid duplicate archive URLs for SEO.', 'easyrankly' ); ?></p>
							<p class="description"><?php esc_html_e( 'Image, Video and News sitemaps are integrated directly into the core wp-sitemap.xml index when enabled.', 'easyrankly' ); ?></p>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Google News sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<div class="erankly-field erankly-checkboxes">
								<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_news_sitemap]" value="1" <?php checked( $settings['enable_news_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate Google News sitemap', 'easyrankly' ); ?></label>
								<p class="description">
									<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-news-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-news-1.xml', 'easyrankly' ); ?></a>
								</p>
								<p class="description"><?php esc_html_e( 'Includes only posts published in the last 48 hours. Submitting a News sitemap does not guarantee inclusion in Google News. Editorial review by Google is still required.', 'easyrankly' ); ?></p>
							</div>
							<fieldset class="erankly-field erankly-checkboxes erankly-visibility-defaults">
								<legend><?php esc_html_e( 'Included post types', 'easyrankly' ); ?></legend>
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
							</fieldset>
							<div class="erankly-field">
								<label for="erankly-news-publication-name"><?php esc_html_e( 'News publication name', 'easyrankly' ); ?></label>
								<input
									id="erankly-news-publication-name"
									class="widefat"
									type="text"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[news_publication_name]"
									value="<?php echo esc_attr( (string) $settings['news_publication_name'] ); ?>"
									maxlength="200"
								>
								<p class="description">
									<?php esc_html_e( 'Publication name for the Google News sitemap. Leave blank to use the organization name or site title; without a name the sitemap is not generated.', 'easyrankly' ); ?>
								</p>
							</div>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Image sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_image_sitemap]" value="1" <?php checked( $settings['enable_image_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate image sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-image-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-image-1.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Links images to the pages that contain them, extracted from post content (featured, embedded, and Gutenberg image/gallery blocks). Attachment pages are not used as URLs.', 'easyrankly' ); ?></p>
							</div>
						</div>
					</div>
					<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Video sitemap', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[enable_video_sitemap]" value="1" <?php checked( $settings['enable_video_sitemap'], 1 ); ?>> <?php esc_html_e( 'Generate video sitemap', 'easyrankly' ); ?></label>
							<p class="description">
								<a href="<?php echo esc_url( erankly_get_sitemap_url( '/sitemap-video-1.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open sitemap-video-1.xml', 'easyrankly' ); ?></a>
							</p>
							<p class="description"><?php esc_html_e( 'Includes published posts with YouTube, Vimeo or self-hosted HTML5 videos; each video on a page counts. Vimeo entries require a featured image. A Video sitemap does not guarantee indexing. The player must also be crawlable.', 'easyrankly' ); ?></p>
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
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-settings" role="tabpanel" aria-labelledby="erankly-settings-tab-settings" data-erankly-settings-panel="settings-settings" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?>>
				<?php if ( function_exists( 'erankly_reset_render_notice' ) ) : ?>
					<?php erankly_reset_render_notice(); ?>
				<?php endif; ?>
				<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Preferences', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[simplified_mode]" value="1" <?php checked( $settings['simplified_mode'], 1 ); ?>> <?php esc_html_e( 'Simplified mode', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'Shows the essential controls and automates advanced SEO defaults.', 'easyrankly' ); ?></p>
					</div>
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[resolve_placeholders]" value="1" <?php checked( ! empty( $settings['resolve_placeholders'] ) ); ?>> <?php esc_html_e( 'Show resolved values for variables', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'Shows resolved values instead of {{variables}}. Click to edit.', 'easyrankly' ); ?></p>
					</div>
					<?php if ( $redirects_enabled ) : ?>
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[redirect_exclude_admins]" value="1" <?php checked( ! empty( $settings['redirect_exclude_admins'] ) ); ?>> <?php esc_html_e( 'Do not apply any redirect to administrators', 'easyrankly' ); ?></label>
						<p class="description"><?php esc_html_e( 'Exempts users with the "manage_options" capability (typically Administrators) from all redirects.', 'easyrankly' ); ?></p>
					</div>
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
			<div class="erankly-tab-panel is-active" id="erankly-settings-panel-advanced" role="tabpanel" aria-labelledby="erankly-settings-tab-advanced" data-erankly-settings-panel="settings-advanced" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?>>
				<div class="erankly-settings-section">
						<h3 class="erankly-section-title"><?php esc_html_e( 'Indexing & robots directives', 'easyrankly' ); ?></h3>
						<div class="erankly-card">
							<?php if ( is_multisite() ) : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page and author/date archives is set per site: in the Site Editor for block themes on WordPress 6.6+, or under Settings → EasyRankly otherwise.', 'easyrankly' ); ?></p>
							<?php elseif ( erankly_use_site_editor_special_page_panels() ) : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is configured in the corresponding Site Editor template.', 'easyrankly' ); ?></p>
							<?php else : ?>
							<p class="description"><?php esc_html_e( 'Noindex for search results, the 404 page, and WordPress author/date archive contexts is now configured per page under General → Special pages and archives.', 'easyrankly' ); ?></p>
					<?php endif; ?>
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_max_image_preview_large]" value="1" <?php checked( $settings['robots_max_image_preview_large'], 1 ); ?>> <?php esc_html_e( 'Allow max-image-preview:large', 'easyrankly' ); ?></label>
					</div>
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
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_nosnippet]" value="1" <?php checked( $settings['robots_nosnippet'], 1 ); ?>> <?php esc_html_e( 'Add nosnippet', 'easyrankly' ); ?></label>
					</div>
					<div class="erankly-field erankly-checkboxes">
						<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_indexifembedded]" value="1" <?php checked( $settings['robots_indexifembedded'], 1 ); ?>> <?php esc_html_e( 'Add indexifembedded when noindex is active', 'easyrankly' ); ?></label>
					</div>
						</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'robots.txt', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<div class="erankly-field">
						<label for="erankly-robots-txt-extra"><?php esc_html_e( 'robots.txt: custom rules', 'easyrankly' ); ?></label>
						<textarea id="erankly-robots-txt-extra" class="widefat code" rows="12" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[robots_txt_extra]"><?php echo esc_textarea( (string) $settings['robots_txt_extra'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One robots.txt directive per line, for example Disallow: /private/. Appended to the generated file.', 'easyrankly' ); ?></p>
						</div>
						<div class="erankly-field">
						<label for="erankly-robots-txt-preview"><?php esc_html_e( 'robots.txt preview', 'easyrankly' ); ?></label>
						<textarea id="erankly-robots-txt-preview" class="widefat code" rows="12" readonly><?php echo esc_textarea( erankly_filter_robots_txt( '', (bool) get_option( 'blog_public' ) ) ); ?></textarea>
						<p class="description">
							<a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open robots.txt', 'easyrankly' ); ?></a>
						</p>
						</div>
					</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Pagination', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<div class="erankly-field erankly-checkboxes">
							<label><input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[noindex_paginated]" value="1" <?php checked( $settings['noindex_paginated'], 1 ); ?>> <?php esc_html_e( 'Noindex page 2, 3, … of archives', 'easyrankly' ); ?></label>
						</div>
						<div class="erankly-field">
						<label for="erankly-paginated-title-format"><?php esc_html_e( 'Paginated title suffix', 'easyrankly' ); ?></label>
						<div class="erankly-variable-field" data-erankly-variable-field>
							<input id="erankly-paginated-title-format" class="widefat" type="text" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[paginated_title_format]" value="<?php echo esc_attr( (string) $settings['paginated_title_format'] ); ?>" placeholder="<?php esc_attr_e( 'Page {{page_number}} of {{max_pages}}', 'easyrankly' ); ?>">
							<?php erankly_render_variable_picker(); ?>
						</div>
						</div>
					</div>
					</div>

					<div class="erankly-settings-section">
					<h3 class="erankly-section-title"><?php esc_html_e( 'Attachment pages', 'easyrankly' ); ?></h3>
					<div class="erankly-card">
						<div class="erankly-field">
						<label for="erankly-attachment-redirect"><?php esc_html_e( 'Redirect attachment pages', 'easyrankly' ); ?></label>
						<select name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[attachment_redirect]" id="erankly-attachment-redirect" class="widefat erankly-field-full-width">
							<option value="parent" <?php selected( $settings['attachment_redirect'], 'parent' ); ?>><?php esc_html_e( 'Redirect to parent post (fallback: media file)', 'easyrankly' ); ?></option>
							<option value="file" <?php selected( $settings['attachment_redirect'], 'file' ); ?>><?php esc_html_e( 'Redirect to media file', 'easyrankly' ); ?></option>
							<option value="none" <?php selected( $settings['attachment_redirect'], 'none' ); ?>><?php esc_html_e( 'Leave attachment pages unchanged', 'easyrankly' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Redirecting attachment pages is recommended for SEO.', 'easyrankly' ); ?></p>
						</div>
					</div>
					</div>
			</div>
	<?php
}
