# UCM Provisioner administrator manual

This manual describes the local UCM Provisioner 1.1.0rc33 candidate, schema 34, on
FreePBX 17. It is not evidence that the candidate is installed or production-tested.
It assumes
the administrator understands extensions, SIP/PJSIP endpoints, VLANs and TFTP, but
does not assume knowledge of the module's internal code.

## 1. What the module controls

UCM Provisioner treats a phone as a combination of:

- a logical extension and person identity;
- a physical MAC/model and optional additional accounts;
- site settings plus an applicable template stack;
- generated phone/companion files;
- a FreePBX CHAN_SIP or PJSIP endpoint route;
- Asterisk Cisco and BLF state; and
- model-compatible firmware, timezone, locale, wallpaper, audio and directory data.

The interface is designed to show the source of each important choice. “Default,”
“group,” “template,” “phone override” and “directory override” are different
sources and do not silently become one another.

## 2. Before installation

### Required platform

- FreePBX 17, Framework/Core 17.0.1 or later and PHP 8.2 or later.
- A working FreePBX Asterisk runtime. Non-Cisco PJSIP phones do not require the
  USECALLMANAGER patch. Cisco enterprise integration requires the exact matching
  Asterisk 20/USECALLMANAGER build, working `chan_sip` and the patch-provided Cisco
  application; the redesigned Asterisk 22 patch is outside this release's contract.
- PHP LDAP for Active Directory, and PHP ZIP for configuration archives.
- A TFTP server reachable by the phones. It may run on the FreePBX host through
  the module-managed `tftpd-hpa` profile or on a separate Docker host. Both modes
  use the restricted `ucmp-tftp` SFTP account for module writes.
- Reliable time and DNS on PBX, directory and TFTP hosts.

### Back up

Before installing or upgrading, back up:

- the FreePBX/MariaDB database;
- `/etc/asterisk`;
- `/var/www/html/admin/modules/ucmprovisioner` if upgrading;
- `/var/lib/asterisk/ucmprovisioner`;
- `/var/lib/ucmp-patch-manager`; and
- every site's TFTP bind directory and PBX-hosted media store.

Keep console or hypervisor access and a known-good Asterisk rollback available when
changing the patched runtime.

## 3. Install, update and integrity signing

For a new PBX, follow the dedicated [Clean FreePBX 17 installation guide](FRESH-INSTALL.md).
It covers the official installer boundary, separate non-Cisco and patched Cisco
paths, package verification and the first canary. See also the
[feature reference](FEATURES.md) and [worked examples](EXAMPLES.md).

Extract the archive so the result is
`/var/www/html/admin/modules/ucmprovisioner`, then run:

```bash
fwconsole ma install ucmprovisioner
fwconsole ma list | grep ucmprovisioner
```

Enable the module if Module Admin reports it disabled. Use FreePBX's normal Apply
Config workflow for pending configuration when needed. Do not add global
permission resets or service restarts merely because files were installed; inspect
and resolve any specific installer warning first.

The install hook creates/repairs module-owned tables, private directories, public
read-only phone endpoints, one-minute schedule entries and constrained helper
programs. An upgrade reconciles older schema in place. Do not drop module tables to
perform an update.

After files reach their final location, open the module's **Module integrity** card
and locally sign the module. Each PBX creates and retains its own signing key and
FreePBX trust anchor. A signature copied from another PBX is deliberately discarded.

Install the same module version at every federated site before enabling automatic
replication.

## 4. Recommended first-run order

1. Confirm the local site under **Sites**.
2. Set its PBX/voice/TFTP addresses and enroll the TFTP host.
3. Add required firmware, Cisco timezone/locale and media objects.
4. Set site endpoint, clock, service, default template and asset policy in
   **Settings**.
5. Create templates and groups.
6. Configure directory sources and phonebooks.
7. Discover/add phones and bind logical extensions.
8. Preview and apply one representative build per device family.
9. Add remote sites, validate API/SSH/SFTP and preview the BLF mesh.
10. Configure replication only after manual source-to-destination tests succeed.

## 5. Site context and offline state

Each site selector changes the owner of the records on the page. When editing a
remote site, Save returns to that same site's list. Opening a different primary tab
without existing context may start at the local site.

Remote reads are live when the site badge says so. If both API and SSH recovery fail,
the page is read-only and may show a dated last-known-good snapshot. A cached phone
status is labeled as cached; it is never represented as a live registration. If no
snapshot exists, empty sections mean unknown—not “the site has no data.”

Mutations cannot be applied to an offline snapshot.

Identity delivery also retains an accepted owner projection for normal generation
when no local authoritative person is available. This fallback is separate from a
live registration: it cannot supersede an explicit local primary, linked or manual
identity. Missing/unresolved identity is reported, not silently reassigned.

An identity whose destination FreePBX account is missing, or whose saved ownership
or revision conflicts, is skipped individually. Other eligible identities continue
to update. The Directory editor lists each skipped extension, site and reason;
its previous identity is retained and it is not reported as applied. Each later
directory sync checks eligibility again and clears the warning after successful
delivery. No account is created and no ownership conflict is overwritten. Reviewed
identity refreshes list the exclusions before applying the eligible targets. Update
controller and receivers together for this behavior. Site-wide transport, storage
and transaction failures still require a successful retry or fresh review.

## 6. Dashboard

The dashboard summarizes site connectivity, phone/template counts, Asterisk patch
preflight, module integrity, scheduled work and recent operations. Use it to detect
cross-cutting faults before opening a device editor.

The patch preflight separates:

- the Asterisk version reported by the running process;
- installed/source-managed build information;
- whether the expected Cisco runtime behavior is present; and
- whether the required SIP driver responds.

A failed preflight blocks Cisco Asterisk glue but should not leave a web request
waiting indefinitely.

## 7. Phones

### Inventory and discovery

The main list shows logical extension, identity, MAC/model, site, applied template
source, driver/transport/port, registration/reachability and rebuild state. **Phone IP**
shows addresses from the existing live PBX status refresh. Multiple contacts remain
visible; a saved address is labeled when no live contact is available or a check fails.
These are addresses as seen by the PBX, which can differ from the handset LAN IP behind NAT.

Identity timestamps and relationship evidence are under **Advanced details**;
publication/activation/handset evidence is under **Deployment details**. **More
actions** keeps configuration explanation, identity editing and exact rebuild
review available without expanding every row. **Advanced identity actions**
reapplies saved accepted names/labels and preserves overrides; it does not contact
AD. Use **Directory & AD → Sync now** to fetch new directory data first.

- **Discover/reload inventory** reads FreePBX SIP and PJSIP endpoint state.
- **Phone MAC address** accepts exactly 12 hexadecimal characters, in either
  letter case, and normalizes them to uppercase. Standard separators in pasted
  addresses are removed without truncation. Invalid nonempty values stop Save
  with an inline message; the entered draft remains in the editor. Existing
  extension-only records may keep an empty MAC until hardware is assigned.
- **Network scan** accepts an entered IPv4 range from /0 to /32 without requiring
  it in Site Settings. Saved discovery ranges are optional suggestions. Active
  scanning runs from the selected PBX with a 90-second limit; neighbor discovery
  reads only addresses inside the entered range. Supply the selected PBX's admin
  username/password for privileged nmap; enable **Use sudo** for a non-root admin.
  Both local and remotely initiated scans authenticate on the selected PBX using
  its local Linux account and existing sudo rights. Remote requests use only the
  encrypted federation API; scanning never initiates SSH or asks for SSH trust.
  An unavailable API stops the request without fallback. All participating sites
  need rc29 or newer for password-authenticated API scans. The password stays
  in the current page/request, can be cleared, and is cleared when changing sites.
  No credential or sudo rule is saved. Without a password, discovery runs
  unprivileged; very large ranges may reach the time limit before completion.
