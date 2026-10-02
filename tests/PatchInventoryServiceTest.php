<?php

require_once __DIR__ . '/../lib/PatchInventoryService.php';

use FreePBX\modules\Ucmprovisioner\PatchInventoryService;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$outputs = [
    'asterisk_version'=>'Asterisk 20.20.0 built by mock',
    'installed_asterisk_version'=>'Asterisk 20.20.0',
    'chan_sip'=>"Global Settings:\n  UDP Bindaddress: 0.0.0.0:5060",
    'usecallmanager'=>"  -= Info about application 'SIPCiscoPage' =-",
    'packages'=>"asterisk20\t20.20.0-local\nasterisk20-core:amd64\t20.20.0-local\nunrelated\t1.0",
    'holds'=>"asterisk-version-switch\nasterisk20-core\nlinux-image-amd64",
    'apt_policy'=>"asterisk20:\n  Installed: 20.20.0-local\n  Candidate: 20.20.0-local\n  Version table:\n *** 20.20.0-local 1001\n        100 /var/lib/dpkg/status\nasterisk20-core:amd64:\n  Installed: 20.20.0-local\n  Candidate: 20.20.1\n  Version table:\n *** 20.20.0-local 100\n        100 /var/lib/dpkg/status",
    'binary_path'=>'/usr/sbin/asterisk',
    'binary_hash'=>str_repeat('a', 64) . "  /usr/sbin/asterisk\n",
];
$commands = [];
$service = new PatchInventoryService(
    static function (string $name, array $command) use ($outputs, &$commands): string {
        $commands[$name] = $command;
        return $outputs[$name] ?? '';
    }
);
$inventory = $service->inventory();

$expect($inventory['running_version'] === '20.20.0' && $inventory['installed_version'] === '20.20.0', 'Running and installed versions must be separate');
$expect($inventory['runtime_eligible'] === true, 'Asterisk, chan_sip and SIPCiscoPage must pass runtime eligibility');
$expect(($inventory['candidate']['patch_sha256'] ?? '') === '22497973cf634b56d3bfa94c123d6444a38b66a3254d263b94d14174f8bb76ca', 'Exact patch candidate is wrong');
$expect(($inventory['candidate']['production_installable'] ?? false) === true, 'Compatible catalog release must be selectable');
$expect(($inventory['assessment']['compatible'] ?? false) === true, 'Exact compatible patched runtime must not be blocked by a newer vanilla release');
$expect($inventory['version_switch_held'] === true && $inventory['package_hold_detected'] === true, 'FreePBX version-switch hold must be detected');
$expect($inventory['held_asterisk_packages'] === ['asterisk20-core:amd64'], 'Architecture-qualified package hold matching failed');
$expect($inventory['exact_version_pin_detected'] === true, 'Exact apt priority detection failed');
$expect($inventory['pinned_asterisk_packages'] === ['asterisk20'], 'APT priority pin must remain distinct from apt-mark hold');
$expect(($inventory['package_recipe_gate']['code'] ?? '') === 'SIMPLE_SOURCE_BUILD' && !empty($inventory['package_recipe_gate']['passed']), 'Simple source-build path must be available');
$expect($inventory['actions_enabled'] === true, 'Inventory must advertise the enrolled manager workflow');
$expect(!in_array('asterisk*', $commands['packages'] ?? [], true), 'Package probe must not use a broad wildcard');
$expect(in_array('asterisk-version-switch', $commands['packages'] ?? [], true), 'Inventory must inspect the real FreePBX version-switch package');
$expect(($commands['chan_sip'] ?? []) === [
    '/usr/bin/timeout', '--signal=KILL', '5',
    '/usr/sbin/asterisk', '-rx', 'sip show settings',
], 'chan_sip detection must use the bounded operational SIP command');

$untracked = $outputs;
$untracked['asterisk_version'] = 'Asterisk 20.19.1';
$untracked['installed_asterisk_version'] = 'Asterisk 20.19.1';
$untrackedInventory = (new PatchInventoryService(
    static fn(string $name, array $command): string => $untracked[$name] ?? ''
))->inventory();
$expect(($untrackedInventory['assessment']['code'] ?? '') === 'runtime_patched_untracked', 'Uncatalogued patched runtime must be called untracked');
$expect($untrackedInventory['candidate'] === null, 'A nearby version must never inherit another patch');

$asterisk22 = $outputs;
$asterisk22['asterisk_version'] = 'Asterisk 22.10.0';
$asterisk22['installed_asterisk_version'] = 'Asterisk 22.10.0';
$asterisk22['chan_sip'] = "No such command 'sip show settings'";
$asterisk22Inventory = (new PatchInventoryService(
    static fn(string $name, array $command): string => $asterisk22[$name] ?? ''
))->inventory();
$expect(($asterisk22Inventory['assessment']['code'] ?? '') === 'asterisk_22_module_blocked', 'Unsupported Asterisk 22 grammar must remain visible');
$expect($asterisk22Inventory['candidate'] === null, 'Asterisk 22 must not appear in the compatible dropdown');

$expect(PatchInventoryService::parseAsteriskVersion('garbage') === '', 'Invalid version output must fail closed');
$expect(count(PatchInventoryService::candidates()) === 4, 'Manifest must expose four compatible releases');

echo "Patch inventory, compatibility and FreePBX pin fixtures passed\n";
