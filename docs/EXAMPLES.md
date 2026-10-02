# Worked examples

These recipes use fictional sites `Office` and `Branch`, extension `4101`, PBX
address `192.0.2.10`, TFTP address `192.0.2.20`, domain `example.com`, and locally
administered sample MAC `02:00:00:00:00:01`. Documentation addresses will not route
on your network. Substitute your actual test values; never copy SIP passwords,
directory exports or private keys into a public issue.

Start with [Fresh installation](FRESH-INSTALL.md). Names of controls are described
in [Features](FEATURES.md) and the [Administrator manual](ADMINISTRATOR-MANUAL.md).

## 1. One PBX, one Yealink or Grandstream phone, no patch

**Goal:** provision an ordinary desk phone using the PBX's working PJSIP runtime.

1. Install FreePBX 17 and the module, then verify the installation contract.
2. Create PJSIP extension `4101` in FreePBX and apply its configuration.
3. Inspect `pjsip show transports` in the Asterisk CLI. In module Settings select
   PJSIP, that transport and its actual listening port for non-Cisco defaults.
4. Inspect the local Site. Choose and bootstrap PBX-hosted TFTP, or enroll the
   existing Docker-hosted TFTP service with its actual SSH port and served path.
5. Configure the phone's provisioning server through its supported web UI or DHCP
   mechanism. Use the TFTP address reachable from the phone network.
6. Add the phone with its real MAC and exact model; bind extension `4101`. Choose
   manual/shared identity if no directory source is used.
7. Preview. Confirm account, registrar, port, transport and generated file family.
   Keep the secret-bearing preview private. Apply the reviewed build.
8. Trigger the supported phone provisioning/reload action. Check registration,
   an internal call in both directions, audio and the displayed extension/name.

**Expected:** one phone registers without downloading or activating a
USECALLMANAGER patch. If it does not, inspect the generated route, TFTP retrieval,
SIP status and phone logs separately. Do not install the Cisco patch to fix an
ordinary PJSIP password or network problem.

## 2. Cisco enterprise phone with USECALLMANAGER

**Goal:** use a supported Cisco enterprise SIP model with the module's patched
Asterisk 20 integration.

1. Complete installation path B and verify the exact source/patch pair, Cisco
   application and `chan_sip` runtime before generating Cisco output.
2. Obtain the correct enterprise SIP firmware through an authorized vendor
   channel. Import the complete model-compatible bundle and required companions.
3. Configure Cisco XML endpoint defaults to the actual CHAN_SIP listener. For a
   7940/7960, use the independent Cisco CNF defaults instead.
4. Create/confirm the compatible FreePBX endpoint, add the exact MAC/model and
   bind the extension. Select the firmware, locale and timezone assets actually
   required by that model.
5. Preview. An enterprise XML device should have `SEP<MAC>.cnf.xml`; a legacy
   7940/7960 uses `SIP<MAC>.cnf` and its applicable companions.
6. Apply, then verify file retrieval, registration, calls, time, line labels and
   supported key/service behavior on that handset.

**Expected:** the generated configuration, FreePBX endpoint and patched listener
agree. A working TFTP download alone is not successful Cisco integration. Do not
replace an enterprise profile with an MPP/3PCC firmware profile based on model
number alone.

## 3. Mixed fleet with separate endpoint routes

**Goal:** add Cisco without converting existing non-Cisco accounts.

Set Cisco XML and Cisco CNF defaults from the actual CHAN_SIP listeners; leave
non-Cisco defaults on the working PJSIP listener. For example, the PBX may use
distinct SIP ports, but do not assume which port belongs to which driver. Read
`sip show settings` and `pjsip show transports` first. Preview one device of each
family and compare the resolved driver/transport/port. Apply each canary and test
calls between them before a wider rebuild.

**Expected:** each device follows its selected route; installing the patch does
not make PJSIP accounts become CHAN_SIP accounts by itself.

## 4. A shared template with an exact-model exception