- **Recover MAC/config from TFTP** recognizes valid vendor filenames and attempts to
  restore the physical binding.
- **Unprovisioned** means the FreePBX extension exists but has no managed physical
  phone. It is not an error.

Deleting a phone removes the physical module record and deterministic per-MAC phone
configuration files. It does not delete the FreePBX extension or logical AD identity;
the extension returns to the unprovisioned list. A suppression marker prevents the
same physical MAC from immediately reappearing through automatic discovery. An
explicit add/recover action clears that intent.

### Add/edit and reassignment

The editor keeps normal controls in one panel and isolates less common raw behavior
under **Advanced**. Select a real model before saving; an unclassified extension
cannot be built safely.

The primary identity binding controls extension, person and default labels. A
reassignment can move or swap a physical phone while keeping logical extension
identity coherent. Additional accounts are independent rows with their own account
slot, appearance key and label.

Driver, transport and port display both the effective value and its source:

- Cisco XML site default or explicit phone override;
- Cisco CNF site default (UDP only) or supported override;
- non-Cisco site default or explicit CHAN_SIP/PJSIP phone override.

When changing a live route, the module publishes the new file and sends the reload
through the previous live registration before FreePBX activates the new endpoint.

DECT controls appear only for compatible base/system models. A DECT profile is the
base/system capability profile—not an extension by itself. The account/handset table
shows each assigned extension. Radio and handset components that do not own a
standalone configuration cannot be built as desk phones.

### Preview and apply

**Preview** is read-only. It resolves the same settings, full-fleet Asterisk state
and managed asset inventory that Apply will use and returns a plan hash.

**Apply** requires that exact hash. If any input changed after preview, the module
returns a new plan rather than applying unseen content. Review and apply the new
plan; repeatedly submitting an old preview cannot bypass this protection.

A single-phone rebuild still resolves all site phones for shared Asterisk glue. It
does not contact every remote PBX. BLF federation has its own explicit/scheduled
operation.

### Explain, draft impact and guided replacement

The phone list adds **Explain**, **Identity** and **Reviewed rebuild** actions.
Explain presents resolved ownership/precedence and separate deployment evidence;
it does not itself save or deploy. The template, group and Settings editor's
**Compare unsaved draft impact** compares a draft with current resolution without
saving it. Candidate reviews currently use structured JSON; they are not a claim
of a graphical field-by-field diff everywhere.

The inventory separates resolved person/binding/adoption, accepted-source time and
observed FreePBX name. **Read retained evidence** shows the effective phone/account
labels alongside desired hash, published hash, PBX and notification receipts with
timestamps. Registration has a separate browser result-received timestamp, not a
server observation timestamp. Unknown remains
unknown: none of these observations proves handset application.

Impact pages cover the selected object's owner site only. Counts and shared-file
references describe the displayed page; use **Compare next owner-site page** for
the remaining phones. Inventory/draft changes invalidate the cursor, but later
pages reread current policy and are not one point-in-time fleet snapshot. Required
stages describe draft deltas, not actual deployment drift. Other sites' synchronized
copies/consumers need separate owner reviews; no foreign policy is assumed changed.
Explain's native tokens are literal candidate output occurrences, not causal proof
that one policy field exclusively controls a particular vendor tag.

For an existing physical phone, **Review replacement / reassignment** captures the
selected phone, existing collision, logical revisions and proposed person/phone
outcomes. Review those fixed move/swap outcomes before apply. Saving a physical
assignment does not by itself prove new files were published or a handset changed;
use the separate reviewed rebuild and device checks. A changed phone or relationship
invalidates the plan; a missing response requires evidence review, not blind replay.

Replacement receipts retain secret-free before and observed-after phone/model IDs
and extension mappings, plus a post-change checklist. **Recent owner changes** and
**Recent directory changes** read bounded retained history; an active review offers
**Read retained change status**. `applying` means incomplete or uncertain, not safe
to replay. A final outcome is not confirmed until its receipt update succeeds.

For reversal, inspect current assignments, then follow the recorded `reverse_review`
steps through fresh **Review replacement / reassignment** operations. A recorded
blank extension means explicitly leave that assigned phone unassigned. Skip a
mapping already restored by a preceding swap and review any new collision/person
revision. This restores assignment mappings only, not exact configuration, runtime
or secrets. Nothing automatically restores, resets, rotates credentials or reboots.
Follow the checklist: verify mappings and person/model policy, separately review
rebuilds of still-assigned phones, inspect displaced/unassigned phones and confirm
published files, PBX activation and actual handset behavior independently.

### Overrides and rebuild exclusion

A managed phone configuration can be deliberately overridden from the TFTP browser
for troubleshooting. The Phones list marks it. **Rebuild all** preserves an allowed
manual override and reports conflicts; **Replace config** is the explicit one-phone
action that returns it to generated ownership.

Phones can also be excluded from Rebuild all. A targeted explicit rebuild remains
available. Scheduled rebuilding uses the same exclusions and override policy.

### Scheduled rebuild and reboot

The Phones schedule and the corresponding Settings section edit the same site-owned
record. Interval, daily and weekly timing is supported. The one-minute worker waits
until due, defers while FreePBX is writing configuration, previews/applies one
verified generation, and optionally sends a supported warm reboot after commit.
Grandstream models without a supported SIP reboot command are rebuilt without a
forced remote reboot.

## 8. Templates

### Applicability

A template may target vendor, provisioning family, firmware/configuration profile,
device role, series or one exact model. Firmware profile is often the correct Cisco
scope: for example SIP41 covers 7941/7961 while SIP8845_65 covers the 8845/8865 video
branch.

Template names are unique within a site. Revision numbers are site-specific and
increase when that site's object changes.

### Inheritance behavior

An unset/inherit field contributes no replacement. Explicit values replace earlier
layers. Site settings always provide general operational defaults, but a site
default template is selected only when no compatible group layer applies.

Phonebook selection has three distinct states:

- **Inherit** — preserve a lower layer's choice;
- **Site default** — deliberately reset to this site's published default; and
- **None** — emit no managed directory URL for the phone.

The template's **Directory label** is the display pattern used when rendering this
template's directory URLs; it does not rename the underlying AD object. Leave it
inherited to use phonebook/site policy.

### Identity and label fields

Phone, line/account, BLF and directory labels are separate surfaces. Patterns can
use documented identity tokens from AD/logical extension data. The resulting value
is bounded for the selected phone surface. A final audited per-extension override
wins over the general/template pattern for that field.

Cisco CNF firmware adds its own `SIP` decoration to the device label. The preview
shows that decoration but the module does not pretend it is part of caller identity.

Use the extension selector in preview to see actual AD data and all override layers,
not placeholder text.

### Softkeys and call behavior

The editor distinguishes:

- serialized state keys that are written to the template-specific softkey file;
- feature-policy entries used by compatible newer branches; and
- visual-only firmware/device keys shown so the preview can match a real handset.

Visual-only keys can be manually arranged/overridden in the editor without being
emitted as unsupported XML. Settings such as 78xx Recents may cause firmware to add
a key and enable the D-pad route independently of the explicit state list. Video
phones may similarly show camera-related controls.

Every state and key has hover help. Use Inherit to omit a state. An explicit state
must remain operational (for example On Hold requires Resume). Earlier schema-less
records are retained for upgrade compatibility; resaving them in the current schema
makes explicit choices authoritative. See `cisco-enterprise-softkeys.md`.

### Display, dialing and assets

Display/backlight schedules allow independent enablement and days; no selected days
means the schedule is disabled rather than forcing an arbitrary day. Dialing rules
generate a stack-addressed companion file. A catch-all such as `*` with a timeout
controls post-dial delay; exact/short patterns can dial sooner and therefore must be
reviewed before broad rules.

