# Changelog

## 1.1.0rc33 - 2026-10-02

- Prepare the public GPL-3.0-or-later repository with installation paths for non-Cisco fleets on unpatched Asterisk and Cisco fleets using the matching USECALLMANAGER patch. Add feature walkthroughs, worked examples and developer references.
- Remove deployment-specific branding, addresses and fixture labels. Use a neutral Company Directory title and an optional, initially blank preferred HTTPS address; preserve explicitly saved URLs and existing access controls.
- Update the existing phpseclib dependency to 3.0.57 and require that patched minimum, addressing [CVE-2026-84308](https://github.com/advisories/GHSA-q97c-8qh3-fpc6). No new dependency is introduced.
- Keep provisioning, replication, model capabilities and schema unchanged. Release packages include locked dependencies, checksums and a per-file source manifest.

## 1.1.0rc32 - 2026-09-26

- Derive new background choices only from current enabled catalog outputs with matching published hashes. Keep loose files, disabled outputs and retained aliases in diagnostic/reference inventory without offering them as new assignments.
- Make Settings and Templates consume the same catalog-authorized hosted choices. Preserve saved legacy URLs, collapse compatible model-library copies, and refuse raw-file fallback from older peers. Cisco template choices likewise require a verified current catalog output.
- Keep catalog replication and compatibility URLs intact. Test the actual catalog/uploader/inventory path across replacement, rename, interrupted repair, missing manifests, unowned copies and hash conflicts, plus both selector paths. No schema or dependency change.

## 1.1.0rc31 - 2026-09-26

- Preserve catalog-owned short wallpaper filenames through hosted upload so Grandstream and Yealink catalog references match the published files. Reject unexpected receiver renaming before committing a save or rename manifest.
- Add **Repair hosted filenames** for affected catalogs: verify existing payload hashes and dimensions, restore the recorded names without re-rendering artwork, preserve existing URLs, reject conflicts, and reconcile lost responses before continuing. Keep revision/ownership guards and read-only catalog listing.
- Exercise the real hosted upload/download/chunk code with catalog create, update, rename, recovery, collisions and uncertain completion. No schema or dependency change.

## 1.1.0rc30 - 2026-09-23

- Clarify the phone MAC field's 12-hex-character format, accept either letter case and normalize the displayed/submitted value to uppercase. Remove the input limit that truncated formatted pastes; remove standard separators before validation.
- Show incomplete/invalid MAC errors beside the field and stop Save before submission so the editor draft remains intact. Preserve known-MAC selection and blank extension-only records. Add UI and actual database-save regression coverage. No schema, dependency or protocol change.

## 1.1.0rc29 - 2026-09-22

- Run administrator-authenticated discovery locally on the selected PBX using its Linux account and existing sudo rights. Never initiate SSH for a scan, including self-SSH or transport fallback.
- Deliver remote scan requests and temporary credentials only through the encrypted federation API to the same local execution path. Reject older receivers explicitly; keep credentials out of command arguments, persisted settings and operation receipts. Preserve bounded scans, arbitrary valid IPv4 CIDRs, unprivileged discovery and local ARP/HTTP probes.
- Replace the self-SSH regression fixture with local process authentication, wrong-password/sudo failure, API receiver parity, missing SSH pin and no-fallback coverage. No schema, dependency or sudo-policy change.

## 1.1.0rc28 - 2026-09-22

- Skip individual identities whose destination account is missing or whose ownership/revision conflicts, while continuing eligible identities. Exclude rejected identities from owner and derived-consumer writes; preserve existing projections and ownership guards.
- Show skipped extensions and reasons in the Directory editor. Retire their failed retry jobs, reconsider them on each directory sync, and clear the warning automatically after successful delivery. Apply the same behavior to reviewed identity refreshes; skipped identities are never counted as applied.

## 1.1.0rc27 - 2026-09-22

- Use a dedicated company-directory browser title; add PNG/JPEG favicon upload and site display aliases to the web-phonebook editor. Aliases apply to results, filters, print and export; presentation settings travel with the existing web-directory policy synchronization.
- Render every permitted field in normal table and People views, use available viewport width, and toggle directly between light and dark with a matching icon and persisted preference.
- Share rebuild eligibility between the phone list and generator. Missing extension/MAC/model fields now produce actionable notices instead of false pending badges and misleading selection errors; manual configurations are not marked for a managed rebuild.
- Accept existing chan_sip transport lists during identity-only publication when they include the chosen transport. Preserve exact PJSIP transport-object checks and credentials, and report missing accounts and failed publication stages explicitly.

## 1.1.0rc26 - 2026-09-22

- Initialize the identity-override extension selector as a searchable dropdown and include usernames beside names and extension numbers.
- Restrict handset text search to identity names, usernames and saved directory-name overrides. Display patterns, departments, OUs, company and other metadata cannot create text matches; digits inside usernames no longer trigger unrelated number matches.
- Carry and acknowledge the controller's destination site key in identity delivery. Receivers retain an explicit mapping to their local site instead of silently treating their own accounts as foreign BLF entries. Local ownership, revision, release and conflicting-authority checks remain enforced; update controller and receivers together.
- Keep web print/export selections valid across unchanged scheduled publications, while changes to visible contacts or publication policy still invalidate them. Download and print mechanisms are unchanged.

## 1.1.0rc25 - 2026-09-22

- Honor the restart checkbox even when Generate all changes endpoint settings. Automatically replace incompatible SIP passwords in FreePBX and the generated phone configuration together; preview remains read-only and a shared account requires all affected phones to be selected.
- Run privileged IPv4 discovery through the supplied administrator's pinned SSH connection and optional password-fed sudo, using the existing site-update authentication path. Accept /0 through /32, retain the 90-second limit, and install no scanner helper or sudo rule.
- Expose explicit per-extension transport radio choices, distinguish unlinked identities from accepted bindings needing review, and save multiple identity overrides in one revision with field help.
- Search handset phonebooks by displayed names and final directory labels. Expose the existing manual non-AD contact editor prominently.
- Select individual Settings fields, including endpoint defaults and optional service URLs, through one shared save/sync inventory. Require source and destination acknowledgement; preserve unselected settings and retain missing companion-file dependencies for later recovery. No schema or dependency change.

## 1.1.0rc24 - 2026-09-20

- Keep AD-derived BLF shortnames independent of the watching phone's top-label pattern.
- Apply per-phone and site label policy before BLF rendering, and directory label patterns to the final directory URL. Preserve explicit blank/zero BLF overrides and format unbound FreePBX targets without bypassing owned-identity guards.
- Add integrated label-policy coverage through Cisco XML, including group members, cross-site targets and template-free phone buttons. No schema or dependency change.

## 1.1.0rc23 - 2026-09-20

- Use short generated filenames throughout dial plans, template softkeys/feature policy, timezone aliases, wallpapers/thumbnails, uploaded hosted media and ringtones, new special catalogs and phonebooks, and firmware compatibility declarations. Public generated names are at most 20 characters; full hashes and readable labels remain intact.
- Keep writers, emitted references, catalog readers, asset classification, replication and retirement compatible with the new names. Refuse conflicting bytes at immutable compact names, including API, SFTP and hosted-media publication. Preserve ringtone display labels through copying and index regeneration.
- Preserve retained references and exact vendor boot, locale and firmware dependency names. No automatic fleet rename, schema change or dependency change. See `docs/ASSET-FILENAMES.md` for the complete naming inventory and upgrade behavior. Local candidate only; handset acceptance and deployment are separate.

## 1.1.0rc22 - 2026-09-20

- Generate a complete Cisco XML SIP base profile, including registration timers, media/DTMF settings, local SIP port, line metadata and explicit unencrypted configuration state. Preserve resolved account/transport, BLF, softkey, timezone and service policies.
- Emit DND alert policy once at the device root, including explicit zero. Correct Display refresh rate to Normal/Flicker correction; previously saved interval values generate Normal with a warning.
- Stop generation before publication when the primary Cisco XML authentication secret exceeds 30 characters. Never truncate credentials; bulk-registered secondary lines remain credential-free. No schema or dependency change.

## 1.1.0rc21 - 2026-09-20

- Synchronize group hierarchy and shared template/BLF policy while keeping extension membership and member exceptions local. New groups need no extensions. Existing missing-extension receipts recover automatically; local member edits no longer pause shared policy sync. Shared-policy ownership and edit protections remain.
- Allow an entered IPv4 /20–/32 discovery range without saving it in Site Settings first. Retain selected-site execution, input validation and the 90-second active-scan limit.
- Add a prominent, sortable Phone IP column refreshed from PBX contact observations. Show multiple contacts and label saved/unavailable addresses; move endpoint inheritance details into the SIP tuple tooltip. No schema or production dependency change.

## 1.1.0rc20 - 2026-09-19

- Keep schema initialization and independent asset inventory work outside configuration object transactions; prevent timezone readers from implicitly committing MariaDB writes. Templates without managed asset references skip unrelated inventory scans.
- Automatically retry retained group/template/settings dependency intent after later imports and on destination scheduler ticks. Preserve local-edit/ownership guards, avoid unchanged fallback rewrites, and refresh controller waiting badges without rewriting historical receipts.
- Distinguish unavailable inventory from absent assets, retain commit diagnostic type/code, and verify the actual MariaDB transaction boundary with an isolated integration test. No schema or production dependency change.

## 1.1.0rc19 - 2026-09-19

- Retain large API uploads in authenticated 16 MiB portions. Retry an interrupted portion at most twice, using the same API and idempotent private staging; never replay an uncertain publication.
- Rerunning the same unchanged selection resumes its confirmed portions from the retained destination receipt. Completed files are skipped; whole-file SHA-256 and destination revision checks still precede publication.
- Show reconnecting/confirmed-range progress and retain per-site results. Both controller and receiver need rc19 for resumable uploads; older receivers retain their existing protocol. No schema or dependency change.

## 1.1.0rc18 - 2026-09-19

- Stream large managed TFTP files through one authenticated binary HTTP request per file. Retain small-file batches and destination SHA-256 verification before publication; reuse each source snapshot across destinations.
- Preserve activity-panel dismissal across polling and navigation. Show selected package totals, cumulative bytes and the current package separately.
- Both endpoints need binary-stream capability (PHP curl/sodium); older peers retain their existing transfer protocol. SFTP backup remains limited to API-unavailable preflight. No schema or dependency change.

## 1.1.0rc17 - 2026-09-19

- Negotiate bounded 1 MiB / 64-chunk TFTP API batches and reuse HTTP connections.
  Older peers retain their 256 KiB requests. Larger hosted-media chunks require
  both peers to advertise support; private staging appends verified bytes.
- Wait briefly and retry only authenticated storage-lock refusals before mutation.
  Partial, unknown and recovery-required operations are never replayed or moved
  to another transport. Retained locks are never removed automatically.
- Report current destination, transfer bytes and stages during manual runs.
  Preserve committed TFTP and hosted-media outcomes when a later stage fails;
  distinguish incomplete private staging from published files.
- No schema or dependency change. Receiver-side batching requires rc17; updating
  only the controller does not enable batching on older receivers.

## 1.1.0rc16 - 2026-09-19

- Recheck discovery observations under the configuration lock and save physical
  and logical bindings together. Preserve fresh operator labels, skip stale
  assignments and stop on uncertain database completion.
- Exclude policy edits during build apply. Keep same-second phone edits pending
  until a build acknowledges their configuration revision.
- Preserve partial and unknown RPC outcomes in encrypted operation receipts,
  duplicate/status responses, transfer results and safe GUI recovery details.
- Export related tables from one consistent database snapshot, check selected
  references and coordinate capture with managed TFTP/directory publication.
  Imports preserve publication lock files from the running service.
- No schema or dependency change; includes all rc14 and rc15 fixes.

## 1.1.0rc15 - 2026-09-18

- Isolate replication ownership by controller, provide explicit legacy ownership
  adoption, and commit object/settings tracking together. Retry pending dependent
  publication and phone markers without replaying completed imports.
- Publish directory content and its authentication digest as one atomic snapshot;
  serialize edits, retain drafts, reject stale revisions and preserve uncertain
  directory acceptance outcomes.
- Execute wallpaper edits on the destination PBX using bounded, receipted input.
  Reject stale editor/source hashes. Retire catalogs only after reference checks,
  preserving complete sources and payloads; disabled profiles keep existing URLs.
  Retain partial reset, timezone, file-generation and companion-cleanup results.
- Protect phone deletion through storage and database completion, reject stale
  bindings, preserve site editor revisions across connection actions, serialize
  local policy writers and seed model updates atomically. Preserve legacy image
  defaults and incompatible saved model selections for explicit correction.
- Preserve both prepared signatures and prior files after partial signing,
  publish service-owned signatures with the service UID, and block another signing
  attempt until the pending receipt is reconciled.
- Add observation revisions for race-safe asset scans and durable phone-marker
  tracking in additive schema 34. Includes the rc14 SSH readiness correction.
  This candidate does not establish handset or production acceptance.

## 1.1.0rc14 - 2026-09-18

- Preserve a finalized SSH RPC response when FreePBX exits nonzero after sending
  it. This fixes remote Install/update rejecting a completed module-readiness
  response after installation, signing and automatic agent enrollment succeeded.
- Require the RPC v2 receipt to match the request's operation identity. Continue
  rejecting incomplete, malformed, oversized, timed-out and mismatched replies;
  preserve confirmed failures without replaying the request. Legacy unreceipted
  replies still require a successful SSH exit. Schema remains 33.

## 1.1.0rc13 - 2026-09-16

- Retain completed destination, file, PBX and device stages when later history or
  receipt writes fail. Isolate failures across replication destinations, scheduler
  jobs and phone commands; retain unresolved mutation identities for inspection.
- Serialize storage cutovers, mesh transactions and recurring job edits. Guard
  template/group updates with database revisions and keep local edits together
  with their synchronization pause. Propagate failed schema repairs.
- Read phone/BLF schedules under the same lock as edits and consume each due slot
  before external work. Retain completed stages on lost command/history results.
  Preserve saved settings after a phonebook publication failure and retry that
  publication even when the saved default already matches.
- Preserve editor context across asynchronous responses, prevent overlapping
  Apply requests and show Configuration Sync results for each destination.
- Preserve unavailable saved template choices and partial wallpaper save results.
  Preserve malformed saved assignment maps and ignore superseded Settings inventory
  responses. Reject explicit line keys outside model capacity without discarding
  the requested appearance or breaking legacy account-only rows.
  Stage and verify TFTP editor/generated-file writes, retain uncertain replacement
  receipts and block replay. Serialize master-source edits with catalog sync.
- Bound local signer process input/output and runtime, preserve recorded key
  identity and prepare both signatures before publishing either one.
- Isolate special-catalog transfers by transaction and verify source content and
  destination revisions. Special-catalog sync requires protocol 2 on both peers;
  upgrade those peers before using this operation. Other transfer protocols retain
  their API-first, preflight-only SFTP fallback contract.
- Harden media publication, public directory inputs, archive validation, Patch
  Manager capture/recovery, provisioning value handling and shared service files.
  Add fault-injected fixtures for the affected transaction boundaries.

Prepared as a local release candidate. Linux lint/assertions passed, with the
owner-sensitive permission-hook fixture run separately as root. Composer strict
validation, locked advisory audit and the affected Node interface checks passed.
This entry does not attest deployment or handset acceptance. Schema remains 33.

## 1.1.0rc12 - 2026-09-15

- Allow per-phone legacy Cisco logos and directories without rejecting the shared
  CNF policy. Keep conflicting shared endpoint policy blocked. Show published
  files, PBX activation and per-phone reload dispatch separately; distinguish an
  unroutable endpoint or missing NOTIFY type from an unconfirmed dispatch.
- Keep Media catalog loading independent from wallpaper-editor requests. Preserve
  successful inventory sections, show errors per section, and allow failed loads
  to retry. Ignore superseded requests and responses from a different site.
- Show destination outcomes for manual and scheduled Media/Firmware replication
  and retained activity. Preserve successes, failures and unresolved results,
  including when detailed file lists exceed the stored result limit. Continue later
  manual Media scopes only for destinations that completed the preceding scope.
- Report transfer failure phases without treating known pre-commit rejections as
  uncertain commits. Preserve API-first transfer and preflight-only SFTP fallback.
- Match Patch Manager captures against real library payloads, excluding validated
  SONAME aliases. Explicitly reuse unchanged captures, detect changes during new
  captures, and verify full duplicate payloads before preserving a pin on the active
  copy and removing a duplicate. Preserve archive integrity and active-copy guards.
- Keep release documentation and validation fixtures aligned. Schema remains 33.

## 1.1.0rc11 - 2026-09-15

- Transfer managed TFTP assets through bounded federation API chunks with source
  hashes, destination revision checks, private staging and retained prior bytes.
  The owning destination publishes files and registers inventory under one local
  storage lock; controllers no longer hold destination locks across registration.
- Select SFTP byte staging plus restricted SSH RPC automatically when the
  read-only preflight finds the API unavailable. Both channels require the same
  transfer protocol and use the same destination commit receipts. Authentication,
  trust and protocol failures do not downgrade; uncertain mutations are reconciled
  by their exact RPC receipt without replaying through another transport.
- Let independent destinations proceed after another target fails. Rebuild remote
  wallpaper/ringtone indexes on their owning PBX. Preserve unresolved recovery locks
  and remove success claims from failed firmware summaries.
- Schema remains 33. Interrupted production transfer recovery remains a separate
  operational step.

## 1.1.0rc10 - 2026-09-11

- Remove Docker enrollment's TFTP packet probe, temporary packet-test files and
  skipped-probe warning. Existing-container and SSH/SFTP access checks remain.
- Keep TFTP server/client installation and local retrieval verification exclusive
  to the explicitly requested PBX-local bootstrap with temporary administrator
  credentials. Clarify the server-and-client package policy in the UI and guides.
- Schema remains 33. No tests or lint were run for this release under the
  operator's restriction; archive integrity is not end-to-end workflow acceptance.

## 1.1.0rc9 - 2026-09-11

- Refresh the saved-site operation log after successful and failed actions and
  periodically while the page is visible, without reloading or losing form edits.
  Preserve expanded rows and old results when a refresh fails.
- Retain bounded, redacted error causes, authentication stages and remote-command
  output in the browser and operation log, including returned partial failures.
- Separate Docker administrator entry from PBX autofill and remove the unverified
  root username default. Existing host-pin and mutation-serialization guards stay.
- Schema remains 33. PHP and end-to-end enrollment behavior have not been verified
  for this release; packaging/deployment integrity is not workflow acceptance.

## 1.1.0rc8 - 2026-09-11

- Prefill effective phone-facing TFTP defaults, follow host edits while automatic,
  preserve deliberate overrides and carry current addresses into enrollment.
- Share guarded authorized-key publication across PBX/Docker enrollment: explicit
  root-owned directory/file modes, existing-key preservation and account-readable
  key verification before SSH policy activation.
- Require PBX-hosted SFTP write/read/delete and owning-PBX verification before
  readiness; preserve active architecture and propagate verified endpoint metadata.
- Retain SSH/PBX daemon recovery through post-checks, guard concurrent key/policy
  edits, and expose the original failure with its recovery reference.
- Preserve installed module/signing identity when later credential readiness fails.
  Schema remains 33. Tests were not run under the operator's restriction; release
  integrity and installation checks do not establish fresh-site/phone acceptance.

## 1.1.0rc7 - 2026-09-11

- Reduced remote PBX actions to Check version and Install / update with a signing
  checkbox. Display controller/remote versions and comparison explicitly.
- Reused the existing installation, agent and API workflow. Current-version
  repair honors signing without reinstalling; signing returns structured verified
  integrity and includes the PBX-local OpenPGP trust setup.
- Prefer fresh API/service-account integrity checks; restricted SSH-agent reads
  report unavailable integrity without widening GPG keyring permissions.
- Use one PBX host with optional distinct voice/API overrides. Existing-site
  actions use a validated current-form snapshot through fingerprint confirmation
  and execution. Read actions do not save; explicit actions save only connection
  fields and invalidate stale endpoint trust/readiness when the target changes.
- Preserve exact authorized_keys bytes through Docker SSH/sudo diagnostics,
  eliminating false concurrent-change failures while retaining the hash guard.
- Include the post-rc6 shared credential-readiness probe correction in this
  package. Schema remains 33. Test suites were not run by operator instruction;
  remote enrollment and handset acceptance remain separate deployment checks.

## 1.1.0rc6 - 2026-09-09

- Explicitly finish the remote module-state, BMO-loadability and credential-store
  PHP probes with exit code zero after their JSON response. This prevents a valid
  bare-FreePBX response from being rejected because of an inherited nonzero exit.
- No tests were run for this release, by explicit operator instruction.

## 1.1.0rc5 - 2026-09-09

- Retained the Sites Save/Delete hotfix: action routing no longer confuses the
  site identifier with the form action, and the editor preserves its action bar.
- Fixed shared fingerprint/enrollment feedback, password cleanup and button
  ownership through cancellation, transport failure and nested asynchronous work.
- Added one PBX preparation/repair path for a blank module, older installation
  or current installed site. Administrator SSH probes do not require an existing
  agent/API; failed probes are unknown, not evidence of a missing installation.
- Preserved no-downgrade, local signing, credential recovery and uncertain-write
  safeguards. Disabled modules use noninteractive enable/install. Readiness checks
  distinguish module, restricted SSH agent and management API; API failure does
  not discard a working recovery channel.
- Docker enrollment adopts an existing compatible TFTP container only, with
  preflight and controller/owning-PBX access checks before readiness is recorded.
  It does not install Docker, create containers or change the active TFTP backend.
- Reconciled active installation documentation and package examples with rc5.
  Schema remains 33. Local builds/tests do not assert production deployment or
  live FreePBX, Docker, network and physical-phone acceptance.

## 1.1.0rc4 - 2026-09-08

- Reused FreePBX's searchable multi-select for web phonebooks and visible fields;
  selected items stay first, including in large catalogs. Matched module form
  styling and grouped optional settings under Advanced settings.
- Reduced assignment clutter: timestamps and deployment evidence are collapsed,
  secondary row actions are grouped, and advanced labels/values are aligned.
  Clarified that reapplying accepted identities does not fetch new AD data.
- Made Fail2ban jail/client navigation and per-client Unban easier to find while
  preserving exact site/jail/IP confirmation and restricted helper operations.
- Normal installation now automatically verifies a local recovery archive and
  moves the identical encryption key and legacy AD credential into private files.
  Preserves ciphertext, source identity/history and saved AD transport; matching
  files survive interrupted commits and repeat installs are no-ops.
- Remote Install/update now enrolls/repairs the constrained site agent, probes the
  new module over fresh key-based SSH and checks credentials as asterisk. Incomplete
  or uncertain installs retain recovery evidence without blindly reinstalling old
  code against migrated credentials.
- Added web-directory configuration-sync scope with displayed source/job ownership,
  optional hard lock, soft local-edit pause/resume, capability preflight and missing
  published-book protection. Source access-policy replication is explicitly confirmed
  in the job; contact data and network/DNS/TLS setup remain separate.
- Normal install and subsequent atomic saves preserve service-group read access
  (0640) on web-directory policy and its stable empty lock for constrained SSH
  synchronization. AD reader credentials remain owner-only.
- Retained the PBX-wide permission reset in remote module installation.
- Added the native FreePBX permission hook for module runtime storage and its
  existing SSH key files, preserving private AD credentials, private-work ownership
  and rename-restored media. Shared media/cache writers and scheduler locks set
  service-group access explicitly instead of relying on SGID surviving a reset.
  Permission failures are no longer reported as scheduler lock contention.
- Verified handset numeric query `12` uses contiguous substring matching across
  enabled numeric fields, not per-character OR matching. Search behavior unchanged.
- Packaged the local 1.1.0rc4 candidate; publication and deployment remain separate.

## 1.1.0rc3 - 2026-09-07

- Restored direct phonebook access based on the connecting address; forwarded
  headers are ignored in that mode. Optional original-client proxy filtering is
  unchanged and no extra proxy list is needed for private connecting addresses.
- Fixed phonebook audience confirmation clearing itself, stale preview races and
  conditional confirmation visibility. Reduced employee-table clutter and repeated
  filtering work while retaining search, contact details, print and Excel output.
- Retained last accepted identities and original last-seen times for temporarily
  nameless LDAP persons. New nameless accounts are skipped with warnings; incomplete
  searches, duplicate stable identities and unreadable prior catalogs still fail.
- Restored established AD-primary management during install/sync using accepted
  source, person, slot and revision checks. Manual identities and ambiguous
  relationships are left alone; existing override values are never rewritten.
- Fixed the operator source query, useful empty-selection/error summaries, retained
  plan/status display and toolbar initialization. Bounded large detail rendering.
- Removed full schema repair from current-schema page/poll requests and database
  writes from activity reads; idle closed activity panels stop recurring polling.
- Schema remains 33. This corrective source/build does not assert deployment,
  real FreePBX/LDAP/proxy acceptance or physical handset behavior.

## 1.1.0rc2 - 2026-09-07

- Added three explicit identity relationships: AD primary, additional extension
  linked to a stable person, and manual/shared. Full caller names and short labels
  remain separate; old labels require adoption and person overrides are retained
  for review instead of transferring silently to a new owner.
- Added all-account/DECT/derived-BLF identity resolution, complete-source release
  records, revision-checked refresh plans and bounded cross-site input staging.
  Normal rebuilds retain accepted received identity without overriding local
  manual or authoritative bindings.
- Added owner-scoped explanation, unsaved policy impact, deployment evidence and
  guided replacement reviews. Replacements preserve physical MAC identity and
  logical person policy; no implicit reboot or credential rotation is included.
- Extended the internal web directory with exact proxy/client separation,
  matched-field search ranking, copy controls, accepted-source freshness,
  explicit export policy and revision-bound exceptional membership review.
- Preserved typed federation results and added encrypted operation receipts;
  uncertain mutations are not replayed through a fallback transport. Enforced
  authenticated payload bounds and secret-free browser/snapshot projections.
- Hardened destination-owned writer locks, generation ownership, staged media
  transfer, retained-source rename, asset dependencies and recoverable retirement.
  Final dialplan companion identity is derived from its generated content.
- Added checked Core/Asterisk edits and compensation evidence, explicit private
  recovery archives, backup-gated credential-key migration and staged SSH policy
  validation. Additive schema 33 preserves existing identity and configuration.
- Added isolated race, fault, recovery, migration and asynchronous UI regression
  coverage. Real PBX, directory, proxy and handset acceptance remains a separate
  coordinated deployment gate; this entry does not assert production deployment.

## 1.1.0rc1 - 2026-09-06

- Added an opt-in internal employee web phonebook with the shared module design
  language, Table/People layouts, English/Persian search, normalized number search,
  site/department filters, sorting, contact details and cross-page selection.
- Added scoped print/PDF and genuine XLSX output with selectable permitted columns,
  full-result export, frozen/filterable workbook headings and explicit string cells
  that preserve number formatting and never execute formula-like contact values.
- Added PBX-local network/book/field access controls, disabled by default. The
  standalone read-only endpoint rejects forwarded-client and cross-site requests,
  exposes no phone bearer token/AD metadata and rechecks policy for every response.
- Added policy-revision, membership/field projection, IPv4/IPv6 access, HTTP and
  workbook regression tests. Existing phone XML publication remains unchanged.

## 1.0.0 - 2026-08-24

- Added a per-site PBX-hosted TFTP architecture alongside the existing remote
  Docker volume. The Sites workflow can inspect/install/repair `tftpd-hpa`, enroll
  the same jailed key-only storage channel, stage mode changes and copy plus
  SHA-256-verify the complete tree before compensated controller/remote activation.
  Changing a PBX management host or SSH port invalidates that host's TFTP bootstrap
  marker so stale readiness can never authorize a cutover.
- Centralized phone-facing host and storage resolution so phone builds, media,
  firmware, timezone/locale, directories, Phone Services, TFTP Browser and
  replication cannot select different site backends.
- Promoted the integrated FreePBX 17 multi-site provisioner to its first stable
  release with publisher attribution and GPL-3.0-or-later metadata.
- Added a complete administrator manual, architecture/class map, device and
  compatibility guide, troubleshooting guide, security policy, contributor guide,
  third-party notices and deterministic release instructions.
- Added a clean-FreePBX installation guide and a release test covering the complete
  fresh schema, phone-facing wrappers, scheduled dispatchers, bundled dependencies
  and constrained privileged helpers. Installation now reports directory/cron
  permission failures, and uninstall also removes the short managed-media wrapper.
- Made the fresh-install phone MAC index match the runtime schema reconciler's
  required unique `(site_id, mac)` identity, and made concurrent index repair verify
  a duplicate-name race before treating it as success.
- Corrected neighbor-table discovery to enforce the exact configured IPv4 CIDR at
  the bit level, including non-octet masks.
- Bounded remaining Asterisk and FreePBX version probes/reloads so an unhealthy CLI
  cannot indefinitely hold status, preview or publication requests.
- Hardened page routing against non-scalar tab input and hardened release packaging
  against local credentials, private-key material, symbolic links and PBX-local
  signatures.
- Reworked core service comments to explain operational ownership, migration and
  vendor constraints without relying on development history or deployment-specific
  context.
- Prevented a database error from presenting a partial media-usage index as
  authoritative “Unused” data, rejected malformed retained special-media manifests
  instead of treating them as absent, and surfaced default-phonebook registry
  warnings without discarding an already active cache.
- Removed internal design-preview artifacts and environment-specific test fixtures
  from the public source tree while preserving all runtime compatibility and upgrade
  paths.

## 0.17.0rc63 - 2026-08-23

- Removed the wedged `module show like chan_sip.so` probe from config planning,
  Patch Manager inventory, and version activation health checks. Runtime
  `chan_sip` capability is now verified with bounded `sip show settings` calls.
- Bounded Asterisk preflight and managed reload CLI calls so an unhealthy CLI
  command fails clearly instead of holding a PHP preview or rebuild indefinitely.

## 0.17.0rc62 - 2026-08-23

- Split Cisco 7940/7960 CNF endpoint defaults from Cisco XML: CNF remains CHAN_SIP/UDP as required by the handset firmware and has its own site SIP port, while XML phones retain independent UDP/TCP/TLS policy. Generation, Core endpoint updates, Settings, Phones and configuration sync use the same resolved tuple.
- Stopped module-only saves and targeted Asterisk glue/mesh reloads from creating an unrelated FreePBX Apply Config prompt. Core endpoint and directory identity mutations retain their real reload boundary.
- Returned Group Save to the selected site's group inventory, matching Phone and Template save navigation.
- Added one shared per-site scheduled Rebuild All policy to Phones and Settings, with manual/interval/daily/weekly timing, configuration-write deferral, exclusions/manual-override preservation and an optional supported-phone warm reboot. The policy is independently selectable in site synchronization.
- Added an authenticated hash-verified controller configuration ZIP with per-tab export/import exclusions. It includes selected module-owned database configuration and managed files, preserves unrelated records/files on import, and excludes runtime logs, caches, live registrations and Fail2ban bans.

## 0.17.0rc61 - 2026-08-22

- Phone deletion now removes every deterministic per-MAC TFTP config, retires the physical binding, and leaves the FreePBX extension available as unprovisioned. A deleted physical MAC is ignored by automatic discovery until an operator explicitly adds or recovers it.
- Removed rc60's per-line FreePBX device-query loop and stopped unchanged builds from converting Asterisk glue's targeted reload marker into a full FreePBX reload.

## 0.17.0rc60 - 2026-08-22

- Centralized each phone's driver/transport/port as one endpoint tuple used by FreePBX, generated Cisco/Yealink/Grandstream configuration and the Phones UI. Cisco USECALLMANAGER phones have independent CHAN_SIP transport/port site defaults; non-Cisco phones have independent CHAN_SIP/PJSIP transport/port defaults and per-phone overrides.
- Added PJSIP extension discovery, binding, secret/transport updates, live status and SIP NOTIFY routing for supported non-Cisco phones. Driver mismatches now fail visibly instead of producing a configuration that cannot register.
- During a route change, publish the new phone config and send its reload over the old live registration before one supported FreePBX reload activates the new endpoint. The Phones list and editor show the effective tuple and whether it came from a phone override or the applicable site default.
- Upgrades preserve the former shared CHAN_SIP transport/port as both vendor-class starting defaults; fresh installs use PJSIP/UDP/5160 for non-Cisco phones. Either non-Cisco driver remains selectable at the site or phone level.

## 0.17.0rc59 - 2026-08-19

- Made provisioning groups authoritative only when at least one inherited layer matches the phone. Ungrouped phones, empty groups and groups with no compatible layer now use the most-specific site default; matching group stacks no longer receive that default underneath them.
- Automatically order each group's matching layers from broad policy to firmware/config compatibility to exact-model policy while preserving the operator's order inside each Applicability bucket.
- Added multi-member final template exceptions. Explicit exception settings win last, and a non-empty programmable-key/BLF list replaces the earlier template list rather than appending; blank/Inherit fields preserve the resolved stack. Exceptions are validated, traced, synchronized with their groups and protected against missing destination-template dependencies.
- Kept Select visible and Clear visible membership tools and placed saved group members first in the extension list.

## 0.17.0rc58 - 2026-08-18

- Made provisioning-group template rows show the same firmware/config-profile and exact-model Applicability used by the Template inventory and config resolver. Parent groups remain root-to-leaf; matching layers remain top-to-bottom, with blank or inherited fields preserving earlier values.
- Moved the durable replication activity panel to the lower-left so it no longer covers the module's Submit and Reset controls.

## 0.17.0rc57 - 2026-08-18

- Replaced the global Cisco timezone-set selector with one canonical runtime map shared by Model Catalog, Firmware Manager, Settings, Templates, replication, lifecycle validation and generated SEP configuration: Standard JVM phones use `tzupdater.jar`, IBM J9 phones use `j9-tzdata.jar`, and Lodown/RTL/Gumbo phones use `TzDataCSV.csv`.
- Added independent hash-addressed timezone revision histories (three retained per runtime), phone-facing immutable aliases, site-default selectors per runtime and template-level per-runtime inheritance/overrides. A missing `tzupdater.ver` no longer blocks an otherwise usable payload; opaque payloads receive a deterministic content version.
- Added extracted-payload upload and TFTP-root discovery workflows without a COP parser or manual version field. Firmware replication now copies all retained runtime revisions, while configuration pushes transfer the exact archive owning each selected hash before applying Settings or Templates.
- Fixed publication cleanup so adopting one timezone revision cannot remove aliases belonging to another runtime or older selected revision. Partial historical captures can be replicated but cannot replace all canonical root files; only a complete three-runtime capture may use the advanced root-publish rollback action.

## 0.17.0rc56 - 2026-08-18

- Registered the replicated `directory.json` cache as a verified module-written private directory artifact, marked signatures not applicable, exposed its real managed path and directory-endpoint usage, and purged superseded publication hashes instead of accumulating stale Asset Index history.

## 0.17.0rc55 - 2026-08-18

- Made an empty Cisco wallpaper profile authoritative: deleting its final complete image pair now removes the physical `List.xml` and retires the exact managed-asset slot, eliminating ghost verified catalogs.
- Made “all retained timezone versions” replication optionally activate the source PBX’s real active set on each destination, while keeping rollback history behind the advanced disclosure and reporting each phone-family payload independently.
- Reconciled complete phone-usable Cisco timezone payloads as verified configuration assets even when an EOL JAR has no parseable embedded release; the declared `tzupdater.ver` is retained as explicit provenance rather than mistaken for a vendor signature.
- Added a per-site default published phonebook, recorded generated-cache origin, secret-free phonebook snapshot provenance, and a destination action that copies only presentation/search policy into a new local-AD-backed phonebook.

## 0.17.0rc54 - 2026-08-18

- Fixed federated firmware inventory reads that were accidentally filtered by synthetic `model_id=0`, making successfully replicated packages appear absent over both API and SSH. Empty model/profile selectors now mean the complete live inventory, while real model/profile filters remain unchanged.
- Confirmed the Firmware replication workspace exposes firmware packages, retained timezone versions, and Cisco locale companion bundles as independent scopes; the final deployment refresh and visual canary now verify the rendered three-scope layout.

## 0.17.0rc53 - 2026-08-18

- Unified Firmware-tab replication around three independent inventory scopes: all complete firmware packages, every retained usable Cisco timezone version, and every complete Cisco locale companion bundle. Exact package and timezone selections remain available for narrow pushes and optional timezone activation.
- Made template and Settings synchronization dependency-first for template-pinned firmware, wallpapers, hosted media, and exact retained timezone versions. A template-only push now transfers its dependencies before destination validation and falls back only when the source genuinely lacks them.
- Replaced cached remote inventories at replication validation boundaries with live destination reads, registered copied and byte-identical files in the destination PBX registry, and made complete wallpaper catalog manifests authoritative when historical asset-index rows are stale.
- Added a durable cross-page replication activity drawer with concurrent-run history, target context, elapsed time, and completion/failure state so navigation or reload no longer loses track of manual replication work.
- Added destination-local managed-file registration and release gates for the expanded Firmware job schema and replication activity ledger.

## 0.17.0rc52 - 2026-08-18

- Made the recoverable storage reset preserve only exact TFTP config filenames tied to phones currently tracked by the module. Stale, duplicate, and unsupported-vendor config-shaped files are quarantined with firmware, media, and other test-site content.

## 0.17.0rc51 - 2026-08-18

- Removed the generic card navigator from Firmware Manager so its dedicated Packages, Cisco timezone & locale, Site replication, and Asset index tabs are the only section controls rendered.

## 0.17.0rc50 - 2026-08-18

- Made granular Settings synchronization dependency-first: selected firmware defaults, Cisco and hosted wallpaper defaults now copy their exact managed packages/catalogs before destination policy is applied. Missing dependencies leave the destination policy unchanged instead of creating broken references.
- Expanded Firmware replication to copy exact selected packages with their matching Cisco locale companions and an explicitly selected retained timezone bundle; timezone activation remains an independent operator choice.
- Reworked Firmware and Sites replication pickers with searchable bulk selection, bounded panels, clear same-name replacement wording, and dedicated package, timezone/locale, replication, and current-asset views.
- Added a guarded, recoverable CLI reset for replication canaries. It preserves recognized MAC phone configs, quarantines every other public/private TFTP item, clears the corresponding managed inventories, and atomically quarantines the PBX-hosted media library.
- Hid historical stale inventory rows from the normal Firmware asset view; they remain database history rather than being presented as current files.

## 0.17.0rc49 - 2026-08-18

- Widened configuration-sync ownership object types from 32 to 64 characters on existing and fresh databases, covering `settings_default_template_selectors` without transport-specific workarounds.
- Centralized and validated every bounded tracking-row identifier before SQL, removing silent federation truncation and adding a release contract test plus live schema-width deployment gate.

## 0.17.0rc48 - 2026-08-17

- Kept Firmware package/destination selected-count badges on the same bounded row as their search fields at narrow FreePBX app widths, removing the wrapped Bootstrap add-on artifact found during live visual inspection.

## 0.17.0rc47 - 2026-08-17

- Made repeated source-scoped configuration-sync saves idempotent, preventing a stale `id=0` Settings form from violating the job-name unique key while refusing to reuse the name across unrelated synchronization editors.
- Added searchable, scrollable destination pickers with Select visible, Select all, Clear, and live counts to Settings, Templates, Groups, and Firmware replication; firmware package selection now has the same bulk controls and retains staged selections across inventory filters.
- Returned successful Template Save operations to the selected site's template inventory by posting to the site-filtered list route while retaining the existing authenticated save payload.
- Corrected successful firmware replication job actions that were displayed with error styling.

## 0.17.0rc46 - 2026-08-17

- Fixed hosted wallpaper ownership reconciliation so retained Yealink and Grandstream catalog aliases are enriched on the actual wallpaper-set collection instead of a temporary PHP array cast. Generated compatibility outputs now remain verified catalog assets rather than appearing as `Observed only`.

## 0.17.0rc45 - 2026-08-17

- Added exact-package firmware and retained Cisco timezone replication from the Firmware tab, with explicit destination sites, manual/interval/daily/weekly schedules, retry state, and no implicit activation of destination firmware or canonical timezone files.
- Added revision-aware group replication from the Groups tab, including selected-group scope, immutable source identity across rename/reparent, parent-first dependency handling, full destination-stack fallback when a template is missing, destination conflicts, soft-locks, and per-object pause/resume behavior. Duplicate same-parent group names are rejected.
- Added granular Settings replication for SIP/service defaults, BLF federation timing, clock/date/NTP, network/VLAN intent, default-template selectors, managed asset defaults, and Cisco device policy. Unselected subsections and destination PBX identity values remain untouched, and inbound granular locks are shown before a destination edit.
- Recognized retained hosted-wallpaper compatibility aliases as catalog-owned assets so generated Media outputs no longer appear as unaccounted “Observed only” files after a catalog rename.

## 0.17.0rc44 - 2026-08-15

- Enforced unique template names per site with a data-preserving upgrade repair: the oldest row keeps its name and later collisions are renamed with their stable template ID so operators can review or delete the intended copy.
- Returned successful template saves to the selected site's inventory and made row deletion an explicit authenticated POST action with its site context retained.
- Made repeated Duplicate actions choose an available copy name instead of creating another collision.

## 0.17.0rc43 - 2026-08-10

- Treat unambiguous AD site/extension changes as authoritative during synchronization: free destinations and complete move/swap chains reconcile automatically, while duplicate claims and destinations occupied outside the accepted move set remain explicit conflicts.
- Preserve every contact when mobile or employee numbers are shared; numeric search returns each matching AD identity rather than treating those searchable fields as unique keys.

## 0.17.0rc42 - 2026-08-10

- Add an explicit retained-payload workflow for EOL Cisco XML timezone families, exposing the real inspected payload release separately from `tzupdater.ver` and refreshing stale retained-bundle evidence without modifying TFTP bytes.
- Add transactional AD logical-binding reconciliation for reviewed site/extension moves, including chained moves and collision refusal without moving phones or extension-scoped policy.
- Repair legacy AD mobile mapping defaults, relabel the directory `pager` field as employee number (`EMP No.`), and keep substring numeric search across extension, mobile and employee number.
- Remove unsupported XML 3.1 `SoftKeyItem` elements from Cisco 7940/7960 search forms, add an explicit no-phonebook template policy, and rework Cisco display/backlight schedules around inherit, daily, custom-inactive-days and always-off modes.
- Add state and action hover help throughout the Cisco softkey editor plus concise call-presentation and dialing examples.

## 0.17.0rc41 - 2026-08-10

- Make selected-template federation capability-negotiated and fail closed: the source snapshot must echo the exact retained template-ID filter before any destination is contacted. Older PBXs that would otherwise ignore the filter now produce a clear upgrade warning and cannot turn a selected push into an unintended full-template push.

## 0.17.0rc40 - 2026-08-10

- Move selected-template push and recurring synchronization into the main Templates inventory. Jobs retain an exact template selection, destination list, schedule and soft-lock policy; destination revision conflicts remain confirmation-gated, and unavailable firmware, media or phonebook dependencies fall back to the destination site's own defaults with visible sync state.
- Extend AD-backed Directory contacts and the existing live phone preview with a configurable pager attribute, extension/mobile/pager numeric search, minimum digit length, optional direct-mobile results and EditDial policy. Cisco legacy output now sanitizes phone-facing XML text without replacing the established Directory workbench.
- Reconcile every observed member of a complete firmware bundle, recognize generated hosted-wallpaper outputs as catalog-owned, and carry site-default wallpaper usage through the full image, thumbnail and generated List.xml bundle so Asset Index status and usage agree with Media, Firmware and Settings.
- Allow Cisco's frozen legacy `tzupdater.jar` profile to remain explicitly selectable when it is the latest payload Cisco supplied, while preserving warnings and keeping contradictory/corrupt payloads blocked.
- Return phone Save and Save + Rebuild to the selected site's inventory, reset the legacy background editor around each newly selected source, and make tiny grayscale wallpaper profiles editable with a smooth scaled preview plus a separate exact quantized device-output inspection.

## 0.17.0rc39 - 2026-08-09

- Bootstrap the public encrypted federation endpoint with the standard FreePBX unauthenticated restricted-module context so both Core and Ucmprovisioner BMOs are available; key enrollment and the Core-dependent readiness probe no longer fail with an endpoint-generated HTTP 403.
- Replace cross-site numeric phone-model IDs with a stable vendor/model identity and a fail-closed rc39 RPC, preventing a remote W70B from silently becoming an unrelated local-ID model such as T20P. Phone, template and group saves now retain the owning site/editor context.
- Collapse identical PBX-hosted screen delivery copies into one option per catalog output/profile while preserving the exact currently saved URL and every unsaved matrix choice.
- Add an explicit active Cisco XML timezone-set selector and EOL-family payload guidance in Firmware Manager; templates clearly expose only a version requirement, while model mapping continues to own Cisco's canonical filenames.

## 0.17.0rc38 - 2026-08-09

- Run the federation endpoint through the installed FreePBX BMO container so Core-dependent phone management works, probe a real extension-management RPC before calling the API healthy, and treat authenticated `{ok:false}` envelopes as failures so SSH recovery and last-known snapshots actually engage. Live phone status is no longer snapshot-eligible.
- Resolve Phone Information template names through the same default/group assignment trace used by phone configuration generation.
- Add retained per-profile grayscale inversion to standard wallpaper catalogs and to Cisco 7940/7960 special BMP catalogs.
- Add a verified legacy Cisco timezone compatibility artifact workflow for rebuilding `tzupdater.jar` from the newer `tzdata-java` payload in the same Cisco COP.

## 0.17.0rc37 - 2026-08-09

- Made phone removal delete the current and recorded predecessor MAC config
  files through the same TFTP owner before deleting the binding, and preserve
  the database row when remote cleanup fails so the failure remains recoverable.
- Added bounded TFTP permission verification and repair for the module-owned
  `ucmp-tftp` tree, plus authenticated single- and multi-file/directory ZIP
  downloads in TFTP Browser. Public artifacts use readable file/traversable
  directory modes while the private transaction tree remains restricted.
- Unified Settings screen-default discovery with the same managed-media profile
  identity used by Media and Templates, including `cisco_cnf:90x56`.
- Replaced the SIP41-only locale assumption with documented per-firmware-family
  user-locale JAR and network-tone paths. Shared companion files now keep one
  canonical asset identity with all compatible profiles.
- Made Cisco timezone inspection and config decisions payload-profile specific.
  A version mismatch blocks only the exact models requesting that payload;
  opaque but present J9/CSV payloads remain explicitly unverified and produce a
  build warning instead of being falsely certified. Cisco's officially frozen
  post-2021a `tzupdater.jar` is reported as a legacy-profile limitation rather
  than corrupting the whole set: usable family payloads remain capturable,
  rollback-safe and transactionally synchronizable, while the stale Java-phone
  payload is not advertised to 79xx/8961/99x1. Only contradictory releases
  inside one payload require non-activatable quarantine.
- Isolated targeted rebuild validation from unrelated phones' softkey,
  feature-policy and device-only asset dependencies. The selected phones
  remain strictly validated, full-site rebuilds remain strict, and any selected
  invalid layer now names the template that needs correction.
- Made every Cisco List.xml PNG server-owned and device-facing: true-colour
  outputs are flattened to opaque PNG, grayscale branches are constrained to
  their supported palettes, existing unsafe catalog outputs are reported, and
  Media Manager can repair them without losing catalog sources or crop edits.
- Added regression coverage for TFTP ownership, safe deletion/download,
  permission repair, shared asset identity, locale mappings, timezone
  legacy-freeze/variance and quarantine behavior, Settings/Media profile consistency, targeted-build
  isolation, and Cisco wallpaper encoding.

## 0.17.0rc36 - 2026-08-08

- Reconciled the candidate from the installed rc35 source so the live
  federation API cache fix and every prior production behavior remain in the
  release lineage.
- Added exact Cisco XML branch policy for SIP11, SIP41, SIP42, SIP45, SIP70 and
  SIP75 firmware families. Config generation now validates branch-specific
  timezone, locale, wallpaper, identity, dial-plan and companion-file behavior
  instead of assuming that 78xx/88xx rules apply to every XML phone.
- Added managed SIP41 locale companions with exact user-locale `mk-sip.jar` and
  root `g3-tones.xml` paths, inventory/upload/sync controls in Firmware Manager,
  case-sensitive completeness checks and destination fallback when a pushed
  locale dependency is absent.
- Made explicit Cisco softkey states fail closed when semantically impossible,
  retained firmware-visible keys as visual observations only, and expanded
  generation traces for branch selection, label policy, digit matching and
  selected managed assets.
- Replaced the three one-profile-at-a-time site-default selectors with one
  inline compatibility matrix. Firmware and screen choices are edited directly
  in each row, staged changes survive asset/vendor tab switches, offline
  inventory never clears saved values, and inventory can be retried in place.
- Added scheduled cross-site configuration jobs for templates, default-template
  targets, site-default assignments, phonebook definitions and managed media,
  firmware, timezone and locale dependencies. Destination PBX identity,
  credentials, network endpoints and AD bindings are never imported.
- Added per-object revision conflicts, dependency fallbacks, optional soft
  locks and destination-edit auditing. A remote edit pauses only its exact
  template, catalog, phonebook, compatibility-profile assignment or
  default-template target; Resume now clears the stale baseline so the next run
  actually reconciles that object. Source deletions remain non-destructive.
- Added catalog-aware replication ownership for standard and Cisco CNF
  wallpaper catalogs plus immutable ringtone transfer, including collision
  confirmation, post-copy digest verification and visible source/destination
  state in Sites, Settings, Templates, Directory and Media.
- Expanded release coverage with full catalog generation for all 96 supported
  entries, locale companion fixtures, settings-matrix persistence, catalog
  mutation guards and granular cross-site ownership/conflict tests.

## 0.17.0rc35 - 2026-08-08

- Fixed false HTTP 403 failures during federation API enrollment and key rotation by rebuilding the cached per-site transport before the verification probe.
- Invalidated the same cached transport after API disablement and successful enrollment so later work in the request observes the persisted API state.

## 0.17.0rc34 - 2026-08-05

- Closed the final live-rendered FreePBX cascade leaks without changing any
  view markup: striped table cells and preformatted output now obey the shared
  Media-derived module palette even when upstream FreePBX marks its colors as
  important.
- Added regression assertions for the module-scoped table and preformatted
  output boundaries.

## 0.17.0rc33 - 2026-08-05

- Completed the module-wide dark visual contract for older nested widgets used
  by Directory, Firmware, Fail2ban and the remaining legacy form builders.
  Their mini-cards, hierarchy nodes, labels and link actions now use the same
  Media palette instead of retaining light backgrounds with pale text.
- Kept the simulated phonebook handset preview intentionally device-colored
  and gave its navigation actions a dedicated high-contrast preview color.

## 0.17.0rc32 - 2026-08-05

- Replaced the page-by-page Bootstrap overrides with one module-scoped visual
  identity derived from the Media workbench. Buttons, fields, chosen selects,
  cards, panels, tables, menus and feedback states now share the same palette,
  geometry, focus behavior and readable semantic colors across every tab.
- Included Dashboard in the common administration shell and constrained
  FreePBX's table-layout page body while this module is open, preventing wide
  Directory content from expanding the complete administration viewport.

## 0.17.0rc31 - 2026-08-05

- Preserved pre-granular BLF mesh intervals during upgrade instead of seeding
  a competing 60-minute JSON schedule. Installations briefly upgraded through
  rc29/rc30 are repaired only when the untouched seeded JSON conflicts with
  their existing interval; operator-saved granular schedules are left alone.
- Made the legacy AD schedule default defer to its stored interval until an
  operator explicitly saves a granular cadence.

## 0.17.0rc30 - 2026-08-05

- Prevented Patch Manager bundle-state variables from overwriting the page
  router's active-tab value, so its live page now receives the same scoped
  administration shell as the other redesigned tabs. Added a regression guard
  for this include-scope collision.

## 0.17.0rc29 - 2026-08-05

- Added one shared PBX-local recurring schedule grammar for managed AD sources,
  legacy AD sync, BLF mesh publication and media replication. Jobs can now be
  manual-only, run at an exact minute interval, daily at an exact time, or on
  selected weekdays at an exact time; the lightweight runners tick once per
  minute while existing locks, retry backoff and FreePBX-write guards remain.
- Brought Groups, Sites, Directory & AD, Patch Manager, Firmware, Phone
  Services, Fail2ban, TFTP Browser and Settings into the scoped visual shell
  already used by the Media, Phones and Templates workbenches without removing
  their existing controls or backend actions.
- Replaced misleading editable Cisco softkey and feature-policy companion
  filenames with generated lifecycle status, and made the optional fallback
  dial-template selector list only actual root-level dial XML files. Generated
  per-template dial plans continue to take precedence.

## 0.17.0rc28 - 2026-08-05

- Reworked the Templates inventory and editor into isolated Media/Phones-style
  workbenches without removing template scope, phonebook, key, firmware, media,
  dialing, feature-policy, advanced, duplicate or delete controls.
- Split Cisco softkey editing into an emitted `softkeys.xml` layer and a clearly
  non-provisioning handset-visible preview. Exact observed rows are stored by
  preview model and call state, can contain arbitrary firmware labels such as
  Recents, Self View or Ignore, and support ordering, removal and template-tier
  clear/inherit semantics.
- Added conservative firmware cues for the 78xx Recents/Navigation-Up product
  setting and 8845/8865 camera UI while guaranteeing that visual observations
  have no effect on generated device configuration.

## 0.17.0rc27 - 2026-08-04

- Corrected Bootstrap's inherited dark table-cell text inside the Phone editor's
  dark workbench, restoring readable account, extension, label and DECT route
  rows while keeping the rule isolated from every other FreePBX/module surface.

## 0.17.0rc26 - 2026-08-04

- Rebuilt phone bind/edit as one continuous Provisioning workbench with only the
  raw per-phone JSON surface separated under Advanced, while preserving every
  assignment, collision, fallback-label, SIP, manual-override and rebuild field.
- Made Account 1 and additional SIP registrations visible together and retained
  working add/remove behavior for model-bounded secondary accounts.
- Replaced the wide single-cell DECT matrix with extension-aware route rows and a
  searchable handset assignment editor backed by the existing generator keys.
  Grandstream DP750/DP752 now explains and exposes its shared SIP server profile
  separately from extensions; DP755 and Yealink correctly hide that concept.
- Kept unsupported multicell manager/radio phone generation explicitly blocked
  instead of exposing a UI that the current backend cannot truthfully compile.

## 0.17.0rc25 - 2026-08-04

- Removed the duplicate Build & configuration workspace and promoted its only
  unique visible action, rebuild-all, directly into Fleet inventory.
- Added direct per-site BLF mesh schedule controls to Phones using the existing
  validated settings save path and scheduler state.

## 0.17.0rc24 - 2026-08-04

- Unified Phones live-status and operation feedback into the existing visible
  action-result surface, eliminating the empty status strip found by live visual
  inspection and restoring visible feedback for discovery and recovery actions.

## 0.17.0rc23 - 2026-08-04

- Rebuilt the Phones workspace in the approved Media-style visual language while
  preserving fleet inventory, configuration build/apply, BLF mesh, discovery,
  TFTP MAC recovery, network scan, reload, reboot and per-phone actions.
- Added bulk phone deletion and made the effective default-template source visible
  beside directly assigned and group-derived template layers.
- Fixed the post-command inventory refresh callback and added a release gate that
  rejects missing, duplicated or escaped Phones workspace controls.

## 0.17.0rc22 - 2026-08-03

- Made Media Manager's physical inventory cover both the managed TFTP catalog
  and every phone-facing HTTP media library used by Cisco, Yealink and
  Grandstream selectors.
- Exposed valid loose Cisco 7940/7960 BMP files beside retained legacy catalogs,
  with explicit adopt and delete actions. Adoption retains the existing bytes,
  filename and query-free phone URL.
- Made template and site media selectors consume the same validated filesystem
  inventory, and report background as well as ringtone assignments.

## 0.17.0rc21 - 2026-08-03

- Added bounded multi-select deletion for provisioner inventory rows without
  deleting the corresponding FreePBX extensions.

## 0.17.0rc20 - 2026-08-03

- Kept the wallpaper scene's explicit top/left anchors from being reset by a
  later `inset` shorthand, eliminating the final double-centering offset.

## 0.17.0rc19 - 2026-08-03

- Removed the wallpaper stage host's residual grid alignment so the camera is
  the single source of centering for both tiny and full-resolution profiles.

## 0.17.0rc18 - 2026-08-03

- Made explicit catalog creation recoverably retire an incompatible hidden
  retained manifest instead of blocking reuse of its catalog name after the
  rendered files have been removed.
- Centered the wallpaper editor camera, added independent view zoom for small
  and high-DPI profiles, and exposed the existing documented 2-tone/16-tone
  grayscale rendering in the visible preview.

## 0.17.0rc17 - 2026-08-03

- Rebuilt the Media workspace against the approved v3 visual and interaction
  contract as five isolated, lazy-loaded work areas. Wallpaper controls can no
  longer leak into Ringtones, Replication or the bounded Asset Index view.
- Added a catalog-first wallpaper workbench with scope-derived profiles, retained
  per-profile crop/scale and optional source overrides, reversible profile
  disablement, eight bounded drag handles, catalog rename/delete and exact-set
  replication.
- Reworked non-standard media as a Cisco 7940/7960-only retained BMP catalog,
  including strict 90x56x8 uncompressed-BMP validation and direct query-free
  device URLs. Added multi-file ringtone/audio upload, browser preview and exact
  selected-file replication for Cisco XML, Yealink and Grandstream libraries.
- Added exact retained-catalog replication contracts, target-side Cisco ringtone
  index regeneration and scheduled cross-brand media replication without probing
  remote sites while the Media page loads.
- Added release-blocking structure, containment, interaction, asset-version and
  backend integrity gates; the stylesheet URL is keyed by its content hash so a
  normal browser reload cannot retain a stale Media layout.

## 0.17.0rc16 - 2026-08-02

- Rebuilt Phone Media as distinct wallpaper, non-standard/legacy, ringtone,
  replication, and asset-index work areas. Wallpaper catalogs now support a
  master source, scoped profile list, retained per-profile images and placement,
  reversible profile disable/enable, selected-profile or whole-scope saves, and
  catalog-level rename/delete without orphaning generated files.
- Added a bounded direct-manipulation wallpaper editor with a fixed viewport,
  draggable image frame, eight resize handles, auto-cover validation, and an
  editor-only fit camera that keeps controls reachable without changing the
  rendered phone image.
- Added browser playback for uploaded and retained Cisco `.raw`/`.rwb` tones by
  wrapping their headerless audio in a temporary client-side PCM preview; phone
  files are not rewritten.
- Added controller-owned recurring media replication jobs with selectable source,
  target sites, scopes, interval, pause/edit/delete/run-now controls, nonblocking
  locks, and retry handling for unavailable sites. The UI explicitly states the
  current preserve-existing limitation instead of claiming remote hash equality.
- Upgraded linked wallpaper manifests to version 2 while retaining version-1
  compatibility, source/hash evidence, custom-source-aware rename/delete/sync,
  disabled-output retirement, and managed-asset registration regression coverage.

## 0.17.0rc15 - 2026-08-01

- Added Cisco firmware/config compatibility scopes alongside the existing broad
  policy groups. Operators can now target SIP11, SIP41 (7941/7961), SIP42,
  SIP45, SIP70, SIP75 (7975), separate 88xx audio/video/wireless/conference
  loads, and distinct 894x/8961/9951/9971 loads without breaking saved
  `cisco_79xx`, `cisco_78xx`, `cisco_88xx`, or `cisco_89xx_99xx` templates.
- Made default-template precedence deterministic: exact model, then Cisco
  compatibility load, broad policy group, family, and optional role. Template
  and General selectors share the same catalog/matcher used by save validation
  and both runtime resolvers.
- Fixed legacy Cisco 7940/7960 logos end to end. The media API now separates the
  direct query-free plain-HTTP BMP URL persisted for P0S3 from the browser-only
  cache-busted preview URL. Previously emitted module-owned `?v=` URLs are
  repaired narrowly during generation; arbitrary external query URLs remain
  rejected.

## 0.17.0rc14 - 2026-07-30

- Replaced the Cisco call-state softkey editor and compiler from first
  principles. Schema-2 policies preserve the operator's exact per-state order,
  duplicate actions, explicit empty `Undefined` slots, sparse template
  inheritance, cleared states and firmware-specific raw definitions. The
  compiler never silently inserts, deletes, deduplicates or model-filters a
  schema-2 choice.
- Removed hard-coded "permanent" and inferred firmware rows from the editable
  arrangement. Product settings and operator-recorded handset observations are
  kept visibly separate from emitted `softkeys.xml`; a transparent repeat-across-
  states helper replaces the nonexistent Cisco "permanent" XML flag.
- Added an in-memory, operator-visible migration for legacy softkey maps. Existing
  rows continue through the frozen legacy compiler until explicitly saved, while
  dropped legacy display tokens remain visible as annotations/unresolved entries
  instead of disappearing.
- Added keyboard/touch Up/Down ordering beside drag-and-drop, searchable raw
  definitions and custom states, server-side map/reference validation, and
  regression coverage for empty-map serialization so a new policy cannot vanish
  when it is saved.
- Replaced feature-policy checkboxes with explicit Inherit/Enabled/Disabled
  tier values. Untouched controls no longer create an accidental all-disabled
  policy, and the editor states the final fallback for unresolved features.
- Stopped referencing unmanaged default `softkeys.xml`/`featurepolicy.xml` files,
  removed the model-dependent shared-filename collision, made 7800 Recents an
  explicit Inherit/Enabled/Disabled product setting, and enabled the documented
  softKeyControl alternative on 8961/9951/9971.

## 0.17.0rc13 - 2026-07-30

- Fixed the repeated Asterisk-file permission regression at its source.
  Adjacent atomic writes now preserve the `/etc/asterisk` owner and group even
  when a rebuild or verifier runs as root, instead of replacing
  `sip_custom_post.conf` and related managed files with root-owned mode-0660
  files that Apache cannot read.
- Reworked Cisco enterprise softkey previews from the live 7841/8845 configs
  and current Cisco documentation. Configured XML rows, product settings,
  built-in controls, incoming-alert overlays, physical keys and observed
  contextual controls are now distinct. The 78xx Recents setting remains
  enabled, unsupported Video Mode rows are blocked, and merged phone services
  preserve the native Missed/Received/Placed applications required by Recents
  and idle Navigation Up.
- Reorganized phone media around linked catalogs: one source ingest, scoped
  fleet/brand/series/model generation, retained per-resolution transforms,
  catalog cards, whole-catalog rename with transactional reference updates and
  rollback, managed compatibility aliases, and whole-catalog lifecycle delete.
  Same-name replacements now use content-hash cache busting.
- Added documented Cisco one-bit/four-bit grayscale wallpaper profiles and
  four-tone 7940/7960 BMP conversion, while moving Yealink T58A and VP59 into
  standard wallpaper catalogs. Prepared Yealink/Grandstream audio or
  firmware-specific files remain separate from standard wallpapers.
- Added bounded multi-file uploads to the TFTP Browser with per-file validation,
  managed-config override warnings and partial-failure reporting.

## 0.17.0rc12 - 2026-07-29

- Fixed Cisco XML dial-template identity end to end: each SEP file now
  references the exact stack-keyed file written by the same build. Rebuild All
  conservatively retires obsolete generated dial/softkey/feature-policy files
  while preserving current, rollback and manually referenced companions.
- Rebuilt the Cisco softkey editor around configurable `softkeys.xml` states
  plus separately labelled firmware-owned 7841/8845 controls. The preview now
  reflects observed Call, ABC, Ignore, Details and conference/new-call screens
  without serializing unsupported fake softkeys.
- Made phone reassignment optionally move the physical fallback label with both
  sides of a swap. Accepted AD identity, template formatting and audited final
  per-extension overrides remain attached to the logical extension.
- Generalized linked wallpaper catalogs across supported Cisco, Yealink and
  Grandstream display profiles, preserving one retained source and independent
  crop settings per size. Whole-catalog replacement/deletion, same-name cache
  invalidation, Grandstream JPEG limits and prepared vendor ringtone pickers
  are now explicit.
- Reconciled the advanced asset index with manager evidence: complete Cisco
  timezone payload pairs become verified, and unchanged module-generated
  `List.xml` catalogs retain generated/verified provenance after generic scans.
- Made the protected Company Directory and Phone Information service routes
  publishable or hidden without duplicating their types, documented their
  separation from native phonebooks, and added an in-place, history-preserving
  upgrade from the `legacy-default` AD compatibility authority.

## 0.17.0rc11 - 2026-07-28

- Fixed the Phones rebuild modal getting stranded on `Applying...` after a
  successful commit by handling the associative TFTP receipt returned by the
  backend and making completion UI finalization independent of detail rendering.
- Added an effective Template column to Phones. It uses the config generator's
  applicability resolver and identifies layers as `(Default) Name` or
  `(Group: Name) Name`, with site catalogs cached once per request. Federated
  rows retain their controller-side site ownership, and All sites now actually
  aggregates each site's cached/live inventory instead of showing only local rows.
  Live registration/reachability is also queried and matched by site plus
  extension, so duplicate extension numbers cannot inherit another site's state.

## 0.17.0rc10 - 2026-07-28

- Fixed fresh-install and upgrade reconciliation for the managed-asset table:
  long paths/JSON use `TEXT`, and every lifecycle column now exists in both the
  FreePBX manifest and runtime migration.
- Replaced fragmented AJAX calls with one module-scoped POST/CSRF transport,
  without altering unrelated FreePBX requests or breaking `FormData` uploads.
- Made phone preview/apply deterministic by removing inventory and cross-site
  work from the config transaction. BLF federation now has an explicit
  preview/apply action and a guarded, configurable schedule.
- Added explicit Phonebook Designer inheritance for directory labels and
  completed GHP630/631 context keys, display/date, firmware schedule, ringtone
  and VLAN controls from the current vendor parameter template.
- Generalized linked wallpaper catalogs across standard Cisco XML, Yealink and
  Grandstream screens with fleet-to-model scope, retained per-profile crops and
  whole-catalog deletion. Added operator-declared Yealink/Grandstream firmware
  compatibility without guessing from arbitrary filenames.
- Scoped managed-asset scans by manager so media deletions are not hidden behind
  firmware hashing, while stale rows are still retired only after a complete
  bounded traversal.
- Added TFTP multi-delete, managed phone-config override tracking, per-phone
  bulk-rebuild exclusion and readable breadcrumbs.
- Bounded firmware discovery to the root and known vendor trees, made declared
  vendor firmware/sidecar replacement recoverable, and restored the prior phone
  config when manual-override tracking cannot be committed.

## 0.17.0rc8 - 2026-07-27

- Separated site-wide Asterisk glue endpoints from real watcher-to-BLF
  subscriptions, made glue preview hashes cover the exact generated blocks, and
  restored default-template buttons for ungrouped phones.
- Scoped the AJAX token boundary to UCM Provisioner requests and preserved
  `FormData` uploads, eliminating the cross-module POST/serialization regression.
- Hid retired managed assets from the normal inventory and made abandoned or
  errored scan cursors restart cleanly while retaining explicit resume safety.
- Added linked Cisco wallpaper sets with retained sources, per-profile crop
  settings, whole-set deletion, exact-profile template choices, collision
  protection, rollback-safe writes and cross-site synchronization that rebuilds
  each target catalog without deleting target-only images.
- Corrected Cisco 8941/8945 wallpapers to the documented `640x480x24` directory,
  preventing their 640x480 files from being mixed into the 88xx
  `800x480x24` catalog.

## 0.17.0rc7 - 2026-07-27

- Made Cisco SEP XML and its softkey, feature-policy and dial-template
  companions deterministic. Preview and Apply now hash identical content when
  the configuration is unchanged, while the stale-preview safety check remains
  enforced.
- Fixed Cisco phonebook search XML Error [4] by keeping the search prompt and
  dynamic directory title within Cisco's 32-character XML schema limits.

## 0.17.0rc6 - 2026-07-27

- Made authenticated module AJAX fail closed through one early transport
  boundary: every module request is POST and carries the page token, including
  initial catalog loads and uploads. Added regression coverage for the FreePBX
  closed-session lifecycle.
- Fixed recurring template data loss by serializing empty settings as a JSON
  object and rejecting malformed/list payloads server-side. Added exact
  extension identity previews and migration-time binding of accepted AD rows.
- Added retained Cisco timezone versions with payload-derived metadata,
  immutable hashes, three-version history, transactional activation/rollback,
  cross-site archive synchronization and compatibility-aware template selection.
- Reworked managed-asset rescans into resumable time-bounded jobs with an
  inventory-preserving restart path.
- Fixed protected Phone Services editing and duplicate type options. Company
  Directory and Phone/site Information remain unique built-ins; custom
  quick-dial and notice services remain addable.
- Made the phonebook preview use the same compiled name formatter as publication,
  exposed separate Cisco alphabetic and numeric search fields, and documented
  vendor-specific mobile-number output.

## 0.17.0rc5 - 2026-07-27

- Moved Cisco XML timezone/DST ownership into Firmware Manager. It scans the
  owning site's canonical `tzupdater.ver`, `j9-tzdata.jar`, `tzupdater.jar`,
  `TzDataCSV.csv` and `tzdb.dat` set, reports missing, wrong-case and mixed
  versions, hashes every member, and can synchronize a complete set to sites.
- Removed manual timezone-data versions and filenames from General Settings and
  Template Manager. Generated XML now reads the exact served version and selects
  the Cisco-documented payload by model; legacy 7940/7960 CNF remains separate.
- Retained the rc4 early AJAX token transport and its page-lifecycle regression
  checks for Patch, Media, Firmware and every other authenticated module tab.

## 0.17.0rc4 - 2026-07-27

- Installed the shared AJAX token prefilter before rendering any tab view.
  FreePBX may render the module into an already-ready jQuery page, where Media,
  Firmware, TFTP and Phone Services otherwise issue their initial requests
  before a later prefilter can attach the token.

## 0.17.0rc3 - 2026-07-27

- Fixed the shared authenticated AJAX boundary so page-created CSRF tokens
  remain valid after FreePBX intentionally releases its session lock. This
  restores Patch Manager, Media, Firmware and every other module AJAX action.
- Preserved the actual authenticated error inside the response payload so tabs
  display the real failure instead of a generic catalog or inventory message.

## 0.17.0rc2 - 2026-07-26

- Replaced the over-engineered package-recipe Patch Manager with the requested
  FreePBX 17 source/binary bundle workflow. Operators can select the latest four
  matching Asterisk 20 / USECALLMANAGER pairs, preview exact commands, download,
  patch, configure, optionally enable strong SRTP suites, build and stage them.
- Added capture and bounded import of an existing Asterisk binary or exported
  bundle, explicit activation with startup validation and automatic restoration,
  selectable rollback history, and retention of the three most recent
  activations.
- Added separate running, installed, active-managed and pinned status. Pinning
  holds FreePBX's `asterisk-version-switch` package so FreePBX updates do not
  replace the selected source-built Asterisk.
- Added compiled Asterisk bundle synchronization to other enrolled sites. As
  with media, firmware and phonebook synchronization, receipt never activates
  the asset on a target PBX; import and activation remain explicit there.
- Recorded a distribution-aware native package updater, signed repository policy
  and universal transactional recovery as a deliberately deferred enhancement.

## 0.17.0rc1 - 2026-07-26

- Made independent Active Directory sources authoritative for identities,
  labels, extension policy and phonebook publication.
- Added logical-extension overrides, label formatting and model-specific Cisco,
  Yealink and Grandstream label surfaces and limits.
- Added ordered group inheritance and vendor/family/series/model template
  precedence with traceable previews and default-template rules.
- Added verified cross-vendor VLAN policy, multi-account and DECT base/handset
  management, and broader Yealink/Grandstream generation coverage.
- Unified firmware, background, ringtone, phonebook and companion/timezone files
  in a hash-addressed asset lifecycle with compatibility-aware selectors.
- Reworked Phone Services with exact-model targeting, validation, ordering,
  model-aware previews and offline cached catalogs.
- Added last-known-good site snapshots and read-only offline UI behavior.
- Added hash-approved transactional phone builds, TFTP generation receipts,
  Asterisk compensation and two-phase cross-site publication mesh changes.
- Added a local-only, fail-closed Patch Manager with immutable catalog entries,
  fixed privileged operations, locks, proof validation and hash-chained rollback
  receipts. No current upstream candidate is enabled for production installation.
- Locked and fully vendored production dependencies and added deterministic
  release archives with source identity/checksum files.
