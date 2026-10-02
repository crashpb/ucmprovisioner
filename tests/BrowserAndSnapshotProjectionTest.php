<?php
declare(strict_types=1);
interface BMO {}
class FreePBX_Helpers {}
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';

$class = new ReflectionClass(\FreePBX\modules\Ucmprovisioner::class);
$ucm = $class->newInstanceWithoutConstructor();
$sanitize = $class->getMethod('sanitizeSnapshotData');
$phone = ['id'=>1,'label'=>'Public label','overrides'=>json_encode(['settings'=>[
    'cisco_phone_password'=>'fixture-private-value',
    'nested'=>['safe'=>'retained','credential'=>'private-nested'],
    'url'=>'https://user:pass@example.invalid/path',
    'url2'=>'https://example.invalid/path?%74oken=private',
    'safe_url'=>'https://example.invalid/logo.bmp',
]], JSON_THROW_ON_ERROR), 'lines_json'=>'[{"account":2,"extension":"402"}]'];
foreach (['getPhoneLocal','getPhonesLocal'] as $method) {
    $value = $method === 'getPhoneLocal' ? $phone : [$phone];
    $clean = $sanitize->invoke($ucm, $method, $value);
    $encoded = json_encode($clean, JSON_THROW_ON_ERROR);
    if (str_contains($encoded, 'fixture-private-value') || str_contains($encoded, 'private-nested')
        || str_contains($encoded, 'user:pass') || str_contains($encoded, '%74oken')) {
        throw new RuntimeException('Structured phone snapshot leaked a secret or credential-bearing URL');
    }
    $row = $method === 'getPhoneLocal' ? $clean : $clean[0];
    $overrides = json_decode($row['overrides'], true, 32, JSON_THROW_ON_ERROR);
    if ($overrides['settings']['nested']['safe'] !== 'retained' || $overrides['settings']['safe_url'] !== 'https://example.invalid/logo.bmp'
        || json_decode($row['lines_json'], true)[0]['extension'] !== '402') {
        throw new RuntimeException('Snapshot projection removed benign configuration');
    }
}
$name = '</script><script>fixture()</script>&"\'';
$json = \FreePBX\modules\Ucmprovisioner::browserJson(['name'=>$name], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$rendered = '<script>var catalog=' . $json . ';</script>';
if (substr_count($rendered, '</script>') !== 1 || json_decode($json, true)['name'] !== $name) {
    throw new RuntimeException('Inline catalog JSON can terminate script or corrupt the stored name');
}
foreach (['templates','groups','settings','media','tftp','phones'] as $view) {
    if (str_contains((string)file_get_contents(dirname(__DIR__) . '/views/' . $view . '.php'), 'json_encode(')) {
        throw new RuntimeException('An owned view bypassed the HTML-safe JSON encoder');
    }
}
echo "Browser JSON and structured snapshot secret-projection fixtures passed\n";
