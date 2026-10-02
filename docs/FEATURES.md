# Feature and workflow reference

Use this page to choose an action and understand its effects. The
[administrator manual](ADMINISTRATOR-MANUAL.md) gives the detailed controls;
[examples](EXAMPLES.md) show complete workflows. All addresses, names and numbers
in the examples are fictional. Run examples against your own test extension.

## How to read results

There are several separate outcomes: a saved policy, a generated preview, files
published to TFTP, an activated PBX configuration, an accepted phone command, and a
working physical phone. Success at one does not prove the next. For example,
“reboot sent” does not prove registration, and an uploaded image does not prove
that the selected phone can display it.

Every page has a site context. Confirm that context before a write. An offline
snapshot is dated, read-only evidence of the last successful read. It cannot prove
current registration or authorize writes to an unreachable site.

## 1. Dashboard and integrity

| Function | Inputs and behavior | Result and verification |
|---|---|---|
| Site health | Reads local or remote site status and available inventory. | Inspect live/cached state and collection time; unknown is not an empty site. |
| Asterisk status | Compares running, installed and managed runtime facts. | For Cisco, verify the matching patch and channel driver. Non-Cisco PJSIP does not need the Cisco patch. |
| Module integrity | Checks module files and provides per-PBX local signing. | Sign only final installed bytes. Each PBX retains its own key and trust; release archives have no local signatures. |
| Recent operations | Displays available job and transaction results. | Read warnings and partial results, not just the top-level status. |

## 2. Phones: inventory, assignments and builds

| Function | Inputs and behavior | Result and verification |
|---|---|---|
| Add/edit phone | Select site, exact vendor/model, MAC, logical extension, additional accounts and policy. MACs are normalized. | A saved record; it is not a published configuration. Confirm the displayed endpoint route and policy source. |
| Discover endpoints | Correlates FreePBX endpoint data with known phones. | Inventory candidates; review model/MAC/extension before binding. |
| Scan a subnet | Takes an explicit IPv4 /20–/32 range and temporary administrator credentials when privileged discovery is required. Remote scans execute on the destination PBX. | Bounded scan results. Suggested saved ranges do not silently expand the scan. No credential is saved by the scan action. |
| Live status | Reads registration and reachable-address evidence. | A current status or a labeled cached/unknown result; neither a saved IP nor ping alone proves SIP registration. |
| Bind/reassign | Connects a physical phone to a logical extension. | Assignment and identity source change. Review the affected old and new devices before publication. |
| Explain | Resolves where settings, identity and template choices came from. | Read-only trace, including skipped/incompatible candidates. |
| Draft impact | Evaluates unsaved choices against the selected scope. | Proposed changes only; saving or applying is a separate action. |
| Replace/move/swap | Reviews a fixed set of assignments with current revisions. | Guarded assignment changes and separate publication/device results. A reverse action needs a fresh review. |
| Preview | Resolves policy and generates phone/companion content without publishing it. | File names, warnings, changes and a plan hash. Preview content can contain SIP credentials: keep it private. |
| Apply build | Uses the reviewed hash, configuration/storage locks, staged files, managed Asterisk blocks and receipts. | A committed generation or a reported failure/compensation result. A changed plan must be previewed again. |
| Reload/reboot | Sends the command supported by the exact model and active endpoint route. | Delivery result only. Observe the handset and registration separately. |
| Rebuild exclusion/manual override | Preserves phones or files deliberately outside ordinary generated policy. | Normal builds skip protected output. Explicit single-phone replacement is required to replace a manual configuration. |
| Scheduled rebuild/reboot | Evaluates the saved schedule and target rules. | Durable run results. Enable only after the same manual workflow succeeds. |

The complete site fleet contributes to shared Asterisk glue even when only one
phone's files are rebuilt. Otherwise a single-phone build could remove other
extensions from a shared managed block. A non-Cisco build does not require the
Cisco patch, but normal build application can still update managed Asterisk
configuration and reload it. Treat Apply as an operational change.

