<?php
/**
 * Inline SVG icons (Hugeicons, stroke style) for the settings sidebar navigation.
 * Icons are embedded directly in the HTML — no icon font, no CDN request.
 * Each SVG inherits its color from the parent link via stroke="currentColor",
 * so hover/active/disabled states keep working without extra CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns the navigation icon SVG for a settings tab, keyed by tab slug.
 * Icons: Hugeicons free "stroke" set (https://hugeicons.com), inlined at 24x24
 * with stroke-width 1.5 and currentColor.
 *
 * @return array<string,string> Map of nav slug to inline SVG markup.
 */
function erankly_nav_icons(): array {
	$icons = array(
		'general'       => '<path d="M3 11.99v2.51c0 3.3 0 4.95 1.025 5.975S6.7 21.5 10 21.5h4c3.3 0 4.95 0 5.975-1.025S21 17.8 21 14.5v-2.51c0-1.682 0-2.522-.356-3.25s-1.02-1.244-2.346-2.276l-2-1.555C14.233 3.303 13.2 2.5 12 2.5s-2.233.803-4.298 2.409l-2 1.555C4.375 7.496 3.712 8.012 3.356 8.74S3 10.308 3 11.99"/><path d="M15 21.5v-5c0-1.414 0-2.121-.44-2.56c-.439-.44-1.146-.44-2.56-.44s-2.121 0-2.56.44C9 14.378 9 15.085 9 16.5v5"/>',
		'social'        => '<path d="M21 6.5a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM9 12a3 3 0 1 1-6 0a3 3 0 0 1 6 0Zm12 5.5a3 3 0 1 1-6 0a3 3 0 0 1 6 0ZM8.729 10.75l6.5-3m-6.5 5.5l6.5 3"/>',
		'schema'        => '<path stroke-linecap="round" stroke-linejoin="round" d="M14 19.5h-1c-2.828 0-4.243 0-5.121-.879C7 17.743 7 16.328 7 13.5v-2M7 8v3.5m0 0h7"/><path d="M14 11.5c0-1.178 0-1.768.351-2.134C14.704 9 15.27 9 16.4 9h1.2c1.131 0 1.697 0 2.048.366c.352.366.352.956.352 2.134s0 1.768-.352 2.134c-.35.366-.917.366-2.048.366h-1.2c-1.131 0-1.697 0-2.048-.366C14 13.268 14 12.678 14 11.5Zm0 8c0-1.178 0-1.768.351-2.134C14.704 17 15.27 17 16.4 17h1.2c1.131 0 1.697 0 2.048.366c.352.366.352.956.352 2.134s0 1.768-.352 2.134c-.35.366-.917.366-2.048.366h-1.2c-1.131 0-1.697 0-2.048-.366C14 21.268 14 20.678 14 19.5ZM5.286 2h3.428C10.79 2 11 3.11 11 5s-.211 3-2.286 3H5.286C3.21 8 3 6.89 3 5s.211-3 2.286-3Z"/>',
		'advanced'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 7h3M3 17h6m9 0h3M15 7h6"/><path d="M6 7c0-.932 0-1.398.152-1.765a2 2 0 0 1 1.083-1.083C7.602 4 8.068 4 9 4s1.398 0 1.765.152a2 2 0 0 1 1.083 1.083C12 5.602 12 6.068 12 7s0 1.398-.152 1.765a2 2 0 0 1-1.083 1.083C10.398 10 9.932 10 9 10s-1.398 0-1.765-.152a2 2 0 0 1-1.083-1.083C6 8.398 6 7.932 6 7Zm6 10c0-.932 0-1.398.152-1.765a2 2 0 0 1 1.083-1.083C13.602 14 14.068 14 15 14s1.398 0 1.765.152a2 2 0 0 1 1.083 1.083C18 15.602 18 16.068 18 17s0 1.398-.152 1.765a2 2 0 0 1-1.083 1.083C16.398 20 15.932 20 15 20s-1.398 0-1.765-.152a2 2 0 0 1-1.083-1.083C12 18.398 12 17.932 12 17Z"/>',
		'features'      => '<path d="m8.643 3.146l-1.705.788C4.313 5.147 3 5.754 3 6.75s1.313 1.603 3.938 2.816l1.705.788c1.652.764 2.478 1.146 3.357 1.146s1.705-.382 3.357-1.146l1.705-.788C19.687 8.353 21 7.746 21 6.75s-1.313-1.603-3.938-2.816l-1.705-.788C13.705 2.382 12.879 2 12 2s-1.705.382-3.357 1.146"/><path d="M20.788 11.097c.141.199.212.406.212.634c0 .982-1.313 1.58-3.938 2.776l-1.705.777c-1.652.753-2.478 1.13-3.357 1.13s-1.705-.377-3.357-1.13l-1.705-.777C4.313 13.311 3 12.713 3 11.731c0-.228.07-.435.212-.634"/><path d="M20.377 16.266c.415.331.623.661.623 1.052c0 .981-1.313 1.58-3.938 2.776l-1.705.777C13.705 21.624 12.879 22 12 22s-1.705-.376-3.357-1.13l-1.705-.776C4.313 18.898 3 18.299 3 17.318c0-.391.208-.72.623-1.052"/>',
		'settings'      => '<path stroke-linecap="round" d="m21.318 7.141l-.494-.856c-.373-.648-.56-.972-.878-1.101c-.317-.13-.676-.027-1.395.176l-1.22.344c-.459.106-.94.046-1.358-.17l-.337-.194a2 2 0 0 1-.788-.967l-.334-.998c-.22-.66-.33-.99-.591-1.178c-.261-.19-.609-.19-1.303-.19h-1.115c-.694 0-1.041 0-1.303.19c-.261.188-.37.518-.59 1.178l-.334.998a2 2 0 0 1-.789.967l-.337.195c-.418.215-.9.275-1.358.17l-1.22-.345c-.719-.203-1.078-.305-1.395-.176c-.318.129-.505.453-.878 1.1l-.493.857c-.35.608-.525.911-.491 1.234c.034.324.268.584.736 1.105l1.031 1.153c.252.319.431.875.431 1.375s-.179 1.056-.43 1.375l-1.032 1.152c-.468.521-.702.782-.736 1.105s.14.627.49 1.234l.494.857c.373.647.56.971.878 1.1s.676.028 1.395-.176l1.22-.344a2 2 0 0 1 1.359.17l.336.194c.36.23.636.57.788.968l.334.997c.22.66.33.99.591 1.18c.262.188.609.188 1.303.188h1.115c.694 0 1.042 0 1.303-.189s.371-.519.59-1.179l.335-.997c.152-.399.428-.738.788-.968l.336-.194c.42-.215.9-.276 1.36-.17l1.22.344c.718.204 1.077.306 1.394.177c.318-.13.505-.454.878-1.101l.493-.857c.35-.607.525-.91.491-1.234s-.268-.584-.736-1.105l-1.031-1.152c-.252-.32-.431-.875-.431-1.375s.179-1.056.43-1.375l1.032-1.153c.468-.52.702-.781.736-1.105s-.14-.626-.49-1.234Z"/><path d="M15.52 12a3.5 3.5 0 1 1-7 0a3.5 3.5 0 0 1 7 0Z"/>',
		'import-export' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 9H6.659c-1.006 0-1.51 0-1.634-.309c-.125-.308.23-.672.941-1.398L8.211 5M5 15h12.341c1.006 0 1.51 0 1.634.309c.125.308-.23.672-.941 1.398L15.789 19"/>',
		'redirects'     => '<path d="M13 6H8.5a4.5 4.5 0 0 0 0 9H20"/><path d="M17 12s3 2.21 3 3s-3 3-3 3"/>',
		/** Custom code usa l'icona "< >" che prima era di Schema. */
		'custom-code'   => '<path stroke-linecap="round" stroke-linejoin="round" d="m17 8l1.84 1.85c.773.778 1.16 1.167 1.16 1.65s-.387.872-1.16 1.65L17 15M7 8L5.16 9.85C4.387 10.628 4 11.017 4 11.5s.387.872 1.16 1.65L7 15m7.5-11l-5 16"/>',
		'sitemap'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 0H9c-1.886 0-2.828 0-3.414.586S5 15.114 5 17m7-4h3c1.886 0 2.828 0 3.414.586S19 15.114 19 17"/><path stroke-linecap="round" d="M2.009 21C2 20.712 2 20.382 2 20c0-1.414 0-2.121.44-2.56C2.878 17 3.585 17 5 17s2.121 0 2.56.44C8 17.878 8 18.585 8 20c0 .382 0 .712-.009 1m8.018 0C16 20.712 16 20.382 16 20c0-1.414 0-2.121.44-2.56C16.878 17 17.585 17 19 17s2.121 0 2.56.44c.44.439.44 1.146.44 2.56c0 .382 0 .712-.009 1"/><path d="M10.286 3h3.428C15.79 3 16 4.11 16 6s-.211 3-2.286 3h-3.428C8.21 9 8 7.89 8 6s.211-3 2.286-3Z"/>',
		'documentation' => '<path d="M20 22H6a2 2 0 0 1-2-2m0 0a2 2 0 0 1 2-2h12a2 2 0 0 0 2-2V2a2 2 0 0 1-2 2h-8c-2.828 0-4.243 0-5.121.879C4 5.757 4 7.172 4 10z"/><path d="M18.5 18s-1 .763-1 2s1 2 1 2M9 4v4"/>',
	);

	/**
	 * Filters the sidebar navigation icons. Each value must be a set of SVG
	 * <path> elements drawn on a 24x24 grid using currentColor strokes.
	 *
	 * @param array<string,string> $icons Map of nav slug to SVG path markup.
	 */
	$icons = apply_filters( 'erankly_nav_icons', $icons );

	// The per-site "General" tab shares the general icon.
	$icons['special-pages'] = $icons['general'] ?? '';

	return $icons;
}

/**
 * Renders the inline SVG icon for a settings nav item. Falls back to a generic
 * grid icon for unknown slugs (extra feature modules) so every row stays aligned.
 *
 * @param string $slug Tab slug as used in erankly_render_settings_nav_link().
 */
function erankly_nav_icon( string $slug ): string {
	$icons = erankly_nav_icons();

	$fallback = '<path stroke-linecap="round" stroke-linejoin="round" d="M7 3v18M17 3v18m4-14H3m18 10H3"/>';
	$paths    = $icons[ $slug ] ?? $fallback;

	return sprintf(
		'<svg class="erankly-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">%1$s</svg>',
		$paths // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static inline SVG path data, not user input.
	);
}
