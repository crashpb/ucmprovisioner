# Architecture and code map

This map covers the maintained source and schema 34. Source mechanisms and
synthetic tests do not establish installed-version or production acceptance.

## Purpose and design boundary

UCM Provisioner is a FreePBX 17 BMO module that keeps five representations of a
phone deployment consistent:

1. the physical phone and its model/firmware limitations;
2. the FreePBX SIP or PJSIP endpoint and credentials;
3. the generated TFTP configuration and companion assets;
4. Asterisk Cisco glue, hints and inter-site publication objects; and
5. directory identity, templates, groups and site-owned policy.

The module is not only a file templater. A change is complete only when every
affected representation resolves to the same endpoint route and compatible asset
set. This is why some validation and migration logic appears in more than one
operational phase: the owner is shared, but validation must still run before each
irreversible boundary.

## Runtime overview

```text
FreePBX browser
      |
      v
page.ucmprovisioner.php ---- server-rendered views + module JS/CSS
      |
      v
Ucmprovisioner.class.php (BMO/controller and federation dispatch)
      |
      +--> policy/resolution services --> vendor generators
      |                                  |
      |                                  v
      +--> BuildPlanner/transactions --> TftpStore --> resolved SFTP TFTP backend
                                                        |-- PBX tftpd-hpa tree
                                                        `-- remote Docker volume
      |
      +--> AsteriskGlue/PublishMesh --> /etc/asterisk managed blocks
      |
      +--> directory/assets/replication --> DB + private state directory
      |
      +--> fixed privileged helpers --> Fail2ban / Asterisk build activation
