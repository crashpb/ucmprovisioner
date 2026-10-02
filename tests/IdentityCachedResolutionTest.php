<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/vendor/autoload.php';
require_once __DIR__.'/IdentityBindingModesTest.php';
use FreePBX\modules\Ucmprovisioner\IdentityDeliveryService;
use FreePBX\modules\Ucmprovisioner\DirectoryService;
use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;
$packet=IdentityDeliveryService::normalize(['identity_site_key'=>'receiver','origin_site_key'=>'controller',
    'extension'=>'900','binding_mode'=>'ad_primary','adoption'=>'managed','extension_revision'=>3,
    'source_id'=>4,'object_key'=>'remote-person','canonical'=>['display_name'=>'Remote Example','label'=>'r.example'],
    'sync_name'=>true,'sync_label'=>true]);
$receiver=new class($db,$packet) {
    public function __construct(private PDO $db,public array $packet) {}
    public function getDb():PDO { return $this->db; }
    public function localSiteId():int { return 1; }
    public function getSite(int $site):array { return ['id'=>$site,'site_key'=>'receiver']; }
    public function getSetting(string $key,?int $site=null,$default=null) { return $key===IdentityDeliveryService::CACHE_KEY?json_encode(['schema'=>1,'entries'=>['receiver:900'=>$this->packet]]):$default; }
};
$directory=new DirectoryService($receiver);
$expect($directory->resolveExtensionIdentity(1,'900')['full_name']==='Remote Example','Normal resolver must retain received projection without an explicit preview');
$row=$logical->ensurePbxExtension(1,'900');
$expect($directory->resolveExtensionIdentity(1,'900')['short_name']==='r.example','Unbound receiver inventory must not suppress received identity');
$logical->setIdentityBinding((int)$row['id'],'manual_shared',null,null,['display_name'=>'Local Shared Desk','label'=>'Shared']);
$expect($directory->resolveExtensionIdentity(1,'900')['full_name']==='Local Shared Desk','Receiver-owned manual identity must override foreign cache');
$db->exec("UPDATE ucmp_extensions SET binding_mode='ad_primary',ad_source_id=1,ad_object_key='unresolved-local' WHERE extension='900'");
$expect(empty($directory->resolveExtensionIdentity(1,'900')['resolved']),'Unresolved local authority must never fall back to a foreign cached person');
$db->exec("DELETE FROM ucmp_extensions WHERE extension='900'");
$receiver->packet=IdentityDeliveryService::normalize(array_replace($receiver->packet,['released'=>true,'extension_revision'=>4]));
$expect($directory->resolveExtensionIdentity(1,'900')===null,'Released projection must not repopulate a departing name');
echo "Normal receiver resolver cached identity and local authority guards passed\n";
