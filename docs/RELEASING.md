# EasyRankly release process

The release archive must be built from a clean, committed tree. Development
tools, tests, CI files, and local dependencies remain in the source repository
and are filtered from the WordPress.org archive by `.distignore`.

## Prerequisites

- PHP 8.0 or newer with the Zip extension
- Composer 2
- Git
- WordPress Studio for the local P1 runtime suite
- Plugin Check for the final extracted archive check

Install the exact development dependencies recorded in `composer.lock`:

```sh
composer install --no-interaction
```

## Translation template

After changing or moving translatable source code, regenerate the POT from the
WordPress Studio site root:

```sh
studio wp i18n make-pot wp-content/plugins/easyrankly wp-content/plugins/easyrankly/languages/easyrankly.pot --slug=easyrankly --domain=easyrankly --exclude=vendor,node_modules,tests,bin,dist,docs,.github --headers='{"Report-Msgid-Bugs-To":"https://wordpress.org/support/plugin/easyrankly"}'
```

The release tests reject POT references to files or line numbers that no longer
exist.

## Candidate checks

Run the source checks before creating a release commit:

```sh
composer validate --strict
composer test
composer check:php
find . -type f -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

From the WordPress Studio site root, run the runtime suite:

```sh
studio --version
studio status
studio wp eval-file wp-content/plugins/easyrankly/tests/p1-runtime.php
```

## Deterministic build

After committing every intended change, require a clean tree and build twice:

```sh
php bin/build-release.php --output=dist/easyrankly-2.0.0-a.zip --force
php bin/build-release.php --output=dist/easyrankly-2.0.0-b.zip --force
cmp dist/easyrankly-2.0.0-a.zip dist/easyrankly-2.0.0-b.zip
php bin/verify-release.php --archive=dist/easyrankly-2.0.0-a.zip
```

The build writes a JSON inventory and a SHA-256 sidecar next to each ZIP. The
verifier checks the clean commit, manifest, archive entries, hashes, PHP syntax,
and production coding standards.

## WordPress runtime and Plugin Check

Install the extracted archive under the real `easyrankly` slug and repeat the
canonical, Classic Editor, redirects, REST/login, and external-SEO sitemap smoke
tests. Then run Plugin Check in update mode against the extracted package:

```sh
studio wp plugin check easyrankly --slug=easyrankly --format=strict-json --mode=update
```

Do not tag if Plugin Check reports a finding or if a smoke-test fixture cannot be
proved to have been removed.

## Tag and retention

Create the annotated `2.0.0` tag only after the two builds are byte-identical and
all checks pass. Retain together:

- `easyrankly-2.0.0.zip`
- `easyrankly-2.0.0.manifest.json`
- `easyrankly-2.0.0.sha256`
- the CI run linked to the tagged commit

Tagging does not authorize publication to WordPress.org. Publishing is a
separate, explicit operation.
