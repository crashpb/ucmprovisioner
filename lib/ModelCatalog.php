<?php
/**
 * ModelCatalog — the single source of truth for `ucmp_models` seed data.
 *
 * Used by both install.php (fresh installs) and Ucmprovisioner::ensureModelCatalog()
 * (a version-checked resync that runs on every page load so catalog additions
 * reach already-installed sites without a full uninstall/reinstall).
 *
 * Bump CATALOG_VERSION whenever a row is added/changed so existing installs
 * pick it up. Catalog-owned model capabilities are resynchronised; operator
 * notes are retained.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

class ModelCatalog
{
    public const CATALOG_VERSION = 13;

    /** [vendor, model, family, line_keys, supports_softkeys, supports_featurepolicy, kem_support, default_firmware, notes] */
    public const CATALOG = [
        // ---- Cisco cisco_xml (SEP<MAC>.cnf.xml): 78xx / 88xx / 79x1 -------------
        ['Cisco', '7811', 'cisco_xml', 1, 1, 0, 0, null, null],
        ['Cisco', '7821', 'cisco_xml', 2, 1, 0, 0, null, null],
        ['Cisco', '7841', 'cisco_xml', 4, 1, 0, 0, null, 'Cisco 7800-series XML SIP configuration'],
        ['Cisco', '7861', 'cisco_xml', 16, 1, 0, 0, null, null],
        ['Cisco', '7906', 'cisco_xml', 1, 1, 0, 0, null, 'Uses the SIP11 firmware family'],
        ['Cisco', '7911', 'cisco_xml', 1, 1, 0, 0, null, 'Uses the SIP11 firmware family'],
        ['Cisco', '8811', 'cisco_xml', 5, 1, 0, 0, null, null],
        ['Cisco', '8821', 'cisco_xml', 6, 1, 0, 0, null, 'Wireless handset with a dedicated SIP8821 firmware family'],
        ['Cisco', '8841', 'cisco_xml', 5, 1, 0, 0, null, null],
        ['Cisco', '8845', 'cisco_xml', 5, 1, 0, 0, null, 'Video-capable Cisco 8800-series model'],
        ['Cisco', '8851', 'cisco_xml', 5, 1, 0, 1, null, null],
        ['Cisco', '8861', 'cisco_xml', 5, 1, 0, 1, null, 'Audio-only model; supports Cisco 8800-series key expansion modules'],
        ['Cisco', '8865', 'cisco_xml', 5, 1, 0, 1, null, 'Video-capable Cisco 8800-series model'],
        ['Cisco', '8831', 'cisco_xml', 0, 1, 0, 0, null, 'Conference phone; Useragent reports as "8831G". No dedicated line keys.'],
        ['Cisco', '8832', 'cisco_xml', 0, 1, 0, 0, null, 'Conference phone with a dedicated SIP8832 firmware family'],
        ['Cisco', '7941', 'cisco_xml', 2, 1, 0, 0, null, null],
        ['Cisco', '7942', 'cisco_xml', 2, 1, 0, 0, null, null],
        ['Cisco', '7945', 'cisco_xml', 2, 1, 0, 0, null, null],
        ['Cisco', '7961', 'cisco_xml', 6, 1, 0, 1, null, null],
        ['Cisco', '7962', 'cisco_xml', 6, 1, 0, 1, null, null],
        ['Cisco', '7965', 'cisco_xml', 6, 1, 0, 1, null, null],
        ['Cisco', '7970', 'cisco_xml', 8, 1, 0, 1, null, null],
        ['Cisco', '7971', 'cisco_xml', 8, 1, 0, 1, null, null],
        ['Cisco', '7975', 'cisco_xml', 8, 1, 0, 1, null, null],
        ['Cisco', '8941', 'cisco_xml', 4, 1, 0, 0, null, 'Uses the SIP8941/8945 firmware family'],
        ['Cisco', '8945', 'cisco_xml', 4, 1, 0, 0, null, 'Video-capable; uses the SIP8941/8945 firmware family'],
        ['Cisco', '8961', 'cisco_xml', 5, 1, 1, 1, null, 'Feature Policy by default; softKeyControl enables a managed softKeyFile'],
        ['Cisco', '9951', 'cisco_xml', 5, 1, 1, 1, null, 'Feature Policy by default; softKeyControl enables a managed softKeyFile'],
        ['Cisco', '9971', 'cisco_xml', 6, 1, 1, 1, null, 'Feature Policy by default; softKeyControl enables a managed softKeyFile'],

        // ---- Cisco cisco_cnf (SIPDefault.cnf + SIP<MAC>.cnf): 7940/7960 ONLY ---
        ['Cisco', '7940', 'cisco_cnf', 2, 0, 0, 0, null, 'Uses SIP<MAC>.cnf and SIPDefault.cnf, not the Cisco XML schema'],
        ['Cisco', '7960', 'cisco_cnf', 6, 0, 0, 0, null, 'Uses SIP<MAC>.cnf; some firmware reports a SEP device name during registration'],

        // ---- Grandstream ---------------------------------------------------------
        ['Grandstream', 'DP750', 'grandstream', 0, 0, 0, 0, null, 'DP75x DECT base; up to 10 SIP accounts and 5 handsets'],
        ['Grandstream', 'DP752', 'grandstream', 0, 0, 0, 0, null, 'DP75x DECT base; up to 10 SIP accounts and 5 handsets'],
        ['Grandstream', 'DP755', 'grandstream', 0, 0, 0, 0, null, 'DP75x DECT base; firmware-specific template supports expanded account set'],
        ['Grandstream', 'DP720', 'grandstream', 0, 0, 0, 0, null, 'DECT handset — provisions through a DP75x base, not directly'],
        ['Grandstream', 'DP722', 'grandstream', 0, 0, 0, 0, null, 'DECT handset — provisions through a DP75x base, not directly'],
        ['Grandstream', 'DP725', 'grandstream', 0, 0, 0, 0, null, 'DECT handset — provisions through a DP75x base, not directly'],
        ['Grandstream', 'DP730', 'grandstream', 0, 0, 0, 0, null, 'DECT handset — provisions through a DP75x base, not directly'],
        ['Grandstream', 'DP735', 'grandstream', 0, 0, 0, 0, null, 'DECT handset — provisions through a DP75x base, not directly'],
        ['Grandstream', 'GXP2130', 'grandstream', 3, 0, 0, 0, null, null],
        ['Grandstream', 'GXP2140', 'grandstream', 4, 0, 0, 1, null, null],
        ['Grandstream', 'GXP2170', 'grandstream', 12, 0, 0, 1, null, null],
        ['Grandstream', 'GRP2604', 'grandstream', 5, 0, 0, 0, null, 'Four physical MPKs plus primary line'],
        ['Grandstream', 'GRP2614', 'grandstream', 4, 0, 0, 0, null, null],
        ['Grandstream', 'GRP2634', 'grandstream', 6, 0, 0, 0, null, 'Five physical MPKs plus primary line'],
        ['Grandstream', 'GHP630', 'grandstream', 7, 0, 0, 0, null, 'Hotel phone; six programmable/context keys and ten digit speed-dial keys'],
        ['Grandstream', 'GHP630W', 'grandstream', 7, 0, 0, 0, null, 'Wi-Fi hotel phone; six programmable/context keys and ten digit speed-dial keys'],
        ['Grandstream', 'GHP631', 'grandstream', 7, 0, 0, 0, null, 'Hotel phone; six programmable/context keys and ten digit speed-dial keys'],
        ['Grandstream', 'GHP631W', 'grandstream', 7, 0, 0, 0, null, 'Wi-Fi hotel phone; six programmable/context keys and ten digit speed-dial keys'],

        // ---- Yealink ---------------------------------------------------------------
        ['Yealink', 'T46S', 'yealink', 10, 0, 0, 1, null, 'Supports compatible Yealink expansion modules'],
        ['Yealink', 'T48S', 'yealink', 16, 0, 0, 1, null, 'Supports compatible Yealink expansion modules'],
        ['Yealink', 'T48U', 'yealink', 29, 0, 0, 1, null, 'Supports up to three EXP43 modules; 60 keys per module'],
        ['Yealink', 'T46U', 'yealink', 27, 0, 0, 1, null, 'Supports up to three EXP43 modules; 60 keys per module'],
        ['Yealink', 'T43U', 'yealink', 21, 0, 0, 1, null, 'Supports up to three EXP43 modules; 60 keys per module'],
        ['Yealink', 'T42U', 'yealink', 15, 0, 0, 0, null, null],
        ['Yealink', 'T33G', 'yealink', 4, 0, 0, 0, null, 'Four line keys; firmware 85 or later provisioning family'],
        ['Yealink', 'T33P', 'yealink', 4, 0, 0, 0, null, null],
        ['Yealink', 'T31G', 'yealink', 2, 0, 0, 0, null, null],
        ['Yealink', 'T31P', 'yealink', 2, 0, 0, 0, null, null],
        ['Yealink', 'T31', 'yealink', 2, 0, 0, 0, null, null],
        ['Yealink', 'T30P', 'yealink', 1, 0, 0, 0, null, null],
        ['Yealink', 'T30', 'yealink', 1, 0, 0, 0, null, null],
        ['Yealink', 'T57W', 'yealink', 29, 0, 0, 1, null, 'Supports up to three EXP50 modules; 60 keys per module'],
        ['Yealink', 'T53W', 'yealink', 21, 0, 0, 1, null, 'Supports up to three EXP50 modules; 60 keys per module'],
        ['Yealink', 'T53', 'yealink', 21, 0, 0, 1, null, 'Supports up to three EXP50 modules; 60 keys per module'],
        ['Yealink', 'T58A', 'yealink', 27, 0, 0, 1, null, 'Supports up to three EXP50 modules; 60 keys per module'],
        ['Yealink', 'VP59', 'yealink', 27, 0, 0, 1, null, 'Video phone; supports expansion modules'],
        ['Yealink', 'T46G', 'yealink', 10, 0, 0, 1, null, 'Predecessor to T46S — same account.*/linekey.* cfg key style'],
        ['Yealink', 'T48G', 'yealink', 16, 0, 0, 1, null, 'Predecessor to T48S'],
        ['Yealink', 'T20P', 'yealink', 2, 0, 0, 0, null, 'Older T2x series — some firmware also requests <mac>-phone.cfg/<mac>-web.cfg'],
        ['Yealink', 'T22P', 'yealink', 3, 0, 0, 0, null, null],
        ['Yealink', 'T26P', 'yealink', 4, 0, 0, 0, null, null],
        ['Yealink', 'T28P', 'yealink', 6, 0, 0, 1, null, null],
        ['Yealink', 'T21P E2', 'yealink', 2, 0, 0, 0, null, null],
        ['Yealink', 'T23G', 'yealink', 3, 0, 0, 0, null, null],
        ['Yealink', 'T27G', 'yealink', 8, 0, 0, 0, null, null],
        ['Yealink', 'T29G', 'yealink', 20, 0, 0, 1, null, null],
        ['Yealink', 'T32G', 'yealink', 3, 0, 0, 0, null, null],
        ['Yealink', 'T40G', 'yealink', 3, 0, 0, 0, null, null],
        ['Yealink', 'T40P', 'yealink', 3, 0, 0, 0, null, null],
        ['Yealink', 'T41S', 'yealink', 6, 0, 0, 0, null, null],
        ['Yealink', 'T42S', 'yealink', 6, 0, 0, 0, null, null],
        ['Yealink', 'T54W', 'yealink', 10, 0, 0, 1, null, null],
        ['Yealink', 'W60B', 'yealink', 0, 0, 0, 0, null, 'DECT base station (pairs with W5x/W7x handsets)'],
        ['Yealink', 'W70B', 'yealink', 0, 0, 0, 0, null, 'DECT base station; handset configuration is distributed by the base'],
        ['Yealink', 'W80B', 'yealink', 0, 0, 0, 0, null, 'Multi-cell DECT base; provisioning applies to the base system'],
        ['Yealink', 'W90B', 'yealink', 0, 0, 0, 0, null, 'Multi-cell DECT radio; controlled by a W90DM manager'],
        ['Yealink', 'W90DM', 'yealink', 0, 0, 0, 0, null, 'Multi-cell DECT manager'],
        ['Yealink', 'W71H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions via its paired base, not directly via TFTP'],
        ['Yealink', 'W53H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions through its paired base'],
        ['Yealink', 'W56H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions through its paired base'],
        ['Yealink', 'W57R', 'yealink', 0, 0, 0, 0, null, 'Rugged DECT handset — provisions through its paired base'],
        ['Yealink', 'W59R', 'yealink', 0, 0, 0, 0, null, 'Rugged DECT handset — provisions through its paired base'],
        ['Yealink', 'W73H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions through its paired base'],
        ['Yealink', 'W74H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions through its paired base'],
        ['Yealink', 'W78H', 'yealink', 0, 0, 0, 0, null, 'DECT handset — provisions through its paired base'],
    ];

    /**
     * Normalised capabilities used by provisioning validation and the UI.
     *
     * A zero capacity means "not applicable or not independently verified";
     * it must never be promoted to an arbitrary default. Firmware profiles are
     * deliberately resolved by capabilitiesFor() through firmwareProfile().
     */
    private const CAPABILITY_DEFAULT = [
        'role'=>'desk', 'sip_accounts'=>0, 'line_appearances'=>0,
        'programmable_keys'=>0, 'programmable_key_namespace'=>'none',
        'programmable_key_pages'=>0, 'handsets_max'=>0, 'simultaneous_calls'=>0,
        'expansion_types'=>[], 'max_expansion_modules'=>0, 'keys_per_expansion'=>0,
        'config_profile'=>null, 'firmware_profile'=>null, 'directory_profile'=>null,
        'media_profile'=>null, 'auth_profile'=>null,
    ];

    private const CAP_CISCO_XML = [
        ...self::CAPABILITY_DEFAULT,
        'programmable_key_namespace'=>'linekey', 'programmable_key_pages'=>1,
        'config_profile'=>'cisco_xml', 'directory_profile'=>'cisco_xml_service',
        'auth_profile'=>'cisco_xml_admin',
    ];

    private const CAP_CISCO_CNF = [
        ...self::CAPABILITY_DEFAULT,
        'programmable_key_namespace'=>'linekey', 'programmable_key_pages'=>1,
        'config_profile'=>'cisco_cnf', 'directory_profile'=>'cisco_xml_service',
        'auth_profile'=>'cisco_cnf_telnet',
    ];

    private const CAP_GRANDSTREAM_DESK = [
        ...self::CAPABILITY_DEFAULT,
        'programmable_key_namespace'=>'mpk', 'programmable_key_pages'=>1,
        'directory_profile'=>'grandstream_xml_phonebook',
        'auth_profile'=>'grandstream_web_admin',
    ];

    private const CAP_YEALINK_DESK = [
        ...self::CAPABILITY_DEFAULT,
        'programmable_key_namespace'=>'linekey', 'programmable_key_pages'=>1,
        'config_profile'=>'yealink_modern', 'directory_profile'=>'yealink_remote_xml',
        'auth_profile'=>'yealink_web_admin',
    ];

    public const CAPABILITIES = [
        // Cisco SEP<MAC>.cnf.xml phones. Existing catalog line-key counts are
        // the verified account/appearance/key capacity for these models.
        'CISCO|7811' => [...self::CAP_CISCO_XML, 'sip_accounts'=>1, 'line_appearances'=>1, 'programmable_keys'=>1],
        'CISCO|7821' => [...self::CAP_CISCO_XML, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>2],
        'CISCO|7841' => [...self::CAP_CISCO_XML, 'sip_accounts'=>4, 'line_appearances'=>4, 'programmable_keys'=>4],
        'CISCO|7861' => [...self::CAP_CISCO_XML, 'sip_accounts'=>16, 'line_appearances'=>16, 'programmable_keys'=>16],
        'CISCO|7906' => [...self::CAP_CISCO_XML, 'sip_accounts'=>1, 'line_appearances'=>1, 'programmable_keys'=>1],
        'CISCO|7911' => [...self::CAP_CISCO_XML, 'sip_accounts'=>1, 'line_appearances'=>1, 'programmable_keys'=>1],
        'CISCO|8811' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5],
        'CISCO|8821' => [...self::CAP_CISCO_XML, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6, 'media_profile'=>'cisco_background'],
        'CISCO|8841' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'media_profile'=>'cisco_background'],
        'CISCO|8845' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'media_profile'=>'cisco_background'],
        'CISCO|8851' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'expansion_types'=>['BEKEM','CP-8800-Audio'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|8861' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'expansion_types'=>['BEKEM','CP-8800-Audio'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|8865' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'expansion_types'=>['BEKEM','CP-8800-Video'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|8831' => [...self::CAP_CISCO_XML, 'sip_accounts'=>1, 'line_appearances'=>1, 'programmable_keys'=>0, 'programmable_key_namespace'=>'none', 'programmable_key_pages'=>0],
        'CISCO|8832' => [...self::CAP_CISCO_XML, 'sip_accounts'=>1, 'line_appearances'=>1, 'programmable_keys'=>0, 'programmable_key_namespace'=>'none', 'programmable_key_pages'=>0],
        'CISCO|7941' => [...self::CAP_CISCO_XML, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>2, 'media_profile'=>'cisco_background'],
        'CISCO|7942' => [...self::CAP_CISCO_XML, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>2, 'media_profile'=>'cisco_background'],
        'CISCO|7945' => [...self::CAP_CISCO_XML, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>2, 'media_profile'=>'cisco_background'],
        'CISCO|7961' => [...self::CAP_CISCO_XML, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6, 'expansion_types'=>['7914'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>14, 'media_profile'=>'cisco_background'],
        'CISCO|7962' => [...self::CAP_CISCO_XML, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6, 'expansion_types'=>['7914','7915','7916'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|7965' => [...self::CAP_CISCO_XML, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6, 'expansion_types'=>['7914','7915','7916'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|7970' => [...self::CAP_CISCO_XML, 'sip_accounts'=>8, 'line_appearances'=>8, 'programmable_keys'=>8, 'expansion_types'=>['7914'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>14, 'media_profile'=>'cisco_background'],
        'CISCO|7971' => [...self::CAP_CISCO_XML, 'sip_accounts'=>8, 'line_appearances'=>8, 'programmable_keys'=>8, 'expansion_types'=>['7914'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>14, 'media_profile'=>'cisco_background'],
        'CISCO|7975' => [...self::CAP_CISCO_XML, 'sip_accounts'=>8, 'line_appearances'=>8, 'programmable_keys'=>8, 'expansion_types'=>['7914','7915','7916'], 'max_expansion_modules'=>2, 'keys_per_expansion'=>0, 'media_profile'=>'cisco_background'],
        'CISCO|8941' => [...self::CAP_CISCO_XML, 'sip_accounts'=>4, 'line_appearances'=>4, 'programmable_keys'=>4, 'media_profile'=>'cisco_background'],
        'CISCO|8945' => [...self::CAP_CISCO_XML, 'sip_accounts'=>4, 'line_appearances'=>4, 'programmable_keys'=>4, 'media_profile'=>'cisco_background'],
        'CISCO|8961' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'expansion_types'=>['CKEM'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>36, 'media_profile'=>'cisco_background'],
        'CISCO|9951' => [...self::CAP_CISCO_XML, 'sip_accounts'=>5, 'line_appearances'=>5, 'programmable_keys'=>5, 'expansion_types'=>['CKEM'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>36, 'media_profile'=>'cisco_background'],
        'CISCO|9971' => [...self::CAP_CISCO_XML, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6, 'expansion_types'=>['CKEM'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>36, 'media_profile'=>'cisco_background'],

        // Cisco SIP<MAC>.cnf phones. Their XML directory client is still
        // available through directory_url despite the different config syntax.
        'CISCO|7940' => [...self::CAP_CISCO_CNF, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>2],
        'CISCO|7960' => [...self::CAP_CISCO_CNF, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>6],

        // Grandstream DECT systems and desk/hotel phones.
        'GRANDSTREAM|DP750' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_base', 'sip_accounts'=>10, 'handsets_max'=>5, 'simultaneous_calls'=>5, 'config_profile'=>'grandstream_dp75x_pvalue', 'directory_profile'=>'grandstream_xml_phonebook', 'auth_profile'=>'grandstream_web_admin'],
        'GRANDSTREAM|DP752' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_base', 'sip_accounts'=>10, 'handsets_max'=>5, 'simultaneous_calls'=>5, 'config_profile'=>'grandstream_dp75x_pvalue', 'directory_profile'=>'grandstream_xml_phonebook', 'auth_profile'=>'grandstream_web_admin'],
        'GRANDSTREAM|DP755' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_base', 'sip_accounts'=>20, 'handsets_max'=>10, 'config_profile'=>'grandstream_dp755_alias_v2', 'directory_profile'=>'grandstream_xml_phonebook', 'auth_profile'=>'grandstream_web_admin'],
        'GRANDSTREAM|DP720' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'grandstream_via_base'],
        'GRANDSTREAM|DP722' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'grandstream_via_base'],
        'GRANDSTREAM|DP725' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'grandstream_via_base'],
        'GRANDSTREAM|DP730' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'grandstream_via_base'],
        'GRANDSTREAM|DP735' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'grandstream_via_base'],
        'GRANDSTREAM|GXP2130' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>3, 'line_appearances'=>3, 'programmable_keys'=>8, 'config_profile'=>'grandstream_gxp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GXP2140' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>4, 'line_appearances'=>4, 'programmable_keys'=>4, 'expansion_types'=>['GXP2200EXT'], 'max_expansion_modules'=>4, 'keys_per_expansion'=>40, 'config_profile'=>'grandstream_gxp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GXP2170' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>6, 'line_appearances'=>12, 'programmable_keys'=>48, 'programmable_key_pages'=>4, 'expansion_types'=>['GXP2200EXT'], 'max_expansion_modules'=>4, 'keys_per_expansion'=>40, 'config_profile'=>'grandstream_gxp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GRP2604' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>6, 'line_appearances'=>3, 'programmable_keys'=>4, 'config_profile'=>'grandstream_grp'],
        'GRANDSTREAM|GRP2614' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>12, 'line_appearances'=>4, 'programmable_keys'=>8, 'programmable_key_pages'=>2, 'config_profile'=>'grandstream_grp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GRP2634' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>12, 'line_appearances'=>8, 'programmable_keys'=>10, 'programmable_key_pages'=>2, 'config_profile'=>'grandstream_grp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GHP630' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>16, 'programmable_key_namespace'=>'mixed', 'config_profile'=>'grandstream_ghp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GHP630W' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>16, 'programmable_key_namespace'=>'mixed', 'config_profile'=>'grandstream_ghp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GHP631' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>16, 'programmable_key_namespace'=>'mixed', 'config_profile'=>'grandstream_ghp', 'media_profile'=>'grandstream_color_media'],
        'GRANDSTREAM|GHP631W' => [...self::CAP_GRANDSTREAM_DESK, 'sip_accounts'=>2, 'line_appearances'=>2, 'programmable_keys'=>16, 'programmable_key_namespace'=>'mixed', 'config_profile'=>'grandstream_ghp', 'media_profile'=>'grandstream_color_media'],

        // Yealink desk phones. A zero line_appearances value means the vendor
        // does not publish a distinct capacity separate from accounts/DSS keys.
        'YEALINK|T46S' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>10, 'expansion_types'=>['EXP40'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T48S' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>16, 'expansion_types'=>['EXP40'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T48U' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>29, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP43'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T46U' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>27, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP43'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T43U' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>12, 'programmable_keys'=>21, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP43'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60],
        'YEALINK|T42U' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>12, 'programmable_keys'=>15, 'programmable_key_pages'=>3],
        'YEALINK|T33G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>4, 'programmable_keys'=>4, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T33P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>4, 'programmable_keys'=>4, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T31G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>2, 'programmable_keys'=>2],
        'YEALINK|T31P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>2, 'programmable_keys'=>2],
        'YEALINK|T31' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>2, 'programmable_keys'=>2],
        'YEALINK|T30P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>1, 'programmable_keys'=>1],
        'YEALINK|T30' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>1, 'programmable_keys'=>1],
        'YEALINK|T57W' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>29, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T53W' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>12, 'programmable_keys'=>21, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60],
        'YEALINK|T53' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>12, 'programmable_keys'=>21, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60],
        'YEALINK|T58A' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>27, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|VP59' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>27, 'programmable_key_pages'=>3, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T46G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>10, 'expansion_types'=>['EXP40'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T48G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>16, 'expansion_types'=>['EXP40'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T20P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>2, 'programmable_keys'=>2, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T22P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'programmable_keys'=>3, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T26P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'line_appearances'=>3, 'programmable_keys'=>13, 'programmable_key_namespace'=>'mixed', 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T28P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>6, 'line_appearances'=>6, 'programmable_keys'=>16, 'programmable_key_namespace'=>'mixed', 'expansion_types'=>['EXP39'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T21P E2' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>2, 'programmable_keys'=>2, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T23G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'programmable_keys'=>3, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T27G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>6, 'programmable_keys'=>8, 'config_profile'=>'yealink_legacy_t2'],
        'YEALINK|T29G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>20, 'expansion_types'=>['EXP20'], 'max_expansion_modules'=>6, 'keys_per_expansion'=>40, 'config_profile'=>'yealink_legacy_t2', 'media_profile'=>'yealink_color_media'],
        'YEALINK|T32G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'programmable_keys'=>3, 'media_profile'=>'yealink_color_media'],
        'YEALINK|T40G' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'programmable_keys'=>3],
        'YEALINK|T40P' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>3, 'programmable_keys'=>3],
        'YEALINK|T41S' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>6, 'programmable_keys'=>6],
        'YEALINK|T42S' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>12, 'programmable_keys'=>6],
        'YEALINK|T54W' => [...self::CAP_YEALINK_DESK, 'sip_accounts'=>16, 'programmable_keys'=>10, 'expansion_types'=>['EXP50'], 'max_expansion_modules'=>3, 'keys_per_expansion'=>60, 'media_profile'=>'yealink_color_media'],
        'YEALINK|W60B' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_base', 'sip_accounts'=>8, 'handsets_max'=>8, 'simultaneous_calls'=>8, 'config_profile'=>'yealink_dect_single', 'directory_profile'=>'yealink_remote_xml', 'auth_profile'=>'yealink_web_admin'],
        'YEALINK|W70B' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_base', 'sip_accounts'=>10, 'handsets_max'=>10, 'simultaneous_calls'=>20, 'config_profile'=>'yealink_dect_single', 'directory_profile'=>'yealink_remote_xml', 'auth_profile'=>'yealink_web_admin'],
        'YEALINK|W80B' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_radio', 'config_profile'=>'yealink_dect_multicell', 'auth_profile'=>'yealink_web_admin'],
        'YEALINK|W90B' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_radio', 'config_profile'=>'yealink_dect_multicell', 'auth_profile'=>'yealink_web_admin'],
        'YEALINK|W90DM' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_manager', 'config_profile'=>'yealink_dect_multicell', 'directory_profile'=>'yealink_remote_xml', 'auth_profile'=>'yealink_web_admin'],
        'YEALINK|W71H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W53H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W56H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W57R' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W59R' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W73H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W74H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
        'YEALINK|W78H' => [...self::CAPABILITY_DEFAULT, 'role'=>'dect_handset', 'directory_profile'=>'yealink_via_base'],
    ];

    /**
     * Firmware is compatible by load family, not by the broad cisco_xml
     * provisioning family. In particular, a SIP41 load is for 7941/7961 only,
     * and the 8845/8865 video load must never be offered to an audio-only 8861.
     */
    public const FIRMWARE_PROFILES = [
        'cisco:p0s3'       => ['vendor'=>'Cisco', 'label'=>'7940/7960 P0S3',       'models'=>['7940','7960'],      'pattern'=>'/^P0S3-[A-Za-z0-9.-]+\.loads$/i'],
        'cisco:sip11'      => ['vendor'=>'Cisco', 'label'=>'7906/7911 SIP11',      'models'=>['7906','7911'],      'pattern'=>'/^SIP11[.-].*\.loads$/i'],
        'cisco:sip41'      => ['vendor'=>'Cisco', 'label'=>'7941/7961 SIP41',      'models'=>['7941','7961'],      'pattern'=>'/^SIP41[.-].*\.loads$/i'],
        'cisco:sip42'      => ['vendor'=>'Cisco', 'label'=>'7942/7962 SIP42',      'models'=>['7942','7962'],      'pattern'=>'/^SIP42[.-].*\.loads$/i'],
        'cisco:sip45'      => ['vendor'=>'Cisco', 'label'=>'7945/7965 SIP45',      'models'=>['7945','7965'],      'pattern'=>'/^SIP45[.-].*\.loads$/i'],
        'cisco:sip70'      => ['vendor'=>'Cisco', 'label'=>'7970/7971 SIP70',      'models'=>['7970','7971'],      'pattern'=>'/^SIP70[.-].*\.loads$/i'],
        'cisco:sip75'      => ['vendor'=>'Cisco', 'label'=>'7975 SIP75',           'models'=>['7975'],             'pattern'=>'/^SIP75[.-].*\.loads$/i'],
        'cisco:sip78xx'    => ['vendor'=>'Cisco', 'label'=>'7800-series',          'models'=>['7811','7821','7841','7861'], 'pattern'=>'/^sip78xx[.-].*\.loads$/i'],
        'cisco:sip88xx'    => ['vendor'=>'Cisco', 'label'=>'8800-series audio',    'models'=>['8811','8841','8851','8861'], 'pattern'=>'/^sip88xx[.-].*\.loads$/i'],
        'cisco:sip8845_65' => ['vendor'=>'Cisco', 'label'=>'8845/8865 video',      'models'=>['8845','8865'],      'pattern'=>'/^sip8845_65[.-].*\.loads$/i'],
        'cisco:sip8821'    => ['vendor'=>'Cisco', 'label'=>'8821 wireless',        'models'=>['8821'],             'pattern'=>'/^sip8821[.-].*\.loads$/i'],
        'cisco:sip8831'    => ['vendor'=>'Cisco', 'label'=>'8831 conference',      'models'=>['8831'],             'pattern'=>'/^sip8831[.-].*\.loads$/i'],
        'cisco:sip8832'    => ['vendor'=>'Cisco', 'label'=>'8832 conference',      'models'=>['8832'],             'pattern'=>'/^sip8832[.-].*\.loads$/i'],
        'cisco:sip894x'    => ['vendor'=>'Cisco', 'label'=>'8941/8945',            'models'=>['8941','8945'],      'pattern'=>'/^sip894(?:1_8945|x|1|5)[.-].*\.loads$/i'],
        'cisco:sip8961'    => ['vendor'=>'Cisco', 'label'=>'8961',                 'models'=>['8961'],             'pattern'=>'/^sip8961[.-].*\.loads$/i'],
        'cisco:sip9951'    => ['vendor'=>'Cisco', 'label'=>'9951',                 'models'=>['9951'],             'pattern'=>'/^sip9951[.-].*\.loads$/i'],
        'cisco:sip9971'    => ['vendor'=>'Cisco', 'label'=>'9971',                 'models'=>['9971'],             'pattern'=>'/^sip9971[.-].*\.loads$/i'],
    ];

    /**
     * Cisco timezone compatibility is owned by the phone runtime, not by the
     * broad cisco_xml family and not by whichever COP happened to contain the
     * bytes.  Every UI and generator path resolves through this table.
     *
     * A profile may retain many payload revisions.  `payload` is only the
     * canonical Cisco filename/format; it is not the identity of a revision.
     */
    public const CISCO_TIMEZONE_PROFILES = [
        'cisco-79xx-8961-99x1'=>[
            'label'=>'Cisco standard Java phones',
            'architecture'=>'Standard JVM',
            'payload'=>'tzupdater.jar',
            'models'=>['7906','7911','7941','7942','7945','7961','7962','7965','7970','7971','7975','8961','9951','9971'],
            'firmware_profiles'=>['cisco:sip11','cisco:sip41','cisco:sip42','cisco:sip45','cisco:sip70','cisco:sip75','cisco:sip8961','cisco:sip9951','cisco:sip9971'],
            'published_prefix'=>'ucmp-tz-java',
        ],
        'cisco-78xx-88xx'=>[
            'label'=>'Cisco IBM J9 phones',
            'architecture'=>'IBM J9 JVM',
            'payload'=>'j9-tzdata.jar',
            'models'=>['7811','7821','7841','7861','8811','8821','8831','8832','8841','8845','8851','8861','8865'],
            'firmware_profiles'=>['cisco:sip78xx','cisco:sip88xx','cisco:sip8845_65','cisco:sip8821','cisco:sip8831','cisco:sip8832'],
            'published_prefix'=>'ucmp-tz-j9',
        ],
        'cisco-39xx-69xx-894x'=>[
            'label'=>'Cisco Lodown / RTL / Gumbo phones',
            'architecture'=>'Lodown / RTL / Gumbo',
            'payload'=>'TzDataCSV.csv',
            'models'=>['3905','3911','3951','6901','6911','6921','6941','6945','6961','8941','8945'],
            'firmware_profiles'=>['cisco:sip894x'],
            'published_prefix'=>'ucmp-tz-csv',
        ],
    ];

    /**
     * Phone-facing locale companions by Cisco load family. User-interface JARs
     * live below the configured user-locale directory. The tone file lives
     * below the configured network-locale directory, or at TFTP root when the
     * phone is intentionally left on its firmware-default locale path.
     */
    public const CISCO_LOCALE_PROFILES = [
        'cisco:sip11-locale' => [
            'label'=>'7906/7911 SIP11 locale bundle',
            'firmware_profile'=>'cisco:sip11',
            'models'=>['7906','7911'],
            'files'=>[
                ['path'=>'{user_locale}/tc-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip41-locale' => [
            'label'=>'7941/7961 SIP41 locale bundle',
            'firmware_profile'=>'cisco:sip41',
            'models'=>['7941','7961'],
            'files'=>[
                ['path'=>'{user_locale}/mk-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip42-locale' => [
            'label'=>'7942/7962 SIP42 locale bundle',
            'firmware_profile'=>'cisco:sip42',
            'models'=>['7942','7962'],
            'files'=>[
                ['path'=>'{user_locale}/mk-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip45-locale' => [
            'label'=>'7945/7965 SIP45 locale bundle',
            'firmware_profile'=>'cisco:sip45',
            'models'=>['7945','7965'],
            'files'=>[
                ['path'=>'{user_locale}/be-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip70-locale' => [
            'label'=>'7970/7971 SIP70 locale bundle',
            'firmware_profile'=>'cisco:sip70',
            'models'=>['7970','7971'],
            'files'=>[
                ['path'=>'{user_locale}/td-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip75-locale' => [
            'label'=>'7975 SIP75 locale bundle',
            'firmware_profile'=>'cisco:sip75',
            'models'=>['7975'],
            'files'=>[
                ['path'=>'{user_locale}/td-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip78xx-locale' => [
            'label'=>'7811/7821/7841/7861 locale bundle',
            'firmware_profile'=>'cisco:sip78xx',
            'models'=>['7811','7821','7841','7861'],
            'files'=>[
                ['path'=>'{user_locale}/sp-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip88xx-locale' => [
            'label'=>'8811/8841/8851/8861 audio locale bundle',
            'firmware_profile'=>'cisco:sip88xx',
            'models'=>['8811','8841','8851','8861'],
            'files'=>[
                ['path'=>'{user_locale}/sl-be-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip8845_65-locale' => [
            'label'=>'8845/8865 video locale bundle',
            'firmware_profile'=>'cisco:sip8845_65',
            'models'=>['8845','8865'],
            'files'=>[
                ['path'=>'{user_locale}/sl-bev-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip894x-locale' => [
            'label'=>'8941/8945 locale bundle',
            'firmware_profile'=>'cisco:sip894x',
            'models'=>['8941','8945'],
            'files'=>[
                ['path'=>'{user_locale}/gb-sccp-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip8961-locale' => [
            'label'=>'8961 locale bundle',
            'firmware_profile'=>'cisco:sip8961',
            'models'=>['8961'],
            'files'=>[
                ['path'=>'{user_locale}/gh-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g3-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip9951-locale' => [
            'label'=>'9951 locale bundle',
            'firmware_profile'=>'cisco:sip9951',
            'models'=>['9951'],
            'files'=>[
                ['path'=>'{user_locale}/lk-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g4-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
        'cisco:sip9971-locale' => [
            'label'=>'9971 locale bundle',
            'firmware_profile'=>'cisco:sip9971',
            'models'=>['9971'],
            'files'=>[
                ['path'=>'{user_locale}/gd-sip.jar', 'role'=>'User-interface locale JAR'],
                ['path'=>'{tone_path}/g4-tones.xml', 'role'=>'Network tones and cadences'],
            ],
        ],
    ];

    /** Models intentionally hidden from supported provisioning/firmware flows. */
    public const UNSUPPORTED_MODELS = [
        'CISCO|7950' => 'No verified Cisco SIP model/configuration profile exists for 7950; it is quarantined.',
    ];

    /** Return one complete capability row with its canonical firmware profile. */
    public static function capabilitiesFor(string $vendor, string $model): ?array
    {
        $key = strtoupper(trim($vendor)) . '|' . strtoupper(trim($model));
        if (isset(self::UNSUPPORTED_MODELS[$key]) || !isset(self::CAPABILITIES[$key])) {
            return null;
        }
        $capabilities = self::CAPABILITIES[$key];
        $capabilities['firmware_profile'] = self::firmwareProfile($vendor, $model);
        return $capabilities;
    }

    /**
     * Stable operator-facing series used by template applicability and default
     * template rules. This is intentionally broader than a firmware profile:
     * an 8845 and 8811 may need different loads while still sharing one 88xx
     * policy template.
     */
    public static function seriesKey(string $vendor, string $model, string $family = ''): string
    {
        $vendor = strtolower(trim($vendor));
        $model = strtoupper(trim($model));
        $family = strtolower(trim($family));

        if ($family === 'cisco_cnf' || ($vendor === 'cisco' && in_array($model, ['7940', '7960'], true))) {
            return 'cisco_cnf';
        }
        if ($vendor === 'cisco' || $family === 'cisco_xml') {
            if (preg_match('/^78/', $model)) { return 'cisco_78xx'; }
            if (preg_match('/^88/', $model)) { return 'cisco_88xx'; }
            if (preg_match('/^79/', $model)) { return 'cisco_79xx'; }
            if (preg_match('/^(?:89|99)/', $model)) { return 'cisco_89xx_99xx'; }
            return 'cisco_xml';
        }
        if ($vendor === 'yealink' || $family === 'yealink') {
            if (preg_match('/^(?:W|CP9)/', $model)) { return 'yealink_dect'; }
            if (preg_match('/^T4\\dU$/', $model)) { return 'yealink_t4u'; }
            if (preg_match('/^T4/', $model)) { return 'yealink_t4'; }
            if (preg_match('/^T5/', $model)) { return 'yealink_t5'; }
            if (preg_match('/^T3/', $model)) { return 'yealink_t3'; }
            if (preg_match('/^T2/', $model)) { return 'yealink_t2'; }
            if (preg_match('/^VP/', $model)) { return 'yealink_vp'; }
            return 'yealink';
        }
        if ($vendor === 'grandstream' || $family === 'grandstream') {
            if (preg_match('/^DP7/', $model)) { return 'grandstream_dp75x'; }
            if (preg_match('/^GRP26/', $model)) { return 'grandstream_grp26xx'; }
            if (preg_match('/^GXP21/', $model)) { return 'grandstream_gxp21xx'; }
            if (preg_match('/^GHP63/', $model)) { return 'grandstream_ghp63x'; }
            return 'grandstream';
        }
        return $family !== '' ? $family : $vendor;
    }

    /**
     * Applicability scopes accepted by templates and default-template rules.
     *
     * The first value is the existing broad policy series, retained so saved
     * Base_78xx/Base_88xx and older rules keep matching. Cisco XML phones also
     * expose their already-audited firmware/configuration compatibility family
     * (for example SIP41 for 7941/7961). This avoids maintaining a second,
     * inevitably divergent Cisco family matrix.
     *
     * @return array<int,string>
     */
    public static function templateScopeKeys(
        string $vendor,
        string $model,
        string $family = '',
        string $seriesKey = '',
        string $firmwareProfile = ''
    ): array {
        $seriesKey = strtolower(trim($seriesKey));
        if ($seriesKey === '') {
            $seriesKey = self::seriesKey($vendor, $model, $family);
        }
        $keys = $seriesKey !== '' ? [$seriesKey] : [];
        if (strcasecmp(trim($vendor), 'Cisco') === 0) {
            $firmwareProfile = strtolower(trim($firmwareProfile));
            if ($firmwareProfile === '') {
                $firmwareProfile = (string)(self::firmwareProfile($vendor, $model) ?? '');
            }
            if ($firmwareProfile !== '' && isset(self::FIRMWARE_PROFILES[$firmwareProfile])) {
                $keys[] = $firmwareProfile;
            }
        }
        return array_values(array_unique($keys));
    }

    public static function templateScopeMatches(string $expected, array $device): bool
    {
        $expected = strtolower(trim($expected));
        if ($expected === '') { return true; }
        return in_array($expected, self::templateScopeKeys(
            (string)($device['vendor'] ?? ''),
            (string)($device['model'] ?? ''),
            (string)($device['family'] ?? ''),
            (string)($device['series_key'] ?? ($device['series'] ?? '')),
            (string)($device['firmware_profile'] ?? '')
        ), true);
    }

    public static function isFirmwareTemplateScope(string $scope): bool
    {
        return isset(self::FIRMWARE_PROFILES[strtolower(trim($scope))]);
    }

    /**
     * Build one shared selector catalog for the Template editor and General
     * defaults. Compatibility entries identical to their broad policy group
     * (currently CNF P0S3 and 7800) are omitted from the UI as duplicates, but
     * remain accepted by the matcher for backward/advanced use.
     *
     * @param array<int,array<string,mixed>> $models
     * @return array<string,array<string,mixed>>
     */
    public static function templateScopeOptions(array $models): array
    {
        $broad = [];
        $compatibility = [];
        foreach ($models as $candidate) {
            if (!is_array($candidate)) { continue; }
            $vendor = (string)($candidate['vendor'] ?? '');
            $model = (string)($candidate['model'] ?? '');
            $family = (string)($candidate['family'] ?? '');
            if ($vendor === '' || $model === '' || $family === '') { continue; }
            $series = strtolower(trim((string)($candidate['series_key'] ?? '')));
            if ($series === '') { $series = self::seriesKey($vendor, $model, $family); }
            $modelKey = strtoupper($vendor . '|' . $model);
            $broad[$series] = $broad[$series] ?? [
                'id'=>$series,
                'vendor'=>$vendor,
                'family'=>$family,
                'kind'=>'policy',
                'models'=>[],
            ];
            $broad[$series]['models'][$modelKey] = $model;

            $firmware = self::firmwareProfile($vendor, $model);
            if (strcasecmp($vendor, 'Cisco') === 0 && $firmware !== null) {
                $compatibility[$firmware] = $compatibility[$firmware] ?? [
                    'id'=>$firmware,
                    'vendor'=>$vendor,
                    'family'=>$family,
                    'kind'=>'compatibility',
                    'models'=>[],
                    'broad_series'=>$series,
                ];
                $compatibility[$firmware]['models'][$modelKey] = $model;
            }
        }

        $options = $broad;
        foreach ($compatibility as $key=>$option) {
            $broadModels = array_keys((array)($broad[$option['broad_series']]['models'] ?? []));
            $compatibilityModels = array_keys((array)$option['models']);
            sort($broadModels);
            sort($compatibilityModels);
            if ($broadModels === $compatibilityModels) { continue; }
            $options[$key] = $option;
        }
        foreach ($options as $key=>&$option) {
            $option['models'] = array_values((array)$option['models']);
            sort($option['models'], SORT_NATURAL | SORT_FLAG_CASE);
            if (($option['kind'] ?? '') === 'compatibility') {
                $profile = self::FIRMWARE_PROFILES[$key];
                $option['label'] = (string)$profile['label'] . ' firmware/config compatibility';
            } else {
                $option['label'] = ucwords(str_replace('_', ' ', $key)) . ' policy group';
            }
            unset($option['broad_series']);
        }
        unset($option);
        uasort($options, static function (array $left, array $right): int {
            $family = strcasecmp((string)$left['family'], (string)$right['family']);
            if ($family !== 0) { return $family; }
            $kind = (($left['kind'] ?? '') === 'policy' ? 0 : 1)
                <=> (($right['kind'] ?? '') === 'policy' ? 0 : 1);
            return $kind !== 0 ? $kind : strnatcasecmp((string)$left['label'], (string)$right['label']);
        });
        return $options;
    }

    /**
     * Vendor/profile-specific identity surfaces. Character limits apply only
     * when the final config is rendered; AD and template values remain
     * unabridged. An accepted configuration length is not a promise that every
     * glyph will fit on the physical LCD.
     *
     * The legacy Cisco line-key limit is a module safety policy, not a
     * Cisco-published hardware limit.
     *
     * @return array<string,mixed>|null
     */
    public static function identityLabelPolicy(string $vendor, string $model): ?array
    {
        $vendor = trim($vendor);
        $model = strtoupper(trim($model));
        if (strcasecmp($vendor, 'Cisco') === 0 && in_array($model, ['7940', '7960'], true)) {
            return [
                'phone_field'=>'phone_label',
                'phone_max_chars'=>11,
                'phone_spaces'=>true,
                'phone_maps_to_line'=>false,
                'line_field'=>'lineX_shortname',
                'line_max_chars'=>30,
                'line_limit_source'=>'module_policy',
                'caller_field'=>'lineX_displayname',
                'caller_max_chars'=>null,
                'handset_field'=>null,
                'handset_max_chars'=>null,
                'firmware_status_text'=>'SIP',
            ];
        }
        $capabilities = self::capabilitiesFor($vendor, $model);
        if (!$capabilities) {
            return null;
        }
        $profile = (string)($capabilities['config_profile'] ?? '');
        $role = (string)($capabilities['role'] ?? 'desk');

        if (strcasecmp($vendor, 'Cisco') === 0) {
            if ($profile !== 'cisco_xml') {
                return null;
            }
            return [
                'phone_field'=>'phoneLabel',
                'phone_max_chars'=>12,
                // The enterprise XML 7900 firmware rejects spaces in phoneLabel.
                'phone_spaces'=>!preg_match('/^79/', $model),
                'phone_maps_to_line'=>false,
                'line_field'=>'featureLabel',
                'line_max_chars'=>30,
                'line_limit_source'=>'vendor',
                'caller_field'=>'displayName',
                'caller_max_chars'=>30,
                'handset_field'=>null,
                'handset_max_chars'=>null,
                'firmware_status_text'=>'',
            ];
        }

        if (strcasecmp($vendor, 'Yealink') === 0) {
            return [
                // Modern Yealink desk/base profiles do not expose a separate,
                // universal top/status identity field. The account label is
                // the idle/line identity surface.
                'phone_field'=>null,
                'phone_max_chars'=>null,
                'phone_spaces'=>true,
                'phone_maps_to_line'=>true,
                'line_field'=>'account.X.label',
                'line_max_chars'=>99,
                'line_limit_source'=>'vendor',
                'caller_field'=>'account.X.display_name',
                'caller_max_chars'=>99,
                'key_field'=>'linekey.X.label',
                'key_max_chars'=>99,
                'handset_field'=>$role === 'dect_base' ? 'handset.X.name' : null,
                'handset_max_chars'=>$role === 'dect_base' ? 24 : null,
                'firmware_status_text'=>'',
            ];
        }

        if (strcasecmp($vendor, 'Grandstream') !== 0) {
            return null;
        }
        if ($profile === 'grandstream_dp755_alias_v2') {
            return [
                'phone_field'=>null,
                'phone_max_chars'=>null,
                'phone_spaces'=>true,
                'phone_maps_to_line'=>true,
                'line_field'=>'account.X.name',
                'line_max_chars'=>512,
                'line_limit_source'=>'vendor_config_field',
                'caller_field'=>'account.X.sip.subscriber.name',
                'caller_max_chars'=>512,
                'handset_field'=>'hs.X.user.hsName',
                'handset_max_chars'=>10,
                'firmware_status_text'=>'',
            ];
        }
        if ($profile === 'grandstream_dp75x_pvalue') {
            return [
                'phone_field'=>null,
                'phone_max_chars'=>null,
                'phone_spaces'=>true,
                'phone_maps_to_line'=>false,
                // The supported DP750/DP752 P-value profile exposes Name as
                // caller identity, not a separate per-account LCD label.
                'line_field'=>null,
                'line_max_chars'=>null,
                'line_limit_source'=>'not_exposed',
                'caller_field'=>'Name (P4180...)',
                'caller_max_chars'=>256,
                'handset_field'=>'Handset Name (P27020...)',
                'handset_max_chars'=>10,
                'firmware_status_text'=>'',
            ];
        }
        return [
            // Current desk-phone templates expose Account Name and caller Name,
            // but publish no common numeric limit across every supported model.
            'phone_field'=>null,
            'phone_max_chars'=>null,
            'phone_spaces'=>true,
            'phone_maps_to_line'=>true,
            'line_field'=>'Account Name (P270/P417/...)',
            'line_max_chars'=>null,
            'line_limit_source'=>'vendor_no_common_limit',
            'caller_field'=>'Name (P3/P407/...)',
            'caller_max_chars'=>null,
            'handset_field'=>null,
            'handset_max_chars'=>null,
            'firmware_status_text'=>'',
        ];
    }

    public static function firmwareProfile(string $vendor, string $model): ?string
    {
        $vendor = ucfirst(strtolower(trim($vendor)));
        $model = strtoupper(trim($model));
        if (isset(self::UNSUPPORTED_MODELS[strtoupper($vendor) . '|' . $model])) {
            return null;
        }
        foreach (self::FIRMWARE_PROFILES as $id => $profile) {
            if (strcasecmp($vendor, (string)$profile['vendor']) === 0
                && in_array($model, $profile['models'], true)) {
                return $id;
            }
        }
        $capabilityKey = strtoupper($vendor) . '|' . $model;
        $role = strtolower((string)(self::CAPABILITIES[$capabilityKey]['role'] ?? 'desk'));
        if (in_array(strtolower($vendor), ['yealink','grandstream'], true)
            && self::catalogHasModel($vendor, $model)
            && !preg_match('/handset|radio|manager/', $role)) {
            return strtolower($vendor) . ':' . self::firmwareModelToken($model);
        }
        return null;
    }

    /** Runtime/architecture timezone profile selected by an Enterprise XML model. */
    public static function ciscoTimezoneProfile(string $vendor, string $model, string $family = 'cisco_xml'): ?string
    {
        if (strcasecmp(trim($vendor), 'Cisco') !== 0 || strtolower(trim($family)) !== 'cisco_xml') {
            return null;
        }
        $model = strtoupper(trim($model));
        foreach (self::CISCO_TIMEZONE_PROFILES as $profile=>$definition) {
            if (in_array($model, (array)$definition['models'], true)) { return $profile; }
        }
        return null;
    }

    /** Canonical Cisco DST payload selected by Enterprise XML phone runtime. */
    public static function ciscoTimezonePayload(string $vendor, string $model, string $family = 'cisco_xml'): ?string
    {
        $profile = self::ciscoTimezoneProfile($vendor, $model, $family);
        return $profile !== null ? (string)self::CISCO_TIMEZONE_PROFILES[$profile]['payload'] : null;
    }

    /**
     * Firmware-branch facts that must stay identical in the template editor,
     * validation and the generated SEP file. This is deliberately derived from
     * the existing model/load/media catalogs instead of maintaining a second
     * list of compatible models.
     *
     * @return array<string,mixed>|null
     */
    public static function ciscoXmlBranchPolicy(string $vendor, string $model, string $family = 'cisco_xml'): ?array
    {
        if (strcasecmp(trim($vendor), 'Cisco') !== 0 || strtolower(trim($family)) !== 'cisco_xml') {
            return null;
        }
        $model = strtoupper(trim($model));
        $firmwareProfile = self::firmwareProfile('Cisco', $model);
        if ($firmwareProfile === null) { return null; }

        // Cisco documents the custom SIP softkey file on this exact legacy set.
        // Other legacy branches remain selectable for field testing, but the UI
        // must say experimental rather than presenting them as guaranteed.
        $documentedLegacy = ['7911','7941','7961','7970','7971'];
        $observedLegacy = ['7975'];
        if (in_array($model, $documentedLegacy, true)) {
            $softkeys = 'verified';
        } elseif (in_array($model, $observedLegacy, true)) {
            $softkeys = 'field_verified';
        } elseif (preg_match('/^(?:78|88)/', $model)) {
            $softkeys = 'verified';
        } elseif (in_array($model, ['8961','9951','9971'], true)) {
            $softkeys = 'feature_policy_preferred';
        } else {
            $softkeys = 'experimental';
        }

        $localeProfile = null;
        foreach (self::CISCO_LOCALE_PROFILES as $profileId=>$definition) {
            if (($definition['firmware_profile'] ?? '') === $firmwareProfile
                && in_array($model, (array)($definition['models'] ?? []), true)) {
                $localeProfile = $profileId;
                break;
            }
        }
        return [
            'firmware_profile'=>$firmwareProfile,
            'timezone_profile'=>self::ciscoTimezoneProfile('Cisco', $model, 'cisco_xml'),
            'timezone_payload'=>self::ciscoTimezonePayload('Cisco', $model, 'cisco_xml'),
            'wallpaper_profile'=>self::backgroundProfileKey('Cisco', $model),
            'softkey_file_status'=>$softkeys,
            'softkey_control_required'=>(bool)preg_match('/^(?:78|88|8961$|9951$|9971$)/', $model),
            'feature_policy_preferred'=>in_array($model, ['8961','9951','9971'], true),
            'locale_profile'=>$localeProfile,
            'locale_files'=>$localeProfile !== null
                ? self::CISCO_LOCALE_PROFILES[$localeProfile]['files']
                : [],
            // Retained for older editor clients; new code consumes locale_files.
            'locale_tone_file'=>$localeProfile !== null
                ? basename((string)(self::CISCO_LOCALE_PROFILES[$localeProfile]['files'][1]['path'] ?? ''))
                : null,
            'default_wallpaper_minimum'=>preg_match('/^79/', $model) ? '9.3(1)SR4' : null,
            'video_ui'=>self::isVideoModel('Cisco', $model),
        ];
    }

    /**
     * Decide whether defaultWallpaperFile is usable by the selected load. A
     * missing/unparseable load is reported as unknown (and may still be emitted)
     * so an existing phone load is not disabled merely because the module does
     * not own it.
     *
     * @return array{supported:?bool,status:string,minimum:?string,detected:?string,message:string}
     */
    public static function ciscoDefaultWallpaperSupport(string $vendor, string $model, string $firmware): array
    {
        $model = strtoupper(trim($model));
        if (self::backgroundProfileKey($vendor, $model) === null) {
            return ['supported'=>false, 'status'=>'not_applicable', 'minimum'=>null, 'detected'=>null,
                'message'=>'This model has no managed Cisco wallpaper profile.'];
        }
        if (!preg_match('/^79/', $model)) {
            return ['supported'=>true, 'status'=>'supported', 'minimum'=>null, 'detected'=>null, 'message'=>''];
        }

        $minimum = '9.3(1)SR4';
        $firmware = trim($firmware);
        if ($firmware === '' || !preg_match('/(?:^|[._-])(\d+)[._-](\d+)[._-](\d+)(?:SR(\d+))?/i', $firmware, $match)) {
            return ['supported'=>null, 'status'=>'unknown_firmware', 'minimum'=>$minimum, 'detected'=>null,
                'message'=>'The selected/running load could not be versioned; Cisco default-wallpaper support requires 9.3(1)SR4 or later on 7900-series XML phones.'];
        }
        $detected = sprintf('%d.%d(%d)%s', (int)$match[1], (int)$match[2], (int)$match[3], isset($match[4]) ? 'SR'.(int)$match[4] : '');
        $actual = [(int)$match[1], (int)$match[2], (int)$match[3], isset($match[4]) ? (int)$match[4] : 0];
        $supported = version_compare(implode('.', $actual), '9.3.1.4', '>=');
        return [
            'supported'=>$supported,
            'status'=>$supported ? 'supported' : 'unsupported_firmware',
            'minimum'=>$minimum,
            'detected'=>$detected,
            'message'=>$supported ? '' : "Cisco $model load $detected predates defaultWallpaperFile support ($minimum).",
        ];
    }

    /** @return array<int,array{vendor:string,model:string}> */
    public static function modelsForFirmwareProfile(string $profile): array
    {
        if (isset(self::FIRMWARE_PROFILES[$profile])) {
            return array_map(
                static fn(string $model): array => ['vendor'=>(string)self::FIRMWARE_PROFILES[$profile]['vendor'], 'model'=>$model],
                self::FIRMWARE_PROFILES[$profile]['models']
            );
        }
        if (preg_match('/^(yealink|grandstream):([a-z0-9]+)$/', strtolower($profile), $match)) {
            foreach (self::CATALOG as $row) {
                if (strtolower((string)$row[0]) === $match[1]
                    && self::firmwareModelToken((string)$row[1]) === $match[2]) {
                    return [['vendor'=>(string)$row[0], 'model'=>(string)$row[1]]];
                }
            }
        }
        return [];
    }

    public static function firmwareModelToken(string $model): string
    {
        return strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '', $model));
    }

    public static function unsupportedReason(string $vendor, string $model): ?string
    {
        return self::UNSUPPORTED_MODELS[strtoupper(trim($vendor)) . '|' . strtoupper(trim($model))] ?? null;
    }

    private static function catalogHasModel(string $vendor, string $model): bool
    {
        foreach (self::CATALOG as $row) {
            if (strcasecmp((string)$row[0], $vendor) === 0 && strcasecmp((string)$row[1], $model) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Official background-image resolution directory per model, straight from
     * https://usecallmanager.nz/background-images.html (NOT guessed, NOT taken
     * from leftover TFTP files). Returns null for models Cisco doesn't document
     * custom-background support for at all (78xx, 8811, 8831, legacy 7940/7960,
     * and non-Cisco vendors) — the caller must not fabricate a directory for
     * those; there's no confirmed mechanism.
     */
    public const BACKGROUND_DIRS = [
        '7906' => '95x34x1', '7911' => '95x34x1',
        '7941' => '320x196x4', '7942' => '320x196x4', '7961' => '320x196x4', '7962' => '320x196x4',
        '7945' => '320x212x16', '7965' => '320x212x16',
        '7970' => '320x212x12', '7971' => '320x212x12',
        '7975' => '320x216x16',
        '8821' => '240x320x24',
        '8841' => '800x480x24', '8845' => '800x480x24', '8851' => '800x480x24', '8861' => '800x480x24', '8865' => '800x480x24',
        '8941' => '640x480x24', '8945' => '640x480x24',
        '8961' => '640x480x24', '9951' => '640x480x24', '9971' => '640x480x24',
    ];

    /** Exact full and thumbnail canvas sizes from the Cisco background guide. */
    public const BACKGROUND_PROFILES = [
        '7906' => [95,34,23,8], '7911' => [95,34,23,8],
        '7941' => [320,196,80,49], '7942' => [320,196,80,49], '7961' => [320,196,80,49], '7962' => [320,196,80,49],
        '7945' => [320,212,80,53], '7965' => [320,212,80,53], '7970' => [320,212,80,53], '7971' => [320,212,80,53],
        '7975' => [320,216,80,54], '8821' => [240,320,117,117],
        '8841' => [800,480,139,109], '8845' => [800,480,139,109], '8851' => [800,480,139,109], '8861' => [800,480,139,109], '8865' => [800,480,139,109],
        '8941' => [640,480,123,111], '8945' => [640,480,123,111], '8961' => [640,480,123,111], '9951' => [640,480,123,111], '9971' => [640,480,123,111],
    ];

    /** Verified standard image canvases; unlisted models remain explicit/manual. */
    public const HOSTED_MEDIA_SIZES = [
        'CISCO|7940'=>[90,56], 'CISCO|7960'=>[90,56],
        'GRANDSTREAM|GXP2130'=>[320,240], 'GRANDSTREAM|GXP2135'=>[320,240],
        'GRANDSTREAM|GRP2612'=>[320,240], 'GRANDSTREAM|GRP2613'=>[320,240],
        'GRANDSTREAM|GRP2614'=>[320,240], 'GRANDSTREAM|GRP2624'=>[320,240],
        'GRANDSTREAM|GRP2634'=>[320,240],
        'GRANDSTREAM|GXP2140'=>[480,272], 'GRANDSTREAM|GXP2160'=>[480,272],
        'GRANDSTREAM|GXP2170'=>[480,272], 'GRANDSTREAM|GRP2615'=>[480,272],
        'GRANDSTREAM|GRP2616'=>[480,272], 'GRANDSTREAM|GRP2636'=>[480,272],
        'GRANDSTREAM|GHP630'=>[480,320], 'GRANDSTREAM|GHP630W'=>[480,320],
        'GRANDSTREAM|GHP631'=>[480,320], 'GRANDSTREAM|GHP631W'=>[480,320],
        'GRANDSTREAM|GRP2650'=>[1280,720], 'GRANDSTREAM|GRP2670'=>[1024,600],
        'YEALINK|T32G'=>[320,240], 'YEALINK|T33G'=>[320,240], 'YEALINK|T33P'=>[320,240],
        'YEALINK|T29G'=>[480,272], 'YEALINK|T46G'=>[480,272],
        'YEALINK|T46S'=>[480,272], 'YEALINK|T46U'=>[480,272], 'YEALINK|T54W'=>[480,272],
        'YEALINK|T48G'=>[800,480], 'YEALINK|T48S'=>[800,480],
        'YEALINK|T48U'=>[800,480], 'YEALINK|T57W'=>[800,480],
        'YEALINK|T58A'=>[1024,600], 'YEALINK|VP59'=>[1280,800],
    ];

    /**
     * Standard URL-delivered wallpaper profiles. Cisco List.xml profiles are
     * added by wallpaperProfiles() from BACKGROUND_DIRS/BACKGROUND_PROFILES.
     * Models absent here are not silently assigned a guessed canvas.
     */
    public const STANDARD_WALLPAPER_PROFILES = [
        'yealink:320x240' => [
            'vendor'=>'Yealink', 'family'=>'yealink', 'series_key'=>'yealink_t3_color',
            'series'=>'T3 color', 'models'=>['T32G','T33G','T33P'],
            'width'=>320, 'height'=>240, 'format'=>'png', 'max_bytes'=>2097152,
            'delivery'=>'hosted_http', 'dir'=>'320x240',
        ],
        'yealink:480x272' => [
            'vendor'=>'Yealink', 'family'=>'yealink', 'series_key'=>'yealink_t4_t5_color',
            'series'=>'T4 / T5 color', 'models'=>['T29G','T46G','T46S','T46U','T54W'],
            'width'=>480, 'height'=>272, 'format'=>'png', 'max_bytes'=>2097152,
            'delivery'=>'hosted_http', 'dir'=>'480x272',
        ],
        'yealink:800x480' => [
            'vendor'=>'Yealink', 'family'=>'yealink', 'series_key'=>'yealink_t4_t5_touch',
            'series'=>'T4 / T5 touch', 'models'=>['T48G','T48S','T48U','T57W'],
            'width'=>800, 'height'=>480, 'format'=>'png', 'max_bytes'=>2097152,
            'delivery'=>'hosted_http', 'dir'=>'800x480',
        ],
        'yealink:1024x600' => [
            'vendor'=>'Yealink', 'family'=>'yealink', 'series_key'=>'yealink_t5_android',
            'series'=>'T5 Android', 'models'=>['T58A'],
            'width'=>1024, 'height'=>600, 'format'=>'png', 'max_bytes'=>5242880,
            'delivery'=>'hosted_http', 'dir'=>'1024x600',
        ],
        'yealink:1280x800' => [
            'vendor'=>'Yealink', 'family'=>'yealink', 'series_key'=>'yealink_vp_android',
            'series'=>'VP Android', 'models'=>['VP59'],
            'width'=>1280, 'height'=>800, 'format'=>'png', 'max_bytes'=>5242880,
            'delivery'=>'hosted_http', 'dir'=>'1280x800',
        ],
        'grandstream:320x240' => [
            'vendor'=>'Grandstream', 'family'=>'grandstream', 'series_key'=>'grandstream_gxp_grp_color',
            'series'=>'GXP21xx / GRP26xx color',
            'models'=>['GXP2130','GXP2135','GRP2612','GRP2613','GRP2614','GRP2624','GRP2634'],
            'width'=>320, 'height'=>240, 'format'=>'jpg', 'max_bytes'=>512000,
            'delivery'=>'hosted_http', 'dir'=>'320x240',
        ],
        'grandstream:480x272' => [
            'vendor'=>'Grandstream', 'family'=>'grandstream', 'series_key'=>'grandstream_gxp_grp_hd',
            'series'=>'GXP21xx / GRP26xx color',
            'models'=>['GXP2140','GXP2160','GXP2170','GRP2615','GRP2616','GRP2636'],
            'width'=>480, 'height'=>272, 'format'=>'jpg', 'max_bytes'=>512000,
            'delivery'=>'hosted_http', 'dir'=>'480x272',
        ],
        'grandstream:480x320' => [
            'vendor'=>'Grandstream', 'family'=>'grandstream', 'series_key'=>'grandstream_ghp63x',
            'series'=>'GHP63x', 'models'=>['GHP630','GHP630W','GHP631','GHP631W'],
            'width'=>480, 'height'=>320, 'format'=>'jpg', 'max_bytes'=>512000,
            'delivery'=>'hosted_http', 'dir'=>'480x320',
        ],
        'grandstream:1024x600' => [
            'vendor'=>'Grandstream', 'family'=>'grandstream', 'series_key'=>'grandstream_grp2670',
            'series'=>'GRP2670 touch', 'models'=>['GRP2670'],
            'width'=>1024, 'height'=>600, 'format'=>'jpg', 'max_bytes'=>512000,
            'delivery'=>'hosted_http', 'dir'=>'1024x600',
        ],
        'grandstream:1280x720' => [
            'vendor'=>'Grandstream', 'family'=>'grandstream', 'series_key'=>'grandstream_grp2650',
            'series'=>'GRP2650 HD', 'models'=>['GRP2650'],
            'width'=>1280, 'height'=>720, 'format'=>'jpg', 'max_bytes'=>512000,
            'delivery'=>'hosted_http', 'dir'=>'1280x720',
        ],
    ];

    /** Explicit manual/special cases; these never enter the standard generator. */
    public const NON_STANDARD_WALLPAPER_MODELS = [
        'CISCO|7940'=>'Legacy 90x56 palette BMP logo',
        'CISCO|7960'=>'Legacy 90x56 palette BMP logo',
    ];

    public static function backgroundDir(string $vendor, string $model): ?string
    {
        if (strcasecmp($vendor, 'Cisco') !== 0) {
            return null;
        }
        return self::BACKGROUND_DIRS[strtoupper($model)] ?? null;
    }

    public static function backgroundProfile(string $vendor, string $model): ?array
    {
        if (strcasecmp($vendor, 'Cisco') !== 0) {
            return null;
        }
        return self::BACKGROUND_PROFILES[strtoupper($model)] ?? null;
    }

    /** Stable key for a wallpaper render/crop profile shared by compatible models. */
    public static function backgroundProfileKey(string $vendor, string $model): ?string
    {
        $dir = self::backgroundDir($vendor, $model);
        $profile = self::backgroundProfile($vendor, $model);
        if ($dir === null || $profile === null) { return null; }
        return 'cisco:' . $dir . ':' . implode('x', array_map('intval', $profile));
    }

    /** @return array<int,array{key:string,dir:string,dimensions:array{0:int,1:int,2:int,3:int},models:array<int,string>}> */
    public static function backgroundProfilesForDirectory(string $directory): array
    {
        $profiles = [];
        foreach (self::BACKGROUND_DIRS as $model => $candidate) {
            if ($candidate !== $directory || !isset(self::BACKGROUND_PROFILES[$model])) { continue; }
            $dimensions = array_map('intval', self::BACKGROUND_PROFILES[$model]);
            $key = 'cisco:' . $directory . ':' . implode('x', $dimensions);
            if (!isset($profiles[$key])) {
                $profiles[$key] = [
                    'key'=>$key, 'dir'=>$directory, 'dimensions'=>$dimensions, 'models'=>[],
                ];
            }
            $profiles[$key]['models'][] = (string)$model;
        }
        return array_values($profiles);
    }

    /** @return array{key:string,dir:string,dimensions:array{0:int,1:int,2:int,3:int},models:array<int,string>}|null */
    public static function backgroundProfileByKey(string $profileKey): ?array
    {
        foreach (array_unique(self::BACKGROUND_DIRS) as $directory) {
            foreach (self::backgroundProfilesForDirectory($directory) as $profile) {
                if (hash_equals($profile['key'], $profileKey)) { return $profile; }
            }
        }
        return null;
    }

    /** @return array{key:string,dir:string,dimensions:array{0:int,1:int,2:int,3:int},models:array<int,string>}|null */
    public static function backgroundProfileForDimensions(
        string $directory,
        array $fullDimensions,
        array $thumbnailDimensions
    ): ?array {
        $actual = array_map('intval', array_merge($fullDimensions, $thumbnailDimensions));
        foreach (self::backgroundProfilesForDirectory($directory) as $profile) {
            if ($profile['dimensions'] === $actual) { return $profile; }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function wallpaperProfiles(): array
    {
        $profiles = [];
        foreach (array_unique(self::BACKGROUND_DIRS) as $directory) {
            foreach (self::backgroundProfilesForDirectory($directory) as $profile) {
                $series = [];
                foreach ($profile['models'] as $model) {
                    $series[self::seriesKey('Cisco', $model, 'cisco_xml')] = true;
                }
                $profiles[] = [
                    'key'=>$profile['key'], 'vendor'=>'Cisco', 'family'=>'cisco_xml',
                    'series_key'=>(string)(array_key_first($series) ?? 'cisco_xml'),
                    'series'=>implode(' / ', array_keys($series)),
                    'models'=>$profile['models'], 'width'=>(int)$profile['dimensions'][0],
                    'height'=>(int)$profile['dimensions'][1],
                    'thumbnail_width'=>(int)$profile['dimensions'][2],
                    'thumbnail_height'=>(int)$profile['dimensions'][3],
                    'format'=>'png', 'max_bytes'=>self::MAX_STANDARD_WALLPAPER_BYTES,
                    'delivery'=>'cisco_list', 'dir'=>$profile['dir'],
                    'grayscale_tones'=>$profile['dir'] === '95x34x1'
                        ? 2
                        : ($profile['dir'] === '320x196x4' ? 16 : 0),
                ];
            }
        }
        foreach (self::STANDARD_WALLPAPER_PROFILES as $key=>$profile) {
            $profiles[] = ['key'=>$key, 'thumbnail_width'=>0, 'thumbnail_height'=>0] + $profile;
        }
        usort($profiles, static fn(array $left, array $right): int =>
            strnatcasecmp(
                (string)$left['vendor'] . '|' . (string)$left['series'] . '|' . (string)$left['key'],
                (string)$right['vendor'] . '|' . (string)$right['series'] . '|' . (string)$right['key']
            )
        );
        return $profiles;
    }

    private const MAX_STANDARD_WALLPAPER_BYTES = 8 * 1024 * 1024;

    /** @return array<string,mixed>|null */
    public static function wallpaperProfileByKey(string $profileKey): ?array
    {
        foreach (self::wallpaperProfiles() as $profile) {
            if (hash_equals((string)$profile['key'], trim($profileKey))) { return $profile; }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function wallpaperProfileForModel(string $vendor, string $model): ?array
    {
        foreach (self::wallpaperProfiles() as $profile) {
            if (strcasecmp((string)$profile['vendor'], trim($vendor)) === 0
                && in_array(strtoupper(trim($model)), $profile['models'], true)) {
                return $profile;
            }
        }
        return null;
    }

    public static function nonStandardWallpaperReason(string $vendor, string $model): ?string
    {
        return self::NON_STANDARD_WALLPAPER_MODELS[
            strtoupper(trim($vendor)) . '|' . strtoupper(trim($model))
        ] ?? null;
    }

    /** Profile key for HTTP-hosted backgrounds used outside Cisco List.xml. */
    public static function hostedMediaProfileKey(string $vendor, string $model): ?string
    {
        $vendorKey = strtolower(trim($vendor));
        $modelKey = strtoupper(trim($model));
        $size = self::hostedMediaSize($vendor, $model);
        if ($vendorKey === 'cisco' && $size !== null) { return 'cisco_cnf:' . implode('x', $size); }
        $profile = self::wallpaperProfileForModel($vendor, $modelKey);
        return $profile && $profile['delivery'] === 'hosted_http' ? (string)$profile['key'] : null;
    }

    public static function hostedMediaSize(string $vendor, string $model): ?array
    {
        return self::HOSTED_MEDIA_SIZES[strtoupper(trim($vendor)) . '|' . strtoupper(trim($model))] ?? null;
    }

    public static function isVideoModel(string $vendor, string $model): bool
    {
        return strcasecmp($vendor, 'Cisco') === 0
            && in_array(strtoupper($model), ['8845', '8865', '8945', '9951', '9971'], true);
    }

    /**
     * The TFTP `Firmware/<series>` subdirectory for a given model is a module
     * organizational convention (firmware is referenced by explicit
     * filename via <loadInformation>, so any directory layout works); unlike
     * backgrounds, Cisco does not mandate a specific firmware directory scheme.
     */
    public static function seriesDir(string $vendor, string $model, string $family = ''): string
    {
        $vendor = strtolower($vendor);
        $model = strtoupper($model);
        if ($vendor === 'yealink') {
            return 'yealink';
        }
        if ($vendor === 'grandstream') {
            return 'grandstream';
        }
        // Cisco
        if (in_array($model, ['7940', '7960'], true) || $family === 'cisco_cnf') {
            return '7940-60';
        }
        $profile = self::firmwareProfile('Cisco', $model);
        $directories = [
            'cisco:sip11'=>'sip11', 'cisco:sip41'=>'sip41', 'cisco:sip42'=>'sip42',
            'cisco:sip45'=>'sip45', 'cisco:sip70'=>'sip70', 'cisco:sip75'=>'sip75',
            'cisco:sip78xx'=>'78xx', 'cisco:sip88xx'=>'88xx-audio',
            'cisco:sip8845_65'=>'8845-65-video', 'cisco:sip8821'=>'8821',
            'cisco:sip8831'=>'8831', 'cisco:sip8832'=>'8832', 'cisco:sip894x'=>'8941-45',
            'cisco:sip8961'=>'8961', 'cisco:sip9951'=>'9951', 'cisco:sip9971'=>'9971',
        ];
        if ($profile !== null && isset($directories[$profile])) { return $directories[$profile]; }
        return 'cisco-other';
    }

    /**
     * Insert missing models and resynchronise catalog-owned capabilities.
     * The column check keeps this safe during upgrades from the old nine-column
     * schema and during fresh-install ordering before runtime migrations run.
     *
     * @return int number of rows inserted
     */
    public static function seed(\PDO $db): int
    {
        $started=!$db->inTransaction();
        if($started&&!$db->beginTransaction()){throw new \RuntimeException('Cannot begin model catalog update');}
        try {
            $count=self::seedRows($db);
            if($started&&!$db->commit()){throw new \RuntimeException('Model catalog commit is unconfirmed; reload its state before retrying');}
            return $count;
        }catch(\Throwable $error){
            if($started&&$db->inTransaction()){$db->rollBack();}
            throw $error;
        }
    }

    private static function seedRows(\PDO $db): int
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = array_column($db->query('PRAGMA table_info(ucmp_models)')->fetchAll(\PDO::FETCH_ASSOC), 'name');
        } else {
            $columns = $db->query('SHOW COLUMNS FROM ucmp_models')->fetchAll(\PDO::FETCH_COLUMN);
        }
        if (!$columns) {
            throw new \RuntimeException('Model catalog schema could not be read; catalog version was not advanced');
        }

        $exists = $db->prepare('SELECT id FROM ucmp_models WHERE vendor = ? AND model = ?');
        $insert = null;
        $update = null;
        $insertColumns = [];
        $updateColumns = [];
        $count = 0;

        foreach (self::CATALOG as $m) {
            $capabilities = self::capabilitiesFor((string)$m[0], (string)$m[1]);
            if ($capabilities === null) {
                throw new \LogicException(sprintf('Missing capabilities for %s %s', $m[0], $m[1]));
            }
            $data = [
                'vendor'=>$m[0], 'model'=>$m[1], 'family'=>$m[2],
                'series_key'=>self::seriesKey((string)$m[0], (string)$m[1], (string)$m[2]),
                'line_keys'=>$m[3], 'supports_softkeys'=>$m[4],
                'supports_featurepolicy'=>$m[5],
                'kem_support'=>((int)$capabilities['max_expansion_modules'] > 0 ? 1 : 0),
                // Firmware must be chosen from a validated bundle, never from a
                // model-level guessed default shipped by an older catalog.
                'default_firmware'=>null, 'notes'=>$m[8],
            ] + $capabilities;
            $data['expansion_types'] = json_encode($data['expansion_types'], JSON_UNESCAPED_SLASHES);
            $data = array_intersect_key($data, array_flip($columns));

            if ($insert === null) {
                $insertColumns = array_keys($data);
                $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $insertColumns);
                $insert = $db->prepare(sprintf(
                    'INSERT INTO ucmp_models (%s) VALUES (%s)',
                    implode(',', $quoted),
                    implode(',', array_fill(0, count($insertColumns), '?'))
                ));
                $updateColumns = array_values(array_diff($insertColumns, ['vendor', 'model', 'notes']));
                $assignments = array_map(static fn(string $column): string => '`' . $column . '`=?', $updateColumns);
                $update = $db->prepare('UPDATE ucmp_models SET ' . implode(',', $assignments) . ' WHERE id=?');
            }

            $exists->execute([$m[0], $m[1]]);
            $id = $exists->fetchColumn();
            if ($id === false) {
                $insert->execute(array_map(static fn(string $column) => $data[$column], $insertColumns));
                $count++;
            } else {
                $values = array_map(static fn(string $column) => $data[$column], $updateColumns);
                $values[] = (int)$id;
                $update->execute($values);
            }
        }

        // Some upgraded databases can contain a Cisco 7950 row even though this
        // module has no verified SIP/config profile for it. Keep any referenced
        // row visible as unsupported rather than guessing another 79xx profile.
        $quarantine = [
            'family'=>'unsupported', 'line_keys'=>0, 'supports_softkeys'=>0,
            'supports_featurepolicy'=>0, 'kem_support'=>0, 'default_firmware'=>null,
        ];
        if (in_array('role', $columns, true)) { $quarantine['role'] = 'unsupported'; }
        foreach (array_keys(self::CAPABILITY_DEFAULT) as $column) {
            if (!in_array($column, $columns, true) || in_array($column, ['role', 'firmware_profile'], true)) { continue; }
            $value = self::CAPABILITY_DEFAULT[$column];
            $quarantine[$column] = $column === 'expansion_types'
                ? json_encode($value, JSON_UNESCAPED_SLASHES)
                : $value;
        }
        if (in_array('firmware_profile', $columns, true)) { $quarantine['firmware_profile'] = null; }
        if (in_array('notes', $columns, true)) { $quarantine['notes'] = self::UNSUPPORTED_MODELS['CISCO|7950']; }
        $quarantine = array_intersect_key($quarantine, array_flip($columns));
        if ($quarantine) {
            $assignments = array_map(static fn(string $column): string => '`' . $column . '`=?', array_keys($quarantine));
            $stmt = $db->prepare(
                'UPDATE ucmp_models SET ' . implode(',', $assignments)
                . " WHERE UPPER(vendor)='CISCO' AND UPPER(model)='7950'"
            );
            $stmt->execute(array_values($quarantine));
        }

        return $count;
    }
}
