<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/IdentityLabelFormatter.php';

use FreePBX\modules\Ucmprovisioner\IdentityLabelFormatter;

$identity = [
    'display_name'=>'Hamed Haghani',
    'given_name'=>'Hamed',
    'surname'=>'Haghani',
    'label'=>'H.Haghani',
    'extension'=>'4019',
    'department'=>'IT',
];
$outputs = IdentityLabelFormatter::outputs($identity, [
    'phone_label'=>'<{initial}.{surname}>',
    'line_label'=>'{display} ({extension})',
    'blf_label'=>'{label}',
    'directory_label'=>'{department} - {display}',
], ['site'=>'SITE-A']);

if ($outputs['phone_label'] !== '<H.Haghani>'
    || $outputs['line_label'] !== 'Hamed Haghani (4019)'
    || $outputs['directory_label'] !== 'IT - Hamed Haghani'
) {
    fwrite(STDERR, "Identity pattern output mismatch\n");
    exit(1);
}

$fallback = IdentityLabelFormatter::outputs([
    'display_name'=>'John Doe',
    'label'=>'',
    'extension'=>'100',
]);
if ($fallback['phone_label'] !== 'John Doe') {
    fwrite(STDERR, "Identity fallback output mismatch\n");
    exit(1);
}

foreach (['{unknown}', '{display', str_repeat('x', 129), "bad\nvalue"] as $invalid) {
    try {
        IdentityLabelFormatter::validatePattern($invalid);
        fwrite(STDERR, "Invalid identity pattern was accepted\n");
        exit(1);
    } catch (\InvalidArgumentException $e) {
    }
}

echo "Identity label formatter assertions passed\n";
