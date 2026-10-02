<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/DirectorySiteMapper.php';
require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
require_once dirname(__DIR__) . '/lib/DirectorySourceService.php';

use FreePBX\modules\Ucmprovisioner\DirectorySourceService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (callable $call, string $message): void {
    try { $call(); } catch (Throwable) { return; }
    throw new RuntimeException($message);
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec(
    'CREATE TABLE ucmp_directory_sources (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_key TEXT NOT NULL UNIQUE, domain TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL DEFAULT 1,
        endpoints_json TEXT, tls_mode TEXT NOT NULL, secret_ref TEXT, base_dn TEXT, filter TEXT,
        attribute_map_json TEXT, site_rules_json TEXT, schedule_minutes INTEGER NOT NULL DEFAULT 60,
        schedule_json TEXT,
        last_accepted_revision TEXT, last_attempt INTEGER NOT NULL DEFAULT 0,
        last_success INTEGER NOT NULL DEFAULT 0, last_error TEXT,
        created INTEGER NOT NULL DEFAULT 0, updated INTEGER NOT NULL DEFAULT 0
    )'
);
$db->exec(
    'CREATE TABLE ucmp_directory_sync_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, source_id INTEGER NOT NULL,
        started INTEGER NOT NULL DEFAULT 0, finished INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL, candidate_revision TEXT, accepted_revision TEXT,
        object_count INTEGER NOT NULL DEFAULT 0, added INTEGER NOT NULL DEFAULT 0,
        changed INTEGER NOT NULL DEFAULT 0, deleted INTEGER NOT NULL DEFAULT 0, error TEXT
    )'
);

$service = new DirectorySourceService($db, ['site-a', 'site-b']);
$source = $service->save([
    'source_key'=>'example-corp',
    'domain'=>'Example.COM.',
    'enabled'=>'1',
    'endpoints'=>[
        ['host'=>'dc02.example.com','port'=>636,'priority'=>20],
        ['host'=>'DC01.example.com','port'=>636,'priority'=>10],
    ],
    'tls_mode'=>'ldaps',
    'secret_ref'=>'file:example-corp',
    'base_dn'=>'OU=Phone Users,DC=example,DC=com',
    'filter'=>'(&(objectCategory=person)(objectClass=user))',
    'attribute_map'=>[
        'display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName',
        'department'=>'department','company'=>'company','mail'=>'mail','mobile'=>'mobile',
        'sam_account_name'=>'customLogin','user_principal_name'=>'customUpn','given_name'=>'customFirst','surname'=>'customLast',
        'extra_attributes'=>['memberOf','l','L'],
    ],
    'site_rules'=>[[
        'id'=>'head-office','type'=>'attribute','attribute'=>'physicalDeliveryOfficeName',
        'value'=>'Head Office','site_key'=>'site-a','priority'=>10,
    ]],
    'schedule_minutes'=>15,
]);

$expect($source['domain'] === 'example.com', 'Domain must be canonicalized');
foreach (['sam_account_name'=>'customLogin','user_principal_name'=>'customUpn','given_name'=>'customFirst','surname'=>'customLast'] as $field=>$attribute) {
    $expect($source['attribute_map'][$field]===$attribute,'Custom naming map lost on persistence readback');
}
$expect($source['endpoints'][0]['host'] === 'dc01.example.com', 'Endpoints must be ordered by priority');
$expect($source['secret_configured'] === true && !array_key_exists('password', $source), 'Only an opaque secret reference may be projected');
$expect($source['attribute_map']['extra_attributes'] === ['l','memberof'], 'Additional attributes must be deduplicated deterministically');
$expect($source['site_rules'][0]['site_key'] === 'site-a', 'Validated AD site rules must be preserved');

$db->prepare(
    "INSERT INTO ucmp_directory_sync_runs
        (source_id,started,finished,status,accepted_revision,object_count,added,changed,deleted)
     VALUES (?,?,?,?,?,?,?,?,?)"
)->execute([$source['id'], 100, 120, 'complete', str_repeat('a', 64), 42, 1, 2, 3]);
$listed = $service->all();
$expect(count($listed) === 1 && $listed[0]['latest_run']['object_count'] === 42, 'Latest source run must be projected independently');

