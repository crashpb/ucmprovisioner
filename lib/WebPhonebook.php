<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Read-only, field-limited view over accepted phonebook snapshots. */
final class WebPhonebook
{
    private const MANAGEMENT_STATUSES = ['active','paused'];
    public const FIELDS = [
        'name'=>'Name', 'extension'=>'Extension', 'department'=>'Department',
        'site'=>'Site', 'email'=>'Email', 'mobile'=>'Mobile',
        'employee'=>'Employee no.', 'company'=>'Company', 'label'=>'Short label', 'alternate'=>'Alternate name',
    ];
    public const DEFAULT_FIELDS = ['name','extension','department','site','email','company'];
    private const SOURCE_FIELDS = [
        'name'=>'display_name', 'extension'=>'extension', 'department'=>'department',
        'site'=>'site_name', 'email'=>'mail', 'mobile'=>'mobile', 'employee'=>'pager',
        'company'=>'company', 'label'=>'canonical_label',
    ];
    public const MAX_CONTACTS = 10000;
    private const MAX_CACHE_BYTES = 67108864;

    public function __construct(private string $root = '/var/lib/asterisk/ucmprovisioner') {}

    public static function defaults(): array
    {
        return ['enabled'=>false,'networks'=>[],'books'=>[],'fields'=>self::DEFAULT_FIELDS,
            'preferred_url'=>'', 'access_mode'=>'direct', 'trusted_proxies'=>[],
            'client_header'=>'HTTP_X_REAL_IP', 'export_enabled'=>true, 'policy_label'=>'Internal / VPN directory',
            'help_url'=>'', 'alternate_field'=>'label', 'site_labels'=>[], 'favicon'=>''];
    }

    public function policy(): array
    {
        return $this->readStoredDocument()['policy'];
    }

    public function policyRevision(): string
    {
        return $this->documentRevision($this->readStoredDocument());
    }

    /** One atomic document read binds form values, ownership and save revision. */
    public function policySnapshot(): array
    {
        $document = $this->readStoredDocument();
        return ['policy'=>$document['policy'], 'management'=>self::publicManagement($document['management']),
            'revision'=>$this->documentRevision($document)];
    }

    /** Sanitized controller-owned policy management state; never returns raw document fields. */
    public function management(): array
    {
        return self::publicManagement($this->readStoredDocument()['management']);
    }