Firmware, wallpaper, ringtone, phonebook, Cisco timezone and locale selectors show
only compatible retained objects. Missing replicated dependencies fall back to the
destination site's default and remain visibly flagged.

### Template push

The main Templates tab can push selected templates to selected sites. The payload
keeps inherit semantics. Destination revisions remain local: if the destination was
edited after a prior push, a new source revision requires conflict confirmation.
Missing assets do not become invented filenames; the destination inherits its own
default and the dependency warning remains attached to the template.

## 9. Groups

Groups organize policy and membership; they are not Active Directory Group Policy.
They may receive members from AD mapping or direct selection.

Group synchronization copies the hierarchy, template layers and shared BLF policy.
Extension membership and member-specific exceptions stay local to each destination.
A new synchronized group starts empty; neither groups nor templates require source
extensions to exist there. Local membership/exception edits do not pause shared
policy synchronization. Shared-policy edits still require the existing sync-edit
confirmation. Earlier missing-extension warnings clear through normal dependency
recovery without reseeding; actual missing templates still wait for their arrival.

A group can have one parent on the same site. Resolution walks root to leaf. Inside
each group, rows are displayed and applied by applicability:

1. broad/generic policy;
2. firmware/config profile; and
3. exact model.

Operator order is preserved inside the same applicability bucket. For a 7841,
`Base_78xx` followed by `HR_78xx` fills/overrides only the values each layer
explicitly supplies. An exact 7861 layer runs afterward only for a 7861.

Member exceptions select one or more group extensions and one final compatible
template. Explicit fields replace the resolved group stack. A non-empty key/BLF
list replaces the earlier list, which supports executive-specific keys without an
extra nested group.

After Save, assigned extensions appear first. **Clear visible** affects the current
filtered selection and is intentionally distinct from clearing every member.

## 10. Sites

### PBX channel

A remote site has identity/voice settings plus two management paths:

- encrypted authenticated API for normal operations;
- restricted `ucmp-pbx` forced-command SSH for enrollment and recovery.

Enter one main PBX address and its SSH port, save a new site once, then use **Install / update**
with a temporary root/sudo-capable administrator credential. Confirm the presented
host fingerprint through an independent channel. Preparation reads the remote
filesystem and FreePBX registry before installing a missing/older module or
repairing the current one. The OS, FreePBX 17 and PHP 8.2 must already be present;
an older Provisioner release does not require its old agent/API to work first.
An unreachable or unreadable target is unknown, never assumed empty. A newer
filesystem or registry version blocks downgrade.

Preparation uses the normal module installer, local signing and credential
recovery gates, then verifies the restricted SSH agent and API management RPC.
A healthy same-version module is not reinstalled. The checked **Sign and verify**
option is honored even for a current version, including its PBX-local OpenPGP
public-key trust and root-owned signature anchor. Temporary administrator passwords are not
retained. Existing module data, site identity and working enrollment keys survive.
API endpoint defaults use the management host, not a phone/voice subnet; review
web scheme, port and TLS validation before installation. The main PBX address is
also the default voice/API host; optional overrides retain existing distinct
phone networks and HTTPS names. No existing site addresses are migrated on upgrade.

**Check version** displays controller and remote versions, comparison and integrity.
Fresh API or administrator checks run integrity as the service account; the
restricted SSH agent reports integrity unavailable without reading the GPG keyring.
For an existing site, fingerprint/version reads use the current validated form
without saving. Install/enrollment actions save only their connection fields when
the action starts, even if remote setup later fails. Other site edits require Save.
Changing SSH host/port invalidates the old pin and requires new confirmation;
edits made after confirmation require restarting the action.

Module, agent and API results are separate. An API failure can leave usable SSH
recovery and Docker enrollment access; fix the reported endpoint problem and retry
Install / update, not a blind reinstall. Saved enrollment badges are
last-verified state, not continuous live monitoring. Use a fresh version/connection
check when diagnosing an existing site. Recovery-required or uncertain installation
results must be reconciled against the retained transaction before retrying.

Federation grants shared administrative trust. Authenticated encryption protects
the channel but does not separately authenticate each claimed origin site's
identity. A remote packet is rejected if it claims the receiving PBX's own origin
authority. Compatible peers still require the same administrative trust review.
If a dispatched write loses its response, retain its operation ID and reconcile
the destination receipt; the fallback channel must not execute it again.

### TFTP channel

Each site has one active TFTP architecture:

- **PBX-hosted** installs or adopts the `tftpd-hpa` server and `tftp-hpa` client, serves
  `/srv/tftp/tftpboot`, and exposes that tree to the key-only `ucmp-tftp`
  account as `/tftpboot` inside a root-owned `/srv/tftp` jail.
- **Remote Docker-hosted** enrolls an existing compatible running TFTP container
  using `/opt/tftp-server/tftpboot` and the same `/tftpboot` jailed SFTP view.
  Supply the existing container name, or leave it empty only when preflight finds
  one unambiguous compatible container. Docker and its container must already
  exist; enrollment does not install, pull or create either. Docker enrollment
  does not install or require a host TFTP client and does not run a TFTP packet test.

Server/client installation and local TFTP retrieval verification run only through
the explicit **Bootstrap / repair PBX TFTP** action using temporary PBX
administrator credentials. Its default package policy installs both when needed;
**Report only; do not install** preserves an explicit no-package-install choice.
Selecting a backend or supplying Docker credentials does not invoke PBX-local setup.

Docker preflight must pass before SSH policy or managed-account changes. Both the
controller and owning PBX must authenticate with their own keys and verify the
candidate storage with a jailed write/read/delete round trip before enrollment is
recorded. Both backends merge existing keys, use a root-owned 0755 authorized-key
directory and 0644 public-key file, and verify account readability before applying
SSH policy. Existing SSH keys and TFTP data
are retained. A partial owner-adoption failure leaves the controller unready and
retains SSH recovery evidence; it is not permission to replay an uncertain RPC.
Enrollment does not switch the active TFTP architecture or establish phone-network
reachability. Verify the actual device path separately before a fleet rebuild.

The editor prefills the effective phone-facing address: Docker host for Docker
mode, PBX phone/SIP address (then management host) for PBX-hosted mode. An automatic
value follows host edits; a deliberate override is preserved. Clearing an override
restores inheritance. Enrollment uses the displayed value and sends it to the
owning PBX. The phone-facing address can differ from either SSH management address. The
module never silently opens FreePBX Firewall rules; **Inspect PBX** reports the
daemon, UDP/69 listener, root and declared phone networks.

An SFTP post-check failure attempts to restore the retained SSH configuration and
keys; PBX-hosted enrollment also restores the previous daemon configuration and
active/enabled state. A concurrent key or policy edit stops compensation for
administrator review. The reported recovery reference identifies retained evidence,
not proof of complete rollback: new packages/accounts and directory permissions
remain. Local UDP or SFTP success never proves retrieval from a phone VLAN.

Changing an existing site first creates a staged proposal. Bootstrap or repair the
target, then use **Preview copy and cutover**. Apply rechecks the preview, copies
the complete active tree (including private transaction state), verifies each
target file with SHA-256, updates the remote PBX and controller records, and only
then makes the target active. The source remains intact. A failed copy or stale
preview leaves it active and can be retried safely.

Use **Verify permissions** before generation. **Repair published permissions** fixes
module-owned paths on the active provider only; it should not recursively rewrite
unrelated host storage. Every storage consumer uses the active selection, so a
phone build cannot write Docker while Firmware or Media writes to the PBX.

Writes share a destination-side `.ucmprovisioner/write-lock`, including source and
destination protection for cross-site copies. Lock age is not proof that a writer
has stopped. After interruption, inspect the lock owner and retained transaction
receipts, stop/verify every possible writer, then have an administrator remove only
the exact verified abandoned lock. There is no automatic stale-lock takeover.

### BLF federation

