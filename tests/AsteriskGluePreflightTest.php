<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner {
    final class AsteriskGlueTestRuntime
    {
        public static array $responses = [];
        public static array $files = [];
        public static array $owners = [];
        public static array $groups = [];
        public static array $modes = [];
        public static array $writes = [];
        public static array $commands = [];
        public static array $unreadable = [];
        public static int $tempCounter = 0;
        public static int $failCommandAt = 0;
        public static int $commandCounter = 0;

        public static function reset(array $responses): void
        {
            self::$responses = $responses;
            self::$files = [];
            self::$owners = ['/etc/asterisk' => 996];
            self::$groups = ['/etc/asterisk' => 996];
            self::$modes = ['/etc/asterisk' => 0775];
            self::$writes = [];
            self::$commands = [];
            self::$unreadable = [];
            self::$tempCounter = 0;
            self::$failCommandAt = 0;
            self::$commandCounter = 0;
        }
    }

    function shell_exec(string $command)
    {
        foreach (AsteriskGlueTestRuntime::$responses as $needle => $response) {
            if (strpos($command, $needle) !== false) {
                return $response;
            }
        }
        return '';
    }

    function is_file(string $filename): bool
    {
        return array_key_exists($filename, AsteriskGlueTestRuntime::$files);
    }

    function file_get_contents(string $filename)
    {
        if (in_array($filename,AsteriskGlueTestRuntime::$unreadable,true)) { return false; }
        return AsteriskGlueTestRuntime::$files[$filename] ?? false;
    }

    function file_put_contents(string $filename, $data, int $flags = 0)
    {
        $content = (string)$data;
        if (!array_key_exists($filename, AsteriskGlueTestRuntime::$files)) {
            // Model a privileged CLI rebuild: its temporary file starts root-owned.
            AsteriskGlueTestRuntime::$owners[$filename] = 0;
            AsteriskGlueTestRuntime::$groups[$filename] = 0;
            AsteriskGlueTestRuntime::$modes[$filename] = 0600;
        }
        AsteriskGlueTestRuntime::$files[$filename] = $content;
        AsteriskGlueTestRuntime::$writes[] = ['path' => $filename, 'content' => $content, 'flags' => $flags];
        return strlen($content);
    }

    function tempnam(string $directory, string $prefix)
    {
        return rtrim($directory, '/') . '/' . $prefix . (++AsteriskGlueTestRuntime::$tempCounter);
    }

    function rename(string $from, string $to): bool
    {
        if (!array_key_exists($from, AsteriskGlueTestRuntime::$files)) {
            return false;
        }
        AsteriskGlueTestRuntime::$files[$to] = AsteriskGlueTestRuntime::$files[$from];
        AsteriskGlueTestRuntime::$owners[$to] = AsteriskGlueTestRuntime::$owners[$from];
        AsteriskGlueTestRuntime::$groups[$to] = AsteriskGlueTestRuntime::$groups[$from];
        AsteriskGlueTestRuntime::$modes[$to] = AsteriskGlueTestRuntime::$modes[$from];
        unset(AsteriskGlueTestRuntime::$files[$from]);
        unset(
            AsteriskGlueTestRuntime::$owners[$from],
            AsteriskGlueTestRuntime::$groups[$from],
            AsteriskGlueTestRuntime::$modes[$from]
        );
        return true;
    }

    function unlink(string $filename): bool
    {
        unset(AsteriskGlueTestRuntime::$files[$filename]);
        unset(
            AsteriskGlueTestRuntime::$owners[$filename],
            AsteriskGlueTestRuntime::$groups[$filename],
            AsteriskGlueTestRuntime::$modes[$filename]
        );
        return true;
    }

    function chmod(string $filename, int $permissions): bool
    {
        if (!array_key_exists($filename, AsteriskGlueTestRuntime::$files)) {
            return false;
        }
        AsteriskGlueTestRuntime::$modes[$filename] = $permissions;
        return true;
    }

    function fileowner(string $filename)
    {
        return AsteriskGlueTestRuntime::$owners[$filename] ?? false;
    }

    function filegroup(string $filename)
    {
        return AsteriskGlueTestRuntime::$groups[$filename] ?? false;
    }

    function chown(string $filename, $user): bool
    {
        if (!array_key_exists($filename, AsteriskGlueTestRuntime::$files)) {
            return false;
        }
        AsteriskGlueTestRuntime::$owners[$filename] = (int)$user;
        return true;
    }

    function chgrp(string $filename, $group): bool
    {
        if (!array_key_exists($filename, AsteriskGlueTestRuntime::$files)) {
            return false;
        }
        AsteriskGlueTestRuntime::$groups[$filename] = (int)$group;
        return true;
    }

    function exec(string $command, ?array &$output = null, ?int &$resultCode = null)
    {
        AsteriskGlueTestRuntime::$commands[] = $command;
        AsteriskGlueTestRuntime::$commandCounter++;
        $output = [];
        $resultCode = (
            AsteriskGlueTestRuntime::$failCommandAt > 0
            && AsteriskGlueTestRuntime::$commandCounter === AsteriskGlueTestRuntime::$failCommandAt
        ) ? 1 : 0;
        return '';
    }

    require_once dirname(__DIR__) . '/lib/AsteriskGlue.php';
}

