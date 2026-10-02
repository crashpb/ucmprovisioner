<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Resolves an AD object to a stable site key using explicit, ordered rules.
 *
 * PBX reachability and extension catalogs are deliberately absent from this
 * class: they are delivery/runtime evidence, not directory identity authority.
 */
final class DirectorySiteMapper
{
    private const TYPES = ['attribute', 'ou', 'group', 'number_range'];

    /**
     * @param array<int|string,mixed>|string $value
     * @param array<int,string> $knownSiteKeys
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeRules($value, array $knownSiteKeys): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('AD site rules must be valid JSON', 0, $e);
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new \InvalidArgumentException('AD site rules must be a JSON array');
        }
        if (count($value) > 500) {
            throw new \InvalidArgumentException('AD site rules cannot exceed 500 rows');
        }

        $known = array_fill_keys(array_map('strval', $knownSiteKeys), true);
        $rules = [];
        foreach ($value as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every AD site rule must be an object');
            }
            if (array_key_exists('enabled', $row) && !$row['enabled']) {
                continue;
            }
            $type = strtolower(trim((string)($row['type'] ?? '')));
            $siteKey = trim((string)($row['site_key'] ?? ''));
            $priority = filter_var(
                $row['priority'] ?? (($index + 1) * 10),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 10000]]
            );
            if (!in_array($type, self::TYPES, true)) {
                throw new \InvalidArgumentException("Unsupported AD site rule type at row " . ($index + 1));
            }
            if ($siteKey === '' || !isset($known[$siteKey])) {
                throw new \InvalidArgumentException("AD site rule " . ($index + 1) . " targets an unknown site_key");
            }
            if ($priority === false) {
                throw new \InvalidArgumentException("AD site rule " . ($index + 1) . " has an invalid priority");
            }

            $rule = [
                'id' => trim((string)($row['id'] ?? '')) ?: 'rule-' . ($index + 1),
                'type' => $type,
                'site_key' => $siteKey,
                'priority' => (int)$priority,
                '_order' => (int)$index,
            ];
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $rule['id'])) {
                throw new \InvalidArgumentException("AD site rule " . ($index + 1) . " has an invalid id");
            }

            if ($type === 'attribute') {
                $attribute = strtolower(trim((string)($row['attribute'] ?? '')));
                $match = trim((string)($row['value'] ?? ''));
                if (!preg_match('/^[a-z][a-z0-9-]*$/D', $attribute) || $match === '') {
                    throw new \InvalidArgumentException("Attribute rule " . ($index + 1) . " needs a valid attribute and value");
                }
                $rule['attribute'] = $attribute;
                $rule['value'] = $match;
            } elseif ($type === 'ou') {
                $dn = trim((string)($row['dn'] ?? $row['value'] ?? ''));
                if ($dn === '' || strlen($dn) > 2048 || stripos($dn, 'OU=') === false) {
                    throw new \InvalidArgumentException("OU rule " . ($index + 1) . " needs an OU distinguished name");
                }
                $rule['dn'] = $dn;
            } elseif ($type === 'group') {
                $match = trim((string)($row['group'] ?? $row['value'] ?? ''));
                if ($match === '' || strlen($match) > 2048) {
                    throw new \InvalidArgumentException("Group rule " . ($index + 1) . " needs a group name or distinguished name");
                }
                $rule['group'] = $match;
            } else {
                $start = self::number((string)($row['start'] ?? ''));
                $end = self::number((string)($row['end'] ?? ''));
                if (self::compareNumber($start, $end) > 0) {
                    throw new \InvalidArgumentException("Number range rule " . ($index + 1) . " starts after it ends");
                }
                $rule['start'] = $start;
                $rule['end'] = $end;
            }
            $rules[] = $rule;
        }

        usort($rules, static fn(array $left, array $right): int =>
            ($left['priority'] <=> $right['priority']) ?: ($left['_order'] <=> $right['_order'])
        );
        return $rules;
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<int,array<string,mixed>> $rules
     * @return array{status:string,site_key:?string,priority:?int,matched_rules:array<int,string>,message:string}
     */
    public static function resolve(array $entry, array $rules): array
    {
        $matches = [];
        foreach ($rules as $rule) {
            if (!self::matches($entry, $rule)) {
                continue;
            }
            $priority = (int)$rule['priority'];
            if ($matches && $priority > (int)$matches[0]['priority']) {
                break;
            }
            $matches[] = $rule;
        }
        if (!$matches) {
            return [
                'status' => 'unmapped',
                'site_key' => null,
                'priority' => null,
                'matched_rules' => [],
                'message' => 'No AD site mapping rule matched',
            ];
        }

        $sites = array_values(array_unique(array_column($matches, 'site_key')));
        $ids = array_values(array_map(static fn(array $rule): string => (string)$rule['id'], $matches));
        if (count($sites) > 1) {
            return [
                'status' => 'conflict',
                'site_key' => null,
                'priority' => (int)$matches[0]['priority'],
                'matched_rules' => $ids,
                'message' => 'Equal-priority AD rules mapped this object to different sites',
            ];
        }
        return [
            'status' => 'mapped',
            'site_key' => (string)$sites[0],
            'priority' => (int)$matches[0]['priority'],
            'matched_rules' => $ids,
            'message' => 'Mapped by AD rule ' . implode(', ', $ids),
        ];
    }