    public static function normalizePolicy(array $input): array
    {
        $enabled = in_array($input['enabled'] ?? false, [true,1,'1'], true);
        $networks = $input['networks'] ?? [];
        if (is_string($networks)) { $networks = preg_split('/[\s,]+/', trim($networks), -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($networks) || count($networks) > 64) { throw new \InvalidArgumentException('Use at most 64 allowed networks.'); }
        $normalized = [];
        foreach ($networks as $network) {
            if (!is_string($network) || !preg_match('#^([0-9a-fA-F:.]+)/(\d{1,3})$#D', trim($network), $match)) {
                throw new \InvalidArgumentException('Allowed networks must use IPv4 or IPv6 CIDR notation.');
            }
            $packed = @inet_pton($match[1]);
            $bits = (int)$match[2];
            if ($packed === false || $bits < 1 || $bits > strlen($packed) * 8) {
                throw new \InvalidArgumentException('Invalid allowed network. A whole-Internet /0 grant is not supported.');
            }
            if (!self::privateNetwork($match[1], $bits)) { throw new \InvalidArgumentException('Only internal or VPN client networks may access this directory.'); }
            $normalized[] = inet_ntop($packed) . '/' . $bits;
        }
        $books = $input['books'] ?? [];
        $fields = $input['fields'] ?? self::DEFAULT_FIELDS;
        if (!is_array($books) || count($books) > 100 || !is_array($fields)) {
            throw new \InvalidArgumentException('Choose valid phonebooks and visible fields.');
        }
        foreach ($books as $book) {
            if (!is_string($book) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $book)) {
                throw new \InvalidArgumentException('Invalid phonebook selection.');
            }
        }
        foreach ($fields as $field) {
            if (!is_string($field) || !isset(self::FIELDS[$field])) { throw new \InvalidArgumentException('Unknown visible field.'); }
        }
        $fields = array_values(array_intersect(array_keys(self::FIELDS), array_unique(['name','extension',...$fields])));
        if ($enabled && (!$normalized || !$books)) {
            throw new \InvalidArgumentException('Select at least one phonebook and allowed network before enabling web access.');
        }
        $mode = (string)($input['access_mode'] ?? 'direct');
        if (!in_array($mode, ['direct','trusted_proxy'], true)) { throw new \InvalidArgumentException('Invalid directory access mode.'); }
        $proxies = $input['trusted_proxies'] ?? [];
        if (is_string($proxies)) { $proxies = preg_split('/[\s,]+/', trim($proxies), -1, PREG_SPLIT_NO_EMPTY); }
        if (!is_array($proxies) || count($proxies) > 16) { throw new \InvalidArgumentException('Use at most 16 exact proxy addresses.'); }
        $trusted = [];
        foreach ($proxies as $proxy) {
            $ip = is_string($proxy) ? self::ip(trim($proxy)) : null;
            if ($ip === null) { throw new \InvalidArgumentException('Trusted proxies must be exact IP addresses, never networks.'); }
            $trusted[] = $ip;
        }
        if ($enabled && $mode === 'trusted_proxy' && !$trusted) { throw new \InvalidArgumentException('Select the exact trusted proxy before enabling proxy access.'); }
        $header = (string)($input['client_header'] ?? 'HTTP_X_REAL_IP');
        if (!in_array($header, ['HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR'], true)) { throw new \InvalidArgumentException('Choose a supported real-client header.'); }
        $alternate = (string)($input['alternate_field'] ?? 'label');
        if (!in_array($alternate, ['label','name'], true)) { throw new \InvalidArgumentException('Alternate name must use an approved projected identity field.'); }
        $labels = $input['site_labels'] ?? [];
        if (is_string($labels)) {
            $lines = preg_split('/\R/u', trim($labels), -1, PREG_SPLIT_NO_EMPTY);
            $labels = [];
            foreach ($lines ?: [] as $line) {
                $pair = explode('=', $line, 2);
                if (count($pair) !== 2) { throw new \InvalidArgumentException('Use one original site name = display label per line.'); }
                $key = trim($pair[0]);
                if (isset($labels[$key])) { throw new \InvalidArgumentException('Each original site name may have only one display label.'); }
                $labels[$key] = trim($pair[1]);
            }
        }
        if (!is_array($labels) || count($labels) > 128) { throw new \InvalidArgumentException('Use at most 128 site display labels.'); }
        foreach ($labels as $key => $label) {
            if (!is_string($label) || trim((string)$key) === '' || trim($label) === '' || strlen((string)$key)>256 || strlen($label)>256 || preg_match('/[\x00-\x1f]/', (string)$key.$label)) {
                throw new \InvalidArgumentException('Site names and display labels must be nonempty text of at most 256 bytes.');
            }
        }
        ksort($labels);
        $favicon = $input['favicon'] ?? '';
        if (!is_string($favicon) || strlen($favicon)>90000) { throw new \InvalidArgumentException('Invalid directory favicon.'); }
        if ($favicon !== '') {
            $bytes = base64_decode($favicon, true);
            $size = $bytes === false ? false : @getimagesizefromstring($bytes);
            if (!$size || $size[2] !== IMAGETYPE_PNG || $size[0]>256 || $size[1]>256 || strlen($bytes)>65536) {
                throw new \InvalidArgumentException('Directory favicon must be a PNG up to 256 pixels and 64 KiB.');
            }
        }
        return ['enabled'=>$enabled,'networks'=>array_values(array_unique($normalized)),
            'books'=>array_values(array_unique($books)),'fields'=>$fields,
            'preferred_url'=>self::safeUrl((string)($input['preferred_url'] ?? self::defaults()['preferred_url'])),
            'access_mode'=>$mode, 'trusted_proxies'=>array_values(array_unique($trusted)), 'client_header'=>$header,
            'export_enabled'=>in_array($input['export_enabled'] ?? true, [true,1,'1'], true),
            'policy_label'=>self::text($input['policy_label'] ?? 'Internal / VPN directory'),
            'help_url'=>self::safeUrl((string)($input['help_url'] ?? ''), true), 'alternate_field'=>$alternate,
            'site_labels'=>$labels, 'favicon'=>$favicon];
    }

