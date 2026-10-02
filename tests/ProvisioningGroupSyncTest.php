<?php
declare(strict_types=1);

// This focused contract test intentionally avoids bootstrapping FreePBX.  The
// production class is a BMO and needs a live FreePBX database; these assertions
// pin Provisioner's own parent/child group-sync invariants. This has no relation
// to Microsoft Active Directory Group Policy.
$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$class = file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$groups = file_get_contents(dirname(__DIR__) . '/views/groups.php');
$settings = file_get_contents(dirname(__DIR__) . '/views/settings.php');
if ($class === false || $groups === false || $settings === false) {
    throw new RuntimeException('Configuration sync production/view files could not be read');
}

$expect(str_contains($class, 'configurationSyncStableGroupKey($sourceIdentity, $id)'), 'Group object identity must use immutable source-site + source-group identity');
$expect(str_contains($class, "['_source_group_id'] = \$id"), 'Group snapshot must retain the immutable source group id');
$expect(str_contains($class, "'group_path'=>\$path"), 'Group snapshot must retain a display/matching path separately from its object key');
$expect(str_contains($class, 'configurationSyncGroupPathKey($sourcePath)'), 'First-time destination matching must use group_path, not a path-derived object key');
$expect(str_contains($class, "'parent_group_key'=>\$parentId > 0 ? \$this->configurationSyncStableGroupKey"), 'Parent references must use the same stable identity');
$expect(str_contains($class, 'Apply ancestors before descendants'), 'Group snapshot order must be parent-first and deterministic');
$revisionStart = strpos($class, '$contentRevision = $this->configurationSyncDigest([');
$revisionEnd = $revisionStart === false ? false : strpos($class, "            \$intended['_source_group_id']", $revisionStart);
$expect($revisionStart !== false && $revisionEnd !== false, 'Group content revision block must remain explicit and inspectable');
$revisionBlock = $revisionStart !== false && $revisionEnd !== false
    ? substr($class, $revisionStart, $revisionEnd - $revisionStart)
    : '';
$expect(str_contains($revisionBlock, "'name'=>\$intended['name']"), 'Group content revision must include the group’s own name');
$expect(str_contains($revisionBlock, "'parent_group_key'=>\$intended['parent_group_key']"), 'Group content revision must include the stable parent identity');
$expect(!str_contains($revisionBlock, "'group_path'"), 'Group content revision must exclude display/matching paths');
$expect(!str_contains($revisionBlock, "'_source_group_id'") && !str_contains($revisionBlock, "'_object_key'") && !str_contains($revisionBlock, "'_dependency_only'"), 'Group content revision must exclude transport metadata');
$expect(str_contains($class, 'A sibling provisioning group with this name already exists on this site'), 'Normalized duplicate sibling names must be rejected');
$expect(str_contains($class, 'templateDependencyMissing && $destination'), 'A missing template dependency must preserve an existing destination stack');
$expect(str_contains($class, "'template_map'] = []"), 'A new destination with missing template dependencies must receive no partial stack');
$expect(str_contains($revisionBlock, "'member_exceptions'=>\$intended['member_exceptions']"), 'Group content revisions must include member exceptions');
$expect(str_contains($class, "'member_exceptions'=>\$destination['member_exceptions'] ?? []"), 'Group sync must preserve site-local member exceptions');
$expect(str_contains($groups, "configurationSyncStates(\$siteFilter, 'group')"), 'Group badges must read destination-side inbound sync states');
$expect(str_contains($groups, "destination_id"), 'Group badges must map inbound state by destination_id');
$expect(str_contains($settings, 'settingsInboundSyncStates'), 'Settings page must load granular inbound sync states');
$expect(str_contains($settings, 'I understand that changing a highlighted subsection pauses its inbound sync'), 'Settings page must expose confirmation for soft-locked granular scopes');

echo "Provisioning group synchronization contract assertions passed\n";
