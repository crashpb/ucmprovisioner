<?php
declare(strict_types=1);
require_once __DIR__ . '/TftpGenerationTransactionTest.php';

$lockSftp = new GenerationSftpStub();
$lockStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($lockSftp));
$expect($lockStore->siteLockStatus(1)['state'] === 'unlocked' && count($lockSftp->directories) === 2, 'Lock inspection must not create state');
$lockStore->withSiteLock(1, function () use ($lockStore,$lockSftp,$expect): void {
    $status = $lockStore->siteLockStatus(1);
    $expect($status['state'] === 'locked' && strlen($status['owner']['nonce']) === 32, 'Lock ownership is visible');
    $expect($lockSftp->modes['/tftpboot/.ucmprovisioner/write-lock/owner.json'] === 0600, 'Owner receipt is private');
    $expect(!empty($lockStore->putConfig(1,'nested.cfg','fixture')['ok']), 'Nested writers reuse the owned lock');
});
$expect($lockStore->siteLockStatus(1)['state'] === 'unlocked', 'Owned lock releases after success');

foreach ([['status'=>'recovery_required'], ['recovery_required'=>true]] as $recovery) {
    $recoverySftp = new GenerationSftpStub();
    $recoveryStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($recoverySftp));
    $result = $recoveryStore->withSiteLock(1, static fn(): array => ['ok'=>false,'recovery_id'=>'fixture'] + $recovery);
    $expect($recoveryStore->siteLockStatus(1)['state'] === 'locked', 'Archive and transfer recovery outcomes must retain exclusion');
    $expect(empty($recoveryStore->putConfig(1,'blocked.cfg','blocked')['ok']), 'An unresolved import must block the next writer');
}

