<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/WebPhonebook.php';
require_once dirname(__DIR__) . '/lib/PhonebookWorkbook.php';
require_once __DIR__ . '/WebPhonebookFixture.php';

use FreePBX\modules\Ucmprovisioner\WebPhonebook;
use FreePBX\modules\Ucmprovisioner\PhonebookWorkbook;

$expect = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
$reject = static function (callable $action, string $message) use ($expect): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    $expect(false, $message);
};
$root = sys_get_temp_dir() . '/ucmp-webbook-test-' . bin2hex(random_bytes(8));
mkdir($root . '/directory', 0700, true);
$cacheJson = json_encode(webPhonebookFixture(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
file_put_contents($root . '/directory/directory.json', $cacheJson);
$service = new WebPhonebook($root);
try {
    $expect(!$service->policy()['enabled'], 'New installations must start disabled');
    $expect($service->policy()['preferred_url'] === '', 'New installations must not inherit a customer directory URL');
    $expect(WebPhonebook::normalizePolicy([])['preferred_url'] === '', 'An optional directory address may be left unconfigured');
    $expect(WebPhonebook::normalizePolicy(['preferred_url'=>'https://directory.example.test/'])['preferred_url'] === 'https://directory.example.test/', 'An explicitly configured directory URL must be preserved');
    foreach (['http://directory.example.test/', 'mailto:user@example.test', 'https://user:pass@example.test/', 'javascript:alert(1)'] as $url) {
        $reject(fn()=>WebPhonebook::normalizePolicy(['preferred_url'=>$url]), 'Unsafe preferred directory URL accepted');
    }
    $expect(!WebPhonebook::peerAllowed($service->policy(), ['REMOTE_ADDR'=>'127.0.0.1']), 'Missing policy must deny access');
    $base = ['enabled'=>true,'networks'=>['192.0.2.128/25','2001:db8:1234::/48'],'books'=>['company','services'],'fields'=>WebPhonebook::DEFAULT_FIELDS];
    $policy = WebPhonebook::normalizePolicy($base);
    $proxy=WebPhonebook::normalizePolicy(array_replace($base,['access_mode'=>'trusted_proxy','trusted_proxies'=>['10.0.0.2']]));
    $expect(WebPhonebook::peerAllowed($proxy,['REMOTE_ADDR'=>'10.0.0.2','HTTP_X_REAL_IP'=>'192.0.2.129']),'Trusted proxy lost allowed client');
    foreach ([['REMOTE_ADDR'=>'10.0.0.3','HTTP_X_REAL_IP'=>'192.0.2.129'],['REMOTE_ADDR'=>'10.0.0.2'],['REMOTE_ADDR'=>'10.0.0.2','HTTP_X_REAL_IP'=>'192.0.2.129, 10.0.0.1'],['REMOTE_ADDR'=>'10.0.0.2','HTTP_X_REAL_IP'=>'192.0.2.129','HTTP_X_FORWARDED_FOR'=>'192.0.2.129'],['REMOTE_ADDR'=>'10.0.0.2','HTTP_X_REAL_IP'=>'8.8.8.8']] as $server) {
        $expect(!WebPhonebook::peerAllowed($proxy,$server),'Unsafe proxy request accepted');
    }
    foreach (['https://user:pass@example.test/','javascript:alert(1)','http://example.test/'] as $url) {
        $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['help_url'=>$url])),'Unsafe help URL accepted');
    }
    $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['networks'=>['8.8.8.0/24']])),'Public client network accepted');
    foreach (['192.0.2.129','192.0.2.255','2001:db8:1234:2::5','::ffff:192.0.2.129'] as $peer) {
        $expect(WebPhonebook::peerAllowed($policy,['REMOTE_ADDR'=>$peer]), 'CIDR unexpectedly denied '.$peer);
    }
    foreach (['192.0.2.127','192.0.3.129','2001:db8:1235::1','unknown',''] as $peer) {
        $expect(!WebPhonebook::peerAllowed($policy,['REMOTE_ADDR'=>$peer]), 'CIDR unexpectedly allowed '.$peer);
    }
    foreach (['HTTP_X_FORWARDED_FOR','HTTP_FORWARDED','HTTP_X_REAL_IP'] as $header) {
        $decision=WebPhonebook::accessDecision($policy,['REMOTE_ADDR'=>'192.0.2.129',$header=>'198.51.100.7']);
        $expect($decision['allowed']&&$decision['peer']==='192.0.2.129'&&$decision['client']==='192.0.2.129', 'Direct mode must use the actual peer and ignore '.$header);
    }
    foreach (['0.0.0.0/0','::/0','192.0.2.1','192.0.2.0/99','2001:db8::/129','host/24'] as $cidr) {
        $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['networks'=>[$cidr]])), 'Invalid network accepted');
    }
    $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['networks'=>[]])), 'Enabled empty networks accepted');
    $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['books'=>[]])), 'Enabled empty books accepted');
    $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['fields'=>['source_key']])), 'Private field accepted');
    $reject(fn()=>WebPhonebook::normalizePolicy(array_replace($base,['books'=>['../private']])), 'Invalid book key accepted');
    $beforeRevision = $service->policyRevision();
    $service->savePolicy($base, $beforeRevision);
    $reject(fn()=>$service->savePolicy($base,$beforeRevision),'Stale settings save accepted');
    $model = $service->model($service->policy(),'company');
    $publication=json_decode(file_get_contents($root.'/directory/directory.json'),true);
    $publication['generated']++;
    $publication['source_freshness']=[['last_success'=>time(),'revision'=>'new-poll-same-contacts']];
    file_put_contents($root.'/directory/directory.json',json_encode($publication));
    $expect($service->model($service->policy(),'company')['revision']===$model['revision'],'Unchanged scheduled publication must not break open print/export selections');
    $publication['phonebooks'][0]['contacts']['fixture-person-1']['display_name']='Changed Person';
    file_put_contents($root.'/directory/directory.json',json_encode($publication));
    $expect($service->model($service->policy(),'company')['revision']!==$model['revision'],'Changed contact values must invalidate output selection');
    file_put_contents($root.'/directory/directory.json',$cacheJson);
    $expect(count($model['contacts'])===36,'Compiled membership changed');
    $serialized=json_encode($model,JSON_THROW_ON_ERROR);
    foreach (['DO NOT PUBLISH','Private phonebook','Excluded root contact','source_key','private.example','ou_path','fixture-person','004726','202 555'] as $hidden) {
        $expect(!str_contains($serialized,$hidden),'Hidden contact field or identity leaked: '.$hidden);
    }
    $expect(count($model['books'])===2,'Unselected phonebook name was exposed');
    $minimalPolicy=WebPhonebook::normalizePolicy(array_replace($base,['fields'=>['name','extension']]));
    $expect(array_keys($service->model($minimalPolicy,'company')['fields'])===['name','extension'],'Unchecking every optional field restored default publication fields');
    $reject(fn()=>$service->model($service->policy(),'private'),'Unpublished web book accepted');
    $ids=array_column($model['contacts'],'id');
    $request=['book'=>'company','revision'=>$model['revision'],'scope'=>'selected','columns'=>['name','extension'],'ids'=>[$ids[0],$ids[2]]];
    $selected=WebPhonebook::exportSelection($model,$request);
    $expect(array_column($selected['rows'],'name')===['Ava Mehr','=2+2'],'Selection order/identity changed');
    $reject(fn()=>WebPhonebook::exportSelection($model,array_replace($request,['columns'=>['name','mobile']])), 'Hidden export field accepted');
    $reject(fn()=>WebPhonebook::exportSelection($model,array_replace($request,['ids'=>['unpublished-person']])), 'Forged contact accepted');
    $reject(fn()=>WebPhonebook::exportSelection($model,array_replace($request,['scope'=>'all'])), 'Partial whole-book request accepted');
    $reject(fn()=>WebPhonebook::exportSelection($model,array_replace($request,['scope'=>'unknown'])), 'Unknown export scope accepted');
    $reject(fn()=>WebPhonebook::exportSelection($model,array_replace($request,['revision'=>'old'])), 'Stale export accepted');
    $other=$service->model($service->policy(),'services');
    $reject(fn()=>WebPhonebook::exportSelection($other,array_replace($request,['revision'=>$other['revision']])), 'Cross-book contact accepted');
    $all=WebPhonebook::exportSelection($model,array_replace($request,['scope'=>'all','ids'=>$ids]));
    $expect(count($all['rows'])===36,'Whole-book output lost contacts');

    $allPolicy=array_replace($base,['fields'=>array_keys(WebPhonebook::FIELDS)]);
    $service->savePolicy($allPolicy,$service->policyRevision());
    $allModel=$service->model($service->policy(),'company');
    $expect(count(array_filter($allModel['contacts'],static fn($c)=>$c['mobile']==='+1 202 555 0106'))===36,'Shared number removed distinct contacts');
    $expect($allModel['revision']!==$model['revision'],'Field-policy change did not invalidate revision');
    $expect($allModel['contacts'][0]['alternate']===$allModel['contacts'][0]['label'],'Alternate name did not use approved projection');
    $reject(fn()=>WebPhonebook::exportSelection(array_replace($model,['export_enabled'=>false]),$request),'Disabled export accepted');
    $bytes=PhonebookWorkbook::create($allModel['contacts'],array_keys($allModel['fields']),$allModel['fields'],['Policy'=>'Internal','Revision'=>$allModel['revision']]);
    file_put_contents($root.'/verify.xlsx',$bytes);
    $zip=new ZipArchive(); $expect($zip->open($root.'/verify.xlsx')===true,'Workbook is not a ZIP package');
    for($index=0;$index<$zip->numFiles;$index++) {
        $name=$zip->getNameIndex($index); $contents=$zip->getFromIndex($index);
        $xml=new DOMDocument(); $expect($xml->loadXML($contents,LIBXML_NONET),'Invalid workbook XML: '.$name);
        $expect(!str_contains($contents,'TargetMode="External"'),'External workbook relationship exists');
    }
    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
    $expect(str_contains($zip->getFromName('xl/worksheets/sheet2.xml'),$allModel['revision']),'Workbook publication metadata missing');
    $expect(str_contains($sheet,'t="inlineStr"'),'Workbook cells are not explicit text');
    $expect(str_contains($sheet,'>00123<')&&str_contains($sheet,'>004726<'),'Leading-zero numbers changed');
    $expect(str_contains($sheet,'>=2+2<')&&!str_contains($sheet,'<f>'),'Formula-like contact became executable');
    $expect(str_contains($sheet,'&lt;script&gt;'),'Contact XML was not escaped');
    $expect(str_contains($sheet,'autoFilter')&&str_contains($sheet,'state="frozen"'),'Excel usability features missing');
    $zip->close();
    if (isset($argv[1])) { file_put_contents($argv[1],$bytes); }
    $expect(file_get_contents($root.'/directory/directory.json')===$cacheJson,'Read-only web access changed the phone cache');
    $rootPath=dirname(__DIR__);
    $source=file_get_contents($rootPath.'/assets/js/web-phonebook.js');
    foreach (['credentials:\'omit\'','state.selected','window.print()','response.blob()','beforeprint'] as $contract) {
        $expect(str_contains($source,$contract),'Missing browser contract: '.$contract);
    }
    $http=file_get_contents($rootPath.'/lib/WebPhonebookHttp.php');
    $expect(strpos($http,'peerAllowed')<strpos($http,"\$action ="),'Access check does not protect every action');
    $class=file_get_contents($rootPath.'/Ucmprovisioner.class.php');
    $expect(strpos($class,'assertPageMutationRequest')<strpos($class,"case 'savewebphonebook'"),'Policy save bypasses mutation authorization');
    $expect(str_contains(file_get_contents($rootPath.'/install.php'),'ucmprovisioner-phonebook.php'),'Public endpoint installation missing');
    $expect(str_contains(file_get_contents($rootPath.'/uninstall.php'),'ucmprovisioner-phonebook.php'),'Public endpoint cleanup missing');
    $settings=new DOMDocument();
    @$settings->loadHTML(file_get_contents($rootPath.'/views/web_phonebook_settings.php'));
    $xpath=new DOMXPath($settings);
    foreach(['name','extension'] as $field) {
        $expect($xpath->query('//input[@type="hidden" and @name="web_phonebook[fields][]" and @value="'.$field.'"]')->length===1,'Required fields must submit even when all optional checkboxes are unchecked');
    }
    $designer=file_get_contents($rootPath.'/views/directory.php');
    $expect(str_contains($designer,'id="ucm-book-review-approval" hidden'),'Exceptional review approval must initially be hidden');
    $expect(str_contains($designer,'I confirm this large audience change.'),'Exceptional review wording must be plain and conditional');
    $candidate=webPhonebookFixture()['phonebooks'][0];
    $candidate['contacts']=array_slice($candidate['contacts'],0,2,true);
    $candidate['entries']=$candidate['contacts'];
    $review=$service->reviewPublication($candidate,['publish_sites'=>'["site-a"]']);
    $expect($review['before_count']===36&&$review['after_count']===2&&count($review['removals'])===34&&$review['exceptional'],'Compiled audience diff incorrect');
    $reject(fn()=>WebPhonebook::validatePublicationReview($review,'old',true),'Stale audience review accepted');
    $reject(fn()=>WebPhonebook::validatePublicationReview($review,$review['revision'],false),'Exceptional audience change accepted without approval');
    WebPhonebook::validatePublicationReview($review,$review['revision'],true);
    $freshCache=webPhonebookFixture();$freshCache['source_freshness']=[['last_success'=>1234,'last_accepted_revision'=>'accepted']];
    file_put_contents($root.'/directory/directory.json',json_encode($freshCache,JSON_THROW_ON_ERROR));
    $expect($service->model($service->policy(),'company')['accepted_at']===1234,'Generated time substituted for accepted-source freshness');
    $pixels=imagecreatetruecolor(20,10); ob_start(); imagepng($pixels); $upload=ob_get_clean(); imagedestroy($pixels);
    $favicon=WebPhonebook::faviconFromImage($upload.'untrusted-trailing-text');
    $size=getimagesizefromstring(base64_decode($favicon));
    $expect($size[0]===64 && $size[1]===64 && !str_contains(base64_decode($favicon),'untrusted-trailing-text'),'Icon must contain re-encoded bounded pixels only');
    foreach (['not an image','<svg xmlns="http://www.w3.org/2000/svg"></svg>',str_repeat('x',1048577)] as $badIcon) {
        $reject(fn()=>WebPhonebook::faviconFromImage($badIcon),'Invalid upload accepted');
    }
    $reject(fn()=>WebPhonebook::normalizePolicy($base+['favicon'=>base64_encode('not PNG')]),'Invalid stored icon accepted');
    foreach (["Main office = One\nMain office = Two",'Missing delimiter',['Main office'=>[]]] as $badLabels) {
        $reject(fn()=>WebPhonebook::normalizePolicy($base+['site_labels'=>$badLabels]),'Malformed display aliases accepted');
    }
    $service->savePolicy($allPolicy+['favicon'=>$favicon,'site_labels'=>"Main office = Head office\nNorth branch = North"],$service->policyRevision());
    $appearance=$service->policy();$aliased=$service->model($appearance,'company');
    $expect($aliased['contacts'][0]['site']==='Head office' && $aliased['contacts'][0]['id']===$model['contacts'][0]['id'],'Display aliases must not change contact IDs');
    $selection=WebPhonebook::exportSelection($aliased,['book'=>'company','revision'=>$aliased['revision'],'scope'=>'all','columns'=>['name','site'],'ids'=>array_column($aliased['contacts'],'id')]);
    $expect($selection['rows'][0]['site']==='Head office','Export must use the displayed site label');
    $service->savePolicy($allPolicy,$service->policyRevision());
    $expect($service->policy()['favicon']===$favicon,'Unrelated form saves must retain the favicon');
    $service->savePolicy($allPolicy+['favicon'=>''],$service->policyRevision());
    $expect($service->policy()['favicon']==='','Explicit favicon removal failed');
    file_put_contents($root.'/directory/directory.json','invalid published JSON');
    $service->savePolicy(array_replace($allPolicy,['enabled'=>false]),$service->policyRevision());
    $expect(!$service->policy()['enabled'],'Broken publication prevented emergency access withdrawal');
    echo "Web phonebook network, field, membership, stale-request and real XLSX checks passed\n";
} finally {
    foreach (['verify.xlsx','web-phonebook.json','web-phonebook.lock','directory/directory.json'] as $file) { if(is_file($root.'/'.$file)){unlink($root.'/'.$file);} }
    rmdir($root.'/directory'); rmdir($root);
}
