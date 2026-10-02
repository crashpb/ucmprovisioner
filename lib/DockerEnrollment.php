<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** Existing-container selection and bounded enrollment probes. */
final class DockerEnrollment
{
    /** Decode data only; SSH/sudo diagnostics must never become authorized-key bytes. */
    public static function decodeAuthorizedKeys(string $output): string
    {
        if (strlen($output) > 2097152 || preg_match_all('/^UCM_AUTHORIZED_KEYS_B64=([A-Za-z0-9+\/=]*)\r?$/m', $output, $matches) !== 1) {
            throw new \RuntimeException('Docker authorized_keys read returned no unique bounded data record');
        }
        $bytes = base64_decode($matches[1][0], true);
        if ($bytes === false || base64_encode($bytes) !== $matches[1][0]) {
            throw new \RuntimeException('Docker authorized_keys data record was invalid');
        }
        return $bytes;
    }

    /** Verify the jailed controller/owner channel with a non-secret round trip. */
    public static function verifySftp(\phpseclib3\Net\SFTP $sftp): array
    {
        if (!$sftp->chdir('/tftpboot')) {
            throw new \RuntimeException('The jailed SFTP channel could not enter /tftpboot');
        }
        $listed = $sftp->nlist('.');
        if (!is_array($listed)) {
            throw new \RuntimeException('The jailed SFTP channel could not list /tftpboot');
        }
        $name = 'ucmp-sftp-probe-' . bin2hex(random_bytes(12));
        $content = 'ucmprovisioner sftp probe ' . bin2hex(random_bytes(16)) . "\n";
        $created = false;
        try {
            // A failed upload can still leave a partial file at this unique name.
            $created = true;
            if (!$sftp->put($name, $content)) {
                throw new \RuntimeException('The jailed SFTP channel could not write its probe');
            }
            $readBack = $sftp->get($name);
            if (!is_string($readBack) || !hash_equals($content, $readBack)) {
                throw new \RuntimeException('The jailed SFTP probe did not round-trip its non-secret contents');
            }
            if (!$sftp->delete($name)) {
                throw new \RuntimeException('The jailed SFTP probe could not be deleted');
            }
            $created = false;
        } finally {
            if ($created) {
                try { $sftp->delete($name); } catch (\Throwable $ignored) {}
            }
        }
        return ['entries'=>count($listed)];
    }

    /**
     * Parse the deliberately narrow docker-inspect format emitted by the
     * enrollment preflight. It contains no container environment or secrets.
     *
     * @return list<array<string,string>>
     */
    public static function parseInspection(string $output): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line === 'UCM_DOCKER_META=1') {
                continue;
            }
            $row = [];
            foreach (explode("\t", $line) as $field) {
                $pair = explode('=', $field, 2);
                if (count($pair) === 2) {
                    $row[$pair[0]] = $pair[1];
                }
            }
            if (isset($row['ID'], $row['NAME'])) {
                $row['NAME'] = ltrim($row['NAME'], '/');
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Choose a compatible running container. A saved name/ID is authoritative;
     * blank configuration is accepted only when exactly one candidate exists.
     *
     * @param list<array<string,string>> $rows
     * @return array<string,string>
     */
    public static function selectContainer(array $rows, string $requested = ''): array
    {
        $requested = ltrim(trim($requested), '/');
        $compatible = static function (array $row): bool {
            if (($row['RUNNING'] ?? '') !== 'true') {
                return false;
            }
            $bind = (string)($row['BIND'] ?? '');
            $parts = explode('|', $bind, 3);
            return count($parts) === 3
                && $parts[0] === '/opt/tftp-server/tftpboot'
                && (($row['TFTP'] ?? '') === '1');
        };

        if ($requested !== '') {
            foreach ($rows as $row) {
                if (($row['NAME'] ?? '') !== $requested && ($row['ID'] ?? '') !== $requested) {
                    continue;
                }
                if (!$compatible($row)) {
                    throw new \RuntimeException('The saved TFTP container exists but is stopped or incompatible; it must be running with a bind from /opt/tftp-server/tftpboot and UDP/69 published or host-networked');
                }
                return $row;
            }
            throw new \RuntimeException('The saved TFTP container was not found by Docker');
        }

        $candidates = array_values(array_filter($rows, $compatible));
        if (count($candidates) !== 1) {
            throw new \RuntimeException(count($candidates) === 0
                ? 'No running compatible TFTP container was found; Docker enrollment does not create containers or install images'
                : 'Docker enrollment found multiple compatible running TFTP containers; save the exact tftp_container name before retrying');
        }
        return $candidates[0];
    }

    /** Merge public keys without discarding existing authorized_keys entries. */
    public static function mergeAuthorizedKeys(string $existing, array $newKeys): string
    {
        $lines = [];
        $seenLines = [];
        $existingKeySignatures = [];
        $keySignature = static function (string $line): ?string {
            if (preg_match('/(?:^|\s)(ssh-(?:rsa|ed25519)|ecdsa-sha2-[^\s]+)\s+([A-Za-z0-9+\/=]+)/', $line, $match)) {
                return $match[1] . ' ' . $match[2];
            }
            return null;
        };

        $parts = preg_split('/(\r\n|\n|\r)/', $existing, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $existingLines = [];
        for ($i = 0; $i < count($parts); $i += 2) {
            $body = (string)($parts[$i] ?? '');
            $ending = (string)($parts[$i + 1] ?? '');
            if ($body === '' && $ending === '' && $i === count($parts) - 1) {
                continue;
            }
            $existingLines[] = [$body, $ending];
        }
        foreach ($existingLines as [$line, $ending]) {
            $lineSignature = 'line:' . hash('sha256', $line);
            if (isset($seenLines[$lineSignature])) {
                continue;
            }
            $seenLines[$lineSignature] = true;
            $lines[] = $line . $ending;
            $signature = $keySignature($line);
            if ($signature !== null) {
                $existingKeySignatures[$signature] = true;
            }
        }
        $existingOutput = implode('', $lines);
        $eol = str_contains($existing, "\r\n") ? "\r\n" : "\n";
        $newLines = [];
        foreach ($newKeys as $key) {
            $key = trim((string)$key);
            if ($key === '') {
                continue;
            }
            $signature = $keySignature($key);
            if ($signature !== null && isset($existingKeySignatures[$signature])) {
                continue;
            }
            $lineSignature = 'line:' . hash('sha256', $key);
            if (!isset($seenLines[$lineSignature])) {
                $seenLines[$lineSignature] = true;
                $newLines[] = $key;
            }
        }
        if ($newLines !== []) {
            if ($existingOutput !== '' && !str_ends_with($existingOutput, "\n")) {
                $existingOutput .= $eol;
            }
            $existingOutput .= implode($eol, $newLines) . $eol;
        }
        return $existingOutput;
    }
}