$lockSftp->directories['/tftpboot/.ucmprovisioner/write-lock'] = true;
$exceptionSftp = new GenerationSftpStub();
$exceptionStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($exceptionSftp));
try {
    $exceptionStore->withSiteLock(1,static function():never {
        throw new FreePBX\modules\Ucmprovisioner\RpcOperationException('Move acknowledgement lost', ['ok'=>false,'status'=>'unknown','recovery_required'=>true]);
    });
    throw new LogicException('Unknown operation was reported as complete');
} catch (FreePBX\modules\Ucmprovisioner\RpcOperationException $expected) {}
$expect($exceptionStore->siteLockStatus(1)['state']==='locked','Thrown recovery receipts must preserve writer exclusion');
$lockSftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'] = '{"nonce":"foreign","started":1}';
$before = $lockSftp->files;
$expect(empty($lockStore->putConfig(1,'blocked.cfg','blocked')['ok']), 'Direct config write cannot bypass another writer');
$expect(empty($lockStore->deletePhoneConfig(1,'SIP001122334455.cnf')['ok']), 'Direct config deletion cannot bypass another writer');
$expect(empty($lockStore->handle(['op'=>'mkdir','site_id'=>1,'path'=>'blocked'])['ok']), 'Browser writes cannot bypass another writer');
$expect($before === $lockSftp->files, 'Blocked operations preserve all content and foreign ownership');
unset($lockSftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'], $lockSftp->directories['/tftpboot/.ucmprovisioner/write-lock']);
try { $lockStore->withSiteLock(1, static function (): void { throw new RuntimeException('isolated failure'); }); }
catch (RuntimeException $e) { $expect($e->getMessage() === 'isolated failure', 'Original operation failure survives verified release'); }
$expect($lockStore->siteLockStatus(1)['state'] === 'unlocked', 'Owned lock releases after handled exception');
try {
    $lockStore->withSiteLock(1, function () use ($lockSftp): void { $lockSftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'] = 'foreign replacement'; });
    throw new LogicException('Unowned lock release was accepted');
} catch (RuntimeException $e) { $expect(str_contains($e->getMessage(),'release is unverified'), 'Uncertain ownership remains recovery-required'); }
$expect($lockSftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'] === 'foreign replacement', 'Never remove another owner receipt');

$receiptSftp = new GenerationSftpStub();
$receiptStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($receiptSftp));
$completed = $receiptStore->withSiteLock(1, function () use ($receiptSftp): array {
    $receiptSftp->files['/tftpboot/.ucmprovisioner/write-lock/owner.json'] = 'foreign replacement';
    return ['ok'=>true,'state'=>'committed','copied'=>5,'transaction_id'=>'fixture-completed'];
});
$expect(!$completed['ok']&&$completed['operation_ok']&&$completed['recovery_required']&&$completed['copied']===5
    &&$completed['state']==='committed'&&$completed['transaction_id']==='fixture-completed',
    'An unverified lock release must retain the known completed result and require reconciliation without replay');

$order = [];
$multiUcm = new class($order) {
    public array $order = [];
    private array $stores;
    public function __construct(array $ignored) { $this->stores = [1=>new GenerationSftpStub(),2=>new GenerationSftpStub()]; }
    public function siteConnection(int $id): GenerationConnectionStub { $this->order[]=$id; return new GenerationConnectionStub($this->stores[$id]); }
};
(new FreePBX\modules\Ucmprovisioner\TftpStore($multiUcm))->withSiteLocks([2,1,2], static fn(): bool => true);
$expect($multiUcm->order === [1,2], 'Cross-site source and destinations acquire in one deterministic order');
echo "TFTP writer serialization assertions passed\n";

$verifiedSftp = new GenerationSftpStub();
$verifiedStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($verifiedSftp));
foreach (['corruptReadTarget','failChmodTarget'] as $fault) {
    $faultSftp = new GenerationSftpStub();
    $faultStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($faultSftp));
    $faultSftp->$fault = '/tftpboot/syncinfo.xml';
    $expect(empty($faultStore->putConfig(1,'syncinfo.xml','expected')['ok']), 'Generated publication must not acknowledge an unverified write');
    try { $faultStore->bumpLegacySync(1); throw new LogicException('Failed sync returned a token'); }
    catch (RuntimeException $expected) {}
}
$expect($verifiedStore->bumpLegacySync(1) !== '', 'Verified publication returns a sync token');

$replacement = new ReflectionMethod(FreePBX\modules\Ucmprovisioner\TftpStore::class, 'replaceRemoteFiles');
$payloadVerifier = new ReflectionMethod(FreePBX\modules\Ucmprovisioner\TftpStore::class, 'verifyRemotePayload');
$boundedRecord = new ReflectionMethod(FreePBX\modules\Ucmprovisioner\TftpStore::class, 'readRemoteJson');
$replacementSftp = new class {
    public GenerationSftpStub $inner;
    public string $fault = '';
    public array $reads = [];
    public function __construct() { $this->inner = new GenerationSftpStub(); }
    public function __call(string $name,array $arguments) { return $this->inner->$name(...$arguments); }
    public function stat(string $path) { return $this->inner->stat($path); }
    public function get(string $path,$local=false,int $offset=0,int $length=-1) {
        $this->reads[] = [$offset,$length];
        return $this->inner->get($path,$local,$offset,$length);
    }
    public function put(string $path,string $content): bool {
        return $this->inner->put($path,$this->fault==='bytes'&&str_contains($path,'.tmp-')?str_repeat('x',strlen($content)):$content);
    }
    public function chmod(int $mode,string $path): bool {
        if (str_contains($path,'.tmp-') && in_array($this->fault,['chmod','mode'],true)) { return $this->fault==='mode'; }
        return $this->inner->chmod($mode,$path);
    }
};
$replacementSftp->inner->files['/tftpboot/catalog.json'] = 'original';
foreach (['bytes','chmod','mode'] as $fault) {
    $replacementSftp->fault = $fault;
    try {
        $replacement->invoke($verifiedStore,$replacementSftp,['/tftpboot/catalog.json'=>['content'=>'replacement','mode'=>0660]]);
        throw new LogicException('Unverified staged replacement was activated');
    } catch (RuntimeException $expected) {}
    $expect($replacementSftp->inner->files === ['/tftpboot/catalog.json'=>'original'], 'Staging faults must preserve every live byte and clean partial staging files');
}
$replacementSftp->fault = '';
$largePayload = str_repeat('bounded-data',14000);
$replacementSftp->inner->files['/tftpboot/large.bin'] = $largePayload;
$replacementSftp->reads = [];
$payloadVerifier->invoke($verifiedStore,$replacementSftp,'/tftpboot/large.bin',['bytes'=>strlen($largePayload),'sha256'=>hash('sha256',$largePayload)]);
$expect(count($replacementSftp->reads)>2 && max(array_column($replacementSftp->reads,1))<=65536, 'Payload verification must never read an entire large file into PHP memory');
$replacementSftp->inner->files['/tftpboot/record.json'] = str_repeat('x',2*1024*1024+1);
$replacementSftp->reads = [];
try { $boundedRecord->invoke($verifiedStore,$replacementSftp,'/tftpboot/record.json'); throw new LogicException('Oversized transaction record was accepted'); }
catch (RuntimeException $expected) {}
$expect($replacementSftp->reads === [], 'An oversized transaction record must be rejected before transfer');

$pauseFailureUcm = new class {
    public function localSiteId(): int { return 1; }
    public function configurationSyncCatalogEditGuard(...$arguments): array { return ['ok'=>true,'items'=>[['id'=>1]]]; }
    public function configurationSyncCatalogEditPause(...$arguments): void { throw new RuntimeException('Injected pause persistence failure'); }
};
$pauseStore = new FreePBX\modules\Ucmprovisioner\TftpStore($pauseFailureUcm);
$catalogMutation = new ReflectionMethod(FreePBX\modules\Ucmprovisioner\TftpStore::class,'synchronizedCatalogMutation');
$retainedEdit = $catalogMutation->invoke($pauseStore,1,'wallpaper','fixture',[],static fn(): array=>['ok'=>true,'written'=>['one.png'],'transaction_id'=>'known-edit','message'=>'Published']);
$expect(!$retainedEdit['ok'] && $retainedEdit['operation_ok'] && $retainedEdit['recovery_required'] && !$retainedEdit['sync_paused']
    && $retainedEdit['written']===['one.png'] && $retainedEdit['transaction_id']==='known-edit', 'A late pause failure must retain the completed catalog result and block another writer');
echo "Staged payload, bounded verification and late catalog-pause fixtures passed\n";

$linkSftp = new GenerationSftpStub();
$linkSftp->links['/tftpboot/alias'] = true;
$linkSftp->files['/tftpboot/alias/private.txt'] = 'must not be read';
$linkStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($linkSftp));
$blockedRead = $linkStore->handle(['op'=>'get','site_id'=>1,'path'=>'alias/private.txt']);
$expect(empty($blockedRead['ok']) && str_contains($blockedRead['message'],'link'), 'Browser reads must reject a linked ancestor even when the leaf looks regular');
try { $linkStore->prepareDownload(1,['alias/private.txt']); throw new LogicException('Download followed a linked ancestor'); }
catch (RuntimeException $expected) {}
$expect(!in_array('/tftpboot/alias/private.txt',array_column($linkSftp->readRequests,'path'),true), 'Link rejection must occur before reading payload bytes');
$directoryTarget = $linkStore->handle(['op'=>'put','site_id'=>1,'path'=>'','content'=>'wrong target']);
$expect(empty($directoryTarget['ok']) && isset($linkSftp->directories['/tftpboot']), 'A text write must never replace a directory with a file');
$growSftp = new GenerationSftpStub();
$growSftp->files['/tftpboot/growing.bin'] = 'four';
$growSftp->growOnDownload = '/tftpboot/growing.bin';
$growStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($growSftp));
try { $growStore->prepareDownload(1,['growing.bin']); throw new LogicException('A growing download exceeded its declared bound'); }
catch (RuntimeException $expected) { $expect(str_contains($expected->getMessage(),'grew'), 'Growing download failed for an unrelated reason'); }
$downloadRead = array_values(array_filter($growSftp->readRequests,static fn(array $read):bool=>$read['path']==='/tftpboot/growing.bin'));
$expect(count($downloadRead)===1 && $downloadRead[0]['length']===5, 'Download must cap the actual SFTP read at declared bytes plus one');
echo "TFTP linked-ancestor and bounded-download fixtures passed\n";

