<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/SoftkeyPolicy.php';
require_once dirname(__DIR__) . '/lib/SoftkeyPolicyGenerator.php';

use FreePBX\modules\Ucmprovisioner\SoftkeyPolicy;
use FreePBX\modules\Ucmprovisioner\SoftkeyPolicyGenerator;

$visualAssert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$base = [
    'schema' => 2,
    'states' => ['On Hook' => ['NewCall', 'Redial']],
    'definitions' => [],
    'annotations' => [],
    'unresolved' => [],
    'visual' => [
        'profiles' => [
            'Cisco|7841' => [
                'On Hook' => ['New Call', 'Redial', 'Recents'],
            ],
        ],
    ],
];

$normalized = SoftkeyPolicy::normalizeV2($base);
$visualAssert(
    $normalized['visual']['profiles']['Cisco|7841']['On Hook']
        === ['New Call', 'Redial', 'Recents'],
    'Model-scoped visual rows must survive schema-2 normalization exactly'
);

$patch = [
    'schema' => 2,
    'states' => [],
    'definitions' => [],
    'annotations' => [],
    'unresolved' => [],
    'visual' => [
        'profiles' => [
            'Cisco|7841' => [
                'On Hook' => null,
                'Ring In' => ['Answer', 'Decline', 'Ignore'],
            ],
            'Cisco|8845' => [
                'On Hook' => ['New Call', 'Redial', 'Forward all', 'Self View'],
            ],
        ],
    ],
];
$merged = SoftkeyPolicy::merge($base, $patch);
$visualAssert(
    $merged['visual']['profiles']['Cisco|7841']['On Hook'] === null
        && $merged['visual']['profiles']['Cisco|7841']['Ring In']
            === ['Answer', 'Decline', 'Ignore']
        && $merged['visual']['profiles']['Cisco|8845']['On Hook'][3] === 'Self View',
    'A higher template tier must replace, clear and add model-scoped visual rows without touching emitted states'
);
$visualAssert(
    $merged['states']['On Hook'] === ['NewCall', 'Redial'],
    'Visual preview overrides must not mutate the emitted call-state policy'
);

$generator = new SoftkeyPolicyGenerator();
$withoutVisual = $base;
unset($withoutVisual['visual']);
$visualAssert(
    $generator->softkeysXml($base) === $generator->softkeysXml($withoutVisual),
    'Editor-only visual data must have zero effect on generated softkeys.xml'
);

foreach ([
    ['visual' => ['profiles' => ['Cisco|7841' => ['On Hook' => ['']]]]],
    ['visual' => ['profiles' => ['Cisco|7841' => ['On Hook' => 'Recents']]]],
] as $invalidPatch) {
    $invalid = array_replace($withoutVisual, $invalidPatch);
    try {
        SoftkeyPolicy::normalizeV2($invalid);
        throw new RuntimeException('Malformed visual preview data was accepted');
    } catch (InvalidArgumentException $expected) {
        // Expected validation boundary.
    }
}

echo "SoftkeyVisualPreviewTest OK\n";
