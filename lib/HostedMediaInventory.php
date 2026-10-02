<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Read-only inventory of media served by media_asset.php.
 *
 * The filesystem proves what is served; catalog manifests alone authorize new
 * background selections. Retained aliases and loose files remain observable
 * for existing references, adoption and retirement, never new assignments.
 */
final class HostedMediaInventory
{
    private const FAMILIES = ['cisco_cnf', 'cisco_xml', 'yealink', 'grandstream'];
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp'];
    private const AUDIO_EXTENSIONS = ['wav', 'mp3', 'ogg'];
    private const MAX_MODEL_DIRECTORIES = 500;
    private const MAX_FILES = 5000;
    private const MAX_FILE_BYTES = 8388608;

    public function __construct(
        private string $root,
        private string $publicBase
    ) {
        $this->root = rtrim($this->root, '/\\');
    }

    /**
     * @param array<string,array<string,mixed>> $owners keyed by family/model/lowercase filename
     * @param array<string,string[]> $usage keyed by lowercase filename
     * @return array{items:array<int,array<string,mixed>>,issues:array<int,array<string,string>>,truncated:bool}
     */
    public function scan(
        ?string $onlyFamily = null,
        ?string $onlyModel = null,
        array $owners = [],
        array $usage = []
    ): array {
        if ($onlyFamily !== null && !in_array($onlyFamily, self::FAMILIES, true)) {
            throw new \InvalidArgumentException('Choose a supported phone family');
        }
        $families = $onlyFamily === null ? self::FAMILIES : [$onlyFamily];
        $items = [];
        $issues = [];
        $directories = 0;
        $truncated = false;
        if (is_link($this->root)) { throw new \RuntimeException('HTTP media root is linked'); }

        foreach ($families as $family) {
            $familyRoot = $this->root . '/' . $family;
            if (is_link($familyRoot)) {
                $issues[] = ['code'=>'linked_library', 'message'=>"Ignored linked HTTP media family $family"];
                continue;
            }
            if (!is_dir($familyRoot)) { continue; }
            $models = $onlyModel === null
                ? $this->modelDirectories($familyRoot)
                : [$this->safeModel($onlyModel)];
            foreach ($models as $model) {
                if (++$directories > self::MAX_MODEL_DIRECTORIES) {
                    $truncated = true;
                    $issues[] = ['code'=>'directory_limit', 'message'=>'HTTP media inventory exceeded its bounded model-library limit'];
                    break 2;
                }
                $directory = $familyRoot . '/' . $model;
                if (is_link($directory)) {
                    $issues[] = ['code'=>'linked_library', 'message'=>"Ignored linked HTTP media library $family/$model"];
                    continue;
                }
                if (!is_dir($directory)) { continue; }
                $names = @scandir($directory);
                if ($names === false) {
                    $issues[] = ['code'=>'unreadable_library', 'message'=>"HTTP media library $family/$model could not be read"];
                    continue;
                }
                foreach ($names as $name) {
                    if (is_link($directory . '/' . $name)) {
                        $issues[] = ['code'=>'linked_asset', 'message'=>"Ignored linked HTTP media asset in $family/$model"];
                        continue;
                    }
                    if ($name === '.' || $name === '..' || str_starts_with($name, '.')
                        || str_ends_with($name, '.ucmp-part') || !is_file($directory . '/' . $name)) {
                        continue;
                    }
                    if (basename($name) !== $name || preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
                        $issues[] = ['code'=>'unsafe_name', 'message'=>"Ignored unsafe HTTP media filename in $family/$model"];
                        continue;
                    }
                    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $kind = in_array($extension, self::IMAGE_EXTENSIONS, true)
                        ? 'background'
                        : (in_array($extension, self::AUDIO_EXTENSIONS, true) ? 'ringtone' : '');
                    if ($kind === '') { continue; }
                    if (count($items) >= self::MAX_FILES) {
                        $truncated = true;
                        $issues[] = ['code'=>'file_limit', 'message'=>'HTTP media inventory exceeded its bounded file limit'];
                        break 3;
                    }
                    $relative = $family . '/' . $model . '/' . $name;
                    $owner = $owners[$family . '/' . $model . '/' . strtolower($name)] ?? null;
                    $items[] = $this->inspect(
                        $directory . '/' . $name,
                        $relative,
                        $family,
                        $model,
                        $name,
                        $kind,
                        is_array($owner) ? $owner : null,
                        (array)($usage[strtolower($name)] ?? [])
                    );
                }
            }
        }
        usort($items, static fn(array $left, array $right): int => strnatcasecmp(
            (string)$left['relative_path'],
            (string)$right['relative_path']
        ));
        return ['items'=>$items, 'issues'=>$issues, 'truncated'=>$truncated];
    }

