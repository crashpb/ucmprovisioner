<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner {
    function ldap_search(...$args): bool { $GLOBALS['person_read_requested'] = $args[3]; return true; }
    function ldap_parse_result($connection,$search,&$error,&$matched,&$message,&$referrals,&$controls): bool {
        $error = $GLOBALS['person_read_error'] ?? 0;
        $controls = ['1.2.840.113556.1.4.319'=>['value'=>['cookie'=>'']]];
        return true;
    }
    function ldap_get_entries($connection,$search): array { return $GLOBALS['person_read_rows']; }
    function ldap_error($connection): string { return 'Synthetic incomplete result'; }
}

namespace {
    require_once __DIR__ . '/../lib/LogicalExtensionService.php';
    require_once __DIR__ . '/../lib/DirectoryService.php';
    use FreePBX\modules\Ucmprovisioner\DirectoryService;
    $expect = static function (bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } };
    $service = new DirectoryService(new stdClass());
    $read = new ReflectionMethod(DirectoryService::class,'readUsers');
    $settings = ['ad_base_dn'=>'DC=example,DC=test','ad_user_filter'=>'(objectClass=user)','ad_attr_display'=>'displayName','ad_attr_extension'=>'telephoneNumber','ad_attr_label'=>'sAMAccountName','ad_attr_department'=>'department','ad_attr_company'=>'company','ad_attr_mail'=>'mail','ad_attr_mobile'=>'mobile','ad_attr_pager'=>'pager'];
    $GLOBALS['person_read_rows'] = ['count'=>1,0=>[
        'dn'=>'CN=Example,DC=example,DC=test',
        'objectguid'=>['count'=>1,str_repeat("\x01",16)],
        'displayname'=>['count'=>1,'Alex Example'],
        'userprincipalname'=>['count'=>1,'a.example@example.test'],
    ]];
    $people = $read->invoke($service,true,$settings);
    $expect(count($people) === 1 && $people[0]['extension'] === '' && $people[0]['label'] === 'a.example', 'Complete LDAP reader must retain a numberless stable person with UPN fallback');
    foreach (['givenName','sn','initials','sAMAccountName','userPrincipalName'] as $attribute) {
        $expect(in_array($attribute,$GLOBALS['person_read_requested'],true), 'Naming attributes must be deliberately requested');
    }
    foreach (['sam_account_name'=>'customLogin','user_principal_name'=>'customUpn','given_name'=>'customFirst','surname'=>'customLast'] as $field=>$attribute) {
        $settings['ad_attr_'.$field]=$attribute;
        $GLOBALS['person_read_rows'][0][strtolower($attribute)]=['count'=>1,'Mapped-'.$field];
    }
    $people=$read->invoke($service,true,$settings);
    foreach (['samaccountname'=>'sam_account_name','userprincipalname'=>'user_principal_name','givenname'=>'given_name','sn'=>'surname'] as $canonical=>$field) {
        $expect(($people[0]['attributes'][$canonical][0] ?? '')==='Mapped-'.$field,'Custom naming attribute was fetched but not normalized');
        $expect(in_array($settings['ad_attr_'.$field],$GLOBALS['person_read_requested'],true),'Custom naming attribute not requested');
    }
    $retainedDb = new \PDO('sqlite::memory:');
    $retainedDb->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $retainedDb->exec('CREATE TABLE ucmp_directory_entries (object_key TEXT,display_name TEXT,label TEXT,extension TEXT,department TEXT,company TEXT,org_unit TEXT,mail TEXT,mobile TEXT,pager TEXT,source_dn TEXT,attributes TEXT,site_id INTEGER,last_seen_at INTEGER,source_id INTEGER)');
    $retainedKey = bin2hex(str_repeat("\x01",16));
    $retainedDb->prepare('INSERT INTO ucmp_directory_entries (object_key,display_name,label,extension,site_id,last_seen_at,source_id,attributes) VALUES (?,?,?,?,?,?,?,?)')->execute([$retainedKey,'Prior Name','prior','4100',3,123,7,'{}']);
    $retainedService = new DirectoryService(new class($retainedDb) {
        public function __construct(private \PDO $db) {}
        public function getDb(): \PDO { return $this->db; }
    });
    $retainedSettings = $settings + ['_source_id'=>7];
    $GLOBALS['person_read_rows'] = ['count'=>1,0=>[
        'dn'=>'CN=Prior,DC=example,DC=test',
        'objectguid'=>['count'=>1,str_repeat("\x01",16)],
    ]];
    $retainedRead = new ReflectionMethod(DirectoryService::class,'readUsers');
    $retained = $retainedRead->invoke($retainedService,true,$retainedSettings);
    $expect(count($retained) === 1 && $retained[0]['display_name'] === 'Prior Name'
        && !empty($retained[0]['_retained_incomplete']) && $retained[0]['_retained_last_seen_at'] === 123,
        'A stable existing person with a temporary blank display must retain the last accepted identity');
    $GLOBALS['person_read_rows'][0]['objectguid'] = ['count'=>1,str_repeat("\x02",16)];
    $newNameless = $retainedRead->invoke($retainedService,true,$retainedSettings);
    $readWarnings = (new ReflectionProperty(DirectoryService::class,'lastReadWarnings'));
    $readWarnings->setAccessible(true);
    $warningText = implode(' ', $readWarnings->getValue($retainedService));
    $expect(count($newNameless) === 0 && str_contains($warningText,'skipped'),
        'A new nameless account must be skipped with an actionable warning, not reject the complete source');
    $GLOBALS['person_read_rows'][1] = $GLOBALS['person_read_rows'][0];
    $GLOBALS['person_read_rows']['count'] = 2;
    try { $read->invoke($service,true,$settings); throw new LogicException('Duplicate skipped GUID accepted'); }
    catch (RuntimeException $e) { $expect(str_contains($e->getMessage(),'repeated an objectGUID'), 'Skipped nameless objects still require unique stable keys'); }
    unset($GLOBALS['person_read_rows'][1]);
    $GLOBALS['person_read_rows']['count'] = 1;
    $brokenCatalog = new DirectoryService(new class {
        public function getDb(): PDO { return new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); }
    });
    try { $read->invoke($brokenCatalog,true,$retainedSettings); throw new LogicException('Failed prior-catalog read ignored'); }
    catch (PDOException $e) { /* An unreadable accepted catalog cannot be treated as empty. */ }
    unset($GLOBALS['person_read_rows'][0]['objectguid']);
    try { $read->invoke($service,true,$settings); throw new LogicException('Missing GUID accepted'); }
    catch (RuntimeException $e) { $expect(str_contains($e->getMessage(),'objectGUID'), 'Missing stable person key must reject snapshot'); }
    $GLOBALS['person_read_error'] = 4;
    try { $read->invoke($service,true,$settings); throw new LogicException('Partial LDAP accepted'); }
    catch (RuntimeException $e) { $expect(str_contains($e->getMessage(),'incomplete'), 'Incomplete LDAP cannot become an empty accepted revision'); }
    echo "Directory stable-person LDAP projection fixtures passed\n";
}
