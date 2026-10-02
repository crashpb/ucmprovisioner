<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$directory = (string)file_get_contents($root . '/views/directory.php');
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expect(
    str_contains($module, "case 'savedirectorysource'")
    && str_contains($module, "case 'savedirectorysourcesecret'")
    && !str_contains($module, "case 'migratedirectorysource'")
    && str_contains($module, "case 'toggledirectorysource'")
    && str_contains($module, 'assertDirectorySourcePayloadSafe')
    && str_contains($module, "'expected_updated'"),
    'Directory-source metadata, external-secret and state mutations must cross the validated BMO/CAS boundary'
);
$expect(
    str_contains((string)file_get_contents($root . '/install.php'), '->upgradeCredentialStorage()')
    && !str_contains($directory, 'value="migratedirectorysource"'),
    'Credential migration belongs to installation, not a separately callable browser action'
);
$expect(
    str_contains($directory, 'name="source[secret_ref]"')
    && !str_contains($directory, 'name="source[password')
    && !str_contains($directory, 'name="source[ad_bind_password]')
    && str_contains($directory, 'name="directory_secret[bind_user]"')
    && str_contains($directory, 'name="directory_secret[password]"')
    && str_contains($directory, 'neither value is stored in the module database, returned by an API, or rendered after submission'),
    'Source metadata must exchange only an opaque reference while credential provisioning uses its separate write-only boundary'
);
$expect(
    str_contains($directory, 'every enabled source runs on its own schedule')
    && str_contains($directory, 'A manual run executes every enabled managed authority independently')
    && str_contains($directory, 'Domain controllers, highest priority first')
    && str_contains($directory, 'name="source[expected_updated]"')
    && str_contains($directory, 'ucm-directory-source-test')
    && str_contains($directory, 'ucm-directory-source-sync'),
    'The UI must expose active independent scheduling, multi-DC authorities, and per-source operations'
);
$expect(
    str_contains($module, 'UNIQUE KEY uniq_ucmp_directory_domain (domain)')
    && str_contains($module, 'ADD UNIQUE INDEX uniq_ucmp_directory_domain (domain)')
    && str_contains($module, 'claim the same canonical AD domain'),
    'One canonical AD domain must have one database-enforced authority without silent merging'
);
$expect(
    str_contains($module, 'KEY idx_ucmp_directory_source (source_key)')
    && str_contains($module, 'UNIQUE KEY idx_ucmp_directory_object (source_id,object_key)')
    && str_contains($module, 'newer.source_id = older.source_id')
    && str_contains($module, 'newer.object_key = older.object_key')
    && str_contains($module, 'newer.updated > older.updated')
    && !str_contains($module, 'UNIQUE KEY idx_ucmp_directory_source (source_key)')
    && !str_contains($module, 'ADD UNIQUE INDEX idx_ucmp_directory_source (source_key)'),
    'Directory object identity must be unique only within its source authority'
);
$expect(
    !str_contains($directory, 'ucm-ad-resolve-write')
    && !str_contains($directory, "mode:'writeback'")
    && str_contains($module, "['local','clear','reconcile']")
    && str_contains($module, 'never writes conflict corrections to AD'),
    'Conflict handling must never expose or accept AD writeback'
);

echo "Directory source boundary assertions passed\n";
