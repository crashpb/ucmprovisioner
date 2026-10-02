<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/SiteConnection.php';

use FreePBX\modules\Ucmprovisioner\SiteConnection;
use phpseclib3\Net\SSH2;

$expect = static function (bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } };
final class StreamRpcSshFixture extends SSH2
{
    public array $commands = [];
    public string $input = '', $fault = '';
    public bool $supported = true, $eof = false, $timedOut = false;
    public int|false $status = 0;
    public int|false $completionStatus = 0;
    public string $response = '{"ok":true}';
    private int $reads = 0;
    public function __construct() { parent::__construct('fixture.invalid'); }
    public function exec($command, $callback = null) {
        $this->commands[] = $command; $this->status = 0; $this->reads = 0;
        if ($command === 'ucmp-rpc-capabilities') {
            $this->status = $this->supported ? 0 : 126;
            return $this->supported ? '{"rpc_stdin":1}' : '';
        }
        if ($command === 'ucmp-rpc-stdin-v1') { return true; }
        $this->status = $this->completionStatus;
        return $this->response;
    }
    public function write($cmd, $channel = null) {
        if ($channel !== self::CHANNEL_EXEC) { throw new RuntimeException('Wrong input channel'); }
        $this->input .= $cmd;
    }
    public function sendEOF($channel = null) { $this->eof = $channel === self::CHANNEL_EXEC; }
    public function read($expect = '', $mode = self::READ_SIMPLE, $channel = null) {
        if (!$this->eof || $mode !== self::READ_NEXT || $channel !== self::CHANNEL_EXEC) { throw new RuntimeException('Wrong response channel or missing EOF'); }
        if ($this->fault === 'lost') { return false; }
        if ($this->fault === 'timeout') { $this->timedOut = true; return true; }
        if ($this->fault === 'oversized') { return str_repeat('x', 16777217); }
        if ($this->reads++ === 0) { return substr($this->response, 0, 5); }
        if ($this->reads === 2) { return substr($this->response, 5); }
        $this->status = $this->fault === 'nonzero' ? 7 : ($this->fault === 'unknown_exit' ? false : $this->completionStatus);
        return true;
    }
    public function isTimeout() { return $this->timedOut; }
    public function getExitStatus() { return $this->status; }
    public function reset($channel = null) {}
}
$connection = static function (StreamRpcSshFixture $ssh): SiteConnection {
    $connection = new SiteConnection(['is_local'=>false]);
    (new ReflectionProperty(SiteConnection::class, 'pbx'))->setValue($connection, $ssh);
    return $connection;
};
$large = base64_encode(json_encode(['args'=>[['content'=>base64_encode(random_bytes(98304))]]], JSON_THROW_ON_ERROR));
$expect(strlen($large) > 131072, 'Fixture must cross Linux single-argument/environment bounds');
$ssh = new StreamRpcSshFixture(); $client = $connection($ssh);
$expect($client->execAgent(base64_encode('{}')) === '{"ok":true}' && count($ssh->commands) === 1 && $ssh->input === '', 'Small requests retain legacy agent compatibility');
$ssh->commands = [];
$expect($client->execAgent($large) === '{"ok":true}' && $ssh->input === $large && $ssh->eof
    && $ssh->commands === ['ucmp-rpc-capabilities','ucmp-rpc-stdin-v1'], 'Large payload must use only the SSH data stream after a capability probe');
$ssh = new StreamRpcSshFixture(); $ssh->supported = false;
try { $connection($ssh)->execAgent($large); $expect(false,'An old dispatcher cannot accept a large request'); }
catch (RuntimeException $error) { $expect(str_contains($error->getMessage(),'no payload was sent') && $ssh->input === '' && $ssh->commands === ['ucmp-rpc-capabilities'], 'Unsupported peers must be rejected before mutation dispatch'); }
foreach (['lost','timeout','nonzero','unknown_exit','oversized'] as $fault) {
    $ssh = new StreamRpcSshFixture(); $ssh->fault = $fault;
    $rejected = false;
    try { $connection($ssh)->execAgent($large); } catch (RuntimeException $error) { $rejected = true; }
    $expect($rejected && substr_count(implode('|',$ssh->commands),'ucmp-rpc-stdin-v1') === 1, 'Unverified stream outcome must fail without replaying the mutation');
}

