<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/ApiCodec.php';

use FreePBX\modules\Ucmprovisioner\ApiCodec;

$secret = bin2hex(random_bytes(32));
$payload = ['timestamp'=>time(),'nonce'=>bin2hex(random_bytes(16)),'payload'=>['method'=>'moduleStatus','args'=>[]]];
$envelope = ApiCodec::seal($payload, $secret);
if (ApiCodec::open($envelope, $secret) !== $payload) {
    throw new RuntimeException('Encrypted API envelope did not round-trip');
}
$outer = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
$cipher = base64_decode((string)$outer['data'], true);
$cipher[0] = chr(ord($cipher[0]) ^ 1);
$outer['data'] = base64_encode($cipher);
$tampered = json_encode($outer, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
try {
    ApiCodec::open($tampered, $secret);
    throw new RuntimeException('Tampered API envelope was accepted');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Tampered API envelope was accepted') { throw $e; }
}

$maximum = ['value' => str_repeat('x', ApiCodec::MAX_PLAINTEXT_BYTES - strlen('{"value":""}'))];
$maximumEnvelope = ApiCodec::seal($maximum, $secret);
if (strlen($maximumEnvelope) > ApiCodec::MAX_ENVELOPE_BYTES || ApiCodec::open($maximumEnvelope, $secret) !== $maximum) {
    throw new RuntimeException('Maximum-size plaintext did not round-trip through the wire envelope');
}
try {
    ApiCodec::seal(['value' => $maximum['value'] . 'x'], $secret);
    throw new RuntimeException('Oversized plaintext was accepted');
} catch (InvalidArgumentException $e) {
    // Expected before encryption.
}
try {
    ApiCodec::open(str_repeat('x', ApiCodec::MAX_ENVELOPE_BYTES + 1), $secret);
    throw new RuntimeException('Oversized wire envelope was accepted');
} catch (InvalidArgumentException $e) {
    // Expected before decoding.
}

echo "Encrypted API codec fixtures passed\n";
