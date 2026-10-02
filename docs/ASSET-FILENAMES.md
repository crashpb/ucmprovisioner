# Generated asset filenames

The rc23 local candidate replaces long module-generated public basenames with
one type letter, 12 hexadecimal identity digits, and an extension. Ordinary
public names are 17 characters; wallpaper thumbnails are 20. Firmware
declaration JSON names are 18. This is the module's naming policy, not a claimed
Cisco maximum filename length.

Full SHA-256 values remain in the asset registry, manifests and transfer
receipts. They are still used to verify content. An existing immutable compact
name containing different bytes is a conflict, even when overwrite was selected.
Short names do not mean truncated integrity checks or shorter authentication tokens.

## Producer and consumer inventory

| Surface | New basename | Producer and consumers |
|---|---|---|
| Cisco dial plan | `d<12hex>.xml` | `DialTemplateGenerator::filenameFor`; Cisco XML and Provisioner file set use the same rendered content |
| Template softkeys | `s<12hex>.xml` | `TemplateEngine::resolvePhone`; `SoftkeyPolicyGenerator::softkeysXml`; Provisioner publication |
| Template feature policy | `f<12hex>.xml` | TemplateEngine and `SoftkeyPolicyGenerator::featurePolicyXml`; Provisioner publication |
| Legacy template-ID helper | `k<12hex>.xml`, `q<12hex>.xml` | `SoftkeyPolicyGenerator::filenameFor`; stable template identity |
| Java/J9/CSV timezone aliases | `a<12hex>.jar`, `j<12hex>.jar`, `c<12hex>.csv` | `CiscoTimezoneBundleManager::publishedFilename`; TftpStore alias publication, TemplateEngine resolution, AssetCatalog and AssetFileInspector |
| Wallpaper full image | `w<12hex>.png` or `.jpg` | `TftpStore::wallpaperOutputNames`; manifest readers, profile publishing, HTTP delivery, List.xml, references and retirement |
| Wallpaper thumbnail | `w<same12hex>-tn.png` | Same owner/profile/content identity as the full image; List.xml pairing |
| Wallpaper/catalog original | `o<12hex>.png` or `.jpg` | TftpStore source paths and special catalog source validation |
| New legacy Cisco BMP catalog | `g<12hex>.bmp` | `specialCatalogSave`; stable catalog identity; save/rename/replication readers |
| Hosted background upload | `h<12hex>.<ext>` | `mediaAssetOpLocal`; MediaChunkTransfer and HTTP inventory |
| Hosted ringtone upload | `r<12hex>.<ext>` | `mediaAssetOpLocal`; API/HTTP copying |
| Cisco ringtone upload | `r<12hex>.raw` or `.rwb` | Media upload requests; TftpStore validation, registry label, Ringlist, selected replication |
| New/duplicated phonebook | `p<12hex>.xml` | `DirectoryService::savePhonebook` and `duplicatePhonebook`; publication exports the stored filename |
| Firmware compatibility declaration | `v<12hex>.json` | `FirmwareService::declarationFilename`; paired upload, inventory and replication; original binary name remains in JSON |

Wallpaper identities include catalog owner, output profile and both full payload
hashes. Changing either image changes the pair. Separate catalogs cannot silently
claim each other's generated files. Dial plans and policies use rendered bytes,
so equivalent content can share a name without relying on template display names.

New custom phonebook filenames up to 20 characters remain supported. Empty or
long filenames for a new locally authored book receive the compact name.
Copying a book gives it a new identity and filename. Human catalog/template/book
names and ringtone labels remain readable.

## Upgrade behavior

- Background choice authority comes from the catalog manifest, not from a filename
  pattern or a valid image found on disk. Only enabled current outputs whose full
  hashes match are offered for new assignments. Physical inventory retains loose,
  disabled and compatibility files for existing references, diagnosis and explicit
  adoption/retirement; it does not promote them to current catalog choices.
- Settings and Templates use the same catalog-authorized hosted choice list.
  Older peers without that list require an update; selectors preserve saved URLs
  without falling back to their raw file inventory. Cisco template choices also
  require a current catalog output. Existing served URLs are not deleted or changed.
- Regenerating phone configurations emits compact dial-plan and policy references.
- Timezone inventory republishes retained payloads under compact aliases; pins
  continue to use full asset identities. Legacy peer aliases are still readable.
- Publishing wallpaper variants uses compact outputs and records their complete
  ownership/hash metadata. Old aliases remain readable and reference-protected.
- Hosted catalog uploads preserve their existing `w<12hex>` filename. Standalone
  image uploads receive `h<12hex>` names. Catalog save and rename reject a receiver
  that returns a different filename before committing the catalog manifest.
- For catalogs affected by the former double-renaming bug, open the catalog and
  choose **Repair hosted filenames**. It verifies every enabled hosted image's
  full hash and dimensions, then republishes matching bytes under the catalog's
  recorded name. It preserves crops, sources, settings and existing URLs; it does
  not regenerate artwork or overwrite conflicting files. Repeated repair skips
  verified files. If a response is lost, readback must confirm the destination;
  otherwise repair stops and retains the storage lock for reconciliation.
- New uploads, catalogs and local phonebooks receive short names. Existing special
  catalog public names and imported phonebook filenames are retained, since already
  published templates/devices may reference them. A read or ordinary upgrade does
  not silently rename those existing assets.
- Existing firmware declaration sidecars remain readable. A compact declaration
  takes precedence when both formats exist for the same binary. Its JSON target
  is validated against its generated basename before reuse.
- API and SFTP copies preserve the source's published names and full content
  hashes; they do not independently invent destination names. Hosted chunked
  transfers and ordinary writes reject conflicting compact immutable names.
- Ringtone indexes preserve display labels independently of filenames, including
  selected cross-site replication and later index rebuilds.

## Exact names and internal identifiers

These were audited and deliberately retain their required identities:

- Device boot names: `SEP<MAC>.cnf.xml`, `SIP<MAC>.cnf`, `<mac>.cfg`, `cfg<mac>.xml`.
- Fixed files requested by firmware: `SIPDefault.cnf`, `syncinfo.xml`, `List.xml`,
  `Ringlist.xml`, `Ringlist-wb.xml`, and model-defined locale companions.
- Vendor firmware load names, manifests and embedded dependencies, including case.
  Renaming a signed manifest dependency breaks loading; the module adds no hash
  prefix to those binary names.
- Canonical timezone import payload names and internal retained bundle/blob paths.
  Only the public timezone aliases are shortened; full private storage identities
  remain available for version selection and content validation.
- Existing legacy directory migration names, URL authentication tokens, database
  keys, transaction IDs, receipt paths, package filenames and release checksums.
  These are compatibility/security/storage identifiers, not generated asset names
  sent to a phone in place of the compact names above.

Source tests verify naming, reference consistency, legacy readers, ownership,
collision refusal and copying. They do not establish physical handset acceptance.