**Goal:** give a team consistent policy while using one model's extra keys.

1. Create a broad compatible template `Desk baseline` with common clock/display
   policy and a small key layout that fits the selected family.
2. Create `Reception model` restricted to the exact reception model, with an
   explicit replacement key layout that uses its supported capacity.
3. Create group `Reception`, attach broad then exact layers, and add the relevant
   logical extensions. Leave unrelated fields inherited in the exact layer.
4. Inspect Explain for a baseline phone and the reception model. Confirm the
   exact layer is skipped for the former and applied last for the latter.
5. Preview both, then apply to one physical device of each model.

**Expected:** common policy is shared while explicit model values override it.
A non-empty narrower key list replaces the earlier list; it is not appended.

## 5. Default template fallback

**Goal:** give ungrouped phones sensible defaults without layering defaults below
every group.

Create one family default and, if needed, a more specific exact-model default in
Settings. Add an ungrouped test phone: Explain should select the most-specific
matching default. Then place it in a group with a compatible layer: Explain should
use the group stack. An empty or entirely incompatible group falls back to the
site default. Remove equally specific competing defaults if the resolver rejects
the ambiguity. Preview after each saved policy change.

## 6. A shared room phone without Active Directory

**Goal:** label extension `4199` as `Meeting Room` without inventing a person.

Create the endpoint in FreePBX, select manual/shared identity for that logical
extension and set its intended caller/short labels. Bind a physical phone. Preview
its title, line label and any BLF references from a second phone. Replace the
physical device later through a reviewed assignment change; keep the logical
extension and manual identity. No LDAP source or reader credential is required.

## 7. An AD person with an additional extension

**Goal:** one accepted person owns a primary extension and a second associated
device without duplicating authority.

Configure a source using your real LDAP scope and a dedicated least-privilege
reader. Test it, sync, and review accepted identities and mapping conflicts. Bind
the primary extension in AD-primary mode. Bind the additional extension to the
same accepted person in linked mode. Keep full caller name separate from short
phone/BLF formatting. Use explicit field overrides only for intentional exceptions.

Review and execute an identity refresh. Inspect each destination result and any
missing-account or ownership exclusions. Publish/rebuild as indicated by the
workflow and observe both devices. A source sync, accepted identity projection and
handset display are distinct results.

## 8. A handset directory without a browser directory

**Goal:** publish a staff book to phones while keeping the employee web page off.

In the phonebook designer, select the accepted source entries or manual contacts,
choose fields/grouping, preview, and publish `Staff`. Set it as the appropriate
site/template phonebook, then rebuild a canary. Open the phone directory, search by
an allowed name/username/number field and place a call. Leave Web Directory
disabled. Publishing handset phonebooks does not require enabling a browser page.

## 9. Employee web directory with limited fields

**Goal:** expose `Staff` to an explicit internal audience with no hidden-field leak.

1. Publish the required book first.
2. Enable Web Directory, select that published book, and choose only the fields
   employees should see. Set a generic title such as `Company Directory`.
3. Enter actual allowed client CIDRs. If a reverse proxy is used, configure only
   its exact trusted addresses and verify the actual connecting-address path.
4. Leave Preferred URL blank to use the normal PBX link, or enter an HTTPS URL
   after separately configuring DNS, TLS and the web server.
5. Review any audience expansion and save. Test Table, People, search, selection,
   print and XLSX from an allowed client. Inspect the workbook columns.
6. Test page/data/export routes from a denied client as well.

**Expected:** all routes enforce the same audience and visible-field policy. A
hostname in settings does not publish a new web server or certificate.

## 10. A wallpaper catalog for several phone sizes

**Goal:** reuse one source image with model-specific outputs.

Upload an original to a wallpaper catalog `Office background`, choose compatible
profiles, crop/adjust each output and build. Confirm the catalog reports complete
current outputs before assigning it in Settings or a template. Preview a phone
from each target profile and verify the selected hosted/TFTP path. Apply and check
the actual display. Keep the original source for later crop changes.

