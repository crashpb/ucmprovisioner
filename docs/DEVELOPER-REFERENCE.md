# Developer reference

This guide explains the main callable contracts and where to extend the module.
The [architecture map](ARCHITECTURE.md) covers storage, class responsibilities and
transaction flows. The [feature reference](FEATURES.md) covers every primary UI
area; [Contributing](../CONTRIBUTING.md) defines verification expectations.

The module is a FreePBX BMO module, not a standalone web application. Most services
expect an initialized module object, FreePBX Core and its database. Pure resolver,
planner and generator tests use synthetic collaborators. Do not bootstrap a
production PBX merely to explore a method.

## Entry points and trust boundaries

| Entry point | Purpose | Boundary |
|---|---|---|
| [Ucmprovisioner.class.php](../Ucmprovisioner.class.php) | FreePBX lifecycle, AJAX, RPC allowlist, schema reconciliation and local/remote service façade. | Browser writes require authentication, POST and page-token validation. Public PHP visibility does not make a method remotely callable. |
| [page.ucmprovisioner.php](../page.ucmprovisioner.php) | Validates the selected tab and renders its view. | Treat query values as untrusted; preserve site context. |
| [api.php](../api.php) | Federation RPC and bounded file transfers. | Authenticated encrypted envelopes, selected methods and mutation receipts; not a general PHP/shell executor. |
| [directory.php](../directory.php) | Phone-facing directory/search output. | Validate requested publication, vendor, token and search policy. |
| [web_phonebook.php](../web_phonebook.php) | Employee page, data and XLSX routes. | All routes use audience, book and field policy; forwarding headers are not trusted by default. |
| [phone_services.php](../phone_services.php) | Model-aware service menus/application responses. | Validate service applicability and request parameters. |
| [media_asset.php](../media_asset.php) | Serves published hosted media. | Token/path validation and bounded file access. |
| [tftp_download.php](../tftp_download.php) | Administrator file/directory downloads. | Authenticated management access; downloaded configurations can contain secrets. |
| [install.php](../install.php) | Module schema/storage/endpoints/helpers/cron setup. | Privileged installation; credential migration has a verified recovery gate. |
| [uninstall.php](../uninstall.php) | Removes module-owned registration components. | Preserves phone-facing output/runtime recovery data as documented. |

Scheduled entry points evaluate saved due times:

- [cli_ad_sync.php](../cli_ad_sync.php): directory synchronization.
- [cli_mesh_sync.php](../cli_mesh_sync.php): BLF federation.
- [cli_phone_rebuild.php](../cli_phone_rebuild.php): phone automation.
- [cli_media_sync.php](../cli_media_sync.php): media, firmware and configuration sync.
- [cli_rebuild.php](../cli_rebuild.php): explicit administrator preview/apply in an
  already bootstrapped CLI context. Its apply mode calculates and applies a fresh
  hash in one invocation; use the UI when a person must review that exact plan.

## Build façade versus build engine

Use the BMO façade for a site-aware operation. Its argument order is:

```php
$ucm->rebuild(
    $selection,
    $apply = false,
    $siteId = null,
    $restartAfter = false,
    $allowMesh = true,
    $expectedPlanHash = null,
    $expectedMeshPlanHash = null,
    $replaceManual = false
);
```

`$selection` is `all`, comma-separated phone IDs or `ext:<extension>` tokens. A
bare `4101` means phone ID 4101, not extension 4101. Preview is the default. Apply
must carry the current preview hash. The engine has a different argument list:
`Provisioner::rebuild(selection, apply, siteId, restartAfter, expectedPlanHash,
replaceManual, identityOnly)`. Do not copy positional arguments between them.
The retained mesh arguments are compatibility parameters; the current façade
reports cross-site mesh work as deferred to its independent workflow.

| Method | Input/output and effect |
|---|---|
| `Ucmprovisioner::previewPhone(id, siteId)` | Dispatches to the owning site's generator and returns filename, content, warnings and template trace. Read-only preview; returned content may contain credentials. |
| `Ucmprovisioner::rebuild(...)` | Dispatches locally/remotely and preserves result/receipt semantics. A remote timeout after dispatch is uncertain, not a reason for a second mutation through another channel. |
| `Provisioner::previewPhone(id)` | Resolves one known phone, aligns the preview's credential representation and generates its vendor content. Returns an error for an unknown phone. |
| `Provisioner::rebuild(...)` | Resolves fleet-wide glue and selected phone output; plans or applies under locks. Apply may change Core endpoint state, managed Asterisk blocks and TFTP output. Phone commands are separately requested. |
| `Provisioner::missingBuildFields(phone)` | Reports absent required build fields so callers can explain ineligibility consistently. |
| `Ucmprovisioner::sendPhoneCommand(phoneId, cmd, siteId)` | Routes an explicit supported command to the owner. Delivery does not prove physical execution. |