namespace {
    use FreePBX\modules\Ucmprovisioner\AsteriskGlue;
    use FreePBX\modules\Ucmprovisioner\AsteriskGlueTestRuntime as Runtime;

    function preflightExpect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $cisco = [[
        'phone' => ['extension' => '301', 'family' => 'cisco_xml'],
        'model' => ['family' => 'cisco_xml'],
        'lines' => [
            ['featureId' => 21, 'speedDialNumber' => '302'],
            ['featureId' => 2, 'speedDialNumber' => '999'],
        ],
    ]];
    $yealink = [[
        'phone' => ['extension' => '401', 'family' => 'yealink'],
        'model' => ['family' => 'yealink'],
        'lines' => [],
    ]];
    $glue = new AsteriskGlue(new stdClass());
    $glueSource = (string)file_get_contents(dirname(__DIR__) . '/lib/AsteriskGlue.php');
    preflightExpect(!str_contains($glueSource, 'module show like chan_sip.so'), 'Config planning must never use the wedged Asterisk module-list probe');
    preflightExpect(str_contains($glueSource, '/usr/bin/timeout --signal=KILL 5'), 'Asterisk preflight commands must have a hard execution bound');

    Runtime::reset([
        'core show version' => 'Asterisk 20.20.0',
        'core show application SIPCiscoPage' => "No such command 'core show application SIPCiscoPage'",
        'sip show settings' => "Global Settings:\n  UDP Bindaddress: 0.0.0.0:5060",
    ]);
    $plan = $glue->plan($cisco);
    preflightExpect($plan['requires_patch'] === true, 'A Cisco fleet must declare that patched glue is required');
    preflightExpect($plan['managed_extensions'] === [301], 'Managed extensions must identify the Cisco endpoint receiving glue');
    preflightExpect($plan['subscribes'] === ['301' => ['302@ext-local']], 'Subscriptions must map each watcher to its actual BLF targets');
    preflightExpect(!str_contains($plan['sip_custom_post_block'], '999@'), 'Ordinary speed dials must not leak into Asterisk subscriptions');
    preflightExpect($plan['preflight']['eligible'] === false, 'A missing SIPCiscoPage application must make preflight ineligible');
    preflightExpect($plan['preflight']['chan_sip_loaded'] === true, 'A loaded chan_sip row should be recognized');

