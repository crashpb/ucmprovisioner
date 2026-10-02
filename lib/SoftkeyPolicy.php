<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Canonical Cisco softkey policy schema.
 *
 * Schema 1 is the older {state => [keyID, ...]} map. It remains accepted by
 * SoftkeyPolicyGenerator unchanged. Schema 2 is explicit: order and
 * duplicates are meaningful, null clears an inherited state, custom states and
 * key definitions are permitted, and annotations/unresolved migration records
 * are display metadata only.
 */
final class SoftkeyPolicy
{
    public const SCHEMA_VERSION = 2;

    /** Cisco-published softKeySet states, in documentation order. */
    public const DOCUMENTED_STATES = [
        'On Hook',
        'Off Hook',
        'Off Hook With Feature',
        'Digits After First',
        'Ring Out',
        'Ring In',
        'Connected',
        'Connected Transfer',
        'Connected Conference',
        'On Hold',
        'Remote In Use',
    ];

    /** Plain-language editor help for every built-in call state. */
    public const STATE_DESCRIPTIONS = [
        'On Hook' => 'The handset is idle with no digits entered.',
        'Off Hook' => 'A line is seized, but no feature or first digit is active yet.',
        'Off Hook With Feature' => 'The handset is off-hook while a phone feature owns the call context.',
        'Digits After First' => 'At least one destination digit has been entered and the phone is collecting more.',
        'Ring Out' => 'The phone is placing a call and waiting for the far end to answer.',
        'Ring In' => 'An incoming call is ringing on the phone.',
        'Connected' => 'A normal active call is connected.',
        'Connected Transfer' => 'A connected call is in the transfer workflow.',
        'Connected Conference' => 'A connected call is in the conference workflow.',
        'On Hold' => 'The selected call is locally held; Resume is required for an explicit usable list.',
        'Remote In Use' => 'A shared line is in use by another device.',
        'Connected No Feature' => 'Compatibility-only state seen in legacy working policies; it is not in Cisco\'s published 11-state table.',
    ];

    /**
     * Advisory compatibility catalog for the editor. Schema 2 deliberately
     * does not filter against it: custom states and definitions remain valid.
     */
    public const STATE_COMPATIBILITY = [
        'On Hook' => ['Redial', 'NewCall', 'DND', 'CFwdAll', 'HLog', 'PickUp', 'GPickUp', 'OPickUp', 'CallBack', 'MeetMe', 'QRT', 'Undefined'],
        'Off Hook' => ['Redial', 'EndCall', 'DND', 'CFwdAll', 'HLog', 'PickUp', 'GPickUp', 'OPickUp', 'AbbrDial', 'Undefined'],
        'Off Hook With Feature' => ['Redial', 'EndCall', 'DND', 'AbbrDial', 'Undefined'],
        'Digits After First' => ['EndCall', '<<', 'DND', 'AbbrDial', 'Undefined'],
        'Ring Out' => ['EndCall', 'DND', 'CallBack', 'Undefined'],
        'Ring In' => ['Answer', 'iDivert', 'DND', 'Undefined'],
        'Connected' => ['Hold', 'EndCall', 'Transfer', 'Park', 'Confrn', 'ConfList', 'RmLstC', 'Record', 'iDivert', 'DND', 'HLog', 'QRT', 'MCID', 'Select', 'Join', 'Undefined'],
        'Connected Transfer' => ['EndCall', 'Transfer', 'CallBack', 'QRT', 'Undefined'],
        'Connected Conference' => ['EndCall', 'Confrn', 'DND', 'QRT', 'Undefined'],
        'On Hold' => ['Resume', 'NewCall', 'iDivert', 'DND', 'Select', 'Join', 'Undefined'],
        'Remote In Use' => [],
        // Compatibility state present in working legacy policies but absent
        // from Cisco's published 11-state table.
        'Connected No Feature' => ['EndCall', 'Undefined'],
    ];

    /** Advisory/recommended keys; never injected into a schema-2 policy. */
    public const RECOMMENDED_KEYS = [
        'On Hook' => ['NewCall'],
        'Digits After First' => ['<<'],
        'Ring Out' => ['EndCall'],
        'Ring In' => ['Answer'],
        'Connected' => ['EndCall'],
        'Connected Transfer' => ['Transfer'],
        'Connected Conference' => ['Confrn'],
        'On Hold' => ['Resume'],
    ];