Select the exact existing PJSIP trunk endpoint on both ends. Preview shows
inbound-publication, asterisk-publication and outbound-publish objects plus hints.
Apply uses prepare/commit/rollback across participants. Extension identifiers must
be unique across the federation because published Asterisk device state is keyed by
extension/device identifier.

The BLF schedule is independently configurable from Phones and Settings. It defers
while FreePBX is writing Asterisk configuration.

### Broad synchronization

Sites provides a one-stop view of configured replication jobs. Tab-specific pages
remain the authoritative place to choose objects because firmware, media,
phonebooks and settings have different dependency and conflict semantics.

## 11. Directory & AD

### Independent sources

Add one source per AD domain/forest policy. Each source owns server/DC failover,
base DN, bind reference, TLS behavior, filters, field mapping and schedule. A source
marked **Legacy/compatibility** was projected from the old single-domain settings.
Installation now automatically converts its credential to a private host file,
preserving source identity, accepted history and the saved AD transport. It requires
a complete existing reader credential and a verified recovery backup; it does not
attempt an AD bind. New/edited source connections still require LDAPS or StartTLS.
Database upgrades and credential-storage upgrades happen in the normal install;
there is no second database or separate migration action to select.

AD is the source of truth for mapped person identity and extension assignment. A
complete zero-result response is authoritative. Bind, paging or connection failure
does not publish a partial deletion; the last accepted revision remains active.

### Site and logical-extension mapping

Ordered rules map directory attributes/OU/domain context to a site. Logical
extensions persist identity, group membership and final-label overrides separately
from a physical phone.

A person's normal site/number move should follow AD automatically. Manual conflict
resolution is reserved for ambiguous data: two AD objects claiming one logical
extension, one object claiming incompatible simultaneous identities, or a manual
record that would be overwritten. The conflict panel explains the blocking rows.

The Identity editor distinguishes three modes:

- **AD primary**: the accepted AD source owns the person's normal number mapping.
- **Additional extension linked to a person**: a stable source/person link supplies
  identity for a separately assigned number; it does not steal the primary mapping.
- **Manual / shared identity**: an explicit full name and short label belong to
  this managed slot, without inventing an AD person.

A missing linked person preserves the previous destination identity and reports an
unresolved relationship. During install and source acceptance, established AD-primary
relationships resume managed operation only when the accepted stable person, site,
number and revisions agree. Existing manual identities, uncertain ownership and
overrides lacking matching person provenance remain unchanged for review; proven
person-owned overrides retain their exact values. No phone/PBX publication occurs
as part of install classification. Full caller/person name and short phone/line/BLF labels are
separate values; shortening a display label must not shorten FreePBX caller identity.

### Identity overrides

The audited override editor selects a logical extension and one field/final label.
An override is explicit and wins over AD formatting until reverted. Reassigning a
physical phone does not erase it.

Person-owned overrides retain their source/person association. Reusing an extension
for a different person does not apply the previous person's overrides to the new
owner. Binding, adoption and override changes are revisioned and require a fresh
review if the relationship changes concurrently.

### Force managed identity refresh

The Phones toolbar offers reviews for selected primary relationships, one explicit
site, one accepted source or **ALL managed identities**. This uses the last accepted
source; it is not a new LDAP scan and does not bypass adoption or manual ownership.
Review fixes the relationships and compatible owner/derived BLF destination sites.
Execution stages bounded cross-site data, rechecks the exact plan and cannot add a
newly enabled site. Phonebook publication likewise binds contacts, definitions,
layout, freshness and destination identities to the reviewed snapshot.

Read the separate desired-state, file-publication and PBX-activation outcomes.
Unknown/offline devices and unconfirmed physical displays remain unknown even when
the server-side transaction succeeds. A failed or stale destination acknowledgment
must not be reported as a fully applied relationship.

### Phonebook designer and search

The phonebook editor controls which accepted contacts are published, hierarchy,
sorting, page size, display text and search behavior. Template label settings only
affect how a selected phone/template renders directory labels; they do not replace
the phonebook's membership/filter policy.

Before a membership-affecting save, the review compares the actual compiled old
and new audience, additions/removals, affected sites and exposed fields. Exceptional
mass audience changes require explicit approval of the current revision. Refresh
the preview after changing a rule or exclusion; this gate does not turn routine
accepted-source synchronization into a manual approval on every run.

Search can match name/login, extension, mobile number and **EMP No.**. If a number
fragment matches multiple users, all matching results are returned subject to page
limits. A mobile match displays the mobile number; selecting the normal result calls
the extension. Compatible Cisco XML responses can also expose EditDial for the
displayed mobile number. EMP No. is searchable metadata, not a dial target.

Cisco CNF has a smaller CMXML subset and uses a compatible input/menu response path.
Test number and alphabet input on one 7940/7960 before broad deployment.

### Employee web phonebook

The browser title is **Company Directory**. Under **Directory appearance**,
upload a PNG/JPEG favicon (up to 1 MiB and 2048 pixels per side); the server re-encodes
it as a 64×64 PNG. Other settings saves retain the icon; use the removal checkbox to
clear it. Site display labels use one `original name = display label` per line,
for example `MAIN-PBX = Main office`. They affect directory results, filters, print and
export without renaming PBX sites. These presentation settings are included in
Web-directory settings synchronization and honor its ownership locks.

Every selected visible field appears in the normal Table and People views. Tables
scroll horizontally when all columns cannot fit on a small display. The theme button
switches directly to the opposite light/dark mode and remembers the selection in
the browser; its icon and accessible label describe the next action.

In **Directory & AD → Web phonebook**, select the locally published books, allowed
client networks and fields, then explicitly enable web access. New installations
are disabled and allow no networks. The separate address is
`/ucmprovisioner-phonebook.php` on this PBX's internal HTTPS host. Employees do not
need a FreePBX account, and the page does not expose administrative controls.

The phonebook and visible-field pickers are searchable multi-select dropdowns;
selected entries stay at the top. Name and extension remain required. Proxy and
other optional controls are under **Advanced settings**. Saving uses the same
policy validation and access confirmation as before.

Use IPv4/IPv6 CIDRs for the actual clients, not a guessed site-wide network. All
readers on those networks can search and print every allowed contact; Excel export
is separately enabled by policy. Direct mode checks the actual connecting IP
against the configured networks and ignores forwarding headers. A private reverse
proxy works in this mode without another allowlist; restrict public access at the
proxy because all readers it forwards share its access. Existing HTTPS termination
can forward plain HTTP to the PBX.

Only when original-client-IP filtering is desired, use the optional trusted-proxy
mode, enter its exact peer IPs, and retain separate private/VPN client CIDRs. The proxy
must overwrite the selected `X-Real-IP` or single-IP `X-Forwarded-For` and remove
other forwarding headers. Missing, chained, conflicting or untrusted forwarding
is rejected in that optional mode. Shared NAT needs upstream client restrictions.
Page, assets, data and download routes all recheck the same policy. Inspect the
safe peer/client/reason diagnostic rather than broadly granting a proxy network.
The preferred HTTPS address and help link are policy metadata, not DNS, certificate,
reverse-proxy or firewall configuration.

Name and extension are required. Mobile, employee number and short label are
opt-in. Alternate name uses only the explicitly selected projected canonical name
or short label; it is not an arbitrary raw AD attribute. Unchecked fields are absent
from data responses, search, contact details,
printing and exports. Phonebook membership, exclusions and manual contacts come
from the accepted published snapshot; phone hierarchy/page-size settings are not
copied into the web layout. Distinct identities sharing numbers remain distinct.

