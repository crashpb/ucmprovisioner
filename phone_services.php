<?php
/** Public token-protected, read-only phone-service endpoint. */
try {
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if (!in_array($method,['GET','HEAD'],true)) {
        http_response_code(405); header('Allow: GET, HEAD'); exit;
    }
    // This route authenticates with its phone token, independently of GUI login.
    // Reuse the installed BMO registry so service rendering and Core share state.
    $bootstrap_settings['freepbx_auth'] = false;
    $restrict_mods = ['core'=>true, 'ucmprovisioner'=>true];
    require_once '/etc/freepbx.conf';
    $path=(string)($_SERVER['PATH_INFO']??'');
    if (strlen($path)>512) { throw new \InvalidArgumentException('Phone-service route is too long'); }
    $encoded=explode('/',trim($path,'/'));
    if (count($encoded)>7) { throw new \InvalidArgumentException('Phone-service route has too many segments'); }
    $parts=[];
    foreach ($encoded as $part) {
        if ($part==='' || strlen($part)>128) { throw new \InvalidArgumentException('Invalid phone-service route'); }
        $parts[]=rawurldecode($part);
    }
    [$token,$format,$mac]=array_pad(array_slice($parts,0,3),3,'');
    $ucm=\FreePBX::Ucmprovisioner();
    $storedToken=$ucm->phoneServicesToken(false);
    if (!preg_match('/^[A-Fa-f0-9]{32,128}$/D',$token) || $storedToken==='' || !hash_equals($storedToken,$token)) { http_response_code(403); exit; }
    $renderer=new \FreePBX\modules\Ucmprovisioner\PhoneServiceService($ucm);
    $result=$renderer->render((string)$format,strtolower((string)$mac),array_slice($parts,3));
    header('Content-Type: '.$result['content_type']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=60');
    if (strlen((string)$result['body'])>262144) { throw new \RuntimeException('Phone-service response exceeded its safe output limit'); }
    if ($method!=='HEAD') { echo $result['body']; }
} catch (\InvalidArgumentException $e) {
    http_response_code(400); header('Content-Type: text/plain; charset=UTF-8'); echo $e->getMessage();
} catch (\Throwable $e) {
    http_response_code(str_contains($e->getMessage(),'missing')||str_contains($e->getMessage(),'disabled')?404:503);
    header('Content-Type: text/plain; charset=UTF-8'); echo 'Phone service is unavailable.';
}