```

Remote sites normally use the encrypted federation API. The restricted
forced-command SSH agent is retained for bootstrap, recovery and operations that
cannot be performed through the web endpoint. Managed inter-site asset copies
prefer the API and can select SFTP/SSH during preflight. Each owning PBX accesses
its active TFTP backend through the separately enrolled `ucmp-tftp` SFTP account,
whether the phone-facing daemon runs on that PBX or on a remote Docker host.

## Entry points

ApiFileStream uses PHP/libsodium secretstream XChaCha20-Poly1305 with bounded
1 MiB authenticated records and a required final tag. Existing AES-GCM metadata
binds file identity, size, request nonce and operation receipt. A 100 MiB body adds
1,724 bytes. Large-file PUT avoids PHP form POST size limits. The owning PBX
receives to a private temporary file without holding the TFTP writer lock, stages
it in one SFTP write, hashes staged bytes and checks destination revision before
atomic publication. Source snapshots are reused across destinations within a copy.
Binary transfers require curl/sodium and receiver capability; older peers retain
the prior protocol. Negotiated rc19 uploads use 16 MiB ranges; the sender retains
the receiving transaction ID in local settings keyed by exact source manifest,
destination and overwrite policy. The destination records confirmed offsets and
range digests under its existing writer lock. Exact duplicate ranges are harmless;
an incomplete private range can be overwritten, while conflicting/gapped ranges
are refused. At most two same-API retries use fresh operation receipts. Final
publication retains its existing no-replay reconciliation rule. Legacy whole-file
uploads still use receipt lookup after a lost acknowledgment.


Replication negotiates binary file streams and bounded small-file API batches through `TftpSyncService`; receivers
reuse the checked upload and destination commit boundary in `TftpStore`.
`StorageBusyRetry` accepts only authenticated pre-write lock refusals. It does not
replay partial/unknown writes, steal locks or change transports. Request-local
HTTP reuse and capability-based chunk sizes preserve older-peer compatibility.
Manual transfer stages feed `ReplicationActivityService` during work; a committed
sub-operation is distinct from successful completion of all destination scopes.

| File | Responsibility |
|---|---|
| `Ucmprovisioner.class.php` | FreePBX BMO lifecycle, authenticated actions, schema reconciliation, local/remote dispatch and service composition. |
| `page.ucmprovisioner.php` | Validates tab/view routing, prepares common view state and renders one module tab. |
| `api.php` | Public authenticated federation endpoint: POST RPC/download and PUT binary upload. It bootstraps only Core and this module. |
| `directory.php` | Read-only phone-facing directory/search response endpoint. |
| `web_phonebook.php` | Separate network-restricted employee page, data and XLSX entry point. |
| `phone_services.php` | Read-only phone-service menu and application responses. |
| `media_asset.php` | Token/path-validated phone-facing hosted media response. |
| `tftp_download.php` | Authenticated administrator download of selected TFTP files/directories. |
| `cli_ad_sync.php` | One-minute directory schedule evaluator. |
| `cli_mesh_sync.php` | One-minute BLF federation schedule evaluator. |
| `cli_phone_rebuild.php` | One-minute scheduled rebuild/reboot evaluator. |
| `cli_media_sync.php` | One-minute media, firmware and configuration-replication evaluator. |
| `cli_rebuild.php` | Administrator CLI preview/apply entry point. |
| `install.php` / `uninstall.php` | Install-time directories/endpoints/helpers/cron and safe removal behavior. |

The main BMO class is intentionally a compatibility façade: FreePBX and remote RPC
call its public methods. New domain logic should live under `lib/`, while existing
public method names remain stable until federation versioning can explicitly retire
them.

## User-interface map

The `views/` directory contains one server-rendered view per primary tab:

- `dashboard.php` — site health, module/Asterisk status and recent operations.
- `phones.php` — inventory, discovery, status, build actions and phone editor.
- `templates.php` — template inventory/editor, applicability and template push.
- `groups.php` — inherited groups, ordered template layers, membership/exceptions.
- `sites.php` — PBX/API/SSH/TFTP enrollment, host keys and BLF peers.
- `directory.php` — directory sources, mapping, conflicts, phonebook designer.
- `patches.php` — Asterisk/USECALLMANAGER inventory and build/activation workflow.
- `media.php` — wallpaper workbench, legacy media, audio, replication and index.
- `firmware.php` — firmware, Cisco timezone/locale companions and replication.
- `services.php` — phone-service catalog and model-aware preview.
- `fail2ban.php` — jail inspection and explicit unban actions.
- `tftp.php` — managed-aware file browser, upload/download/delete and override state.
- `settings.php` — site policy, default selectors/assets, schedules and archives.

`assets/js/ucmprovisioner.js` provides shared authenticated requests, site-context
retention, pickers, progress activity and tab behavior. The module stylesheet owns
the visual system used by all views; a view should not introduce an independent
component theme.

## Configuration resolution

`TemplateEngine` constructs one immutable resolved phone record. The effective
order is:

```text
documented built-in defaults
  -> selected site's saved settings
  -> either:
       compatible inherited group layers (root to leaf, broad to exact model)
       OR the single most-specific site default template
  -> matching member-exception layer, when present
  -> phone-specific setting/key overrides
  -> AD/logical-extension identity formatting and final identity override
  -> capability validation and companion-asset resolution
