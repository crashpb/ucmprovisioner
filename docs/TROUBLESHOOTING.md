# Troubleshooting

Start with the selected site and the operation timestamp. A local live result, a
remote live result and a remote snapshot are different evidence. Do not modify files
until the failing ownership boundary is known.

These notes describe the 1.1.0rc6/schema 33 local candidate. A fixture passing on
the development host is not evidence of a live FreePBX, MySQL, LDAP, proxy or handset
outcome. Record installed version and actual observation separately.

## Collect a safe baseline

On the affected PBX:

```bash
fwconsole ma list | grep -i ucmprovisioner
fwconsole ma list | grep -E 'framework|core'
asterisk -rx 'core show version'
asterisk -rx 'sip show settings'
asterisk -rx 'core show application SIPCiscoPage'
systemctl is-active asterisk httpd crond
```

Do not paste database secrets, generated phone credentials, API keys, LDAP bind
files or an unredacted configuration archive into a public report.

## A page says POST/token is missing or expired

All mutations require an authenticated FreePBX session, POST and a page token.

1. Reload the full module page, not only a bookmarked AJAX URL.
2. Confirm the browser did not restore a form from before login/session expiry.
3. Confirm the request is POST and includes the page token; a direct GET is rejected
   intentionally.
4. Check reverse-proxy/session cookie settings and whether multiple PBX hostnames are
   being mixed in one session.
5. Sign out/in and reproduce with one tab before clearing server state.

The module must never weaken this boundary to make a stale browser tab work.

## Preview works but Apply reports “configuration changed”

Apply accepts only the exact current plan hash. Inputs include phone/template/group
revisions, site defaults, logical identity, current managed assets, generated
companions and shared Asterisk state.

- Read the returned new preview and identify the changed item.
- Wait for an active directory sync, settings/template replication or another phone
  edit to finish.
- Ensure the asset inventory is not being replaced manually between preview/apply.
- Apply the new plan only after reviewing it.

Repeatedly submitting the old hash is not a fix. If the plan changes with no visible
mutation, capture both plan summaries and operation timestamps.

For a reviewed identity or phonebook run, also compare accepted-source freshness,
logical adoption/overrides, compiled audience/layout and the fixed destination
IDs/site keys. A newly enabled site belongs in a new review, not a late addition to
the running plan. Explain and unsaved draft Impact help inspect policy; they do not
apply it. An older/stale result must not acknowledge a newer relationship revision.

## Rebuild remains “applying” or is very slow

The build worker uses bounded external calls; a hung Asterisk CLI should return a
timeout rather than hold forever.

1. Check the module operation log and `ucmp_phone_builds`/apply transaction status.
2. Verify `asterisk -rx 'sip show settings'` returns promptly.
3. Verify the selected site's TFTP SFTP and permissions from **Sites**.
4. Check whether another FreePBX process is writing `/etc/asterisk`.
5. Check disk space/inodes on PBX and TFTP host.
6. Do not run an asset rescan as part of the rebuild; complete or cancel that job
   separately.

An interrupted transaction should retain a failure/compensation receipt. Do not
clear “pending” manually until the receipt and actual file hashes have been compared.

## Permission denied for an Asterisk configuration file

Example: `/etc/asterisk/sip_custom_post.conf` cannot be read.

```bash
namei -l /etc/asterisk/sip_custom_post.conf
ls -l /etc/asterisk/sip_custom_post.conf
fwconsole chown
```

The FreePBX/Asterisk service account must be able to read managed Asterisk files and
write them through the supported owner. Investigate who created a root-only file;
do not make `/etc/asterisk` world-writable.

## Cisco preflight says CHAN_SIP/Cisco support is unavailable

Check the bounded commands directly:

```bash
asterisk -rx 'sip show settings'
asterisk -rx 'core show application SIPCiscoPage'
```

If CHAN_SIP is not active, verify the running Asterisk build, module configuration
and startup logs. If `SIPCiscoPage` is absent, the running process is not the expected
USECALLMANAGER-patched build even if a patched bundle exists on disk. Patch Manager's
Running and Active managed values can legitimately differ after a failed/reverted
restart.

## A phone does not register after a driver/transport change

Compare all three layers:

1. Phones list effective `Driver/Transport/Port` and its source.
2. FreePBX endpoint technology and transport.
3. Generated phone proxy/transport/port.

For CHAN_SIP:

```bash
asterisk -rx 'sip show peer EXTENSION'
```

For PJSIP:

```bash
asterisk -rx 'pjsip show endpoint EXTENSION'
```