Readers can search English/Persian text and Latin/Persian/Arabic digits, filter by
site/department, sort, switch Table/People, inspect contact details and select
across pages. Exact extension and name-prefix matches rank ahead of weaker matches;
matched-field feedback and copy-name/copy-number controls reduce ambiguity. Shared
numbers keep site identity visible. Output scope is explicit: current filtered results, selection or the
whole current book. Changing books clears selection. Excel is a true `.xlsx`
workbook with a frozen heading row, filters and text-valued numbers to retain
leading zeroes. Print preview offers department/site grouping and an A4 landscape
layout; **Print / Save PDF** opens the browser's native print workflow.
Large previews are bounded and labeled; actual output contains the full chosen
scope. Browser Print without opening the output panel prints current results.

The page distinguishes accepted-source freshness from cache-generation time and
shows policy context, not live employee/phone presence. Refresh clears
selection and reloads accepted data. A changed snapshot or policy makes a pending
Excel export fail with a refresh instruction. A failed directory sync continues
to use the prior accepted snapshot; no live AD query is performed for browsing.
Currently each book supports up to 10,000 contacts and the cache reader is bounded.

Access policy is PBX-local in `/var/lib/asterisk/ucmprovisioner/web-phonebook.json`,
saved atomically with restrictive permissions; include it in protected system
backups. Phonebook replication and configuration-package import do not copy or
enable it. To withdraw access, disable Web phonebook and save. Already loaded,
printed or downloaded information cannot be withdrawn from readers.

### Replicated phonebooks

Phonebook replication sends the generated cache and layout/search policy, not AD
credentials. The destination clearly identifies the source PBX. **Copy policy to
local AD** creates a separate phonebook that uses the destination's own directory
entries; it does not duplicate source contacts or secrets.

## 12. Patch Manager

Patch Manager is a GUI for a controlled Asterisk 20/USECALLMANAGER workflow, not a
general distribution package manager.

It separately reports:

- **Running** — the current Asterisk process;
- **Installed** — the executable/files detected on disk;
- **Active managed** — the managed bundle selected by the module;
- **Pinned** — whether FreePBX's Asterisk version-switch package is held; and
- **History** — retained activations that can be selected for rollback.

Select one of the recent compatible catalog pairs and review the exact commands.
The workflow downloads the two matching sources, verifies declared hashes, applies
the patch with `patch --strip=1`, runs the preset `configure`, optionally enables
the supported strong SRTP suites, compiles and stages a bundle. An administrator may
instead upload a plausible patched Asterisk executable/bundle; lightweight checks
confirm executable/version structure but cannot prove every runtime behavior.

Activation captures the current managed runtime first, atomically switches the
selected bundle, starts Asterisk and runs health checks. Failure attempts automatic
restoration. Core identity edits and runtime activation keep compensation evidence;
an unverified restoration remains `recovery_required`, not a successful rollback.
Keep console access for any runtime change.

Pinning means holding FreePBX's `asterisk-version-switch` package so a normal module
update does not replace the selected source-built Asterisk with a vanilla version.
It does not force the newest Asterisk release.

Capture verifies the executable, modules and real Asterisk libraries together.
An unchanged installation reuses its verified capture and reports that explicitly;
changed files create a new capture. Normal library aliases do not imply runtime
drift. Deleting a protected duplicate verifies both complete archives against the
active runtime and retains the existing pin on the active copy. A different pinned
payload and the active copy remain protected; this operation does not restart
Asterisk or release the package hold.

Replicating a compiled bundle transfers it to another site as staged/incoming. It
never activates or pins it remotely without a separate administrator action.

## 13. Media

### Legacy Cisco builds and phone reload results

Legacy Cisco logos and directories can differ per phone; their values are emitted
in each handset's CNF. Shared SIPDefault.cnf settings, including the endpoint port
and time policy, must still match the canonical site policy.

A build can publish and verify files while its phone reload request fails. The
result shows file publication, PBX activation and each reload request separately.
An endpoint with no routable address must register before a reload can reach it.
After resolving that condition, retry the phone reload; already published files
do not need rebuilding. A dispatched NOTIFY does not prove the handset fetched
or applied its configuration.

### Wallpaper catalogs

One high-resolution master image creates a named logical catalog for the selected
scope: all supported models, vendor, series/profile or exact model. The editor lists
only output profiles in scope.

For each profile, the administrator can:

- preview at a usable editor zoom independent of actual output pixels;
- move/scale/crop the source within a bounded frame;
- save a profile-specific transform;
- upload or remove a separate source override;
- disable output without deleting the retained profile settings; and
- preview 1-bit/4-level grayscale or invert output for compatible displays.

Profile edits remain children of the catalog. Rename changes the logical catalog
name and all references; generated phone-facing filenames remain implementation
details. Generated variants and retained source are owned together; an apparent
unused filename is not permission to remove part of that set. Referenced or
incompletely inventoried objects must be resolved through retirement review.

### Non-standard/legacy media

This section is for devices that require a distinct format/delivery pipeline, such
as Cisco 7940/7960 palette BMP logos. Upload a source, name the catalog, and open the
crop/scale/conversion editor when the source is not already compatible. “Protocol
filename override” appears only when firmware requires an exact phone-facing name.

Standard Yealink/Grandstream color wallpapers belong in Wallpaper catalogs, not in
the legacy section merely because of their vendor.

### Audio, replication and index

Ringtone/audio libraries support browser playback where the codec is playable.
Phone-specific format validation still determines whether a file can be selected.

Site replication selects catalogs/audio libraries, destinations and changed-only or
overwrite behavior. Scheduled jobs support scoped targets and durable progress that
survives page navigation/reload.

Manual results, saved job results and Replication activity show each destination's
outcome separately. A partial run retains successful sites and the exact failures;
an unresolved outcome requires receipt review before retry. Later manual Media
scopes continue only for destinations that completed the preceding scope. Catalog
inventory errors identify the affected section; successful sections remain usable.
Use Refresh catalogs or reopen the work area to retry a failed inventory read.

The Asset Index is diagnostic. **Verified** means bytes/manifests match; **Observed**
means recognized bytes are not owned by a catalog/bundle/generation; **Incomplete**
or **Stale** means declared roles/hashes no longer agree. Usage is computed from
site defaults, templates, phonebooks and generated ownership, not guessed from a
similar filename.

### Staging, retirement and recovery

Generic HTTP media copying pins source SHA-256/size across chunks and uses a private
transfer ID/receipt at the destination. Equal-byte retries are idempotent; unrelated
concurrent transfers cannot share a partial payload. Final publication checks the
complete content and unchanged destination, retaining prior bytes. Special catalog
transfers use their separate retained-catalog workflow; do not infer generic chunk
coverage for every catalog role from a generic media test.

The Asset Index retirement preview lists references, dependencies and protected
bytes. Compatible mutable references can be reassigned before another preview.
HTTP audio/special-file/catalog removal first displays its own exact dependency
preview and confirmation; a referenced or incomplete inventory blocks quarantine.
Retained generations, aliases and template-pinned assets stay protected. Quarantine
does not permanently delete bytes or immediately reclaim their disk space.

After a confirmed retirement, retain the recovery ID. **Restore retained asset** or
**Restore retained HTTP asset** uses its captured site/library and refuses a newer
conflicting revision. A changed site requires returning to the captured context;
an uncertain response requires checking receipts before retrying. Complete editable
HTTP catalogs are retained with their published file, not reduced to one image.

## 14. Firmware, Cisco timezone and locale

### Firmware

Upload/select the target vendor and narrow scope to a firmware/config profile or
exact model. Cisco load names are validated against known firmware profiles.
Yealink and Grandstream ROM/binary names are treated as operator-supplied content
within the selected compatibility scope when no reliable vendor filename grammar is
available.

A complete bundle includes every declared companion required by that model/load.
Do not mark an incomplete bundle as a default. Firmware replication transfers the
selected firmware object and its declared companions.

### Cisco timezone runtime files

Timezone payloads are managed like firmware revisions by phone runtime architecture,
not as one hard-coded COP object:

