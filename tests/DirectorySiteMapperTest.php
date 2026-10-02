<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DirectorySiteMapper.php';

use FreePBX\modules\Ucmprovisioner\DirectorySiteMapper;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$rules = DirectorySiteMapper::normalizeRules([
    ['id'=>'office-a','type'=>'attribute','attribute'=>'physicalDeliveryOfficeName','value'=>'Headquarters','site_key'=>'site-a','priority'=>10],
    ['id'=>'ou-b','type'=>'ou','dn'=>'OU=Users,OU=SITE-B,DC=example,DC=com','site_key'=>'site-b','priority'=>20],
    ['id'=>'group-mgmt','type'=>'group','group'=>'Department A Management','site_key'=>'site-a','priority'=>30],
    ['id'=>'range-c','type'=>'number_range','start'=>'5000','end'=>'5999','site_key'=>'site-c','priority'=>40],
], ['site-a','site-b','site-c']);

$office = DirectorySiteMapper::resolve([
    'extension'=>'4019',
    'dn'=>'CN=Example User,OU=Users,OU=SITE-B,DC=example,DC=com',
    'attributes'=>['physicaldeliveryofficename'=>['headquarters']],
], $rules);
$expect($office['status'] === 'mapped' && $office['site_key'] === 'site-a' && $office['priority'] === 10, 'Highest-priority attribute rule did not win');

$ou = DirectorySiteMapper::resolve([
    'extension'=>'4019',
    'dn'=>'CN=Example User,OU=Users,OU=SITE-B,DC=example,DC=com',
    'attributes'=>[],
], $rules);
$expect($ou['status'] === 'mapped' && $ou['site_key'] === 'site-b', 'OU subtree rule did not match');

$group = DirectorySiteMapper::resolve([
    'extension'=>'4019',
    'dn'=>'CN=Example User,OU=Other,DC=example,DC=com',
    'attributes'=>['memberof'=>['CN=Department A Management,OU=Groups,DC=example,DC=com']],
], $rules);
$expect($group['status'] === 'mapped' && $group['site_key'] === 'site-a', 'AD group rule did not match a memberOf DN');

$range = DirectorySiteMapper::resolve(['extension'=>'05001','attributes'=>[]], $rules);
$expect($range['status'] === 'mapped' && $range['site_key'] === 'site-c', 'Number range rule did not compare normalized numeric strings');

$conflicting = DirectorySiteMapper::normalizeRules([
    ['id'=>'a','type'=>'attribute','attribute'=>'l','value'=>'Headquarters','site_key'=>'site-a','priority'=>10],
    ['id'=>'b','type'=>'attribute','attribute'=>'l','value'=>'Headquarters','site_key'=>'site-b','priority'=>10],
], ['site-a','site-b']);
$conflict = DirectorySiteMapper::resolve(['extension'=>'1','attributes'=>['l'=>['HEADQUARTERS']]], $conflicting);
$expect($conflict['status'] === 'conflict' && $conflict['site_key'] === null, 'Equal-priority conflicting mappings must fail closed');

$unmapped = DirectorySiteMapper::resolve(['extension'=>'7000','attributes'=>[]], $rules);
$expect($unmapped['status'] === 'unmapped' && $unmapped['site_key'] === null, 'Unmatched AD object must remain explicitly unmapped');

foreach ([
    [[['type'=>'number_range','start'=>'20','end'=>'10','site_key'=>'site-a']], 'starts after'],
    [[['type'=>'attribute','attribute'=>'bad attr','value'=>'x','site_key'=>'site-a']], 'valid attribute'],
    [[['type'=>'group','group'=>'x','site_key'=>'missing']], 'unknown site_key'],
] as [$badRules, $message]) {
    try {
        DirectorySiteMapper::normalizeRules($badRules, ['site-a']);
        throw new RuntimeException('Invalid AD site rule was accepted');
    } catch (InvalidArgumentException $e) {
        $expect(str_contains($e->getMessage(), $message), 'Unexpected site-rule validation message: ' . $e->getMessage());
    }
}

echo "Directory site mapping fixtures passed\n";
