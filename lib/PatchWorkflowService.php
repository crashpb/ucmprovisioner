<?php
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/PatchCatalog.php';

/** Unprivileged local client for the fixed, no-argument root helper. */
final class PatchWorkflowService
{
    private const HELPER = '/usr/local/sbin/ucmp-patch-manager';
    private const IMPLEMENTATION = '/usr/local/libexec/PatchManagerHelper.php';
    private const MANAGER = '/usr/local/libexec/AsteriskVersionManager.php';
    private const CATALOG = '/usr/local/libexec/PatchCatalog.php';

    /** @return array<string,mixed> */
    public static function enrollmentStatus(): array
    {
        $enrolled = is_executable(self::HELPER)
            && is_readable(self::IMPLEMENTATION)
            && is_readable(self::MANAGER)
            && is_readable(self::CATALOG);
        return [
            'enrolled'=>$enrolled,
            'helper'=>self::HELPER,
            'message'=>$enrolled
                ? 'The local Asterisk version-manager helper is enrolled.'
                : 'Run fwconsole ma install ucmprovisioner as root to enroll the Asterisk version-manager helper.',
        ];
    }

    /** @return array<string,mixed> */
    public function state(string $catalogId = ''): array
    {
        if (!self::enrollmentStatus()['enrolled']) { return $this->unenrolledState(); }
        return $this->invoke(['op'=>'status']);
    }

    /** @return array<string,mixed> */
    public function handle(array $request): array
    {
        $operation = strtolower(trim((string)($request['op'] ?? 'status')));
        $schemas = [
            'status'=>['op'],
            'preview'=>['op','catalog_id','high_strength_srtp','jobs'],
            'download'=>['op','catalog_id'],
            'stage'=>['op','catalog_id','high_strength_srtp','jobs'],
            'build'=>['op','catalog_id','high_strength_srtp','jobs'],
            'capture'=>['op'],
            'import'=>['op','incoming_id','declared_version','declared_patched'],
            'activate'=>['op','bundle_id','confirmation'],
            'pin'=>['op','bundle_id','confirmation'],
            'unpin'=>['op','confirmation'],
            'rollback'=>['op','confirmation'],
            'delete'=>['op','bundle_id','confirmation'],
        ];
        if (!isset($schemas[$operation])) {
            throw new \InvalidArgumentException('Unknown Patch Manager operation');
        }
        $payload = array_intersect_key($request, array_fill_keys($schemas[$operation], true));
        $payload['op'] = $operation;
        if (isset($payload['catalog_id'])) {
            $payload['catalog_id'] = trim((string)$payload['catalog_id']);
            if (!PatchCatalog::find($payload['catalog_id'])) {
                throw new \InvalidArgumentException('Unknown immutable patch catalog ID');
            }
        }
        foreach (['incoming_id','declared_version','bundle_id','confirmation'] as $field) {
            if (array_key_exists($field, $payload)) { $payload[$field] = trim((string)$payload[$field]); }
        }
        if (!self::enrollmentStatus()['enrolled']) {
            if ($operation === 'status') { return $this->unenrolledState(); }
            throw new \RuntimeException(self::enrollmentStatus()['message']);
        }
        return $this->invoke($payload);
    }

    /** @return array<string,mixed> */
    private function unenrolledState(): array
    {
        return [
            'ok'=>true,
            'enrolled'=>false,
            'local_only'=>true,
            'mode'=>'freepbx17_source_bundles',
            'catalog'=>PatchCatalog::entries(),
            'builds'=>[],
            'bundles'=>[],
            'incoming'=>[],
            'active_bundle_id'=>'',
            'pinned_bundle_id'=>'',
            'switch_package_held'=>false,
            'pin_protected'=>false,
            'pin_drift'=>false,
            'history'=>[],
            'runtime'=>[],
            'message'=>self::enrollmentStatus()['message'],
        ];
    }

    /** @return array<string,mixed> */
    private function invoke(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 16384) {
            throw new \InvalidArgumentException('Patch Manager request is too large');
        }
        $pipes = [];
        $process = @proc_open(
            ['/usr/bin/sudo','-n',self::HELPER],
            [['pipe','r'],['pipe','w'],['pipe','w']],
            $pipes,
            null,
            ['LC_ALL'=>'C','PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin'],
            ['bypass_shell'=>true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the Patch Manager helper');
        }
        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 4194305);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2], 65537);
        fclose($pipes[2]);
        $code = proc_close($process);
        $decoded = json_decode((string)$stdout, true);
        if ($code !== 0 || !is_array($decoded)) {
            throw new \RuntimeException(is_array($decoded)
                ? (string)($decoded['message'] ?? 'Patch Manager helper failed')
                : (trim((string)$stderr) ?: 'Patch Manager helper returned invalid JSON'));
        }
        return $decoded + ['enrolled'=>true,'local_only'=>true];
    }
}
