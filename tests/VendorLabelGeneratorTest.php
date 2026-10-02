<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/YealinkGenerator.php';
require_once dirname(__DIR__) . '/lib/GrandstreamGenerator.php';

use FreePBX\modules\Ucmprovisioner\GrandstreamGenerator;
use FreePBX\modules\Ucmprovisioner\YealinkGenerator;

function vendorContext(string $model, string $vendor): array
{
    return [
        'phone' => ['mac'=>'001122AABBCC', 'family'=>$vendor],
        'model' => ['model'=>$model, 'family'=>$vendor, 'line_keys'=>6, 'sip_accounts'=>4],
        'settings' => ['pbx_addr'=>'10.0.0.10', 'sip_port'=>'5060', 'transport'=>'udp'],
        'lines' => [
            ['button'=>1, 'featureId'=>9, 'lineIndex'=>1, 'label'=>'Line Alpha', 'displayName'=>'Caller Alpha', 'name'=>'301', 'authName'=>'301', 'secret'=>'one'],
            ['button'=>2, 'featureId'=>9, 'lineIndex'=>2, 'label'=>'Line Beta', 'displayName'=>'Caller Beta', 'name'=>'302', 'authName'=>'302', 'secret'=>'two'],
        ],
    ];
}

function vendorContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}

$yealinkContext = vendorContext('T48U', 'yealink');
$yealinkContext['lines'][0]['label'] = str_repeat('آ', 100);
$yealinkContext['settings']['account.1.label'] = 'Advanced Label Bypass';
$yealinkContext['settings']['account.1.display_name'] = 'Advanced Caller Bypass';
$yealink = (new YealinkGenerator())->generate($yealinkContext);
vendorContains('account.1.label = ' . str_repeat('آ', 99), $yealink['content'], 'Yealink account label must use the resolved line label and truncate by UTF-8 character.');
vendorContains('account.1.display_name = Caller Alpha', $yealink['content'], 'Yealink SIP display identity must not reuse the line-key label.');
vendorContains('linekey.1.label = ' . str_repeat('آ', 99), $yealink['content'], 'Yealink line-key label must share the documented 99-character label limit.');
if (str_contains($yealink['content'], 'Advanced Label Bypass') || str_contains($yealink['content'], 'Advanced Caller Bypass')
    || count(array_filter($yealink['warnings'], static fn(string $warning): bool => str_contains($warning, 'AD/template identity owns'))) !== 2) {
    throw new RuntimeException('Advanced Yealink settings must not bypass resolved account-label or caller-identity precedence.');
}
if (count(array_filter($yealink['warnings'], static fn(string $warning): bool => str_contains($warning, '99-character'))) < 2) {
    throw new RuntimeException('Yealink account and DSS label truncation must each be reported.');
}

$yealinkDectContext = vendorContext('W70B', 'yealink');
$yealinkDectContext['model'] += ['role'=>'base', 'handsets_max'=>8, 'config_profile'=>'yealink_dect_single'];
$yealinkDectContext['lines'][0]['label'] = 'Department Alpha Long Label';
$yealinkDectContext['settings'] += [
    'yl_dect_account_1_handsets'=>'1',
    'yl_dect_account_2_handsets'=>'1',
    'handset.1.name'=>'Advanced Handset Bypass',
    'dect_handsets'=>[
        ['slot'=>2, 'model'=>'W73H', 'label'=>str_repeat('ب', 25), 'identifier'=>'hs-two'],
        ['slot'=>9, 'model'=>'W73H', 'label'=>'Outside', 'identifier'=>'hs-nine'],
    ],
];
$yealinkDect = (new YealinkGenerator())->generate($yealinkDectContext);
vendorContains('handset.1.name = Department Alpha Long La', $yealinkDect['content'], 'Yealink handset name must derive from the lowest assigned account line label even before inventory metadata is recorded.');
vendorContains('handset.2.name = ' . str_repeat('ب', 24), $yealinkDect['content'], 'Yealink explicit handset labels must win and honor the 24-character limit.');
if (str_contains($yealinkDect['content'], 'Advanced Handset Bypass')) {
    throw new RuntimeException('Advanced Yealink settings must not bypass managed DECT handset-name precedence.');
}
if (!array_filter($yealinkDect['warnings'], static fn(string $warning): bool => str_contains($warning, 'multiple assigned accounts'))
    || !array_filter($yealinkDect['warnings'], static fn(string $warning): bool => str_contains($warning, 'outside this base'))) {
    throw new RuntimeException('Yealink ambiguous and out-of-capacity handset inventory must be explicit in warnings.');
}