    /** Decode and re-encode uploaded pixels; never publish the original upload. */
    public static function faviconFromImage(string $bytes): string
    {
        if (strlen($bytes)>1048576) { throw new \InvalidArgumentException('Upload a PNG or JPEG no larger than 1 MiB.'); }
        $size = @getimagesizefromstring($bytes);
        if (!$size || !in_array($size[2], [IMAGETYPE_PNG,IMAGETYPE_JPEG], true) || $size[0]>2048 || $size[1]>2048) {
            throw new \InvalidArgumentException('Use a PNG or JPEG image up to 2048 pixels per side.');
        }
        if (!function_exists('imagecreatefromstring')) { throw new \RuntimeException('Favicon upload requires the PHP GD image extension.'); }
        $image = @imagecreatefromstring($bytes);
        if (!$image) { throw new \InvalidArgumentException('The favicon image could not be decoded.'); }
        $icon = imagecreatetruecolor(64,64);
        imagealphablending($icon,false); imagesavealpha($icon,true);
        imagefill($icon,0,0,imagecolorallocatealpha($icon,0,0,0,127));
        $scale = min(64/$size[0],64/$size[1]); $width=max(1,(int)round($size[0]*$scale)); $height=max(1,(int)round($size[1]*$scale));
        imagecopyresampled($icon,$image,(int)((64-$width)/2),(int)((64-$height)/2),0,0,$width,$height,$size[0],$size[1]);
        ob_start(); imagepng($icon); $png=ob_get_clean(); imagedestroy($image); imagedestroy($icon);
        return base64_encode($png);
    }

    /** Called only from the authenticated module page mutation dispatcher. */
    public function savePolicy(array $input, string $expectedRevision): void
    {
        $this->withPolicyLock(function () use ($input, $expectedRevision): void {
            $document = $this->readStoredDocument();
            if (!hash_equals($this->documentRevision($document), $expectedRevision)) {
                throw new \RuntimeException('Web-directory settings changed. Reload this page before saving.');
            }
            $management = $document['management'];
            if (!empty($management['hard_lock'])) {
                throw new \RuntimeException('Web-directory settings are hard-locked by configuration synchronization.');
            }
            // An ordinary settings save keeps the uploaded icon; explicit removal sends an empty value.
            $policy = self::normalizePolicy($input + ['favicon'=>$document['policy']['favicon']]);
            if ($policy['enabled']) {
                $available = array_column($this->availableBooks(), 'key');
                if (array_diff($policy['books'], $available)) { throw new \InvalidArgumentException('A selected phonebook is not currently published on this PBX.'); }
            }
            if ($management) { $management['status'] = 'paused'; }
            $this->writeStoredDocument($policy, $management);
        });
    }

    /** Apply one controller-owned policy without allowing another owner to take it over. */
    public function applyManagedPolicy(array $input, array $context, bool $force = false): array
    {
        $policy = self::normalizePolicy($input);
        $owner = self::normalizeManagementContext($context);
        return $this->withPolicyLock(function () use ($policy, $owner, $force): array {
            $document = $this->readStoredDocument();
            $existing = $document['management'];
            $sameOwner = $existing
                && hash_equals((string)$existing['job_key'], $owner['job_key'])
                && hash_equals((string)$existing['source_site_key'], $owner['source_site_key']);
            if ($existing && !$sameOwner) {
                return $this->managedResult(false, 'owner_conflict', false, [], $document, 'Web-directory policy is owned by another configuration sync job.');
            }
            $missing = [];
            if ($policy['enabled']) {
                $available = array_fill_keys(array_column($this->availableBooks(), 'key'), true);
                foreach ($policy['books'] as $book) { if (!isset($available[$book])) { $missing[] = $book; } }
                if ($missing) {
                    return $this->managedResult(true, 'missing_dependency', false, $missing, $document, 'Selected web phonebooks are not currently published on this PBX.');
                }
            }
            if ($sameOwner && !empty($existing['hard_lock']) && empty($owner['hard_lock'])) {
                return $this->managedResult(false, 'owner_conflict', false, [], $document, 'A hard-locked web-directory owner must be explicitly released before downgrade.');
            }
            if ($sameOwner && (string)$existing['status'] === 'paused' && empty($owner['hard_lock']) && !$force) {
                return $this->managedResult(true, 'paused', false, [], $document, 'Web-directory synchronization is paused after a local edit.');
            }
            $management = $owner;
            $management['status'] = 'active';
            $changed = $document['policy'] !== $policy || $existing !== $management;
            if ($changed) { $this->writeStoredDocument($policy, $management); }
            $document = ['policy'=>$policy,'management'=>$management];
            return $this->managedResult(true, 'active', $changed, [], $document, 'Web-directory policy synchronized.');
        });
    }

    /** Mark a matching soft-managed pause active without changing effective policy. */
    public function resumeManagedPolicy(string $jobKey): bool
    {
        try { $jobKey = self::normalizeJobKey($jobKey); } catch (\Throwable) { return false; }
        return $this->withPolicyLock(function () use ($jobKey): bool {
            $document = $this->readStoredDocument();
            $management = $document['management'];
            if (!$management || !hash_equals((string)$management['job_key'], $jobKey) || !empty($management['hard_lock'])) { return false; }
            if ((string)$management['status'] !== 'paused') { return (string)$management['status'] === 'active'; }
            $management['status'] = 'active';
            $this->writeStoredDocument($document['policy'], $management);
            return true;
        });
    }

