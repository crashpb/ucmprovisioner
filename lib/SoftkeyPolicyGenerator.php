<?php
/**
 * SoftkeyPolicyGenerator — builds softkeys.xml and featurepolicy.xml from the
 * structured layout an operator picks in the Templates editor.
 *
 * Schema is sourced from the official UCM documentation, NOT from files left
 * on a TFTP server (which may be stale/broken/experimental):
 *  - https://usecallmanager.nz/soft-keys.html
 *  - https://usecallmanager.nz/feature-policy.html
 *
 * These are referenced by filename from SEPMAC.cnf.xml (`softKeyFile` /
 * `featurePolicyFile`) only when the resolved template stack contains a
 * managed policy. A filename setting alone is never enough: that would point a
 * handset at an unmanaged or missing file.
 *
 * @package FreePBX\modules\Ucmprovisioner
 */

namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/DeterministicVersionStamp.php';
require_once __DIR__ . '/AssetFilename.php';
require_once __DIR__ . '/SoftkeyPolicy.php';
require_once __DIR__ . '/TemplateEngine.php';

class SoftkeyPolicyGenerator
{
    /**
     * @param array $layout Current schema, or an older schema-less compatibility
     *                      map whose names come from TemplateEngine::SOFTKEY_STATES.
     */
    public function softkeysXml(array $layout): string
    {
        if (SoftkeyPolicy::isV2($layout)) {
            return $this->softkeysXmlV2(SoftkeyPolicy::normalizeV2($layout));
        }
        if (array_key_exists('schema', $layout)) {
            // A malformed/future envelope must fail visibly instead of falling
            // through to the permissive legacy-map renderer.
            SoftkeyPolicy::normalizeV2($layout);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('softKeyCfg');
        $doc->appendChild($root);
        $versionStamp = $doc->createElement('versionStamp');
        $root->appendChild($versionStamp);

        // Collect every keyID actually referenced so <typeSoftKey> only defines what's used.
        $used = ['Undefined' => true];
        $resolved = [];
        foreach (TemplateEngine::SOFTKEY_STATES as $state => $valid) {
            $mandatory = TemplateEngine::SOFTKEY_MANDATORY[$state] ?? [];
            $requested = array_values(array_intersect(
                (array)($layout[$state] ?? []),
                $valid
            ));
            $keys = $requested;
            foreach ($mandatory as $m) {
                if (!in_array($m, $keys, true)) {
                    $keys[] = $m;
                }
            }
            $keys = array_values(array_unique($keys));
            if (!$keys) {
                continue;
            }
            $resolved[$state] = $keys;
            foreach ($keys as $k) {
                $used[$k] = true;
            }
        }

        $typeSoftKey = $doc->createElement('typeSoftKey');
        foreach (array_keys($used) as $keyId) {
            $codes = TemplateEngine::SOFTKEY_CODES[$keyId] ?? null;
            if (!$codes) {
                continue;
            }
            $def = $doc->createElement('softKeyDef');
            $def->setAttribute('keyID', $this->xmlKeyId($keyId));
            $def->appendChild($doc->createElement('tag', (string) $codes[0]));
            $def->appendChild($doc->createElement('eventID', (string) $codes[1]));
            $def->appendChild($doc->createElement('helpID', (string) $codes[2]));
            $typeSoftKey->appendChild($def);
        }
        $root->appendChild($typeSoftKey);

        $sets = $doc->createElement('softKeySets');
        foreach ($resolved as $state => $keys) {
            $set = $doc->createElement('softKeySet');
            $set->setAttribute('id', $state);
            foreach ($keys as $k) {
                $sk = $doc->createElement('softKey');
                $sk->setAttribute('keyID', $this->xmlKeyId($k));
                $set->appendChild($sk);
            }
            $sets->appendChild($set);
        }
        $root->appendChild($sets);

        $versionStamp->appendChild($doc->createTextNode(
            DeterministicVersionStamp::fromContent((string)$doc->saveXML())
        ));
        return $doc->saveXML();
    }

    /**
     * Schema 2 is operator-owned: emit exact state/key order and duplicates.
     * No mandatory keys, model filters, firmware rows, or state allowlists are
     * applied here. Null states are inheritance tombstones and emit nothing.
     *
     * @param array<string,mixed> $policy normalized schema-2 policy
     */
    private function softkeysXmlV2(array $policy): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('softKeyCfg');
        $doc->appendChild($root);
        $versionStamp = $doc->createElement('versionStamp');
        $root->appendChild($versionStamp);

        $used = [];
        foreach ($policy['states'] as $keys) {
            if ($keys === null) {
                continue;
            }
            foreach ($keys as $keyId) {
                if (!array_key_exists($keyId, $used)) {
                    $used[$keyId] = true;
                }
            }
        }

        $builtins = SoftkeyPolicy::builtinDefinitions();
        $typeSoftKey = $doc->createElement('typeSoftKey');
        foreach (array_keys($used) as $keyId) {
            $definition = $builtins[$keyId] ?? $policy['definitions'][$keyId];
            $def = $doc->createElement('softKeyDef');
            $def->setAttribute('keyID', $keyId);
            $def->appendChild($doc->createElement('tag', (string)$definition['tag']));
            $def->appendChild($doc->createElement('eventID', (string)$definition['event_id']));
            $def->appendChild($doc->createElement('helpID', (string)$definition['help_id']));
            $typeSoftKey->appendChild($def);
        }
        $root->appendChild($typeSoftKey);

        $sets = $doc->createElement('softKeySets');
        foreach ($policy['states'] as $state => $keys) {
            if ($keys === null) {
                continue;
            }
            $set = $doc->createElement('softKeySet');
            $set->setAttribute('id', $state);
            foreach ($keys as $keyId) {
                $softKey = $doc->createElement('softKey');
                $softKey->setAttribute('keyID', $keyId);
                $set->appendChild($softKey);
            }
            $sets->appendChild($set);
        }
        $root->appendChild($sets);

        $versionStamp->appendChild($doc->createTextNode(
            DeterministicVersionStamp::fromContent((string)$doc->saveXML())
        ));
        return $doc->saveXML();
    }

