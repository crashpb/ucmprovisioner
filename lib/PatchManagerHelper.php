<?php
/** Root-side, fixed-schema entry point for the Asterisk version manager. */
namespace FreePBX\modules\Ucmprovisioner;

if (!class_exists(__NAMESPACE__ . '\\PatchCatalog', false)) {
    require_once __DIR__ . '/PatchCatalog.php';
}
if (!class_exists(__NAMESPACE__ . '\\AsteriskVersionManager', false)) {
    require_once __DIR__ . '/AsteriskVersionManager.php';
}

final class PatchManagerHelper
{
    private const LOCK_FILE = '/run/lock/ucmp-patch-manager.lock';

    public function __construct(
        private ?AsteriskVersionManager $manager = null,
        private string $lockFile = self::LOCK_FILE,
        private bool $enforceRoot = true
    ) {
        $this->manager ??= new AsteriskVersionManager();
    }

    /** @return array<string,mixed> */
    public function handle(array $request): array
    {
        if ($this->enforceRoot && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new \RuntimeException('The Patch Manager helper must run as root');
        }
        if (array_is_list($request)) {
            throw new \InvalidArgumentException('Patch Manager request must be an object');
        }
        $operation = strtolower(trim((string)($request['op'] ?? 'status')));
        $schemas = [
            'health'=>['op'],
            'status'=>['op'],
            'preview'=>['op','catalog_id','high_strength_srtp','jobs'],
            'download'=>['op','catalog_id'],
            'stage'=>['op','catalog_id','high_strength_srtp','jobs'],
            'build'=>['op','catalog_id','high_strength_srtp','jobs'],
            'capture'=>['op'],
            'import'=>['op','incoming_id','declared_version','declared_patched'],
            'activate'=>['op','bundle_id','confirmation'],
            'install'=>['op','bundle_id','confirmation'],
            'pin'=>['op','bundle_id','confirmation'],
            'protect'=>['op','bundle_id','confirmation'],
            'unpin'=>['op','confirmation'],
            'unprotect'=>['op','confirmation'],
            'rollback'=>['op','confirmation'],
            'delete'=>['op','bundle_id','confirmation'],
        ];
        if (!isset($schemas[$operation])) {
            throw new \InvalidArgumentException('Unknown Asterisk version-manager operation');
        }
        if (array_diff(array_keys($request), $schemas[$operation])) {
            throw new \InvalidArgumentException('Unknown fields are not accepted by the Patch Manager helper');
        }
        if (in_array($operation, ['preview','download','stage','build'], true)) {
            $entry = PatchCatalog::find(trim((string)($request['catalog_id'] ?? '')));
            if (!$entry || empty($entry['operator_selectable']) || empty($entry['module_compatible'])) {
                throw new \InvalidArgumentException('Choose a compatible immutable catalog entry');
            }
        }
        $confirmation = match ($operation) {
            'activate', 'install' => 'ACTIVATE ' . trim((string)($request['bundle_id'] ?? '')),
            'pin', 'protect' => 'PIN ' . trim((string)($request['bundle_id'] ?? '')),
            'unpin', 'unprotect' => 'UNPIN ASTERISK',
            'rollback' => 'ROLLBACK ASTERISK',
            'delete' => 'DELETE ' . trim((string)($request['bundle_id'] ?? '')),
            default => '',
        };
        if ($confirmation !== '' && !hash_equals($confirmation, trim((string)($request['confirmation'] ?? '')))) {
            throw new \RuntimeException('The exact transaction confirmation was not supplied');
        }
        if (in_array($operation, ['health','status','preview'], true)) {
            return $this->manager->handle($request);
        }
        $lock = $this->openLock();
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another Patch Manager operation is already running');
        }
        try {
            return $this->manager->handle($request);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource */
    private function openLock()
    {
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the Patch Manager lock directory');
        }
        $lock = @fopen($this->lockFile, 'c+');
        if (!is_resource($lock)) {
            throw new \RuntimeException('Unable to open the Patch Manager lock');
        }
        @chmod($this->lockFile, 0600);
        return $lock;
    }
}
