<?php
namespace FreePBX\modules\Ucmprovisioner;

final class PhoneAssignment
{
    /**
     * Choose the physical phone's fallback label for one logical extension.
     *
     * Final audited output overrides are deliberately not copied here: they
     * remain attached to the logical extension and are resolved at build time.
     *
     * @return array{label:string,source:string}
     */
    public static function fallbackLabel(array $extension): array
    {
        $identity = is_array($extension['identity'] ?? null) ? $extension['identity'] : [];
        foreach ([
            [(string)($identity['label'] ?? ''), 'accepted AD short label'],
            [(string)($identity['display_name'] ?? ''), 'accepted AD display name'],
            [(string)($extension['description'] ?? ''), 'FreePBX display name'],
            [(string)($extension['id'] ?? ''), 'extension number'],
        ] as [$candidate, $source]) {
            $candidate = trim((string)preg_replace('/[\x00-\x1F\x7F]/', '', $candidate));
            if ($candidate !== '') {
                return ['label'=>mb_substr($candidate, 0, 128, 'UTF-8'), 'source'=>$source];
            }
        }
        return ['label'=>'', 'source'=>'none'];
    }

    /** Return the two extension outcomes without ever moving a MAC between phone rows. */
    public static function plan(array $target, ?array $other, string $desiredExtension, string $collisionAction): array
    {
        if (!in_array($collisionAction, ['swap', 'move'], true)) {
            throw new \InvalidArgumentException('Collision action must be swap or move');
        }
        $previous = trim((string)($target['extension'] ?? ''));
        $otherPrevious = trim((string)($other['extension'] ?? ''));
        $changed = $previous !== $desiredExtension;
        $otherExtension = null;
        if ($other && $collisionAction === 'swap' && $previous !== '' && $previous !== $desiredExtension) {
            $otherExtension = $previous;
        }
        return [
            'target_extension' => $desiredExtension !== '' ? $desiredExtension : null,
            'target_prev_extension' => $changed && $previous !== '' ? $previous : ($target['prev_extension'] ?? null),
            'target_status' => self::status((string)($target['mac'] ?? ''), $desiredExtension),
            'other_extension' => $otherExtension,
            'other_prev_extension' => $other && $otherPrevious !== '' ? $otherPrevious : ($other['prev_extension'] ?? null),
            'other_status' => $other ? self::status((string)($other['mac'] ?? ''), (string)$otherExtension) : null,
        ];
    }

    private static function status(string $mac, string $extension): string
    {
        if ($extension === '') { return $mac !== '' ? 'unassigned' : 'discovered'; }
        return $mac !== '' ? 'bound' : 'discovered';
    }
}
