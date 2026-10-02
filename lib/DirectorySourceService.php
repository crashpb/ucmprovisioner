<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

use PDO;

/**
 * Validated persistence model for independent AD domain authorities.
 *
 * Credentials are represented only by an opaque, allow-listed secret_ref.
 * The credential object itself belongs outside the module database.
 */
final class DirectorySourceService
{
    private const ATTRIBUTE_FIELDS = [
        'display_name', 'extension', 'label', 'department', 'company', 'mail', 'mobile', 'pager',
        'sam_account_name','user_principal_name','given_name','surname',
    ];
    private const REQUIRED_ATTRIBUTES = ['display_name', 'extension', 'label'];

    public function __construct(
        private PDO $db,
        /** @var string[] */
        private array $siteKeys = []
    ) {
        $this->siteKeys = array_values(array_unique(array_filter(array_map(
            static fn($key): string => trim((string)$key),
            $this->siteKeys
        ))));
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM ucmp_directory_sources ORDER BY domain,source_key'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $runs = $this->db->query(
            'SELECT r.*
               FROM ucmp_directory_sync_runs r
               JOIN (
                    SELECT source_id,MAX(id) id
                      FROM ucmp_directory_sync_runs
                     GROUP BY source_id
               ) latest ON latest.id=r.id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $latest = [];
        foreach ($runs as $run) {
            $sourceId = (int)($run['source_id'] ?? 0);
            if ($sourceId > 0 && !isset($latest[$sourceId])) {
                $latest[$sourceId] = $this->publicRun($run);
            }
        }
        return array_map(
            fn(array $row): array => $this->publicRow($row, $latest[(int)$row['id']] ?? null),
            $rows
        );
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        if ($id < 1) { return null; }
        $stmt = $this->db->prepare('SELECT * FROM ucmp_directory_sources WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        $run = $this->db->prepare(
            'SELECT * FROM ucmp_directory_sync_runs WHERE source_id=? ORDER BY started DESC,id DESC LIMIT 1'
        );
        $run->execute([$id]);
        $latest = $run->fetch(PDO::FETCH_ASSOC);
        return $this->publicRow($row, $latest ? $this->publicRun($latest) : null);
    }