// A readiness response may complete successfully before FreePBX exits 151.
// Exercise the same consumer for reads, mutations and receipt reconciliation,
// through both argv and stdin. Never dispatch the request more than once.
$id = str_repeat('a', 32);
foreach ([false, true] as $stream) {
    foreach ([151, false] as $exit) {
        foreach (['moduleStatus', 'saveSettingsLocal', 'rpcOperationStatusLocal'] as $method) {
            $request = ['rpc_version'=>2, 'method'=>$method, 'args'=>$method === 'rpcOperationStatusLocal' ? [$id] : []];
            if ($method === 'saveSettingsLocal') { $request['operation_id'] = $id; }
            if ($stream) { $request['padding'] = str_repeat('x', 100000); }
            $receipt = ['rpc_version'=>2, 'state'=>'completed', 'operation_id'=>$method === 'moduleStatus' ? '' : $id,
                'result'=>$method === 'moduleStatus' ? ['ok'=>true, 'version'=>'1.1.0rc13'] : null];
            $ssh = new StreamRpcSshFixture(); $ssh->completionStatus = $exit;
            $ssh->response = json_encode($receipt, JSON_THROW_ON_ERROR);
            $payload = base64_encode(json_encode($request, JSON_THROW_ON_ERROR));
            $expect($connection($ssh)->execAgent($payload) === $ssh->response, 'Finalized matching RPC result was discarded after CLI shutdown');
            $expect(count($ssh->commands) === ($stream ? 2 : 1), 'Completed RPC must not be replayed');
            foreach (['mismatched-id', 'missing-result', 'running', 'malformed', 'legacy', 'timeout'] as $fault) {
                $bad = $receipt;
                if ($fault === 'mismatched-id') { $bad['operation_id'] = str_repeat('b',32); }
                if ($fault === 'missing-result') { unset($bad['result']); }
                if ($fault === 'running') { $bad['state'] = 'running'; }
                $ssh = new StreamRpcSshFixture(); $ssh->completionStatus = $exit;
                $ssh->response = $fault === 'malformed' ? '{"rpc_version":2' : ($fault === 'legacy' ? '{"ok":true}' : json_encode($bad));
                $ssh->timedOut = $fault === 'timeout';
                $rejected = false;
                try { $connection($ssh)->execAgent($payload); } catch (RuntimeException $error) { $rejected = true; }
                $expect($rejected, 'Unconfirmed response was accepted: ' . $fault);
            }
            if ($method !== 'moduleStatus') {
                $ssh = new StreamRpcSshFixture(); $ssh->completionStatus = $exit;
                $ssh->response = json_encode(['rpc_version'=>2,'state'=>'failed','operation_id'=>$id,'message'=>'synthetic operation rejection']);
                $expect($connection($ssh)->execAgent($payload) === $ssh->response, 'Finalized failure must reach the RPC error handler intact');
            }
        }
    }
}

// Execute the actual generated agent; replace only the PBX bootstrap with a
// fixture dispatcher so no real credentials, services or PBX state are accessed.
$source = file_get_contents(dirname(__DIR__) . '/Ucmprovisioner.class.php');
$expect(preg_match('/\$agent = <<<\x27PHP\x27\R(.*?)\RPHP;/s', $source, $agentMatch) === 1, 'Generated agent must be extractable');
$expect(preg_match('/\$dispatcher = <<<\x27SH\x27\R(.*?)\RSH;/s', $source, $dispatcherMatch) === 1, 'Generated dispatcher must be extractable');
$expect(!str_contains($agentMatch[1],"\r")&&!str_contains($dispatcherMatch[1],"\r"), 'Generated Linux agent and dispatcher require LF source lines');
$root = sys_get_temp_dir() . '/ucmp-rpc-stream-' . bin2hex(random_bytes(8)); mkdir($root,0700);
$agentPath = $root . '/agent.php'; $dispatcherPath = $root . '/dispatcher.sh';
$stub = 'class FreePBX { public static function Ucmprovisioner() { return new class { public function remoteDispatch(string $payload): string { return json_encode(["size"=>strlen($payload),"sha256"=>hash("sha256",$payload)]); } }; } }';
$agent = str_replace("require_once '/etc/freepbx.conf';", $stub, $agentMatch[1]);
$run = static function (array $command, string $input, ?array $env = null) use ($root): array {
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, $env, ['bypass_shell'=>true]);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run isolated generated agent'); }
    if ($input !== '') { fwrite($pipes[0],$input); } fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process),$output,$error];
};
try {
    file_put_contents($agentPath,$agent);
    [$status,$output] = $run([PHP_BINARY,$agentPath,'--stdin-v1'],$large);
    $result = json_decode($output,true);
    $expect($status === 0 && ($result['size'] ?? 0) === strlen($large) && ($result['sha256'] ?? '') === hash('sha256',$large), 'Generated agent must receive exact large bytes through stdin');
    foreach (['',"YWJj\n",str_repeat('A',1048577)] as $invalid) {
        [$status,$output] = $run([PHP_BINARY,$agentPath,'--stdin-v1'],$invalid);
        $expect($status === 64 && $output === '', 'Agent must reject empty, malformed and oversized streams before PBX bootstrap');
    }
    [$status,$output] = $run([PHP_BINARY,$agentPath,base64_encode('{}')],'');
    $expect($status === 0, 'Generated agent retains its old command argument');
    if (PHP_OS_FAMILY === 'Linux') {
        file_put_contents($agentPath, preg_replace('~\A#!/usr/bin/php~', '#!' . PHP_BINARY, $agent)); chmod($agentPath,0700);
        file_put_contents($dispatcherPath,str_replace('/usr/local/libexec/ucmp-pbx-agent',escapeshellarg($agentPath),$dispatcherMatch[1]));
        [$status,$output] = $run(['/bin/sh',$dispatcherPath],$large,['SSH_ORIGINAL_COMMAND'=>'ucmp-rpc-stdin-v1']);
        $expect($status === 0 && (json_decode($output,true)['sha256'] ?? '') === hash('sha256',$large), 'Real forced dispatcher must transport large bytes without argv/environment expansion');
        [$status,$output] = $run(['/bin/sh',$dispatcherPath],'',['SSH_ORIGINAL_COMMAND'=>'ucmp-rpc-capabilities']);
        $expect($status === 0 && (json_decode($output,true)['rpc_stdin'] ?? 0) === 1, 'Dispatcher advertises the installed stream contract');
        [$status] = $run(['/bin/sh',$dispatcherPath],'',['SSH_ORIGINAL_COMMAND'=>'ucmp-rpc-stdin-v1 unexpected']);
        $expect($status === 126,'Dispatcher must reject command suffixes');
    } else { echo "Real forced-shell dispatcher acceptance requires Linux\n"; }
} finally {
    foreach ([$agentPath,$dispatcherPath] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($root);
}
echo "SSH RPC stream, legacy compatibility, capability and generated-agent input fixtures passed\n";
