<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/views/templates.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expect(
    str_contains($view, 'action="config.php?display=ucmprovisioner&amp;tab=templates&amp;site_id=<?php echo $siteId; ?>&amp;site_filter=<?php echo $siteId; ?>"'),
    'Template editor must post to the site-filtered list URL rather than retaining view=template in the browser URL'
);
$expect(
    str_contains($view, 'name="view" value="template"')
    && str_contains($view, 'name="action" value="savetemplate"'),
    'Template editor must retain the POST routing fields needed to save the template'
);
$expect(
    str_contains($module, "case 'savetemplate':")
    && str_contains($module, "unset(\$_REQUEST['view'], \$_REQUEST['id']);")
    && str_contains($module, "\$_REQUEST['site_filter'] = (string)\$templateSiteId;"),
    'Successful template saves must return to inventory while retaining the edited site'
);

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Template save navigation UI checks passed.\n";