    /**
     * Create or compare-and-swap one domain source.
     *
     * Existing source keys are immutable because they namespace AD object
     * identity. A domain is represented by one source containing multiple
     * failover DC endpoints; creating two authorities for one DNS domain is
     * rejected.
     *
     * @return array<string,mixed>
     */
    public function save(array $input): array
    {
        $id = max(0, (int)($input['id'] ?? 0));
        $existing = $id > 0 ? $this->raw($id) : null;
        if ($id > 0 && !$existing) {
            throw new \InvalidArgumentException('Directory source not found');
        }
        $sourceKey = self::sourceKey((string)($input['source_key'] ?? ($existing['source_key'] ?? '')));
        if ($existing && !hash_equals((string)$existing['source_key'], $sourceKey)) {
            throw new \InvalidArgumentException('A directory source key cannot be changed after creation');
        }
        $domain = self::domain((string)($input['domain'] ?? ($existing['domain'] ?? '')));
        $tlsMode = strtolower(trim((string)($input['tls_mode'] ?? ($existing['tls_mode'] ?? 'ldaps'))));
        if (!in_array($tlsMode, ['ldaps', 'starttls'], true)) {
            throw new \InvalidArgumentException('New directory sources require LDAPS or StartTLS');
        }
        $endpoints = self::endpoints($input['endpoints'] ?? $input['endpoints_json'] ?? ($existing['endpoints_json'] ?? []), $tlsMode);
        $secretRef = self::secretRef(
            (string)($input['secret_ref'] ?? ($existing['secret_ref'] ?? '')),
            $sourceKey
        );
        $baseDn = self::baseDn((string)($input['base_dn'] ?? ($existing['base_dn'] ?? '')));
        $filter = self::ldapFilter((string)($input['filter'] ?? ($existing['filter'] ?? '(&(objectCategory=person)(objectClass=user))')));
        $attributeMap = self::attributeMap(
            $input['attribute_map'] ?? $input['attribute_map_json'] ?? ($existing['attribute_map_json'] ?? [])
        );
        $siteRules = $this->siteRules(
            $input['site_rules'] ?? $input['site_rules_json'] ?? ($existing['site_rules_json'] ?? [])
        );
        $fallbackMinutes = max(0, (int)($input['schedule_minutes'] ?? ($existing['schedule_minutes'] ?? 60)));
        $scheduleInput = $input;
        if (
            !array_key_exists('schedule_json', $scheduleInput)
            && !array_key_exists('schedule_mode', $scheduleInput)
            && array_key_exists('schedule_minutes', $scheduleInput)
        ) {
            // Backward-compatible API callers that send only the old integer
            // field still mean an interval (or zero for manual-only).
            $scheduleInput['schedule_mode'] = $fallbackMinutes === 0 ? 'manual' : 'interval';
        }
        if (!array_key_exists('schedule_json', $scheduleInput)) {
            $scheduleInput['schedule_json'] = (string)($existing['schedule_json'] ?? '');
        }
        $schedule = RecurringSchedule::fromFields(
            $scheduleInput,
            $fallbackMinutes,
            5
        );
        $scheduleMinutes = RecurringSchedule::compatibilityMinutes($schedule);
        $scheduleJson = RecurringSchedule::encode($schedule);
        // A newly described source has no usable credential object until the
        // privileged secret workflow provisions file:<source_key>. Keep it
        // disabled unless the operator explicitly enables it.
        $enabled = self::boolean($input['enabled'] ?? ($existing['enabled'] ?? false));
        $now = time();

        $duplicate = $this->db->prepare(
            'SELECT id FROM ucmp_directory_sources WHERE (source_key=? OR domain=?) AND id<>? LIMIT 1'
        );
        $duplicate->execute([$sourceKey, $domain, $id]);
        if ($duplicate->fetchColumn() !== false) {
            throw new \InvalidArgumentException('That source key or AD DNS domain is already configured');
        }

        $encodedEndpoints = self::json($endpoints);
        $encodedAttributes = self::json($attributeMap);
        $encodedRules = self::json($siteRules);
        if (!$existing) {
            $stmt = $this->db->prepare(
                'INSERT INTO ucmp_directory_sources
                    (source_key,domain,enabled,endpoints_json,tls_mode,secret_ref,base_dn,filter,
                     attribute_map_json,site_rules_json,schedule_minutes,schedule_json,created,updated)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $sourceKey,$domain,$enabled ? 1 : 0,$encodedEndpoints,$tlsMode,$secretRef,$baseDn,$filter,
                $encodedAttributes,$encodedRules,$scheduleMinutes,$scheduleJson,$now,$now,
            ]);
            $id = (int)$this->db->lastInsertId();
        } else {
            $expected = (int)($input['expected_updated'] ?? -1);
            if ($expected < 0 || $expected !== (int)$existing['updated']) {
                throw new \RuntimeException('This directory source changed in another session; reload it before saving');
            }
            $nextUpdated = max($now, $expected + 1);
            $stmt = $this->db->prepare(
                'UPDATE ucmp_directory_sources
                    SET domain=?,enabled=?,endpoints_json=?,tls_mode=?,secret_ref=?,base_dn=?,filter=?,
                        attribute_map_json=?,site_rules_json=?,schedule_minutes=?,schedule_json=?,updated=?
                  WHERE id=? AND updated=?'
            );
            $stmt->execute([
                $domain,$enabled ? 1 : 0,$encodedEndpoints,$tlsMode,$secretRef,$baseDn,$filter,
                $encodedAttributes,$encodedRules,$scheduleMinutes,$scheduleJson,$nextUpdated,$id,$expected,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('This directory source changed in another session; reload it before saving');
            }
        }
        return $this->get($id) ?? throw new \RuntimeException('Saved directory source could not be reloaded');
    }

    /** Enable or disable a source using the same optimistic revision. */
    public function setEnabled(int $id, bool $enabled, int $expectedUpdated): array
    {
        $row = $this->raw($id);
        if (!$row) { throw new \InvalidArgumentException('Directory source not found'); }
        $input = $row;
        $input['id'] = $id;
        $input['enabled'] = $enabled;
        $input['expected_updated'] = $expectedUpdated;
        return $this->save($input);
    }

