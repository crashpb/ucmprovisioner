<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/LogicalExtensionService.php';

use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;

final class LogicalMovePdo extends PDO
{
    public ?Closure $beforeDestinationWrite = null;
}
final class LogicalMoveStatement extends PDOStatement
{
    protected function __construct(private LogicalMovePdo $db) {}
    public function execute(?array $params = null): bool
    {
        if ($this->db->beforeDestinationWrite && preg_match('/SET\s+ad_source_id=\?/', $this->queryString)) {
            $callback = $this->db->beforeDestinationWrite;
            $this->db->beforeDestinationWrite = null;
            $callback();
        }
        return parent::execute($params);
    }
}

function logicalExtensionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function logicalExtensionThrows(callable $callable, string $message): void
{
    try {
        $callable();
    } catch (InvalidArgumentException|DomainException $e) {
        return;
    }
    throw new RuntimeException($message);
}

$identity = [
    'extension' => ' 4019 ',
    'display_name' => ' Hamed Haghani ',
    'given_name' => ' Hamed ',
    'surname' => ' Haghani ',
    'label' => ' H.Haghani ',
    'department' => ' IT ',
    'source_id' => 2,
    'source_revision' => 'ad-rev-17',
];
$normalized = LogicalExtensionService::normalizeAdIdentity($identity);
logicalExtensionExpect($normalized['extension'] === '4019', 'AD extension must be normalized');
logicalExtensionExpect($normalized['display_name'] === 'Hamed Haghani', 'AD display name must be normalized');
logicalExtensionExpect(array_keys($normalized) === LogicalExtensionService::CANONICAL_FIELDS, 'Canonical field order must be stable');
logicalExtensionExpect(
    LogicalExtensionService::canonicalHash($identity)
        === LogicalExtensionService::canonicalHash(array_reverse($identity, true)),
    'Canonical hash must not depend on input key order'
);

$resolved = LogicalExtensionService::resolveIdentity(
    $identity,
    [
        [
            'id' => 10,
            'mode' => 'canonical',
            'field_name' => 'display_name',
            'value_json' => '"John Doe"',
            'reason' => 'HR-approved presentation name',
            'source_revision' => 'ad-rev-17',
        ],
        [
            'id' => 11,
            'mode' => 'final',
            'field_name' => 'phone_label',
            'value' => '<John Doe> SIP',
            'reason' => 'Legacy Cisco SIP suffix spacing',
            'source_revision' => 'ad-rev-17',
        ],
    ],
    static function (array $canonical): array {
        return [
            'phone_label' => '<' . $canonical['display_name'] . '>SIP',
            'line_label' => $canonical['display_name'],
            'blf_label' => $canonical['display_name'],
            'directory_label' => $canonical['display_name'],
        ];
    }
);
logicalExtensionExpect($resolved['ad']['display_name'] === 'Hamed Haghani', 'Override must not mutate AD snapshot');
logicalExtensionExpect($resolved['canonical']['display_name'] === 'John Doe', 'Canonical override must precede formatter');
logicalExtensionExpect($resolved['outputs']['phone_label'] === '<John Doe> SIP', 'Final override must win after formatter');
logicalExtensionExpect($resolved['outputs']['line_label'] === 'John Doe', 'Non-overridden formatted label must remain');
logicalExtensionExpect(
    $resolved['trace']['canonical']['display_name']['override_id'] === 10
        && $resolved['trace']['outputs']['phone_label']['override_id'] === 11,
    'Every effective override must retain trace metadata'
);
logicalExtensionExpect(
    $resolved['canonical_hash'] !== $resolved['effective_hash'],
    'Canonical and fully formatted identity hashes must have distinct scopes'
);

$blank = LogicalExtensionService::resolveIdentity(
    $identity,
    [['mode' => 'final', 'field_name' => 'blf_label', 'value' => '', 'reason' => 'intentional blank']]
);
logicalExtensionExpect(
    array_key_exists('blf_label', $blank['outputs']) && $blank['outputs']['blf_label'] === '',
    'An explicit final-label blank must not be treated as missing'
);

$zero = LogicalExtensionService::resolveIdentity(
    $identity,
    [['mode' => 'final', 'field_name' => 'line_label', 'value' => 0, 'reason' => 'literal zero']]
);
logicalExtensionExpect($zero['outputs']['line_label'] === '0', 'A zero override must not be treated as missing');

logicalExtensionThrows(
    static fn() => LogicalExtensionService::normalizeAdIdentity(['extension' => '4019']),
    'AD identity without a display name must be rejected'
);
logicalExtensionThrows(
    static fn() => LogicalExtensionService::resolveIdentity(
        $identity,
        [['mode' => 'canonical', 'field_name' => 'extension', 'value' => '']]
    ),
    'Empty canonical extension override must be rejected'
);
logicalExtensionThrows(
    static fn() => LogicalExtensionService::resolveIdentity(
        $identity,
        [['mode' => 'final', 'field_name' => 'firmware', 'value' => 'x']]
    ),
    'Identity override must not escape into device policy'
);

