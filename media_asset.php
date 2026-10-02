<?php
/** Public, read-only phone media endpoint. No configuration or directory listing is exposed. */
require_once __DIR__ . '/lib/MediaUrl.php';

use FreePBX\modules\Ucmprovisioner\MediaUrl;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD'], true)) { header('Allow: GET, HEAD'); http_response_code(405); exit; }

try {
    $route = MediaUrl::parsePathInfo((string)($_SERVER['PATH_INFO'] ?? ''));
    if ($route === null) {
        // Backward compatibility for URLs emitted by releases before 0.17.
        $family = (string)($_GET['family'] ?? '');
        $model = (string)($_GET['model'] ?? 'all');
        $file = (string)($_GET['file'] ?? '');
        $route = MediaUrl::parsePathInfo(
            '/' . rawurlencode($family) . '/' . rawurlencode($model) . '/' . rawurlencode($file)
        );
    }
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    exit;
}
['family'=>$family, 'model'=>$model, 'file'=>$file] = $route;
$path = '/var/lib/asterisk/ucmprovisioner/media/' . $family . '/' . $model . '/' . $file;
foreach ([$path, dirname($path), dirname($path,2), dirname($path,3)] as $component) {
    if (is_link($component)) { http_response_code(404); exit; }
}
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit; }
$types = ['png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','bmp'=>'image/bmp','wav'=>'audio/wav','mp3'=>'audio/mpeg','ogg'=>'audio/ogg'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if (!isset($types[$ext])) { http_response_code(415); exit; }
$size = filesize($path);
$hash = hash_file('sha256', $path);
if (!is_int($size) || !is_string($hash)) { http_response_code(500); exit; }
$etag = '"' . $hash . '"';
header('Cache-Control: public, no-cache, must-revalidate');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $types[$ext]); header('Content-Length: ' . $size); header('X-Content-Type-Options: nosniff');
if ($method !== 'HEAD') { readfile($path); }
