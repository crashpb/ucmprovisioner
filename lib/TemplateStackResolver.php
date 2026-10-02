<?php
/**
 * Deterministic provisioning-group/template stack resolution.
 *
 * This class is deliberately storage-agnostic: callers load rows, then pass one
 * leaf group and one confirmed device profile. It resolves only ordering,
 * applicability and top-level setting precedence; typed button/account/softkey
 * mergers remain the responsibility of TemplateEngine.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/ModelCatalog.php';

final class TemplateStackResolver
{
    /**
     * @param int   $leafGroupId One directly assigned leaf group.
     * @param array $groups      Group rows containing id and optional parent_group_id.
     * @param array $assignments Rows containing group_id, template_id, position and enabled.
     * @param array $templates   Template rows containing id, settings and target_* constraints.
     * @param array $device      Confirmed vendor/family/series_key/model/role profile.
     */
    public function resolve(
        int $leafGroupId,
        array $groups,
        array $assignments,
        array $templates,
        array $device
    ): array {
        if ($leafGroupId <= 0) {
            throw new \InvalidArgumentException('Choose one leaf provisioning group');
        }

        $groupsById = $this->indexById($groups, 'group');
        $templatesById = $this->indexById($templates, 'template');
        $path = $this->groupPath($leafGroupId, $groupsById);
        $byGroup = $this->assignmentsByGroup($assignments, $templatesById);

        $effective = [];
        $applied = [];
        $trace = [];

        foreach ($path as $group) {
            $groupId = (int)$group['id'];
            foreach ($byGroup[$groupId] ?? [] as $assignment) {
                $templateId = (int)($assignment['template_id'] ?? 0);
                $baseTrace = [
                    'group_id' => $groupId,
                    'group_name' => (string)($group['name'] ?? ('Group ' . $groupId)),
                    'template_id' => $templateId,
                    'position' => (int)($assignment['position'] ?? 0),
                ];

                if (array_key_exists('enabled', $assignment) && empty($assignment['enabled'])) {
                    $trace[] = $baseTrace + [
                        'template_name' => $this->templateName($templatesById[$templateId] ?? null, $templateId),
                        'status' => 'skipped',
                        'reason' => 'assignment is disabled',
                    ];
                    continue;
                }
                if ($templateId <= 0 || !isset($templatesById[$templateId])) {
                    $trace[] = $baseTrace + [
                        'template_name' => $this->templateName(null, $templateId),
                        'status' => 'skipped',
                        'reason' => 'assigned template was not found',
                    ];
                    continue;
                }

                $template = $templatesById[$templateId];
                $reasons = array_merge(
                    $this->assignmentMismatchReasons($assignment, $device),
                    $this->mismatchReasons($template, $device)
                );
                if ($reasons) {
                    $trace[] = $baseTrace + [
                        'template_name' => $this->templateName($template, $templateId),
                        'status' => 'skipped',
                        'reason' => implode('; ', $reasons),
                    ];
                    continue;
                }

                $settings = $this->templateSettings($template);
                foreach ($settings as $key => $value) {
                    if ($value === null) {
                        unset($effective[$key]);
                    } else {
                        $effective[$key] = $value;
                    }
                }
                $applied[] = [
                    'group_id' => $groupId,
                    'template_id' => $templateId,
                    'template_name' => $this->templateName($template, $templateId),
                    'position' => (int)($assignment['position'] ?? 0),
                ];
                $trace[] = $baseTrace + [
                    'template_name' => $this->templateName($template, $templateId),
                    'status' => 'applied',
                    'reason' => $this->hasConstraints($template)
                        ? 'all specified applicability constraints matched'
                        : 'generic template has no applicability constraints',
                ];
            }
        }

        return [
            'leaf_group_id' => $leafGroupId,
            'group_path' => array_map(static fn(array $group): array => [
                'id' => (int)$group['id'],
                'name' => (string)($group['name'] ?? ('Group ' . (int)$group['id'])),
            ], $path),
            'applied' => $applied,
            'applied_template_ids' => array_column($applied, 'template_id'),
            'settings' => $effective,
            'trace' => $trace,
        ];
    }

    private function indexById(array $rows, string $kind): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int)($row['id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException("Every $kind row needs a positive id");
            }
            $id = (int)$row['id'];
            if (isset($indexed[$id])) {
                throw new \InvalidArgumentException("Duplicate $kind id $id");
            }
            $indexed[$id] = $row;
        }
        return $indexed;
    }

    private function groupPath(int $leafGroupId, array $groupsById): array
    {
        if (!isset($groupsById[$leafGroupId])) {
            throw new \InvalidArgumentException("Leaf provisioning group $leafGroupId was not found");
        }

        $path = [];
        $seen = [];
        $currentId = $leafGroupId;
        while ($currentId > 0) {
            if (isset($seen[$currentId])) {
                $cycle = array_slice(array_column($path, 'id'), $seen[$currentId]);
                $cycle[] = $currentId;
                throw new \RuntimeException('Provisioning group cycle detected: ' . implode(' -> ', $cycle));
            }
            if (!isset($groupsById[$currentId])) {
                throw new \RuntimeException("Provisioning group parent $currentId was not found");
            }

            $seen[$currentId] = count($path);
            $group = $groupsById[$currentId];
            $path[] = $group;
            $parentId = (int)($group['parent_group_id'] ?? 0);
            if ($parentId > 0 && isset($groupsById[$parentId])) {
                $siteId = (int)($group['site_id'] ?? 0);
                $parentSiteId = (int)($groupsById[$parentId]['site_id'] ?? 0);
                if ($siteId > 0 && $parentSiteId > 0 && $siteId !== $parentSiteId) {
                    throw new \RuntimeException("Provisioning group $currentId cannot inherit from a different site");
                }
            }
            $currentId = $parentId;
        }
        return array_reverse($path);
    }

    private function assignmentsByGroup(array $assignments, array $templatesById): array
    {
        $byGroup = [];
        foreach (array_values($assignments) as $order => $assignment) {
            if (!is_array($assignment) || (int)($assignment['group_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Every template assignment needs a positive group_id');
            }
            $assignment['_input_order'] = $order;
            $byGroup[(int)$assignment['group_id']][] = $assignment;
        }
        foreach ($byGroup as &$rows) {
            usort($rows, function (array $left, array $right) use ($templatesById): int {
                $specificity = $this->assignmentSpecificity($left, $templatesById)
                    <=> $this->assignmentSpecificity($right, $templatesById);
                if ($specificity !== 0) {
                    return $specificity;
                }
                $position = (int)($left['position'] ?? 0) <=> (int)($right['position'] ?? 0);
                if ($position !== 0) {
                    return $position;
                }
                $leftId = (int)($left['id'] ?? 0);
                $rightId = (int)($right['id'] ?? 0);
                if ($leftId > 0 && $rightId > 0 && $leftId !== $rightId) {
                    return $leftId <=> $rightId;
                }
                return (int)$left['_input_order'] <=> (int)$right['_input_order'];
            });
        }
        unset($rows);
        return $byGroup;
    }

    /** Broad policy first, firmware/config compatibility next, exact model last. */
    private function assignmentSpecificity(array $assignment, array $templatesById): int
    {
        $template = $templatesById[(int)($assignment['template_id'] ?? 0)] ?? [];
        if ((int)($assignment['model_id'] ?? 0) > 0
            || trim((string)($assignment['model'] ?? '')) !== ''
            || (int)($template['target_model_id'] ?? 0) > 0
            || trim((string)($template['target_model'] ?? '')) !== '') {
            return 300;
        }
        $series = trim((string)($assignment['series'] ?? ($template['target_series'] ?? '')));
        if ($series !== '') {
            return ModelCatalog::isFirmwareTemplateScope($series) ? 200 : 100;
        }
        return 0;
    }

    private function mismatchReasons(array $template, array $device): array
    {
        $reasons = [];
        $this->compareString($reasons, 'vendor', $template['target_vendor'] ?? '', $device['vendor'] ?? '');
        $this->compareString($reasons, 'family', $template['target_family'] ?? '', $device['family'] ?? '');
        $this->compareScope($reasons, 'series', $template['target_series'] ?? '', $device);
        $this->compareString($reasons, 'role', $template['target_role'] ?? '', $device['role'] ?? '');

        $targetModelId = (int)($template['target_model_id'] ?? 0);
        if ($targetModelId > 0 && $targetModelId !== (int)($device['model_id'] ?? 0)) {
            $reasons[] = sprintf(
                'model id requires %d; phone is %s',
                $targetModelId,
                (int)($device['model_id'] ?? 0) > 0 ? (string)(int)$device['model_id'] : 'unset'
            );
        }

        $targetModel = trim((string)($template['target_model'] ?? ''));
        if ($targetModel !== '') {
            $model = trim((string)($device['model'] ?? ''));
            $actual = strpos($targetModel, '|') !== false
                ? trim((string)($device['vendor'] ?? '')) . '|' . $model
                : $model;
            if (strcasecmp($targetModel, $actual) !== 0) {
                $reasons[] = sprintf(
                    'model requires %s; phone is %s',
                    $targetModel,
                    $actual !== '' && $actual !== '|' ? $actual : 'unset'
                );
            }
        }
        return $reasons;
    }

    /** Legacy template_map rows may carry an additional assignment scope. */
    private function assignmentMismatchReasons(array $assignment, array $device): array
    {
        $reasons = [];
        $this->compareString($reasons, 'assignment vendor', $assignment['vendor'] ?? '', $device['vendor'] ?? '');
        $this->compareString($reasons, 'assignment family', $assignment['family'] ?? '', $device['family'] ?? '');
        $this->compareScope($reasons, 'assignment series', $assignment['series'] ?? '', $device);
        $this->compareString($reasons, 'assignment role', $assignment['role'] ?? '', $device['role'] ?? '');
        $modelId = (int)($assignment['model_id'] ?? 0);
        if ($modelId > 0 && $modelId !== (int)($device['model_id'] ?? 0)) {
            $reasons[] = sprintf(
                'assignment model id requires %d; phone is %s',
                $modelId,
                (int)($device['model_id'] ?? 0) > 0 ? (string)(int)$device['model_id'] : 'unset'
            );
        }
        $model = trim((string)($assignment['model'] ?? ''));
        if ($model !== '' && strcasecmp($model, (string)($device['model'] ?? '')) !== 0) {
            $reasons[] = sprintf(
                'assignment model requires %s; phone is %s',
                $model,
                trim((string)($device['model'] ?? '')) ?: 'unset'
            );
        }
        return $reasons;
    }

    private function compareString(array &$reasons, string $label, $expected, $actual): void
    {
        $expected = trim((string)$expected);
        if ($expected === '') {
            return;
        }
        $actual = trim((string)$actual);
        if (strcasecmp($expected, $actual) !== 0) {
            $reasons[] = sprintf(
                '%s requires %s; phone is %s',
                $label,
                $expected,
                $actual !== '' ? $actual : 'unset'
            );
        }
    }

    private function compareScope(array &$reasons, string $label, $expected, array $device): void
    {
        $expected = trim((string)$expected);
        if ($expected === '' || ModelCatalog::templateScopeMatches($expected, $device)) { return; }
        $actual = implode(', ', ModelCatalog::templateScopeKeys(
            (string)($device['vendor'] ?? ''),
            (string)($device['model'] ?? ''),
            (string)($device['family'] ?? ''),
            (string)($device['series_key'] ?? ($device['series'] ?? '')),
            (string)($device['firmware_profile'] ?? '')
        ));
        $reasons[] = sprintf('%s requires %s; phone scopes are %s', $label, $expected, $actual ?: 'unset');
    }

    private function hasConstraints(array $template): bool
    {
        foreach (['target_vendor', 'target_family', 'target_series', 'target_model', 'target_model_id', 'target_role'] as $key) {
            if (trim((string)($template[$key] ?? '')) !== '' && (string)($template[$key] ?? '') !== '0') {
                return true;
            }
        }
        return false;
    }

    private function templateSettings(array $template): array
    {
        $settings = $template['settings'] ?? ($template['setting_overrides'] ?? []);
        if (is_string($settings)) {
            if (trim($settings) === '') {
                return [];
            }
            $settings = json_decode($settings, true);
        }
        if (!is_array($settings)) {
            throw new \RuntimeException(
                'Template ' . $this->templateName($template, (int)$template['id']) . ' has invalid settings'
            );
        }
        return $settings;
    }

    private function templateName(?array $template, int $templateId): string
    {
        return $template && trim((string)($template['name'] ?? '')) !== ''
            ? (string)$template['name']
            : 'Template ' . $templateId;
    }
}
