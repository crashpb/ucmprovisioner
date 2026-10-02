# Cisco enterprise softkey and Recents evidence

Reviewed 2026-07-30. This note covers Cisco **enterprise** firmware. It does not
use Multiplatform Phone (MPP/3PCC) documentation.

## Configuration boundaries

- `softkeys.xml` controls supported call-state actions. It does not define every
  label or button that firmware may show.
- Template Manager therefore keeps two explicit layers: the validated emitted
  policy and a model/state-scoped handset observation. An exact observed row is
  preview metadata only; it never supplies a keyID, tag, eventID or helpID and
  cannot change generated XML.
- 78xx `recentsSoftKey` is a product setting, not a `typeSoftKey` definition.
- Cisco documents idle **Navigation Up** as a built-in route to Recents on both
  [7800](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/english/user-guide/PA2D_BK_I89BE38F_00_ip_phone-7800-series-user-guide/PA2D_BK_I89BE38F_00_ip_phone-7800-series-user-guide_chapter_011.html)
  and [8800](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/english/user-guide/P881_BK_C3A802A0_00_cisco-ip-phone-8800-user_guide/P881_BK_C3A802A0_00_cisco-ip-phone-8800-user_guide_chapter_011.html)
  enterprise phones.
- The current [7800 administration guide](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/english/admin-guide/pa2d_b_7800-series-admin-guide-cucm/pa2d_b_7800-series-admin-guide-cucm_chapter_01011.html)
  lists Recents as a supported configurable softkey, defaults the product
  setting to enabled, marks Join and Video Mode unsupported, and notes that
  iDivert is displayed as Decline from firmware 10.3(1).
- The current [8800 administration guide](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/english/adminguide/P881_BK_C136782F_00_cisco-ip-phone-8800_series/P881_BK_C136782F_00_cisco-ip-phone-8811-8841_chapter_01011.html)
  documents Recents as built-in phone behavior and defines the selected-line
  call-history and actionable incoming-call-alert product settings.
- The on-wire [SEPMAC schema reference](https://usecallmanager.nz/sepmac-cnf-xml.html)
  defines `phoneServices/provisioning`: `0` uses embedded services, `1` uses
  direct URLs, and `2` uses both. Therefore mode `1` ignores embedded
  `Application:Cisco/MissedCalls`, `ReceivedCalls`, and `PlacedCalls` entries
  even if they remain in the SEP file.

## Enterprise 7800 release review

The following consecutive published releases were checked through their full
release-note text for `Recent`, `Navigation`, and `softkey`:

1. [14.4(1)SR3](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-4-1-SR3/p2ad_b_7800-rn-1441sr3.html)
2. [14.4(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-4-1-SR2/p2ad_b_7800-rn-1441sr2.html)
3. [14.4(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-4-1-SR1/p2ad_b_7800-rn-1441sr1.html)
4. [14.4(1)](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-4/pa2d_b_7800-rn-1441.html)
5. [14.3(1)SR3](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-3-1-SR3/p2ad_b_7800-rn-1431sr3.html)
6. [14.3(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-3-1SR2/p2ad_b_7800-rn-1431sr2.html)
7. [14.3(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-3-1-SR1/p2ad_b_7800-rn-1431sr1.html)
8. [14.3(1)](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-3-1/p2ad_b_7800-rn-1431.html)
9. [14.2(1)SR4](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/14-2-1-SR4/p2ad_b_7800-rn-1421sr4.html)
10. [14.2(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/firmware/14_2_1SR2/Cisco-IP-Phone-7800-Release-Notes-for-Firmware-14-2-1-SR2.html)
11. [14.2(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/7800-series/firmware/14_2_1SR1/Cisco-IP-Phone-7800-Release-Notes-for-Firmware-14-2-1SR1.html)

None documents a change that removes or remaps Recents or Navigation Up. The
softkey-related limitation repeated in applicable notes is that Video Mode
cannot be configured for 7800 phones; this supports blocking `VidMode`, not
removing Recents. SR3, SR2, and SR1 of 14.4 explicitly report no new or changed
features.

## Enterprise 8800 release review

The same `Recent`, `Navigation`, and `softkey` check was run over the eleven
most recent 8800 enterprise release notes:

1. [14.4(1)SR3](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-4-1SR3/p881_b_8800-rn-1441sr3.html)
2. [14.4(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-4-1SR2/p881_b_8800-rn-1441sr2.html)
3. [14.4(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-4-1SR1/p881_b_8800-rn-1441sr1.html)
4. [14.4(1)](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-4/p881_b_8800-rn-1441.html)
5. [14.3(1)SR3](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-3-1SR3/p881_b_8800-rn-1431sr3.html)
6. [14.3(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-3-1SR2/p881_b_8800-rn-1431sr2.html)
7. [14.3(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-3-1SR1/p881_b_8800-rn-1431sr1.html)
8. [14.3(1)](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-3-1/p881_b_8800-rn-1431.html)
9. [14.2(1)SR3](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-2-1SR3/Cisco-IP-Phone-8800-Release-Notes-for-Firmware-14-2-1-SR3.html)
10. [14.2(1)SR2](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-2-1SR2/Cisco-IP-Phone-8800-Release-Notes-for-Firmware-14-2-1-SR2.html)
11. [14.2(1)SR1](https://www.cisco.com/c/en/us/td/docs/voice_ip_comm/cuipph/8800-series/firmware/14-2-1SR1/Cisco-IP-Phone-8800-Release-Notes-for-Firmware-14-2-1SR1.html)

None documents removal or remapping of idle Navigation Up or Recents. The only
repeated softkey restriction is that Video Mode cannot be configured through a
softkey template. Cisco's current 8800 administration guide still documents
Recents, the selected-line call-history scope, Simplified New Call UI, and the
actionable incoming-call alert as separate controls. This is why the editor
does not mislabel Call, ABC, Ignore, Active Calls, Speed dials, or Show Details
as serialized permanent softkeys.

## Fleet evidence and resulting default

During fleet validation, a generated CP-7841 configuration for extension 1001 had
`recentsSoftKey=1`, a valid `softkeys.xml`, and all three embedded call-history
applications, but `phoneServices/provisioning=1`; firmware therefore ignored
those embedded applications. The comparison CP-8845 configuration for extension 1002 used
mode `2`.

The generator now defaults every Cisco XML phone to mode `2`, while retaining
mode `1` as an explicit operator choice. Templates that omit this setting
inherit mode `2` from effective site defaults; no template-row migration is
required.