“Device not configured to use this transport type” means the registration reached a
peer whose allowed transport does not match the phone. Rebuild after correcting the
single inherited/override source. During a switch, the module attempts to send the
reload over the previous live route; an offline phone must be restarted manually.

## A generated dial/softkey/feature-policy file is missing

The phone config and companion are one build generation. Inspect the preview's file
list and TFTP transaction result. A template-specific companion uses a content/stack
addressed filename; it is retired only when no current generation references it.

Do not copy an old hash-named XML file into place. Correct the template/dial policy,
preview, and apply the generation so registry ownership and the phone reference agree.

## A remote site is offline, returns 403 or mentions a missing FreePBX class

1. In **Sites**, run the PBX channel test and inspect API and SSH results separately.
2. Verify both PBXs run the same module version.
3. Confirm Core and Framework are installed/enabled on the remote PBX.
4. Re-enroll the API/agent if the endpoint or key changed; verify the host key before
   accepting it.
5. Confirm the remote `/ucmprovisioner-api.php` endpoint is installed and that a
   proxy/firewall permits POST.
6. A successful TFTP or phone-status path does not prove the PBX API works; they are
   separate channels.

Until live connectivity returns, use the dated snapshot only for inspection. Do not
interpret missing snapshot sections as empty remote data.

If a write has an operation ID and an uncertain outcome, stop automatic retry.
Read the exact destination receipt through the available management channel. A
`running`, missing or unreachable receipt is not proof that nothing happened; never
send the mutation over SSH merely because its API response was lost. Encrypted
receipts can contain sensitive results and must remain private.

An identity packet rejected for claiming the receiver's local origin is an ownership
guard, not a reason to disable validation. Federation provides shared administrative
trust, not independently verified origin identities for mutually hostile sites.

## TFTP writes fail or phones cannot fetch files

Use **Sites > Verify permissions**. Confirm:

- the site shows the intended **active** TFTP architecture (a proposed mode does
  not serve phones before cutover);
- PBX or Docker management address/SSH port and pinned host key;
- phone-facing TFTP address;
- the enrolled `ucmp-tftp` account and `/tftpboot` root;
- for PBX-hosted mode, `tftpd-hpa` is active, enabled, bound to UDP/69 and serving
  `/srv/tftp/tftpboot`; use **Inspect PBX** for the same checks;
- for Docker mode, the bind path and container state;
- generated directories are traversable and phone-facing files are readable; and
- firewall/UDP TFTP behavior from the phone VLAN.

Use **Repair published permissions** for module-owned paths. Do not recursively chown
an entire host or unrelated bind directory.

If a mode change is staged but cutover preview fails, repair the target first. A
“tree changed” message means a build or replication modified the source during the
copy; wait for it to finish, preview again and retry. The old provider remains
active. PBX bootstrap refuses a different service already bound to UDP/69 rather
than replacing it; stop and identify that daemon before deciding which one owns
the site.

### Destination says it is locked

Transfer-protocol copies record the chosen API/SFTP channel and recovery ID in
each destination result. A `receiving` receipt has private staged data; a
`committing` receipt can include live file changes. Compare the receipt's manifest,
stage, backup, live hashes and local inventory before choosing recovery. Switching
transports or starting another job does not resolve an uncertain commit.

If preflight reports an unsupported transfer protocol, upgrade the participating
PBXs to compatible builds. API authentication/certificate failures stop the job;
only API connectivity/service unavailability selects the SFTP/SSH backup. A backup
failure requires checking both the active TFTP SFTP endpoint and the PBX agent's
configured host, nonstandard SSH port and enrolled pins.

Read the selected site's lock status and owner receipt. A source/destination copy
may legitimately hold more than one site. Confirm all controller, scheduler and
manual writers are stopped before declaring a lock abandoned. Reconcile retained
generation/copy receipts with current files and database state. Only then may an
administrator remove the exact verified abandoned lock. Age, PID alone or a failed
browser request does not authorize stale-lock stealing or removal of the whole
`.ucmprovisioner` tree.

## A Cisco background is blank

- Confirm the selected catalog contains the exact model profile and both required
  full/thumbnail roles.
- For Cisco XML, check the generated List.xml in the documented profile directory
  and verify its filenames exactly match present files.
- Confirm the phone's firmware supports that profile/defaultWallpaper behavior.
- For 7940/7960, confirm a direct plain-HTTP 90×56 Windows 256-color BMP URL with no
  credentials, query string or fragment. It does not use List.xml.
- Confirm the phone can reach the PBX-hosted HTTP endpoint from its VLAN.

Review catalog lifecycle from Media, not by removing individual generated variants.