    /**
     * Cisco-required actions for an explicitly managed call state.
     *
     * These are validated, never inserted. A missing state (inherit) or null
     * tombstone emits no softKeySet, while an explicit list must remain usable
     * on the handset exactly as the operator ordered it.
     */
    public const REQUIRED_KEYS = [
        'On Hook' => ['NewCall'],
        'Digits After First' => ['<<'],
        'Connected' => ['EndCall'],
        'Connected Transfer' => ['Transfer'],
        'Connected Conference' => ['Confrn'],
        'On Hold' => ['Resume'],
    ];

    /** Trusted exact on-wire keyID => Cisco typeSoftKey triplet. */
    public const BUILTIN_DEFINITIONS = [
        'Undefined' => ['tag' => 0, 'event_id' => 0, 'help_id' => 0],
        'Redial' => ['tag' => 1, 'event_id' => 1, 'help_id' => 301],
        'NewCall' => ['tag' => 2, 'event_id' => 2, 'help_id' => 302],
        'Hold' => ['tag' => 3, 'event_id' => 3, 'help_id' => 303],
        'Transfer' => ['tag' => 4, 'event_id' => 4, 'help_id' => 304],
        'CFwdAll' => ['tag' => 5, 'event_id' => 5, 'help_id' => 305],
        '<<' => ['tag' => 8, 'event_id' => 8, 'help_id' => 308],
        'EndCall' => ['tag' => 9, 'event_id' => 9, 'help_id' => 309],
        'Resume' => ['tag' => 10, 'event_id' => 10, 'help_id' => 310],
        'Answer' => ['tag' => 11, 'event_id' => 11, 'help_id' => 311],
        'Confrn' => ['tag' => 13, 'event_id' => 13, 'help_id' => 313],
        'Park' => ['tag' => 14, 'event_id' => 14, 'help_id' => 314],
        'Join' => ['tag' => 15, 'event_id' => 15, 'help_id' => 315],
        'MeetMe' => ['tag' => 16, 'event_id' => 16, 'help_id' => 316],
        'PickUp' => ['tag' => 17, 'event_id' => 17, 'help_id' => 317],
        'GPickUp' => ['tag' => 18, 'event_id' => 18, 'help_id' => 318],
        'RmLstC' => ['tag' => 57, 'event_id' => 19, 'help_id' => 319],
        'CallBack' => ['tag' => 65, 'event_id' => 20, 'help_id' => 320],
        'DND' => ['tag' => 63, 'event_id' => 69, 'help_id' => 369],
        'QRT' => ['tag' => 75, 'event_id' => 22, 'help_id' => 322],
        'MCID' => ['tag' => 76, 'event_id' => 27, 'help_id' => 327],
        'Select' => ['tag' => 78, 'event_id' => 29, 'help_id' => 329],
        'ConfList' => ['tag' => 79, 'event_id' => 30, 'help_id' => 330],
        'iDivert' => ['tag' => 80, 'event_id' => 31, 'help_id' => 331],
        'OPickUp' => ['tag' => 91, 'event_id' => 34, 'help_id' => 334],
        'HLog' => ['tag' => 92, 'event_id' => 35, 'help_id' => 335],
        'AbbrDial' => ['tag' => 7740, 'event_id' => 71, 'help_id' => 371],
        'Record' => ['tag' => 7747, 'event_id' => 74, 'help_id' => 374],
    ];

    /** Plain-language editor help for every trusted built-in key definition. */
    public const KEY_DESCRIPTIONS = [
        'Undefined' => 'An intentionally empty softkey position.',
        'Redial' => 'Dial the most recently called number again.',
        'NewCall' => 'Start a new call or open a new-call context.',
        'Hold' => 'Place the selected connected call on hold.',
        'Transfer' => 'Complete or continue the current transfer workflow.',
        'CFwdAll' => 'Configure or cancel forwarding of all calls.',
        '<<' => 'Delete the last entered digit.',
        'EndCall' => 'End the selected call or cancel the current call attempt.',
        'Resume' => 'Resume the selected locally held call.',
        'Answer' => 'Answer the incoming call.',
        'Confrn' => 'Start or complete an ad-hoc conference.',
        'Park' => 'Park the selected call using the PBX parking feature.',
        'Join' => 'Join selected calls into a conference where firmware supports it.',
        'MeetMe' => 'Open the phone\'s Meet-Me conference workflow.',
        'PickUp' => 'Answer another ringing extension in the configured pickup scope.',
        'GPickUp' => 'Answer a call ringing in another pickup group.',
        'RmLstC' => 'Remove the last conference participant.',
        'CallBack' => 'Request a callback notification where the PBX and firmware support it.',
        'DND' => 'Toggle Do Not Disturb.',
        'QRT' => 'Submit a quality-report event where supported.',
        'MCID' => 'Mark a connected call as malicious where supported.',
        'Select' => 'Select a call or list item for a multi-call operation.',
        'ConfList' => 'Show the current conference participant list.',
        'iDivert' => 'Immediately divert an incoming call, commonly to voicemail.',
        'OPickUp' => 'Answer a ringing call using Other Group Pickup.',
        'HLog' => 'Log an agent in or out of a hunt group.',
        'AbbrDial' => 'Use an abbreviated-dial entry after digits are entered.',
        'Record' => 'Start or stop call recording where the PBX and firmware support it.',
    ];