    private function raw(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_directory_sources WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function publicRow(array $row, ?array $latestRun): array
    {
        $schedule = RecurringSchedule::fromFields([
            'schedule_json'=>(string)($row['schedule_json'] ?? ''),
            'schedule_minutes'=>(int)($row['schedule_minutes'] ?? 0),
        ], max(0, (int)($row['schedule_minutes'] ?? 0)), 5);
        return [
            'id'=>(int)$row['id'],
            'source_key'=>(string)$row['source_key'],
            'domain'=>(string)$row['domain'],
            'enabled'=>(bool)$row['enabled'],
            'endpoints'=>self::decodedArray($row['endpoints_json'] ?? null),
            'tls_mode'=>(string)$row['tls_mode'],
            'secret_ref'=>(string)($row['secret_ref'] ?? ''),
            'secret_configured'=>trim((string)($row['secret_ref'] ?? '')) !== '',
            'base_dn'=>(string)($row['base_dn'] ?? ''),
            'filter'=>(string)($row['filter'] ?? ''),
            'attribute_map'=>self::decodedArray($row['attribute_map_json'] ?? null),
            'site_rules'=>self::decodedArray($row['site_rules_json'] ?? null),
            'schedule_minutes'=>(int)$row['schedule_minutes'],
            'schedule'=>$schedule,
            'schedule_json'=>RecurringSchedule::encode($schedule),
            'schedule_description'=>RecurringSchedule::describe($schedule),
            'last_accepted_revision'=>(string)($row['last_accepted_revision'] ?? ''),
            'last_attempt'=>(int)($row['last_attempt'] ?? 0),
            'last_success'=>(int)($row['last_success'] ?? 0),
            'last_error'=>mb_substr(trim((string)($row['last_error'] ?? '')), 0, 1000, 'UTF-8'),
            'created'=>(int)($row['created'] ?? 0),
            'updated'=>(int)($row['updated'] ?? 0),
            'latest_run'=>$latestRun,
        ];
    }

    private function publicRun(array $run): array
    {
        return [
            'id'=>(int)$run['id'],
            'started'=>(int)$run['started'],
            'finished'=>(int)($run['finished'] ?? 0),
            'status'=>(string)$run['status'],
            'candidate_revision'=>(string)($run['candidate_revision'] ?? ''),
            'accepted_revision'=>(string)($run['accepted_revision'] ?? ''),
            'object_count'=>(int)($run['object_count'] ?? 0),
            'added'=>(int)($run['added'] ?? 0),
            'changed'=>(int)($run['changed'] ?? 0),
            'deleted'=>(int)($run['deleted'] ?? 0),
            'error'=>mb_substr(trim((string)($run['error'] ?? '')), 0, 1000, 'UTF-8'),
        ];
    }

    private function siteRules($value): array
    {
        $rules = DirectorySiteMapper::normalizeRules($value, $this->siteKeys);
        foreach ($rules as &$rule) { unset($rule['_order']); }
        unset($rule);
        return $rules;
    }

    private static function sourceKey(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value)) {
            throw new \InvalidArgumentException('Source key must use 1-64 lowercase letters, digits, underscores or hyphens');
        }
        return $value;
    }

    private static function domain(string $value): string
    {
        $value = strtolower(rtrim(trim($value), '.'));
        if (
            $value === ''
            || strlen($value) > 253
            || filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || strpos($value, '.') === false
        ) {
            throw new \InvalidArgumentException('Enter the AD DNS domain, for example example.com');
        }
        return $value;
    }

    private static function endpoints($value, string $tlsMode): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = preg_split('/\R+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        if (!is_array($value) || !$value || count($value) > 16) {
            throw new \InvalidArgumentException('Configure between one and sixteen domain-controller endpoints');
        }
        $out = [];
        foreach (array_values($value) as $position => $item) {
            if (is_string($item)) {
                $item = trim($item);
                $host = $item;
                $port = $tlsMode === 'ldaps' ? 636 : 389;
                if (preg_match('/^\[([0-9A-Fa-f:]+)\](?::([0-9]+))?$/D', $item, $match)) {
                    $host = $match[1];
                    $port = isset($match[2]) && $match[2] !== '' ? (int)$match[2] : $port;
                } elseif (substr_count($item, ':') === 1 && preg_match('/^([^:]+):([0-9]+)$/D', $item, $match)) {
                    $host = $match[1];
                    $port = (int)$match[2];
                }
                $priority = ($position + 1) * 10;
            } elseif (is_array($item)) {
                $host = trim((string)($item['host'] ?? ''));
                $port = (int)($item['port'] ?? ($tlsMode === 'ldaps' ? 636 : 389));
                $priority = (int)($item['priority'] ?? (($position + 1) * 10));
            } else {
                throw new \InvalidArgumentException('Each domain-controller endpoint must be a host and port');
            }
            $host = strtolower(rtrim($host, '.'));
            $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false
                || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
            if (!$validHost || $port < 1 || $port > 65535 || $priority < 0 || $priority > 10000) {
                throw new \InvalidArgumentException('A domain-controller endpoint has an invalid host, port or priority');
            }
            $key = $host . ':' . $port;
            if (isset($out[$key])) {
                throw new \InvalidArgumentException('Duplicate domain-controller endpoint');
            }
            $out[$key] = ['host'=>$host, 'port'=>$port, 'priority'=>$priority];
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int =>
            ($a['priority'] <=> $b['priority']) ?: strcmp($a['host'] . ':' . $a['port'], $b['host'] . ':' . $b['port'])
        );
        return $out;
    }

    private static function secretRef(string $value, string $sourceKey): string
    {
        $value = trim($value);
        if ($value === 'legacy-setting:ad_bind_password' && $sourceKey === 'legacy-default') {
            return $value;
        }
        if ($value !== 'file:' . $sourceKey) {
            throw new \InvalidArgumentException('The credential reference must be the managed secret object file:' . $sourceKey);
        }
        return $value;
    }

    private static function baseDn(string $value): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $value)
            || !preg_match('/^[A-Za-z][A-Za-z0-9-]*=.+(?:,[A-Za-z][A-Za-z0-9-]*=.+)*$/D', $value)
        ) {
            throw new \InvalidArgumentException('Enter a valid LDAP base DN');
        }
        return $value;
    }

    private static function ldapFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException('LDAP filter is empty or invalid');
        }
        $depth = 0;
        $escaped = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($char === '\\') { $escaped = true; continue; }
            if ($char === '(') { $depth++; }
            if ($char === ')' && --$depth < 0) {
                throw new \InvalidArgumentException('LDAP filter parentheses are unbalanced');
            }
        }
        if ($escaped || $depth !== 0 || $value[0] !== '(' || $value[strlen($value) - 1] !== ')') {
            throw new \InvalidArgumentException('LDAP filter parentheses are unbalanced');
        }
        return $value;
    }

    private static function attributeMap($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value)) { throw new \InvalidArgumentException('Attribute map must be a JSON object'); }
        $out = [];
        foreach (self::ATTRIBUTE_FIELDS as $field) {
            $attribute = trim((string)($value[$field] ?? ''));
            if (in_array($field, self::REQUIRED_ATTRIBUTES, true) && $attribute === '') {
                throw new \InvalidArgumentException("Attribute map requires $field");
            }
            if ($attribute !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $attribute)) {
                throw new \InvalidArgumentException("Invalid LDAP attribute for $field");
            }
            $out[$field] = $attribute;
        }
        $extra = $value['extra_attributes'] ?? [];
        if (is_string($extra)) {
            $extra = preg_split('/[\s,;]+/', trim($extra), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($extra) || count($extra) > 50) {
            throw new \InvalidArgumentException('Additional LDAP attributes must be a list of at most fifty names');
        }
        $cleanExtra = [];
        foreach ($extra as $attribute) {
            $attribute = trim((string)$attribute);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/D', $attribute)) {
                throw new \InvalidArgumentException('Invalid additional LDAP attribute');
            }
            $cleanExtra[strtolower($attribute)] = strtolower($attribute);
        }
        ksort($cleanExtra, SORT_STRING);
        $out['extra_attributes'] = array_values($cleanExtra);
        return $out;
    }

    private static function boolean($value): bool
    {
        if (is_bool($value)) { return $value; }
        if (in_array($value, [1, '1', 'true', 'on', 'yes'], true)) { return true; }
        if (in_array($value, [0, '0', 'false', 'off', 'no', ''], true)) { return false; }
        throw new \InvalidArgumentException('Enabled must be a boolean value');
    }

    private static function decodedArray($value): array
    {
        if (is_array($value)) { return $value; }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