If generic HTTP copy reports changed source, conflicting chunks or changed
destination, preserve its transfer ID and retained prior bytes. Do not reuse the ID
with different content or overwrite a competing transfer's payload. A fresh transfer
is appropriate only after the prior outcome and intended source revision are known.
The retained receipt distinguishes completed publication from a lost final response.

## Cisco time is one hour wrong

First identify the phone's runtime profile in the firmware/device guide.

- 79xx/8961/99x1 Standard JVM: `tzupdater.jar`.
- 78xx/88xx IBM J9: `j9-tzdata.jar`.
- 39xx/69xx/894x Lodown/RTL/Gumbo: `TzDataCSV.csv`.

Check the site's selected default revision for that runtime, the template override,
the generated payload reference and the served file hash. A valid J9 payload says
nothing about a 7941's Standard JVM data. For EOL Standard JVM phones, select the
latest retained compatible custom/official payload that contains the required DST
rules, then rebuild and restart the phone.

Also verify NTP reachability, Cisco timezone name, optional Olson zone and the
handset's received date/time after a full restart.

## Asset Index shows observed, incomplete, stale or unused

- **Observed**: recognized bytes exist but no current module manifest/receipt owns
  them. Inspect through the appropriate manager; observed is not proof of no use.
- **Incomplete**: a declared bundle/catalog lacks a required role or hash/size.
- **Stale**: registered identity and current bytes/path differ.
- **Unused**: no use was found in the reported inventory. Retirement still requires
  complete database/storage, alias and retained-generation reference checks.

Complete an explicit rescan and wait for its final result before comparing views.
Do not trust a timed-out partial scan as a retirement decision. A manager-created
List.xml should be linked to its catalog manifest; a separately discovered List.xml
is foreign/obsolete and should be removed through a reviewed cleanup plan.

### Retirement is blocked or restore conflicts

Use the exact site's fresh retirement preview. Review reference owners, required
companions, protected bytes and the proposed compatible reassignment. Missing
inventory, a live scan or retained aliases/rollback generations deliberately blocks
quarantine. Rebuild affected owners after approved reassignment, then preview again;
do not unlink the file to bypass the graph.

Quarantined payloads and complete editable HTTP catalogs remain on disk. Record the
recovery ID and return to its original site/library for explicit restore. A current
path with different bytes is a conflict, not permission to overwrite it. If a
response was lost, inspect the receipt/current hashes before another attempt.

## Directory synchronization fails or remains “Legacy”

Test the source, bind, base DN, TLS and paging. A failed source preserves its last
accepted revision. “Legacy/compatibility” means the source was constructed from the
older single-domain settings. Normal installation now converts its credential
storage in place. If it remains Legacy after upgrade, review the failed install
message and retained recovery archive, correct the reported missing/invalid input,
and retry the normal installation. Source identity and accepted history must be
preserved; do not delete/recreate the source as a migration shortcut.

If an AD user moves site/number, the logical identity normally follows AD. A
reconciliation warning should remain only for a genuine collision with another
identity/manual record.

For a wrong full caller name or short label, use the Identity editor to inspect
binding mode, accepted source and adoption separately. An AD primary owns its normal
mapping; a linked additional extension refers to a stable person; manual/shared
identity is explicit. A missing linked person retains prior delivery and needs
source review. An override retained for the previous person must not be reassigned
automatically to a new owner of the number. A normal cached foreign projection is
fallback data, never permission to override local authority.

The force-refresh toolbar reads the last accepted source only. It does not repair
LDAP by querying a new revision. Successful file publication or PBX activation does
not prove that an offline phone fetched it or displayed the new label; check the
separate device observation and representative handset.

An enabled LDAP object with an empty mapped display name no longer blocks every
person. If its stable GUID was accepted before, its last good identity and original
last-seen time are retained with a warning; a new nameless account is skipped.
Do not interpret a successful source read as fresh identity data for a retained
person. Missing/repeated GUIDs, incomplete paging and an unreadable prior catalog
still prevent acceptance. Existing proven AD-primary bindings are classified during
install/sync without a blanket per-extension adoption step; manual overrides remain.

## Employee web phonebook denies access or exports stale data

- Inspect the local policy revision and safe peer/client/reason diagnostic.
- Direct mode checks the actual connecting address against allowed networks and
  ignores forwarded headers. No extra proxy list is required. For a private proxy,
  upstream access restrictions determine which readers can use that route.
- Optional original-client filtering uses trusted-proxy mode: an exact peer IP and
  one overwritten single-IP `X-Real-IP` or `X-Forwarded-For`. Other, missing or
  chained client headers are rejected only in that mode.