    /** Release matching ownership metadata while preserving the effective policy. */
    public function releaseManagedPolicy(string $jobKey): array
    {
        try { $jobKey = self::normalizeJobKey($jobKey); } catch (\Throwable $e) {
            return ['ok'=>false,'status'=>'owner_conflict','changed'=>false,'management'=>$this->management(),'message'=>$e->getMessage()];
        }
        return $this->withPolicyLock(function () use ($jobKey): array {
            $document = $this->readStoredDocument();
            if (!$document['management']) {
                return ['ok'=>true,'status'=>'released','changed'=>false,'management'=>self::publicManagement([]),'message'=>'Web-directory policy has no management owner.'];
            }
            if (!hash_equals((string)$document['management']['job_key'], $jobKey)) {
                return ['ok'=>true,'status'=>'owner_conflict','changed'=>false,'management'=>self::publicManagement($document['management']),'message'=>'Web-directory policy is owned by another configuration sync job.'];
            }
            $this->writeStoredDocument($document['policy'], []);
            return ['ok'=>true,'status'=>'released','changed'=>true,'management'=>self::publicManagement([]),'message'=>'Web-directory policy ownership released; effective policy preserved.'];
        });
    }

    /** @return array{policy:array<string,mixed>,management:array<string,mixed>} */
    private function readStoredDocument(): array
    {
        $this->assertPrivateStorePaths();
        $path = $this->root . '/web-phonebook.json';
        if (!file_exists($path)) { return ['policy'=>self::defaults(),'management'=>[]]; }
        $raw = $this->readJson($path, 524288);
        return ['policy'=>self::normalizePolicy($raw), 'management'=>self::normalizeStoredManagement($raw['management'] ?? [])];
    }

