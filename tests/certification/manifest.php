<?php
// phpcs:ignoreFile -- Declarative CLI-test data, not WordPress runtime code.
/**
 * Immutable migration-certification contract.
 *
 * Fixture hashes make every certification record reproducible. The fixtures
 * certify storage and export contracts; they are not represented as vendor-
 * signed packages. Licensed PRO binaries are a separate, optional evidence
 * layer and may never be silently substituted by these fixtures.
 *
 * @package EasyRankly
 */

return array(
	'schema_version' => 1,
	'plugin_version' => '2.0.0',
	'runtime_matrix' => array(
		'php'       => array( '8.0', '8.4', '8.5' ),
		'wordpress' => array( '6.2', '7.0.1' ),
		'database'  => array( 'MariaDB 10.11' ),
		'topologies' => array( 'single-site', 'multisite' ),
	),
	'certification_cells' => array(
		array( 'layer' => 'standalone', 'php' => '8.0', 'wordpress' => '', 'database' => '', 'topology' => 'contract' ),
		array( 'layer' => 'standalone', 'php' => '8.4', 'wordpress' => '', 'database' => '', 'topology' => 'contract' ),
		array( 'layer' => 'standalone', 'php' => '8.5', 'wordpress' => '', 'database' => '', 'topology' => 'contract' ),
		array( 'layer' => 'quality', 'php' => '8.4', 'wordpress' => '', 'database' => '', 'topology' => 'static' ),
		array( 'layer' => 'quality', 'php' => '8.5', 'wordpress' => '', 'database' => '', 'topology' => 'static' ),
		array( 'layer' => 'wordpress', 'php' => '8.0', 'wordpress' => '6.2', 'database' => 'MariaDB 10.11', 'topology' => 'single-site' ),
		array( 'layer' => 'wordpress', 'php' => '8.0', 'wordpress' => '7.0.1', 'database' => 'MariaDB 10.11', 'topology' => 'single-site' ),
		array( 'layer' => 'wordpress', 'php' => '8.4', 'wordpress' => '7.0.1', 'database' => 'MariaDB 10.11', 'topology' => 'single-site' ),
		array( 'layer' => 'wordpress', 'php' => '8.5', 'wordpress' => '7.0.1', 'database' => 'MariaDB 10.11', 'topology' => 'single-site' ),
		array( 'layer' => 'wordpress', 'php' => '8.0', 'wordpress' => '6.2', 'database' => 'MariaDB 10.11', 'topology' => 'multisite' ),
		array( 'layer' => 'wordpress', 'php' => '8.4', 'wordpress' => '7.0.1', 'database' => 'MariaDB 10.11', 'topology' => 'multisite' ),
		array( 'layer' => 'wordpress', 'php' => '8.5', 'wordpress' => '7.0.1', 'database' => 'MariaDB 10.11', 'topology' => 'multisite' ),
	),
	'sources'        => array(
		'yoast'    => array(
			'editions'          => array( 'free', 'premium' ),
			'version_range'     => array( 'min' => '3.0.0', 'max' => '28.999.999' ),
			'contract_fixtures' => array( 'yoast-free-pro.json' ),
			'official_formats'  => array( 'yoast-redirects-official.csv' ),
			'pro_surfaces'      => array( 'additional keyphrases', 'schema', 'redirects' ),
		),
		'rankmath' => array(
			'editions'          => array( 'free', 'pro' ),
			'version_range'     => array( 'min' => '0.9.0', 'max' => '1.999.999' ),
			'contract_fixtures' => array( 'rankmath-free-pro.json' ),
			'official_formats'  => array( 'rankmath-metadata-official.csv', 'rankmath-redirects-official.csv' ),
			'pro_surfaces'      => array( 'advanced robots', 'schema', 'redirections' ),
		),
		'aioseo'   => array(
			'editions'          => array( 'lite', 'pro' ),
			'version_range'     => array( 'min' => '3.0.0', 'max' => '4.999.999' ),
			'contract_fixtures' => array( 'aioseo-free-pro.json' ),
			'official_formats'  => array( 'aioseo-redirects-official.json' ),
			'pro_surfaces'      => array( 'terms', 'schema', 'redirects' ),
		),
		'seopress' => array(
			'editions'          => array( 'free', 'pro' ),
			'version_range'     => array( 'min' => '3.0.0', 'max' => '10.999.999' ),
			'contract_fixtures' => array( 'seopress-free-pro.json' ),
			'official_formats'  => array( 'seopress-metadata-official.csv' ),
			'pro_surfaces'      => array( 'schema', 'redirects', 'visibility conditions' ),
		),
	),
	'fixture_hashes' => array(
		'aioseo-free-pro.json'                => '17d3e18a8ad078fc5d4faef9f6efee8e0d3508642d0f0908f60a1d653729662d',
		'aioseo-redirects-official.json'      => '47b1eae091f9b71d3f1fe150f4ae8994c9bb4472c939509be1679521d55c49b4',
		'rankmath-free-pro.json'               => '51736249ba86b5a2626f93da253786c55942a7f7d152dca658618029e227b170',
		'rankmath-metadata-official.csv'       => 'fcbf6114efdf07fda21c0049f248998c4383d42b305833bb4dd09cb6e9a2f1c1',
		'rankmath-redirects-official.csv'      => 'd3d31e1642060112291c908b1f5de9b437f369ec6f601a9683174e893740e4db',
		'seopress-free-pro.json'               => 'e12e17b1a2d784d537a8e412480287974c24447c8c9f0e9b126e5751aa392400',
		'seopress-metadata-official.csv'       => '22f122f7a1fab674c51c646d8b4727c33eab477a0e42efe4fbb61d92656e7b34',
		'yoast-free-pro.json'                  => '067de8d6a937c29ad9af4741c709856c48da2ed2cddb5597306c851ec7f6c089',
		'yoast-redirects-official.csv'         => '0b452d6916fa79fc1fc8cd3d89529fce0175fe00e836c4e9edd2d56d07ca69ed',
	),
	'required_standalone_tests' => array(
		'tests/migration-worker-loader.php',
		'tests/phase1-smoke.php',
		'tests/phase2-migration-smoke.php',
		'tests/phase3-migration-integration.php',
		'tests/phase4-adapter-certification.php',
		'tests/phase5-upload-certification.php',
		'tests/phase7-contract-certification.php',
		'tests/phase8-go-live-gate.php',
		'tests/concurrent-standalone-certification.php',
		'tests/security-broken-links-ssrf.php',
		'tests/broken-links-state-segmentation.php',
		'tests/security-ai-rate-limit.php',
		'tests/security-health-privacy.php',
		'tests/security-import-memory.php',
		'tests/import-export-batching-contract.php',
		'tests/security-workflow-pinning.php',
		'tests/redirect-runtime-index.php',
		'tests/feature-module-dependencies.php',
		'tests/content-analysis-contract.php',
		'tests/opengraph-image-alt-contract.php',
		'tests/bloat-advanced-contract.php',
		'tests/performance-contract.php',
	),
	'required_wordpress_tests' => array(
		'tests/contextual-modules-wordpress-integration.php',
		'tests/content-analysis-wordpress-integration.php',
		'tests/performance-wordpress-integration.php',
		'tests/sitemap-wordpress-integration.php',
		'tests/migration-cron-seed-wordpress-integration.php',
		'tests/migration-cron-worker-wordpress-integration.php',
		'tests/migration-rollback-resume-wordpress-integration.php',
		'tests/import-export-batch-wordpress-integration.php',
		'tests/phase3-wordpress-integration.php',
		'tests/phase4-wordpress-integration.php',
		'tests/phase5-wordpress-integration.php',
		'tests/phase6-wordpress-integration.php',
		'tests/phase7-wordpress-certification.php',
		'tests/phase8-wordpress-go-live.php',
	),
	'required_multisite_tests' => array(
		'tests/contextual-modules-wordpress-integration.php',
		'tests/phase7-multisite-certification.php',
	),
	'evidence_layers' => array(
		'contract_fixtures' => 'required',
		'official_export_signatures' => 'required',
		'real_wordpress_mysql' => 'required',
		'licensed_pro_binaries' => 'externally_supplied',
	),
);