## Policy resolution

| Method/service | Contract |
|---|---|
| `TemplateEngine::effectiveSettings(siteId)` | Reads effective site settings. Consumers must not reconstruct precedence in a view. |
| `TemplateEngine::templateAssignmentTrace(phone)` | Explains chosen/skipped template assignments without publishing output. |
| `TemplateEngine::resolvePhone(phone, fullDeviceResolution = true)` | Returns the normalized phone, model, settings, lines and resolved dependencies used by generators. Full device resolution validates device assets; the limited path supports fleet-wide glue inputs. |
| `TemplateStackResolver::resolve(...)` | Orders compatible inherited layers and detects invalid group relationships. Read its declared signature before supplying group/assignment arrays. |
| `DefaultTemplateResolver` | Selects one most-specific fallback when a compatible group stack does not exist. Rejects equal-specificity ambiguity. |
| `EndpointProfile::resolve(phone, model, settings)` | Returns driver, transport, port, selected modes and inheritance-source metadata. Cisco uses CHAN_SIP; legacy Cisco CNF enforces UDP. Non-Cisco can resolve PJSIP or SIP. |
| `EndpointProfile::driverLabel(driver)` | Converts the internal driver token to the display label; it does not select a driver. |
| `ModelCatalog` | Owns model identity and firmware/media/timezone/VLAN/command capabilities. Add verified model facts here before changing generators/selectors. |

For a new setting, follow the existing normalized field from editor input through
validation, persistence, resolver, generator and preview. Add one regression at
the shared ownership boundary, including an inherited value and an explicit
override. Do not create an independent precedence rule inside each vendor class.

## Planning, transactions and Asterisk

| Method | Contract and failure boundary |
|---|---|
| `BuildPlanner::plan(siteId, resolvedPhones, files, gluePlan, context)` | Accepts filename/content rows, rejects unsafe/conflicting filenames, hashes content and returns a canonical manifest. The plan hash excludes transaction ID/time; changes in substantive input change the hash. |
| `BuildPlanner::assertFilename(filename)` | Rejects invalid names at the publication boundary. Do not bypass it for a new generator. |
| `BuildPlanner::verifyManifest(manifest)` | Checks the manifest's integrity contract before use. |
| `BuildPlanner::assertTransactionId(id)` | Validates transaction identifiers before storage access. |
| `BuildTransactionService::begin(manifest, actor)` | Creates the durable transaction record. |
| `BuildTransactionService::transition(...)` | Moves a transaction through guarded states with result evidence. |
| `BuildTransactionService::markPhonesApplied(manifest)` | Updates applied metadata after the appropriate commit boundary. |
| `BuildTransactionService::get(transactionId)` | Reads a retained transaction or returns no record. |
| `TftpStore::withSiteLock(siteId, operation, mode, context)` | Serializes destination file operations using checked ownership. An old lock is not automatically stealable. |
| `TftpStore::withSiteLocks(siteIds, operation)` | Acquires multiple site locks consistently for operations involving more than one tree. |
| `TftpStore::stageGeneration(...)` | Stages files and prior-state evidence for a generation. |
| `TftpStore::commitGeneration(siteId, transactionId)` | Commits the staged generation; callers must retain and inspect the result. |
| `TftpStore::rollbackGeneration(siteId, transactionId)` | Explicit receipt-based compensation; never assume rollback succeeded because it was attempted. |
| `TftpStore::currentGeneration(siteId)` | Reads current generation identity. |
| `AsteriskGlue::plan(resolvedList)` | Builds managed configuration intent and indicates whether Cisco patch support is required. Non-Cisco records do not require that patch. |
| `AsteriskGlue::patchPreflight()` | Checks the expected patched runtime capabilities. |
| `AsteriskGlue::snapshot()` / `restoreSnapshot(snapshot, reload)` | Capture/restore the managed Asterisk state for transaction recovery; restoration is a write. |
| `AsteriskGlue::apply(resolvedList)` | Writes managed blocks and performs bounded reload work after applicable checks. A patch-free non-Cisco plan does not imply zero Asterisk writes. |

Prefer `Provisioner` for normal builds: calling storage and glue methods manually
can omit Core coordination, locks, metadata or compensation. Generated shared
blocks must use the entire owning-site fleet even for a one-phone file build.

## Directory and web policy

