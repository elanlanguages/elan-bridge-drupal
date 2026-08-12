# ELAN AI Bridge for Drupal

Connect Drupal Translation Management Tool (TMGMT) jobs to the **ELAN AI
Bridge**. Drupal remains responsible for extracting content, preserving entity
and Paragraphs structure, reviewing translations, and applying revisions. ELAN
Bridge runs the translation workflow and returns translated TMGMT data items.

> This repository is under active development. The first implementation slice
> provides the TMGMT translator, signed durable event delivery, immutable
> JobItem snapshot endpoints, and review-stage result import. Browser-based
> pairing, the Bridge-side Drupal HTTP connector, and lifecycle callbacks are
> the next slices. This initial commit is not yet an end-to-end release.

## Requirements

- Drupal **10.3+ or 11**
- PHP **8.1+** (use PHP 8.3+ with Drupal 11)
- [TMGMT **1.17 or 1.18**](https://www.drupal.org/project/tmgmt)
- [Key 1.22+](https://www.drupal.org/project/key)
- An HTTPS URL reachable by ELAN Bridge

The v1 support boundary is symmetric Paragraphs translation. Asymmetric
per-language Paragraphs structures are not yet supported.

## Install with Composer

Composer is the canonical installation and upgrade method. Tagged releases are
distributed directly from this GitHub VCS repository until a dedicated ELAN
Composer registry is introduced.

Run these commands from the root of a Composer-managed Drupal project:

```bash
composer config repositories.elan-bridge vcs https://github.com/elanlanguages/elan-bridge-drupal.git
composer require elan/elan-bridge-drupal:^0.1
vendor/bin/drush en elan_bridge -y
```

Before the first tagged release, development environments can require
`elan/elan-bridge-drupal:dev-main` instead of the `^0.1` constraint.

Composer installs the connector at `web/modules/contrib/elan_bridge` and
resolves TMGMT and Key as independent packages. Do not copy either dependency
into this module. The Drupal package repository must be configured in the
consuming project's root `composer.json`; projects created from Drupal's
recommended Composer template already include it. Repository declarations in a
dependency's `composer.json` are intentionally ignored by Composer.

### Upgrade

Update the connector and its compatible dependencies from the Drupal project
root:

```bash
composer update elan/elan-bridge-drupal --with-all-dependencies
vendor/bin/drush updatedb -y
vendor/bin/drush cache:rebuild
```

### Install a local checkout for development

Use a Composer path repository instead of copying the module manually:

```bash
composer config repositories.elan-bridge path /absolute/path/to/elan-bridge-drupal
composer require elan/elan-bridge-drupal:@dev
vendor/bin/drush en elan_bridge -y
```

Composer normally symlinks a path repository, so edits in the checkout are
immediately visible to the Drupal site.

## Configure the initial connection

1. In Drupal's Key module, create two independent authentication keys:
   - a bearer token used when Bridge reads or writes TMGMT snapshots;
   - an HMAC secret used when Drupal sends events to Bridge.
2. Open **Configuration → Translation → ELAN AI Bridge** and enter the Bridge
   API base URL, Bridge connection ID, and the two Key references. The base URL
   is joined directly with `/connectors`; include a proxy prefix such as `/api`
   in the configured base only when that proxy exposes the Drupal route.
3. Open **Translation → Providers**, add an **ELAN AI Bridge** translator, and
   enter the numeric Bridge project binding ID. Leave TMGMT automatic
   acceptance disabled so every result stops for editorial review.
4. Map Drupal language codes to the locale codes used by the paired Bridge
   project in the TMGMT translator configuration.

The manual connection fields are temporary. The planned pairing flow will mint
and rotate both credentials server-to-server, then let the administrator choose
the ELAN organization and project without copying secrets through the browser.

## Runtime contract

Submitting a TMGMT job creates one immutable submission for each JobItem:

1. The module hashes the JobItem's cached TMGMT source data and creates an
   opaque snapshot token.
2. It persists a `cms.translation.requested` event before network I/O.
3. Drupal Queue API delivers the event with a stable event ID and fresh HMAC
   signature on every attempt.
4. Bridge pulls only the opaque snapshot, runs exactly the signed project
   binding and target locale, then posts translated values against the same
   token.
5. The module calls TMGMT `addTranslatedData()`. Complete results enter
   **Needs review**; the module never accepts or applies the entity translation.

Transport retries preserve both `event_id` and `submission_id`. A deliberate
new TMGMT submission gets a new `submission_id`, even if the underlying source
revision has not changed.

### Bridge-facing endpoints

- `GET /elan-bridge/v1/snapshots/{token}` returns canonical source keys,
  existing TMGMT translations, immutable version metadata, and data-item
  constraints.
- `POST /elan-bridge/v1/snapshots/{token}/translations` accepts
  `{ "locale": "de", "values": { "opaque][key": "..." } }`.

Both endpoints require `Authorization: Bearer <site credential>`. Snapshot
tokens are random and never expose Drupal entity IDs.

### Event signature

Drupal posts to `{bridge_api_base_url}` plus:

```text
/connectors/drupal/webhook/{connection_id}
```

The signature is lowercase HMAC-SHA256 over the timestamp header concatenated
with the exact JSON bytes:

```text
X-ELAN-Event-ID: <stable UUID>
X-ELAN-Timestamp: <unix seconds>
X-ELAN-Signature: sha256=<hex digest>
```

HTTP 2xx is delivered; network errors, 429, and 5xx are retried with capped
backoff; other 4xx responses become durable failures. After repairing the
connection, an administrator can use **Retry failed events** on the module
settings page. Drupal cron also recreates lost queue wake-ups and stale worker
leases from the durable outbox.

## Development

```bash
composer update
composer check
bash bin/build-module-zip.sh --expect 0.1.0
```

CI pins three compatibility lanes: current Drupal 10 with TMGMT 1.17.0 on PHP
8.1, current Drupal 10 with TMGMT 1.18.0 on PHP 8.1, and current Drupal 11 with
TMGMT 1.18.0 on PHP 8.3. Release tags matching `v*` build a clean
`elan_bridge.zip` artifact, following the WordPress companion repository's
release convention.

## Support

Email [support@elanlanguages.com](mailto:support@elanlanguages.com) or contact
your ELAN account manager.

## License

This module is proprietary software, matching the ELAN AI Bridge WordPress
companion. No open-source license is granted by this repository.
