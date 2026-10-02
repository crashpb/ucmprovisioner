<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (!is_file($root . '/composer.lock')) {
    throw new RuntimeException('Release dependencies are not locked');
}
require $root . '/vendor/autoload.php';

foreach ([
    'phpseclib/phpseclib' => \phpseclib3\Net\SSH2::class,
    'paragonie/constant_time_encoding' => \ParagonIE\ConstantTime\Base64::class,
] as $package => $class) {
    if (!class_exists($class)) {
        throw new RuntimeException("$package is absent from the release autoloader");
    }
    if (!\Composer\InstalledVersions::isInstalled($package)) {
        throw new RuntimeException("$package is absent from Composer package metadata");
    }
}

$roundTrip = \ParagonIE\ConstantTime\Base64::decode(
    \ParagonIE\ConstantTime\Base64::encode('ucmprovisioner')
);
if ($roundTrip !== 'ucmprovisioner') {
    throw new RuntimeException('Constant-time codec dependency failed its round trip');
}

echo "Locked SSH and constant-time dependencies are complete\n";
