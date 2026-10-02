<?php
namespace FreePBX\modules\Ucmprovisioner;

/** Unprivileged BMO-side client for the fixed, root-owned helper. */
final class Fail2banService
{
    private const HELPER = '/usr/local/sbin/ucmp-fail2ban';

    public function __construct(private object $ucm) {}

    public static function enrollmentStatus(): array
    {
        $implementation = '/usr/local/libexec/ucmp-fail2ban-helper.php';
        $enrolled = is_executable(self::HELPER) && is_readable($implementation);
        return [
            'enrolled'=>$enrolled,
            'helper'=>self::HELPER,
            'message'=>$enrolled
                ? 'Fail2ban helper files are installed; open the Fail2ban tab to verify the sudo path.'
                : 'Fail2ban helper is not enrolled. Run sudo fwconsole ma install ucmprovisioner once on this PBX.',
        ];
    }

    public function handle(array $request): array
    {
        if (!is_executable(self::HELPER)) {
            return ['ok'=>false, 'enrolled'=>false, 'message'=>'Fail2ban helper is not enrolled on this PBX. Update/reinstall the module locally as root.'];
        }
        $op = (string)($request['op'] ?? 'overview');
        $allowed = match ($op) {
            'health' => ['op'],
            'overview' => ['op'],
            'detail' => ['op','jail'],
            'unban' => ['op','jail','ip'],
            'test_filter' => ['op','filter','sample'],
            default => ['op'],
        };
        $payload = array_intersect_key($request, array_fill_keys($allowed, true));
        $payload['op'] = $op;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) { throw new \InvalidArgumentException('Fail2ban request is too large'); }
        $pipes = [];
        $proc = proc_open(['/usr/bin/sudo','-n',self::HELPER], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, null, ['LC_ALL'=>'C','PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin'], ['bypass_shell'=>true]);
        if (!is_resource($proc)) { throw new \RuntimeException('Unable to start the Fail2ban helper'); }
        fwrite($pipes[0], $json); fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 1048577); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2], 65537); fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $decoded = json_decode((string)$stdout, true);
            throw new \RuntimeException((string)($decoded['message'] ?? trim((string)$stderr) ?: 'Fail2ban helper failed'));
        }
        $decoded = json_decode((string)$stdout, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) { throw new \RuntimeException('Fail2ban helper returned an invalid response'); }
        return $decoded + ['enrolled'=>true];
    }
}
