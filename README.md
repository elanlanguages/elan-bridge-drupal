# ELAN AI Bridge for Drupal

Connect Drupal Translation Management Tool (TMGMT) jobs to the **ELAN AI
Bridge**. Drupal remains responsible for extracting content, preserving entity
and Paragraphs structure, reviewing translations, and applying revisions. ELAN
Bridge runs the translation workflow and returns translated TMGMT data items.

Version **0.2.0** adds self-service setup through the hosted
[ELAN demo](https://demo.elanlanguages.ai). Install the module, open its
configuration page, and choose **Connect to ELAN demo**. Sign in, choose your
organization and project, then return to Drupal. The wizard verifies the site's
callback and configures credentials, one provider, and language mappings.

The translation connector supports immutable snapshots, signed durable delivery,
and TMGMT editorial review. Intermediate workflow lifecycle callbacks remain
unimplemented. See the [installation and operations guide](docs/demo-installation-and-operations.md).

## Requirements

- Drupal **10.3+ or 11**
- PHP **8.1+** (use PHP 8.3+ with Drupal 11)
- [Key 1.22+](https://www.drupal.org/project/key)
- An HTTPS URL reachable by ELAN Bridge

Tested Drupal/TMGMT combinations:

- Drupal 10 with [TMGMT 1.17.0](https://www.drupal.org/project/tmgmt)
- Drupal 10 with TMGMT 1.18.0
- Drupal 11 with TMGMT 1.18.0

The v1 support boundary is symmetric Paragraphs translation. Asymmetric
per-language Paragraphs structures are not yet supported.

## Install with Composer

Composer is the canonical installation and upgrade method. Tagged releases are
distributed directly from this GitHub VCS repository until a dedicated ELAN
Composer registry is introduced.

Run these commands from the root of a Composer-managed Drupal project:

```bash
composer config repositories.elan-bridge vcs https://github.com/elanlanguages/elan-bridge-drupal.git
composer require elan/elan-bridge-drupal:^0.2
vendor/bin/drush en elan_bridge -y
```

The self-service release is `v0.2.0`. Pin `elan/elan-bridge-drupal:0.2.0` when an exact
version is required for acceptance testing.

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

## Connect the site

1. Enable the source and target languages and content translation in Drupal.
2. Open **Configuration → Region and language → ELAN AI Bridge**, or the
   module's **Configure** link. Confirm the detected public HTTPS Drupal URL.
   A public callback override is available for local development tunnels.
3. Choose **Connect to ELAN demo**. Sign in to demo and choose an organization
   where you are an owner or administrator.
4. Select an existing compatible translation project or create one for this
   site. Approve the connection and return to Drupal.
5. Confirm the connection, project, and background-processing status. The wizard
   creates or updates `elan_bridge` with the project binding and matching
   languages, keeping automatic acceptance disabled.
6. Submit a test page through TMGMT, review its translated fields, and accept it.

No manual credential exchange or database access is required for site setup.
The hosted demo must have the matching Drupal pairing endpoints and auth-schema
migration deployed. Drupal cron must run for background delivery; the wizard
reports its last run, and the operations guide explains scheduling.

Reconnect preserves existing credentials and Key references. Disconnect revokes
only this site's hosted connection and retains TMGMT history. Newly created
credentials are encrypted in site-local storage using Drupal's hash salt and
excluded from configuration exports. Preserve the hash salt with database backups.

**Advanced connection settings** retain the manual API URL, connection ID, and
Key selectors for troubleshooting and older installations. The current demo API
base is `https://tms-llm-bridge.fly.dev`. It is configured automatically; the demo
UI URL is not the API base URL.

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

HTTP 2xx is delivered; network errors, 408, 425, 429, and 5xx are retried with capped
backoff; other 4xx responses become durable failures. After repairing the
connection, an administrator can use **Retry failed events** on the module
settings page. Drupal cron also recreates lost queue wake-ups and stale worker
leases from the durable outbox.

## Development

```bash
composer update
composer check
bash bin/build-module-zip.sh --expect 0.2.0
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