    /** Exact state order used by the pre-schema generator. */
    private const LEGACY_STATE_ORDER = [
        'On Hook',
        'Off Hook',
        'Off Hook With Feature',
        'Digits After First',
        'Ring Out',
        'Connected',
        'Connected No Feature',
        'Connected Transfer',
        'Connected Conference',
        'On Hold',
        'Ring In',
    ];

    private const MAX_STATES = 64;
    /** Cisco softkeys.xml permits at most 16 slots in one state. */
    private const MAX_SLOTS_PER_STATE = 16;
    private const MAX_DEFINITIONS = 128;
    private const MAX_ANNOTATIONS_PER_STATE = 128;
    private const MAX_UNRESOLVED = 512;
    private const MAX_VISUAL_PROFILES = 128;
    private const MAX_VISUAL_KEYS_PER_STATE = 32;
    private const MAX_POLICY_BYTES = 262144;

    public static function isV2(array $policy): bool
    {
        return array_key_exists('schema', $policy)
            && $policy['schema'] === self::SCHEMA_VERSION;
    }

    /** @return array<string,mixed> */
    public static function normalize(array $policy): array
    {
        return self::normalizeV2($policy);
    }

    /** @return array<string,mixed> */
    public static function validateV2(array $policy): array
    {
        return self::normalizeV2($policy);
    }

    /** @return array<string,mixed> */
    public static function normalizeV2(array $policy): array
    {
        return self::normalizeV2Internal($policy, true);
    }

    /**
     * Trusted built-in key definitions, using exact on-wire Cisco keyID case.
     *
     * VidMode is intentionally absent. The current research set has no trusted
     * working triplet for it on the supported 78xx/88xx enterprise firmware.
     *
     * @return array<string,array{tag:int,event_id:int,help_id:int}>
     */
    public static function builtinDefinitions(): array
    {
        return self::BUILTIN_DEFINITIONS;
    }