- Standard JVM phones use `tzupdater.jar`;
- IBM J9 phones use `j9-tzdata.jar`;
- Lodown/RTL/Gumbo phones use `TzDataCSV.csv`.

Upload extracted files through Firmware or the TFTP browser. The Firmware tab scans
recognized canonical names, inspects bytes/version evidence and retains revisions.
No COP parser is required. Assign a default revision per runtime in Settings; a
template may inherit it or select another compatible revision.

`tzupdater.ver` is optional version evidence, not a fourth phone runtime payload.
When absent, a stable revision can be derived from payload bytes. Legacy Cisco
phones may require the latest compatible custom `tzupdater.jar` even though newer
COPs stopped updating that architecture.

### Cisco XML configuration generation

The generator emits the base SIP registration timers, media ports, DTMF settings,
local SIP port and explicit unencrypted configuration state on every build. The
phone's local SIP port is 5060; its remote PBX port and transport still come from
the resolved endpoint profile. Templates, BLF, softkeys, timezone assets and
directory settings are applied to that base configuration.

Display refresh rate selects **Normal (0)** or **Flicker correction (1)**; it is
not an interval in seconds. Earlier saved interval values use Normal and produce
a preview warning. DND alert policy appears once at the device root. Primary
authentication secrets outside the supported model's constraints are automatically
replaced during Generate/Apply with a compatible 16-character password in both
FreePBX and the generated phone configuration. Preview changes neither. Compatible
passwords are not repeatedly rotated. Select every managed phone sharing an
affected account; otherwise the build stops before any change. An unchecked
restart box sends no handset command, including after password or transport
alignment. Reload the phone when ready to consume its changed credentials. Secondary
bulk-registered lines do not receive independent credentials.

XML parsing and fixture tests do not establish handset acceptance of every
combination of firmware and optional template settings. Installing an update does
not itself rebuild or restart phones.

### Individual settings and identity controls

Configuration sync in Sites and Settings provides **Individual Settings fields**.
Choose the subsections and then the fields to copy. Unchecked fields retain the
destination's values; a selected absent scalar setting removes its destination
override. URL fields default to unchecked because selecting one copies its exact
source address. Connection credentials, PBX identity and extension membership
remain site-owned. Field selection requires rc25 or newer on both source and
destination; an older peer is rejected before dependency copying or policy changes.
Companion-file settings remain pending if the destination lacks the referenced
file and recover through the existing dependency reconciler after it arrives.

The phone editor presents **Use site default / UDP / TCP / TLS** radio choices.
Cisco 7940/7960 firmware remains UDP-only. Unlinked extensions show **No directory
identity linked**; an accepted AD binding still requires explicit ownership review
when applicable. In **Per-extension identity overrides**, select multiple fields
and hover or focus each help marker for its meaning. The save uses one identity
revision. **Directory label** is the visible handset search name; a phone-only
label does not rename the directory entry. Use **Manual contacts (non-AD)** in the
phonebook designer to add entries, then save and publish that phonebook.

The override dropdown searches extension number, name and username. Handset text
search matches names, usernames and saved directory-name overrides; it does not
search department, OU, company or other metadata. Use the separate numeric field
for configured extension/mobile/employee-number searches.

Identity delivery in rc26 explicitly maps the controller's site entry to the
receiver's local site key. Update controller and receivers together; an older
receiver is rejected before applying a plan it cannot acknowledge. The next
successful identity delivery retains the mapping for ordinary identity reads.
This does not create an AD relationship for an unmapped extension, change group
membership or replace a locally owned manual/AD identity.

Routine republication of unchanged directory contents does not invalidate an
open web print/export selection. Visible contact or policy changes still require
refreshing that selection.

### Locale

Locale companion requirements are separated by Cisco load family (SIP11, SIP41,
SIP42, SIP45, SIP70, SIP75, 78xx, 88xx, video 88x5, 894x, 8961, 9951 and 9971 where
applicable). The UI offers only compatible bundles and publishes the configured
user-locale JAR plus tone XML path.

### Firmware-tab replication

With rc19 on the controller and receiver, large API uploads retain confirmed
16 MiB portions. A connection interruption retries the current portion twice;
after that, rerun the same selection to resume the retained receiving transaction.
The source manifest and overwrite choice must match. Completed destination files
are skipped. No transport switch occurs during an upload. An interrupted storage
worker or uncertain publication may still require receipt recovery; retained locks
are never removed automatically. Final whole-file hashes are verified before files
become live. Progress distinguishes sending, confirmed portions and publication.
At 16 MiB per 42 seconds, 100 MiB of actual transferred bytes takes about 4.4 minutes
before preparation/verification and any interrupted-portion retry. Multiple
destinations require their own byte transfers; a selected package is not the total
across all sites.

Replication scope covers firmware bundles, retained timezone revisions and Cisco
locale bundles. The destination receives required bytes and manifest identity before
defaults/templates are synchronized. An optional timezone activation flag changes
the destination default only when explicitly selected.

## 15. Phone Services

Phone Services are vendor menu entries delivered to a phone. They are separate from
the native directory URL assigned to a phonebook button.

The seeded **Company Directory** and **Phone Information** services are locked
because generated phone configurations and support workflows expect stable keys.
Their order numbers (for example 10 and 20) are menu sort positions, not limits.
Removing a service entry would not delete phonebook data, but it would remove that
service-menu route; the locked seed avoids accidental confusion.

Phone Information is generated at request time from the phone MAC/site context and
shows safe facts such as extension, model, site and effective template. Company
Directory opens the generated directory through the service menu.

Administrators can add model-scoped safe URLs, XML/menu/text/input/directory roots,
quick-dial actions and informational endpoints supported by each phone family.
Response types and URLs are validated; the service cannot execute arbitrary server
commands. Complex vendor XML applications are possible within the documented phone
browser subset—for example a read-only voicemail browser would require a dedicated
authenticated application and is not supplied by this module.

## 16. Fail2ban

The tab reads jail state and performs explicit unban operations through a fixed
root-side helper. It is intentionally not a complete Fail2ban configuration editor.
Jail names, addresses, request size, operation count and execution time are bounded.

Choose the PBX site, then click a jail row or **View clients / unban**. Use **Unban**
beside the exact client IP and confirm the displayed site, jail and address. A
client can be banned again if failures continue. **Access and recovery** contains
helper enrollment details; the filter tester remains read-only and advanced.

## 17. TFTP Browser

The breadcrumb shows `root > directory > child`. The browser supports multi-file
upload, multi-select delete, and single or multi-file/directory download (a bounded
archive for multiple selections).

Managed files display ownership and cannot be casually overwritten. A deliberate
phone-config override records manual ownership and is shown in Phones. Deleting a
module-owned logical catalog should be done from its manager after dependency
review. Raw deletion cannot bypass managed references, aliases or rollback retention
and is not normal lifecycle management.

Firmware, canonical Cisco timezone and recognized locale uploads are adopted by the
appropriate inventory after scan. Unknown files remain observed until explicitly
owned or removed.

## 18. Settings

Settings belong to the selected site. The main categories are independently
selectable for synchronization:

- **Endpoint and service defaults** — Cisco XML, Cisco CNF and non-Cisco driver,
  transport, port, proxy/voicemail and base service URLs.
- **BLF federation schedule** — the same schedule available from Phones/Dashboard.
- **Phone automation** — scheduled rebuild and optional reboot.
- **Clock and date** — NTP, display date/timezone, and Cisco runtime defaults.
- **Default template selectors** — one most-specific template for family, policy,
  firmware/config profile, role or exact model.
- **Site asset defaults** — firmware, timezone, wallpaper/hosted image and default
  phonebook.
- **VLAN/network policy** — normalized verified model-specific network behavior.
- **Archive/import** — hash-verified configuration export/import with tab exclusions.

