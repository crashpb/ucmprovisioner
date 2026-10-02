<?php
namespace FreePBX\modules\Ucmprovisioner;

/** Site-local phone-service catalog and vendor renderers. No PBX mutation lives here. */
final class PhoneServiceService
{
    private const TYPES = ['directory', 'info', 'diallist', 'text'];
    private const FORMATS = ['cisco', 'cisco_legacy', 'yealink', 'grandstream'];
    private const MAX_SERVICES = 100;
    private const MAX_SETTINGS_BYTES = 65536;
    private const MAX_TARGET_MODELS = 100;
    private const FAMILY_FORMATS = [
        'cisco_xml'=>'cisco',
        'cisco_cnf'=>'cisco_legacy',
        'yealink'=>'yealink',
        'grandstream'=>'grandstream',
    ];

    public function __construct(private object $ucm) {}

    public function handle(array $request): array
    {
        $op = (string)($request['op'] ?? 'list');
        return match ($op) {
            'list' => $this->catalog(),
            'save' => $this->save($request),
            'delete' => $this->delete((int)($request['id'] ?? 0)),
            'reorder' => $this->reorder($request['ids'] ?? []),
            'preview' => $this->preview($request),
            default => throw new \InvalidArgumentException('Unsupported phone-service operation'),
        };
    }

    public function ensureBuiltins(): void
    {
        $siteId = $this->ucm->localSiteId();
        $insert = $this->ucm->getDb()->prepare(
            'INSERT INTO ucmp_phone_services (site_id,service_key,name,service_type,enabled,locked,menu_order,settings_json,updated)
             SELECT ?,?,?,?,?,?,?,?,? WHERE NOT EXISTS
             (SELECT 1 FROM ucmp_phone_services WHERE site_id=? AND service_key=?)'
        );
        $repair = $this->ucm->getDb()->prepare(
            'UPDATE ucmp_phone_services
                SET service_type=?,locked=1,updated=?
              WHERE site_id=? AND service_key=? AND (service_type<>? OR locked<>1)'
        );
        foreach ([
            ['directory','Company Directory','directory',1,1,10,[]],
            ['phone-info','Phone Information','info',1,1,90,[]],
        ] as $row) {
            [$key,$name,$type,$enabled,$locked,$order,$settings] = $row;
            $insert->execute([$siteId,$key,$name,$type,$enabled,$locked,$order,json_encode($settings),time(),$siteId,$key]);
            $repair->execute([$type,time(),$siteId,$key,$type]);
        }
    }

