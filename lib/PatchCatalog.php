<?php
/** Reviewed Asterisk 20 releases with exact matching USECALLMANAGER patches. */
namespace FreePBX\modules\Ucmprovisioner;

final class PatchCatalog
{
    public const SCHEMA_VERSION = 2;
    public const MANIFEST_DATE = '2026-07-26';
    public const UPSTREAM_PAGE = 'https://usecallmanager.nz/patching-asterisk.html';
    public const OPERATIONS = [
        'preview','download','stage','build','capture','import',
        'activate','pin','unpin','rollback','delete',
    ];

    private const PATCH_COMMIT = 'e59678c3e37a2a31e682cd5e9502f476ed4a6a43';

    /** @return array<int,array<string,mixed>> */
    public static function entries(): array
    {
        return [
            self::entry(
                '20.20.0',
                '22497973cf634b56d3bfa94c123d6444a38b66a3254d263b94d14174f8bb76ca',
                'c26751ed2772c222129bea65db390a456df8539cc8fc70a4589af3536ff088a7',
                'Current stable USECALLMANAGER Asterisk 20 patch. A newer vanilla Asterisk release does not make this exact patched release unavailable.'
            ),
            self::entry(
                '20.19.0',
                'adcb88c17a1cf8eb6242c7d9f1959f06afc64ae9af210268abaf767d388b83d2',
                '10fbc070f9aaec50b788962dbcddf694fcc61d49c1f1bb158bf04a2e7e46aba0',
                'Matches the patched Asterisk currently running on the inspected FreePBX 17 system.'
            ),
            self::entry(
                '20.18.0',
                '0748085f7f33c997ced0f8e5544e6d5d063fdfdc35f222684b997e8d6253c568',
                '277d3fbfbb9edaec70fbe9c0d977d10d23a8d945691b587793467aed23a3a39d',
                'Older compatible Asterisk 20 release retained as an operator-selectable build.'
            ),
            self::entry(
                '20.16.0',
                'b01462252bf3fc51671b682b7c347a5c253213b0ec5025c23c6535aba4c8085d',
                '30945737855f47200b6986d917377989fd284fab50bf4d7646c63bda50bbf7b7',
                'Fourth most recent compatible Asterisk 20 patch retained for rollback and compatibility needs.'
            ),
        ];
    }

    /** @return array<string,mixed> */
    private static function entry(string $version, string $patchSha256, string $sourceSha256, string $note): array
    {
        $id = 'asterisk-' . $version . '-ucm-' . substr(self::PATCH_COMMIT, 0, 8);
        return [
            'catalog_id'=>$id,
            'asterisk_version'=>$version,
            'series'=>20,
            'upstream_status'=>$version === '20.20.0' ? 'stable' : 'retained compatible',
            'recommended'=>$version === '20.20.0',
            'module_compatible'=>true,
            'operator_selectable'=>true,
            'production_installable'=>true,
            'security_status'=>$version === '20.20.0' ? 'UPSTREAM_PATCH_CURRENT' : 'OLDER_COMPATIBLE_WARNING',
            'patch_filename'=>"cisco-usecallmanager-$version.patch",
            'patch_commit'=>self::PATCH_COMMIT,
            'patch_commit_url'=>'https://github.com/usecallmanagernz/patches/commit/' . self::PATCH_COMMIT,
            'patch_url'=>'https://raw.githubusercontent.com/usecallmanagernz/patches/' . self::PATCH_COMMIT
                . "/asterisk/cisco-usecallmanager-$version.patch",
            'patch_sha256'=>$patchSha256,
            'source_filename'=>"asterisk-$version.tar.gz",
            'source_url'=>"https://downloads.asterisk.org/pub/telephony/asterisk/old-releases/asterisk-$version.tar.gz",
            'source_sha256'=>$sourceSha256,
            'configure_args'=>['--with-jansson-bundled'],
            'module_note'=>$note,
        ];
    }

    public static function find(string $catalogId): ?array
    {
        foreach (self::entries() as $entry) {
            if (hash_equals((string)$entry['catalog_id'], $catalogId)) { return $entry; }
        }
        return null;
    }

    public static function forVersion(string $version): ?array
    {
        foreach (self::entries() as $entry) {
            if (hash_equals((string)$entry['asterisk_version'], $version)) { return $entry; }
        }
        return null;
    }

    public static function digest(): string
    {
        return hash('sha256', self::canonicalJson([
            'schema_version'=>self::SCHEMA_VERSION,
            'manifest_date'=>self::MANIFEST_DATE,
            'entries'=>self::entries(),
        ]));
    }

    public static function entryDigest(array $entry): string
    {
        return hash('sha256', self::canonicalJson(['schema_version'=>self::SCHEMA_VERSION,'entry'=>$entry]));
    }

    /** @return array<int,array{code:string,passed:bool,message:string}> */
    public static function gates(array $entry, string $operation): array
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unknown patch workflow operation');
        }
        $exact = preg_match('/^[0-9]+(?:\.[0-9]+){2,3}$/D', (string)($entry['asterisk_version'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string)($entry['source_sha256'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string)($entry['patch_sha256'] ?? '')) === 1
            && str_contains((string)($entry['source_url'] ?? ''), (string)$entry['asterisk_version'])
            && str_contains((string)($entry['patch_url'] ?? ''), (string)$entry['asterisk_version']);
        return [
            ['code'=>'EXACT_MATCHING_ARTIFACTS','passed'=>$exact,'message'=>'The source and patch must name the exact same Asterisk version.'],
            ['code'=>'MODULE_COMPATIBLE','passed'=>!empty($entry['module_compatible']),'message'=>'This module must support the selected Asterisk driver grammar.'],
            ['code'=>'OPERATOR_SELECTABLE','passed'=>!empty($entry['operator_selectable']),'message'=>'The reviewed catalog entry must allow operator selection.'],
        ];
    }

    /** @return array<string,array{enabled:bool,gates:array}> */
    public static function actions(array $entry): array
    {
        $actions = [];
        foreach (self::OPERATIONS as $operation) {
            $gates = self::gates($entry, $operation);
            $actions[$operation] = [
                'enabled'=>!in_array(false, array_column($gates, 'passed'), true),
                'gates'=>$gates,
            ];
        }
        return $actions;
    }

    private static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) { ksort($value, SORT_STRING); }
            foreach ($value as &$item) {
                $item = json_decode(self::canonicalJson($item), true, 128, JSON_THROW_ON_ERROR);
            }
            unset($item);
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
