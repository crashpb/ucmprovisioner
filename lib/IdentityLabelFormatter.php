<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/**
 * Bounded token replacement for AD-derived phone labels.
 *
 * This intentionally is not an expression language: templates can only insert
 * known identity fields among literal display characters.
 */
final class IdentityLabelFormatter
{
    public const DEFAULT_PATTERNS = [
        'phone_label' => '{label}',
        'line_label' => '{label}',
        'blf_label' => '{label}',
        'directory_label' => '{label}',
    ];

    private const TOKENS = [
        'display', 'given', 'initial', 'surname', 'label', 'extension',
        'department', 'site',
    ];

    public static function validatePattern(string $pattern): string
    {
        if (strlen($pattern) > 128) {
            throw new \InvalidArgumentException('Identity label pattern cannot exceed 128 bytes');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pattern)) {
            throw new \InvalidArgumentException('Identity label pattern contains control characters');
        }
        preg_match_all('/\{([^{}]+)\}/', $pattern, $matches);
        foreach ($matches[1] ?? [] as $token) {
            if (!in_array((string)$token, self::TOKENS, true)) {
                throw new \InvalidArgumentException("Unknown identity label token {{$token}}");
            }
        }
        $withoutKnownTokens = preg_replace(
            '/\{(?:' . implode('|', array_map('preg_quote', self::TOKENS)) . ')\}/',
            '',
            $pattern
        );
        if (str_contains((string)$withoutKnownTokens, '{') || str_contains((string)$withoutKnownTokens, '}')) {
            throw new \InvalidArgumentException('Identity label pattern contains an incomplete token');
        }
        return $pattern;
    }

    /**
     * @param array<string,mixed> $identity Canonical identity after canonical overrides.
     * @param array<string,mixed> $patterns Per-output patterns.
     * @param array<string,mixed> $context Optional site/attribute context.
     * @return array<string,string>
     */
    public static function outputs(array $identity, array $patterns = [], array $context = []): array
    {
        $tokens = self::tokens($identity, $context);
        $result = [];
        foreach (self::DEFAULT_PATTERNS as $field => $default) {
            $pattern = array_key_exists($field, $patterns)
                ? (string)$patterns[$field]
                : $default;
            self::validatePattern($pattern);
            $result[$field] = strtr($pattern, $tokens);
        }
        return $result;
    }

    /** @return array<string,string> */
    private static function tokens(array $identity, array $context): array
    {
        $display = trim((string)($identity['display_name'] ?? ''));
        $given = trim((string)($identity['given_name'] ?? ($context['given_name'] ?? '')));
        $surname = trim((string)($identity['surname'] ?? ($context['surname'] ?? '')));
        $initial = trim((string)($identity['initial'] ?? ($context['initial'] ?? '')));
        if ($initial === '' && $given !== '') {
            $initial = function_exists('mb_substr')
                ? mb_substr($given, 0, 1, 'UTF-8')
                : substr($given, 0, 1);
        }
        $label = trim((string)($identity['label'] ?? ''));
        if ($label === '') { $label = $display; }

        return [
            '{display}' => $display,
            '{given}' => $given,
            '{initial}' => $initial,
            '{surname}' => $surname,
            '{label}' => $label,
            '{extension}' => trim((string)($identity['extension'] ?? '')),
            '{department}' => trim((string)($identity['department'] ?? '')),
            '{site}' => trim((string)($context['site'] ?? ($identity['site'] ?? ''))),
        ];
    }
}