    public function catalog(bool $ensure=true, bool $includePreview=true): array
    {
        if ($ensure) { $this->ensureBuiltins(); }
        $siteId = $this->ucm->localSiteId();
        $stmt = $this->ucm->getDb()->prepare(
            'SELECT * FROM ucmp_phone_services WHERE site_id=? ORDER BY menu_order,name,id LIMIT ' . (self::MAX_SERVICES + 1)
        );
        $stmt->execute([$siteId]);
        $services = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            if (count($services) >= self::MAX_SERVICES) {
                throw new \RuntimeException('Phone-service catalog exceeds the supported 100-service limit');
            }
            $row['settings'] = json_decode((string)($row['settings_json'] ?? ''), true) ?: [];
            $row['settings']['formats'] = $this->serviceFormats($row['settings'], false);
            $row['settings']['target_models'] = $this->serviceModels($row['settings'], false);
            unset($row['settings_json']);
            $services[] = $row;
        }
        $result = [
            'ok'=>true,
            'services'=>$services,
            'urls'=>$this->exampleUrls(),
            'capabilities'=>$this->capabilities(),
            'models'=>$includePreview ? $this->modelOptions() : [],
        ];
        if ($includePreview) { $result['preview_cache'] = $this->previewCache($services); }
        return $result;
    }

    private function save(array $request): array
    {
        $this->ensureBuiltins();
        $db = $this->ucm->getDb();
        $siteId = $this->ucm->localSiteId();
        $id = (int)($request['id'] ?? 0);
        $existing = null;
        if ($id > 0) {
            $find = $db->prepare('SELECT * FROM ucmp_phone_services WHERE id=? AND site_id=?');
            $find->execute([$id,$siteId]);
            $existing = $find->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$existing) { throw new \InvalidArgumentException('Unknown service on this PBX'); }
        }
        $name = $this->text($request['name'] ?? '', 48);
        if ($name === '') { throw new \InvalidArgumentException('Service name is required'); }
        $type = (string)($request['service_type'] ?? 'diallist');
        if ($existing && !empty($existing['locked'])) { $type = (string)$existing['service_type']; }
        if (!in_array($type, self::TYPES, true)) { throw new \InvalidArgumentException('Unsupported service type'); }
        if ((!$existing || empty($existing['locked'])) && in_array($type, ['directory','info'], true)) {
            throw new \InvalidArgumentException(
                'Company Directory and Phone/site Information are protected built-ins; edit their existing rows'
            );
        }
        $key = $existing ? (string)$existing['service_key'] : strtolower(trim((string)($request['service_key'] ?? '')));
        if ($key === '') { $key = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'); }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/D', $key)) { throw new \InvalidArgumentException('Service key must use lowercase letters, numbers and hyphens'); }
        if ($key === 'root') { throw new \InvalidArgumentException('The service key "root" is reserved for the catalog'); }
        $settings = $request['settings'] ?? [];
        if (is_string($settings)) {
            if (strlen($settings) > self::MAX_SETTINGS_BYTES) { throw new \InvalidArgumentException('Service settings payload is too large'); }
            try { $settings = json_decode($settings, true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \InvalidArgumentException('Service settings must be valid JSON'); }
        }
        if (!is_array($settings)) { throw new \InvalidArgumentException('Service settings must be valid JSON'); }
        $enabled = !empty($request['enabled']);
        $settings = $this->validateSettings($type, $settings, $enabled);
        $row = [
            'name'=>$name,
            'service_type'=>$type,
            'enabled'=>$enabled ? 1 : 0,
            'menu_order'=>max(0,min(999,(int)($request['menu_order'] ?? 50))),
            'settings_json'=>json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated'=>time(),
        ];
        if ($existing) {
            $set = implode(',', array_map(static fn($k)=>"`$k`=:$k", array_keys($row)));
            $row['where_id'] = $id;
            $row['where_site_id'] = $siteId;
            $db->prepare("UPDATE ucmp_phone_services SET $set WHERE id=:where_id AND site_id=:where_site_id")->execute($row);
        } else {
            $count = $db->prepare('SELECT COUNT(*) FROM ucmp_phone_services WHERE site_id=?');
            $count->execute([$siteId]);
            if ((int)$count->fetchColumn() >= self::MAX_SERVICES) {
                throw new \InvalidArgumentException('This PBX already has the maximum 100 phone services');
            }
            $row += ['site_id'=>$siteId,'service_key'=>$key,'locked'=>0];
            $cols = implode(',', array_map(static fn($k)=>"`$k`", array_keys($row)));
            $vals = implode(',', array_map(static fn($k)=>":$k", array_keys($row)));
            try { $db->prepare("INSERT INTO ucmp_phone_services ($cols) VALUES ($vals)")->execute($row); }
            catch (\PDOException $e) { throw new \InvalidArgumentException('That service key already exists on this PBX'); }
            $id = (int)$db->lastInsertId();
        }
        return ['ok'=>true,'id'=>$id,'message'=>'Phone service saved on this PBX. Rebuild affected phones when its service URL was not already provisioned.'] + $this->catalog();
    }

    private function delete(int $id): array
    {
        if ($id <= 0) { throw new \InvalidArgumentException('Choose a valid phone service'); }
        $siteId = $this->ucm->localSiteId();
        $stmt = $this->ucm->getDb()->prepare('DELETE FROM ucmp_phone_services WHERE id=? AND site_id=? AND locked=0');
        $stmt->execute([$id,$siteId]);
        if ($stmt->rowCount() !== 1) { throw new \InvalidArgumentException('Built-in or unknown services cannot be deleted'); }
        return ['ok'=>true,'message'=>'Phone service deleted from this PBX.'] + $this->catalog();
    }

    private function reorder($value): array
    {
        if (is_string($value)) {
            if (strlen($value) > 2048) { throw new \InvalidArgumentException('Service order payload is too large'); }
            try { $value = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new \InvalidArgumentException('Service order must be valid JSON'); }
        }
        if (!is_array($value) || !array_is_list($value) || !$value || count($value) > self::MAX_SERVICES) {
            throw new \InvalidArgumentException('Service order must be a complete ordered list');
        }
        $ids = [];
        foreach ($value as $id) {
            if (!(is_int($id) || (is_string($id) && ctype_digit($id)))) {
                throw new \InvalidArgumentException('Service order contains an invalid identifier');
            }
            $id = (int)$id;
            if ($id <= 0 || in_array($id, $ids, true)) {
                throw new \InvalidArgumentException('Service order contains an invalid or duplicate identifier');
            }
            $ids[] = $id;
        }

        $db = $this->ucm->getDb();
        $siteId = $this->ucm->localSiteId();
        $find = $db->prepare('SELECT id FROM ucmp_phone_services WHERE site_id=? ORDER BY menu_order,name,id');
        $find->execute([$siteId]);
        $current = array_map('intval', $find->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        $expected = $current; $submitted = $ids;
        sort($expected, SORT_NUMERIC); sort($submitted, SORT_NUMERIC);
        if ($expected !== $submitted) {
            throw new \InvalidArgumentException('Service order must contain every service on this PBX exactly once');
        }

        $db->beginTransaction();
        try {
            $update = $db->prepare('UPDATE ucmp_phone_services SET menu_order=?,updated=? WHERE id=? AND site_id=?');
            $now = time();
            foreach ($ids as $position=>$id) {
                $update->execute([($position + 1) * 10, $now, $id, $siteId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
        return ['ok'=>true,'message'=>'Phone-service order saved on this PBX.'] + $this->catalog();
    }

    private function preview(array $request): array
    {
        $format = (string)($request['format'] ?? 'cisco');
        $key = (string)($request['service_key'] ?? 'root');
        $this->assertRenderRequest($format, '000000000000');
        $modelKey = $this->previewModel((string)($request['model_key'] ?? ''), $format);
        $services = $this->catalog(false, false)['services'];
        $service = null;
        if ($key === 'root') {
            $services = array_values(array_filter(
                $services,
                fn($item)=>!empty($item['enabled']) && $this->appliesTo($item, $format, $modelKey)
            ));
            $route = [];
        } else {
            foreach ($services as $candidate) {
                if (hash_equals((string)$candidate['service_key'], $key)) { $service = $candidate; break; }
            }
            if (!$service) { throw new \InvalidArgumentException('Unknown phone service'); }
            $services = [$service];
            $route = ['service',$key];
        }
        $rendered = $this->renderCatalog($format, '000000000000', $route, $services);
        $applicable = $service === null || $this->appliesTo($service, $format, $modelKey);
        $published = $service === null || !empty($service['enabled']);
        $warning = !$published
            ? 'This service is hidden; the preview is not currently published.'
            : $this->applicabilityWarning($service, $format, $modelKey, $applicable);
        return [
            'ok'=>true, 'format'=>$format, 'content_type'=>$rendered['content_type'],
            'xml'=>$rendered['body'], 'applicable'=>$applicable, 'published'=>$published,
            'warning'=>$warning, 'model_key'=>$modelKey,
        ];
    }

    /** Render a public, token-protected request. Route is [] or [service,key] or [service,key,item,index]. */
    public function render(string $format, string $mac, array $route): array
    {
        $this->assertRenderRequest($format, $mac);
        $services = $this->catalog(false, false)['services'];
        $needsModel = array_filter($services, fn($service)=>$this->serviceModels((array)($service['settings'] ?? []), false));
        $modelKey = $needsModel ? $this->phoneModelKey(strtolower($mac)) : null;
        $catalog = array_values(array_filter(
            $services,
            fn($service)=>!empty($service['enabled']) && $this->appliesTo($service, $format, $modelKey)
        ));
        return $this->renderCatalog($format, strtolower($mac), $route, $catalog);
    }

    private function renderCatalog(
        string $format,
        string $mac,
        array $route,
        array $catalog,
        ?string $rootOverride=null,
        ?string $directoryOverride=null,
        ?string $phoneInfoOverride=null
    ): array
    {
        $root = $rootOverride ?? $this->serviceBaseUrl($format, strtolower($mac));
        if (!$route) { return $this->renderMenu($format, 'Phone Services', $catalog, $root); }
        if (($route[0] ?? '') !== 'service' || !preg_match('/^[a-z0-9-]{1,48}$/D', (string)($route[1] ?? ''))) {
            throw new \InvalidArgumentException('Invalid phone-service route');
        }
        if (count($route) !== 2
            && (count($route) !== 4 || ($route[2] ?? '') !== 'item' || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', (string)($route[3] ?? '')))
        ) {
            throw new \InvalidArgumentException('Invalid phone-service route');
        }
        $key = (string)$route[1];
        $service = null;
        foreach ($catalog as $candidate) { if (hash_equals((string)$candidate['service_key'], $key)) { $service=$candidate; break; } }
        if (!$service) { throw new \RuntimeException('Phone service is disabled or missing'); }
        $type = (string)$service['service_type'];
        if ($type === 'directory') {
            $url = $directoryOverride ?? $this->directoryUrl($format);
            return $url === ''
                ? $this->renderText($format, (string)$service['name'], 'The company directory is unavailable. Publish or refresh the directory cache and try again.')
                : $this->redirectDocument($format, $url, (string)$service['name']);
        }
        if ($type === 'info') {
            return $this->renderText(
                $format,
                (string)$service['name'],
                $phoneInfoOverride ?? $this->phoneInfo($mac)
            );
        }
        if ($type === 'text') {
            return $this->renderText($format, (string)$service['name'], (string)($service['settings']['body'] ?? ''));
        }
        $items = (array)($service['settings']['items'] ?? []);
        if (($route[2] ?? '') === 'item') {
            $index = (int)($route[3] ?? -1);
            if ($index < 0 || !isset($items[$index])) { throw new \InvalidArgumentException('Unknown service item'); }
            return $this->renderDial($format, (string)$items[$index]['label'], (string)$items[$index]['number']);
        }
        $menu = [];
        foreach ($items as $index=>$item) {
            $menu[] = ['name'=>$item['label'],'url'=>$root . '/service/' . rawurlencode($key) . '/item/' . $index];
        }
        return $this->renderMenu($format, (string)$service['name'], $menu, $root);
    }

    private function validateSettings(string $type, array $settings, bool $enabled): array
    {
        $formats = $this->serviceFormats($settings, true);
        $validated = [
            'formats'=>$formats,
            'target_models'=>$this->serviceModels($settings, true, $formats),
        ];
        if ($type === 'text') {
            $validated['body'] = $this->text($settings['body'] ?? '', 1200);
            if ($enabled && $validated['body'] === '') { throw new \InvalidArgumentException('A published notice needs notice text'); }
            return $validated;
        }
        if ($type !== 'diallist') { return $validated; }
        if (!is_array($settings['items'] ?? [])) { throw new \InvalidArgumentException('Quick-dial entries must be a list'); }
        if (count($settings['items']) > 40) { throw new \InvalidArgumentException('A quick-dial service supports at most 40 destinations'); }
        $items = [];
        foreach ($settings['items'] as $item) {
            if (!is_array($item)) { continue; }
            $label = $this->text($item['label'] ?? '', 40);
            $number = trim((string)($item['number'] ?? ''));
            if ($label === '' && $number === '') { continue; }
            if ($label === '' || !preg_match('/^[0-9A-Da-d*#+,._-]{1,64}$/D', $number)) {
                throw new \InvalidArgumentException('Each quick-dial row needs a label and a dial-safe number');
            }
            $items[] = ['label'=>$label,'number'=>$number];
        }
        if ($enabled && !$items) { throw new \InvalidArgumentException('A published quick-dial service needs at least one destination'); }
        $validated['items'] = $items;
        return $validated;
    }

    private function serviceFormats(array $settings, bool $strict): array
    {
        if (!array_key_exists('formats', $settings)) { return self::FORMATS; }
        if (!is_array($settings['formats'])) {
            if ($strict) { throw new \InvalidArgumentException('Phone families must be a list'); }
            return [];
        }
        $formats = [];
        foreach ($settings['formats'] as $format) {
            if (!is_string($format) || !in_array($format, self::FORMATS, true)) {
                if ($strict) { throw new \InvalidArgumentException('Unsupported phone family'); }
                continue;
            }
            if (!in_array($format, $formats, true)) { $formats[] = $format; }
        }
        if ($strict && !$formats) { throw new \InvalidArgumentException('Select at least one phone family'); }
        return $formats;
    }

    private function serviceModels(array $settings, bool $strict, array $formats=[]): array
    {
        if (!array_key_exists('target_models', $settings) || $settings['target_models'] === []) { return []; }
        if (!is_array($settings['target_models']) || !array_is_list($settings['target_models'])) {
            if ($strict) { throw new \InvalidArgumentException('Target phone models must be a list'); }
            return [];
        }
        if (count($settings['target_models']) > self::MAX_TARGET_MODELS) {
            if ($strict) { throw new \InvalidArgumentException('Select at most 100 target phone models'); }
            return [];
        }
        $available = [];
        if ($strict) {
            foreach ($this->modelOptions() as $model) { $available[(string)$model['key']] = (string)$model['format']; }
        }
        $models = [];
        foreach ($settings['target_models'] as $model) {
            if (!is_string($model) || strlen($model) > 96 || trim($model) === '') {
                if ($strict) { throw new \InvalidArgumentException('Target phone model is invalid'); }
                continue;
            }
            $model = trim($model);
            if ($strict && !isset($available[$model])) {
                throw new \InvalidArgumentException('Target phone model is unknown or does not support the service catalog');
            }
            if ($strict && !in_array($available[$model], $formats, true)) {
                throw new \InvalidArgumentException('Every target model must belong to a selected phone family');
            }
            if (!in_array($model, $models, true)) { $models[] = $model; }
        }
        return $models;
    }

    private function appliesTo(array $service, string $format, ?string $modelKey): bool
    {
        if (!in_array($format, $this->serviceFormats((array)($service['settings'] ?? []), false), true)) {
            return false;
        }
        $models = $this->serviceModels((array)($service['settings'] ?? []), false);
        return !$models || ($modelKey !== null && in_array($modelKey, $models, true));
    }

    private function applicabilityWarning(?array $service, string $format, ?string $modelKey, bool $applicable): string
    {
        if ($service === null || $applicable) { return ''; }
        if (!in_array($format, $this->serviceFormats((array)($service['settings'] ?? []), false), true)) {
            return 'This service is excluded from the selected phone family.';
        }
        $models = $this->serviceModels((array)($service['settings'] ?? []), false);
        if ($models && $modelKey === null) {
            return 'This service targets exact models; select one to evaluate applicability.';
        }
        return 'This service is excluded from the selected phone model.';
    }

    private function assertRenderRequest(string $format, string $mac): void
    {
        if (!in_array($format, self::FORMATS, true)) { throw new \InvalidArgumentException('Unsupported phone-service format'); }
        if (!preg_match('/^[a-f0-9]{12}$/D', strtolower($mac))) { throw new \InvalidArgumentException('Invalid phone identity'); }
    }

    private function modelOptions(): array
    {
        $stmt = $this->ucm->getDb()->prepare(
            'SELECT vendor,model,family,role FROM ucmp_models ORDER BY vendor,model LIMIT 201'
        );
        $stmt->execute();
        $models = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            if (count($models) >= 200) { throw new \RuntimeException('Phone model catalog exceeds the supported preview limit'); }
            $vendor = trim((string)($row['vendor'] ?? ''));
            $model = trim((string)($row['model'] ?? ''));
            $family = strtolower(trim((string)($row['family'] ?? '')));
            $format = self::FAMILY_FORMATS[$family] ?? null;
            if ($vendor === '' || $model === '' || $format === null) { continue; }
            // The verified Grandstream XMLApp entry is currently emitted only for GXP/GRP.
            if ($format === 'grandstream' && !preg_match('/^(?:GXP|GRP)/i', $model)) { continue; }
            if ($format === 'yealink' && strtolower((string)($row['role'] ?? 'desk')) !== 'desk') { continue; }
            $models[] = [
                'key'=>$this->canonicalModelKey($vendor, $model),
                'vendor'=>$vendor,
                'model'=>$model,
                'family'=>$family,
                'format'=>$format,
            ];
        }
        return $models;
    }

    private function previewModel(string $key, string $format): ?string
    {
        $key = trim($key);
        if ($key === '') { return null; }
        foreach ($this->modelOptions() as $model) {
            if (hash_equals((string)$model['key'], $key)) {
                if ((string)$model['format'] !== $format) {
                    throw new \InvalidArgumentException('Preview model does not belong to the selected phone family');
                }
                return $key;
            }
        }
        throw new \InvalidArgumentException('Preview model is unknown or does not support the service catalog');
    }

    private function phoneModelKey(string $mac): ?string
    {
        $stmt = $this->ucm->getDb()->prepare(
            'SELECT m.vendor,m.model FROM ucmp_phones p
             INNER JOIN ucmp_models m ON m.id=p.model_id
             WHERE p.site_id=? AND LOWER(p.mac)=? LIMIT 1'
        );
        $stmt->execute([$this->ucm->localSiteId(), strtolower($mac)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        return $row ? $this->canonicalModelKey((string)$row['vendor'], (string)$row['model']) : null;
    }

    private function canonicalModelKey(string $vendor, string $model): string
    {
        return strtoupper(trim($vendor)) . '|' . strtoupper(trim($model));
    }

    private function previewCache(array $services): array
    {
        $cache = [];
        foreach ($services as $service) {
            $key = (string)($service['service_key'] ?? '');
            if ($key === '') { continue; }
            foreach (self::FORMATS as $format) {
                $root = 'https://phone-services.invalid/ucmp-s.php/REDACTED/' . $format . '/000000000000';
                $rendered = $this->renderCatalog(
                    $format,
                    '000000000000',
                    ['service',$key],
                    [$service],
                    $root,
                    'https://phone-services.invalid/directory/' . $format,
                    "Site: Cached preview\nExtension: Device-specific\nMAC: Device-specific"
                );
                $cache[$key][$format] = [
                    'content_type'=>$rendered['content_type'],
                    'xml'=>$rendered['body'],
                ];
            }
        }
        return $cache;
    }

    private function renderMenu(string $format, string $title, array $items, string $root): array
    {
        $items = array_map(static fn(array $item): array => [
            'name'=>(string)($item['name']??''),
            'url'=>(string)($item['url']??($root.'/service/'.rawurlencode((string)($item['service_key']??'')))),
        ], $items);
        if ($format === 'grandstream') { return $this->grandstreamMenu($title,$items); }
        $doc = new \DOMDocument('1.0','UTF-8'); $doc->formatOutput=true;
        if (str_starts_with($format,'cisco')) {
            $node=$doc->createElement('CiscoIPPhoneMenu');$doc->appendChild($node);$this->child($doc,$node,'Title',$title);$this->child($doc,$node,'Prompt','Choose a service');
            foreach (array_slice($items,0,100) as $item) { $m=$this->child($doc,$node,'MenuItem');$this->child($doc,$m,'Name',(string)($item['name']??''));$this->child($doc,$m,'URL',(string)($item['url']??($root.'/service/'.rawurlencode((string)$item['service_key'])))); }
        } else {
            $node=$doc->createElement('YealinkIPPhoneTextMenu');$node->setAttribute('style','radio');$doc->appendChild($node);$this->child($doc,$node,'Title',$title);
            foreach (array_slice($items,0,100) as $item) { $m=$this->child($doc,$node,'MenuItem');$this->child($doc,$m,'Prompt',(string)($item['name']??''));$this->child($doc,$m,'URI',(string)($item['url']??($root.'/service/'.rawurlencode((string)$item['service_key'])))); }
        }
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function renderText(string $format, string $title, string $body): array
    {
        if ($format === 'grandstream') { return $this->grandstreamText($title,$body); }
        $doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=true;
        $tag=str_starts_with($format,'cisco')?'CiscoIPPhoneText':'YealinkIPPhoneTextScreen';$node=$doc->createElement($tag);$doc->appendChild($node);$this->child($doc,$node,'Title',$title);$this->child($doc,$node,'Text',$body);
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function renderDial(string $format, string $label, string $number): array
    {
        if ($format === 'cisco_legacy') { return $this->renderText($format,$label,"Dial manually: $number\n\nThis legacy SIP firmware does not implement CiscoIPPhoneExecute."); }
        if ($format === 'grandstream') { return $this->grandstreamDial($label,$number); }
        $doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=true;
        if ($format === 'cisco') { $node=$doc->createElement('CiscoIPPhoneExecute');$doc->appendChild($node);$item=$doc->createElement('ExecuteItem');$item->setAttribute('URL','Dial:'.$number);$node->appendChild($item); }
        else { $node=$doc->createElement('YealinkIPPhoneExecute');$doc->appendChild($node);$item=$doc->createElement('ExecuteItem');$item->setAttribute('URI','Dial:'.$number);$node->appendChild($item); }
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function redirectDocument(string $format, string $url, string $label): array
    {
        if ($format === 'grandstream') { return $this->grandstreamLink($label,$url); }
        if ($format === 'cisco_legacy') {
            return $this->renderMenu($format,$label,[['name'=>'Open directory','url'=>$url]],$this->serviceBaseUrl($format,'000000000000'));
        }
        $doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=true;
        if ($format === 'cisco') { $node=$doc->createElement('CiscoIPPhoneExecute');$doc->appendChild($node);$item=$doc->createElement('ExecuteItem');$item->setAttribute('URL',$url);$node->appendChild($item); }
        else { $node=$doc->createElement('YealinkIPPhoneExecute');$doc->appendChild($node);$item=$doc->createElement('ExecuteItem');$item->setAttribute('URI',$url);$node->appendChild($item); }
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function grandstreamMenu(string $title, array $items): array
    {
        $doc=$this->grandstreamScreen($title, array_map(static fn($i)=>(string)($i['name']??''),$items));$soft=$doc->getElementsByTagName('SoftKeys')->item(0);
        foreach (array_slice($items,0,20) as $item) { $key=$doc->createElement('SoftKey');$key->setAttribute('action','UseURL');$key->setAttribute('label',$this->text($item['name']??'Open',16));$key->setAttribute('commandArgs',(string)($item['url']??''));$soft->appendChild($key); }
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function grandstreamText(string $title, string $body): array
    {
        $doc=$this->grandstreamScreen($title,preg_split('/\R/',wordwrap($body,34)) ?: []);$soft=$doc->getElementsByTagName('SoftKeys')->item(0);$key=$doc->createElement('SoftKey');$key->setAttribute('action','QuitApp');$key->setAttribute('label','Exit');$soft->appendChild($key);
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function grandstreamDial(string $label, string $number): array
    {
        $doc=$this->grandstreamScreen($label,[$number]);$soft=$doc->getElementsByTagName('SoftKeys')->item(0);$key=$doc->createElement('SoftKey');$key->setAttribute('action','Dial');$key->setAttribute('label','Call');$key->setAttribute('commandId','0');$key->setAttribute('commandArgs',$number);$soft->appendChild($key);
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function grandstreamLink(string $label, string $url): array
    {
        $doc=$this->grandstreamScreen($label,['Press Open']);$soft=$doc->getElementsByTagName('SoftKeys')->item(0);$key=$doc->createElement('SoftKey');$key->setAttribute('action','UseURL');$key->setAttribute('label','Open');$key->setAttribute('commandArgs',$url);$soft->appendChild($key);
        return ['content_type'=>'text/xml; charset=UTF-8','body'=>$doc->saveXML()];
    }

    private function grandstreamScreen(string $title, array $lines): \DOMDocument
    {
        $doc=new \DOMDocument('1.0','UTF-8');$doc->formatOutput=true;$screen=$doc->createElement('Screen');$doc->appendChild($screen);$page=$this->child($doc,$screen,'Page');$this->child($doc,$page,'ShowStatusLine','true');$contents=$this->child($doc,$page,'Contents');
        foreach (array_slice(array_merge([$title],$lines),0,12) as $i=>$text) { $display=$this->child($doc,$contents,'DisplayString');$this->child($doc,$display,'X','8');$this->child($doc,$display,'Y',(string)(8+$i*18));$this->child($doc,$display,'DisplayStr',$this->text($text,48)); }
        $this->child($doc,$page,'SoftKeys'); return $doc;
    }

    private function phoneInfo(string $mac): string
    {
        $stmt=$this->ucm->getDb()->prepare(
            'SELECT COALESCE(x.extension,p.extension) extension,p.label,p.ip,p.config_filename,
                    p.last_applied,p.updated,p.site_id,p.model_id,
                    CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END group_id,
                    m.vendor,m.model,m.vendor m_vendor,m.model m_model,m.family m_family,
                    m.series_key m_series_key,m.role m_role,g.name group_name
               FROM ucmp_phones p
          LEFT JOIN ucmp_extensions x ON x.id=p.extension_id
          LEFT JOIN ucmp_models m ON m.id=p.model_id
          LEFT JOIN ucmp_groups g ON g.id=CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END
              WHERE p.site_id=? AND LOWER(p.mac)=? LIMIT 1'
        );
        $stmt->execute([$this->ucm->localSiteId(),strtolower($mac)]);$p=$stmt->fetch(\PDO::FETCH_ASSOC) ?: [];$site=$this->ucm->getSite($this->ucm->localSiteId()) ?: [];
        $templateSummary = '';
        if (!empty($p['model_id'])) {
            try {
                $parts = [];
                $engine = new TemplateEngine($this->ucm);
                foreach ($engine->templateAssignmentTrace($p) as $entry) {
                    if (($entry['status'] ?? '') !== 'applied') { continue; }
                    $source = (string)($entry['source'] ?? '');
                    $origin = $source === 'default'
                        ? 'Default'
                        : ($source === 'group'
                            ? 'Group: ' . (string)($entry['group_name'] ?? 'unknown')
                            : ($source === 'exception'
                                ? 'Member exception: ' . (string)($entry['group_name'] ?? 'group')
                                : ucfirst($source ?: 'Assigned')));
                    $parts[] = '(' . $origin . ') ' . (string)($entry['template_name'] ?? ('Template ' . (int)($entry['template_id'] ?? 0)));
                }
                $templateSummary = implode(' + ', $parts);
            } catch (\Throwable $ignored) {
                $templateSummary = 'Resolution unavailable';
            }
        }
        return implode("\n",[
            'Site: '.($site['name']??'Local PBX'),'Extension: '.($p['extension']??'Not assigned'),'Label: '.($p['label']??''),'Model: '.trim(($p['vendor']??'').' '.($p['model']??'')),'MAC: '.strtoupper($mac),'IP: '.($p['ip']??($_SERVER['REMOTE_ADDR']??'')),'Group: '.($p['group_name']??''),'Template: '.$templateSummary,'Config: '.($p['config_filename']??''),'Last applied: '.(!empty($p['last_applied'])?date('Y-m-d H:i',(int)$p['last_applied']):'Never'),
        ]);
    }

    private function directoryUrl(string $format): string
    {
        $configured=trim((string)$this->ucm->getSetting('directory_token',$this->ucm->localSiteId(),''));
        if (!preg_match('/^[A-Fa-f0-9]{8,128}$/D',$configured)) { return ''; }
        $token=is_readable('/var/lib/asterisk/ucmprovisioner/directory/token')?trim((string)file_get_contents('/var/lib/asterisk/ucmprovisioner/directory/token')):'';
        if (!preg_match('/^[A-Fa-f0-9]{8,128}$/D',$token) || !hash_equals($configured,$token)) { return ''; }
        if ($format==='cisco_legacy') { return $this->publicBase().'/ucmp-d.php/'.rawurlencode($token); }
        $dirFormat=$format==='cisco'?'cisco':$format;
        return $this->publicBase().'/ucmprovisioner-directory.php?token='.rawurlencode($token).'&format='.$dirFormat;
    }

    private function serviceBaseUrl(string $format, string $mac): string
    {
        return $this->publicBase().'/ucmp-s.php/'.rawurlencode($this->ucm->phoneServicesToken()).'/'.$format.'/'.strtolower($mac);
    }

    private function publicBase(): string
    {
        $configured=trim((string)$this->ucm->getSetting('phone_services_base_url',$this->ucm->localSiteId(),''));
        if ($configured!=='') { return MediaUrl::normalizeBase($configured); }
        $site=$this->ucm->getSite($this->ucm->localSiteId()) ?: [];$host=trim((string)($site['pbx_addr']??$site['pbx_host']??''));
        if ($host==='' || !preg_match('/^[A-Za-z0-9.\-:\[\]]+$/D',$host)) { $host='127.0.0.1'; }
        if (filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) { $host='['.$host.']'; }
        return MediaUrl::normalizeBase('http://'.$host);
    }

    private function exampleUrls(): array
    {
        $mac='001122334455';
        return ['cisco'=>$this->serviceBaseUrl('cisco',$mac),'cisco_legacy'=>$this->serviceBaseUrl('cisco_legacy',$mac),'yealink'=>$this->serviceBaseUrl('yealink',$mac),'grandstream'=>$this->serviceBaseUrl('grandstream',$mac)];
    }

    private function capabilities(): array
    {
        return [
            ['fleet'=>'Cisco XML 78xx/88xx/79xx','entry'=>'Native Services URL','catalog'=>true,'dial'=>true,'note'=>'Rebuild provisions the phone Services application URL.'],
            ['fleet'=>'Cisco SIP 7940/7960','entry'=>'Native Services menu','catalog'=>true,'dial'=>false,'note'=>'Short query-free URL; read-only menus/text because this SIP firmware does not support CiscoIPPhoneExecute.'],
            ['fleet'=>'Yealink desk','entry'=>'Template XML Browser key','catalog'=>true,'dial'=>true,'note'=>'Use key type XML Browser and target {services}.'],
            ['fleet'=>'Yealink DECT','entry'=>'Downloaded directory','catalog'=>false,'dial'=>false,'note'=>'Directory is provisioned through the base. XML-browser wiring differs by base/handset firmware and is left fail-closed.'],
            ['fleet'=>'Grandstream GXP/GRP','entry'=>'XMLApp softkey / XML Application key','catalog'=>true,'dial'=>true,'note'=>'Rebuild provisions P337 and P352.'],
            ['fleet'=>'Grandstream DP DECT','entry'=>'Downloaded directory','catalog'=>false,'dial'=>false,'note'=>'Handsets inherit the company directory from their base.'],
            ['fleet'=>'Grandstream GHP hotel','entry'=>'Directory and programmable dial keys','catalog'=>false,'dial'=>false,'note'=>'The verified GHP profile has no general XML Application mode; unsupported output is not emitted.'],
        ];
    }

    private function child(\DOMDocument $doc, \DOMElement $parent, string $name, string $value=''): \DOMElement
    {
        $node=$doc->createElement($name);if($value!==''){$node->appendChild($doc->createTextNode($value));}$parent->appendChild($node);return $node;
    }

    private function text($value, int $limit): string
    {
        $value=trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',(string)$value)??'');
        if (function_exists('mb_substr')) { return mb_substr($value,0,$limit,'UTF-8'); }
        return implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY) ?: [],0,$limit));
    }
}
