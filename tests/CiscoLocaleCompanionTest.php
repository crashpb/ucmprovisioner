<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ModelCatalog.php';
require_once __DIR__ . '/../lib/TemplateEngine.php';
require_once __DIR__ . '/../lib/TftpStore.php';

use FreePBX\modules\Ucmprovisioner\TemplateEngine;
use FreePBX\modules\Ucmprovisioner\TftpStore;

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

final class CiscoLocaleSftpStub
{
    public function __construct(private bool $complete = true, private bool $exactCase = true) {}

    public function rawlist(string $path): array
    {
        if ($path === '/tftpboot') {
            return [
                '.'=>['type'=>2], '..'=>['type'=>2],
                ($this->exactCase ? 'English_United_States' : 'english_united_states')=>['type'=>2],
                ($this->exactCase ? 'Iran' : 'iran')=>['type'=>2],
            ];
        }
        if (strcasecmp($path, '/tftpboot/English_United_States') === 0) {
            return [
                '.'=>['type'=>2], '..'=>['type'=>2],
                'mk-sip.jar'=>['type'=>1,'size'=>$this->complete ? 4096 : 0],
            ];
        }
        if (strcasecmp($path, '/tftpboot/Iran') === 0) {
            return [
                '.'=>['type'=>2], '..'=>['type'=>2],
                'g3-tones.xml'=>['type'=>1,'size'=>$this->complete ? 2048 : 0],
            ];
        }
        return [];
    }
}

final class CiscoLocaleConnectionStub
{
    public function __construct(private CiscoLocaleSftpStub $sftp) {}
    public function dockerSftp(): CiscoLocaleSftpStub { return $this->sftp; }
    public function tftpRoot(): string { return '/tftpboot'; }
}

final class CiscoLocaleUcmStub
{
    public function __construct(private CiscoLocaleSftpStub $sftp) {}
    public function localSiteId(): int { return 1; }
    public function siteConnection(int $siteId): CiscoLocaleConnectionStub { return new CiscoLocaleConnectionStub($this->sftp); }
}

$inventory = (new TftpStore(new CiscoLocaleUcmStub(new CiscoLocaleSftpStub())))->handle([
    'op'=>'cisco_locale_inventory', 'site_id'=>1, 'user_locale'=>'English_United_States', 'network_locale'=>'Iran',
]);
$sip41 = $inventory['profiles']['cisco:sip41-locale'] ?? [];
$expect(($sip41['status'] ?? '') === 'complete', 'SIP41 locale inventory must require and accept both exact phone-facing paths');
$expect(
    array_column($sip41['files'] ?? [], 'path') === ['English_United_States/mk-sip.jar','Iran/g3-tones.xml'],
    'SIP41 locale inventory returned the wrong requested paths'
);

$badInventory = (new TftpStore(new CiscoLocaleUcmStub(new CiscoLocaleSftpStub(false, false))))->handle([
    'op'=>'cisco_locale_inventory', 'site_id'=>1, 'user_locale'=>'English_United_States', 'network_locale'=>'Iran',
]);
$expect(
    ($badInventory['profiles']['cisco:sip41-locale']['status'] ?? '') === 'incomplete',
    'A zero-byte or case-mismatched SIP41 companion must fail closed'
);

$resolver = new ReflectionMethod(TemplateEngine::class, 'resolveCiscoLocale');
$resolver->setAccessible(true);
$module = new class($inventory) {
    public int $calls = 0;
    public function __construct(private array $inventory) {}
    public function tftpOp(array $request): array
    {
        $this->calls++;
        if (($request['op'] ?? '') !== 'cisco_locale_inventory') {
            throw new RuntimeException('Locale resolver used the wrong inventory operation');
        }
        return $this->inventory;
    }
};
$settings = ['cisco_networkLocale'=>'Iran','cisco_userLocale'=>'English_United_States'];
$resolver->invokeArgs(new TemplateEngine($module), [1, ['vendor'=>'Cisco','model'=>'7941','family'=>'cisco_xml'], &$settings]);
$expect(($settings['_cisco_locale_usable'] ?? null) === true, 'A complete SIP41 locale bundle must permit the configured network locale');

$sip42Settings = ['cisco_networkLocale'=>'Iran','cisco_userLocale'=>'English_United_States'];
$resolver->invokeArgs(new TemplateEngine($module), [1, ['vendor'=>'Cisco','model'=>'7942','family'=>'cisco_xml'], &$sip42Settings]);
$expect(($sip42Settings['_cisco_locale_usable'] ?? null) === true, 'SIP42 must resolve its own complete locale profile instead of borrowing SIP41 state');

$missingModule = new class($badInventory) {
    public function __construct(private array $inventory) {}
    public function tftpOp(array $request): array { return $this->inventory; }
};
$missingSettings = ['cisco_networkLocale'=>'Iran','cisco_userLocale'=>'English_United_States'];
$resolver->invokeArgs(new TemplateEngine($missingModule), [1, ['vendor'=>'Cisco','model'=>'7961','family'=>'cisco_xml'], &$missingSettings]);
$expect(
    ($missingSettings['_cisco_locale_usable'] ?? null) === false
    && str_contains((string)($missingSettings['_cisco_locale_warning'] ?? ''), 'English_United_States/mk-sip.jar'),
    'An incomplete SIP41 bundle must omit the locale and identify the missing phone-facing path'
);

$unverifiedModule = new class {
    public int $calls = 0;
    public function tftpOp(array $request): array { $this->calls++; return ['ok'=>true]; }
};
$unverifiedSettings = ['cisco_networkLocale'=>'Iran'];
$resolver->invokeArgs(new TemplateEngine($unverifiedModule), [1, ['vendor'=>'Cisco','model'=>'8821','family'=>'cisco_xml'], &$unverifiedSettings]);
$expect(
    ($unverifiedSettings['_cisco_locale_usable'] ?? null) === false
    && $unverifiedModule->calls === 0,
    'A Cisco XML branch without a documented locale profile must not borrow another family or probe a guessed path'
);

$firmwareView = (string)file_get_contents(dirname(__DIR__) . '/views/firmware.php');
$expect(
    str_contains($firmwareView, 'Cisco locale companions by firmware family')
    && str_contains($firmwareView, 'ucm-locale-profile')
    && str_contains($firmwareView, 'id="ucm-locale-upload"')
    && str_contains($firmwareView, 'sync_cisco_locale_bundle')
    && str_contains($firmwareView, 'g3-tones\\.xml'),
    'Firmware Manager must expose profile-scoped upload, inventory and site synchronization for Cisco locale companions'
);

echo "Cisco locale companion fixtures passed\n";