| Method | Contract |
|---|---|
| `DirectoryService::testSource(sourceId)` | Tests the configured source; it is not a full synchronization. |
| `DirectoryService::sync(manual, sourceId)` | Reads and reconciles a source and its accepted identity/publication state. Treat it as a mutation. |
| `DirectoryService::previewDraft(input)` / `previewPhonebook(id)` | Resolves a proposed/saved phonebook for review. Keep contacts private. |
| `DirectoryService::savePhonebook(input, importKey, publish)` | Persists a book; publication defaults to enabled. Do not mistake it for a read-only validation call. |
| `DirectoryService::planIdentityPackets(scope)` | Fixes identity targets and accepted inputs for a reviewed delivery. |
| `DirectoryService::refreshIdentities(force, scope)` | Executes the applicable identity workflow. Force is not permission to reread unrelated data or bypass ownership/revision guards. |
| `LogicalExtensionService::setIdentityBinding(...)` | Changes explicit primary/linked/manual authority with revision control. |
| `LogicalExtensionService::saveExtensionOverrides(...)` | Stores audited field overrides on logical identity rather than a MAC. |
| `WebPhonebook::defaults()` | Returns neutral, disabled-by-default policy with no organization-specific preferred URL. |
| `WebPhonebook::normalizePolicy(input)` | Validates and normalizes audience, fields and presentation settings; empty optional URL is allowed, supplied URLs must meet the HTTPS/help-link rules. |
| `WebPhonebook::savePolicy(input, expectedRevision)` | Saves a locally editable policy only against its current revision/management state. |
| `WebPhonebook::applyManagedPolicy(input, context, force)` | Applies synchronized policy under management/ownership rules. |
| `WebPhonebook::accessDecision(policy, server)` | Evaluates the connecting client and explicitly trusted proxy policy. |
| `WebPhonebook::model(policy, requestedBook)` | Builds the field-limited view of allowed published data. |
| `WebPhonebook::exportSelection(model, request)` | Selects export content from that authorized model. Do not re-query hidden raw fields for XLSX output. |

## Federation and synchronization

`SiteConnection::execApi(payload)` is the normal encrypted transport;
`execAgent(payload)` is the constrained SSH channel. `tftpSftp(mode)` uses the
configured host, port, key and trust pin. `activeTftpMode(site)` and
`resolvedTftpHost(site, mode)` distinguish active architecture from a staged one.
Do not substitute port 22 or infer an administrative shell from SFTP access.

`RpcOperationJournal` owns mutation receipts. Reconcile an uncertain operation by
its identity before any retry. `SiteSnapshotCache` provides safe dated reads;
offline snapshots never become authoritative empty inventories.

`ConfigurationSyncService::save(input)` validates a job;
`runNow(id, force)` executes its explicit scope; `runDue()` evaluates schedules;
`resume(jobId, targetSiteId, objectType, objectKey)` resumes a selected managed
object. These are mutations. Selected Settings fields come from the service's
allowlist, not arbitrary keys submitted by a browser.

Media/firmware replication services preserve catalog identity, portable
dependencies and destination ownership. Background selectors read current
verified catalog outputs; raw storage observations and compatibility aliases must
not become new choices. Consult [Asset filenames](ASSET-FILENAMES.md) before
changing any generated path or URL.

## Extension examples

### Add a model

Verify its configuration family and actual firmware capabilities. Add the model
facts to ModelCatalog, reuse an existing generator where the grammar matches, and
test the emitted configuration plus companion references. Check selectors,
firmware/media compatibility and reload commands together. Record physical-phone
acceptance separately from synthetic fixture coverage.

### Add a phone setting

Use the existing editor and normalization path. Define inheritance/clear behavior,
resolve it once, emit only supported vendor output, and verify both a group value
and a phone override. Add archive/replication handling only if the setting belongs
to those existing portable policy scopes. Never export a local credential as a
portable setting.

### Add a privileged action

Prefer an existing fixed-schema helper operation. If a new operation is necessary,
validate all fields and constrain paths/commands in the root-side helper as well
as the web layer. Test rejected input and partial failure. Never turn an RPC field
into a general shell command or broaden sudo to solve one missing operation.

## Verification and reproducible packaging

Use PHP 8.2+, Composer and Python 3. Production PBXs use the packaged `vendor/`
tree; development installs locked dependencies. Run the commands in
[Contributing](../CONTRIBUTING.md), including the full PHP runner and applicable
Node checks. Linux permission/SSH fixtures need Linux to establish their real
platform guarantees. Record skipped or failing checks explicitly.

Build twice into separate directories with `tools/build-release.py`, compare
archive hashes, inspect the generated source manifest and verify every packaged
file against the intended source. Keep output outside the source tree. Publish the
TGZ, its checksum and its source manifest together. A release artifact is distinct
from a deployed installation and from a successful handset canary.