    $blocked = false;
    try {
        $glue->apply($cisco);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'SIPCiscoPage');
    }
    preflightExpect($blocked, 'Cisco apply must fail with the unified preflight reason');
    preflightExpect(Runtime::$writes === [], 'Cisco preflight failure must happen before the first Asterisk file write');
    preflightExpect(Runtime::$commands === [], 'Cisco preflight failure must happen before any Asterisk reload');

    Runtime::reset([
        'core show version' => 'Asterisk 20.20.0',
        'core show application SIPCiscoPage' => "  -= Info about application 'SIPCiscoPage' =-\n[Synopsis]\nCisco page",
        'sip show settings' => "No such command 'sip show settings'",
    ]);
    $preflight = $glue->patchPreflight();
    preflightExpect($preflight['eligible'] === false, 'SIPCiscoPage without a loaded chan_sip module must remain ineligible');
    preflightExpect(str_contains($preflight['warning'], 'chan_sip.so'), 'The failed check must be named in the preflight warning');

    Runtime::reset([
        'core show version' => 'Asterisk 20.20.0',
        'core show application SIPCiscoPage' => "  -= Info about application 'SIPCiscoPage' =-\n[Synopsis]\nCisco page",
        'sip show settings' => "Global Settings:\n  UDP Bindaddress: 0.0.0.0:5060",
    ]);
    $applied = $glue->apply($cisco);
    preflightExpect($applied['ok'] === true, 'An eligible Cisco fleet should still apply normally');
    preflightExpect($applied['written'] === [301], 'Apply results must report managed endpoints, not subscription targets');
    preflightExpect(count(Runtime::$writes) > 0, 'Eligible Cisco apply should reach managed file writes');
    preflightExpect(count(Runtime::$commands) === 2, 'Eligible Cisco apply should reload dialplan and chan_sip once each');
    foreach ([
        '/etc/asterisk/sip_custom_post.conf',
        '/etc/asterisk/extensions_override_freepbx.conf',
        '/etc/asterisk/extensions_custom.conf',
        '/etc/asterisk/sip_notify_custom.conf',
    ] as $path) {
        preflightExpect(
            (Runtime::$owners[$path] ?? null) === 996 && (Runtime::$groups[$path] ?? null) === 996,
            "A root-run rebuild must leave $path owned by the Asterisk directory account"
        );
        preflightExpect((Runtime::$modes[$path] ?? null) === 0660, "$path must remain group-readable after replacement");
    }

    Runtime::reset([
        'core show version' => 'Asterisk 20.20.0',
        'core show application SIPCiscoPage' => "  -= Info about application 'SIPCiscoPage' =-\n[Synopsis]\nCisco page",
        'sip show settings' => "Global Settings:\n  UDP Bindaddress: 0.0.0.0:5060",
    ]);
    $oldFiles = [
        '/etc/asterisk/sip_custom_post.conf' => "; existing sip\n",
        '/etc/asterisk/extensions_override_freepbx.conf' => "; existing hints\n",
        '/etc/asterisk/extensions_custom.conf' => "; existing custom\n",
        '/etc/asterisk/sip_notify_custom.conf' => "; existing notify\n",
    ];
    Runtime::$files = $oldFiles;
    Runtime::$failCommandAt = 2;
    $reloadFailed = false;
    try {
        $glue->apply($cisco);
    } catch (RuntimeException $exception) {
        $reloadFailed = str_contains($exception->getMessage(), 'sip reload');
    }
    preflightExpect($reloadFailed, 'A reload failure must fail the glue transaction');
    foreach ($oldFiles as $path => $content) {
        preflightExpect((Runtime::$files[$path] ?? null) === $content, "Reload failure must restore $path exactly");
    }
    preflightExpect(count(Runtime::$commands) === 4, 'Rollback must reload both restored Asterisk areas');

    foreach ([true, false] as $manualSip) {
        Runtime::reset([
            'core show version'=>'Asterisk 20.20.0',
            'core show application SIPCiscoPage'=>"  -= Info about application 'SIPCiscoPage' =-\n[Synopsis]\nCisco page",
            'sip show settings'=>"Global Settings:\n UDP Bindaddress: 0.0.0.0:5060",
        ]);
        $sip = $manualSip ? "[301](+)\nqualify=450\n" : "[301](+)\ncisco_usecallmanager=yes\n";
        $hint = $manualSip ? "exten => 301,hint,SIP/301,SIP/301\n" : "exten => 301,hint,Custom:operator-owned\n";
        Runtime::$files['/etc/asterisk/sip_custom_post.conf'] = $sip;
        Runtime::$files['/etc/asterisk/extensions_override_freepbx.conf'] = $hint;
        $glue->apply($cisco);
        $manualPath = $manualSip ? '/etc/asterisk/sip_custom_post.conf' : '/etc/asterisk/extensions_override_freepbx.conf';
        preflightExpect(str_contains(Runtime::$files[$manualPath], $manualSip ? $sip : $hint), 'Safe migration in another file must preserve the exact conflicting manual object');
        $safePath = $manualSip ? '/etc/asterisk/extensions_override_freepbx.conf' : '/etc/asterisk/sip_custom_post.conf';
        preflightExpect(substr_count(Runtime::$files[$safePath], $manualSip ? 'exten => 301,hint,' : '[301](+)') === 1, 'Safe object must consolidate into exactly one generated replacement');
    }

    Runtime::reset([]);
    $nonCiscoPlan = $glue->plan($yealink);
    preflightExpect($nonCiscoPlan['requires_patch'] === false, 'An all-non-Cisco fleet must not require the Cisco patch');
    $nonCiscoApply = $glue->apply($yealink);
    preflightExpect($nonCiscoApply['ok'] === true, 'An all-non-Cisco fleet must not be blocked by Cisco preflight');

    echo "Asterisk glue preflight guard passed\n";

    foreach (array_keys($oldFiles) as $unreadable) {
        foreach (['snapshot','apply'] as $operation) {
            Runtime::reset([
                'core show version'=>'Asterisk 20.20.0',
                'core show application SIPCiscoPage'=>'SIPCiscoPage',
                'sip show settings'=>'Global Settings:',
            ]);
            Runtime::$files=$oldFiles;Runtime::$unreadable=[$unreadable];$rejected=false;
            try { $operation==='snapshot' ? $glue->snapshot() : $glue->apply($cisco); }
            catch (RuntimeException $error) { $rejected=true; }
            preflightExpect($rejected && Runtime::$writes===[] && Runtime::$files===$oldFiles,
                'An unreadable custom file must stop before mutation and cannot become an empty recovery snapshot');
        }
    }
    Runtime::reset([]);
    foreach (["302\ninvalid=value",'302,999','302@other-context','302&Custom:other'] as $unsafe) {
        $invalid=$cisco;$invalid[0]['lines'][0]['speedDialNumber']=$unsafe;$rejected=false;
        try { $glue->plan($invalid); } catch (InvalidArgumentException) { $rejected=true; }
        preflightExpect($rejected,'BLF identifiers cannot inject another dialplan directive or provider');
    }
    $skip=new ReflectionMethod(AsteriskGlue::class,'skipExts');
    preflightExpect($skip->invoke($glue,['*78: conflict','301: conflict'])===['*78','301'],'Feature-code conflicts must remain excluded from managed output');
}