foreach (['preserve','activate','cleanup'] as $phase) {
    $uncertainSftp = new GenerationSftpStub();
    $uncertainSftp->files['/tftpboot/fixture.txt'] = 'original';
    $uncertainSftp->loseReplacementReply = $phase;
    $uncertainSftp->rejectReplacementCleanup = $phase === 'cleanup';
    $uncertainStore = new FreePBX\modules\Ucmprovisioner\TftpStore(new GenerationUcmStub($uncertainSftp));
    $result = $uncertainStore->handle(['op'=>'put','site_id'=>1,'path'=>'fixture.txt','content'=>'new bytes']);
    $receipt = $result['file_replacements'][0] ?? [];
    $expect(empty($result['ok']) && !empty($result['recovery_required']) && ($receipt['stage'] ?? '')===$phase,
        'Lost ' . $phase . ' must survive the browser catch as a retained recovery receipt');
    $expect($uncertainStore->siteLockStatus(1)['state']==='locked', 'Uncertain replacement must retain exclusion');
    $file = $receipt['files']['/tftpboot/fixture.txt'] ?? [];
    $expect(($uncertainSftp->files[$file['backup']] ?? '')==='original', 'Original bytes must remain available for reconciliation');
    $expect(isset($uncertainSftp->files[$receipt['intent_path']]), 'Immutable intent must survive a lost rename reply');
    $intent = json_decode($uncertainSftp->files[$receipt['intent_path']],true,512,JSON_THROW_ON_ERROR);
    $expect($intent['files']['/tftpboot/fixture.txt']['expected']['sha256']===hash('sha256','new bytes'), 'Intent must pin exact candidate bytes before a live rename');
    if ($phase==='preserve') {
        $expect(($uncertainSftp->files[$file['staged']] ?? '')==='new bytes', 'Unknown preservation must retain staged bytes');
    } else {
        $expect(($uncertainSftp->files['/tftpboot/fixture.txt'] ?? '')==='new bytes', 'An activated file must never be removed after a lost reply');
    }
    $expect(($receipt['publication_complete'] ?? false)===($phase==='cleanup'), 'Only readback-confirmed publication can be reported complete');
    $beforeRetry = $uncertainSftp->files;
    $retry = $uncertainStore->handle(['op'=>'put','site_id'=>1,'path'=>'fixture.txt','content'=>'must not replay']);
    $expect(empty($retry['ok']) && $uncertainSftp->files===$beforeRetry, 'Recovery lock must prevent automatic or manual replay');
}
echo "Lost rename replies, retained intent and blocked replay fixtures passed\n";

$hashMethod = new ReflectionMethod(FreePBX\modules\Ucmprovisioner\TftpStore::class, 'remotePayloadHash');
$hashSftp = new GenerationSftpStub();
$hashSftp->files['/tftpboot/growing.bin'] = 'four';
$hashSftp->growOnDownload = '/tftpboot/growing.bin';
try { $hashMethod->invoke($verifiedStore,$hashSftp,'/tftpboot/growing.bin'); throw new LogicException('Growing hash transfer was accepted'); }
catch (RuntimeException $expected) { $expect(str_contains($expected->getMessage(),'changed size'), 'Growing hash failed for an unrelated reason'); }
$expect($hashSftp->readRequests[0]['length']===5, 'Hash verification must bound the transfer itself');
$hashSftp->links['/tftpboot/linked.bin'] = true;
$beforeReads = count($hashSftp->readRequests);
try { $hashMethod->invoke($verifiedStore,$hashSftp,'/tftpboot/linked.bin'); throw new LogicException('Linked hash payload accepted'); }
catch (RuntimeException $expected) {}
$expect(count($hashSftp->readRequests)===$beforeReads, 'Linked payload must be rejected before hashing');
