<?php

namespace FreePBX\modules\Ucmprovisioner;

/** Validate the additional SIP accounts stored on one physical endpoint/base. */
final class PhoneAccounts
{
    public static function normalize($raw, ?array $model, array $knownExtensions, string $primaryExtension = ''): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $rows = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Additional SIP accounts must be a JSON array');
        }
        if (!$rows) {
            return [];
        }
        if ($primaryExtension === '') {
            throw new \InvalidArgumentException('Assign the primary extension before adding more SIP accounts');
        }
        if (!$model) {
            throw new \InvalidArgumentException('Choose a supported phone or DECT base model before adding SIP accounts');
        }

        $limit = max(0, (int)($model['sip_accounts'] ?? 0));
        if ($limit < 2) {
            throw new \InvalidArgumentException('The selected model supports only one SIP account');
        }
        if (count($rows) > $limit - 1) {
            throw new \InvalidArgumentException("The selected model supports $limit SIP accounts including the primary account");
        }

        // The caller passes an extension=>true lookup. Normalize its keys once so numeric
        // extension identifiers are compared consistently without guessing array shape.
        $known = array_fill_keys(array_map('strval', array_keys($knownExtensions)), true);
        $usedAccounts = [1 => true];
        $usedAppearances = [1 => true];
        $usedExtensions = $primaryExtension !== '' ? [$primaryExtension => true] : [];
        $normalized = [];
        $next = 2;
        $role = strtolower((string)($model['role'] ?? 'desk'));
        $isDectBase = str_contains($role, 'base');
        $requiresAppearance = str_starts_with((string)($model['family'] ?? ''), 'cisco_');
        $appearanceLimit = max(1, (int)($model['programmable_keys'] ?? ($model['line_keys'] ?? 1)));

        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every additional SIP account must be an object');
            }
            $account = (int)($row['account'] ?? ($row['account_index'] ?? ($row['lineIndex'] ?? ($row['button'] ?? 0))));
            if ($account < 2) {
                while (isset($usedAccounts[$next]) && $next <= $limit) { $next++; }
                $account = $next;
            }
            if ($account > $limit) {
                throw new \InvalidArgumentException("SIP account $account exceeds this model's 1-$limit capacity");
            }
            if (isset($usedAccounts[$account])) {
                throw new \InvalidArgumentException("SIP account $account is assigned more than once");
            }

            $extension = trim((string)($row['extension'] ?? ''));
            if ($extension === '' || !isset($known[$extension])) {
                throw new \InvalidArgumentException($extension === ''
                    ? "Choose an extension for SIP account $account"
                    : "Extension $extension does not exist on this PBX");
            }
            if (isset($usedExtensions[$extension])) {
                throw new \InvalidArgumentException("Extension $extension is assigned more than once on this endpoint");
            }

            $label = trim((string)($row['label'] ?? ''));
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($labelLength > 32) {
                throw new \InvalidArgumentException("The label for SIP account $account exceeds 32 characters");
            }
            $appearance = $isDectBase ? 0 : max(0, (int)($row['button'] ?? 0));
            if ($requiresAppearance && $appearance < 2) {
                if ($account <= $appearanceLimit) { $appearance = $account; }
                else { throw new \InvalidArgumentException("Cisco SIP account $account needs a physical line key, but this model has only $appearanceLimit keys"); }
            }
            if ($appearance > $appearanceLimit) {
                // Rows created before separate key-slot storage used the account
                // number as the physical key. An impossible implicit appearance
                // becomes registration-only; an explicit invalid slot still fails.
                $legacyAccount = !array_key_exists('account',$row) && !array_key_exists('account_index',$row) && !array_key_exists('lineIndex',$row);
                if (!$requiresAppearance && $legacyAccount && $appearance === $account) { $appearance = 0; }
                else { throw new \InvalidArgumentException("Line appearance $appearance exceeds this model's 1-$appearanceLimit base-key capacity"); }
            }
            if ($appearance === 1 || ($appearance > 1 && isset($usedAppearances[$appearance]))) {
                throw new \InvalidArgumentException("Line appearance $appearance is already occupied on this endpoint");
            }
            $normalized[] = [
                'account' => $account,
                'button' => $appearance,
                'extension' => $extension,
                'label' => $label,
            ];
            $usedAccounts[$account] = true;
            if ($appearance > 0) { $usedAppearances[$appearance] = true; }
            $usedExtensions[$extension] = true;
            // An explicit high slot must not hide free lower slots from later implicit rows.
            $next = 2;
        }

        usort($normalized, static fn(array $a, array $b): int => $a['account'] <=> $b['account']);
        return $normalized;
    }

    /** Validate DECT handset inventory and account routing stored in per-phone overrides. */
    public static function normalizeDectOverrides($raw, ?array $model, array $handsetCatalog, array $activeAccounts): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $overrides = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (!is_array($overrides)) {
            throw new \InvalidArgumentException('Per-phone overrides must be a JSON object');
        }

        $hasDectData = array_key_exists('dect_handsets', $overrides);
        foreach (array_keys($overrides) as $key) {
            if (preg_match('/^(?:yl|gs)_dect_account_\d+_(?:handsets?|profile)$/', (string)$key)) {
                $hasDectData = true;
                break;
            }
        }
        if (!$hasDectData) {
            return $overrides;
        }
        if (!$model || !str_contains(strtolower((string)($model['role'] ?? '')), 'base')) {
            throw new \InvalidArgumentException('DECT handset inventory and routing belong to a DECT base, not a desk phone or standalone handset');
        }

        $maximum = (int)($model['handsets_max'] ?? 0);
        if ($maximum < 1) {
            throw new \InvalidArgumentException('The selected DECT base has no verified handset capacity');
        }
        $vendor = strtoupper(trim((string)($model['vendor'] ?? '')));
        $prefix = $vendor === 'YEALINK' ? 'yl' : ($vendor === 'GRANDSTREAM' ? 'gs' : '');
        if ($prefix === '') {
            throw new \InvalidArgumentException('The selected vendor has no supported DECT routing profile');
        }
        $active = array_fill_keys(array_map('intval', $activeAccounts), true);

        $inventory = $overrides['dect_handsets'] ?? [];
        if (!is_array($inventory)) {
            throw new \InvalidArgumentException('DECT handset inventory must be a JSON array');
        }
        $normalizedInventory = [];
        $usedSlots = [];
        foreach ($inventory as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Every DECT handset inventory entry must be an object');
            }
            $slot = (int)($row['slot'] ?? 0);
            if ($slot < 1 || $slot > $maximum || isset($usedSlots[$slot])) {
                throw new \InvalidArgumentException("DECT handset slot $slot is outside this base's 1-$maximum capacity or is duplicated");
            }
            $handsetModel = strtoupper(trim((string)($row['model'] ?? '')));
            if ($handsetModel !== '' && empty($handsetCatalog[$vendor . '|' . $handsetModel])) {
                throw new \InvalidArgumentException("$handsetModel is not a supported $vendor handset model");
            }
            $label = trim((string)($row['label'] ?? ''));
            $identifier = trim((string)($row['identifier'] ?? ''));
            $labelLength = function_exists('mb_strlen') ? mb_strlen($label) : strlen($label);
            if ($labelLength > 32 || strlen($identifier) > 64 || preg_match('/[\x00-\x1F\x7F]/', $identifier)) {
                throw new \InvalidArgumentException("DECT handset slot $slot has an invalid label or identifier");
            }
            if ($handsetModel !== '' || $label !== '' || $identifier !== '') {
                $normalizedInventory[] = ['slot'=>$slot, 'model'=>$handsetModel, 'label'=>$label, 'identifier'=>$identifier];
            }
            $usedSlots[$slot] = true;
        }
        usort($normalizedInventory, static fn(array $a, array $b): int => $a['slot'] <=> $b['slot']);
        if ($normalizedInventory) { $overrides['dect_handsets'] = $normalizedInventory; }
        else { unset($overrides['dect_handsets']); }

        foreach (array_keys($overrides) as $key) {
            if (!preg_match('/^(yl|gs)_dect_account_(\d+)_(handsets?|profile)$/', (string)$key, $match)) {
                continue;
            }
            if ($match[1] !== $prefix) {
                throw new \InvalidArgumentException('DECT routing keys do not match the selected base vendor');
            }
            $account = (int)$match[2];
            if (!isset($active[$account])) {
                unset($overrides[$key]);
                continue;
            }
            if ($match[3] === 'profile') {
                $profile = (int)$overrides[$key];
                if ($prefix !== 'gs' || $profile < 1 || $profile > 4) {
                    throw new \InvalidArgumentException("DECT account $account has an invalid Grandstream profile");
                }
                $overrides[$key] = (string)$profile;
                continue;
            }

            $selected = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)$overrides[$key])))));
            foreach ($selected as $slot) {
                if ($slot < 1 || $slot > $maximum) {
                    throw new \InvalidArgumentException("DECT account $account references handset $slot outside this base's 1-$maximum capacity");
                }
            }
            sort($selected, SORT_NUMERIC);
            $pluralKey = $prefix . '_dect_account_' . $account . '_handsets';
            unset($overrides[$key]);
            if ($selected) { $overrides[$pluralKey] = implode(',', $selected); }
            else { unset($overrides[$pluralKey]); }
        }

        return $overrides;
    }
}
