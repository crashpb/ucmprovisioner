# Clean FreePBX 17 installation guide

This guide takes a new, working FreePBX 17 system from an empty UCM Provisioner
database to one safely provisioned canary phone. It does not replace the FreePBX
operating-system installer, and it does not assume that an existing PBX, TFTP tree,
media catalog or directory database can be copied from another site.

## Choose a path before installing

| Path | Phones | Asterisk action |
|---|---|---|
| **A: no Cisco fleet** | Supported Yealink/Grandstream desk or DECT phones | Keep a working FreePBX runtime and use PJSIP endpoints. No USECALLMANAGER patch, source build or Patch Manager activation is needed. Skip section 5B. |
| **B: Cisco integration** | Cisco enterprise SIP, or a mixed fleet including Cisco | Use the exact supported Asterisk 20/USECALLMANAGER pair and CHAN_SIP. Complete section 5B before publishing Cisco configurations. |

The project's original purpose is USECALLMANAGER integration. It is not a
requirement imposed on non-Cisco phones. Active Directory, remote sites and the
employee web directory are also optional. A first installation can consist of one
PBX, PBX-hosted TFTP, one manually identified extension and one phone.

The source fixtures cover endpoint/generator behavior independently of the Cisco
patch. They are not certification of every FreePBX/Asterisk/handset combination.
The established Cisco runtime contract is Asterisk 20; a newer unpatched PJSIP
installation still needs a one-phone registration/call canary. Do not replace a
working non-Cisco runtime solely to satisfy a Cisco dashboard warning.

## 1. Supported starting point

The module release requires:

- FreePBX 17 with Framework and Core 17.0.1 or newer;
- PHP 8.2 or newer, LDAP when Active Directory is used, and ZIP for archive
  import/export;
- MariaDB/MySQL and the `asterisk` service account created by FreePBX;
- a stable host name, static phone-facing addresses, DNS and time synchronization;
- root access for installation and a console-accessible maintenance window; and
- an amd64 Debian 12 host when using the official FreePBX 17 Debian installer.