$updated = $service->setEnabled((int)$source['id'], false, (int)$source['updated']);
$expect($updated['enabled'] === false && $updated['updated'] > $source['updated'], 'Enable state must use optimistic compare-and-swap');
$throws(
    fn() => $service->setEnabled((int)$source['id'], true, (int)$source['updated']),
    'A stale source editor must not overwrite a newer revision'
);
$throws(
    fn() => $service->save([
        'id'=>$updated['id'], 'expected_updated'=>$updated['updated'],
        'source_key'=>'renamed-authority',
    ]),
    'A source key must remain an immutable AD object namespace'
);
$throws(
    fn() => $service->save([
        'source_key'=>'duplicate-domain','domain'=>'example.com','endpoints'=>['dc03.example.com'],
        'tls_mode'=>'ldaps','secret_ref'=>'file:duplicate-domain','base_dn'=>'DC=example,DC=com',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
    ]),
    'A replicated AD domain belongs in one source with multiple endpoints'
);
$throws(
    fn() => $service->save([
        'source_key'=>'unsafe','domain'=>'example.com','endpoints'=>['https://user:pass@dc.example.com'],
        'tls_mode'=>'ldaps','secret_ref'=>'file:unsafe','base_dn'=>'DC=example,DC=com',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
    ]),
    'Endpoint URLs and embedded credentials must be rejected'
);
$throws(
    fn() => $service->save([
        'source_key'=>'plain','domain'=>'plain.example','endpoints'=>['dc.plain.example'],
        'tls_mode'=>'ldap','secret_ref'=>'file:plain','base_dn'=>'DC=plain,DC=example',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
    ]),
    'New sources must not permit an unencrypted LDAP bind'
);
$throws(
    fn() => $service->save([
        'source_key'=>'wrong-site','domain'=>'other.example','endpoints'=>['dc.other.example'],
        'tls_mode'=>'starttls','secret_ref'=>'file:wrong-site','base_dn'=>'DC=other,DC=example',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
        'site_rules'=>[['id'=>'bad','type'=>'ou','dn'=>'OU=Nowhere,DC=other,DC=example','site_key'=>'unknown']],
    ]),
    'Source rules must reference an exact saved site key'
);

$disabledByDefault = $service->save([
    'source_key'=>'staged-source','domain'=>'staged.example',
    'endpoints'=>['dc.staged.example'],'tls_mode'=>'ldaps',
    'secret_ref'=>'file:staged-source','base_dn'=>'DC=staged,DC=example',
    'attribute_map'=>[
        'display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName',
    ],
]);
$expect($disabledByDefault['enabled'] === false, 'A new source must remain disabled until its credential object is provisioned');

$throws(
    fn() => $service->save([
        'source_key'=>'wrong-ref','domain'=>'wrong-ref.example','endpoints'=>['dc.wrong-ref.example'],
        'tls_mode'=>'ldaps','secret_ref'=>'file:someone-else','base_dn'=>'DC=wrong-ref,DC=example',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
    ]),
    'A source must not reference another authority credential object'
);
$throws(
    fn() => $service->save([
        'source_key'=>'legacy-ref-abuse','domain'=>'legacy-ref-abuse.example','endpoints'=>['dc.legacy-ref-abuse.example'],
        'tls_mode'=>'ldaps','secret_ref'=>'legacy-setting:ad_bind_password','base_dn'=>'DC=legacy-ref-abuse,DC=example',
        'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
    ]),
    'Only legacy-default may use the migrated legacy credential setting'
);
$legacy = $service->save([
    'source_key'=>'legacy-default','domain'=>'legacy.example','endpoints'=>['dc.legacy.example'],
    'tls_mode'=>'ldaps','secret_ref'=>'legacy-setting:ad_bind_password','base_dn'=>'DC=legacy,DC=example',
    'attribute_map'=>['display_name'=>'displayName','extension'=>'telephoneNumber','label'=>'sAMAccountName'],
]);
$expect(
    $legacy['secret_ref'] === 'legacy-setting:ad_bind_password'
    && !array_key_exists('password', $legacy),
    'The migrated source may retain only its opaque legacy credential reference'
);

echo "Directory source model assertions passed\n";
