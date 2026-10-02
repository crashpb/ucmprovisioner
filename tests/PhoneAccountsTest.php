<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/PhoneAccounts.php';

use FreePBX\modules\Ucmprovisioner\PhoneAccounts;

function accountAssertSame(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . json_encode($expected) . "\nActual: " . json_encode($actual));
    }
}

function accountExpectFailure(callable $operation, string $messagePart): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        if (strpos($exception->getMessage(), $messagePart) === false) {
            throw new RuntimeException("Unexpected validation message: {$exception->getMessage()}");
        }
        return;
    }
    throw new RuntimeException("Expected account validation failure containing: $messagePart");
}

function accountExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$extensions = ['4019'=>true, '4020'=>true, '4021'=>true, '4022'=>true, '4023'=>true];
$yealinkDesk = [
    'family'=>'yealink', 'model'=>'T48U', 'role'=>'desk',
    'sip_accounts'=>4, 'programmable_keys'=>29,
];
$deskAccounts = PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020', 'label'=>'Reception'],
    ['account'=>3, 'button'=>0, 'extension'=>'4021', 'label'=>'Private'],
], $yealinkDesk, $extensions, '4019');
accountAssertSame([
    ['account'=>2, 'button'=>2, 'extension'=>'4020', 'label'=>'Reception'],
    ['account'=>3, 'button'=>0, 'extension'=>'4021', 'label'=>'Private'],
], $deskAccounts, 'Desk phones must preserve separate SIP account and physical-key placement.');

$yealinkBase = [
    'vendor'=>'Yealink', 'family'=>'yealink', 'model'=>'W70B', 'role'=>'dect_base',
    'sip_accounts'=>10, 'handsets_max'=>10, 'programmable_keys'=>0,
];
$baseAccounts = PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020', 'label'=>'Handset 2'],
    ['account'=>3, 'button'=>9, 'extension'=>'4021', 'label'=>'Handset 3'],
], $yealinkBase, $extensions, '4019');
accountAssertSame([
    ['account'=>2, 'button'=>0, 'extension'=>'4020', 'label'=>'Handset 2'],
    ['account'=>3, 'button'=>0, 'extension'=>'4021', 'label'=>'Handset 3'],
], $baseAccounts, 'DECT registrations must not be mistaken for physical line keys.');

$cisco = [
    'family'=>'cisco_xml', 'model'=>'7841', 'role'=>'desk',
    'sip_accounts'=>4, 'programmable_keys'=>4,
];
$sparse = PhoneAccounts::normalize([
    ['account'=>4, 'extension'=>'4020'],
    ['extension'=>'4021'],
], $yealinkDesk, $extensions, '4019');
accountExpect(array_column($sparse, 'account') === [2,4], 'An explicit high account must leave unused lower slots available.');
$ciscoAccounts = PhoneAccounts::normalize([
    ['account'=>2, 'button'=>0, 'extension'=>'4020', 'label'=>'Second line'],
], $cisco, $extensions, '4019');
accountAssertSame([
    ['account'=>2, 'button'=>2, 'extension'=>'4020', 'label'=>'Second line'],
], $ciscoAccounts, 'Cisco accounts require an actual line-key appearance.');

$limitedKeys = array_replace($yealinkDesk, ['programmable_keys'=>2]);
$legacy = PhoneAccounts::normalize([['button'=>4,'extension'=>'4020']],$limitedKeys,$extensions,'4019');
accountExpect($legacy[0]['account']===4 && $legacy[0]['button']===0,'Legacy account-only rows must retain registration without inventing a physical key.');
foreach (['account','account_index','lineIndex'] as $accountKey) {
    accountExpectFailure(static fn()=>PhoneAccounts::normalize([[$accountKey=>4,'button'=>4,'extension'=>'4020']],$limitedKeys,$extensions,'4019'), 'base-key capacity');
}
accountExpectFailure(static fn()=>PhoneAccounts::normalize([['button'=>4,'extension'=>'4020']],array_replace($cisco,['programmable_keys'=>2]),$extensions,'4019'), 'base-key capacity');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020'],
    ['account'=>2, 'button'=>3, 'extension'=>'4021'],
], $yealinkDesk, $extensions, '4019'), 'account 2 is assigned more than once');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020'],
    ['account'=>3, 'button'=>3, 'extension'=>'4020'],
], $yealinkDesk, $extensions, '4019'), 'Extension 4020 is assigned more than once');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4999'],
], $yealinkDesk, $extensions, '4019'), 'Extension 4999 does not exist');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020'],
    ['account'=>3, 'button'=>2, 'extension'=>'4021'],
], $cisco, $extensions, '4019'), 'Line appearance 2 is already occupied');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020'],
    ['account'=>3, 'button'=>3, 'extension'=>'4021'],
    ['account'=>4, 'button'=>4, 'extension'=>'4022'],
    ['account'=>5, 'button'=>5, 'extension'=>'4023'],
], $yealinkDesk, $extensions, '4019'), 'supports 4 SIP accounts');