Implementation: [Provisioner](../lib/Provisioner.php),
[PhoneAssignment](../lib/PhoneAssignment.php),
[OperatorWorkflowService](../lib/OperatorWorkflowService.php),
[PhoneCommand](../lib/PhoneCommand.php), [LiveStatus](../lib/LiveStatus.php).

## 3. Templates

Templates describe model-compatible policy rather than copied complete phone
files. Set applicability first, then explicit values. An inherited/blank value
usually leaves an earlier layer in force; it is not an instruction to clear every
field. Use the editor's explicit clear/override control where one is provided.

| Function | What it controls | Important boundary |
|---|---|---|
| Applicability | Vendor, family/profile or exact model. | An incompatible layer is skipped, with a trace. A shared display name does not prove compatibility. |
| Endpoint policy | Driver, transport and port. | One resolved tuple feeds FreePBX binding, generated output and status. Cisco XML and Cisco CNF defaults are independent of non-Cisco defaults. |
| Identity labels | Phone title, line text, BLF names and bounded formatting. | Full caller identity is separate from short handset labels. Directory authority and explicit identity overrides still apply. |
| Keys/accounts | Line, BLF, speed-dial and supported additional-account assignments. | Check physical capacity, firmware behavior and model capabilities. A narrower non-empty key list replaces the earlier list. |
| Dial/softkey policy | Cisco digit rules, softkey positions and feature policy. | The model/firmware determines what is generated and what the handset honors. See [softkeys](cisco-enterprise-softkeys.md). |
| Network/display/assets | Supported VLAN intent, clock, display, firmware, locale, timezone, wallpaper, ringtone and directory references. | References must resolve to compatible managed objects. A selected policy does not configure the network itself. |
| Push/replicate | Sends selected policy with portable dependencies to explicit destinations. | Review ownership and missing dependencies; it does not automatically rebuild phones. |

Implementation: [TemplateEngine](../lib/TemplateEngine.php),
[EndpointProfile](../lib/EndpointProfile.php), [VlanPolicy](../lib/VlanPolicy.php).

## 4. Groups and defaults

Groups organize logical extensions and ordered template layers. Parent groups
apply before children; broad compatible layers apply before exact-model layers.
Member exceptions apply to selected members. Phone overrides follow the group
stack. Parent cycles and cross-site parent relationships are rejected.

A phone with a compatible group stack does not also inherit the site default
template underneath it. If there is no compatible group layer, the resolver
selects one most-specific default. Equally specific competing defaults are an
error to correct, not an arbitrary tie to accept. Inspect Explain after changing
membership, applicability or defaults.

Implementation: [TemplateStackResolver](../lib/TemplateStackResolver.php),
[DefaultTemplateResolver](../lib/DefaultTemplateResolver.php).

## 5. Sites, connections and TFTP

| Function | Inputs and effect | What to check |
|---|---|---|
| Site record | Main PBX address plus optional API, voice and service overrides. | Management reachability and phone reachability can differ. Confirm both. |
| Install/update remote module | Temporary administrator access, verified SSH host key and supported existing FreePBX. Installs/upgrades or repairs module access. | Version, enabled state, constrained SSH and encrypted API readiness are separate checks. This is not an OS installer. |
| API enrollment | Establishes the authenticated encrypted normal transport. | Both sides must agree on identity/keys and supported capabilities. |
| SSH recovery | Retains a restricted, host-key-pinned administrative channel. | Verify a changed host key out of band; do not accept it just to clear an error. |
| PBX-hosted TFTP bootstrap | Inspects the host, installs missing TFTP packages when approved, creates the jail/tree, configures and tests the service. | Port ownership, permissions, local TFTP retrieval and SFTP from the controller and owning PBX. |
| Docker-hosted TFTP enrollment | Uses explicit host, SSH port, bind root and container metadata; sets up restricted file access. | Confirm the existing container and actual served bind path. Enrollment alone is not a phone-side packet test. |
| TFTP architecture change | Stages the new architecture, copies files and verifies hashes before activation. | Retained source tree, full copy verification and the newly active phone-facing address. DHCP/firewall changes remain external work. |
| BLF mesh | Uses selected existing inter-site PJSIP endpoints to preview and apply device-state publication. | Two-phase preparation/activation and live cross-site hints. It does not create trunks or routes. |

