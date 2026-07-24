# EasyRankly Multilingual M1 contract

This directory retains the complete M1 safety net pinned to `origin/beta` commit `eccebfb` (EasyRankly 2.0.0) and adds the M2 release-bridge verification. The M1 snapshot remains immutable; the M2 scenarios validate the current 2.1 provider API and lifecycle without creating the future add-on.

## Suites

- `legacy-baseline` is the green parity suite. It characterizes the embedded provider through a replaceable test driver and covers the site/language registry, manual and inferred relations, home/posts/terms, SEO versus navigable alternates, canonical, robots, head HTML, shortcodes, REST, assets, cache, deletion, cross-site permissions, concurrent group creation, network sizes and multi-network option scope.
- `multisite-conformance` is separate and intentionally red on `eccebfb`. Its nine `ML-CONF-*` failures correspond one-for-one with section 21 of `SPECIFICATION.md`; `manifest.php` assigns each failure to M2 or M4.
- `m2-bridge` validates deterministic provider selection, legacy filter compatibility, SEO/hreflang ownership, lease/CAS, a real two-process whole-settings race, crash-safe claim/rollback, reset, and normal/retained multi-network uninstall behavior.
- `localized-value-writer` is a Single Site core-only certification for the additive API-major-1 source writer. It covers the closed allowlist, native sanitizer, fingerprint CAS, two-process stale snapshots, unrelated-setting preservation, write and verification failures, bounded errors, retry/restore idempotency, and admin/WP-CLI authorization with frontend fail-closed behavior.

The conformance suite must not be added to a green required-test list until the assigned milestone closes the corresponding defect. A non-zero exit from this suite on EasyRankly 2.0 is expected evidence, not a legacy-baseline regression.
On the pinned bundled EasyRankly 2.0 baseline, the runner also verifies the machine-readable failure list and rejects any result other than exactly `ML-CONF-001` through `ML-CONF-009`; the exit code alone is not accepted as evidence. Later core/add-on milestones can therefore close individual failures without weakening the baseline proof.
On EasyRankly 2.1, the same runner requires `ML-CONF-001`, `007`, `008`, and `009` to pass and accepts only `ML-CONF-002` through `006` as the explicit M4 expected-red set.

## Clean execution

The runner creates a disposable WordPress/PHP/MariaDB environment and removes it afterward:

```bash
bash tests/multilingual-contract/run.sh --suite=legacy-baseline --scale=3
bash tests/multilingual-contract/run.sh --suite=legacy-baseline --scale=250
bash tests/multilingual-contract/run.sh --suite=legacy-baseline --scale=501
bash tests/multilingual-contract/run.sh --suite=multisite-conformance --scale=3
bash tests/multilingual-contract/run.sh --suite=m2-bridge --scale=3
bash tests/localized-value-writer/run.sh
```

Defaults are PHP 8.4, WordPress 6.2 and MariaDB 10.11. `--php=` and `--wordpress=` select other matrix cells. Fixture creation above three sites is refused unless the runner marks the installation explicitly ephemeral.

## Provider reuse

The shared scenarios use `ERankly_ML_Contract_Driver`. The bundled driver is selected by default. A future add-on checkout can run the same contract without changing core runtime code:

```bash
ERANKLY_ML_CONTRACT_ADDON_PATH=/absolute/path/to/easyrankly-multilingual \
ERANKLY_ML_CONTRACT_DRIVER_FILE=/var/www/html/wp-content/plugins/easyrankly-multilingual/tests/contract-driver.php \
bash tests/multilingual-contract/run.sh --provider=addon --suite=legacy-baseline --scale=3
```

The add-on test adapter registers an `ERankly_ML_Contract_Driver` instance through the test-only `erankly_multilingual_contract_driver` filter. This is a testing seam, not the M2 application provider API.

`prepare.php` is provider-aware: `bundled` enables the legacy feature, while `addon` explicitly keeps it disabled before the add-on is activated. Add-on mode requires the external driver path and never loads the bundled adapter as a fallback.

## Snapshots and concurrency

`snapshots/legacy-baseline.php` stores provider-neutral URL, head, robots, shortcode, REST, asset, storage and ownership semantics. Dynamic object/site IDs and disposable hosts are replaced with stable tokens. The runner launches two WP-CLI processes behind a shared barrier and verifies that simultaneous `group_id=0` allocations receive distinct, internally consistent group IDs.
