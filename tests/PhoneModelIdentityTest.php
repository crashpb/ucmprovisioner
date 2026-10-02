<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/PhoneModelIdentity.php';

use FreePBX\modules\Ucmprovisioner\PhoneModelIdentity;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$database = static function (int $w70bId, int $t20pId): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ucmp_models (id INTEGER PRIMARY KEY, vendor TEXT, model TEXT, family TEXT)');
    $insert = $db->prepare('INSERT INTO ucmp_models (id,vendor,model,family) VALUES (?,?,?,?)');
    $insert->execute([$w70bId, 'Yealink', 'W70B', 'yealink']);
    $insert->execute([$t20pId, 'Yealink', 'T20P', 'yealink']);
    return $db;
};

$controller = $database(57, 72);
$remote = $database(72, 557);
$key = PhoneModelIdentity::key('Yealink', 'W70B');
$expect((int)PhoneModelIdentity::resolve($controller, $key)['id'] === 57, 'Controller fixture must resolve its own W70B row');
$expect((int)PhoneModelIdentity::resolve($remote, $key)['id'] === 72, 'Remote fixture must resolve W70B by identity despite a different numeric ID');
$expect((string)PhoneModelIdentity::resolve($remote, 'YEALINK|w70b')['model'] === 'W70B', 'Model identity matching must be case-insensitive');

foreach (['', 'Yealink', 'Yealink|', '|W70B', 'Yealink|W70B|extra'] as $invalid) {
    try {
        PhoneModelIdentity::parse($invalid);
        throw new RuntimeException("Invalid identity was accepted: $invalid");
    } catch (InvalidArgumentException) {}
}

echo "Cross-site phone model identity fixtures passed\n";