    /**
     * Materialize a legacy map as schema 2 without silently losing old input.
     *
     * Known keys keep their earlier emitted order. Compatibility duplicate removal
     * and mandatory additions are materialized because the old generator
     * applied them at render time. Unknown, invalid, and no-longer-trusted
     * entries become non-emitting annotations plus structured unresolved
     * records. Native schema-2 editing still preserves intentional duplicates.
     *
     * @return array<string,mixed>
     */
    public static function migrateLegacy(array $legacy): array
    {
        if (array_key_exists('schema', $legacy)) {
            throw new \InvalidArgumentException(
                'Only a schema-less legacy softkey map can be migrated'
            );
        }

        $states = [];
        $annotations = [];
        $unresolved = [];

        // An empty map means inherit/no custom policy. The compatibility mandatory
        // additions only ran after a non-empty custom map reached the generator.
        if ($legacy === []) {
            return self::normalizeV2([
                'schema' => self::SCHEMA_VERSION,
                'states' => [],
                'definitions' => [],
                'annotations' => [],
                'unresolved' => [],
            ]);
        }

        foreach (self::LEGACY_STATE_ORDER as $state) {
            $validKeys = self::STATE_COMPATIBILITY[$state];
            $raw = array_key_exists($state, $legacy) ? $legacy[$state] : [];
            if (!is_array($raw)) {
                self::recordUnresolved(
                    $annotations,
                    $unresolved,
                    $state,
                    0,
                    $raw,
                    'invalid_state_value',
                    'Legacy state value was not a key list and was not emitted.'
                );
                $raw = [];
            }

            $resolved = [];
            $position = 0;
            foreach (array_values($raw) as $token) {
                if (!is_string($token)) {
                    self::recordUnresolved(
                        $annotations,
                        $unresolved,
                        $state,
                        $position++,
                        $token,
                        'invalid_key',
                        'Legacy key was not text and was not emitted.'
                    );
                    continue;
                }

                if ($token === 'VidMode') {
                    self::recordUnresolved(
                        $annotations,
                        $unresolved,
                        $state,
                        $position++,
                        $token,
                        'untrusted_definition',
                        'No trusted working VidMode definition is available for the supported firmware.'
                    );
                    continue;
                }
                if ($token === 'CFwdAll') {
                    self::recordUnresolved(
                        $annotations,
                        $unresolved,
                        $state,
                        $position++,
                        $token,
                        'nonlegacy_key',
                        'Legacy policies used the editor alias CFwdALL; exact CFwdAll belongs to schema 2.'
                    );
                    continue;
                }
                $wireToken = self::legacyWireKeyId($token);
                if (!array_key_exists($wireToken, self::BUILTIN_DEFINITIONS)) {
                    self::recordUnresolved(
                        $annotations,
                        $unresolved,
                        $state,
                        $position++,
                        $token,
                        'unknown_key',
                        'Legacy key has no trusted definition and was not emitted.'
                    );
                    continue;
                }
                if (!in_array($wireToken, $validKeys, true)) {
                    self::recordUnresolved(
                        $annotations,
                        $unresolved,
                        $state,
                        $position++,
                        $token,
                        'invalid_for_state',
                        'Legacy generator did not permit this key in the selected state.'
                    );
                    continue;
                }

                $resolved[] = $wireToken;
                $position++;
            }

            foreach (self::RECOMMENDED_KEYS[$state] ?? [] as $mandatory) {
                $wireKeyId = self::legacyWireKeyId($mandatory);
                if (!in_array($wireKeyId, $resolved, true)) {
                    $resolved[] = $wireKeyId;
                }
            }
            $resolved = array_values(array_unique($resolved));
            if ($resolved !== []) {
                $states[$state] = $resolved;
            }
        }

        $legacyStates = array_fill_keys(self::LEGACY_STATE_ORDER, true);
        foreach ($legacy as $state => $raw) {
            if (isset($legacyStates[(string)$state])) {
                continue;
            }
            $values = is_array($raw) ? array_values($raw) : [$raw];
            if ($values === []) {
                $values = [null];
            }
            foreach ($values as $position => $token) {
                self::recordUnresolved(
                    $annotations,
                    $unresolved,
                    (string)$state,
                    (int)$position,
                    $token,
                    'unknown_state',
                    'Legacy generator did not recognize this call state.'
                );
            }
        }

        return self::normalizeV2([
            'schema' => self::SCHEMA_VERSION,
            'states' => $states,
            'definitions' => [],
            'annotations' => $annotations,
            'unresolved' => $unresolved,
        ]);
    }

    /**
     * Merge template tiers. A missing state inherits, an array replaces the
     * lower tier exactly, and null explicitly clears the inherited state.
     *
     * @return array<string,mixed>
     */
    public static function merge(array $base, array $patch): array
    {
        $baseV2 = self::toV2ForMerge($base);
        $patchV2 = self::toV2ForMerge($patch);

        $states = $baseV2['states'];
        foreach ($patchV2['states'] as $state => $keys) {
            $states[$state] = $keys;
        }

        $definitions = $baseV2['definitions'];
        foreach ($patchV2['definitions'] as $keyId => $definition) {
            $definitions[$keyId] = $definition;
        }

        $annotations = $baseV2['annotations'];
        foreach ($patchV2['annotations'] as $state => $entries) {
            if ($entries === null) {
                unset($annotations[$state]);
                continue;
            }
            $existing = $annotations[$state] ?? [];
            $annotations[$state] = array_merge(
                is_array($existing) ? $existing : [],
                $entries
            );
        }

        $visualProfiles = (array)($baseV2['visual']['profiles'] ?? []);
        foreach ((array)($patchV2['visual']['profiles'] ?? []) as $profile => $statePatch) {
            $profileStates = (array)($visualProfiles[$profile] ?? []);
            foreach ($statePatch as $state => $labels) {
                // A list replaces the lower visual row exactly. Null is an
                // explicit reset to the automatic handset estimate.
                $profileStates[$state] = $labels;
            }
            $visualProfiles[$profile] = $profileStates;
        }

        $merged = [
            'schema' => self::SCHEMA_VERSION,
            'states' => $states,
            'definitions' => $definitions,
            'annotations' => $annotations,
            'unresolved' => array_merge(
                $baseV2['unresolved'],
                $patchV2['unresolved']
            ),
        ];
        if ($visualProfiles !== []) {
            $merged['visual'] = ['profiles' => $visualProfiles];
        }
        return self::normalizeV2($merged);
    }

