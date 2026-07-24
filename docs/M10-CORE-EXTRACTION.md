# M10 — EasyRankly 3.0 core extraction

EasyRankly 3.0 removes the concrete multilingual product from the core plugin.
The independently versioned EasyRankly Multilingual plugin owns its runtime,
storage, ownership journal, screens, routes, editor surfaces, blocks,
shortcodes, cache and lifecycle.

The core retains only provider-neutral contracts:

- extension API major 1 and the fail-closed provider registry;
- SEO-state, canonical and localized-source APIs;
- sanitized SEO and navigable alternate filters;
- localized URL, robots and schema extension hooks;
- generic settings-tab actions and a shared whole-settings mutex.

Core reset and uninstall never inspect, convert or delete multilingual tables,
options, journals or ownership markers. A 2.1-to-3.0 upgrade leaves legacy data
unchanged and records an administrator notice. Whole-settings writes preserve
already-stored extension keys that are outside the current core defaults.

## Upgrade and rollback

For continuous multilingual operation, install EasyRankly Multilingual 1.1.1
while core 2.1 is still active, then upgrade the core to 3.0. The add-on uses
the 2.1 ownership runtime on old core and supplies that runtime itself on 3.0,
while sharing the neutral settings mutex in both directions.

Rollback restores the exact EasyRankly 2.1 package. Core 3.0 performs no
storage conversion, so the 2.1 runtime can read the unchanged legacy topology.
The add-on must remain active while any ownership rollback is prepared.

## Gate status

M9 1.1.0 was certified locally. The dependency requiring a completed
production cycle before M10 was explicitly waived by the operator for this
implementation; it is recorded as skipped, not passed. This baseline therefore
does not authorize production, publication, remote operations, tags or a
release.

## Locally observed implementation evidence

The uncommitted M10 implementation baseline was exercised on 2026-07-25 from
core parent `48bb147d557256a854b7d4e1381fe9bf81ab4740` and add-on parent
`8b39e48590c1a61adcfdc515d8d499b8829b07d4`.

The deterministic core candidate `easyrankly-3.0.0.zip` contains 140 ordered
entries, is 556309 bytes and has SHA-256
`1a37f683bbca9018cca7371b89a9e6abd303ca9a08abe42f7ca7c8ece1b00161`.
Two independent builds were byte-identical. Source-to-ZIP comparison,
`unzip -t` and the concrete multilingual path/token absence scan passed.

The cross-package true-ZIP matrix passed on WordPress 6.2/PHP 8.0 and
WordPress 7.0.2/PHP 8.4. It covered core 3.0 with the add-on absent, active and
deactivated; add-on-first upgrade from core 2.1; zero-copy Multisite adoption;
core network reset; exact downgrade to core 2.1; unadopted legacy storage; and
core uninstall while add-on storage exists. The public localized-source writer
also passed read/write, CAS, restore, privacy and two-process concurrency on
WordPress 6.2/PHP 8.0.

PHP lint, WPCS, PHPCompatibilityWP 8.0+, JavaScript/CSS quality, the standalone
migration/security/performance suites, deterministic POT and Composer strict
validation passed. Plugin Check and a networked dependency audit were not
rerun for this uncommitted M10 baseline.
