<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

/** Stable cross-site identity for a row in each PBX's local model catalog. */
final class PhoneModelIdentity
{
    public static function key(string $vendor, string $model): string
    {
        $vendor = trim($vendor);
        $model = trim($model);
        if ($vendor === '' || $model === '' || strlen($vendor) > 64 || strlen($model) > 64
            || str_contains($vendor, '|') || str_contains($model, '|')) {
            throw new \InvalidArgumentException('The selected phone model identity is invalid');
        }
        return $vendor . '|' . $model;
    }

    /** @return array{0:string,1:string} */
    public static function parse(string $key): array
    {
        $key = trim($key);
        if (strlen($key) > 129 || substr_count($key, '|') !== 1) {
            throw new \InvalidArgumentException('The selected phone model identity is invalid');
        }
        [$vendor, $model] = array_map('trim', explode('|', $key, 2));
        self::key($vendor, $model);
        return [$vendor, $model];
    }

    /** @return array<string,mixed>|null */
    public static function resolve(PDO $db, string $key): ?array
    {
        [$vendor, $model] = self::parse($key);
        $stmt = $db->prepare(
            'SELECT * FROM ucmp_models WHERE UPPER(vendor)=UPPER(?) AND UPPER(model)=UPPER(?) LIMIT 1'
        );
        $stmt->execute([$vendor, $model]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
