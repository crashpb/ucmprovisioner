<?php
declare(strict_types=1);

namespace {
    interface BMO {}
    class FreePBX_Helpers {}
}

namespace FreePBX\modules {
    require_once dirname(__DIR__) . '/lib/ModelCatalog.php';
    require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

    $expect = static function (bool $condition, string $message): void {
        if (!$condition) { throw new \RuntimeException($message); }
    };

    $profile = null;
    foreach (\FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfiles() as $candidate) {
        if (($candidate['delivery'] ?? '') === 'hosted_http') { $profile = $candidate; break; }
    }
    $expect(is_array($profile), 'The model catalog must expose a hosted HTTP wallpaper profile');

    $models = array_values((array)($profile['models'] ?? []));
    $expect($models !== [], 'The hosted wallpaper profile must have at least one model');
    $model = strtolower((string)$models[0]);
    $family = (string)$profile['family'];
    $manifest = [[
        'id'=>'fleet',
        'name'=>'Fleet',
        'updated'=>123,
        'variants'=>[[
            'profile'=>(string)$profile['key'],
            'enabled'=>true,
            'current'=>true,
            'full_sha256'=>str_repeat('1',64),
            'models'=>$models,
            'full'=>'fleet-current.png',
        ]],
        'aliases'=>[[
            'set_id'=>'fleet',
            'profile'=>(string)$profile['key'],
            'models'=>$models,
            'dir'=>(string)$profile['dir'],
            'full'=>'fleet-before-rename.png',
            'thumbnail'=>'',
        ]],
    ]];

    $method = new \ReflectionMethod(Ucmprovisioner::class, 'hostedMediaOwners');
    $method->setAccessible(true);
    $owners = (array)$method->invoke(null, [], $manifest);

    $expect(isset($owners[$family . '/' . $model . '/fleet-current.png']), 'The current hosted wallpaper output must be catalog-owned');
    $expect(isset($owners[$family . '/' . $model . '/fleet-before-rename.png']), 'A retained hosted wallpaper alias must remain catalog-owned instead of appearing Observed only');
    $expect($owners[$family.'/'.$model.'/fleet-current.png']['state']==='current' && $owners[$family.'/'.$model.'/fleet-current.png']['sha256']===str_repeat('1',64), 'Current ownership must carry the verified output and full hash');
    $expect($owners[$family.'/'.$model.'/fleet-before-rename.png']['state']==='compatibility', 'Retained aliases must not be current outputs');
    $manifest[0]['aliases'][]=$manifest[0]['variants'][0];
    $repeated=$method->invoke(null,[],$manifest);
    $expect($repeated[$family.'/'.$model.'/fleet-current.png']['state']==='current', 'An alias with the same name must not hide the current output');
    $manifest[0]['variants'][0]['enabled']=false;
    $disabled=$method->invoke(null,[],$manifest);
    $expect($disabled[$family.'/'.$model.'/fleet-current.png']['state']==='disabled', 'Disabled profiles retain ownership without authorizing new choices');

    $production = (string)file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
    $expect(str_contains($production, 'foreach ($wallpaperSets as &$wallpaperSet)'), 'Inventory must enrich the persistent wallpaper-set collection, not a temporary array cast');
    $expect(str_contains($production, "self::hostedMediaOwners((array)(\$catalogResult['catalogs'] ?? []), \$wallpaperSets)"), 'Inventory must pass the alias-enriched wallpaper sets to hosted ownership reconciliation');

    echo "Hosted media ownership assertions passed\n";
}
