# Device and compatibility support

This document describes the model catalog shipped with UCM Provisioner 1.0.0.
`lib/ModelCatalog.php` is the executable source of truth. If this guide and a later
catalog revision differ, the catalog used by the installed module controls what can
be selected and generated.

“Supported” means the module has an explicit configuration/capability profile. It
does not mean every handset firmware version has been tested or that a vendor still
supports the device. Canary the exact firmware used in production.

## Generated phone configuration families

| Family | Models | Primary output |
|---|---|---|
| Cisco Enterprise XML | 7811, 7821, 7841, 7861, 7906, 7911, 7941, 7942, 7945, 7961, 7962, 7965, 7970, 7971, 7975, 8811, 8821, 8831, 8832, 8841, 8845, 8851, 8861, 8865, 8941, 8945, 8961, 9951, 9971 | `SEP<MAC>.cnf.xml` |
| Cisco legacy CNF | 7940, 7960 | `SIP<MAC>.cnf`, shared `SIPDefault.cnf` and `syncinfo.xml` |
| Grandstream desk/hotel | GXP2130, GXP2140, GXP2170, GRP2604, GRP2614, GRP2634, GHP630, GHP630W, GHP631, GHP631W | `cfg<mac>.xml` |
| Grandstream DECT system | DP750, DP752, DP755 bases; DP720, DP722, DP725, DP730, DP735 handsets | base `cfg<mac>.xml`; handset policy is carried by the base |
| Yealink desk/video | T20P, T21P E2, T22P, T23G, T26P, T27G, T28P, T29G, T30, T30P, T31, T31P, T31G, T32G, T33P, T33G, T40P, T40G, T41S, T42S, T42U, T43U, T46G, T46S, T46U, T48G, T48S, T48U, T53, T53W, T54W, T57W, T58A, VP59 | lowercase `<mac>.cfg` |
| Yealink DECT system | W60B, W70B bases; W80B, W90B radios; W90DM manager; W53H, W56H, W57R, W59R, W71H, W73H, W74H, W78H handsets | base/system configuration; handset policy is carried by its system |

Cisco 7950 is explicitly quarantined: no verified SIP model/configuration profile is
available. Unknown models are not silently treated as a nearby device.

Some media profiles list additional phones that share a verified image canvas. A
media-only listing does not make that phone fully provisionable; it becomes a full
phone only when it also has a model/capability row.

## Endpoint driver and transport

| Family | Driver choices | Site defaults | Notes |
|---|---|---|---|
| Cisco XML | CHAN_SIP | Cisco XML transport and port | Required for USECALLMANAGER-specific behavior. UDP/TCP/TLS must agree with the FreePBX peer and generated XML. |
| Cisco CNF | CHAN_SIP | Separate Cisco CNF UDP port | 7940/7960 SIP firmware is treated as UDP-only by the module. |
| Yealink | CHAN_SIP or PJSIP | Non-Cisco driver/transport/port | Per-phone override is permitted and shown with its inheritance source. |
| Grandstream | CHAN_SIP or PJSIP | Non-Cisco driver/transport/port | Per-phone override is permitted and shown with its inheritance source. |

`EndpointProfile` resolves one tuple used by FreePBX, generator, status and control
commands. A driver mismatch blocks generation instead of creating a file for an
endpoint that cannot register.

## Cisco firmware/config profiles

Firmware selection and template applicability use precise load families:

| Profile | Models | Cisco load pattern |
|---|---|---|
| `cisco:p0s3` | 7940, 7960 | `P0S3-….loads` plus the required P0S3/P003 companions |
| `cisco:sip11` | 7906, 7911 | `SIP11…loads` |
| `cisco:sip41` | 7941, 7961 | `SIP41…loads` |
| `cisco:sip42` | 7942, 7962 | `SIP42…loads` |
| `cisco:sip45` | 7945, 7965 | `SIP45…loads` |
| `cisco:sip70` | 7970, 7971 | `SIP70…loads` |
| `cisco:sip75` | 7975 | `SIP75…loads` |
| `cisco:sip78xx` | 7811, 7821, 7841, 7861 | `sip78xx…loads` |
| `cisco:sip88xx` | 8811, 8841, 8851, 8861 | `sip88xx…loads` |
| `cisco:sip8845_65` | 8845, 8865 | `sip8845_65…loads` |
| `cisco:sip8821` | 8821 | `sip8821…loads` |
| `cisco:sip8831` | 8831 | `sip8831…loads` |
| `cisco:sip8832` | 8832 | `sip8832…loads` |
| `cisco:sip894x` | 8941, 8945 | supported SIP8941/8945 load patterns |
| `cisco:sip8961` | 8961 | `sip8961…loads` |
| `cisco:sip9951` | 9951 | `sip9951…loads` |
| `cisco:sip9971` | 9971 | `sip9971…loads` |