$plan = LogicalExtensionService::planLegacyBackfill([
    ['id' => 1, 'site_id' => 10, 'extension' => '4019', 'group_id' => 5, 'label' => 'Old label'],
    ['id' => 2, 'site_id' => 10, 'extension' => '4019', 'group_id' => 5, 'label' => 'Another label'],
    ['id' => 3, 'site_id' => 10, 'extension' => '4020', 'group_id' => 6, 'label' => 'Alice'],
    ['id' => 4, 'site_id' => 10, 'extension' => '4020', 'group_id' => 7, 'label' => 'Alice'],
    ['id' => 5, 'site_id' => 11, 'extension' => '4019', 'group_id' => 8, 'label' => 'Remote 4019'],
    ['id' => 6, 'site_id' => 0, 'extension' => '4999', 'group_id' => 9, 'label' => 'No site'],
    ['id' => 7, 'site_id' => 10, 'extension' => '', 'group_id' => 9, 'label' => 'No extension'],
]);
logicalExtensionExpect(count($plan['extensions']) === 3, 'Backfill key must be site plus extension');
logicalExtensionExpect(count($plan['skipped']) === 2, 'Unsafe legacy rows must be reported and skipped');

$byKey = [];
foreach ($plan['extensions'] as $candidate) {
    $byKey[$candidate['site_id'] . ':' . $candidate['extension']] = $candidate;
}
logicalExtensionExpect(
    $byKey['10:4019']['group_id'] === 5 && !$byKey['10:4019']['group_conflict'],
    'One unambiguous legacy group may seed the logical extension'
);
logicalExtensionExpect(
    $byKey['10:4020']['group_id'] === null
        && $byKey['10:4020']['group_conflict']
        && $byKey['10:4020']['legacy_group_ids'] === [6, 7],
    'Conflicting legacy groups must not be guessed'
);
logicalExtensionExpect(
    $byKey['10:4019']['label_candidates'] === ['Another label', 'Old label'],
    'Legacy labels must be review candidates instead of automatic AD overrides'
);

