<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$media = (string)file_get_contents($root . '/views/media.php');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$page = (string)file_get_contents($root . '/page.ucmprovisioner.php');
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/** Parse the v3 server-rendered skeleton and reject browser-repaired nesting. */
$parseStaticHtml = static function (string $source): array {
    $html = preg_replace('/<\?(?:php|=)?[\s\S]*?\?>/i', '', $source);
    if ($html === null) {
        return [[], ['Could not strip PHP blocks']];
    }
    $html = preg_replace('/<(script|style)\b[^>]*>[\s\S]*?<\/\1\s*>/i', '<$1></$1>', $html);
    if ($html === null) {
        return [[], ['Could not isolate script/style contents']];
    }

    preg_match_all('/<!--[\s\S]*?-->|<![^>]*>|<\/?[A-Za-z][^>]*>/', $html, $matches);
    $void = array_fill_keys([
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link',
        'meta', 'param', 'source', 'track', 'wbr',
    ], true);
    $nodes = [];
    $stack = [];
    $errors = [];

    foreach ($matches[0] as $tagSource) {
        if (str_starts_with($tagSource, '<!')) {
            continue;
        }
        if (preg_match('/^<\s*\/\s*([A-Za-z][A-Za-z0-9:-]*)\s*>$/', $tagSource, $close)) {
            $name = strtolower($close[1]);
            if (!$stack) {
                $errors[] = "Unexpected </$name>";
                break;
            }
            $index = array_pop($stack);
            if ($nodes[$index]['tag'] !== $name) {
                $errors[] = "Expected </{$nodes[$index]['tag']}>, found </$name>";
                break;
            }
            $nodes[$index]['closed'] = true;
            continue;
        }
        if (!preg_match('/^<\s*([A-Za-z][A-Za-z0-9:-]*)([\s\S]*?)\/?\s*>$/', $tagSource, $open)) {
            $errors[] = 'Could not parse tag ' . $tagSource;
            break;
        }

        $name = strtolower($open[1]);
        $attrs = [];
        preg_match_all(
            '/([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/',
            $open[2],
            $attributeMatches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );
        foreach ($attributeMatches as $attribute) {
            $attrs[strtolower($attribute[1])] = (string)($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
        }
        $selfClosing = isset($void[$name]) || preg_match('/\/\s*>$/', $tagSource) === 1;
        $index = count($nodes);
        $nodes[] = [
            'tag' => $name,
            'attrs' => $attrs,
            'parent' => $stack ? end($stack) : null,
            'closed' => $selfClosing,
        ];
        if (!$selfClosing) {
            $stack[] = $index;
        }
    }
    if (!$errors && $stack) {
        $open = array_map(static fn(int $index): string => '<' . $nodes[$index]['tag'] . '>', $stack);
        $errors[] = 'Unclosed tags: ' . implode(' > ', $open);
    }
    return [$nodes, $errors];
};

$find = static function (array $nodes, callable $predicate): array {
    $found = [];
    foreach ($nodes as $index => $node) {
        if ($predicate($node)) {
            $found[] = $index;
        }
    }
    return $found;
};
$hasClass = static function (array $node, string $class): bool {
    $classes = preg_split('/\s+/', trim((string)($node['attrs']['class'] ?? ''))) ?: [];
    return in_array($class, $classes, true);
};
$isInside = static function (array $nodes, int $node, int $ancestor): bool {
    for ($parent = $nodes[$node]['parent']; $parent !== null; $parent = $nodes[$parent]['parent']) {
        if ($parent === $ancestor) {
            return true;
        }
    }
    return false;
};

// Prove this gate rejects an orphaned workbench that a browser would otherwise repair silently.
[, $goodFixtureErrors] = $parseStaticHtml('<section><div></div></section>');
[, $badFixtureErrors] = $parseStaticHtml('<section><div></div></div><div class="workbench"></div></section>');
$expect($goodFixtureErrors === [], 'Static HTML parser rejected a balanced fixture');
$expect($badFixtureErrors !== [], 'Static HTML parser failed to catch an orphan-workbench fixture');

[$nodes, $structureErrors] = $parseStaticHtml($media);
foreach ($structureErrors as $error) {
    $failures[] = 'media.php static HTML: ' . $error;
}
$byId = static function (string $id) use (&$nodes, $find): array {
    return $find($nodes, static fn(array $node): bool => ($node['attrs']['id'] ?? null) === $id);
};
$byClass = static function (string $class) use (&$nodes, $find, $hasClass): array {
    return $find($nodes, static fn(array $node): bool => $hasClass($node, $class));
};
$byClassInside = static function (string $class, ?int $ancestor) use (&$nodes, $hasClass, $isInside): array {
    if ($ancestor === null) {
        return [];
    }
    $matches = [];
    foreach ($nodes as $index => $node) {
        if ($hasClass($node, $class) && $isInside($nodes, $index, $ancestor)) {
            $matches[] = $index;
        }
    }
    return $matches;
};
$one = static function (array $matches, string $description) use ($expect): ?int {
    $expect(count($matches) === 1, "$description must exist exactly once");
    return count($matches) === 1 ? $matches[0] : null;
};
$inside = static function (?int $node, ?int $ancestor, string $description) use (&$nodes, $isInside, $expect): void {
    $expect($node !== null && $ancestor !== null && $isInside($nodes, $node, $ancestor), $description);
};

$app = $one($byId('ucmp-media-preview-v2'), 'Media v3 root #ucmp-media-preview-v2');
$expect($app !== null && $hasClass($nodes[$app], 'mp2-app'), 'Media v3 root must itself carry the mp2-app class');
$ids = [];
foreach ($nodes as $node) {
    if (isset($node['attrs']['id'])) {
        $ids[(string)$node['attrs']['id']] = ($ids[(string)$node['attrs']['id']] ?? 0) + 1;
    }
}
$duplicateIds = array_keys(array_filter($ids, static fn(int $count): bool => $count > 1));
$expect($duplicateIds === [], 'Static Media markup must not contain duplicate IDs: ' . implode(', ', $duplicateIds));

$requiredPanels = ['wallpapers', 'legacy', 'audio', 'replication', 'diagnostics'];
$panels = [];
foreach ($requiredPanels as $name) {
    $matches = $find($nodes, static fn(array $node): bool =>
        $node['tag'] === 'section' && ($node['attrs']['data-media-panel'] ?? null) === $name
    );
    $panels[$name] = $one($matches, "Media panel $name");
    if ($panels[$name] !== null) {
        $inside($panels[$name], $app, "Media panel $name must be contained by the v3 root");
        $expect(
            array_key_exists('data-media-owned-panel', $nodes[$panels[$name]]['attrs']),
            "Media panel $name must carry the runtime ownership marker"
        );
        $hasHeading = false;
        foreach ($nodes as $nodeIndex => $node) {
            if (in_array($node['tag'], ['h2', 'h3'], true)
                && $isInside($nodes, $nodeIndex, $panels[$name])) {
                $hasHeading = true;
                break;
            }
        }
        $expect($hasHeading, "Media panel $name must contain a heading");
    }
}
$panelParents = array_values(array_unique(array_map(
    static fn(?int $index): ?int => $index === null ? null : $nodes[$index]['parent'],
    $panels
), SORT_REGULAR));
$expect(count($panelParents) === 1 && $panelParents[0] !== null, 'All five Media panels must be complete siblings, not nested or orphaned');
$visibleByDefault = array_values(array_filter(
    $panels,
    static fn(?int $index): bool => $index !== null && !array_key_exists('hidden', $nodes[$index]['attrs'])
));
$expect($visibleByDefault === [$panels['wallpapers']], 'Exactly the Wallpaper panel must be visible before JavaScript runs');

$tabs = $find($nodes, static fn(array $node): bool => isset($node['attrs']['data-media-tab']));
$tabNames = array_map(static fn(int $index): string => (string)$nodes[$index]['attrs']['data-media-tab'], $tabs);
sort($tabNames);
$expectedTabNames = $requiredPanels;
sort($expectedTabNames);
$expect($tabNames === $expectedTabNames, 'Media tabs must map one-to-one to the five complete panels');

$wallpaper = $panels['wallpapers'];
$editor = $one($byClassInside('mp2-editor', $wallpaper), 'Approved v3 wallpaper editor .mp2-editor');
$setup = $one($byClassInside('mp2-setup', $wallpaper), 'Approved v3 master-image column .mp2-setup');
$middle = $one($byClassInside('mp2-middle', $wallpaper), 'Approved v3 profile/editor area .mp2-middle');
$profiles = $one($byClassInside('mp2-profiles', $wallpaper), 'Approved v3 profile column .mp2-profiles');
$work = $one($byClassInside('mp2-work', $wallpaper), 'Approved v3 visual work area .mp2-work');
$controls = $one($byClassInside('mp2-controls', $wallpaper), 'Approved v3 placement controls .mp2-controls');
$profileList = $one($byId('ucm-bg-profile-list'), 'Dynamic wallpaper profile-card list');
$stage = $one($byId('ucm-wall-stage-host'), 'Bounded wallpaper stage');
$frame = $one($byId('ucm-wall-frame'), 'Direct-manipulation wallpaper frame');
$catalogLibrary = $one($byId('ucm-bg-grid'), 'Whole-catalog library');
foreach ([$editor, $setup, $middle, $profiles, $work, $controls, $profileList, $stage, $frame, $catalogLibrary] as $node) {
    $inside($node, $wallpaper, 'Every wallpaper workbench component must remain inside the Wallpaper panel');
}
$legacyWallpaperClasses = [];
if ($wallpaper !== null) {
    foreach ($nodes as $nodeIndex => $node) {
        if (!$isInside($nodes, $nodeIndex, $wallpaper)) {
            continue;
        }
        foreach (preg_split('/\s+/', trim((string)($node['attrs']['class'] ?? ''))) ?: [] as $class) {
            if (in_array($class, ['ucm-wallpaper-workbench', 'ucm-media-controls', 'ucm-wall-move', 'ucm-wallpaper-catalog-list'], true)
                || str_starts_with($class, 'ucm-wall-handle')) {
                $legacyWallpaperClasses[] = $class;
            }
        }
    }
}
$expect(
    $legacyWallpaperClasses === [],
    'The v3 Wallpaper workbench must not inherit conflicting pre-v3 classes: ' . implode(', ', array_unique($legacyWallpaperClasses))
);
$inside($setup, $editor, 'Master-image controls must be inside the v3 editor');
$inside($middle, $editor, 'Profile selection and visual editor must be inside the v3 editor');
$inside($controls, $editor, 'Precise placement controls must be inside the v3 editor');
$inside($profileList, $profiles, 'Profile cards must be inside the profile column');
$inside($stage, $work, 'Bounded checkerboard stage must be inside the visual work area');
$inside($frame, $stage, 'Direct-manipulation frame must be inside the bounded checkerboard stage');
$expect(
    preg_match('/function\s+renderProfileCards\s*\(\s*\)\s*\{[\s\S]{0,3000}mp2-profile-select/', $media) === 1
    && preg_match('/#ucmp-media-preview-v2\s+\.mp2-profile-select\b/', $css) === 1,
    'Dynamically rendered profile cards must match the padded v3 profile-card selector'
);
$profileRendererOffset = strpos($media, 'function renderProfileCards');
$profileRenderer = $profileRendererOffset === false ? '' : substr($media, $profileRendererOffset, 5000);
$profileActionsVariable = null;
if (preg_match('/var\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*\$\([\'\"]#ucm-bg-profile-actions[\'\"]\)\.detach\(\)/', $profileRenderer, $profileActionsMatch) === 1) {
    $profileActionsVariable = $profileActionsMatch[1];
}
$actionsAppendedToActiveCard = false;
if ($profileActionsVariable !== null) {
    $actionsAppendedToActiveCard = preg_match(
        '/if\s*\(\s*p\.key\s*===\s*activeProfileKey\s*\)\s*(?:\{\s*)?card\.append\(\s*' . preg_quote($profileActionsVariable, '/') . '\s*\)/',
        $profileRenderer
    ) === 1;
    if (!$actionsAppendedToActiveCard
        && preg_match('/if\s*\(\s*p\.key\s*===\s*activeProfileKey\s*\)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*card/', $profileRenderer, $selectedCardMatch) === 1) {
        $actionsAppendedToActiveCard = preg_match(
            '/' . preg_quote($selectedCardMatch[1], '/') . '\.append\(\s*' . preg_quote($profileActionsVariable, '/') . '\s*\)/',
            $profileRenderer
        ) === 1;
    }
}
$expect($actionsAppendedToActiveCard, 'Selected profile actions must be detached safely and appended inside the active profile card');
foreach (['ucm-bg-source', 'ucm-bg-name', 'ucm-bg-brand', 'ucm-bg-series', 'ucm-bg-model-scope',
          'ucm-bg-profile-source', 'ucm-bg-profile-enabled', 'ucm-bg-profile-invert', 'ucm-bg-save-profile', 'ucm-bg-upload',
          'ucm-wall-preview', 'ucm-wall-view-zoom'] as $id) {
    $inside($one($byId($id), "Wallpaper contract #$id"), $wallpaper, "Wallpaper contract #$id must be inside its panel");
}
$expect(
    str_contains($media, 'data-tones="<?php echo (int)($profile[\'grayscale_tones\'] ?? 0); ?>"')
    && preg_match('/tones\s*:\s*parseInt\(o\.data\([\'\"]tones[\'\"]\)/', $media) === 1,
    'Wallpaper grayscale preview must use ModelCatalog capability metadata rather than directory-name guesses'
);
$expect(
    preg_match('/paint\(full,fz,fx,fy,image\);quantizeGrayscale\(full,p\.tones,!!row\.invert\)/', $media) === 1
    && preg_match('/wallImage\.hidden\s*=\s*!image/', $media) === 1
    && str_contains($media, 'id="ucm-bg-device-output"')
    && str_contains($media, 'Exact device output preview')
    && str_contains($media, 'id="ucm-hosted-invert"')
    && str_contains($media, 'invert:rendered.invert?1:0')
    && str_contains($media, "invert:$('#ucm-hosted-invert').is(':checked')")
    && preg_match('/#ucmp-media-preview-v2\s+\.mp2-device-output\s+canvas\s*\{[^}]*image-rendering:\s*pixelated/is', $css) === 1,
    'Grayscale-capable profiles must keep a smooth usable editor while separately previewing, persisting and publishing their exact quantized inversion'
);
$expect(
    preg_match('/function\s+stageDimensions\s*\([^)]*\)\s*\{\s*return\s*\{width:\s*Math\.max\(1,p\.fw\),height:\s*Math\.max\(1,p\.fh\)\}/', $media) === 1
    && preg_match('/#ucmp-media-preview-v2\s+#ucm-wall-stage-host\s*\{[^}]*position:\s*relative[^}]*display:\s*block/is', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+#ucm-wall-scene\s*\{[^}]*position:\s*absolute[^}]*top:\s*0[^}]*left:\s*0[^}]*transform-origin:\s*0\s+0/is', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+#ucm-wall-scene\s*\{[^}]*inset\s*:/is', $css) === 0
    && preg_match('/#ucm-wall-view-zoom[\'\"]\)\.on\([\'\"]input change[\'\"]/', $media) === 1
    && preg_match('/viewZoom\s*=\s*Math\.max\(\.25[\s\S]{0,300}scale\s*=\s*Math\.max\(\.05/', $media) === 1,
    'Wallpaper camera must center logical phone pixels, auto-fit tiny profiles, and expose independent view zoom'
);
$handles = ['n', 's', 'e', 'w', 'ne', 'nw', 'se', 'sw'];
$expect(
    preg_match('/foreach\s*\(\s*\[\s*[\'\"]n[\'\"]\s*,\s*[\'\"]s[\'\"]\s*,\s*[\'\"]e[\'\"]\s*,\s*[\'\"]w[\'\"]\s*,\s*[\'\"]ne[\'\"]\s*,\s*[\'\"]nw[\'\"]\s*,\s*[\'\"]se[\'\"]\s*,\s*[\'\"]sw[\'\"]\s*\]/', $media) === 1
    && preg_match('/data-wall-handle\s*=\s*[\'\"]<\?php\s+echo\s+\$handle;\s*\?>[\'\"]/', $media) === 1,
    'Wallpaper markup must render all eight named edge/corner handles'
);
foreach ($handles as $handle) {
    $expect(
        preg_match('/#ucmp-media-preview-v2\s+\.mp2-handle\[data-wall-handle=[\'\"]' . $handle . '[\'\"]\]\s*\{/', $css) === 1,
        "Wallpaper handle $handle must have a matching root-scoped CSS position"
    );
}
$writtenInverseProperties = [];
preg_match_all('/setProperty\(\s*[\'\"](--[A-Za-z0-9_-]*handle[A-Za-z0-9_-]*inverse)[\'\"]/', $media, $writtenInverseMatches);
$writtenInverseProperties = array_values(array_unique($writtenInverseMatches[1] ?? []));
$consumedInverseProperties = [];
$expect($writtenInverseProperties !== [], 'Wallpaper fit logic must publish an inverse-handle scale property');
foreach (['mp2-move-grip', 'mp2-handle'] as $selector) {
    $consumed = null;
    if (preg_match('/#ucmp-media-preview-v2\s+\.' . $selector . '\b[^{]*\{[^}]*var\(\s*(--[A-Za-z0-9_-]*handle[A-Za-z0-9_-]*inverse)\b/is', $css, $consumedMatch) === 1) {
        $consumed = $consumedMatch[1];
        $consumedInverseProperties[] = $consumed;
    }
    $expect(
        $consumed !== null && in_array($consumed, $writtenInverseProperties, true),
        ".$selector must consume the same inverse-handle property written by JavaScript"
    );
}
$expect(count(array_unique($consumedInverseProperties)) === 1, 'Move grip and resize handles must consume one shared inverse-scale property');

$audio = $panels['audio'];
$audioWorkbench = $one($byId('ucm-audio-workbench'), 'Model-aware audio workbench');
$audioFile = $one($byId('ucm-ring-file'), 'Audio multi-file input');
foreach ([$audioWorkbench, $audioFile, $one($byId('ucm-audio-family'), 'Audio brand selector'),
          $one($byId('ucm-audio-model'), 'Audio model/profile selector'),
          $one($byId('ucm-ring-list'), 'Audio library')] as $node) {
    $inside($node, $audio, 'Audio controls and library must remain inside the Audio panel');
}
$expect($audioFile !== null && array_key_exists('multiple', $nodes[$audioFile]['attrs']), 'Audio ingest must accept multiple files');
$expect(
    preg_match('/function\s+previewRingFiles\s*\([^)]*\)\s*\{[\s\S]{0,600}Array\.from\(\s*files/', $media) === 1
    && preg_match('/files\s*=\s*Array\.from\(\s*input\.files[\s\S]{0,1800}files\.forEach\(/', $media) === 1,
    'Audio preview and publishing must iterate every selected file, not silently use files[0]'
);

$replication = $panels['replication'];
foreach (['ucm-media-sync-catalogs', 'ucm-media-sync-targets', 'ucm-media-sync-selected'] as $id) {
    $inside($one($byId($id), "Replication contract #$id"), $replication, "Replication contract #$id must be inside its panel");
}
$expect(
    preg_match('/ucm-sync-catalog[\s\S]{0,500}data-set-id|data-set-id[\s\S]{0,500}ucm-sync-catalog/', $media) === 1
    && preg_match('/(?:[\'\"])?set_ids(?:[\'\"])?\s*:/', $media) === 1,
    'Manual replication must select and submit exact catalog IDs, not only broad media categories'
);
$expect(
    $byId('ucm-hosted-sync-replication') === []
    && $byId('ucm-ring-sync') === []
    && preg_match('/#ucm-media-sync-selected[\'\"]\)\.on\([\'\"]click[\'\"][\s\S]{0,3500}selectedSetIds\([\'\"]wallpaper[\'\"]\)[\s\S]{0,3500}set_ids\s*:/', $media) === 1,
    'Manual replication must have one primary exact-selection action and no broad-only secondary controls'
);
$manualSyncOffset = strpos($media, "$('#ucm-media-sync-selected').on('click'");
$manualSyncEnd = $manualSyncOffset === false ? false : strpos($media, 'function formatMediaJobTime', $manualSyncOffset);
$manualSync = $manualSyncOffset === false ? '' : substr(
    $media,
    $manualSyncOffset,
    $manualSyncEnd === false ? 6000 : $manualSyncEnd - $manualSyncOffset
);
$expect(
    preg_match('/replicationRow\(\s*[\'\"]ringtone[\'\"][\s\S]{0,700}\{\s*name\s*:/', $media) === 1
    && preg_match('/function\s+selectedRingtoneNames\s*\(\s*\)/', $media) === 1
    && preg_match('/selectedRingtoneNames\(\)[\s\S]{0,3000}scope\s*:\s*[\'\"]ringtones[\'\"]\s*,\s*names\s*:\s*JSON\.stringify\(\s*ringtoneNames\s*\)/', $manualSync) === 1
    && substr_count($manualSync, "scope:'ringtones'") === 1,
    'Cisco ringtone replication must use exact selected names through the sole manual sync action'
);

$diagnostics = $panels['diagnostics'];
$assetViewport = $one($byClass('ucm-asset-index-viewport'), 'Bounded Asset Index viewport');
foreach ([$assetViewport, $one($byId('ucm-media-assets'), 'Asset Index body'),
          $one($byId('ucm-media-assets-prev'), 'Asset Index previous-page control'),
          $one($byId('ucm-media-assets-next'), 'Asset Index next-page control'),
          $one($byId('ucm-media-assets-page'), 'Asset Index page status')] as $node) {
    $inside($node, $diagnostics, 'Asset Index viewport and pagination must remain inside its panel');
}
$expect(
    $assetViewport !== null
    && isset($nodes[$assetViewport]['attrs']['data-page-size'])
    && (int)$nodes[$assetViewport]['attrs']['data-page-size'] > 0
    && (int)$nodes[$assetViewport]['attrs']['data-page-size'] <= 100,
    'Asset Index must declare a bounded page size of 100 rows or fewer'
);

$expect(
    preg_match('/#ucmp-media-preview-v2\.mp2-app\b[^{]*\{/', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+\.mp2-editor\b/', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+\.mp2-controls\b/', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+#ucm-wall-scene\b[^{]*\{[^}]*transform-origin\s*:\s*0(?:px)?\s+0(?:px)?/is', $css) === 1
    && preg_match('/#ucmp-media-preview-v2\s+\.ucm-asset-index-viewport\b[^{]*\{(?=[^}]*max-height\s*:)(?=[^}]*overflow(?:-y)?\s*:\s*(?:auto|scroll))[^}]*\}/is', $css) === 1
    && preg_match('/#ucmp-media-preview-v2[^\{]*(?:\[data-media-panel\]|\[data-media-owned-panel\]|\.ucm-media-panel)\[hidden\][^{]*\{[^}]*display\s*:\s*none\b/is', $css) === 1,
    'Media v3 root/layout, stage origin, bounded Asset Index, and hidden-panel rules must be correctly scoped'
);
$guardOffset = strpos($media, 'window.ucmpMediaContractCheck');
$guardSource = $guardOffset === false ? '' : substr($media, $guardOffset, 3000);
$globalOwnershipScan = preg_match('/\$\(\s*[\'\"]\[data-media-owned-panel\][\'\"]\s*\)/', $guardSource) === 1
    || preg_match('/\$\(\s*document\s*\)\.find\(\s*[\'\"]\[data-media-owned-panel\][\'\"]\s*\)/', $guardSource) === 1;
$rootOwnershipCheck = preg_match('/closest\(\s*[\'\"]#ucmp-media-preview-v2[\'\"]\s*\)/', $guardSource) === 1
    || str_contains($guardSource, 'mediaRoot[0].contains');
$expect(
    preg_match('/window\.ucmpMediaContractCheck\s*=\s*function\b/', $guardSource) === 1
    && $globalOwnershipScan
    && $rootOwnershipCheck
    && !str_contains($guardSource, "mediaRoot.find('[data-media-owned-panel]')")
    && str_contains($guardSource, 'visiblePanels')
    && str_contains($guardSource, 'orphaned'),
    'Media runtime guard must globally detect escaped ownership markers and enforce one visible panel'
);
$expect(
    preg_match('/hash_file\(\s*[\'\"]sha256[\'\"]\s*,\s*\$ucmpCssFile\s*\)/', $page) === 1
    && preg_match('/ucmprovisioner\.css\?v=<\?php\s+echo\s+rawurlencode\(\$ucmpCssVersion\)/', $page) === 1,
    'The stylesheet URL must use a content-derived version token so deployed CSS cannot remain stale'
);

if ($failures) {
    fwrite(STDERR, implode("\n", array_values(array_unique($failures))) . "\n");
    exit(1);
}
echo "Media UI v3 structural and behavior-contract assertions passed\n";