    private function documentRevision(array $document): string
    {
        return hash('sha256', json_encode([
            'policy'=>(array)($document['policy'] ?? self::defaults()),
            'management'=>(array)($document['management'] ?? []),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** Install-only permission upgrade; no policy content or reader secrets are changed. */
    public function installPolicyPermissions(): void
    {
        $this->withPolicyLock(function (): void {
            foreach (['web-phonebook.json', 'web-phonebook.lock'] as $name) {
                $path = $this->root . '/' . $name;
                if (is_file($path)) { $this->setPolicyFilePermissions($path); }
            }
        });
    }

    private function withPolicyLock(callable $action): mixed
    {
        $this->ensurePolicyRoot();
        $lockPath = $this->root . '/web-phonebook.lock';
        // The empty lock is never rewritten or replaced. Linux flock works on a
        // read-only handle, so neither service account needs group-write on it.
        $lock = @fopen($lockPath, 'rb');
        if (!$lock && !file_exists($lockPath)) {
            $lock = @fopen($lockPath, 'x+b');
            if ($lock) {
                try { $this->setPolicyFilePermissions($lockPath); }
                catch (\Throwable $e) { fclose($lock); throw $e; }
            } else {
                // Another writer may have created the one stable lock first.
                $lock = @fopen($lockPath, 'rb');
            }
        }
        if (!$lock) {
            throw new \RuntimeException('Cannot read the web-directory settings lock; complete the module install permission upgrade.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another web-directory settings save is in progress.');
        }
        try { return $action(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function ensurePolicyRoot(): void
    {
        $this->assertPrivateStorePaths();
        if (is_link($this->root) || (file_exists($this->root) && !is_dir($this->root))) {
            throw new \RuntimeException('The private web-directory policy store must be a real directory.');
        }
        if (!is_dir($this->root) && !@mkdir($this->root, 0770, true) && !is_dir($this->root)) {
            throw new \RuntimeException('Cannot open the private web-directory policy store.');
        }
    }

    private function assertPrivateStorePaths(): void
    {
        for ($directory = $this->root; dirname($directory) !== $directory; $directory = dirname($directory)) {
            if (is_link($directory)) { throw new \RuntimeException('The private web-directory policy store must not use symlinks.'); }
        }
        foreach (['web-phonebook.json', 'web-phonebook.lock'] as $name) {
            $path = $this->root . '/' . $name;
            if (is_link($path) || (file_exists($path) && !is_file($path))) {
                throw new \RuntimeException('The private web-directory policy and lock must be regular files.');
            }
        }
    }

    private function setPolicyFilePermissions(string $path): void
    {
        if ((PHP_OS_FAMILY !== 'Windows' && !@chgrp($path, 'asterisk')) || !@chmod($path, 0640)) {
            throw new \RuntimeException('Cannot apply web-directory service-group read permissions.');
        }
    }

    private function writeStoredDocument(array $policy, array $management): void
    {
        $this->assertPrivateStorePaths();
        $document = $policy;
        if ($management) { $document['management'] = $management; }
        $temporary = tempnam($this->root, '.web-phonebook-');
        if ($temporary === false) { throw new \RuntimeException('Cannot stage web-directory policy.'); }
        try {
            $this->setPolicyFilePermissions($temporary);
            $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !@rename($temporary, $this->root . '/web-phonebook.json')) {
                throw new \RuntimeException('Cannot atomically save web-directory policy.');
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    /** @return array<string,mixed> */
    private static function normalizeStoredManagement(mixed $input): array
    {
        if ($input === null || $input === []) { return []; }
        if (!is_array($input)) { throw new \RuntimeException('Web-directory management metadata is invalid.'); }
        $jobKey = trim((string)($input['job_key'] ?? ''));
        $sourceSiteKey = trim((string)($input['source_site_key'] ?? ''));
        $sourceSiteName = self::boundedText((string)($input['source_site_name'] ?? ''), 80);
        $sourceRevision = self::boundedText((string)($input['source_revision'] ?? ''), 64);
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, self::MANAGEMENT_STATUSES, true)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $jobKey)
            || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $sourceSiteKey)
            || $sourceRevision === '') { throw new \RuntimeException('Web-directory management metadata is invalid.'); }
        return [
            'job_key'=>$jobKey,
            'source_site_key'=>$sourceSiteKey,
            'source_site_name'=>$sourceSiteName !== '' ? $sourceSiteName : $sourceSiteKey,
            'hard_lock'=>in_array($input['hard_lock'] ?? false, [true,1,'1'], true),
            'status'=>$status,
            'source_revision'=>$sourceRevision,
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeManagementContext(array $input): array
    {
        $jobKey = trim((string)($input['job_key'] ?? ''));
        $sourceSiteKey = trim((string)($input['source_site_key'] ?? ''));
        $sourceSiteName = self::boundedText((string)($input['source_site_name'] ?? ''), 80);
        $sourceRevision = self::boundedText((string)($input['source_revision'] ?? ''), 64);
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $jobKey)) { throw new \InvalidArgumentException('Web-directory management job key is invalid.'); }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $sourceSiteKey)) { throw new \InvalidArgumentException('Web-directory management source site key is invalid.'); }
        if ($sourceRevision === '') { throw new \InvalidArgumentException('Web-directory management source revision is required.'); }
        return [
            'job_key'=>$jobKey,
            'source_site_key'=>$sourceSiteKey,
            'source_site_name'=>$sourceSiteName !== '' ? $sourceSiteName : $sourceSiteKey,
            'hard_lock'=>in_array($input['hard_lock'] ?? false, [true,1,'1'], true),
            'status'=>'active',
            'source_revision'=>$sourceRevision,
        ];
    }

    /** @return array<string,mixed> */
    private static function publicManagement(array $management): array
    {
        if (!$management) { return []; }
        return ['job_key'=>(string)$management['job_key'],
            'source_site_key'=>(string)$management['source_site_key'],
            'source_site_name'=>(string)$management['source_site_name'],
            'hard_lock'=>!empty($management['hard_lock']), 'status'=>(string)$management['status'],
            'source_revision'=>(string)$management['source_revision']];
    }

    /** @return array<string,mixed> */
    private function managedResult(bool $ok, string $status, bool $changed, array $missing, array $document, string $message): array
    {
        return ['ok'=>$ok,'status'=>$status,'changed'=>$changed,'missing'=>array_values($missing),
            'management'=>self::publicManagement((array)($document['management'] ?? [])),
            'policy_revision'=>$this->documentRevision($document),'message'=>$message];
    }

    private static function normalizeJobKey(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $value)) { throw new \InvalidArgumentException('Web-directory management job key is invalid.'); }
        return $value;
    }

    private static function boundedText(string $value, int $limit): string
    {
        return mb_substr(self::text($value), 0, $limit, 'UTF-8');
    }

    public static function peerAllowed(array $policy, array $server): bool
    {
        return self::accessDecision($policy, $server)['allowed'];
    }

    /** Safe request metadata for authenticated diagnostics; never includes contacts. */
    public static function accessDecision(array $policy, array $server): array
    {
        $peer = self::ip($server['REMOTE_ADDR'] ?? null);
        $decision = ['allowed'=>false, 'peer'=>$peer, 'client'=>null, 'reason'=>'disabled'];
        if (empty($policy['enabled'])) { return $decision; }
        if ($peer === null) { return array_replace($decision, ['reason'=>'invalid_peer']); }
        $client = $peer;
        if (($policy['access_mode'] ?? 'direct') === 'trusted_proxy') {
            if (!in_array($peer, (array)($policy['trusted_proxies'] ?? []), true)) {
                return array_replace($decision, ['reason'=>'untrusted_proxy']);
            }
            $forwarded = [];
            foreach (['HTTP_FORWARDED','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP'] as $header) {
                if (isset($server[$header]) && $server[$header] !== '') { $forwarded[$header] = $server[$header]; }
            }
            $header = (string)($policy['client_header'] ?? 'HTTP_X_REAL_IP');
            if (!in_array($header, ['HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR'], true)
                || array_keys($forwarded) !== [$header]) {
                return array_replace($decision, ['reason'=>'missing_or_conflicting_forwarding']);
            }
            $client = self::ip($forwarded[$header]);
            if ($client === null) { return array_replace($decision, ['reason'=>'invalid_or_chained_client']); }
        }
        $decision['client'] = $client;
        $packed = inet_pton($client);
        if (!self::privateNetwork($client, strlen($packed) * 8)) { return array_replace($decision, ['reason'=>'public_client']); }
        foreach ((array)($policy['networks'] ?? []) as $network) {
            if (!is_string($network) || !str_contains($network, '/')) { continue; }
            [$ip,$prefix] = explode('/', $network, 2);
            $base = @inet_pton($ip); $bits = (int)$prefix;
            if ($base === false || strlen($base) !== strlen($packed) || $bits < 1 || $bits > strlen($base)*8) { continue; }
            $bytes = intdiv($bits, 8); $remainder = $bits % 8;
            if (substr($base, 0, $bytes) !== substr($packed, 0, $bytes)) { continue; }
            if (!$remainder || ((ord($base[$bytes]) ^ ord($packed[$bytes])) & (0xff << (8-$remainder))) === 0) {
                return array_replace($decision, ['allowed'=>true, 'reason'=>'allowed_client_network']);
            }
        }
        return array_replace($decision, ['reason'=>'client_network_not_allowed']);
    }

    private static function ip(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) !== $value || ($packed = @inet_pton($value)) === false) { return null; }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") { $packed = substr($packed, 12); }
        return inet_ntop($packed);
    }

    private static function privateNetwork(string $address, int $bits): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) { return false; }
        // Documentation prefixes support offline fixtures and cannot grant public reachability.
        foreach (['10.0.0.0/8','172.16.0.0/12','192.168.0.0/16','127.0.0.0/8','169.254.0.0/16','100.64.0.0/10',
            '192.0.2.0/24','198.51.100.0/24','203.0.113.0/24','::1/128','fc00::/7','fe80::/10','2001:db8::/32'] as $network) {
            [$base,$prefix] = explode('/', $network); $prefix = (int)$prefix; $base = inet_pton($base);
            if (strlen($packed) !== strlen($base) || $bits < $prefix) { continue; }
            $bytes = intdiv($prefix, 8); $rest = $prefix % 8;
            if (substr($packed, 0, $bytes) === substr($base, 0, $bytes)
                && (!$rest || ((ord($packed[$bytes]) ^ ord($base[$bytes])) & (0xff << (8-$rest))) === 0)) { return true; }
        }
        return false;
    }

    private static function safeUrl(string $url, bool $help = false): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        if (preg_match('/[\x00-\x20\x7f]/', $url)) { throw new \InvalidArgumentException('Invalid directory URL.'); }
        if ($help && str_starts_with($url, 'mailto:') && filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL)) { return $url; }
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { throw new \InvalidArgumentException('Use a valid HTTPS URL without credentials or a fragment.'); }
        return $url;
    }

    private function readJson(string $path, int $limit): array
    {
        $file = @fopen($path, 'rb');
        if (!$file) { throw new \RuntimeException('The published web directory is unavailable.'); }
        try { $json = stream_get_contents($file, $limit + 1); } finally { fclose($file); }
        if (!is_string($json) || strlen($json) > $limit) { throw new \RuntimeException('The directory exceeds the supported size.'); }
        $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new \RuntimeException('The published web directory is invalid.'); }
        return $payload;
    }

    public function availableBooks(): array
    {
        if (!is_file($this->root . '/directory/directory.json')) { return []; }
        return $this->bookCatalog($this->readJson($this->root . '/directory/directory.json', self::MAX_CACHE_BYTES));
    }

    private function bookCatalog(array $cache): array
    {
        $result = [];
        foreach ((array)($cache['phonebooks'] ?? []) as $book) {
            if (!is_array($book) || !is_string($book['book_key'] ?? null)
                || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $book['book_key'])) { continue; }
            $result[$book['book_key']] = ['key'=>$book['book_key'],'name'=>self::text($book['name'] ?? $book['book_key'])];
        }
        return array_values($result);
    }

    /** Authenticated administrator review of the actual compiled membership. */
    public function reviewPublication(array $candidate, array $draft = []): array
    {
        $cache = is_file($this->root . '/directory/directory.json') ? $this->readJson($this->root . '/directory/directory.json', self::MAX_CACHE_BYTES) : [];
        $before = [];
        foreach ((array)($cache['phonebooks'] ?? []) as $book) {
            if (($book['book_key'] ?? '') === ($candidate['book_key'] ?? '')) { $before = $book; break; }
        }
        $index = static function(array $book): array {
            $rows=[];
            foreach ((array)($book['contacts'] ?? $book['entries'] ?? []) as $row) {
                if (!is_array($row)) { continue; }
                $key=(string)($row['source_key'] ?? hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)));
                $rows[$key]=$row;
            }
            ksort($rows); return $rows;
        };
        $old=$index($before); $new=$index($candidate);
        $summarize=static fn(array $rows): array => array_values(array_map(static fn(array $row): array => [
            'name'=>self::text($row['display_name'] ?? ''), 'extension'=>self::text($row['extension'] ?? ''),
            'site'=>self::text($row['site_name'] ?? ''),
        ],$rows));
        $added=array_diff_key($new,$old); $removed=array_diff_key($old,$new); $changed=[];
        foreach (array_intersect_key($new,$old) as $key=>$row) { if ($row !== $old[$key]) { $changed[$key]=$row; } }
        $policy=$this->policy();
        $publishSites=$draft['publish_sites'] ?? [];
        if (is_string($publishSites)) { $publishSites=json_decode($publishSites,true,16,JSON_THROW_ON_ERROR); }
        $publishSites=is_array($publishSites)?array_values(array_unique(array_map('strval',$publishSites))):[];
        sort($publishSites);
        $delta=count($added)+count($removed);
        return ['revision'=>hash('sha256',json_encode([$before,$candidate,$publishSites,$policy],JSON_THROW_ON_ERROR)),
            'before_count'=>count($old),'after_count'=>count($new), 'additions'=>$summarize($added),
            'removals'=>$summarize($removed), 'changed'=>$summarize($changed),
            'sites'=>array_values(array_unique(array_filter(array_column($summarize($new),'site')))),
            'publish_sites'=>$publishSites, 'exposed_fields'=>$policy['fields'],
            'reasons'=>['Compiled audience rules, explicit exclusions, manual entries and accepted identity catalog determine membership.'],
            'exceptional'=>count($old)>0 ? $delta>=10 && $delta/count($old)>=0.25 : count($new)>=100];
    }

    public static function validatePublicationReview(array $review, string $revision, bool $approved): void
    {
        if (!hash_equals((string)$review['revision'],$revision)) { throw new \RuntimeException('Directory review is stale. Preview the current membership before saving.'); }
        if (!empty($review['exceptional']) && !$approved) { throw new \RuntimeException('This exceptional audience change requires explicit approval of the current review.'); }
    }

    public function model(array $policy, string $requestedBook = ''): array
    {
        $cache = $this->readJson($this->root . '/directory/directory.json', self::MAX_CACHE_BYTES);
        $books = array_values(array_filter($this->bookCatalog($cache), static fn($book) => in_array($book['key'], $policy['books'], true)));
        $key = $requestedBook !== '' ? $requestedBook : (string)($books[0]['key'] ?? '');
        if ($key === '' || !in_array($key, array_column($books, 'key'), true)) { throw new \OutOfBoundsException('This web phonebook is not available.'); }
        $selected = null;
        foreach ((array)$cache['phonebooks'] as $book) { if (is_array($book) && ($book['book_key'] ?? '') === $key) { $selected = $book; break; } }
        if (!$selected) { throw new \OutOfBoundsException('This web phonebook is not available.'); }
        // Use the compiled membership directly; never fall back to the cache-wide entries.
        $rows = $selected['contacts'] ?? $selected['entries'] ?? [];
        if (!is_array($rows) || count($rows) > self::MAX_CONTACTS) { throw new \RuntimeException('The phonebook exceeds the supported contact limit.'); }
        $fields = array_intersect_key(self::FIELDS, array_fill_keys($policy['fields'], true));
        $contacts = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) { continue; }
            $source = (string)($row['source_key'] ?? $index);
            $contact = ['id'=>hash('sha256', $key . "\0" . $source)];
            foreach ($fields as $field => $_) {
                $sourceField = $field === 'alternate' ? self::SOURCE_FIELDS[$policy['alternate_field'] ?? 'label'] : self::SOURCE_FIELDS[$field];
                $value = $row[$sourceField] ?? ($sourceField === 'canonical_label' ? ($row['label'] ?? '') : '');
                if ($field === 'site') { $value = $policy['site_labels'][(string)$value] ?? $value; }
                $contact[$field] = self::text($value);
            }
            if ($contact['name'] === '' || $contact['extension'] === '') { continue; }
            $contacts[] = $contact;
        }
        $model = ['book'=>$key,'title'=>self::text($selected['name'] ?? 'Company directory'),
            'books'=>$books,'fields'=>$fields,'contacts'=>$contacts,'generated'=>max(0,(int)($cache['generated'] ?? 0)),
            'export_enabled'=>$policy['export_enabled'] ?? true, 'policy_label'=>$policy['policy_label'] ?? '',
            'help_url'=>$policy['help_url'] ?? '', 'preferred_url'=>$policy['preferred_url'] ?? '',
            'accepted_at'=>0,'source_revision'=>''];
        $freshness = $cache['source_freshness'] ?? [];
        if (is_array($freshness) && $freshness) {
            $times = array_map(static fn($source) => is_array($source) ? max(0,(int)($source['last_success'] ?? 0)) : 0, $freshness);
            $model['accepted_at'] = min($times);
            $model['source_revision'] = hash('sha256', json_encode($freshness, JSON_THROW_ON_ERROR));
        }
        // Scheduled publication refreshes these timestamps even when the visible
        // directory is unchanged. Only output content/policy invalidate a selection.
        $content = $model;
        unset($content['generated'], $content['accepted_at'], $content['source_revision']);
        $model['revision'] = hash('sha256', json_encode([$policy,$content], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $model;
    }

    public static function text($value): string
    {
        if (!is_scalar($value)) { return ''; }
        return mb_substr((string)preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', trim((string)$value)), 0, 255, 'UTF-8');
    }

    /** Select server-owned values only. Browser filters submit IDs, never cell contents. */
    public static function exportSelection(array $model, array $request): array
    {
        if (isset($model['export_enabled']) && !$model['export_enabled']) { throw new \InvalidArgumentException('Excel export is disabled by policy.'); }
        if (!is_string($request['revision'] ?? null) || !hash_equals($model['revision'], $request['revision'])) {
            throw new \UnexpectedValueException('The phonebook changed. Refresh it and select the output again.');
        }
        $columns = $request['columns'] ?? [];
        if (!is_array($columns) || !$columns || count($columns) > count(self::FIELDS)) { throw new \InvalidArgumentException('Select valid export columns.'); }
        foreach ($columns as $column) {
            if (!is_string($column) || !isset($model['fields'][$column])) { throw new \InvalidArgumentException('A requested field is not available.'); }
        }
        if (!in_array('name', $columns, true)) { throw new \InvalidArgumentException('Name must be included in the export.'); }
        if (!in_array($request['scope'] ?? '', ['all','filtered','selected'], true)) { throw new \InvalidArgumentException('Invalid export scope.'); }
        $ids = $request['ids'] ?? [];
        if (!is_array($ids) || count($ids) > self::MAX_CONTACTS) { throw new \InvalidArgumentException('Too many selected contacts.'); }
        $indexed = array_column($model['contacts'], null, 'id');
        $rows = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($indexed[$id])) { throw new \InvalidArgumentException('A requested contact is not in this phonebook.'); }
            $rows[$id] = $indexed[$id];
        }
        if (($request['scope'] ?? '') === 'all' && count($rows) !== count($indexed)) {
            throw new \InvalidArgumentException('Whole-phonebook export must include every current contact.');
        }
        if (!$rows) { throw new \InvalidArgumentException('No contacts selected for export.'); }
        return ['columns'=>array_values(array_unique($columns)),'rows'=>array_values($rows)];
    }
}