    /**
     * @param array $enabled {featureName => bool} — from template.feature_policy (decoded JSON),
     *                       names exactly as in TemplateEngine::FEATURE_POLICY_KEYS
     */
    public function featurePolicyXml(array $enabled): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('featurePolicy');
        $root->setAttribute('name', 'Feature Policy');
        $doc->appendChild($root);
        $versionStamp = $doc->createElement('versionStamp');
        $root->appendChild($versionStamp);

        foreach (TemplateEngine::FEATURE_POLICY_KEYS as $id => $name) {
            $def = $doc->createElement('featureDef');
            $def->setAttribute('name', $name);
            $def->appendChild($doc->createElement('id', (string) $id));
            $def->appendChild($doc->createElement('enable', !empty($enabled[$name]) ? 'true' : 'false'));
            $root->appendChild($def);
        }
        $versionStamp->appendChild($doc->createTextNode(
            DeterministicVersionStamp::fromContent((string)$doc->saveXML())
        ));
        return $doc->saveXML();
    }

    /** Deterministic, filesystem-safe filename for a template's custom softkeys/feature-policy file. */
    public static function filenameFor(int $templateId, string $kind): string
    {
        if ($templateId < 1) { throw new \InvalidArgumentException('Template identity must be positive'); }
        // Legacy ID-based callers use a mutable namespace; stack rendering uses content names.
        return AssetFilename::content($kind === 'featurepolicy' ? 'q' : 'k', (string)$templateId, 'xml');
    }

    /** Preserve the editor's familiar CUCM label while emitting Cisco's exact XML keyID case. */
    private function xmlKeyId(string $keyId): string
    {
        return $keyId === 'CFwdALL' ? 'CFwdAll' : $keyId;
    }
}
