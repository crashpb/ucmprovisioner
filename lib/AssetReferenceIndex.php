<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Private reference graph. Raw source fields never belong in browser responses. */
final class AssetReferenceIndex
{
    private const TABLES=['ucmp_settings','ucmp_templates','ucmp_phones','ucmp_phonebooks','ucmp_site_jobs',
        'ucmp_media_replication_jobs','ucmp_firmware_replication_jobs','ucmp_configuration_sync_jobs','ucmp_configuration_sync_items'];
    public function __construct(private \PDO $db) {}

    public function snapshot(int $siteId): array
    {
        if ($siteId<1) { throw new \InvalidArgumentException('Choose a valid site'); }
        $sources=[];$missing=[];
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) { $missing[]=$table;continue; }
            // Read only known reference columns, never complete settings/credential rows.
            $wanted=match($table) {
                'ucmp_settings'=>['id','site_id','skey','sval'],
                'ucmp_templates'=>['id','site_id','name','phonebook_filename','setting_overrides'],
                'ucmp_phones'=>['id','site_id','mac','extension','overrides'],
                'ucmp_phonebooks'=>['id','name','filename','publish_sites'],
                'ucmp_site_jobs'=>['id','site_id','job_type','object_key','payload_ref','state'],
                'ucmp_media_replication_jobs'=>['id','name','source_site_id','enabled','scopes_json','targets_json','last_status'],
                'ucmp_firmware_replication_jobs'=>['id','name','source_site_id','enabled','packages_json','timezone_bundle_key','include_all_firmware','include_all_timezones','include_all_locales','targets_json','last_status'],
                'ucmp_configuration_sync_jobs'=>['id','name','source_site_id','enabled','scopes_json','object_filter_json','targets_json','last_status'],
                default=>['id','job_id','source_site_id','target_site_id','object_type','object_key','status','intended_json','missing_json'],
            };
            $columns=array_values(array_intersect($wanted,$this->columns($table)));
            if (!in_array('id',$columns,true)) { $missing[]=$table;continue; }
            $required=match($table) {
                'ucmp_settings'=>['site_id','skey','sval'],
                'ucmp_templates'=>['site_id','phonebook_filename','setting_overrides'],
                'ucmp_phones'=>['site_id','overrides'],
                'ucmp_phonebooks'=>['filename'],
                'ucmp_site_jobs'=>['site_id','job_type','payload_ref','state'],
                'ucmp_media_replication_jobs'=>['source_site_id','enabled','scopes_json','targets_json'],
                'ucmp_firmware_replication_jobs'=>['source_site_id','enabled','packages_json','timezone_bundle_key','include_all_firmware','include_all_timezones','include_all_locales','targets_json'],
                'ucmp_configuration_sync_jobs'=>['source_site_id','enabled','scopes_json','targets_json'],
                default=>['target_site_id','object_type','status','intended_json'],
            };
            if (array_diff($required,$columns)) { $missing[]=$table.':reference_columns'; }
            $statement=$this->db->query('SELECT '.implode(',',array_map(static fn(string $column): string=>'`'.$column.'`',$columns)).' FROM `'.$table.'` ORDER BY id');
            $count=0;
            while ($row=$statement->fetch(\PDO::FETCH_ASSOC)) {
                if (++$count>100000) { throw new \LengthException('Reference inventory is too large; deletion is blocked'); }
                foreach ($row as $field=>$content) {
                    if (!is_string($content)||trim($content)==='') { continue; }
                    if (str_ends_with($field,'_json')||in_array(substr(ltrim($content),0,1),['{','['],true)) {
                        json_decode($content,true);
                        if (json_last_error()!==JSON_ERROR_NONE) { $missing[]=$table.':invalid_reference_json'; }
                    }
                }
                if (isset($row['site_id']) && (int)$row['site_id']>0 && (int)$row['site_id']!==$siteId) { continue; }
                if (isset($row['source_site_id']) && (int)$row['source_site_id']!==$siteId && (int)($row['target_site_id']??0)!==$siteId
                    && !in_array($siteId,array_map('intval',(array)json_decode((string)($row['targets_json']??'[]'),true)),true)) { continue; }
                if (!isset($row['source_site_id'])&&isset($row['target_site_id'])&&(int)$row['target_site_id']!==$siteId) { continue; }
                $type=match($table) {'ucmp_settings'=>'site_setting','ucmp_templates'=>'template','ucmp_phones'=>'phone','ucmp_phonebooks'=>'phonebook_definition',default=>'job'};
                $state=(string)($row['state']??$row['status']??$row['last_status']??'');
                if ($type==='job' && array_key_exists('enabled',$row) && empty($row['enabled']) && !in_array($state,['queued','pending','running','retry','applying','recovery_required'],true)) { continue; }
                if ($table==='ucmp_site_jobs' && in_array($state,['complete','completed','success','verified','cancelled','done'],true)) { continue; }
                if ($table==='ucmp_configuration_sync_items' && $state==='released') { continue; }
                $key=(string)($row['skey']??'');
                if ($type==='site_setting' && !preg_match('/(?:firmware|load|wallpaper|background|ringtone|tzdata|timezone|locale|phonebook|asset|logo|image|media)/i',$key)) { continue; }
                $fields=match($type) {
                    'site_setting'=>['sval'=>$row['sval']??null],
                    'template'=>array_intersect_key($row,array_flip(['phonebook_filename','setting_overrides'])),
                    'phone'=>['overrides'=>$row['overrides']??null],
                    default=>array_diff_key($row,array_flip(['id','name','mac','extension'])),
                };
                $value=$type==='site_setting'?[$key=>$row['sval']??null]:$fields;
                $scope=[];
                if ($table==='ucmp_media_replication_jobs') {
                    $scopes=(array)json_decode((string)($row['scopes_json']??'[]'),true);
                    foreach ($scopes as $scopeName) {
                        if (preg_match('/wallpaper|background|special/i',(string)$scopeName)) { $scope[]='background'; }
                        if (preg_match('/ring|audio/i',(string)$scopeName)) { $scope[]='ringtone'; }
                    }
                    if (!$scope) { $scope=['background','ringtone']; }
                }
                if ($table==='ucmp_firmware_replication_jobs') {
                    if (!empty($row['include_all_firmware'])) { $scope[]='firmware'; }
                    if (!empty($row['include_all_timezones'])||!empty($row['include_all_locales'])) { $scope[]='locale'; }
                }
                // Opaque pending work cannot be proved unrelated to an asset.
                if (in_array($table,['ucmp_site_jobs','ucmp_configuration_sync_jobs','ucmp_configuration_sync_items'],true)) { $scope=ManagedAssetRegistry::KINDS; }
                $sources[]=['type'=>$type,'table'=>$table,'id'=>(int)$row['id'],
                    'label'=>(string)($row['name']??$row['skey']??$row['job_type']??$row['object_type']??('#'.$row['id'])),
                    'state'=>$state,'site_id'=>$row['site_id']??null,'fields'=>$fields,'key'=>$key,'value'=>$value,
                    'mutable'=>in_array($type,['site_setting','template','phone'],true) && isset($row['site_id']) && (int)$row['site_id']===$siteId,
                    'scope_kinds'=>array_values(array_unique($scope))];
            }
        }
        return ['complete'=>!$missing,'missing'=>$missing,'sources'=>$sources];
    }

    public static function matches(array $source, array $asset): bool
    {
        return in_array($asset['kind'],(array)($source['scope_kinds']??[]),true) || self::references($source['value']??[],$asset);
    }

    public static function references(mixed $value, array $asset, string $key=''): bool
    {
        if (is_string($value)) {
            $decoded=json_decode($value,true);
            if (json_last_error()===JSON_ERROR_NONE && $decoded!==$value) { return self::references($decoded,$asset,$key); }
            foreach ([$asset['asset_key']??'', $asset['metadata']['timezone_asset_key']??'', $asset['bundle_key']??''] as $identity) {
                if ($identity!=='' && hash_equals((string)$identity,trim($value))) { return true; }
            }
            if (preg_match('/(?:asset|payload)_id$/i',$key) && ctype_digit(trim($value)) && (int)$value===(int)$asset['asset_id']) { return true; }
            if (!preg_match('/(?:firmware|load|wallpaper|background|ringtone|tzdata|timezone|locale|phonebook|file|path|alias|payload|logo|image|media)/i',$key)) { return false; }
            $path=parse_url(trim($value),PHP_URL_PATH);
            return is_string($path) && hash_equals((string)$asset['filename'],basename(str_replace('\\','/',$path)));
        }
        if (is_int($value) && preg_match('/(?:asset|payload)_id$/i',$key)) { return $value===(int)$asset['asset_id']; }
        if (!is_array($value)) { return false; }
        foreach ($value as $childKey=>$child) { if (self::references($child,$asset,$key===''?(string)$childKey:$key.'.'.$childKey)) { return true; } }
        return false;
    }

    public static function summary(array $source): array
    {
        return array_intersect_key($source,array_flip(['type','id','label','state','mutable']));
    }

    private function columns(string $table): array
    {
        $rows=$this->db->query($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'PRAGMA table_info(`'.$table.'`)':'SHOW COLUMNS FROM `'.$table.'`')->fetchAll(\PDO::FETCH_ASSOC);
        return array_column($rows,$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?'name':'Field');
    }
    private function tableExists(string $table): bool
    {
        $statement=$this->db->prepare($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'?"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?":'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $statement->execute([$table]);return (bool)$statement->fetchColumn();
    }
}