If a loose image appears in storage, it does not automatically become a catalog
choice. Use the catalog's supported import/repair workflow and verify ownership;
do not manufacture extra choices by renaming retained aliases.

## 11. Enroll a branch and replicate selected policy

**Goal:** give `Branch` the same template and wallpaper without copying local
credentials or PBX identity.

1. Install supported FreePBX on the branch and establish network/SSH reachability.
2. Save its Site record; use Install/update with temporary administrator access
   and a separately verified host key. Confirm version plus API/SSH readiness.
3. Enroll its TFTP service separately and test a local branch phone.
4. Replicate the selected wallpaper and required firmware/locale/timezone first,
   then the chosen phonebook snapshot, templates/groups and Settings fields.
5. Review each destination's applied/waiting/paused/conflict state. A missing
   dependency retains intended policy and can resolve on later scheduler ticks.
6. Preview and apply a branch canary only after its dependencies are ready.

**Expected:** portable managed objects arrive with ownership. LDAP passwords,
local signing keys, PBX addressing and Asterisk activation state remain local.

## 12. Local edit versus hard-locked synchronization

**Goal:** choose whether a receiver may customize a synchronized policy.

Create a configuration-sync job with only the intended object/fields. Without a
hard lock, make a deliberate receiver edit and inspect the resulting pause. Resume
only when the source should control it again. For web-directory settings that
must stay centralized, use the supported hard lock and confirm local saves are
rejected. Releasing management preserves current content; it is not a delete.
Do not use force to dismiss unexplained ownership or revision conflicts.

## 13. Replace a failed phone without losing identity

**Goal:** move extension `4101` to a replacement MAC.

Record the current safe assignment and review the guided move/replacement plan.
Choose the replacement's exact model and MAC, inspect affected old/new assignments
and execute the current plan. Preview the replacement's resolved identity,
template and compatible assets; publish and test it. If reversal is needed, create
a new reviewed move/swap with current revisions. An old plan is not reusable after
the assignments change, and assignment reversal does not prove handset recovery.

## 14. First BLF link between two sites

**Goal:** show extension state across two already connected PBXs.

First prove bidirectional calls over the existing inter-site PJSIP trunks. Select
the actual peer endpoints in Sites, preview the BLF mesh, and inspect the planned
device-state publication relationships. Apply the reviewed two-phase workflow.
Configure a compatible BLF key on a canary, then observe idle, ringing and busy
states while placing real calls. A healthy trunk alone does not prove publication
subscriptions, hint mapping or the phone's BLF display.

## 15. A safe read-only developer preview

On a disposable installed PBX, run PHP as the appropriate local service/operator
identity. This example prints only a safe result summary; it neither publishes
files nor prints generated SIP credentials:

```bash
php -r 'require "/etc/freepbx.conf"; $r=FreePBX::Ucmprovisioner()->rebuild("ext:4101", false); echo json_encode(["ok"=>$r["ok"]??false,"plan_hash"=>$r["plan_hash"]??null], JSON_PRETTY_PRINT), PHP_EOL;'
```

An extension selector needs the `ext:` prefix; a bare number is a phone record ID.
Use the authenticated UI for reviewed Apply. Do not pipe complete preview output
into a public log. The CLI helper's apply option previews and immediately applies
its newly computed hash; it is not an approval of a separate previously saved plan.

## 16. Upgrade and first failure diagnosis

Verify the new release checksum and retain a coherent system backup. Install the
TGZ through the normal module installer, check its output/version, and refresh the
local signature after final files are in place. Confirm private credential
readiness and one representative phone before scheduling broad changes.

For a failed build, read its exact error and receipt first. Distinguish missing
assets, changed plan hash, permission/host-key rejection and Asterisk preflight.
Fix the specific prerequisite, preview again and apply only the current plan.
Never delete an unfamiliar storage lock or replay an uncertain remote mutation
because it has been waiting a long time. See [Troubleshooting](TROUBLESHOOTING.md).