Start with the current
[official FreePBX 17 Debian installer](https://github.com/FreePBX/sng_freepbx_debian_install)
when the PBX itself has not yet been installed. Download and inspect the current
installer from that repository rather than keeping a private old copy of it. Its
own documentation and supported-platform checks remain authoritative for the base
FreePBX installation.

### Important Asterisk version boundary

The base FreePBX installer can select an Asterisk release newer than this module's
Cisco integration supports. Check the installed version instead of assuming the
installer's default. **For path A, the patch is unnecessary. For path B,** use the
reviewed Asterisk 20 source paired with the exact same-version USECALLMANAGER patch.
Install the module first to access Patch Manager, but do not apply Cisco
configurations until it reports the expected patched runtime and `chan_sip`.

The Asterisk 22 USECALLMANAGER patch uses a redesigned channel driver and different
configuration grammar. It is deliberately outside this release's compatibility
contract; it must not be substituted for an Asterisk 20 catalog entry.

## 2. Prepare and inspect the clean PBX

Run these checks as root before copying the module archive:

```bash
uname -m
php -v
php -m | grep -E '^(ldap|zip)$'
fwconsole --version
fwconsole ma list | grep -E 'framework|core'
asterisk -V
df -h /var /usr /var/www
```

**Path B only:** Patch Manager stages a source build; it does not install operating-system build
packages. Before using its Build action, confirm that at least these tools exist:

```bash
command -v tar
command -v patch
command -v make
command -v cc
command -v file
command -v timeout
command -v apt-mark
```

The Asterisk `configure` and build logs are the source of truth for any missing
development library on that particular FreePBX image. Resolve a missing tool or
header before retrying Build. Do not bypass a failed configure check.

Record the starting state and take a FreePBX backup. Also preserve:

- `/etc/asterisk`;
- `/usr/sbin/asterisk` and the module directory reported by
  `asterisk -rx 'core show settings'`;
- `/var/lib/asterisk`;
- the TFTP bind directory, if one already exists; and
- `/var/www/html/admin/modules`, if this is not actually a clean module install.

## 3. Verify and install the release package

Download the assets from the
[project release page](https://github.com/crashpb/ucmprovisioner/releases).
Select the module `.tgz`, not GitHub's automatically generated source ZIP/TAR.
The installable release contains these adjacent files:

- `ucmprovisioner-1.1.0rc33.tgz` — installable FreePBX module archive;
- `ucmprovisioner-1.1.0rc33.tgz.sha256` — archive checksum; and
- `ucmprovisioner-1.1.0rc33.tgz.source.json` — canonical per-file source manifest.

Create a private temporary directory on the PBX and copy all three files there
with your normal SCP/SFTP client. For example, run on the PBX as root:

```bash
install -d -m 0700 /var/tmp/ucmprovisioner-install
```

Transfer the files to that directory, then verify and inspect them:

```bash
cd /var/tmp/ucmprovisioner-install
sha256sum -c ucmprovisioner-1.1.0rc33.tgz.sha256
tar -tzf ucmprovisioner-1.1.0rc33.tgz | sed -n '1,20p'
```

Stop if checksum verification fails. A checksum detects changed bytes; it does not
authenticate a download obtained from an untrusted source. Compare against the
release you intentionally selected. The source manifest lists the hash of every
packaged file; it contains no site settings or credentials.

The archive must contain one top-level `ucmprovisioner/` directory. Install it as
root so the hook can securely enroll the two constrained privileged helpers:

```bash
tar -xzf ucmprovisioner-1.1.0rc33.tgz -C /var/www/html/admin/modules
fwconsole ma install ucmprovisioner
fwconsole ma list | grep ucmprovisioner
```

If Module Admin reports the module disabled, enable it with
`fwconsole ma enable ucmprovisioner` and read the result. Apply pending FreePBX
configuration through its normal Apply Config workflow when needed. Asterisk
runtime activation is a separate, explicitly disruptive action; installing this
module is not an instruction to restart phones or activate a patch bundle.

Read the complete install output. In particular, do not ignore a warning about a
required data directory, phone-facing endpoint, scheduled-job file, sudoers policy,
Patch Manager helper or Fail2ban helper. Correct the reported host problem, then
rerun `fwconsole ma install ucmprovisioner` as root.

The package deliberately contains its locked PHP dependencies in `vendor/`; a
production PBX does not need Composer. It deliberately does not contain
`module.sig`, PBX credentials, site keys or any preconfigured customer data.

## 4. Verify the installation contract

Confirm the module version and the objects installed by the hook:

```bash
fwconsole ma list | grep ucmprovisioner
test -d /var/lib/asterisk/ucmprovisioner
test -f /etc/cron.d/ucmprovisioner-ad
test -x /usr/local/sbin/ucmp-patch-manager
test -x /usr/local/sbin/ucmp-fail2ban
test -f /var/www/html/ucmprovisioner-api.php
test -f /var/www/html/ucmp-d.php
test -f /var/www/html/ucmp-s.php
test -f /var/www/html/ucmp-m.php
test -f /var/www/html/ucmprovisioner-phonebook.php
```

The historical cron filename contains all four one-minute dispatchers: directory
sync, BLF mesh, phone automation and media/replication work. Each dispatcher reads
its saved schedule; a one-minute cron entry does not mean every job runs every
minute.

Open **UCM Provisioner > Dashboard** and review the platform, module-integrity and
Asterisk cards. Then use **Module integrity** to create this PBX's local signature.
The signing key and signature belong to this installation and must not be copied to
another site.

## 5A. Non-Cisco fleet: use the existing FreePBX endpoints

Skip Patch Manager. Create a test extension in **Applications > Extensions** using
PJSIP, then apply the FreePBX configuration. In **UCM Provisioner > Settings**, set
the non-Cisco endpoint defaults to PJSIP and select the transport and port of the
actual FreePBX listener. A usual UDP listener is port 5060, but inspect yours:

```bash
asterisk -rx 'pjsip show transports'
asterisk -rx 'pjsip show endpoints'
```

The extension, module route and generated phone configuration must use the same
driver/transport/port. UDP, TCP and TLS are not interchangeable; TLS also requires
a valid listener and phone-compatible certificate/trust setup. For a first phone,
choose a route already known to work on this PBX. Do not change FreePBX's global
listener just to match an example.

**Check the port before saving your first phone.** Version 1.1.0rc33 defaults the
non-Cisco PJSIP port to **5160**; it does not automatically discover the listener's
port. If `pjsip show transports` shows UDP on **5060**, set the selected site's
non-Cisco defaults in **Settings** to **PJSIP / UDP / 5060**, or set that phone's
**Phone SIP port** override to **5060**. Check the phone editor's **Effective
endpoint** and correct any explicit override that still selects 5160. Use the
actual listener's protocol and port if they differ from this example.

A mismatch can make **Save only** or **Save & Rebuild** appear to fail silently:
the endpoint check rejects the save and rolls back the MAC assignment, leaving
the extension in **unbound extensions**. A valid MAC alone cannot fix that failure.
Matching the port resolved this symptom on a fresh Yealink-only installation;
the USECALLMANAGER patch was not required. Save one phone and reopen it to confirm
the MAC persists before rebuilding. See
[phone-save troubleshooting](TROUBLESHOOTING.md#phone-save-fails-silently-or-the-mac-remains-unbound)
for the exact error and verification steps.

No Cisco firmware, locale, timezone JAR, service-URI configuration or BLF mesh is
needed for this first non-Cisco phone. Proceed to section 6, provision one device,
and verify registration and calls before adding optional features. A Cisco patch
preflight warning is relevant to Cisco glue, not evidence that a non-Cisco fleet
must install the patch.

## 5B. Cisco fleet: establish the supported patched runtime

Perform this step with console access and a maintenance window:

1. Open **Patch Manager** and record Running, Installed, Active managed, Pinned and
   History separately.
2. Select a reviewed Asterisk 20 catalog entry whose source and patch versions
   match exactly. Read the version and hashes shown by the installed module; do
   not substitute a similarly named or newer patch file.
3. Use **Download**, then **Stage**. Both the Asterisk archive and patch must pass
   their pinned SHA-256 checks.
4. Review the displayed configure, optional SRTP and make commands.
5. Choose an appropriate build-job count and run **Build**. Nothing is activated by
   Download, Stage or Build.
6. Keep the existing runtime capture, then explicitly **Activate** the completed
   bundle. Activation stops FreePBX services, changes the executable and matching
   module set together, restarts FreePBX and performs runtime health checks.
7. Confirm that Running, Installed and Active managed identify the intended Asterisk
   20 build, the Cisco application is detected, and `chan_sip` responds.
8. Only after that validation, optionally **Pin** the active bundle. Pinning holds
   FreePBX's `asterisk-version-switch` package; it does not select a newer version.

If activation fails, the manager attempts to restore the captured executable,
module directory and Asterisk libraries. Keep console access because no web-based
rollback can recover a host-level outage that also removes the web interface.

The upstream [patching guide](https://usecallmanager.nz/patching-asterisk.html)
explains the matching-version requirement. Its Asterisk 22 branch uses a redesigned
driver and is outside this module's Cisco compatibility contract. Its standalone
Asterisk sample-file instructions are **not** a reason to run `make samples` over
an existing FreePBX configuration. Use the module's managed custom-file workflow.

Do not redistribute downloaded vendor phone firmware with this repository.
Obtain the correct enterprise SIP firmware and companion files through your
authorized vendor channel; a Cisco MPP/3PCC firmware branch is not automatically
interchangeable with the enterprise XML profile.

## 6. Configure the local site and TFTP ownership

The install hook creates one enabled local site from the PBX host name and first
usable IPv4 address. Inspect it; automatic discovery is not proof that the chosen
address is reachable from the phone network.

In **Sites**:

1. set the PBX management address, phone-facing SIP address and service base URL;
2. choose **PBX-hosted TFTP** for the built-in path, or **Remote Docker-hosted
   TFTP** when a separate server is required;
3. review the prefilled phone-facing TFTP address: Docker defaults to its host;
   PBX-hosted defaults to the PBX phone/SIP address. Enter an override only when
   phones reach a different address; clearing it restores the automatic default;
4. for PBX-hosted mode, click **Inspect PBX**, review the bootstrap plan, provide a
   temporary root/sudo-capable account, and allow installation of the `tftpd-hpa`
   server and `tftp-hpa` client when missing. Click **Bootstrap / repair PBX TFTP**
   to install and verify the PBX-local service. Bootstrap refuses a different
   process already bound to UDP/69, symlinked roots, and unconfirmed SSH host keys;
5. for Docker mode, configure the bind/container metadata and enroll the restricted
   `ucmp-tftp` account on that host. Docker enrollment does not install or require
   a TFTP client and does not run a TFTP packet test; and
6. run **Verify permissions** before generating a phone configuration.

PBX-hosted bootstrap creates `/srv/tftp` as the root-owned SFTP jail and
`/srv/tftp/tftpboot` as the phone-facing tree, configures `tftpd-hpa`, starts and
enables it, then verifies local UDP retrieval and key-only SFTP write/read/delete
from the controller and owning PBX before recording readiness. Both TFTP modes
merge existing authorized keys and verify that the account can read its key file.
SSH recovery is retained through the fresh SFTP check; PBX-hosted recovery also
retains the prior daemon configuration and active/enabled state. Account/package
creation and TFTP directory permissions are not automatically undone. It reports declared
phone networks but does not silently modify firewall policy. The temporary
administrator password is used only for that action and is not stored.

On an upgraded site, selecting the other architecture only stages it. Bootstrap
the target and run the copy preview. Activation occurs only after every source
file has been copied and verified with SHA-256; the old tree is retained.

The module does not treat an arbitrary pre-existing TFTP tree as managed. Files it
generates or explicitly adopts receive registry identity and hashes; unrelated
files remain observed until an administrator adopts or removes them. Normal catalog
deletion belongs in the relevant manager, not the raw TFTP browser.

## 7. Load assets before selecting defaults

Only load what your fleet needs. Non-Cisco-only installations skip Cisco timezone
and locale assets; directory integration and custom media are optional.

Populate dependencies in this order:

1. **Firmware** — add complete model-compatible firmware bundles.
2. **Firmware > Cisco timezone** — upload the extracted runtime files
   (`tzupdater.jar`, `j9-tzdata.jar`, and/or `TzDataCSV.csv`) that the deployed
   models require. The scanner maps them to the model/firmware runtime profile.
3. **Firmware > Locale** — add the companions required by each Cisco load family.
4. **Media** — create wallpaper catalogs, legacy/special images and ringtone assets.
5. **Directory & AD** — create a phonebook; add directory credentials only when the
   PBX itself will query that directory.

An asset appearing in an upload directory is not enough. Do not select it as a
default until its manager reports a complete, compatible managed object.

## 8. Set policy, then provision one canary

In **Settings**, save the local site's:

- Cisco XML, Cisco CNF and non-Cisco driver/transport/port routes;
- PBX, proxy, voicemail, NTP, clock and phone-service defaults;
- default phonebook;
- model-aware firmware, timezone, wallpaper and ringtone selections; and
- one most-specific default template for each phone family in use.

Create templates and groups next. A phone with compatible group/template layers
does not also receive the site default template. A phone with no compatible group
layer, no direct template, or an empty group does receive the most-specific site
default. Exact-model layers are applied after wider compatible layers and may
override only values they explicitly set.

For the first phone:

1. create or confirm its FreePBX CHAN_SIP/PJSIP endpoint;
2. use **Discover** or **Add phone**, then set the exact model and MAC;
3. bind the logical extension/account and review the resolved template source;
4. preview the build and inspect every planned phone and companion file;
5. apply that exact preview hash;
6. verify TFTP retrieval, registration, time, directory, media, keys, dialing and
   BLF on the physical handset; and
7. repeat once for every distinct configuration/firmware family before a fleet
   rebuild.

Do not enable scheduled rebuild/reboot, directory publication, BLF mesh or automatic
replication until manual runs have succeeded and their durable activity records are
clear.

## 9. Add remote sites only after the local canary

Enter the site's main PBX address, save the new site once, then use **Install / update** with
temporary administrator credentials and a confirmed host key. The destination
must already run supported FreePBX 17/PHP 8.2; the module need not be installed.
Preparation installs a missing module or upgrades an older one to this controller's
version, repairs restricted SSH access, and enrolls/tests the API. A healthy current
module does not need a reinstall. The signing checkbox also signs a current
module and establishes its local OpenPGP trust. Check version shows both versions
and their comparison. Voice/API hosts inherit the main address unless overridden.
Existing-site actions accept current connection edits without a save/reload cycle;
explicit actions save those connection fields even if remote setup later fails.
Review any partial result before continuing.
Enroll SFTP separately, select the real inter-site PJSIP trunk endpoints and preview
the BLF mesh before applying it. No OS, network or trunk setup is inferred.

For an empty destination, a practical replication order is media; firmware,
timezone and locale; generated phonebook snapshots; templates/groups; then selected
Settings categories. Dependency-aware jobs still transfer required children, but
this order makes operator review easier. Credentials, PBX identity, local signing
keys and runtime activation state are never inherited from the controller.

## 10. Operational handoff

Before calling the site production-ready:

- complete the canary matrix in the Administrator Manual;
- for path B, retain the original Asterisk runtime in Patch Manager history and
  record whether `asterisk-version-switch` is held;
- verify one scheduled job of every enabled type;
- test an unreachable remote site and confirm its dated snapshot is clearly marked;
- export a configuration archive with intentional tab inclusions; and
- store database, Asterisk, module data, TFTP and media backups outside the PBX.

Uninstalling the web module removes its tables, cron file, public wrappers and
privileged helpers. It intentionally preserves generated Asterisk/TFTP files,
rollback bundles, a module-bootstrapped `tftpd-hpa` service, `/srv/tftp`, and the
restricted `ucmp-tftp` account so uninstall cannot unexpectedly take phones or
Asterisk offline. Archive or remove those components separately only after every
phone has been moved and their data is no longer needed.

See the [Administrator Manual](ADMINISTRATOR-MANUAL.md) for every tab and the
[Troubleshooting Guide](TROUBLESHOOTING.md) for symptom-led recovery.

## Network and DHCP checklist

Configure the phone's provisioning server using the method supported by that
vendor and firmware: its local/web UI, DHCP option or vendor provisioning URL.
Cisco commonly uses DHCP option 150 for TFTP; other devices may use option 66 or
a vendor-specific mechanism. Do not configure competing options without checking
the handset's documented precedence. The module does not manage DHCP.

| Path | Required evidence |
|---|---|
| Phone to TFTP | Correct server address and actual retrieval of the expected configuration/companion files. UDP/69 starts TFTP; replies use the negotiated transfer ports, so stateful firewall handling matters. |
| Phone to PBX | Reachable resolved SIP transport/port plus bidirectional negotiated RTP audio. Use the PBX's configured RTP range, not an assumed universal range. |
| Phone to DNS/NTP | Correct name resolution and time from the phone network. TLS and time-sensitive behavior need a valid clock. |
| Phone to HTTP/HTTPS services | Reachable directory/media/service URLs using protocols and certificates supported by that firmware. Legacy plain-HTTP requirements belong on restricted networks. |
| Controller to site/storage | Actual configured API/SSH/SFTP address and port, trusted host key and least-privilege account. SFTP-only access is not an administrator shell. |

Do not expose TFTP or secret-bearing generated configurations to the public
Internet. Test from the phone network: a successful download from the PBX itself
does not prove the DHCP, routing and firewall path used by a phone.

## First-phone acceptance record

Record the module version, phone model/firmware, endpoint driver/transport/port,
selected configuration filename and test time without recording passwords.

- Retrieve the expected files and confirm the phone registered to the intended PBX.
- Call another internal extension in both directions and verify two-way audio.
- Test the outbound route and emergency-calling policy through your organization's
  approved procedure; module installation does not configure those routes.
- Check time, caller/line labels, directory dialing and any selected media.
- For enabled features, check hold/transfer/BLF and one supported reload action.
- Compare a second preview with the applied intended policy; inspect unexpected
  drift before extending to the fleet.

## Common first-install problems

| Symptom | First check |
|---|---|
| Module missing or disabled | Complete installer output, Framework/Core requirements and Module Admin state. |
| Integrity warning | Verify intended package bytes, then create the installation's own local signature. Do not copy a signature from another PBX. |
| No provisioning request | Phone's actual server setting/DHCP lease, correct network and TFTP reachability. |
| File downloaded but no registration | Exact driver/transport/port, account credentials, active listener and handset firmware family. |
| Non-Cisco build versus Cisco warning | Use path A; investigate the specific build error. A Cisco dashboard warning alone does not require a patch. |
| Cisco preflight failed | Exact Asterisk 20 patch/runtime pair and loaded CHAN_SIP/Cisco capabilities. |
| Plan changed | Refresh the preview after the input change; do not reuse the stale hash. |
| Missing firmware/media | Complete model-compatible managed object and current inventory; a loose file is not enough. |
| Directory unavailable | Published book, enabled policy, actual connecting address and selected visible fields. |

## Build an installer from a source checkout

Use a development machine with PHP 8.2+, Composer and Python 3, outside the live
PBX module directory. Install locked dependencies and run verification:

```bash
composer install --no-dev --optimize-autoloader
composer validate --strict
composer audit --locked
php tests/run.php
python3 tools/build-release.py /tmp/ucmp-build-one
python3 tools/build-release.py /tmp/ucmp-build-two
sha256sum /tmp/ucmp-build-one/*.tgz /tmp/ucmp-build-two/*.tgz
```

The two archive hashes must match for a reproducibility claim. Inspect manifests
and the file list; use the generated TGZ/checksum/manifest with section 3. See
[Contributing](../CONTRIBUTING.md) for Node checks and Linux-specific acceptance.
Do not run Composer update on a PBX to resolve an installer problem.
