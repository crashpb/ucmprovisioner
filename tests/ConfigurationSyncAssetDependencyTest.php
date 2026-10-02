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

    final class ConfigurationSyncDependencyFreePBX
    {
        public function __construct(private \PDO $db) {}
        public function Database(): \PDO { return $this->db; }
    }

    final class ConfigurationSyncDependencyModule extends Ucmprovisioner
    {
        public array $calls = [];
        public array $settings = [];
        public bool $commitFails = false;
        public string $failedInventory = '';
        public string $timezoneKey = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        public string $timezoneAssetKey = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
        public function localSiteId(): int { return 1; }
        public function getSiteSettings(?int $siteId): array { return $this->settings; }
        public function tftpOp(array $request): array
        {
            $this->calls[] = $request;
            if (($request['op'] ?? '') === $this->failedInventory) { return ['ok'=>false,'message'=>'fixture inventory unavailable']; }
            return match ($request['op'] ?? '') {
                'wallpaper_set_list' => ['ok'=>true,'sets'=>[[
                    'id'=>'Fleet','name'=>'Fleet','complete'=>true,'source_sha256'=>str_repeat('a',64),
                    'variants'=>[['enabled'=>true,'full'=>'Fleet-800x480-deadbeef.png','thumbnail'=>'Fleet-tn.png']],
                ]]],
                'sync_wallpaper_media' => ['ok'=>true,'message'=>'copied'],
                'firmware_inventory' => ['ok'=>true,'bundles'=>[[
                    'profile'=>'cisco:sip88xx','load_name'=>'sip88xx.14-4-1','status'=>'complete',
                ]]],
                'sync_firmware_bundle' => ['ok'=>true,'message'=>'copied'],
                'timezone_versions' => ['ok'=>true,
                    'assets'=>[[
                        'asset_key'=>$this->timezoneAssetKey,'profile'=>'cisco-78xx-88xx',
                        'source_bundle_id'=>9,'selectable'=>true,
                    ]],
                    'bundles'=>[[
                        'id'=>9,'bundle_key'=>$this->timezoneKey,'status'=>'complete',
                    ]],
                ],
                'sync_timezone_version' => ['ok'=>true,'message'=>'timezone copied'],
                default => throw new \RuntimeException('Unexpected TFTP operation ' . (string)($request['op'] ?? '')),
            };
        }
        public function mediaAssetOp(array $request): array
        {
            $this->calls[] = $request;
            return ['ok'=>true,'catalogs'=>[]];
        }
        public function configurationSyncPrepareAsset(int $targetSiteId, array $context, string $objectType, string $objectKey, string $sourceRevision, array $intended): array
        {
            return ['ok'=>true,'allow'=>true,'already_current'=>false,'overwrite'=>false];
        }
        public function configurationSyncCommitAsset(int $targetSiteId, array $context, string $objectType, string $objectKey, string $sourceRevision, array $intended): array
        {
            return ['ok'=>!$this->commitFails,'message'=>'fixture commit verification'];
        }
    }

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };
    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $module = new ConfigurationSyncDependencyModule(new ConfigurationSyncDependencyFreePBX($db));
    $module->settings = [
        'firmware_defaults'=>json_encode(['cisco:sip88xx'=>'sip88xx.14-4-1']),
        'cisco_timezone_defaults'=>json_encode(['cisco-78xx-88xx'=>$module->timezoneAssetKey]),
        'cisco_wallpaper_defaults'=>json_encode(['cisco:800x480'=>'Fleet-800x480-deadbeef.png']),
        'hosted_image_defaults'=>'{}',
    ];
    $result = $module->configurationSyncAssets(1,[2],['site_defaults'],[
        'job_key'=>'cfgc:fixture-1:1','job_id'=>1,'source_site_key'=>'site-a','source_site_name'=>'SITE-A','force'=>false,
    ]);
    $expect(!empty($result['ok']), 'Legacy and main-site default sync must resolve its managed dependencies');
    $expect(count(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_wallpaper_media')) === 1, 'The exact referenced wallpaper catalog must be copied');
    $firmware = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_firmware_bundle'));
    $expect(count($firmware) === 1 && ($firmware[0]['load_name'] ?? '') === 'sip88xx.14-4-1', 'The exact referenced firmware package must be copied');
    $timezoneDefaults = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version'));
    $expect(count($timezoneDefaults) === 1 && (int)($timezoneDefaults[0]['bundle_id'] ?? 0) === 9, 'The exact retained runtime selected by site defaults must be copied first');

    $module->calls = [];
    $module->settings['cisco_wallpaper_defaults'] = json_encode(['cisco:800x480'=>'missing.png']);
    $missing = $module->configurationSyncAssets(1,[2],['settings_assets'],[
        'job_key'=>'cfgc:fixture-1:2','job_id'=>2,'source_site_key'=>'site-a','source_site_name'=>'SITE-A','force'=>false,
    ]);
    $expect(empty($missing['ok']) && str_contains((string)$missing['message'], 'no complete managed source catalog owns it'), 'An unresolved default must fail before destination policy can be applied');

    $module->calls = [];
    $templateDependencies = $module->configurationSyncAssets(1,[2],['templates'],[
        'job_key'=>'cfgc:fixture-1:3','job_id'=>3,'source_site_key'=>'site-a','source_site_name'=>'SITE-A','force'=>false,
        'snapshot'=>['templates'=>[ ['setting_overrides'=>['cisco_timezone_bundle_key'=>$module->timezoneKey]] ]],
    ]);
    $expect(!empty($templateDependencies['ok']), 'A template-only push must transfer its exact retained timezone dependency before policy apply');
    $timezoneCopies = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version'));
    $expect(count($timezoneCopies) === 1 && (int)($timezoneCopies[0]['bundle_id'] ?? 0) === 9, 'Template timezone dependency transfer must use the exact retained source bundle');

    $module->calls = [];
    $runtimeTemplate = $module->configurationSyncAssets(1,[2],['templates'],[
        'job_key'=>'cfgc:fixture-1:4','job_id'=>4,'source_site_key'=>'site-a','source_site_name'=>'SITE-A','force'=>false,
        'snapshot'=>['templates'=>[ ['setting_overrides'=>['cisco_timezone_by_profile'=>['cisco-78xx-88xx'=>$module->timezoneAssetKey]]] ]],
    ]);
    $runtimeCopies = array_values(array_filter($module->calls, static fn(array $call): bool => ($call['op'] ?? '') === 'sync_timezone_version'));
    $expect(!empty($runtimeTemplate['ok']) && count($runtimeCopies) === 1 && (int)($runtimeCopies[0]['bundle_id'] ?? 0) === 9, 'A template runtime override must replicate the archive owning that exact hash asset');
    $baseOptions = ['job_key'=>'cfgc:fixture-1:5','source_site_key'=>'site-a','source_site_name'=>'SITE-A'];
    foreach ([['cisco_default_wallpaper'=>'absent.png'],['firmware_by_profile'=>['cisco:sip88xx'=>'absent-load']]] as $overrides) {
        $missing = $module->configurationSyncAssets(1,[2],['templates'],$baseOptions + ['snapshot'=>['templates'=>[['setting_overrides'=>$overrides]]]]);
        $expect(empty($missing['ok']) && str_contains($missing['message'],'Selected policy references'), 'Template-only sync must reject missing managed wallpaper and firmware dependencies');
    }
    $module->commitFails = true;
    $unverified = $module->configurationSyncAssets(1,[2],['templates'],$baseOptions + ['snapshot'=>['templates'=>[['setting_overrides'=>['cisco_default_wallpaper'=>'Fleet-800x480-deadbeef.png']]]]]);
    $expect(empty($unverified['ok']) && str_contains($unverified['message'],'commit verification'), 'A failed post-copy catalog verification must block policy apply');
    $module->commitFails = false; $module->failedInventory = 'timezone_versions';
    $inventoryFailure = $module->configurationSyncAssets(1,[2],['templates'],$baseOptions + ['snapshot'=>['templates'=>[['setting_overrides'=>['cisco_timezone_bundle_key'=>$module->timezoneKey]]]]]);
    $expect(empty($inventoryFailure['ok']) && str_contains($inventoryFailure['message'],'inventory unavailable'), 'A failed timezone inventory must not be an empty successful selection');
    echo "Configuration sync asset dependency assertions passed\n";
}
