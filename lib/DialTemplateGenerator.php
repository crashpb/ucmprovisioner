<?php
namespace FreePBX\modules\Ucmprovisioner;

require_once __DIR__ . '/DeterministicVersionStamp.php';
require_once __DIR__ . '/AssetFilename.php';

/** Builds the Cisco dial-template companion file from GUI rule rows. */
class DialTemplateGenerator
{
    private const TONES = [
        'Bellcore-Inside', 'Bellcore-Outside', 'Bellcore-Busy', 'Bellcore-Reorder',
        'Bellcore-CallWaiting', 'Bellcore-Hold', 'Bellcore-Reminder',
        'Bellcore-Confirmation', 'Bellcore-Permanent', 'Bellcore-None',
        'Cisco-Zip', 'Cisco-ZipZip', 'Cisco-BeepBonk',
    ];

    /** One canonical companion name for both SEP references and TFTP writes. */
    public static function filenameFor(array $settings): string
    {
        return AssetFilename::content('d', self::fromSettings($settings), 'xml');
    }

    /** Final normalized bytes are the identity, including per-phone rule overrides. */
    public static function fromSettings(array $settings): string
    {
        $rules = (array)($settings['dial_rules'] ?? []);
        if (!$rules) {
            $rules = [['match'=>(string)($settings['dial_pattern'] ?? '*'), 'timeout'=>(int)($settings['digit_timeout'] ?? 5)]];
        }
        return (new self())->generate($rules);
    }

    public function generate(array $rules): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('dialTemplate');
        $doc->appendChild($root);
        $versionStamp = $doc->createElement('versionStamp');
        $root->appendChild($versionStamp);
        foreach (array_slice($rules, 0, 100) as $rule) {
            if (!is_array($rule)) { continue; }
            $match = str_replace(["\r", "\n"], '', (string)($rule['match'] ?? ''));
            // Cisco documents "\*" as the literal-star token. Remove only that
            // valid escape before rejecting every other non-dial-plan character.
            $matchCheck = str_replace('\\*', '', $match);
            if (strlen($match) > 128 || preg_match('/[^0-9#.*,]/', $matchCheck)) { continue; }
            $el = $doc->createElement('TEMPLATE');
            $el->setAttribute('match', $match);
            $el->setAttribute('timeout', (string)max(0, min(30, (int)($rule['timeout'] ?? 5))));
            $line = (int)($rule['line'] ?? 0);
            if ($line > 0) { $el->setAttribute('line', (string)min(200, $line)); }
            $rewrite = str_replace(["\r", "\n"], '', (string)($rule['rewrite'] ?? ''));
            if ($rewrite !== '' && strlen($rewrite) <= 128 && !preg_match('/[^0-9.%]/', $rewrite)) {
                $el->setAttribute('rewrite', $rewrite);
            }
            $tone = (string)($rule['tone'] ?? '');
            if (in_array($tone, self::TONES, true)) { $el->setAttribute('tone', $tone); }
            $root->appendChild($el);
        }
        $versionStamp->appendChild($doc->createTextNode(
            DeterministicVersionStamp::fromContent((string)$doc->saveXML())
        ));
        return (string)$doc->saveXML();
    }
}
