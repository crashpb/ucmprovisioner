<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$templates = (string)file_get_contents($root . '/views/templates.php');
$module = (string)file_get_contents($root . '/Ucmprovisioner.class.php');
$css = (string)file_get_contents($root . '/assets/css/ucmprovisioner.css');
$expectTemplateUi = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};

$expectTemplateUi(
    str_contains($templates, 'id="ucmp-templates-preview-v2"')
        && str_contains($templates, 'id="ucmp-template-editor-v2"')
        && str_contains($css, '#ucmp-templates-preview-v2')
        && str_contains($css, '#ucmp-template-editor-v2'),
    'Template inventory and editor must use isolated Media/Phones-style workbench roots'
);
$expectTemplateUi(
    str_contains($templates, 'Handset-visible preview')
        && str_contains($templates, 'Provisioned call-state policy')
        && str_contains($templates, 'Preview only')
        && str_contains($templates, 'Emitted at Build'),
    'The editor must visibly separate handset observations from provisioned softkey data'
);
$expectTemplateUi(
    str_contains($templates, 'softkeyPolicy.visual.profiles')
        && str_contains($templates, 'automaticVisualItems')
        && str_contains($templates, 'Exact observed handset row')
        && str_contains($templates, 'Visible label, for example Recents')
        && str_contains($templates, 'ucm-visual-up')
        && str_contains($templates, 'ucm-visual-remove'),
    'Model/state visual overrides must be persistent, arbitrary, ordered and removable'
);
$expectTemplateUi(
    str_contains($templates, "source:'firmware'")
        && str_contains($templates, "source:'observed'")
        && str_contains($templates, "source:'template'")
        && str_contains($templates, 'these labels have no keyID, tag, eventID or helpID'),
    'Every preview row must disclose its source and the non-provisioning boundary'
);
$expectTemplateUi(
    str_contains($templates, "recentsSoftKey")
        && str_contains($templates, "Navigation Up")
        && str_contains($templates, "Self View")
        && str_contains($templates, "Show/Hide video"),
    'Verified Recents and video firmware cues must be visible without becoming fake XML definitions'
);
$expectTemplateUi(
    str_contains($templates, 'id="ucm-template-filter"')
        && str_contains($templates, 'data-template-row')
        && str_contains($templates, 'visual state override(s)')
        && str_contains($templates, 'action=duptemplate')
        && str_contains($templates, 'name="action" value="deltemplate"')
        && str_contains($templates, 'method="post"')
        && str_contains($module, 'uniq_ucmp_templates_site_name'),
    'The redesigned inventory must retain filtering, edit, duplicate, delete and visual-layer status'
);

echo "TemplateVisualWorkbenchTest OK\n";
