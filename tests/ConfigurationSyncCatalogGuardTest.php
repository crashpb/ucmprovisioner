<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
}

namespace FreePBX\modules {
    require_once dirname(__DIR__) . '/lib/RecurringSchedule.php';
    require_once dirname(__DIR__) . '/lib/ConfigurationSyncService.php';
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    final class ConfigurationSyncCatalogFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }

    final class ConfigurationSyncCatalogModule extends Ucmprovisioner
    {
        public array $wallpaperSets = [];
        public function localSiteId(): int { return 1; }
        public function tftpOp(array $request): array
        {
            if (($request['op'] ?? '') !== 'wallpaper_set_list') {
                throw new \RuntimeException('Unexpected TFTP operation');
            }
            return ['ok'=>true,'sets'=>$this->wallpaperSets,'issues'=>[]];
        }
        public function mediaAssetOpLocal(array $request): array
        {
            return ['ok'=>true,'catalogs'=>[],'issues'=>[]];
        }
    }

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($db);
    $module = new ConfigurationSyncCatalogModule(new ConfigurationSyncCatalogFreePBX($db));
    $context = [
        'job_key'=>'cfgc:fixture-2:7','job_id'=>7,'source_site_key'=>'site-a','source_site_name'=>'SITE-A',
        'soft_lock'=>true,'force'=>false,
    ];
    $catalog = [
        'id'=>'base','name'=>'Base','source'=>'source.jpg','source_sha256'=>str_repeat('a',64),
        'original_scope'=>['brand'=>'Cisco','series'=>'','model'=>'','label'=>'Cisco'],
        'variants'=>[[
            'profile'=>'cisco:800x480','enabled'=>true,'models'=>['8845'],'source'=>'source.jpg',
            'source_sha256'=>str_repeat('a',64),'full'=>'base.png','thumbnail'=>'base-tn.png',
            'full_sha256'=>str_repeat('b',64),'thumbnail_sha256'=>str_repeat('c',64),
            'full_transform'=>['zoom'=>100,'x'=>0,'y'=>0],
            'thumbnail_transform'=>['zoom'=>100,'x'=>0,'y'=>0],
            'delivery'=>'cisco_list','dir'=>'800x480x24',
        ]],
    ];
    $revisionMethod = new \ReflectionMethod(Ucmprovisioner::class, 'configurationSyncCatalogRevision');
    $revisionMethod->setAccessible(true);
    $revision = (string)$revisionMethod->invoke($module, 'wallpaper_catalog', $catalog);

    $prepared = $module->configurationSyncPrepareAssetLocal(
        $context,'wallpaper_catalog','wallpaper:base',$revision,['catalog_id'=>'base','name'=>'Base']
    );
    $expect(!empty($prepared['allow']) && empty($prepared['overwrite']), 'A missing destination catalog must be creatable without overwrite');

    $module->wallpaperSets = [$catalog];
    $committed = $module->configurationSyncCommitAssetLocal(
        $context,'wallpaper_catalog','wallpaper:base',$revision,['catalog_id'=>'base','name'=>'Base']
    );
    $expect(!empty($committed['ok']) && ($committed['item']['status'] ?? '') === 'active', 'A matching copied catalog must establish active ownership');

    $guard = $module->configurationSyncCatalogEditGuard('wallpaper_catalog','wallpaper:base',[]);
    $expect(empty($guard['ok']) && !empty($guard['sync_edit_required']), 'A soft-locked destination catalog must require explicit edit confirmation');
    $confirmed = $module->configurationSyncCatalogEditGuard('wallpaper_catalog','wallpaper:base',['sync_edit_confirmed'=>1]);
    $expect(!empty($confirmed['ok']) && count($confirmed['items'] ?? []) === 1, 'Confirmed catalog edit must return only its owned sync item');
    $module->configurationSyncCatalogEditPause((array)$confirmed['items'], ['sync_edit_reason'=>'local catalog edit']);
    $states = $module->configurationSyncStatesLocal('wallpaper_catalog');
    $expect(count($states) === 1 && ($states[0]['status'] ?? '') === 'paused', 'A confirmed destination edit must pause only that catalog');

    $paused = $module->configurationSyncPrepareAssetLocal(
        $context,'wallpaper_catalog','wallpaper:base',$revision,['catalog_id'=>'base','name'=>'Base']
    );
    $expect(empty($paused['allow']) && str_contains((string)$paused['message'],'paused'), 'Scheduled sync must preserve a destination-paused catalog');
    $context['force'] = true;
    $resumed = $module->configurationSyncPrepareAssetLocal(
        $context,'wallpaper_catalog','wallpaper:base',$revision,['catalog_id'=>'base','name'=>'Base']
    );
    $expect(!empty($resumed['allow']) && !empty($resumed['already_current']), 'An explicit source-side run must be able to resume an already matching paused catalog');

    echo "Configuration sync catalog guard assertions passed\n";
}