One firmware profile is not substituted for a visually similar model. In
particular, the 8845/8865 video load is not offered to an audio-only 8861.

Yealink and Grandstream firmware profiles are model-scoped. Their uploaded ROM or
binary is trusted within the operator-selected scope because vendor filenames do
not always provide a stable complete compatibility grammar.

## Cisco timezone runtimes

Cisco timezone compatibility follows the phone runtime architecture:

| Profile | Payload | Models |
|---|---|---|
| Standard Java phones | `tzupdater.jar` | 7906, 7911, 7941, 7942, 7945, 7961, 7962, 7965, 7970, 7971, 7975, 8961, 9951, 9971 |
| IBM J9 phones | `j9-tzdata.jar` | 7811, 7821, 7841, 7861, 8811, 8821, 8831, 8832, 8841, 8845, 8851, 8861, 8865 |
| Lodown / RTL / Gumbo phones | `TzDataCSV.csv` | 3905, 3911, 3951, 6901, 6911, 6921, 6941, 6945, 6961, 8941, 8945 |

The 39xx/69xx entries in the last row define timezone compatibility even when a
complete configuration profile for that phone is not present in the 1.0.0 phone
catalog. They must not be treated as independently provisionable phones.

`tzupdater.ver` is optional evidence. It is not downloaded by phones as a substitute
for their runtime payload. The module can retain multiple payload revisions and
assign one default per runtime; a template can inherit or select another compatible
revision.

For EOL Standard JVM phones, use the latest payload that actually supports that
runtime. A current `j9-tzdata.jar` working on 78xx/88xx does not imply the same DST
data exists in an old `tzupdater.jar` for 7941-era phones.

## Cisco locale companions

Locale bundle selection is load-family specific. The model catalog declares:

| Firmware family | User-interface JAR | Tone file |
|---|---|---|
| SIP11 | `tc-sip.jar` | `g3-tones.xml` |
| SIP41 / SIP42 | `mk-sip.jar` | `g3-tones.xml` |
| SIP45 | `be-sip.jar` | `g3-tones.xml` |
| SIP70 / SIP75 | `td-sip.jar` | `g3-tones.xml` |
| 78xx | `sp-sip.jar` | `g3-tones.xml` |
| audio 88xx | `sl-be-sip.jar` | `g3-tones.xml` |
| 8845/8865 video | `sl-bev-sip.jar` | `g3-tones.xml` |
| 8941/8945 | `gb-sccp-sip.jar` | `g3-tones.xml` |
| 8961 | `gh-sip.jar` | `g3-tones.xml` |
| 9951 | `lk-sip.jar` | `g4-tones.xml` |
| 9971 | `gd-sip.jar` | `g4-tones.xml` |

The configured user-locale and network-locale directories determine the final TFTP
paths. A generic “Cisco locale” option is deliberately avoided because the phone
firmware expects different JAR names.

## Wallpaper and display profiles

### Cisco XML List.xml profiles

| Directory/profile | Full image | Thumbnail | Models |
|---|---:|---:|---|
| `95x34x1` | 95×34 | 23×8 | 7906, 7911; 1-bit/2-tone preview |
| `320x196x4` | 320×196 | 80×49 | 7941, 7942, 7961, 7962; 4-bit/16-level grayscale preview |
| `320x212x16` | 320×212 | 80×53 | 7945, 7965 |
| `320x212x12` | 320×212 | 80×53 | 7970, 7971 |
| `320x216x16` | 320×216 | 80×54 | 7975 |
| `240x320x24` | 240×320 | 117×117 | 8821 |
| `800x480x24` | 800×480 | 139×109 | 8841, 8845, 8851, 8861, 8865 |
| `640x480x24` | 640×480 | 123×111 | 8941, 8945, 8961, 9951, 9971 |