The selector matrices edit in place. Switching sub-tabs does not discard pending
matrix choices; **Save site defaults** commits them together.

Credential-key storage conversion runs automatically during installation after a
restricted matching recovery archive is verified. It does not rotate credentials
or rewrite ciphertext. Retain the matching database/key archive for recovery.
Settings shows a warning only when installation has not completed that transition.

### Settings synchronization

Choose one or more categories, destinations and schedule. The UI states that a
selected category can replace the destination's same named settings. Site-specific
identity, addresses, credentials and service ownership are excluded.

When **soft-lock** is enabled, a remote edit requires confirmation and pauses only
that synchronized object/category. The source site reports who/when paused it. An
administrator must explicitly resume synchronization for that item.

Configuration job ownership includes the controlling PBX's stable site key.
Older numeric job keys cannot identify their original controller. They remain
read-only until an administrator uses **Sites > Configuration sync > Review
legacy ownership** for the selected destination. Check the listed objects and
confirm only when they belong to that job. Adoption preserves content, revisions
and local pauses; a changed preview or overlapping ownership is rejected. Run the
job afterward to refresh controller receipts. Upgrade both participants first.
Deleting or resuming a modern job never releases or resumes ambiguous legacy
records belonging to another controller.

Each imported database object/category commits with its ownership record. Schedule
categories hold their scheduler lock through that commit. Publication warnings
retain the saved settings, and a later unchanged run retries publication and
failed dependent-phone status updates. An unconfirmed commit requires receipt
reconciliation before any retry.

### Shared web-directory settings

In **Sites > Configuration sync**, choose the source PBX, select the receiving
sites (Select all excludes the source), and enable **Web-directory settings**.
Confirm the access-policy scope once when saving the job: it includes enabled
state, private/VPN networks, proxy mode, published book keys, visible fields,
employee-facing address, help/labels and export permission. Run now or use the
existing schedule. This does not publish contacts or configure DNS/TLS/firewalls.
Upgrade participating sites before the push; unsupported receivers fail preflight.
The normal install also gives the existing asterisk service group read access
to the settings file and its empty lock (0640), including after later saves.
This supports the constrained SSH path without sharing AD reader credentials;
those remain in separate owner-only files.

The normal PBX-wide `fwconsole chown` command is retained. This version registers
the module's permission requirements through FreePBX's native module hook, so a
later standard reset also restores its private stores and service-group access.
No module-only bypass, framework edit or post-reset repair script is required.
Shared media/cache writes and scheduler locks no longer depend on SGID remaining
set. Private transfer/recovery ownership and the permissions of rename-restored
media are retained; AD reader credentials remain owner-only.
For release acceptance on a disposable FreePBX 17 host, run the ordinary reset
after normal installation, then verify web-policy reads/saves through both service
paths, media upload/restore, scheduler locking, credential decryption as asterisk,
and continued denial of direct AD-secret reads as ucmp-pbx. Repeat the reset and
check that those operations still work. Windows source tests do not prove these
Linux owner/group and separate-account checks. Custom administrator permission
overrides or arbitrary external resets are outside the module's native contract.

Each receiver's Web phonebook form shows the effective settings, source site/key,
job and status. **Hard lock** disables local editing and enforces the source policy
on later pushes. Without hard lock, a confirmed local save pauses only this policy;
Resume on the controlling job enables its next push. Different jobs cannot steal
ownership. Change or delete the owning job to release it without removing the
effective settings; disabling its schedule does not unlock receivers.

Every selected book must already be published on the receiver under the same book
key. A missing book is shown as a dependency and leaves its policy unchanged. Use
the existing phonebook snapshot replication separately when source contacts are
required. Review remote access paths before enabling a shared policy: the same
network/proxy settings can admit a different audience on a different site.

## 19. Replication behavior

Managed firmware, locale, retained timezone and TFTP media copies use the owning
PBX's transfer API. The source manifest fixes paths, sizes and SHA-256 values;
payloads travel in bounded chunks. The receiving PBX stages the complete selection,
checks that the destination has not changed, then publishes and registers its own
inventory under one storage lock. Each PBX still uses its configured SFTP channel
to reach its own active TFTP backend.

Before a transfer starts, an API connectivity/service failure automatically selects
SFTP for staged bytes and the enrolled restricted SSH agent for destination
operations. This backup requires both channels and the same transfer protocol on
the peer. Authentication, certificate, authenticated operation and protocol errors
stop the job. A running transfer never changes transports. If a mutation response
is lost, only its existing operation receipt is queried; the mutation is not sent
again. An unknown outcome requires reconciliation.

The transfer protocol bounds an individual chunk to 256 KiB, one file to 512 MiB
and one selected transfer to 500 files / 2 GiB. From rc17, a receiving peer can
advertise API batches containing up to 64 chunks / 1 MiB, including chunks from
different small files. Older peers retain individual requests. Hosted media can
negotiate 512 KiB chunks when both peers support them; older peers use 96 KiB.
Install rc17 on receiving sites to obtain these request-count reductions.
The protocol uses disk-backed hashing and offset
writes rather than whole-firmware JSON messages. Hosted HTTP media and special
catalogs retain their existing content-specific limits. Inspect per-site results
for the selected transport, error and recovery ID. A failed destination does not
prevent other selected destinations from being attempted.

Authenticated storage-busy responses that prove no operation began are retried
for at most 15 seconds on the same transport. The owner must release its lock
naturally. An uncertain or partially completed operation is never replayed by
this wait, and an occupied lock is never declared abandoned or deleted.

Manual activity shows the current site, stage and acknowledged transfer bytes
while work runs. Its moving bar indicates activity, not an estimated percentage.
Completed sub-operations do not declare a whole destination successful while
later libraries or catalogs remain. Final per-site details retain committed TFTP
files separately from subsequent hosted-media or catalog failures. Incomplete
private staging is identified explicitly and has not published its files.

Successful receipts retain prior files. Interrupted staging is private; incomplete
or changed payloads cannot commit. A failed live publication attempts verified
rollback. Unverified rollback/finalization retains the exact storage lock and
receipt for administrator reconciliation; retries do not remove old locks.

Upgrade every participating PBX to a build supporting transfer protocol 1 before
using these copies. Successful API health checks on an older build do not establish
transfer compatibility. Validate a single destination before scheduling broad sync.

Every tab-specific replication job uses these principles:

- source and destination are explicit;
- logical identity is based on object key/name plus type, not an arbitrary row ID;
- **changed only** skips equal hashes/revisions;
- **overwrite** replaces the destination object with the same logical identity;
- dependencies travel before settings/templates that reference them;
- destination-only identity/secrets are preserved;
- progress is durable and can be viewed after page navigation or reload; and
- independent jobs may run concurrently without replacing each other's status.

For a clean destination, replicate in this practical order:

1. Media catalogs/audio.
2. Firmware, timezone and locale bundles.
3. Generated phonebook snapshots if the destination will consume source contacts.
4. Templates and groups.
5. Selected Settings categories/defaults.

The engine still transfers declared dependencies when a later job requires them;
the order makes operator review easier.

## 20. Configuration export/import

Settings distinguishes **configuration** and restricted **recovery** archives.
Member SHA-256 verifies integrity; the ZIP is not digitally signed. Tab scopes are
explicit. Ordinary configuration output projects secrets and credential-bearing
generated files out; recovery output can contain them and must be handled as a
private backup, never an issue attachment or cross-site sharing package.

Exports capture related tables from one consistent database snapshot. Builds,
policy edits and archive operations share configuration exclusion; managed TFTP
and directory publication also exclude file capture. If an operation is busy,
finish or reconcile it before retrying the export. Broken references between
selected tables stop the export with the affected table and ID. Deliberately
partial tab selections remain supported.

