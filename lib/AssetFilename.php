<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Compact public names; complete identities remain in the asset manifests. */
final class AssetFilename
{
    public const MAX_LENGTH = 20;

    public static function fromHash(string $type, string $sha256, string $extension, string $suffix = ''): string
    {
        if (!preg_match('/^[a-z]$/D', $type)
            || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !preg_match('/^[a-z0-9]{1,4}$/D', $extension)
            || !in_array($suffix, ['', '-tn'], true)) {
            throw new \InvalidArgumentException('Invalid compact asset filename identity');
        }
        $name = $type . substr($sha256, 0, 12) . $suffix . '.' . $extension;
        if (strlen($name) > self::MAX_LENGTH) { throw new \InvalidArgumentException('Compact filename exceeds its length bound'); }
        return $name;
    }

    public static function content(string $type, string $content, string $extension): string
    {
        return self::fromHash($type, hash('sha256', $content), $extension);
    }

    public static function isCompact(string $name): bool
    {
        return strlen($name) <= self::MAX_LENGTH && preg_match('/^[a-z][a-f0-9]{12}(?:-tn)?\.[a-z0-9]{1,4}$/D', $name) === 1;
    }

    public static function isImmutable(string $name): bool
    {
        return self::isCompact($name) && str_contains('dsfajcworh', $name[0]);
    }

    public static function assertSameContent(string $name, string $expectedHash, string $existingHash): void
    {
        if (self::isImmutable($name) && !hash_equals($expectedHash, $existingHash)) {
            throw new \RuntimeException("Compact asset filename conflict: $name; existing content was preserved");
        }
    }

    public static function isTimezone(string $name): bool
    {
        return preg_match('/^(?:[aj][a-f0-9]{12}\.jar|c[a-f0-9]{12}\.csv|ucmp-tz-(?:java|j9|csv)-[a-f0-9]{64}\.(?:jar|csv))$/iD', $name) === 1;
    }

    public static function timezonePayload(string $name): ?string
    {
        foreach (['a'=>['java','tzupdater.jar'], 'j'=>['j9','j9-tzdata.jar'], 'c'=>['csv','TzDataCSV.csv']] as $prefix=>[$legacy,$payload]) {
            $extension = pathinfo($payload, PATHINFO_EXTENSION);
            if (preg_match('/^(?:' . $prefix . '[a-f0-9]{12}|ucmp-tz-' . $legacy . '-[a-f0-9]{64})\.' . $extension . '$/iD', $name)) {
                return $payload;
            }
        }
        return null;
    }

    public static function isCompanion(string $name): bool
    {
        return preg_match('/^(?:[dsf][a-f0-9]{12}|dialplan-tpl(?:[a-f0-9]{16}|[a-f0-9]{64})|tplstack-[a-f0-9]{16}-(?:softkeys|featurepolicy))\.xml$/iD', $name) === 1;
    }
}