Implementation: [SiteConnection](../lib/SiteConnection.php),
[TftpArchitectureService](../lib/TftpArchitectureService.php),
[PublishMesh](../lib/PublishMesh.php).

## 6. Directory, identities and phonebooks

| Function | Inputs and effect | Result and boundary |
|---|---|---|
| Directory source | LDAP host, base/search scope, mapping, schedule and its own reader credentials. | Independent source configuration. Test connectivity before sync; credentials belong only to the querying PBX. |
| Directory sync | Reads the selected source, evaluates mappings and records accepted identities/conflicts. | Source freshness, accepted people and conflict results. A failed/partial read must not be treated as authoritative deletion. |
| Site mapping | Ordered rules map directory data to module sites/extensions. | Review which rule matched. Similar site names do not prove a correct destination. |
| Identity binding | Chooses AD-primary, person-linked additional or manual/shared identity. | The logical extension owns identity; replacing the physical MAC does not create a new person. |
| Identity override | Saves explicit field overrides with actor/reason and revision guards. | A visible override source; reverting returns to the underlying authority according to the chosen mode. |
| Identity refresh | Reviews fixed owner and consumer destinations and commits accepted identity projections. | Separate per-target outcomes. Missing accounts or ownership conflicts are skipped individually; no account is silently created. |
| Phonebook designer | Selects accepted contacts, rules, fields, groups and manual entries. | Preview before publication. Phonebooks and identity authority are separate concerns. |
| Handset search/publication | Produces vendor-specific phonebook/search responses and published snapshots. | Test the actual handset URL, search and dialing. Names, usernames and numbers follow the configured search policy. |
| Phonebook replication | Transfers selected published data to explicit sites. | A receiver can serve the snapshot without inheriting the LDAP password. Source ownership and publication revision remain visible. |

Implementation: [DirectoryService](../lib/DirectoryService.php),
[LogicalExtensionService](../lib/LogicalExtensionService.php),
[IdentityDeliveryService](../lib/IdentityDeliveryService.php).

## 7. Employee web directory

The employee directory is separately enabled. Select published books, visible
fields and permitted client networks before exposing it. Table/People views,
multilingual search, selected-contact print layout and XLSX export use the same
field and audience policy. Export must not reveal hidden fields.

The preferred URL is optional presentation metadata; entering a hostname does not
create DNS, a web-server virtual host or a certificate. Connecting-address checks
are the default. Forwarded client addresses are trusted only through explicitly
configured exact proxies. Test an allowed and a denied client, including export.

Configuration sync can share this policy. Hard lock prevents receiver edits;
without hard lock, a local edit pauses synchronization. Releasing ownership keeps
the current content. Policy replication neither copies contacts nor publishes a
missing book. Audience expansion requires the corresponding publication review.

Implementation: [WebPhonebook](../lib/WebPhonebook.php),
[web_phonebook.php](../web_phonebook.php).

## 8. Patch Manager: optional Cisco runtime management

| Action | Effect | Activation boundary |
|---|---|---|
| Inventory | Reads running, installed, active managed, staged, pinned and historical versions. | Read-only; these versions can differ. |
| Download | Fetches catalog-selected source/patch artifacts and checks pinned hashes. | No running-runtime change. |
| Stage | Prepares the exact supported source/patch pair. | No running-runtime change. |
| Build | Runs the reviewed build configuration with the chosen job count. | Produces a bundle; does not activate it. |
| Import/replicate bundle | Validates an existing binary bundle and its metadata. | A copied bundle is not the active runtime. |
| Activate | Captures existing runtime, switches executable/modules together, restarts FreePBX services and checks health. | Disruptive maintenance action requiring console recovery access. |
| Rollback | Restores a selected retained compatible runtime through the managed workflow. | Explicit disruptive action; having a backup does not trigger it automatically. |
| Pin/unpin | Manages the supported FreePBX package hold. | Does not install or activate a different bundle. |

