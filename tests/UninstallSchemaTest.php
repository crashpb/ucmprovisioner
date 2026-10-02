<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = (string)file_get_contents($root . '/module.xml');
$uninstall = (string)file_get_contents($root . '/uninstall.php');
preg_match_all('/<table name="([^"]+)"/', $manifest, $matches);
foreach ($matches[1] as $table) {
    if (!str_contains($uninstall, "'$table'")) {
        throw new RuntimeException("Uninstall hook omits module-owned table $table");
    }
}
echo "Uninstall schema assertions passed\n";