```

A site default is used only when the phone has no group or its inherited group path
contains no compatible template layer. It is not placed underneath a compatible
group stack. Within a group, broad policy layers run before firmware/config-profile
layers and exact-model layers run last. Blank or inherited values leave the prior
value intact. An explicit narrower value can replace it.

Member exceptions are final applicability-checked layers for one or more selected
extensions. Explicit scalar settings replace earlier values. A non-empty
programmable-key/BLF list replaces the earlier list rather than appending to it.

`DefaultTemplateResolver` rejects equally specific matching defaults. A trace of
applied and skipped candidates is returned to both preview and generation.
`TemplateStackResolver` rejects parent cycles and cross-site parents and records why
each assignment applied or did not apply.

## Phone build transaction

```text
1. Read the last completed managed-asset inventory.
2. Resolve the complete site fleet for global Asterisk glue.
3. Generate files for the selected phone set without writing.
4. Build companion dial/softkey/feature-policy files and the whole-site glue plan.
5. Canonicalize the plan and calculate a plan hash.
6. Return the preview; apply requires that exact hash.
7. Stage TFTP files and record pre-change state.
8. Apply managed Asterisk blocks and atomically commit the TFTP generation.
9. Commit asset/build metadata; compensate from receipts if a later phase fails.
10. Send a supported reload/reboot command only after a successful commit.
```

Asset rescans are deliberately not run inside preview/apply. An inline scan can make
preview and apply observe different inputs and falsely report that configuration
changed. Scans are explicit, resumable jobs; builds consume their last completed
inventory.

Even a single-phone rebuild resolves the full fleet when producing Asterisk glue.
That is required because the managed block in `sip_custom_post.conf` is shared; a
partial global view could remove unrelated extensions.

`BuildPlanner` owns canonical hashes and diff summaries. `BuildTransactionService`
owns durable build/apply records. `TftpStore` owns safe paths, staging, permissions,
file lifecycle and downloads. `Provisioner` coordinates these services and the
vendor generators.

`OperatorWorkflowService` owns fixed review/execute plans for rebuild, identity and
replacement, plus read-only Explain/Impact projections. Its browser presentation
is currently structured JSON. It separates desired policy, published generation,
PBX activation and device observation; an accepted server command cannot set an
unknown physical-device outcome to success. Full-fleet identity plans fix owner and
derived-consumer destinations, stage bounded content and reject changed plan inputs.

`ucmp_operator_changes` retains fixed plans and checked final outcomes. Read-only
owner/directory history projects safe result fields; `applying` is explicitly
uncertain and never grants replay. Replacement plans/results retain only safe
before/after phone/model/extension mappings, not raw phone configuration or secrets.
Reverse instructions use fresh move/swap/unassign reviews with current hash and
logical-revision guards. They are assignment recovery guidance, not automatic or
exact full-configuration rollback; publication and device checks remain separate.

`TftpStore::withSiteLock` acquires a destination-side directory lock with a checked
private owner receipt. Nested local writers reuse their owned lock; cross-site
source/destination locks acquire in a consistent order and fail rather than wait
indefinitely. No elapsed-time rule steals a lock. Recovery requires administrative
verification of all writers and retained receipts before removal of an exact lock.

## Asterisk ownership

`AsteriskGlue` maintains marked blocks in:

- `/etc/asterisk/sip_custom_post.conf` for Cisco USECALLMANAGER endpoint options;
- `/etc/asterisk/extensions_custom.conf` for Cisco service URI handlers and
  cross-site hint support; and
- the module's generated include/override content where required by the installed
  FreePBX layout.

It preserves content outside module markers, detects unrecognized same-extension
blocks, and migrates byte-equivalent historical blocks into managed ownership.

`PublishMesh` transactionally maintains `/etc/asterisk/pjsip_custom.conf` objects
for `res_pjsip_publish_asterisk`. Prepare, commit and rollback use file hashes and a
private receipt under `/var/lib/asterisk/ucmprovisioner/publish-mesh`. A rollback
refuses to overwrite a file changed after commit.

Asterisk CLI calls that can be reached by the web application are time-bounded.
Failure to reload is reported separately from whether files were safely committed.

`ExtensionBinder` verifies FreePBX Core writes and retains compensation evidence;
rollback must preserve unmanaged values and report incomplete restoration.
`AsteriskVersionManager` similarly compares complete retained runtime identity,
not a version string alone. Failed compensation or unverified final receipt remains
`recovery_required` rather than being flattened into success or automatic replay.

## Model and vendor ownership

`ModelCatalog` is the single compatibility authority. It maps models to:

- provisioning family and role;
- line/account/key capacity;
- firmware/config profile;
- Cisco timezone runtime and locale companions;
- wallpaper/media profile;
- VLAN support;
- video and DECT behavior; and
- supported phone command method.

Views, template validation, firmware/media selectors and generators must derive
their choices from this catalog. A model must not be added only to a dropdown or a
generator.

Vendor output is owned by:

- `CiscoXmlGenerator` — `SEP<MAC>.cnf.xml`;
- `CiscoCnfGenerator` — `SIP<MAC>.cnf` plus legacy defaults/sync companions;
- `YealinkGenerator` — lowercase `<mac>.cfg`;
- `GrandstreamGenerator` — `cfg<mac>.xml`;
- `DialTemplateGenerator` — Cisco dialing-rule XML; and
- `SoftkeyPolicyGenerator` — stack-addressed softkey/feature-policy XML.

Firmware-rendered softkeys that are not configurable XML entries are represented as
visual editor overlays. They are intentionally not serialized. Earlier schema-less
softkey policies remain readable for upgrade compatibility; once resaved in the
current schema, explicit inherit/enabled/disabled values become authoritative.

## Directory and identity flow

```text
independent AD source(s)
  -> bounded LDAP read and completeness checks
  -> atomic accepted source revision
  -> site mapping + logical extension identity
  -> audited explicit override, if any
  -> generated phonebook policy
  -> private directory cache
  -> phone-facing vendor response