Use only the documented Asterisk 20 contract for this release. The redesigned
Asterisk 22 patch is not a drop-in replacement. Non-Cisco-only installations skip
this workflow entirely. See [installation](FRESH-INSTALL.md#5b-cisco-fleet-establish-the-supported-patched-runtime).

## 9. Media

Wallpaper catalogs retain a source image and compatible model outputs. Upload,
crop/adjust, build and validate the catalog before assignment. Selectors offer
verified current outputs; loose files, disabled outputs and retained compatibility
aliases are not extra choices. Existing saved references remain resolvable.

Legacy/special images and audio use their own model/codec constraints. Hosted HTTP
media and TFTP media have different publishers. A successful image decode or a
familiar filename is not proof of catalog ownership. Use the manager's repair and
retirement operations for managed objects.

Replication transfers selected objects and dependencies, checks hashes and
tracks progress. Interrupted transfers can leave owned staging for recovery;
uncertain results must be reconciled before retrying a mutation. Retirement
reviews references and revision before replacing/removing files. A retained prior
file is not permission to overwrite a newer one during restore.

## 10. Firmware, timezone and locale

Firmware imports inspect model identity and companion completeness. Select a
complete bundle appropriate to the phone's current firmware branch. Vendor
firmware and locale licenses remain the operator's responsibility; they are not
included in this source repository.

Cisco timezone support inventories runtime artifacts such as `tzupdater.jar`,
`j9-tzdata.jar` and `TzDataCSV.csv` according to the model/firmware profile. Locale
selection similarly requires compatible companions. A timezone label in Settings
does not replace a missing phone runtime file. Verify the handset's actual local
time and daylight-saving behavior.

Firmware-tab replication can include firmware, timezone and locale objects. It
does not activate Asterisk or reboot the fleet. See [device support](DEVICE-SUPPORT.md).

## 11. Phone Services

Create services with explicit applicability, destinations and supported response
types, then preview for the intended vendor/model. Phone Services generates
catalog/application responses; it does not make an inaccessible destination
reachable. Service URLs must work from the phone network and meet the handset's
HTTP/TLS capability. Test selection, navigation and dialing on a canary handset.

## 12. TFTP Browser and Fail2ban

The TFTP Browser inventories files, supports authenticated download and explicit
upload/delete, and distinguishes managed files from observed files and manual
overrides. Use the catalog manager to retire a catalog object; deleting one of its
files through raw storage is not an equivalent operation. Downloaded phone files
can contain credentials and must not be posted in issues.

Fail2ban reads jail status through a constrained helper. Unban acts on an explicit
jail/address after confirmation. It does not disable a jail or fix the failed
authentication that caused the ban. Verify the intended address and correct the
client configuration before repeated unbans.

## 13. Settings, replication and archives

Settings supply site defaults, model-aware default templates/assets, endpoint
routes and schedules. “Default” means fallback; it does not overwrite every phone
override. Inspect the resolver trace when a saved default appears not to apply.

Configuration-sync jobs select explicit objects/categories/fields and
destinations. Ownership, local edits, revisions and missing dependencies control
whether an object applies, waits, pauses or conflicts. The existing scheduler
retries eligible dependency-waiting objects as their assets arrive. It never
uses missing media as a reason to erase intended policy, force ownership or build
phones automatically.

Configuration archives export/import selected module-owned configuration and
managed files. Review inclusion choices and destination validation. They are not
a complete FreePBX backup and do not replace the database, Asterisk runtime, secret
key and phone-facing storage recovery plan. Keep archives private.

For all scheduled functions, first run the same scope manually, inspect durable
results, then enable a schedule appropriate to the site. The one-minute dispatch
cron evaluates saved due times; it does not run every configured job every minute.
