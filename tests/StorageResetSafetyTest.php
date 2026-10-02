<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\TftpStore;

final class StorageResetFakeSftp
{
    public bool $failPrivateList = false;
    public function rawlist(string $path): array|false
    {
        if ($path === '/tftpboot') {
            return [
                '.'=>['type'=>2],'..'=>['type'=>2],'.ucmprovisioner'=>['type'=>2],
                'SEP001122AABBCC.cnf.xml'=>['type'=>1,'size'=>100],
                'SIP001122AABBDD.cnf'=>['type'=>1,'size'=>90],
                '001122AABBEE.cfg'=>['type'=>1,'size'=>80],
                'cfg001122AABBFF.xml'=>['type'=>1,'size'=>70],
                '0004F22CD184-phone.cfg'=>['type'=>1,'size'=>60],
                '0004F23AD82A-web.cfg'=>['type'=>1,'size'=>50],
                'Desktops'=>['type'=>2], 'sip88xx.loads'=>['type'=>1,'size'=>200],
            ];
        }
        if ($path === '/tftpboot/.ucmprovisioner') {
            if ($this->failPrivateList) { return false; }
            return ['.'=>['type'=>2],'..'=>['type'=>2],'quarantine'=>['type'=>2],'wallpaper-sets'=>['type'=>2]];
        }
        return [];
    }
    public function is_dir(string $path): bool { return in_array($path, ['/tftpboot/.ucmprovisioner'], true); }
}
final class StorageResetFakeConnection
{
    public function __construct(private object $sftp) {}
    public function dockerSftp(): object { return $this->sftp; }
    public function tftpRoot(): string { return '/tftpboot'; }
}
final class StorageResetFakeModule
{
    private object $connection;
    public function __construct(?object $sftp = null) { $this->connection = new StorageResetFakeConnection($sftp ?? new StorageResetFakeSftp()); }
    public function localSiteId(): int { return 1; }
    public function siteConnection(int $siteId): object { return $this->connection; }
    public function phoneConfigFiles(int $siteId): array
    {
        return array_fill_keys([
            'SEP001122AABBCC.cnf.xml', 'SIP001122AABBDD.cnf',
            '001122AABBEE.cfg', 'cfg001122AABBFF.xml',
        ], []);
    }
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$store = new TftpStore(new StorageResetFakeModule());
$plan = $store->handle(['op'=>'reset_except_phone_configs','site_id'=>1]);
$expect(!empty($plan['ok']) && ($plan['mode'] ?? '') === 'dry-run', 'Storage reset must default to a read-only plan');
$expect(count($plan['preserved_phone_configs'] ?? []) === 4, 'Only phone configs tracked by the module inventory must be preserved');
$moveNames = array_column($plan['tftp_entries_to_quarantine'] ?? [], 'name');
$expect(in_array('Desktops',$moveNames,true) && in_array('sip88xx.loads',$moveNames,true), 'Non-phone TFTP content must be selected for quarantine');
$expect(in_array('0004F22CD184-phone.cfg',$moveNames,true) && in_array('0004F23AD82A-web.cfg',$moveNames,true), 'Untracked vendor config files must be quarantined with the rest of the test-site content');
$expect(($plan['ledger_entries_to_quarantine'][0]['name'] ?? '') === 'wallpaper-sets', 'Managed private state must be reset while prior quarantine remains untouched');
$rejected = $store->handle(['op'=>'reset_except_phone_configs','site_id'=>1,'apply'=>1,'confirm'=>'wrong']);
$expect(empty($rejected['ok']) && str_contains((string)$rejected['message'],'confirmation'), 'Apply must require the exact destructive confirmation token');
$failedList = new StorageResetFakeSftp();$failedList->failPrivateList=true;
$failedPlan = (new TftpStore(new StorageResetFakeModule($failedList)))->handle(['op'=>'reset_except_phone_configs','site_id'=>1]);
$expect(empty($failedPlan['ok']) && str_contains($failedPlan['message'],'Cannot inspect private'), 'A failed private listing must not become an empty successful reset plan');
echo "Storage reset safety assertions passed\n";