$deskContext = vendorContext('GRP2634', 'grandstream');
$deskContext['settings']['gs_P270'] = 'Advanced Account Bypass';
$deskContext['settings']['gs_P3'] = 'Advanced Caller Bypass';
$deskResult = (new GrandstreamGenerator())->generate($deskContext);
$desk = $deskResult['content'];
foreach ([
    '<P270>Line Alpha</P270>',
    '<P3>Caller Alpha</P3>',
    '<P417>Line Beta</P417>',
    '<P407>Caller Beta</P407>',
] as $needle) {
    vendorContains($needle, $desk, 'Grandstream desk account label and SIP caller identity must remain separate.');
}
if (str_contains($desk, 'Advanced Account Bypass') || str_contains($desk, 'Advanced Caller Bypass')
    || count(array_filter($deskResult['warnings'], static fn(string $warning): bool => str_contains($warning, 'AD/template identity owns'))) !== 2) {
    throw new RuntimeException('Advanced Grandstream P-values must not bypass resolved desk account or caller identity.');
}

$dp752Context = vendorContext('DP752', 'grandstream');
$dp752Context['model'] += ['role'=>'base', 'handsets_max'=>5];
$dp752Context['settings'] += [
    'gs_dect_account_1_handsets'=>'1',
    'gs_dect_account_2_handsets'=>'1',
    'gs_P4180'=>'Advanced DP Caller Bypass',
    'gs_P27020'=>'Advanced DP Handset Bypass',
    'dect_handsets'=>[
        ['slot'=>2, 'model'=>'DP730', 'label'=>str_repeat('پ', 11), 'identifier'=>'hs-two'],
    ],
];
$dp752 = (new GrandstreamGenerator())->generate($dp752Context);
vendorContains('<P4180>Caller Alpha</P4180>', $dp752['content'], 'DP75x P418x Name must carry SIP caller identity, not the independent line label.');
vendorContains('<P27020>Line Alpha</P27020>', $dp752['content'], 'DP75x handset name must derive from the lowest assigned account line label even before inventory metadata is recorded.');
vendorContains('<P27021>' . str_repeat('پ', 10) . '</P27021>', $dp752['content'], 'DP75x explicit handset name must truncate by UTF-8 character at ten.');
if (str_contains($dp752['content'], 'Advanced DP Caller Bypass') || str_contains($dp752['content'], 'Advanced DP Handset Bypass')) {
    throw new RuntimeException('Advanced Grandstream P-values must not bypass DP75x caller or handset identity.');
}
if (!array_filter($dp752['warnings'], static fn(string $warning): bool => str_contains($warning, 'multiple assigned accounts'))) {
    throw new RuntimeException('DP75x multiple-account handset naming choice must be reported.');
}

$dp755Context = vendorContext('DP755', 'grandstream');
$dp755Context['model'] += ['role'=>'base', 'handsets_max'=>10, 'config_profile'=>'grandstream_dp755_alias_v2'];
$dp755Context['settings'] += [
    'gs_dect_account_1_handsets'=>'1',
    'dect_handsets'=>[['slot'=>1, 'model'=>'DP735', 'label'=>'Reception Long', 'identifier'=>'hs-one']],
];
$dp755 = (new GrandstreamGenerator())->generate($dp755Context);
vendorContains('<item name="account.1.name">Line Alpha</item>', $dp755['content'], 'DP755 alias account name must use the resolved line label.');
vendorContains('<item name="account.1.sip.subscriber.name">Caller Alpha</item>', $dp755['content'], 'DP755 alias SIP subscriber name must use caller identity.');
vendorContains('<item name="hs.1.user.hsName">Reception</item>', $dp755['content'], 'DP755 handset inventory override must use the documented ten-character field.');

$dp755LongContext = $dp755Context;
$dp755LongContext['lines'][0]['label'] = str_repeat('ژ', 513);
$dp755LongContext['lines'][0]['displayName'] = str_repeat('گ', 513);
$dp755Long = (new GrandstreamGenerator())->generate($dp755LongContext);
vendorContains('<item name="account.1.name">' . str_repeat('ژ', 512) . '</item>', $dp755Long['content'], 'DP755 alias account name must use its profile-specific 512-character limit.');
vendorContains('<item name="account.1.sip.subscriber.name">' . str_repeat('گ', 512) . '</item>', $dp755Long['content'], 'DP755 alias SIP name must truncate independently at 512 UTF-8 characters.');
if (count(array_filter($dp755Long['warnings'], static fn(string $warning): bool => str_contains($warning, '512-character'))) < 2) {
    throw new RuntimeException('DP755 alias account and SIP-name truncation must each be reported.');
}

echo "Vendor label generator assertions passed.\n";