$extensionSql = LogicalExtensionService::extensionsTableSql();
$overrideSql = LogicalExtensionService::overridesTableSql();
foreach ([
    'UNIQUE KEY uniq_ucmp_extensions_site_ext (site_id, extension)',
    'UNIQUE KEY uniq_ucmp_extensions_ad_object (ad_source_id, ad_object_key)',
    "apply_status VARCHAR(24) NOT NULL DEFAULT 'unbound'",
] as $needle) {
    logicalExtensionExpect(str_contains($extensionSql, $needle), "Extension schema is missing {$needle}");
}
foreach ([
    'value_json LONGTEXT NOT NULL',
    'UNIQUE KEY uniq_ucmp_identity_override (scope_key, field_name, mode)',
    'updated_by VARCHAR(128) NOT NULL',
] as $needle) {
    logicalExtensionExpect(str_contains($overrideSql, $needle), "Override schema is missing {$needle}");
}

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $db = new LogicalMovePdo('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [LogicalMoveStatement::class, [$db]]);
    $db->exec("CREATE TABLE ucmp_extensions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        site_id INTEGER NOT NULL,
        extension TEXT NOT NULL,
        ad_source_id INTEGER NULL,
        ad_object_key TEXT NULL,
        ad_source_revision TEXT NULL,
        binding_mode TEXT NOT NULL DEFAULT 'ad_primary',
        canonical_identity_hash TEXT,
        identity_adoption TEXT NOT NULL DEFAULT 'legacy_review',
        desired_identity_hash TEXT NULL,
        applied_identity_hash TEXT NULL,
        apply_status TEXT NOT NULL DEFAULT 'unbound',
        apply_error TEXT NULL,
        revision INTEGER NOT NULL DEFAULT 1,
        updated INTEGER NOT NULL DEFAULT 0,
        UNIQUE(site_id,extension),
        UNIQUE(ad_source_id,ad_object_key)
    )");
    $db->exec('CREATE TABLE ucmp_identity_overrides (extension_id INTEGER,source_id INTEGER,object_key TEXT)');
    $insert = $db->prepare('INSERT INTO ucmp_extensions (site_id,extension,ad_source_id,ad_object_key,ad_source_revision,apply_status,revision,updated) VALUES (?,?,?,?,?,?,1,1)');
    $insert->execute([5,'321',1,'object-h','r1','applied']);
    $insert->execute([5,'301',1,'object-b','r1','applied']);
    $insert->execute([9,'900',2,'outside-object','r1','applied']);
    $service = new LogicalExtensionService($db);
    $automatic = $service->reconcileAuthoritativeAdIdentityMoves([
        ['source_id'=>1,'object_key'=>'object-h','source_revision'=>'r2','site_id'=>5,'identity'=>['extension'=>'301','display_name'=>'H. Ganji','label'=>'H.Ganji']],
        ['source_id'=>1,'object_key'=>'object-b','source_revision'=>'r2','site_id'=>1,'identity'=>['extension'=>'4104','display_name'=>'Bahman','label'=>'Bahman']],
    ], 100);
    $moves = $automatic['applied'];
    logicalExtensionExpect(count($moves) === 2, 'A reviewed AD move chain must reconcile atomically');
    logicalExtensionExpect($automatic['blocked'] === [], 'A complete authoritative AD move chain must not require manual review');
    $bound = array_map(static fn(array $row): array => ['site_id'=>(int)$row['site_id'],'extension'=>(string)$row['extension'],'ad_object_key'=>(string)$row['ad_object_key']], $db->query("SELECT site_id,extension,ad_object_key FROM ucmp_extensions WHERE ad_object_key IS NOT NULL ORDER BY ad_object_key")->fetchAll(PDO::FETCH_ASSOC));
    logicalExtensionExpect($bound === [
        ['site_id'=>1,'extension'=>'4104','ad_object_key'=>'object-b'],
        ['site_id'=>5,'extension'=>'301','ad_object_key'=>'object-h'],
        ['site_id'=>9,'extension'=>'900','ad_object_key'=>'outside-object'],
    ], 'Reconciliation must move AD bindings while leaving site/number slots in place');
    logicalExtensionThrows(
        static fn() => $service->reconcileAdIdentityMoves([
            ['source_id'=>1,'object_key'=>'object-h','source_revision'=>'r3','site_id'=>9,'identity'=>['extension'=>'900','display_name'=>'H. Ganji','label'=>'H.Ganji']],
        ], 101),
        'A destination occupied by an unreviewed AD object must abort reconciliation'
    );
    $stillBoundRaw = $db->query("SELECT site_id,extension FROM ucmp_extensions WHERE ad_object_key='object-h'")->fetch(PDO::FETCH_ASSOC);
    $stillBound = ['site_id'=>(int)$stillBoundRaw['site_id'],'extension'=>(string)$stillBoundRaw['extension']];
    logicalExtensionExpect($stillBound === ['site_id'=>5,'extension'=>'301'], 'Failed reconciliation must leave the accepted binding unchanged');
    $db->exec("CREATE TRIGGER reject_move_fill BEFORE UPDATE OF ad_source_id ON ucmp_extensions WHEN NEW.ad_source_id IS NOT NULL BEGIN SELECT RAISE(ABORT,'fixture destination write failed'); END");
    $beforeMoves = $db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE fixture_outer_work (value INTEGER)');
    $db->beginTransaction();
    $db->exec('INSERT INTO fixture_outer_work VALUES (1)');
    try {
        $service->reconcileAdIdentityMoves([
            ['source_id'=>1,'object_key'=>'object-h','source_revision'=>'r3','site_id'=>5,'identity'=>['extension'=>'321','display_name'=>'Fixture Person']],
        ]);
        throw new RuntimeException('Injected move failure was ignored');
    } catch (PDOException $expected) {}
    logicalExtensionExpect($db->inTransaction() && (int)$db->query('SELECT COUNT(*) FROM fixture_outer_work')->fetchColumn() === 1, 'Failed nested move must preserve unrelated outer work');
    logicalExtensionExpect($beforeMoves === $db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'A caught nested move failure must not leave cleared source bindings to be committed');
    $db->commit();
    $db->exec('DROP TRIGGER reject_move_fill');
    $raced = false;
    $db->beforeDestinationWrite = function () use ($db, &$raced): void {
        $raced = true;
        $db->exec("UPDATE ucmp_extensions SET binding_mode='manual_shared',revision=revision+1 WHERE site_id=5 AND extension='321'");
    };
    logicalExtensionThrows(static fn() => $service->reconcileAdIdentityMoves([
        ['source_id'=>1,'object_key'=>'object-h','source_revision'=>'r3','site_id'=>5,'identity'=>['extension'=>'321','display_name'=>'Fixture Person']],
    ]), 'A destination changed between read and write must not receive the stale AD assignment');
    logicalExtensionExpect($raced && $beforeMoves === $db->query('SELECT * FROM ucmp_extensions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'A raced destination must abort the complete move without clearing its source');
    $blockedAutomatic = $service->reconcileAuthoritativeAdIdentityMoves([
        ['source_id'=>1,'object_key'=>'object-h','source_revision'=>'r3','site_id'=>9,'identity'=>['extension'=>'900','display_name'=>'H. Ganji','label'=>'H.Ganji']],
    ], 102);
    logicalExtensionExpect(
        $blockedAutomatic['applied'] === [] && count($blockedAutomatic['blocked']) === 1,
        'An AD move into an independently occupied destination must remain an explicit conflict'
    );
}

echo "LogicalExtensionService tests passed\n";
