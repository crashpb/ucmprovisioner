<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Bounded binary HTTP bodies using PHP/libsodium's authenticated stream format. */
final class ApiFileStream
{
    public const CHUNK_BYTES = 1048576;
    public const MAX_FILE_BYTES = 536870912;
    public const RESUME_BYTES = 16777216;
    private string $buffer = '';
    private ?string $state = null;
    private int $received = 0;
    private bool $final = false;

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push');
    }

    public static function wireBytes(int $size): int
    {
        self::checkSize($size);
        return 24 + $size + max(1, (int)ceil($size / self::CHUNK_BYTES)) * 17;
    }

    private static function checkSize(int $size): void
    {
        if (!self::available()) { throw new \RuntimeException('Binary API transfers require the PHP sodium extension'); }
        if ($size < 0 || $size > self::MAX_FILE_BYTES) { throw new \InvalidArgumentException('Binary transfer size is outside the supported bound'); }
    }

    private static function key(string $secret, string $nonce, string $direction): string
    {
        if (!preg_match('/\A[a-fA-F0-9]{64,128}\z/D', $secret) || !preg_match('/\A[a-f0-9]{32}\z/D', $nonce)
            || !in_array($direction, ['upload','download'], true)) { throw new \InvalidArgumentException('Invalid binary transfer identity'); }
        return hash_hkdf('sha256', $secret, 32, 'ucmp-file-stream-v1:' . $direction, hex2bin($nonce));
    }

    /** @param resource $input The private, seekable source snapshot. */
    public static function reader($input, int $size, string $secret, string $nonce, string $direction, ?int $sourceOffset = null): \Closure
    {
        self::checkSize($size);
        if (!is_resource($input) || ($sourceOffset !== null && ($sourceOffset < 0 || $sourceOffset + $size > (int)fstat($input)['size']))
            || ($sourceOffset === null && (int)fstat($input)['size'] !== $size) || fseek($input, $sourceOffset ?? 0) !== 0) {
            throw new \RuntimeException('Binary source snapshot has changed size');
        }
        [$state, $buffer] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key($secret, $nonce, $direction));
        $offset = 0; $finished = false;
        return static function (int $length) use ($input, $size, &$state, &$buffer, &$offset, &$finished): string {
            if ($length < 1) { return ''; }
            // Fill requested buffers across encryption-record boundaries.
            $length = min($length, self::CHUNK_BYTES);
            while (strlen($buffer) < $length && !$finished) {
                $wanted = min(self::CHUNK_BYTES, $size - $offset); $plain = '';
                while (strlen($plain) < $wanted) {
                    $part = fread($input, $wanted - strlen($plain));
                    if ($part === false || $part === '') { throw new \RuntimeException('Binary source snapshot was truncated'); }
                    $plain .= $part;
                }
                $offset += strlen($plain); $finished = $offset === $size;
                $buffer .= sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $finished
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            }
            $out = substr($buffer, 0, $length); $buffer = substr($buffer, strlen($out));
            return $out;
        };
    }

    /** @param resource $output Private temporary file; never a published path. */
    public function __construct(private $output, private int $size, private string $secret, private string $nonce, private string $direction)
    {
        self::checkSize($size);
        if (!is_resource($output)) { throw new \InvalidArgumentException('A private output stream is required'); }
        self::key($secret, $nonce, $direction);
    }

    public function write(string $bytes): int
    {
        $length = strlen($bytes);
        // cURL and php://input feed bounded pieces; reject oversized direct callers.
        if ($length > self::CHUNK_BYTES + 41) { throw new \RuntimeException('Binary input fragment exceeds its bound'); }
        if ($this->final && $length) { throw new \RuntimeException('Unexpected data after the final binary record'); }
        $this->buffer .= $bytes;
        if ($this->state === null && strlen($this->buffer) >= 24) {
            $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($this->buffer, 0, 24), self::key($this->secret, $this->nonce, $this->direction));
            $this->secret = ''; $this->buffer = substr($this->buffer, 24);
        }
        while ($this->state !== null && !$this->final) {
            $plainLength = min(self::CHUNK_BYTES, $this->size - $this->received); $recordLength = $plainLength + 17;
            if (strlen($this->buffer) < $recordLength) { break; }
            $record = substr($this->buffer, 0, $recordLength); $this->buffer = substr($this->buffer, $recordLength);
            $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $record);
            $last = $this->received + $plainLength === $this->size;
            $expectedTag = $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            if ($opened === false || strlen($opened[0]) !== $plainLength || $opened[1] !== $expectedTag) {
                throw new \RuntimeException('Binary transfer authentication or final record failed');
            }
            for ($offset = 0; $offset < $plainLength; $offset += $written) {
                $written = fwrite($this->output, substr($opened[0], $offset));
                if ($written === false || $written === 0) { throw new \RuntimeException('Cannot write the private received file'); }
            }
            $this->received += $plainLength; $this->final = $last;
        }
        if ($this->final && $this->buffer !== '') { throw new \RuntimeException('Unexpected data after the final binary record'); }
        return $length;
    }

    public function finish(): void
    {
        if (!$this->final || $this->received !== $this->size || $this->buffer !== '') { throw new \RuntimeException('Binary transfer was incomplete'); }
        if (!fflush($this->output) || fseek($this->output, 0) !== 0) { throw new \RuntimeException('Cannot finalize the private received file'); }
    }
}