    /** @return array<string,mixed> */
    private static function toV2ForMerge(array $policy): array
    {
        if (self::isV2($policy)) {
            // Templates are reusable building blocks and must remain valid on
            // their own. A tier may override an inherited definition, but a
            // state cannot depend on a definition that exists only in some
            // possible group stack.
            return self::normalizeV2($policy);
        }
        if (array_key_exists('schema', $policy)) {
            throw new \InvalidArgumentException('Unsupported softkey policy schema');
        }
        return self::migrateLegacy($policy);
    }

    /** @return array<string,mixed> */
    private static function normalizeV2Internal(array $policy, bool $validateReferences): array
    {
        if (!self::isV2($policy)) {
            throw new \InvalidArgumentException('Softkey policy schema must be integer 2');
        }

        $allowedTopLevel = [
            'schema' => true,
            'states' => true,
            'definitions' => true,
            'annotations' => true,
            'unresolved' => true,
            'visual' => true,
        ];
        foreach ($policy as $key => $_value) {
            if (!isset($allowedTopLevel[$key])) {
                throw new \InvalidArgumentException(
                    'Unknown softkey policy field: ' . (string)$key
                );
            }
        }

        $rawStates = $policy['states'] ?? null;
        if (!is_array($rawStates) || !self::isMap($rawStates)) {
            throw new \InvalidArgumentException('Softkey policy states must be an object');
        }
        if (count($rawStates) > self::MAX_STATES) {
            throw new \InvalidArgumentException('Softkey policy has too many states');
        }

        $states = [];
        foreach ($rawStates as $state => $rawKeys) {
            $state = self::validatedMapKey((string)$state, 'state name', 96);
            if ($rawKeys === null) {
                $states[$state] = null;
                continue;
            }
            if (!is_array($rawKeys) || !array_is_list($rawKeys)) {
                throw new \InvalidArgumentException(
                    'Softkey state "' . $state . '" must be a key list or null'
                );
            }
            if (count($rawKeys) > self::MAX_SLOTS_PER_STATE) {
                throw new \InvalidArgumentException(
                    'Softkey state "' . $state . '" has too many slots'
                );
            }
            $keys = [];
            foreach ($rawKeys as $index => $keyId) {
                if (!is_string($keyId)) {
                    throw new \InvalidArgumentException(
                        'Softkey slot ' . $state . '[' . $index . '] must be text'
                    );
                }
                $keyId = self::validatedText($keyId, 'keyID', 96);
                if ($keyId === 'CFwdALL') {
                    throw new \InvalidArgumentException(
                        'Schema 2 uses the exact Cisco keyID "CFwdAll", not "CFwdALL"'
                    );
                }
                $keys[] = $keyId;
            }
            $states[$state] = $keys;
        }

        $rawDefinitions = $policy['definitions'] ?? [];
        if (!is_array($rawDefinitions) || !self::isMap($rawDefinitions)) {
            throw new \InvalidArgumentException('Softkey definitions must be an object');
        }
        if (count($rawDefinitions) > self::MAX_DEFINITIONS) {
            throw new \InvalidArgumentException('Softkey policy has too many custom definitions');
        }

        $builtins = self::builtinDefinitions();
        $definitions = [];
        foreach ($rawDefinitions as $keyId => $rawDefinition) {
            $keyId = self::validatedMapKey((string)$keyId, 'custom keyID', 96);
            if ($keyId === 'CFwdALL') {
                throw new \InvalidArgumentException(
                    'Schema 2 uses the exact Cisco keyID "CFwdAll", not "CFwdALL"'
                );
            }
            if (array_key_exists($keyId, $builtins)) {
                throw new \InvalidArgumentException(
                    'Custom definition may not replace built-in keyID "' . $keyId . '"'
                );
            }
            if (!is_array($rawDefinition) || !self::isMap($rawDefinition)) {
                throw new \InvalidArgumentException(
                    'Custom definition "' . $keyId . '" must be an object'
                );
            }
            $allowedDefinitionFields = [
                'tag' => true,
                'event_id' => true,
                'help_id' => true,
                'label' => true,
            ];
            foreach ($rawDefinition as $field => $_value) {
                if (!isset($allowedDefinitionFields[$field])) {
                    throw new \InvalidArgumentException(
                        'Unknown field "' . (string)$field . '" in custom definition "' . $keyId . '"'
                    );
                }
            }
            $definition = [
                'tag' => self::validatedCode($rawDefinition, 'tag', $keyId),
                'event_id' => self::validatedCode($rawDefinition, 'event_id', $keyId),
                'help_id' => self::validatedCode($rawDefinition, 'help_id', $keyId),
            ];
            if (array_key_exists('label', $rawDefinition)) {
                if (!is_string($rawDefinition['label'])) {
                    throw new \InvalidArgumentException(
                        'Custom definition "' . $keyId . '" label must be text'
                    );
                }
                $definition['label'] = self::validatedText(
                    $rawDefinition['label'],
                    'custom definition label',
                    128
                );
            }
            $definitions[$keyId] = $definition;
        }

        if ($validateReferences) {
            foreach ($states as $state => $keys) {
                if ($keys === null) {
                    continue;
                }
                foreach ($keys as $keyId) {
                    if (!array_key_exists($keyId, $builtins)
                        && !array_key_exists($keyId, $definitions)) {
                        throw new \InvalidArgumentException(
                            'Softkey state "' . $state . '" references undefined keyID "' . $keyId . '"'
                        );
                    }
                }
                $missingRequired = array_values(array_diff(self::REQUIRED_KEYS[$state] ?? [], $keys));
                if ($missingRequired) {
                    throw new \InvalidArgumentException(
                        'Softkey state "' . $state . '" requires ' . implode(', ', $missingRequired)
                        . '; choose Inherit to omit this state instead of emitting an unusable explicit list'
                    );
                }
            }
        }

        $annotations = self::normalizeAnnotations($policy['annotations'] ?? []);
        $unresolved = self::normalizeUnresolved($policy['unresolved'] ?? []);
        $visual = self::normalizeVisual($policy['visual'] ?? []);
        $normalized = [
            'schema' => self::SCHEMA_VERSION,
            'states' => $states,
            'definitions' => $definitions,
            'annotations' => $annotations,
            'unresolved' => $unresolved,
        ];
        if ($visual !== []) {
            $normalized['visual'] = $visual;
        }

        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || strlen($json) > self::MAX_POLICY_BYTES) {
            throw new \InvalidArgumentException('Softkey policy is too large');
        }
        return $normalized;
    }

    /**
     * Validate editor-only handset observations. These rows never become a
     * typeSoftKey definition or a softKeySet. They are keyed by exact preview
     * model so a broad template can describe different firmware displays.
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function normalizeVisual($raw): array
    {
        if (!is_array($raw) || !self::isMap($raw)) {
            throw new \InvalidArgumentException('Softkey visual data must be an object');
        }
        if ($raw === []) {
            return [];
        }
        foreach ($raw as $field => $_value) {
            if ($field !== 'profiles') {
                throw new \InvalidArgumentException(
                    'Unknown softkey visual field: ' . (string)$field
                );
            }
        }
        $rawProfiles = $raw['profiles'] ?? [];
        if (!is_array($rawProfiles) || !self::isMap($rawProfiles)) {
            throw new \InvalidArgumentException('Softkey visual profiles must be an object');
        }
        if (count($rawProfiles) > self::MAX_VISUAL_PROFILES) {
            throw new \InvalidArgumentException('Softkey policy has too many visual profiles');
        }

        $profiles = [];
        foreach ($rawProfiles as $profile => $rawStates) {
            $profile = self::validatedMapKey((string)$profile, 'visual profile', 128);
            if (!is_array($rawStates) || !self::isMap($rawStates)) {
                throw new \InvalidArgumentException(
                    'Softkey visual profile "' . $profile . '" must be a state object'
                );
            }
            if (count($rawStates) > self::MAX_STATES) {
                throw new \InvalidArgumentException(
                    'Softkey visual profile "' . $profile . '" has too many states'
                );
            }
            $states = [];
            foreach ($rawStates as $state => $rawLabels) {
                $state = self::validatedMapKey((string)$state, 'visual state', 96);
                if ($rawLabels === null) {
                    $states[$state] = null;
                    continue;
                }
                if (!is_array($rawLabels) || !array_is_list($rawLabels)) {
                    throw new \InvalidArgumentException(
                        'Softkey visual state "' . $state . '" must be a label list or null'
                    );
                }
                if (count($rawLabels) > self::MAX_VISUAL_KEYS_PER_STATE) {
                    throw new \InvalidArgumentException(
                        'Softkey visual state "' . $state . '" has too many visible keys'
                    );
                }
                $labels = [];
                foreach ($rawLabels as $index => $label) {
                    if (!is_string($label)) {
                        throw new \InvalidArgumentException(
                            'Softkey visual label ' . $state . '[' . $index . '] must be text'
                        );
                    }
                    $labels[] = self::validatedText($label, 'visual softkey label', 128);
                }
                $states[$state] = $labels;
            }
            if ($states !== []) {
                $profiles[$profile] = $states;
            }
        }
        return $profiles === [] ? [] : ['profiles' => $profiles];
    }

    /**
     * @param mixed $raw
     * @return array<string,array<int,array<string,string>>|null>
     */
    private static function normalizeAnnotations($raw): array
    {
        if (!is_array($raw) || !self::isMap($raw)) {
            throw new \InvalidArgumentException('Softkey annotations must be an object');
        }
        $annotations = [];
        foreach ($raw as $state => $rawEntries) {
            $state = self::validatedMapKey((string)$state, 'annotation state', 96);
            if ($rawEntries === null) {
                $annotations[$state] = null;
                continue;
            }
            if (!is_array($rawEntries) || !array_is_list($rawEntries)) {
                throw new \InvalidArgumentException(
                    'Annotations for "' . $state . '" must be a list or null'
                );
            }
            if (count($rawEntries) > self::MAX_ANNOTATIONS_PER_STATE) {
                throw new \InvalidArgumentException(
                    'Too many annotations for "' . $state . '"'
                );
            }
            $entries = [];
            foreach ($rawEntries as $index => $rawEntry) {
                if (!is_array($rawEntry) || !self::isMap($rawEntry)) {
                    throw new \InvalidArgumentException(
                        'Annotation ' . $state . '[' . $index . '] must be an object'
                    );
                }
                $allowed = ['label' => true, 'note' => true, 'source' => true, 'reason' => true];
                foreach ($rawEntry as $field => $_value) {
                    if (!isset($allowed[$field])) {
                        throw new \InvalidArgumentException(
                            'Unknown annotation field "' . (string)$field . '"'
                        );
                    }
                }
                if (!isset($rawEntry['label']) || !is_string($rawEntry['label'])) {
                    throw new \InvalidArgumentException('Annotation label must be text');
                }
                if (!isset($rawEntry['note']) || !is_string($rawEntry['note'])) {
                    throw new \InvalidArgumentException('Annotation note must be text');
                }
                $entry = [
                    'label' => self::validatedText($rawEntry['label'], 'annotation label', 128),
                    'note' => self::validatedText($rawEntry['note'], 'annotation note', 512),
                ];
                foreach (['source', 'reason'] as $field) {
                    if (array_key_exists($field, $rawEntry)) {
                        if (!is_string($rawEntry[$field])) {
                            throw new \InvalidArgumentException('Annotation ' . $field . ' must be text');
                        }
                        $entry[$field] = self::validatedText(
                            $rawEntry[$field],
                            'annotation ' . $field,
                            64
                        );
                    }
                }
                $entries[] = $entry;
            }
            $annotations[$state] = $entries;
        }
        return $annotations;
    }

    /**
     * @param mixed $raw
     * @return array<int,array{source:string,state:string,index:int,token:string,reason:string}>
     */
    private static function normalizeUnresolved($raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Softkey unresolved metadata must be a list');
        }
        if (count($raw) > self::MAX_UNRESOLVED) {
            throw new \InvalidArgumentException('Softkey policy has too many unresolved records');
        }
        $unresolved = [];
        foreach ($raw as $index => $record) {
            if (!is_array($record) || !self::isMap($record)) {
                throw new \InvalidArgumentException(
                    'Unresolved record ' . $index . ' must be an object'
                );
            }
            $allowed = [
                'source' => true,
                'state' => true,
                'index' => true,
                'token' => true,
                'reason' => true,
            ];
            foreach ($record as $field => $_value) {
                if (!isset($allowed[$field])) {
                    throw new \InvalidArgumentException(
                        'Unknown unresolved field "' . (string)$field . '"'
                    );
                }
            }
            foreach (['source', 'state', 'token', 'reason'] as $field) {
                if (!isset($record[$field]) || !is_string($record[$field])) {
                    throw new \InvalidArgumentException(
                        'Unresolved record field "' . $field . '" must be text'
                    );
                }
            }
            if (!isset($record['index']) || !is_int($record['index']) || $record['index'] < 0) {
                throw new \InvalidArgumentException(
                    'Unresolved record index must be a non-negative integer'
                );
            }
            $unresolved[] = [
                'source' => self::validatedText($record['source'], 'unresolved source', 64),
                'state' => self::validatedText($record['state'], 'unresolved state', 128),
                'index' => $record['index'],
                'token' => self::validatedText($record['token'], 'unresolved token', 512, true),
                'reason' => self::validatedText($record['reason'], 'unresolved reason', 64),
            ];
        }
        return $unresolved;
    }

    private static function validatedCode(array $definition, string $field, string $keyId): int
    {
        if (!array_key_exists($field, $definition) || !is_int($definition[$field])) {
            throw new \InvalidArgumentException(
                'Custom definition "' . $keyId . '" field "' . $field . '" must be an integer'
            );
        }
        $value = $definition[$field];
        if ($value < 0 || $value > 2147483647) {
            throw new \InvalidArgumentException(
                'Custom definition "' . $keyId . '" field "' . $field . '" is out of range'
            );
        }
        return $value;
    }

    private static function validatedText(
        string $value,
        string $field,
        int $maxBytes,
        bool $allowEmpty = false
    ): string {
        if ((!$allowEmpty && $value === '') || trim($value) !== $value) {
            throw new \InvalidArgumentException($field . ' is empty or has outer whitespace');
        }
        if (strlen($value) > $maxBytes
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException($field . ' contains invalid text');
        }
        return $value;
    }

    /**
     * PHP converts integer-looking JSON object keys to array indexes, while
     * JavaScript treats __proto__ specially on ordinary objects. Neither form
     * can round-trip as a stable policy map, so reject them at the boundary.
     */
    private static function validatedMapKey(string $value, string $field, int $maxBytes): string
    {
        $value = self::validatedText($value, $field, $maxBytes);
        if (preg_match('/^[+-]?\d+$/D', $value)) {
            throw new \InvalidArgumentException($field . ' must not be numeric-only');
        }
        if (in_array(strtolower($value), ['__proto__', 'prototype', 'constructor'], true)) {
            throw new \InvalidArgumentException($field . ' uses a reserved object name');
        }
        return $value;
    }

    private static function isMap(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }

    private static function legacyWireKeyId(string $keyId): string
    {
        return $keyId === 'CFwdALL' ? 'CFwdAll' : $keyId;
    }

    /**
     * @param array<string,array<int,array<string,string>>> $annotations
     * @param array<int,array<string,mixed>> $unresolved
     * @param mixed $token
     */
    private static function recordUnresolved(
        array &$annotations,
        array &$unresolved,
        string $state,
        int $index,
        $token,
        string $reason,
        string $note
    ): void {
        $safeState = self::metadataText($state, 128, '(invalid state)');
        $safeToken = self::metadataValue($token);
        $annotationState = self::metadataText($state, 96, '_migration');
        if ($annotationState === ''
            || trim($annotationState) !== $annotationState
            || preg_match('/^[+-]?\d+$/D', $annotationState)
            || in_array(strtolower($annotationState), ['__proto__', 'prototype', 'constructor'], true)) {
            $annotationState = '_migration';
        }
        $annotations[$annotationState][] = [
            'label' => self::metadataText($safeToken, 128, '(invalid value)'),
            'note' => $note,
            'source' => 'legacy',
            'reason' => $reason,
        ];
        $unresolved[] = [
            'source' => 'legacy',
            'state' => $safeState,
            'index' => max(0, $index),
            'token' => $safeToken,
            'reason' => $reason,
        ];
    }

    /** @param mixed $value */
    private static function metadataValue($value): string
    {
        if (is_string($value)) {
            return self::metadataText($value, 512, '(invalid value)');
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::metadataText(
            is_string($json) ? $json : gettype($value),
            512,
            '(invalid value)'
        );
    }

    private static function metadataText(string $value, int $maxBytes, string $fallback): string
    {
        if (!preg_match('//u', $value)) { return $fallback; }
        $value = preg_replace('/[\x00-\x1F\x7F]/', '?', $value) ?? $fallback;
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        $value = substr($value, 0, $maxBytes);
        while ($value !== '' && !preg_match('//u', $value)) { $value = substr($value, 0, -1); }
        $value = rtrim($value);
        return $value !== '' ? $value : $fallback;
    }
}
