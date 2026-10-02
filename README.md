# UCM Provisioner

**Phone provisioning, directories, media and multi-site management for FreePBX 17.**

UCM Provisioner 1.1.0rc33 manages supported Cisco, Yealink and Grandstream desk and
DECT phones from the FreePBX web interface. Start with one PBX and one phone; add
directory integration, shared templates and remote sites when you need them.

The original goal was integration with the
[USECALLMANAGER patch](https://usecallmanager.nz): make Cisco enterprise SIP line
behavior, BLF hints and cross-site device-state publication work coherently with
FreePBX and patched Asterisk. That remains a core feature. **A Yealink/Grandstream-only
installation does not need that patch or Patch Manager.** Use its normal FreePBX
PJSIP endpoints and follow the non-Cisco installation path.

Copyright (C) 2026 crashpb. Released under the GNU General Public License v3.0 or
later. See [LICENSE](LICENSE) and [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Start here

| Your goal | Start with |
|---|---|
| Install on a new FreePBX system | [Installation guide](docs/FRESH-INSTALL.md) |
| Provision Yealink or Grandstream without the patch | [Non-Cisco walkthrough](docs/EXAMPLES.md#1-one-pbx-one-yealink-or-grandstream-phone-no-patch) |
| Integrate Cisco enterprise phones with USECALLMANAGER | [Cisco walkthrough](docs/EXAMPLES.md#2-cisco-enterprise-phone-with-usecallmanager) |
| Understand every product function and its effects | [Feature and workflow reference](docs/FEATURES.md) |
| Set up templates, directories and multiple sites | [Worked examples](docs/EXAMPLES.md) |
| Find a class, method, endpoint or scheduled job | [Developer reference](docs/DEVELOPER-REFERENCE.md) and [architecture](docs/ARCHITECTURE.md) |
| Diagnose a failed build, registration or sync | [Troubleshooting](docs/TROUBLESHOOTING.md) |

Download the installable `.tgz`, checksum and source manifest from
[Releases](https://github.com/crashpb/ucmprovisioner/releases). GitHub's automatically
generated **Source code** archives are source checkouts, not the packaged installer.
This is a release candidate: use a test extension and one representative phone
before applying changes to a fleet.

## Choose the right installation path

| Fleet | USECALLMANAGER patch | Endpoint route | What to skip |
|---|---|---|---|
| Supported Yealink/Grandstream only | Not required | Normally PJSIP; configured driver, transport and listener must agree | Cisco patch activation, Cisco locale/timezone assets and Cisco glue setup |
| Cisco enterprise SIP | Required for this module's Cisco integration | Matching patched Asterisk 20 and CHAN_SIP | Do not substitute the redesigned Asterisk 22 patch |
| Mixed Cisco and non-Cisco | Required for Cisco features | Separate Cisco CHAN_SIP and non-Cisco endpoint defaults | Do not change non-Cisco accounts to CHAN_SIP merely because Cisco phones exist |

Active Directory, the employee web directory, Docker-hosted TFTP and multi-site
replication are optional. A local-only installation can use PBX-hosted TFTP and
manual/shared identities. The module does not install FreePBX, create network
routes, supply vendor firmware licenses or configure your switches and DHCP server.

## What it manages

Generated asset naming and upgrade compatibility are documented in
[Asset filenames](docs/ASSET-FILENAMES.md).

- Cisco Enterprise XML configurations (`SEP<MAC>.cnf.xml`) for supported 39xx,
  69xx, 78xx, 79xx, 88xx, 89xx and 99xx branches.
- Cisco 7940/7960 legacy SIP configurations (`SIP<MAC>.cnf`).
- Yealink and Grandstream desk phones, programmable keys, accounts, VLAN policy,
  phonebooks, media and supported DECT base/handset relationships.
- Model-aware templates, inherited groups, exact-model layers, member exceptions,
  site defaults and per-extension identity overrides.
- Asterisk `chan_sip` Cisco glue, local hints and a two-phase
  `res_pjsip_publish_asterisk` BLF federation mesh.
- Independent Active Directory sources, authoritative identity synchronization,
  generated phonebooks, name/username/number search and scheduled publication.
- Explicit AD-primary, person-linked additional and manual/shared identities,
  with separate full caller names, short labels and legacy adoption review.
- Owner-scoped explanation, unsaved draft impact, reviewed replacement and
  fixed-target identity refresh, with separate publication/runtime/device evidence.
- A separately enabled internal web phonebook with Table/People views, multilingual
  search, contact selection, print/PDF layout and genuine Excel workbook export.
  Client networks, published books and visible fields are explicitly allowlisted.
- Transactional TFTP configuration builds with preview hashes, rollback receipts,
  manual-override protection and per-phone reload/reboot commands.
- Wallpaper catalogs, legacy media, ringtones, firmware bundles, Cisco locale and
  timezone runtime files, managed hashes and cross-site replication.
- Site enrollment, authenticated federation API, restricted SSH recovery channel,
  last-known-good offline snapshots and selectable PBX-hosted or remote
  Docker-hosted TFTP with verified architecture cutover.
- Asterisk/USECALLMANAGER source build, binary import, activation history, rollback
  and optional FreePBX package hold through Patch Manager.
- Phone Services, a TFTP browser, bounded discovery, status inspection, scheduled
  maintenance, configuration export/import and read/unban Fail2ban operations.

## Compatibility boundary

Cisco enterprise features require more than a generated XML file. The installed
Asterisk source and USECALLMANAGER patch must be an exact supported pair, the
patched runtime must expose the expected Cisco application, and the applicable
`chan_sip` transport must be active. Patch Manager shows running, installed,
managed, pinned and rollback versions separately; it never treats a downloaded or
replicated build as active until an administrator explicitly activates it.

Cisco 7940/7960 CNF phones use their own CHAN_SIP/UDP site defaults. Cisco XML
phones use independent CHAN_SIP transport and port defaults. Yealink and
Grandstream phones can use CHAN_SIP or PJSIP where their configured model profile
supports it. One resolved driver/transport/port tuple is used by FreePBX, the phone
configuration and status display so those layers cannot silently disagree.

Install the same UCM Provisioner version on every participating PBX. Firmware,
media and timezone compatibility is decided by the phone model catalog, not only
by a filename or vendor name. See [Device support](docs/DEVICE-SUPPORT.md).

## Requirements

- FreePBX 17 with Framework and Core 17.0.1 or newer.
- PHP 8.2 or newer, with the extensions required by FreePBX plus LDAP for Active
  Directory integration and ZIP for configuration archives.
- A working FreePBX Asterisk runtime. Cisco integration targets Asterisk 20 with
  its exact matching USECALLMANAGER patch and `chan_sip`. Non-Cisco phones do not
  require the patch; use a working endpoint driver supported by your FreePBX
  installation. See the installation guide for the tested-version boundary.
- MariaDB/MySQL as supplied by FreePBX.
- A phone-reachable TFTP service. Sites can bootstrap `tftpd-hpa` on the FreePBX
  host or retain a separate Docker TFTP volume. Both use a key-only, jailed
  `ucmp-tftp` data plane.
- `nmap` only when active subnet discovery is required.
- Network reachability from managed phones to TFTP, PBX SIP services and any
  configured plain-HTTP legacy media or phone-service endpoint.

Production dependencies are locked in `composer.lock` and included under
`vendor/` in the release archive.

## Install or update

Use the [complete installation guide](docs/FRESH-INSTALL.md) for prerequisites,
both runtime paths, TFTP/DHCP setup, signing, first-phone checks and troubleshooting.
On an existing PBX, prepare a recoverable system backup and a maintenance window.
Copy the three release files into a private temporary directory on the PBX, then
run these commands there as root:

```bash
sha256sum -c ucmprovisioner-1.1.0rc33.tgz.sha256
tar -xzf ucmprovisioner-1.1.0rc33.tgz -C /var/www/html/admin/modules
fwconsole ma install ucmprovisioner
fwconsole ma list | grep ucmprovisioner
```

The archive contains a top-level `ucmprovisioner/` directory. Use FreePBX Module
Admin to confirm version 1.1.0rc33 after installation. On each PBX, use the module's
Module integrity control to create that PBX's local signature after the files are
in their final location. Local signing keys and signatures are intentionally not
replicated between sites.

For an existing installation, install the archive over the current module and let
the install hook reconcile older module-owned schema. Do not drop module tables as
an upgrade procedure: they contain site, phone, template, directory and managed
asset identity.

Installation now completes the credential-storage transition automatically: it
verifies a private local recovery archive, preserves the existing encryption key
and ciphertext, and moves any legacy AD reader credential in place. No separate
migration button or new AD bind is required. Incomplete or inconsistent secret
state stops the upgrade; retain the matching recovery archive before retrying.
The remote Install/update action also repairs and verifies its constrained site
agent and checks credential access as the service account. It reports readiness
separately from files being installed.

To use one site's employee web-directory settings across other sites, create a
Configuration sync job with **Web-directory settings**. The receiving form shows
the source and lock state. Optional **Hard lock** prevents receiver edits; without
it a local edit pauses updates. Selected books must already be published on each
receiver. This scope does not copy contacts or set up DNS, TLS or network access.

## First configuration

1. Open **Sites** and verify the automatically created local site.
2. Choose PBX-hosted or remote Docker-hosted TFTP, set its phone-facing address,
   bootstrap it, and verify the active tree.
3. Configure **Settings** site defaults: endpoint routes, NTP/timezone, services,
   default templates, firmware, phonebook and wallpaper selections.
4. Add and validate firmware, locale, timezone and media assets before selecting
   them in defaults or templates.
5. Create templates and groups, then configure Active Directory sources and
   phonebooks if they are used.
6. Discover or add phones, bind their logical extensions, preview a build and
   inspect every planned file before applying it.
7. For multiple PBXs, save the remote site and use **Install / update** with
   temporary administrator SSH credentials. It installs a missing module, upgrades
   an older module, or repairs the current version, then verifies SSH and API
   channels. The signing checkbox includes local trust setup, even for a current
   module. One PBX address supplies SSH, API and voice defaults; distinct addresses
   remain optional overrides. Select the actual inter-site PJSIP endpoints, preview the BLF mesh and
   only then apply it. The remote OS and supported FreePBX must already exist.
8. Validate one representative phone from every firmware/configuration family
   before a fleet rebuild.

The [Administrator manual](docs/ADMINISTRATOR-MANUAL.md) covers every tab and the
recommended rollout sequence.

## Safety model

- Mutations require an authenticated FreePBX session, POST and a page token.
- Remote API envelopes are encrypted and authenticated; SSH host keys are pinned.
- Uncertain remote mutations are reconciled by operation receipt, never
  automatically replayed over the other transport.
- Stored site and directory secrets are encrypted with a per-installation key.
- TFTP writes are staged, hashed and committed as one generation. A changed preview
  is rejected rather than applying an unseen plan.
- Generated Asterisk glue and BLF mesh files use managed blocks and transaction
  receipts. Reload commands are bounded so an unhealthy Asterisk CLI cannot hold a
  web request forever.
- An unreachable site is read-only and displays a dated last-known-good snapshot.
  Missing cached data is reported as unknown, not as an empty remote inventory.
- Replication transfers selected managed objects and their portable dependencies;
  it does not transfer PBX identity, passwords, directory bind credentials or local
  signing keys.
- Privileged Patch Manager and Fail2ban operations use fixed helpers with bounded
  JSON input rather than arbitrary shell commands.

This is a schema-34 release candidate. Local regression and failure tests do not
replace deployment verification or coordinated FreePBX, LDAP, proxy and handset
acceptance. Upgrading does not automatically adopt unknown legacy labels.
Credential-key migration runs through the install hook and has an explicit
verified-recovery-backup gate; ordinary read paths do not move keys.

Read [SECURITY.md](SECURITY.md) before exposing federation or phone-service
endpoints outside a trusted management network.

## Documentation

- [Clean FreePBX 17 installation](docs/FRESH-INSTALL.md) — base-system boundary,
  package verification, unpatched non-Cisco setup, optional patched Cisco runtime
  and rollout from an empty module database.
- [Feature reference](docs/FEATURES.md) — inputs, effects, outcomes and related
  controls for each major product function.
- [Worked examples](docs/EXAMPLES.md) — single-site, mixed-fleet, template,
  identity, phonebook, wallpaper and federation scenarios using synthetic data.
- [Developer reference](docs/DEVELOPER-REFERENCE.md) — entry points, core methods,
  service contracts, data ownership and extension examples.
- [Administrator manual](docs/ADMINISTRATOR-MANUAL.md) — installation, complete UI
  workflow, replication and operations.
- [Architecture and code map](docs/ARCHITECTURE.md) — data flows, ownership
  boundaries, storage and class-by-class responsibilities.
- [Device support](docs/DEVICE-SUPPORT.md) — model families, generated files,
  firmware, locale, timezone, media and reload behavior.
- [Cisco enterprise softkeys](docs/cisco-enterprise-softkeys.md) — documented,
  generated and visual-only softkey behavior.
- [Troubleshooting](docs/TROUBLESHOOTING.md) — symptom-led diagnosis and recovery.
- [Changelog](CHANGELOG.md) — release history.
- [Contributing](CONTRIBUTING.md) — development and verification rules.

## Development and verification

Install locked dependencies only when building from a source checkout:

```bash
composer install --no-dev --optimize-autoloader
composer validate --strict
composer audit --locked
php tests/run.php
python3 tools/build-release.py /path/to/output
```

`tests/run.php` lints every first-party PHP entry point, class, view, helper and
test, then runs the assertion scripts in isolated processes. The suite covers
schema upgrades, generation for all supported families, directory authority,
template/group/default resolution, endpoint routing, media/firmware/timezone
catalogs, federation, transactional builds, privileged-helper schemas and release
metadata. It complements—rather than replaces—real FreePBX, Asterisk and handset
canaries.

`tools/build-release.py` creates a deterministic archive and emits both an archive
SHA-256 file and a canonical per-file source manifest. It refuses symbolic links,
local credential files and private-key extensions, and excludes PBX-local module
signatures.

## License

UCM Provisioner is licensed under GPL-3.0-or-later. Bundled libraries retain their
own licenses and authorship; see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
Use, modification, forks and commercial use are allowed under that license.
When distributing covered code or modified versions, comply with the GPL's
source, notice and license requirements. There is no separate noncommercial
restriction or commercial-permission requirement for this GPL release.