The module generates one List.xml and linked full/thumbnail files per required Cisco
directory from the catalog manifest. Models absent from the table are not assigned a
guessed Cisco background directory.

### Standard PBX-hosted wallpaper profiles

- Yealink 320×240: T32G, T33G, T33P.
- Yealink 480×272: T29G, T46G, T46S, T46U, T54W.
- Yealink 800×480: T48G, T48S, T48U, T57W.
- Yealink 1024×600: T58A.
- Yealink 1280×800: VP59.
- Grandstream 320×240 profile: GXP2130/GXP2135 and selected GRP2612/2613/2614/2624/2634 models.
- Grandstream 480×272 profile: GXP2140/GXP2160/GXP2170 and selected GRP2615/2616/2636 models.
- Grandstream 480×320: GHP630/630W/631/631W.
- Grandstream 1024×600: GRP2670.
- Grandstream 1280×720: GRP2650.

Some models in these canvas groups are media-only in 1.0.0, as noted above. A
catalog can render their size but the phone cannot be added until a complete model
profile exists.

Cisco 7940/7960 use the non-standard legacy path: a direct query-free plain-HTTP
90×56 Windows 256-color BMP URL. They do not consume Cisco XML List.xml catalogs.

## Keys, accounts and DECT

ModelCatalog records exact verified account, appearance and programmable-key
capacity. The editor uses it to prevent a template intended for a high-capacity
model from silently overflowing a smaller phone. Expansion module type/count is
also model-specific.

Grandstream GHP630/631 profiles expose:

- two SIP accounts;
- six programmable/context keys;
- ten separate digit speed-dial keys;
- documented key disable/None and supported key modes;
- built-in firmware 1.0.1.50 icon codes only—arbitrary uploaded key icons are not
  supported by the verified parameter set;
- center-clock show/hide and date-format controls; and
- no documented replacement of the center clock with arbitrary text.

Grandstream DP750/DP752 support ten accounts and five handsets in the verified
profile. DP755 uses its separate version-2 alias configuration with twenty account
slots and up to ten handset entries. Handset names are bounded independently from
account caller identity.

Yealink W60B supports eight accounts/handsets; W70B supports ten accounts/handsets.
W80/W90 multi-cell components use their manager/radio roles and are not rendered as
standalone desk phones. Handsets inherit service/directory behavior through their
base/system.

## Labels and directory behavior

The module keeps these fields independent:

- phone/device status-line label;
- physical line/account label;
- SIP caller display name;
- BLF key label;
- DECT handset name; and
- directory contact text.

Each generator enforces its own verified field limit. A single global 11-character
rule is not applied across all Cisco XML phones. Cisco firmware may truncate or add
fixed decorations on a particular surface; the model-aware preview distinguishes
that from the emitted identity.

Cisco XML uses vendor XML directory/search responses. Cisco CNF uses the smaller
CMXML subset. Yealink and Grandstream use their remote XML phonebook profiles.

## Phone control commands

| Family | Reload/restart | Reboot | Factory reset |
|---|---|---|---|
| Cisco XML | `cisco-restart` service-control SIP NOTIFY | `cisco-reset` SIP NOTIFY | handset admin menu only |
| Cisco CNF | `syncinfo.xml` revision plus `cisco-check-cfg` NOTIFY | same path with reboot intent when idle | handset admin menu only |
| Yealink | `reboot-yealink`/check-sync NOTIFY | same supported NOTIFY path | phone web UI/manual |
| Grandstream | no verified generic SIP NOTIFY path | no verified generic SIP NOTIFY path | phone/base web UI or power cycle |

Commands use the endpoint's actual driver. During a transport/extension transition,
the module can target the previous live registration so the phone receives the new
configuration before its endpoint route changes.

## Adding a model safely

A new model requires all applicable facts in one catalog change:

1. catalog identity and provisioning family;
2. capability/role/account/key/DECT profile;
3. firmware/config profile and filename rules;
4. media/wallpaper profile if verified;
5. timezone and locale profile for Cisco XML;
6. VLAN and phone-command behavior;
7. template/editor applicability; and
8. generator, validation and regression-test coverage.

Adding only a name to a dropdown is not supported.