- Test HTML, assets, JSON and export routes under the same policy. A preferred URL
  setting does not configure DNS/TLS/proxy/firewall or prove that those routes work.
- Verify Excel export is enabled and the selected fields/book are allowed. Refresh
  after accepted membership or policy changes; exports reject stale selection IDs.
- Compare accepted-source freshness with cache generation, not with live presence.
  Search and alternate-name matching use only fields projected by policy.

For a mass audience-change warning, inspect actual additions/removals, sites and
exposed fields in the compiled review. Approve only the current intended revision;
do not remove exclusions simply to make the review pass.

## Directory search misses mobile/EMP No. or returns CMXML error

- Confirm those attributes are mapped and present in the last accepted source
  revision.
- Confirm the active phonebook includes number-field search and is published.
- Search fragments are normalized; inspect the generated cache entry rather than the
  phone display alone.
- Multiple users with the same fragment should all appear subject to the page limit.
- EMP No. is searchable but not dialed; mobile matches may expose EditDial only for
  compatible Cisco XML clients.

For Cisco CNF, reproduce using the root menu and number/alphabet input modes. Check
the phone-facing response with the same family/MAC route and validate that it is
within the legacy CMXML subset. An HTML/PHP warning before the XML root causes the
phone's generic CMXML error; inspect the web/PHP log for that timestamp.

## Replication reports missing dependencies

Open the source manager and verify the referenced logical object is current and
selected for replication. Firmware-tab replication includes firmware, timezone and
locale; Media includes catalogs/audio; phonebooks transfer their generated cache.

Check durable replication activity for the prepare/transfer/commit stage. The
destination must receive and validate bytes before a template/default reference is
accepted. Missing dependencies intentionally fall back to the destination default
and remain flagged. Do not replace the warning with a dangling filename.

When overwrite is enabled, identity is the object type plus logical key/name. A
destination object with that identity is replaced; unrelated objects remain.

## FreePBX Apply Config appears unexpectedly

Module-only changes should not request a full FreePBX reload unless they changed a
Core endpoint/directory identity or another FreePBX-owned configuration boundary.
Targeted Asterisk glue and mesh operations use their own bounded reload.

Record the exact action that raised the banner. If it changed only media, a template
draft, asset metadata or a replication schedule, report it as a regression with the
operation log entry.

## Safe recovery principles

For interrupted operator reviews, use **Read retained change status** or **Recent
owner changes** / **Recent directory changes**. History is read-only and bounded;
`applying` remains uncertain and an expired plan cannot be executed. A failed final
receipt update does not prove the underlying assignment/build was undone.

Replacement results retain before/observed-after assignment mappings and explicit
checks. To reverse, compare current physical phone IDs and extensions with the
recorded before mapping, then create fresh guarded replacement reviews; blank means
leave an assigned phone unassigned. Skip mappings already restored by another swap.
Review new collisions instead of automatically displacing a newer owner. This is
not exact configuration restore: old phone files, PBX activation and physical-device
state still require separate checks, especially for an unassigned displaced phone.

- Prefer manager-level delete/retire over raw file deletion.
- Preserve transaction receipts until actual files and DB state agree.
- Do not drop tables or clear the whole asset registry to repair one object.
- Do not disable request tokens or helper validation to bypass an error.
- Do not activate a replicated Asterisk bundle automatically.
- If recovery would overwrite newer external edits, stop and compare hashes first.

### Key, archive, Core or runtime recovery is required

Ordinary credential reads never create or migrate a key. A missing, over-permissive
or disagreeing private/database key needs the matching protected database/key
backup; a new key cannot decrypt old ciphertext. Automatic install-time conversion
requires a verified matching local recovery archive and preserves the same key.
An installed-but-not-ready remote result is not a successful upgrade. Inspect its
reported phase, keep the candidate/previous code and private recovery archive, and
retry the normal Install/update after correcting the issue. Never reinstall older
code alone after credentials have moved out of its database representation.

Configuration and recovery ZIPs are distinct modes. Configuration is projected;
restricted recovery may contain secrets. Imports require local controller identity,
matching mode and verified members; SHA-256 is not a digital signature. Do not use
an archive from another controller as a shortcut for configuration federation.

For `recovery_required`, preserve the recovery ID, before-state and unresolved steps.
Core write compensation, Asterisk runtime restoration and database/file archive
rollback can fail independently. Inspect each boundary and the current hashes or
runtime identity; do not mark a request successful because one file was restored,
one process started or the version string matches.
