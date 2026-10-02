<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

foreach (['settings.php', 'groups.php', 'templates.php'] as $view) {
    $source = (string)file_get_contents($root . '/views/' . $view);
    $expect(str_contains($source, 'data-ucm-picker'), $view . ' must use the compact destination picker');
    $expect(str_contains($source, 'ucm-picker-filter') && str_contains($source, 'ucm-picker-count'), $view . ' picker must expose search and selected/visible counts');
    $expect(str_contains($source, 'ucm-picker-all') && str_contains($source, 'ucm-picker-clear'), $view . ' picker must expose select-all and clear actions');
    $expect(str_contains($source, 'refreshPickers()'), $view . ' picker must refresh after saved-job loading');
}

$settings = (string)file_get_contents($root . '/views/settings.php');
$expect(str_contains($settings, 'ucm-settings-sync-scope') && str_contains($settings, 'ucm-picker-all'), 'Settings must retain subsection selection with bulk actions');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$expect(str_contains($css, '.ucm-picker-list') && str_contains($css, 'max-height:230px') && str_contains($css, 'overflow:auto'), 'Picker lists must remain compact and scrollable for larger site fleets');

echo "Configuration sync picker UI contract passed\n";