    private static function matches(array $entry, array $rule): bool
    {
        return match ($rule['type']) {
            'attribute' => self::contains(
                self::attributeValues($entry, (string)$rule['attribute']),
                (string)$rule['value']
            ),
            'ou' => self::dnIsWithin(
                (string)($entry['dn'] ?? $entry['source_dn'] ?? ''),
                (string)$rule['dn']
            ),
            'group' => self::groupMatches($entry, (string)$rule['group']),
            'number_range' => self::numberMatches(
                (string)($entry['extension'] ?? ''),
                (string)$rule['start'],
                (string)$rule['end']
            ),
            default => false,
        };
    }

    /** @return array<int,string> */
    private static function attributeValues(array $entry, string $attribute): array
    {
        $attributes = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : [];
        $value = $attributes[$attribute] ?? $attributes[strtolower($attribute)] ?? $entry[$attribute] ?? null;
        if ($value === null) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            $values
        ), static fn(string $item): bool => $item !== ''));
    }

    /** @param array<int,string> $values */
    private static function contains(array $values, string $needle): bool
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $needle) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function dnIsWithin(string $objectDn, string $subtreeDn): bool
    {
        $object = strtolower(trim($objectDn));
        $subtree = strtolower(trim($subtreeDn));
        return $object !== '' && ($object === $subtree || str_ends_with($object, ',' . $subtree));
    }

    private static function groupMatches(array $entry, string $expected): bool
    {
        $groups = [];
        foreach (['groups', 'memberof'] as $attribute) {
            $groups = array_merge($groups, self::attributeValues($entry, $attribute));
        }
        foreach ($groups as $group) {
            if (strcasecmp($group, $expected) === 0) {
                return true;
            }
            if (stripos($group, 'CN=') === 0 && preg_match('/^CN=((?:\\\\.|[^,])+)/i', $group, $match)) {
                $name = str_replace(['\\,','\\+','\\"','\\\\'], [',','+','"','\\'], $match[1]);
                if (strcasecmp($name, $expected) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function numberMatches(string $value, string $start, string $end): bool
    {
        try {
            $number = self::number($value);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
        return self::compareNumber($number, $start) >= 0
            && self::compareNumber($number, $end) <= 0;
    }

    private static function number(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^\d{1,64}$/D', $value)) {
            throw new \InvalidArgumentException('AD number range values must contain only 1 to 64 digits');
        }
        return ltrim($value, '0') ?: '0';
    }

    private static function compareNumber(string $left, string $right): int
    {
        return (strlen($left) <=> strlen($right)) ?: strcmp($left, $right);
    }
}
