# Drupal demo installation and operations

Module 0.2.0 connects through the hosted [ELAN demo](https://demo.elanlanguages.ai).
The Drupal administrator can complete setup without manually exchanging keys,
connection IDs, or binding IDs. The ELAN account must have owner or administrator
access to the selected organization. Install the module on the Drupal host;
translation execution stays on the hosted Bridge.

## Before installation

- Back up the Drupal database, files, configuration, and Composer lock file.
- Use Drupal 10.3+ or 11, with PHP 8.3+ for Drupal 11. Enable the languages and
  content translation settings that the site's TMGMT workflow needs.
- Use the site's public HTTPS address. The wizard detects it and verifies
  callback access automatically. Bridge must reach `/elan-bridge/v1/setup/probe`
  during setup and `/elan-bridge/v1/snapshots/` during translation. Drupal must
  reach the hosted demo and its API over HTTPS.

## Install and connect

Run from the Composer-managed Drupal project root:

```bash
composer config repositories.elan-bridge vcs https://github.com/elanlanguages/elan-bridge-drupal.git
composer require elan/elan-bridge-drupal:^0.2
vendor/bin/drush en elan_bridge -y
vendor/bin/drush cache:rebuild
```

The root project must include the Drupal package repository. Composer resolves
TMGMT and Key as separate dependencies. PHP's OpenSSL extension is required for
credential storage.

1. Open the module's **Configure** link or **Configuration → Region and language
   → ELAN AI Bridge** at `/admin/config/regional/elan-bridge/setup`.
2. Confirm the detected **Public Drupal URL**. Include a Drupal subdirectory
   when applicable. Local DDEV sites need a public HTTPS tunnel override for
   callbacks; the browser can still return to the local `.ddev.site` address.
3. Choose **Connect to ELAN demo**. Sign in, select your organization, then select
   an existing compatible project or create one for this site.
4. Choose **Connect and return to Drupal**. Setup verifies the callback,
   provisions the hosted connection and project binding, stores the keys, and
   configures the single ELAN AI Bridge provider automatically.
5. Check the success message and project name. The provider contains mappings
   for languages enabled in both Drupal and the selected project. A newly
   created project uses the site's source and all enabled target languages.
6. Check the background-processing message and arrange Drupal cron if needed.
   Submit a test page and complete the review steps below.

A project already bound to another content source is rejected rather than
reassigned. Use another project or create one. A site with multiple pre-existing
ELAN providers must consolidate those configurations before automatic setup.

Setup expires after ten minutes. Repeating Connect during a live setup resumes
it. On an interrupted project-creation response, select the project that was
created; setup will not silently create another one. An expired setup can be
restarted from Drupal. The callback is accepted only for the administrator who
started the matching Drupal setup session.

**Reconnect** reuses the site's connection and credentials. Choose the same
project to preserve its binding. **Disconnect** revokes that hosted connection
and preserves review results and history; pending translations must finish or be
cancelled first. The last site URL and project are retained for reconnection.

New keys use the module's encrypted site-local Key provider and are excluded
from configuration exports. Back up both the database and the original
`settings.php` hash salt. Existing Key references remain intact on reconnect.

**Advanced connection settings** retain manual configuration for diagnosis.
The environment is `demo.elanlanguages.ai`; the API currently runs at
`https://tms-llm-bridge.fly.dev`. Setup supplies this API URL automatically.
The generic project autosave defect remains tracked separately as
[bridge-ui #263](https://github.com/elanlanguages/bridge-ui/issues/263).

## Language mappings

Map English `en` to `en` as the source. The tested target mappings are identical
on both sides:

| Language | Drupal and Bridge code |
|---|---|
| Bulgarian | `bg` |
| Czech | `cs` |
| Danish | `da` |
| German | `de` |
| Estonian | `et` |
| Greek | `el` |
| Spanish | `es` |
| French | `fr` |
| Croatian | `hr` |
| Italian | `it` |
| Latvian | `lv` |
| Lithuanian | `lt` |
| Hungarian | `hu` |
| Maltese | `mt` |
| Dutch | `nl` |
| Polish | `pl` |
| European Portuguese | `pt-pt` |
| Romanian | `ro` |
| Slovak | `sk` |
| Slovenian | `sl` |
| Finnish | `fi` |
| Swedish | `sv` |

If an existing site's Drupal language IDs differ, map those IDs explicitly to
the Bridge codes and test them. Do not assume `pt` selects European Portuguese.

## Schedule delivery

Submissions persist in Drupal before network delivery. Configure a recurring
Drupal cron runner on the target platform, for example every minute. Ordinary
Drupal cron recovers the durable outbox and processes the registered
`elan_bridge_delivery` queue. Avoid overlapping runners.

For an isolated ELAN runner or a manual diagnosis, run both commands in order:

```bash
vendor/bin/drush php:eval '\Drupal::service("elan_bridge.event_outbox")->recover();'
vendor/bin/drush queue:run elan_bridge_delivery
```

The recovery step recreates missing queue entries and recovers stale delivery
leases. A one-time queue command is not an unattended schedule. The September
13 local test used a manual queue run; the receiving platform's scheduler still
needs installation and a recovery rehearsal.

## Submit, review, and accept

1. As an editor with TMGMT submission and acceptance permissions, select an
   English content item in the site's TMGMT source overview or translation tab.
   Request the target language and select ELAN AI Bridge on the job checkout.
2. Submit the job. The runner delivers its event, and hosted Bridge reads the
   immutable snapshot and returns the translated fields.
3. Open the item under `/admin/tmgmt/jobs` when it reaches **Needs review**.
   Review every field, including nested Paragraphs and formatted text.
4. Make any corrections and select **Save**. Reopen the item to confirm the
   correction persisted. Select the intended publication setting, then choose
   **Save as completed** to apply the translation through TMGMT.
5. Inspect the target-language page and the English source. Confirm the target
   includes the approved correction and the English content is unchanged.

Bridge returns review data. TMGMT acceptance applies it to Drupal. Publication
follows the site's TMGMT controls and moderation configuration; review the
actual target status after acceptance. The tested CMEMS editor kept Published
unchecked, and both source and accepted target remained unpublished.

## Diagnose and retry

| Symptom | Action |
|---|---|
| Provider unavailable | Check connection settings, both Key values, a positive binding ID, and language mappings. |
| Job waits with no hosted execution | Check the Drupal queue runner, outbound HTTPS access, and Drupal logs for event delivery errors. |
| Hosted execution fails | Inspect the project's execution history in demo. Confirm Bridge can reach the Drupal snapshot routes and that its bearer matches Drupal. |
| Authentication or signature rejected | Have the administrator verify both sides of the affected credential and the host clock. Keep the bearer and HMAC secret separate. |
| Durable event failed | Repair the cause, save settings, then use **Retry failed events** on the ELAN settings page and let the runner process the queue. |
| Result rejected | Inspect locale, complete field keys, snapshot state, and validation errors. Correct the cause before resubmitting. |
| Demo project says Failed to save | Follow [bridge-ui issue #263](https://github.com/elanlanguages/bridge-ui/issues/263). Generic project autosave resends a polling binding that the API rejects. Affected settings edits may not persist. Translation execution passed with the existing binding. |

Network failures and HTTP 408, 425, 429, and 5xx responses retry with capped
backoff. Other 4xx responses become durable failures. Retrying an outbound event
preserves its event and submission IDs. A deliberate new TMGMT submission
creates a new submission ID. A hosted workflow that already received its event
needs diagnosis in demo; the Drupal failed-event button does not restart every
kind of hosted failure.

The connector imports complete translation results and rejects invalid results.
Identical result delivery is idempotent. Intermediate hosted running/failed
notifications are not implemented, so inspect demo execution history when a job
waits unexpectedly.

## Upgrade and rollback

Record installed versions and take a restorable backup before an upgrade.
Pause submissions and queue processing while changing versions, then run:

```bash
composer update elan/elan-bridge-drupal --with-all-dependencies
vendor/bin/drush updatedb -y
vendor/bin/drush cache:rebuild
```

Resume processing and run an unpublished submission through review and
acceptance. To roll back, restore the matching application code, Composer lock,
database, and configuration backup under the site's recovery procedure. Reconcile
hosted jobs created after that backup before resuming; restoring Drupal alone
does not undo hosted executions.

Do not delete a provider while jobs still reference it. TMGMT can abort those
jobs during provider deletion. Back up first, migrate references deliberately,
verify item data and states, and delete only after the old reference count is
zero. The local CMEMS consolidation preserved all 23 existing job items.

## Acceptance scope and support

The September 13 self-service rehearsal installed a separate local Drupal site
and connected it to demo through the browser. Setup created the connection,
project, keys, language mappings, and one provider without copying credentials
or binding IDs. Disconnect and reconnect reused the same connection and binding.
Browser submission covered all 22 target languages. Drupal automatic cron
delivered all 22 events; 44 fields and 242 structural assertions passed. A German
correction survived save/reopen and acceptance, with all nine acceptance checks
passing. This verifies request-triggered cron, not an operating-system scheduler
or outage recovery.

The earlier September 13 translation checks covered 22 languages, 66 returned fields, preserved
HTML and links, immutable source content, one provider, and normal-editor
acceptance of a corrected German nested Paragraph. They used a synthetic
unpublished page. Representative customer content, linguistic approval,
unattended recovery, and a clean installation by the receiving administrator
remain delivery checks. A temporary laptop tunnel must be replaced by the
receiving site's stable HTTPS callback URL.

Contact [ELAN support](mailto:support@elanlanguages.com) or your account manager
with the Drupal job/item ID, time, target locale, module version, and demo
execution ID. Keep credentials and source content out of public issue reports.
