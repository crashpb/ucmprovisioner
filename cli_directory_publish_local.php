<?php
/** Rebuild only this PBX's phone-facing directory cache and URL settings. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once '/etc/freepbx.conf';

$ucm = \FreePBX::Ucmprovisioner();
$service = new \FreePBX\modules\Ucmprovisioner\DirectoryService($ucm);
$siteId = $ucm->localSiteId();
$site = $ucm->getSite($siteId);
if (!$site) { throw new RuntimeException('Local site is not configured'); }
$token = $ucm->directoryTokenForSite($site);
$payload = array_merge([[
    '__ucmp_version'=>4,
    '__ucmp_layout'=>$service->phonebookLayout(),
    '__ucmp_phonebooks'=>$service->phonebooks(),
    '__ucmp_source_freshness'=>$service->acceptedSourceFreshness(),
]], $service->entries(true, 0));
$result = $service->publishSnapshotLocal($payload, $token);
fwrite(STDOUT, (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
exit(!empty($result['ok']) ? 0 : 1);
