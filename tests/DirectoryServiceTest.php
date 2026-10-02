<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DirectoryService.php';
set_error_handler(static function ($severity,$message,$file,$line): bool { throw new ErrorException($message,0,$severity,$file,$line); });

use FreePBX\modules\Ucmprovisioner\DirectoryService;

final class DirectoryServiceUcmStub
{
    public array $saved = [];
    public function localSiteId(): int { return 1; }
    public function getSiteSettingsLocal(int $siteId): array
    {
        return ['ad_security'=>'ldap','ad_host'=>'10.0.0.10','ad_port'=>'389','ad_base_dn'=>'OU=Services,DC=example,DC=com'];
    }
    public function dec(string $value): string { return $value; }
    public function enc(string $value): string { return 'encrypted:' . $value; }
    public function setSetting(string $key, string $value, int $siteId): void { $this->saved[$key] = $value; }
}

function directoryExpect(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$ucm = new DirectoryServiceUcmStub();
$service = new DirectoryService($ucm);
$result = $service->createServiceAccount([
    'directory'=>['ad_security'=>'ldap','ad_base_dn'=>'OU=Services,DC=example,DC=com'],
    'service_sam'=>'svc_ucmprovisioner','service_container_dn'=>'OU=Service Accounts,DC=example,DC=com',
]);
directoryExpect(!empty($result['requires_local_run']), 'Plain LDAP must return the safe local PowerShell workflow');
directoryExpect($result['bind_user'] === 'svc_ucmprovisioner', 'The UI should be able to keep a short reader login');
directoryExpect(strpos($result['bootstrap_script'],'svc_ucmprovisioner@example.com') !== false, 'Bootstrap script must derive the UPN from the base DN');
directoryExpect($ucm->saved === [], 'Generating the script must not save credentials before the user runs it');

$method = static function (string $name) {
    $method = new ReflectionMethod(DirectoryService::class,$name); $method->setAccessible(true); return $method;
};
$normalize = $method('normalizedBindUser');
directoryExpect($normalize->invoke($service,'reader','DC=example,DC=com') === 'reader@example.com', 'Short login must expand from the base DN');
directoryExpect($normalize->invoke($service,'EXAMPLE\\reader','DC=example,DC=com') === 'EXAMPLE\\reader', 'DOMAIN\\user login must remain unchanged');

$ouPath = $method('ouPath');
$ous = $ouPath->invoke($service,'CN=Alice,OU=Users,OU=Finance,OU=Tehran,DC=example,DC=com');
directoryExpect($ous === ['Finance','Tehran'], 'OU hierarchy must be ordered from the user upward and skip generic Users containers');
$groupNames = $method('groupNames');
$parsedGroups = $groupNames->invoke($service,['CN=Helpdesk\\, Tier 1,OU=Groups,DC=example,DC=com','CN=All Staff,OU=Groups,DC=example,DC=com']);
directoryExpect($parsedGroups === ['Helpdesk, Tier 1','All Staff'], 'AD memberOf DNs must become readable multi-valued group names: ' . json_encode($parsedGroups));

$compile = $method('compilePhonebook');
$baseBook = [
    'book_key'=>'company','name'=>'Company','filename'=>'company.xml','title'=>'Company Directory','include_mode'=>'all',
    'rules'=>[],'exclusions'=>[],'manual_entries'=>[],'group_by'=>'none','publish_sites'=>['*'],
    'name_format'=>'display_label','sort_by'=>'display_name','include_mobile'=>1,'cisco_page_size'=>32,'is_default'=>1,
];
$entries = [
    ['source_key'=>str_repeat('a',40),'display_name'=>'Jane Smith','label'=>'j.smith','extension'=>'301','department'=>'Sales','ou_path'=>['Sales','Head Office'],
        'attributes'=>['l'=>['Tehran'],'st'=>['Tehran'],'groups'=>['Helpdesk-Team','All Staff']],'groups'=>['Helpdesk-Team','All Staff']],
    ['source_key'=>str_repeat('b',40),'display_name'=>'Bob Daryaei','label'=>'b.daryaei','extension'=>'302','department'=>'Finance','ou_path'=>['Finance','HQ'],'attributes'=>[]],
];

// A v1 linear hierarchy is upgraded in memory, preserving existing saved books.
$legacy = $baseBook + ['hierarchy'=>[['field'=>'site_name','fallback'=>'Unmapped'],['field'=>'ou:1','fallback'=>'Other']]];
$legacyCompiled = $compile->invoke($service,$legacy,$entries);
$legacyTree = $legacyCompiled['layout']['hierarchy'];
directoryExpect(($legacyTree['version']??0) === 2 && ($legacyTree['children'][0]['source']??'') === 'site_name' && ($legacyTree['children'][0]['children'][0]['source']??'') === 'ou:1', 'V1 linear hierarchy must migrate to nested v2 groups');
directoryExpect(
    ($legacyCompiled['contacts'][str_repeat('a',40)]['shown_name'] ?? '') === 'Jane Smith (j.smith)',
    'Phonebook preview must use the saved Display name (short label) formatter rather than showing the raw display name'
);

// Manual city labels can route on AD locality/province, then nest OU and multi-valued groups.
$book = $baseBook + ['hierarchy'=>['version'=>2,'children'=>[
    ['type'=>'branch','label'=>'Tehran Office','when'=>['source'=>'attr:l','operator'=>'is','value'=>'Tehran'],'children'=>[
        ['type'=>'group','source'=>'ou:0','fallback'=>'Other department','aliases'=>[],'children'=>[
            ['type'=>'group','source'=>'groups','fallback'=>'Ungrouped','aliases'=>['Helpdesk-Team'=>'Support'],'children'=>[]],
        ]],
    ]],
    ['type'=>'branch','label'=>'Northern Offices','when'=>['source'=>'attr:st','operator'=>'is','value'=>'Mazandaran'],'children'=>[]],
    ['type'=>'group','source'=>'attr:l','fallback'=>'Unknown city','aliases'=>['Tehran'=>'Capital'],'children'=>[]],
]]];
$compiled = $compile->invoke($service,$book,$entries);
$sharedSearchEntries = [
    ['source_key'=>str_repeat('f',40),'display_name'=>'First Shared','label'=>'first.shared','extension'=>'401','mobile'=>'09357460040','pager'=>'413730'],
    ['source_key'=>str_repeat('9',40),'display_name'=>'Second Shared','label'=>'second.shared','extension'=>'402','mobile'=>'09357460040','pager'=>'413730'],
];
$sharedCompiled = $compile->invoke($service,$baseBook + ['hierarchy'=>['version'=>2,'children'=>[]]],$sharedSearchEntries);
directoryExpect(
    count($sharedCompiled['contacts']) === 2
        && count(array_filter($sharedCompiled['contacts'],static fn(array $contact):bool=>(string)($contact['mobile']??'')==='09357460040')) === 2
        && count(array_filter($sharedCompiled['contacts'],static fn(array $contact):bool=>(string)($contact['pager']??'')==='413730')) === 2,
    'Shared mobile and EMP numbers are searchable attributes and must retain every matching AD user'
);
$layoutBook = $baseBook + ['hide_uncategorised'=>1,'search_enabled'=>0,'search_position'=>'last','search_scope'=>'all',
    'cisco_softkeys'=>['menu'=>['Search','None','Next','Previous'],'list'=>['Search','EditDial','None','Previous']],
    'hierarchy'=>['version'=>2,'children'=>[]]];
$layoutCompiled = $compile->invoke($service,$layoutBook,$entries);
directoryExpect(!empty($layoutCompiled['layout']['hide_uncategorised']), 'Hide Uncategorized must reach the compiled/published layout');
directoryExpect($layoutCompiled['layout']['search_enabled'] === false && $layoutCompiled['layout']['search_position'] === 'last', 'Cisco Search visibility and position must reach the compiled/published layout');
directoryExpect($layoutCompiled['layout']['search_scope'] === 'all', 'Cisco Search scope must reach the compiled/published layout');
directoryExpect(in_array('Select',$layoutCompiled['layout']['cisco_softkeys']['menu'],true) && in_array('Exit',$layoutCompiled['layout']['cisco_softkeys']['menu'],true), 'Cisco menu softkey normalization must retain Select and Exit');
directoryExpect(in_array('Dial',$layoutCompiled['layout']['cisco_softkeys']['list'],true) && in_array('Exit',$layoutCompiled['layout']['cisco_softkeys']['list'],true), 'Cisco list softkey normalization must retain Dial and Exit');
$paths = array_map(static fn($node)=>implode(' / ',$node['path']),array_filter($compiled['nodes'],static fn($node)=>empty($node['children'])&&!empty($node['contacts'])));
directoryExpect(in_array('Tehran Office / Sales / Support',$paths,true) && in_array('Tehran Office / Sales / All Staff',$paths,true), 'Branch, OU, group multi-values, and aliases must compile into distinct navigable leaves');
directoryExpect(in_array('Capital',$paths,true) && in_array('Unknown city',$paths,true), 'Dynamic aliases and missing-value fallback must both remain visible');

// A subtree rule consumes the root-to-leaf OU path and includes every descendant without name-substring leaks.
$subtreeEntries = [
    ['source_key'=>str_repeat('c',40),'display_name'=>'CFO Direct','label'=>'cfo.direct','extension'=>'303','ou_path'=>['CFO','CEO','Example Corporation']],
    ['source_key'=>str_repeat('d',40),'display_name'=>'CFO Child','label'=>'cfo.child','extension'=>'304','ou_path'=>['Payroll','CFO','CEO','Example Corporation']],
    ['source_key'=>str_repeat('e',40),'display_name'=>'Other CFO','label'=>'other.cfo','extension'=>'305','ou_path'=>['CFO','CEO','OtherGroup']],
];
$subtreeBook = $baseBook + ['hierarchy'=>['version'=>2,'children'=>[[
    'type'=>'branch','label'=>'Example Corporation','when'=>['source'=>'ou_path','operator'=>'under','value'=>'Example Corporation / CEO / CFO'],'children'=>[],
]]]];
$subtreeCompiled = $compile->invoke($service,$subtreeBook,$subtreeEntries);
$subtreeNode = array_values(array_filter($subtreeCompiled['nodes'],static fn($node)=>$node['path']===['Example Corporation']))[0] ?? [];
directoryExpect(count($subtreeNode['contacts']??[]) === 2, 'OU subtree must include direct and nested users but exclude a same-named OU under another parent');
directoryExpect(count($subtreeCompiled['nodes']['root']['contacts']) === 1, 'Contacts outside the selected OU subtree must remain explicitly uncategorised');

// A manual path bypasses the AD rule tree and can create arbitrary menus.
$manualBook = $book;
$manualBook['manual_entries'] = [['display_name'=>'Security Desk','label'=>'Security','extension'=>'100','menu_path'=>'Iran / Shared Services / Emergency']];
$manualCompiled = $compile->invoke($service,$manualBook,$entries);
$manualPaths = array_map(static fn($node)=>implode(' / ',$node['path']),$manualCompiled['nodes']);
directoryExpect(in_array('Iran / Shared Services / Emergency',$manualPaths,true), 'Manual menu_path must override dynamic hierarchy routing');
$normalizeCompiled = $method('normalizeCompiledPhonebook');
$wireBook = $manualCompiled; unset($wireBook['entries']);
$wireBook['contacts'] = array_filter($wireBook['contacts'],static fn($contact)=>!empty($contact['manual']));
$catalog = []; foreach ($entries as $entry) { $catalog[$entry['source_key']]=$entry; }
$rehydrated = $normalizeCompiled->invoke($service,$wireBook,$catalog);
directoryExpect(array_keys($rehydrated['contacts']) === array_keys($manualCompiled['contacts']) && array_keys($rehydrated['nodes']) === array_keys($manualCompiled['nodes']), 'Published v2 snapshots must rehydrate the exact compiled contact order/tree while sending AD contacts only once');

// Depth is capped at eight menus and IDs do not depend on contact or input order.
$children=[];
for ($i=9;$i>=0;$i--) { $children=[['type'=>'group','source'=>'attr:l','fallback'=>'Level '.$i,'aliases'=>[],'children'=>$children]]; }
$deepBook = $baseBook + ['hierarchy'=>['version'=>2,'children'=>$children]];
$deepA = $compile->invoke($service,$deepBook,$entries);
$deepB = $compile->invoke($service,$deepBook,array_reverse($entries));
$maxDepth=max(array_map(static fn($node)=>count($node['path']),$deepA['nodes']));
directoryExpect($maxDepth === 8, 'Phone menu depth must never exceed eight');
directoryExpect(array_keys($deepA['nodes']) === array_keys($deepB['nodes']) && array_keys($deepA['contacts']) === array_keys($deepB['contacts']), 'Stable node/contact IDs and ordering must survive input reordering');

echo "Directory service fixtures passed\n";