    /** @return string[] */
    private function modelDirectories(string $familyRoot): array
    {
        $models = [];
        foreach ((array)@scandir($familyRoot) as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) { continue; }
            if (is_dir($familyRoot . '/' . $entry)) { $models[] = $this->safeModel((string)$entry); }
        }
        $models = array_values(array_unique($models));
        natcasesort($models);
        return array_values($models);
    }

    private function safeModel(string $model): string
    {
        $model = preg_replace('/[^a-z0-9_.-]/i', '', $model) ?: 'all';
        if ($model === '.' || $model === '..') { throw new \InvalidArgumentException('Invalid media model library'); }
        return strtolower($model);
    }

    /**
     * @param array<string,mixed>|null $owner
     * @param string[] $usageLabels
     * @return array<string,mixed>
     */
    private function inspect(
        string $path,
        string $relative,
        string $family,
        string $model,
        string $name,
        string $kind,
        ?array $owner,
        array $usageLabels
    ): array {
        $size = (int)@filesize($path);
        $inspection = null;
        $errors = [];
        if ($size < 0 || $size > self::MAX_FILE_BYTES) {
            $errors[] = 'File exceeds the 8 MiB phone-media limit';
        } else {
            try {
                $inspection = AssetFileInspector::inspect($path, $name, $kind);
                $errors = array_values(array_map('strval', (array)($inspection['errors'] ?? [])));
                if ($family === 'cisco_cnf') {
                    MediaUrl::assertLegacyCiscoBmp((string)file_get_contents($path));
                }
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
        }
        $hash = (string)($inspection['content_sha256'] ?? '');
        $managed = $owner !== null;
        $decodedStatus = (string)($inspection['validation_status'] ?? 'invalid');
        $contentVerified = !$errors && $decodedStatus === 'verified';
        $state = $kind === 'background' ? (string)($owner['state'] ?? 'observed') : 'current';
        $expectedHash = (string)($owner['sha256'] ?? '');
        $catalogVerified = $managed && preg_match('/^[a-f0-9]{64}$/D', $expectedHash) && hash_equals($expectedHash, $hash);
        if ($managed && $expectedHash !== '' && !$catalogVerified) {
            $errors[] = 'Published bytes do not match the catalog manifest';
        }
        // Old manifests may omit hashes. Retain their existing references, but
        // never authorize a new choice until publication supplies a verified hash.
        if ($kind === 'background' && $state === 'current' && !$catalogVerified) { $state = 'unavailable'; }
        $status = $errors || $decodedStatus === 'invalid'
            ? 'invalid'
            : ($managed ? 'verified' : 'discovered');
        $profile = $this->profile($family, $model, $kind);
        $vendor = match ($family) {
            'cisco_cnf', 'cisco_xml'=>'Cisco',
            'yealink'=>'Yealink',
            'grandstream'=>'Grandstream',
        };
        $version = $hash !== '' ? substr($hash, 0, 16) : ((int)@filemtime($path) . '-' . $size);
        $usage = array_values(array_map(
            static fn(string $label): array => ['type'=>'assignment', 'label'=>$label],
            array_values(array_unique(array_filter(array_map('strval', $usageLabels))))
        ));
        $usedBy = array_values(array_map(
            static fn(array $entry): string => (string)$entry['label'],
            $usage
        ));
        $manifest = $managed ? [[
            'role'=>'published',
            'relative_path'=>'HTTP media/' . $relative,
            'catalog_id'=>(string)($owner['catalog_id'] ?? ''),
        ]] : [];
        $issues = array_values(array_map(
            static fn(string $message): array => ['code'=>'content_invalid', 'message'=>$message],
            array_values(array_unique($errors))
        ));
        $compatibility = $vendor . ($model === 'all' ? ' shared HTTP library' : ' ' . strtoupper($model));

        return [
            'asset_id'=>'http-' . substr(hash('sha256', $relative), 0, 12),
            'asset_key'=>hash('sha256', "http\0$relative\0$hash"),
            'kind'=>$kind,
            'name'=>$name,
            'filename'=>$name,
            'size'=>$size,
            'size_bytes'=>$size,
            'mtime'=>(int)@filemtime($path),
            'content_sha256'=>$hash,
            'content_type'=>(string)($inspection['content_type'] ?? 'application/octet-stream'),
            'vendor'=>$vendor,
            'family'=>$family,
            'model'=>$model,
            'profile'=>$profile,
            'role'=>$kind === 'background' ? 'full' : 'payload',
            'bundle_key'=>$managed ? 'special:' . strtolower((string)($owner['catalog_id'] ?? '')) : null,
            'bundle_role'=>$managed ? 'published' : null,
            'relative_path'=>'HTTP media/' . $relative,
            'validation_status'=>$status,
            'status'=>$status,
            'signature_status'=>(string)($inspection['signature_status'] ?? 'not_applicable'),
            'provenance'=>$managed ? 'generated' : 'http_observation',
            'state'=>$state,
            'metadata'=>(array)($inspection['metadata'] ?? []) + [
                'delivery'=>'http', 'library'=>$family . '/' . $model,
            ],
            'manifest'=>$manifest,
            'issues'=>$issues,
            'usage'=>$usage,
            'used_by'=>$usedBy,
            'usage_count'=>count($usage),
            'managed'=>$managed,
            'catalog_id'=>(string)($owner['catalog_id'] ?? ''),
            'catalog_name'=>(string)($owner['catalog_name'] ?? ''),
            'catalog_revision'=>(string)($owner['revision'] ?? ''),
            'content_verified'=>$contentVerified,
            'catalog_verified'=>(bool)$catalogVerified,
            'selectable'=>!$errors && $decodedStatus === 'verified' && ($kind !== 'background' || ($managed && $state === 'current')),
            'media_kind'=>$kind === 'ringtone' ? 'audio' : 'image',
            'format'=>strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            'compatibility'=>$compatibility,
            'compatibility_family'=>$family,
            'compatibility_model'=>$model,
            'validation_message'=>$errors ? implode('; ', array_values(array_unique($errors))) : 'File signature and container decoded',
            'url'=>MediaUrl::asset($this->publicBase, $family, $model, $name, $version),
            'preview_url'=>MediaUrl::preview($this->publicBase, $family, $model, $name, $version),
        ];
    }

    private function profile(string $family, string $model, string $kind): string
    {
        if ($kind === 'ringtone') { return $family . ':' . $model . ':audio'; }
        if ($family === 'cisco_cnf') { return 'cisco_cnf:90x56'; }
        $vendor = match ($family) {
            'cisco_xml'=>'Cisco', 'yealink'=>'Yealink', 'grandstream'=>'Grandstream',
            default=>'',
        };
        if ($vendor !== '' && $model !== 'all') {
            $profile = ModelCatalog::hostedMediaProfileKey($vendor, strtoupper($model));
            if ($profile !== null) { return $profile; }
        }
        return $family . ':' . $model . ':http';
    }
}