Import validates the manifest and stages files before changing module-owned state.
It preserves unrelated database records/files and requires the original local
controller identity and matching archive mode. Use configuration federation for
cross-controller policy transfer. Recovery validates matching settings/sites/key
material before restoration. Partial or uncertain compensation retains a recovery
ID and before-state; do not discard it because the main request failed.

Credential storage is upgraded automatically during normal module installation.
The hook verifies a local sites/settings/directory/key recovery archive, moves the
same key to `secrets/credentials.key`, and converts a legacy AD credential in place.
It does not rotate keys, rewrite ciphertext, change AD transport or contact AD.
Completed upgrades are no-ops on repeat; interrupted upgrades reuse matching files.
Ordinary reads, schema reconciliation, cron and federation do not migrate secrets.
Missing/disagreeing keys require the matching protected recovery pair, never a new
replacement key. There is no separate normal-operation migration button.

Remote **Install/update module** performs the same hook, enrolls/repairs the
constrained PBX agent with the supplied one-time administrator credentials, checks
its RPC and verifies credential readability as asterisk. The credentials are not
stored. A post-install readiness failure is reported as installed but not ready.
Failed installs/signing retain the candidate, previous code and available private
recovery archives; they do not blindly activate old code against migrated secrets.
For recovery to an older version, restore its matching database/private state as
well as code. A retained code directory alone is not a complete rollback.

## 21. Routine operations

- Review failed/stale/incomplete assets before changing defaults.
- Check directory last accepted revision and publication result after AD changes.
- Preview the BLF mesh after adding a site/trunk or changing extension ownership.
- Keep no more automatic replication scope than is operationally required.
- Review scheduled job activity and operation log after upgrades.
- Keep a representative canary phone for every active firmware/config profile.
- Rebuild only after FreePBX endpoint changes have settled.

## 22. Release canary matrix

The rc2 candidate has local synthetic assertion and asynchronous UI coverage for
identity ownership, reviewed destinations, failure/compensation, archives, locks,
media transfers/retirement and private directory policy. These checks are not live
FreePBX/Core, MySQL/MariaDB concurrency, LDAP, reverse-proxy or physical-device
acceptance. Those gates remain outstanding until exercised and recorded on an
approved disposable/canary environment. A local build is not a deployment.

Before declaring a new module/runtime combination production-ready, test at least:

- one Cisco 7940/7960 CNF phone: register, logo, directory, call/reload;
- one Standard JVM Cisco XML phone: timezone/DST, locale, wallpaper, directory,
  softkeys, BLF and restart;
- one 78xx and one audio 88xx: Recents/D-pad, call states, J9 timezone, wallpaper,
  BLF and transport change;
- one 88x5 video phone: feature policy, video controls, background and call states;
- one Yealink desk phone and one configured DECT system;
- one Grandstream desk/GHP phone and one configured DECT system;
- one cross-site BLF pair in both directions;
- one unreachable remote site with and without a prior snapshot;
- one media, firmware/timezone/locale, template/group, settings and phonebook
  replication to a disposable destination; and
- Asterisk activation plus rollback from console-ready maintenance mode.

## 23. Uninstall behavior

Uninstall drops module-owned tables and removes its cron, public endpoints and
privileged helper programs. It deliberately does not delete generated TFTP/Asterisk
files or Asterisk rollback bundles. A module-bootstrapped `tftpd-hpa` service,
`/srv/tftp` tree and restricted `ucmp-tftp` account also remain in place. This
preserves phone service and gives an administrator the chance to archive or remove
those components deliberately.

After every phone has been moved to another provisioning service, an administrator
may stop/disable `tftpd-hpa`, remove the module-managed SSH match block and account,
and archive or delete `/srv/tftp`. Review any pre-module `tftpd-hpa` configuration
backup before restoring it; uninstall never guesses that those destructive actions
are safe.

See [Troubleshooting](TROUBLESHOOTING.md) for symptom-led checks and
[Architecture](ARCHITECTURE.md) for internal ownership.

## rc15 editor and recovery behavior

Wallpaper writes run on the PBX that owns the selected site's TFTP and HTTP
libraries. Upgrade both controller and owning PBX to rc15 before using cross-site
wallpaper editing. The request is staged in bounded chunks before one apply;
an uncertain apply is never automatically replayed or moved to another transport.
Reopen an editor after a revision conflict. Disabling a profile hides it from new
wallpaper selections while preserving the URLs already used by phones.

For a catalog reporting missing Grandstream or Yealink files after the former
short-name upload mismatch, open it and choose **Repair hosted filenames**. This
checks all affected images against the catalog's full hashes and dimensions,
then restores their recorded short names from the matching published bytes.
It preserves the artwork, crops, sources, settings and existing URLs. Conflicting
files are never overwritten; reads do not repair automatically. Verified files
are skipped on repeat runs. An unconfirmed write is read back before continuing;
if that cannot be verified, inspect the retained operation and storage lock.

Deleting a wallpaper catalog checks saved settings, templates, phones, jobs,
generated configuration and retained generations. A protected reference blocks
retirement. Successful retirement moves HTTP payloads and TFTP payloads/editable
sources into private recovery storage. The returned recovery receipt identifies
every completed and pending move. A late failure retains those files and the
destination writer lock; inspect the receipt and actual hashes before releasing
that exact operation's lock. Do not delete retained receipts to force a retry.
Generation publication, companion cleanup, storage reset and timezone activation
likewise preserve completed stages after uncertain completion.

Asset scans compare observation revisions, including changes in the same second.
A cursor begun by an older release must be reset before starting a new full scan;
resetting the cursor does not delete media. Phone deletion rejects a changed
binding from a stale page. Site connection actions advance the form revision only
when unrelated site fields have not changed.

The local signer preserves a prepared signature pair and previous files under
the root-owned signer directory. An incomplete
`/var/lib/ucmprovisioner-signer/pending-signature.json` blocks another signing run.
This receipt contains paths, hashes and the existing key fingerprint, not private
key material. Administrator recovery must verify the installed source manifest,
the recorded fingerprint and both prepared hashes, finish that same pair, and
confirm FreePBX trusted signature status 129 before marking the receipt complete.
Do not regenerate the key, discard the pending receipt or sign changed source
over an unresolved pair. Source files and handset acceptance are separate from
signature trust.

### Binary replication and activity progress

From rc18, compatible endpoints send large managed TFTP files through a continuous
binary API request. Small files remain batched. Update the controller and receiving
PBXs to enable this path; older peers retain their existing protocol. PHP curl and
sodium must be available. Reverse proxies must allow PUT and the selected file
size (up to 512 MiB). HTTP transfer progress means bytes sent, while destination
verification and publication are reported as separate stages.

The activity panel remembers open/closed state for the browser tab, including
navigation; polling updates the badge without overriding dismissal. Firmware runs
show the selected package/byte total across destinations before identical-file
skips, cumulative sent/unchanged bytes, and the current package separately. Missing
inventory sizes are marked incomplete. Earlier successful packages remain visible
when a later destination or package fails. An interrupted run requires receipt
review before retrying unconfirmed work.

## Automatic dependency recovery

Configuration imports initialize and inspect referenced asset inventories before opening the object transaction. Template/group/settings writes and ownership remain atomic. Failed inventory reads are errors, not an empty catalog. Simple templates do not scan unrelated media or firmware.

A destination retains intended values for missing dependencies. After configuration imports and on the existing minute scheduler, it retries up to 100 waiting records, sharing each requested inventory and yielding between records after 30 seconds. Firmware, media, phonebooks, parent groups and templates arriving in later jobs can therefore satisfy prior policy without reseeding. Unchanged unresolved templates/groups are not rewritten. Paused, released, conflicting or locally modified objects retain their protection; automatic recovery never forces ownership or builds/reloads phones. Large backlogs or unavailable storage can need later ticks. Controller waiting badges refresh from destination state on scheduled ticks; historical run receipts remain unchanged.
