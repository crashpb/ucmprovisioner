<?php
/**
 * Selects at most one site default template for a confirmed phone profile.
 *
 * Group templates are deliberately outside this class. TemplateEngine invokes
 * this resolver only when no compatible layer exists in the assigned group's
 * inherited stack (or when the phone has no group).
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/ModelCatalog.php';

final class DefaultTemplateResolver
{
    /**
     * @return array{template_id:?int,rule:?array,trace:array}
     */
    public function resolve(array $rules, array $templates, array $device): array
    {
        $templatesById = [];
        foreach ($templates as $template) {
            if (!is_array($template) || (int)($template['id'] ?? 0) < 1) {
                throw new \InvalidArgumentException('Every default-template candidate needs a positive template id');
            }
            $templatesById[(int)$template['id']] = $template;
        }

        $matches = [];
        $trace = [];
        foreach (array_values($rules) as $order => $rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Every default-template rule must be an object');
            }
            $ruleId = (int)($rule['id'] ?? ($order + 1));
            $templateId = (int)($rule['template_id'] ?? 0);
            $entry = [
                'source'=>'default',
                'rule_id'=>$ruleId,
                'template_id'=>$templateId,
                'template_name'=>(string)($templatesById[$templateId]['name'] ?? ('Template ' . $templateId)),
            ];
            if (array_key_exists('enabled', $rule) && empty($rule['enabled'])) {
                $trace[] = $entry + ['status'=>'skipped','reason'=>'rule is disabled'];
                continue;
            }
            if (!isset($templatesById[$templateId])) {
                $trace[] = $entry + ['status'=>'skipped','reason'=>'selected template was not found'];
                continue;
            }
            $ruleReasons = $this->mismatchReasons($rule, $device, false);
            if ($ruleReasons) {
                $trace[] = $entry + ['status'=>'skipped','reason'=>implode('; ', $ruleReasons)];
                continue;
            }
            $templateReasons = $this->mismatchReasons($templatesById[$templateId], $device, true);
            if ($templateReasons) {
                $trace[] = $entry + [
                    'status'=>'skipped',
                    'reason'=>'template applicability failed: ' . implode('; ', $templateReasons),
                ];
                continue;
            }
            $score = $this->specificity($rule);
            $matches[] = ['rule'=>$rule,'template_id'=>$templateId,'score'=>$score,'trace_index'=>count($trace)];
            $trace[] = $entry + ['status'=>'candidate','reason'=>'all rule and template constraints matched','specificity'=>$score];
        }

        if (!$matches) {
            return ['template_id'=>null,'rule'=>null,'trace'=>$trace];
        }
        usort($matches, static function (array $left, array $right): int {
            $score = $right['score'] <=> $left['score'];
            if ($score !== 0) { return $score; }
            return (int)($left['rule']['id'] ?? 0) <=> (int)($right['rule']['id'] ?? 0);
        });
        $winner = $matches[0];
        $ties = array_values(array_filter(
            $matches,
            static fn(array $match): bool => $match['score'] === $winner['score']
        ));
        if (count($ties) > 1) {
            $ids = array_map(static fn(array $match): int => (int)($match['rule']['id'] ?? 0), $ties);
            throw new \RuntimeException(
                'Ambiguous default-template rules ' . implode(', ', $ids)
                . ' have equal specificity for this phone'
            );
        }

        foreach ($matches as $match) {
            $index = (int)$match['trace_index'];
            if ($match === $winner) {
                $trace[$index]['status'] = 'applied';
                $trace[$index]['reason'] = 'most specific matching default';
            } else {
                $trace[$index]['status'] = 'skipped';
                $trace[$index]['reason'] = 'a more specific default matched';
            }
        }
        return [
            'template_id'=>(int)$winner['template_id'],
            'rule'=>$winner['rule'],
            'trace'=>$trace,
        ];
    }

    private function specificity(array $rule): int
    {
        $model = trim((string)($rule['target_model'] ?? '')) !== ''
            || (int)($rule['target_model_id'] ?? 0) > 0;
        $seriesKey = trim((string)($rule['target_series'] ?? ''));
        $series = $seriesKey !== '';
        $family = trim((string)($rule['target_family'] ?? '')) !== '';
        $vendor = trim((string)($rule['target_vendor'] ?? '')) !== '';
        $role = trim((string)($rule['target_role'] ?? '')) !== '';
        if ($model) { return 500 + ($role ? 1 : 0); }
        if ($series) {
            return (ModelCatalog::isFirmwareTemplateScope($seriesKey) ? 350 : 300) + ($role ? 1 : 0);
        }
        if ($family) { return 100 + ($role ? 1 : 0); }
        if ($vendor) { return 40 + ($role ? 1 : 0); }
        if ($role) { return 20; }
        return 0;
    }

    private function mismatchReasons(array $scope, array $device, bool $template): array
    {
        $prefix = $template ? 'template ' : '';
        $reasons = [];
        $this->compare($reasons, $prefix . 'vendor', $scope['target_vendor'] ?? '', $device['vendor'] ?? '');
        $this->compare($reasons, $prefix . 'family', $scope['target_family'] ?? '', $device['family'] ?? '');
        $expectedSeries = trim((string)($scope['target_series'] ?? ''));
        if ($expectedSeries !== '' && !ModelCatalog::templateScopeMatches($expectedSeries, $device)) {
            $actual = implode(', ', ModelCatalog::templateScopeKeys(
                (string)($device['vendor'] ?? ''),
                (string)($device['model'] ?? ''),
                (string)($device['family'] ?? ''),
                (string)($device['series_key'] ?? ($device['series'] ?? '')),
                (string)($device['firmware_profile'] ?? '')
            ));
            $reasons[] = $prefix . 'series requires ' . $expectedSeries
                . '; phone scopes are ' . ($actual !== '' ? $actual : 'unset');
        }
        $this->compare($reasons, $prefix . 'role', $scope['target_role'] ?? '', $device['role'] ?? '');

        $modelId = (int)($scope['target_model_id'] ?? 0);
        if ($modelId > 0 && $modelId !== (int)($device['model_id'] ?? 0)) {
            $reasons[] = $prefix . 'model id requires ' . $modelId;
        }
        $targetModel = trim((string)($scope['target_model'] ?? ''));
        if ($targetModel !== '') {
            $actual = str_contains($targetModel, '|')
                ? trim((string)($device['vendor'] ?? '')) . '|' . trim((string)($device['model'] ?? ''))
                : trim((string)($device['model'] ?? ''));
            if (strcasecmp($targetModel, $actual) !== 0) {
                $reasons[] = $prefix . 'model requires ' . $targetModel . '; phone is ' . ($actual ?: 'unset');
            }
        }
        return $reasons;
    }

    private function compare(array &$reasons, string $label, $expected, $actual): void
    {
        $expected = trim((string)$expected);
        if ($expected === '') { return; }
        $actual = trim((string)$actual);
        if (strcasecmp($expected, $actual) !== 0) {
            $reasons[] = $label . ' requires ' . $expected . '; phone is ' . ($actual ?: 'unset');
        }
    }
}