```

Each domain controller is authoritative for its configured source. A complete
zero-result revision is accepted. Connection, bind, paging or completeness failure
preserves the prior accepted revision. PBX reachability does not change directory
truth.

`LogicalExtensionService` keeps person/extension identity independent of a physical
MAC so a phone can be deleted or replaced without erasing directory membership.
An AD number/site change is applied unless it collides with a manual contact or an
ambiguous identity. `IdentityLabelFormatter` applies bounded tokens; final audited
extension overrides win over generated label patterns.

The logical binding explicitly selects `ad_primary`, `linked_additional` or
`manual_shared`. Primary AD assignment, a stable additional-person link and a manual
slot have distinct owners. Adoption gates legacy fields. Full caller/person names
remain separate from short phone/line/BLF labels; final overrides retain their
person association across release/reassignment and cannot silently apply to the
next person using the number.

`IdentityDeliveryService` accepts versioned owner relationships and maintains a
derived projection for owner builds and foreign BLF consumers. `TemplateEngine`
can use a cached accepted projection during normal generation, but an explicit
local authority wins. Unresolved additional links retain prior delivery rather
than stealing a primary binding. Complete accepted-source departures retain
delivery tombstones atomically with logical binding reconciliation.

Phonebook replication transfers compiled contacts and display/search policy. It
does not transfer directory credentials. A destination administrator can copy the
replicated layout into a new local-AD phonebook without copying contacts.

`DirectoryService::planAcceptedPhonebooks` returns only a safe hash and fixed
destination IDs/site keys. The hash binds resolved contacts, compiled books,
definitions, layout, accepted freshness and source identity. Reviewed publication
recreates/checks that snapshot before delivery and passes captured values to every
destination, including token refresh. Newly enabled destinations are not appended
to a reviewed run. The no-argument scheduled path still uses the current enabled
set. No raw employee data or tokens appear in the public plan.

### Employee directory boundary

`WebPhonebook` projects only selected fields from selected compiled books and
hashes source identities into book-scoped public selection IDs. It never falls
back to cache-wide entries or emits raw AD/source metadata. Its PBX-local private
policy is independent of phone tokens, replication and configuration packages.
An enabled explicit private/VPN IPv4/IPv6 CIDR allowlist checks the connecting IP
in direct mode; forwarded headers are ignored, not rejected. An allowed proxy's
audience is controlled upstream. Optional trusted-proxy mode requires an exact
peer IP and one configured single-IP header, rejecting missing, chained,
conflicting and untrusted headers. The preferred URL is metadata, not proxy setup.

`WebPhonebookHttp` applies that boundary to the page, same-origin static assets,
data and bounded JSON POST export. `PhonebookWorkbook` writes an Open XML workbook
with the existing ZIP extension; all cells are inline strings, never formulas.
The browser submits ordered selected IDs and allowed column names, not values.
The server reselects values from its current projection and verifies a revision
covering policy, membership and field data, preventing mismatched stale exports.
The no-framework browser interface filters the field-limited snapshot locally;
there is no duplicate directory-authority or identity-reconciliation engine.

Search ranks exact extension/name-prefix matches and uses only projected fields;
copy actions, site ambiguity and accepted-versus-generated freshness are explicit.
Export is separately policy-gated and includes policy/freshness context. Alternate
name mapping is limited to projected canonical name/label, not raw LDAP attributes.
`reviewPublication` compares actual compiled old/new membership and binds
exceptional audience-change approval to the current candidate and policy revision.

## Managed asset lifecycle

`ManagedAssetRegistry` records logical identity, path, SHA-256, size, provenance,
validation state, compatibility, manifest and use references. `AssetFileInspector`
derives facts from bytes. `AssetLifecycleService` joins registry records with live
catalog/default/template usage. `ManagedAssetScanJob` makes large scans resumable
and bounded.

State meanings:

- **verified/current** — required bytes and manifest roles exist and match hashes;
- **discovered/observed** — recognized bytes exist but have not been adopted by a
  module-owned bundle/catalog or generation receipt;
- **incomplete** — a declared bundle is missing required roles;
- **stale** — the registered bytes/path no longer match the active logical object;
- **invalid** — content or compatibility validation failed;
- **retired** — the logical object was retired; retained recovery bytes may remain.

Generated wallpaper variants and vendor index files are children of one wallpaper
catalog. Lifecycle operations must preserve that logical set. Firmware and
timezone revisions retain their own immutable payload manifests. Replication moves
the selected logical object and its required portable dependencies before a
destination default/template is applied.

`AssetReferenceIndex` and `AssetStorageReferences` include database usage, generated
aliases and retained rollback references. `AssetRetirementService` binds mutable
reassignment and quarantine to a fresh graph revision; an incomplete inventory or
active scan blocks destructive retirement. `HostedAssetRetirement` preserves the
exact HTTP asset and complete editable catalog with a checked recovery receipt.
Explicit restoration is site/library-bound and cannot overwrite a newer revision.
Quarantine is not permanent deletion and does not reclaim retained bytes.

`MediaChunkTransfer` fixes generic HTTP transfer identity, size and SHA-256 across
chunks. Private per-transfer staging/receipts prevent same-filename interleaving;
identical retries are idempotent, publication checks the original destination and
keeps prior bytes. Special catalogs retain their separate role/manifest transfer
workflow. All these server-side guarantees remain distinct from handset fetching.

## Federation and offline behavior

Managed TFTP asset replication is coordinated by `TftpSyncService`. It selects a
fixed channel during a read-only capability preflight, obtains a size/hash-pinned
manifest, and sends 256 KiB chunks to private destination staging. API is primary;
API-unavailable preflight can select SFTP byte writes and restricted SSH RPC.
Authenticated rejection, trust failure and incompatible capability stop selection.
An uncertain mutation only queries its original RPC receipt and cannot replay on
the alternate channel.

`TftpTransferOperations`, composed into `TftpStore`, owns local source reads,
destination preparation, chunk bounds/replays, revision checks, commit and index
publication. API and SFTP share the same `.ucmprovisioner/copies/<id>` manifest,
staging, prior files and receipt. The receiving PBX takes its storage lock for each
bounded write/commit; no controller holds that lock while calling destination
registration. The full staged selection is verified before live file renames.
Files and database registration are recoverable steps rather than a distributed
atomic transaction. Finalization uncertainty retains recovery state and the lock.
Local SFTP storage access remains necessary for Docker-hosted and PBX-hosted trees.

`SiteConnection` selects local execution, encrypted API, restricted PBX SSH or the
site's active TFTP SFTP backend as appropriate. `TftpArchitectureService` owns the
bounded inventory, copy, SHA-256 verification and compensated controller/remote
activation used when changing backends. `ApiCodec` owns authenticated envelopes. `SiteSnapshotCache`
stores sanitized read results only; keys matching credential/secret patterns are
removed recursively.

Remote reads try the normal channel and may fall back to recovery SSH. A successful
read refreshes the snapshot. If both channels fail, the UI may render the dated
snapshot read-only. If no snapshot exists, it says the remote state is unknown.
Mutations never run against cached state.

Sites onboarding uses `prepareRemoteSite()` as the browser orchestration boundary.
Fresh administrator SSH reads the platform, module filesystem and FreePBX registry
without relying on a pre-existing remote agent or API. Missing/older/disabled and
repairable current installations enter the existing recovery-safe installer;
healthy current installations retain their files. Module, key-based agent,
service-account credential storage and API management probes are distinct phases.
Unknown reads cannot become blank-install permission, and either newer registry
or filesystem identity blocks downgrade. Existing healthy API keys are tested
before considering enrollment; API failure does not erase verified SSH readiness.

Docker enrollment is a separate existing-container operation. Read-only service
and bind-root preflight precedes the retained SSH policy transaction. Controller
and owning-PBX storage verification precedes their respective readiness records;
private keys stay on their owner. Enrollment metadata is not a backend cutover.

`RpcOperationJournal` separates transport delivery from operation outcome. It
records the typed request identity and encrypts the destination response receipt.
After an uncertain dispatch, a secondary channel may inspect that receipt, not
re-execute the mutation. A still-running or missing receipt is unresolved, not a
timeout-based replay grant. The federation credential establishes shared
administrative trust, not per-site cryptographic origin identity. The receiver
rejects remote claims to its own local origin authority; the system does not claim
to isolate mutually hostile federation administrators.

`ConfigurationSyncService`, `MediaReplicationService` and
`FirmwareReplicationService` retain separate schedules because their payload and
dependency rules differ, but all publish durable progress through
`ReplicationActivityService`. Multiple independent jobs can run without replacing
each other's visible progress.

Configuration synchronization is revision-aware. Destination edits to a soft-locked
object pause that object only. Missing portable dependencies fall back to the
destination default and remain visible in both the list and editor. Site identity,
addresses, credentials and signing material are not portable.

`LocalCredentialKey` performs read-only key lookup. The module install hook runs
credential conversion automatically after verifying a matching local database/key
recovery archive. It writes the identical private key and removes the legacy
database key transactionally; neither schema reads nor cron perform conversion.
`ConfigurationArchiveService` distinguishes projected
configuration from secret-bearing restricted recovery. Imports bind the local
controller and mode, verify members, stage paired database/files and retain recovery
evidence on uncertain compensation. Archive SHA-256 is not a digital signature.

## Storage map

### Database

Module tables use the `ucmp_` prefix and fall into these groups:

- inventory/policy: `ucmp_sites`, `ucmp_models`, `ucmp_phones`, `ucmp_templates`,
  `ucmp_groups`, `ucmp_default_template_rules`, `ucmp_settings`;
- identity/directory: `ucmp_extensions`, `ucmp_identity_overrides`,
  `ucmp_directory_sources`, `ucmp_directory_sync_runs`, `ucmp_directory_entries`,
  `ucmp_directory_conflicts`, `ucmp_phonebooks`;
- assets: `ucmp_assets`, `ucmp_asset_scan_jobs`, `ucmp_firmware_bundles`,
  `ucmp_timezone_bundles`;
- transactions/replication: `ucmp_phone_builds`, `ucmp_apply_transactions`,
  `ucmp_replication_runs`, `ucmp_media_replication_jobs`,
  `ucmp_firmware_replication_jobs`, `ucmp_configuration_sync_jobs`,
  `ucmp_configuration_sync_items`, `ucmp_site_jobs`, `ucmp_site_snapshots`;
- operations: `ucmp_phone_services`, `ucmp_phone_mac_suppressions`,
  `ucmp_operation_log`.

`module.xml` describes the fresh-install schema. `ensureSchema()` is the upgrade
reconciler for installations created by older versions. The two paths must converge
on the same field widths and effective constraints. A few XML indexes deliberately
start non-unique: the runtime reconciler first merges or renames historical duplicate
rows and only then promotes those indexes to unique. Declaring those constraints in
the pre-hook XML would make an otherwise recoverable upgrade fail before the cleanup
code can run.

### Filesystem

- `/var/www/html/admin/modules/ucmprovisioner` — installed module.
- `/var/lib/asterisk/ucmprovisioner` — private directory cache, schedules,
  snapshots, transfer staging, Asterisk bundles and transaction receipts.
- `/var/lib/asterisk/ucmprovisioner/secrets/directory` — mode-restricted directory
  source credential files when file-backed secret storage is used.
- `/etc/asterisk/keys/ucmp_id_rsa` — provisioner SSH key, with the older
  `/var/lib/ucmp-pbx/keys` path retained as a read fallback.
- `/var/lib/ucmp-patch-manager` — Patch Manager state and rollback metadata.
- `/usr/local/sbin/ucmp-patch-manager` and `/usr/local/sbin/ucmp-fail2ban` — fixed
  privileged entry points with matching `/etc/sudoers.d/` rules.
- `/etc/cron.d/ucmprovisioner-ad` — one-minute schedule ticks; each service decides
  whether its own saved schedule is due.
- the enrolled site's `/tftpboot` SFTP root — phone-facing files and hidden module
  catalog metadata.

Uninstall removes module tables, public endpoints, cron and privileged helpers. It
deliberately preserves phone-facing TFTP/Asterisk output and Asterisk rollback
bundles to avoid taking a working phone fleet or runtime offline merely because the
web module was removed.

## Class-by-class code map

| Class | Responsibility |
|---|---|
| `ApiCodec` | Encrypts/authenticates federation request and response envelopes. |
| `AssetCatalog` | Compatibility-facing view that joins managed asset records. |
| `AssetFileInspector` | Inspects file magic, dimensions, codecs and embedded version facts. |
| `AssetLifecycleService` | Produces validation, provenance, dependency and usage state. |
| `AsteriskGlue` | Plans and transactionally updates Cisco SIP options and hint/service blocks. |
| `AsteriskVersionManager` | Builds/imports, inventories, activates and rolls back Asterisk bundles. |
| `BuildPlanner` | Canonicalizes previews and calculates immutable plan hashes/diffs. |
| `BuildTransactionService` | Stores build and apply transaction state/receipts. |
| `CiscoCnfGenerator` | Generates legacy Cisco 7940/7960 CNF content and companions. |
| `CiscoTimezoneBundleManager` | Retains immutable Cisco timezone payload revisions. |
| `CiscoTimezoneService` | Inspects and validates individual Cisco timezone runtime files. |
| `CiscoXmlGenerator` | Generates Cisco Enterprise XML phone configurations. |
| `ConfigurationArchiveService` | Exports/imports selected module-owned configuration and managed files. |
| `ConfigurationSyncService` | Schedules and tracks revision-aware policy synchronization. |
| `DefaultTemplateResolver` | Selects one most-specific default when no group layer applies. |
| `DeterministicVersionStamp` | Produces stable content-derived configuration stamps. |
| `DialTemplateGenerator` | Generates Cisco digit-rule XML from normalized rules. |
| `DirectoryService` | Synchronizes directory data, identity policy and compiled phonebooks. |
| `DirectorySiteMapper` | Applies ordered directory-to-site mapping rules. |
| `DirectorySourceService` | Owns independent source definitions and credential references. |
| `Discovery` | Reads CHAN_SIP/PJSIP endpoint inventory and correlates phones. |
| `EndpointProfile` | Resolves the shared driver/transport/port tuple and inheritance source. |
| `ExtensionBinder` | Applies the resolved endpoint route and credentials through FreePBX Core. |
| `Fail2banHelper` | Root-side strict implementation for jail reads and unban. |
| `Fail2banService` | Unprivileged client for the Fail2ban helper. |
| `FirmwareReplicationService` | Replicates selected firmware, timezone and locale objects with progress. |
| `FirmwareService` | Inventories model-scoped firmware and validates bundle completeness. |
| `GrandstreamGenerator` | Generates Grandstream desk, GHP and DECT configuration XML. |
| `HostedMediaInventory` | Inventories the PBX-hosted media library. |
| `IdentityLabelFormatter` | Applies bounded identity tokens and field-specific labels. |
| `IdentityDeliveryService` | Versioned owner/derived-consumer identity projections and separate delivery outcomes. |
| `LocalCredentialKey` | Read-only key lookup and install-only matching-backup-gated private-key migration. |
| `LiveStatus` | Resolves registration, address and reachability without mutating phones. |
| `LogicalExtensionService` | Owns AD identity, extension groups and audited overrides independently of MAC. |
| `ManagedAssetRegistry` | Stores hash-addressed managed asset identity and manifests. |
| `ManagedAssetScanJob` | Persists bounded/resumable asset inventory cursors. |
| `MediaReplicationService` | Replicates catalogs/audio/special media and schedules. |
| `MediaChunkTransfer` | Hash-pinned generic HTTP chunks, owned staging, destination CAS and retained prior bytes. |
| `HostedAssetRetirement` | Exact HTTP file/catalog dependency review, quarantine and no-overwrite restoration. |
| `AssetRetirementService` | Reference-revision-bound reassignment and retirement eligibility. |
| `MediaUrl` | Validates and constructs phone-compatible media URLs. |
| `ModelCatalog` | Single model, firmware, media, timezone, locale, VLAN and command authority. |
| `NetworkScan` | Performs bounded nmap or neighbor-table discovery for an entered IPv4 /20–/32 range; saved ranges are optional suggestions. |
| `PatchCatalog` | Defines the selectable Asterisk/USECALLMANAGER source pairs. |
| `PatchInventoryService` | Reads running, installed, managed, pinned and staged patch state. |
| `PatchManagerHelper` | Validates root-helper request schema and serializes patch operations. |
| `PatchWorkflowService` | Unprivileged client for Patch Manager operations. |
| `PhoneAccounts` | Normalizes additional line accounts and DECT assignments. |
| `PhoneAssignment` | Handles bind/reassign/swap behavior and fallback identity. |
| `PhoneCommand` | Sends the model-supported reload or reboot request on the live route. |
| `PhoneModelIdentity` | Provides stable vendor/model identity tokens. |
| `OperatorWorkflowService` | Explain/Impact plus fixed identity, rebuild and replacement review/execute plans. |
| `PhoneServiceService` | Owns service CRUD, applicability, catalog and vendor response formats. |
| `Provisioner` | Orchestrates resolution, generation, planning, apply and compensation. |
| `PublishMesh` | Two-phase cross-site Asterisk device-state publication. |
| `RecurringSchedule` | Validates interval/daily/weekly schedule grammar and due time. |
| `ReplicationActivityService` | Stores durable progress for concurrent replication runs. |
| `RpcOperationJournal` | Destination mutation ownership and encrypted receipts without uncertain replay. |
| `SiteConnection` | Selects local, API, SSH and SFTP transports with host-key checks. |
| `SiteSnapshotCache` | Stores secret-free last-known-good remote reads. |
| `SoftkeyPolicy` | Validates and merges current and compatible historical softkey schemas. |
| `SoftkeyPolicyGenerator` | Emits template-stack softkey and feature-policy XML. |
| `TemplateEngine` | Resolves the complete immutable policy for a physical phone. |
| `TemplateStackResolver` | Orders applicable parent/group/template layers and traces decisions. |
| `TftpArchitectureService` | Previews, copies, verifies and activates a staged PBX/Docker TFTP architecture change while retaining the source. |
| `TftpStore` | Owns safe remote storage, generations, assets, browser actions and downloads. |
| `VlanPolicy` | Validates normalized VLAN intent against verified model capabilities. |
| `YealinkGenerator` | Generates Yealink desk and DECT configuration. |

## Maintenance risks and intentional seams

`Ucmprovisioner.class.php` and `TftpStore.php` are larger than ideal. Their size is
known technical debt, not evidence that their behavior is unused. The BMO class is
the stable FreePBX/RPC surface; `TftpStore` is the shared transaction and remote-file
boundary. Future extraction should move one cohesive service at a time, keep a
compatibility delegate, and prove identical schema, plan hashes and rollback
behavior.

Other intentionally retained seams include:

- fresh-schema declarations plus runtime upgrade repair;
- current softkey schema plus a reader for older schema-less records;
- normal federation API plus restricted SSH recovery;
- active managed assets plus observed-file discovery;
- current SSH-key location plus an older read-only fallback; and
- generated state plus last-known-good offline snapshots.

Removing one side of these pairs without a migration would strand existing
installations or remove a recovery path.

The candidate's synthetic PDO/Core/SFTP and deferred browser fixtures cover these
boundaries locally. Real FreePBX/Core and MySQL/MariaDB concurrency, LDAP behavior,
reverse-proxy deployment and physical handset acceptance require separate approved
canaries; they cannot be inferred from a locally passing release build.

## Configuration dependency transactions

Configuration imports initialize and inspect referenced asset inventories before opening the object transaction. Template/group/settings writes and ownership remain atomic. Failed inventory reads are errors, not an empty catalog. Simple templates do not scan unrelated media or firmware.

A destination retains intended values for missing dependencies. After configuration imports and on the existing minute scheduler, it retries up to 100 waiting records, sharing each requested inventory and yielding between records after 30 seconds. Firmware, media, phonebooks, parent groups and templates arriving in later jobs can therefore satisfy prior policy without reseeding. Unchanged unresolved templates/groups are not rewritten. Paused, released, conflicting or locally modified objects retain their protection; automatic recovery never forces ownership or builds/reloads phones. Large backlogs or unavailable storage can need later ticks. Controller waiting badges refresh from destination state on scheduled ticks; historical run receipts remain unchanged.