accountExpectFailure(static fn() => PhoneAccounts::normalize([
    ['account'=>2, 'button'=>2, 'extension'=>'4020'],
], $yealinkDesk, $extensions, ''), 'primary extension');

$handsetCatalog = [
    'YEALINK|W53H'=>true, 'YEALINK|W73H'=>true,
    'GRANDSTREAM|DP730'=>true,
];
$ylOverrides = PhoneAccounts::normalizeDectOverrides([
    'dect_handsets'=>[
        ['slot'=>2, 'model'=>'w73h', 'label'=>'Warehouse', 'identifier'=>'IPUI-2'],
        ['slot'=>1, 'model'=>'W53H', 'label'=>'Front desk', 'identifier'=>''],
    ],
    'yl_dect_account_2_handsets'=>'3,1,3',
    'yl_dect_account_9_handsets'=>'9',
    'settings'=>['note'=>'preserved'],
], $yealinkBase, $handsetCatalog, [1,2]);
accountExpect(($ylOverrides['dect_handsets'][0]['slot'] ?? 0) === 1, 'DECT handset inventory must be sorted by slot.');
accountExpect(($ylOverrides['dect_handsets'][1]['model'] ?? '') === 'W73H', 'DECT handset model names must be normalized.');
accountExpect(($ylOverrides['yl_dect_account_2_handsets'] ?? '') === '1,3', 'DECT routing must deduplicate and sort handset slots.');
accountExpect(!isset($ylOverrides['yl_dect_account_9_handsets']), 'Routing for an inactive SIP account must be removed.');
accountExpect(($ylOverrides['settings']['note'] ?? '') === 'preserved', 'Unrelated advanced overrides must survive DECT normalization.');

$grandstreamBase = [
    'vendor'=>'Grandstream', 'family'=>'grandstream', 'model'=>'DP755', 'role'=>'dect_base',
    'sip_accounts'=>20, 'handsets_max'=>10, 'programmable_keys'=>0,
];
$gsOverrides = PhoneAccounts::normalizeDectOverrides([
    'dect_handsets'=>[['slot'=>1, 'model'=>'DP730', 'label'=>'Security', 'identifier'=>'HS-01']],
    'gs_dect_account_2_handset'=>'2',
    'gs_dect_account_2_profile'=>'4',
], $grandstreamBase, $handsetCatalog, [1,2]);
accountExpect(($gsOverrides['gs_dect_account_2_handsets'] ?? '') === '2' && !isset($gsOverrides['gs_dect_account_2_handset']), 'Legacy Grandstream single-handset routing must migrate to the plural key.');
accountExpect(($gsOverrides['gs_dect_account_2_profile'] ?? '') === '4', 'Grandstream DECT profile must remain bounded and explicit.');

accountExpectFailure(static fn() => PhoneAccounts::normalizeDectOverrides([
    'dect_handsets'=>[['slot'=>1, 'model'=>'DP730']],
], $yealinkBase, $handsetCatalog, [1]), 'not a supported YEALINK handset');

accountExpectFailure(static fn() => PhoneAccounts::normalizeDectOverrides([
    'gs_dect_account_2_handsets'=>'103',
], $grandstreamBase, $handsetCatalog, [1,2]), 'outside this base');

echo "Phone account fixtures passed\n";
