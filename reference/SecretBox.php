<?php

declare(strict_types=1);

namespace Reference;

/**
 * SecretBox — authenticated encryption for secrets at rest, with a rotation path.
 *
 * WHAT THIS IS FOR
 * Third-party credentials the application must be able to USE, not merely check:
 * an API client secret, a certificate passphrase, a device password. They cannot
 * be hashed — the system has to recover the plaintext to authenticate outbound.
 * So they are encrypted with a key that lives outside the database, and the
 * blast radius of a database dump stops at ciphertext.
 *
 * It is emphatically NOT for user passwords. Those get bcrypt/argon2 and are
 * never recoverable. If you find yourself reaching for this class for a password,
 * the requirement is wrong, not the tool.
 *
 * THE DECISIONS
 *
 * AES-256-GCM, not CBC. GCM authenticates as well as encrypts: a modified
 * ciphertext fails to open instead of decrypting to garbage that some downstream
 * parser then treats as data. Unauthenticated encryption at rest is a footgun
 * that only fires when someone has already gained write access — exactly when
 * you least want a padding-oracle or bit-flipping surprise.
 *
 * Derived keys, not the master key. The stored key is a master; the key actually
 * used is HKDF-SHA256(master, info = "purpose"). Two consequences: the master
 * never touches ciphertext, and separate purposes (a bank credential, a device
 * password, a per-tenant field) get cryptographically independent keys, so
 * compromise of one derived key does not unlock the others.
 *
 * Context as associated data. The caller passes a context string — a tenant id, a
 * column name — that is authenticated but not encrypted. A ciphertext copied from
 * one tenant's row into another's fails to open, so a database-level row swap is
 * detected rather than silently honoured.
 *
 * A versioned, key-identified envelope: `box.v1.<key-id>.<base64(iv|tag|ct)>`.
 * The key id is the whole rotation story. Without it, rotation means "decrypt
 * everything and re-encrypt in one transaction, and hope nothing is written
 * meanwhile". With it:
 *
 *   1. Add the new key to the ring with a new id and mark it current. Nothing
 *      breaks: old envelopes still name the old key, which is still in the ring.
 *   2. New writes use the new key automatically.
 *   3. Sweep the old rows at leisure — `needsRotation()` says which they are —
 *      decrypting with whichever key the envelope names and re-encrypting with
 *      the current one. This is interruptible and restartable.
 *   4. Only when no envelope names the old key can it be removed from the ring.
 *
 * TRADE-OFF ACCEPTED
 * The master key sits in the process environment, so anyone who can read the
 * environment of the web process can decrypt everything. On managed shared
 * hosting there is no KMS, no HSM, and no separate secrets daemon to hand the
 * work to; the honest description is "this raises the cost of a database leak,
 * it does not defend against host compromise". Saying that plainly is more useful
 * than implying a guarantee the deployment cannot make.
 */
final class KeyRing
{
    /** @var array<string,string> key id => 32 raw bytes */
    private array $keys = [];

    private string $currentId;

    /**
     * @param array<string,string> $keys id => base64 of 32 bytes, or 32 raw bytes
     */
    public function __construct(array $keys, string $currentId)
    {
        foreach ($keys as $id => $material) {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', (string) $id) !== 1) {
                throw new \InvalidArgumentException('Key id must be a short slug.');
            }
            $this->keys[(string) $id] = self::normalise((string) $material);
        }

        if (!isset($this->keys[$currentId])) {
            throw new \InvalidArgumentException('Current key id is not in the ring.');
        }

        $this->currentId = $currentId;
    }

    /**
     * Read a ring from one environment variable:
     *   APP_SECRET_KEYS="v2:<base64>,v1:<base64>"   (first entry is current)
     * One variable keeps deployment simple on hosts where you get a form field
     * per variable and nothing else.
     */
    public static function fromEnv(string $variable = 'APP_SECRET_KEYS'): self
    {
        $raw = trim((string) (getenv($variable) ?: ($_ENV[$variable] ?? '')));
        if ($raw === '') {
            throw new \RuntimeException($variable . ' is not set.');
        }

        $keys = [];
        $currentId = null;
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!str_contains($entry, ':')) {
                throw new \RuntimeException($variable . ' entries must look like "id:base64key".');
            }
            [$id, $material] = explode(':', $entry, 2);
            $id = trim($id);
            $keys[$id] = trim($material);
            $currentId ??= $id;
        }

        return new self($keys, (string) $currentId);
    }

    public function currentId(): string
    {
        return $this->currentId;
    }

    public function master(string $id): string
    {
        if (!isset($this->keys[$id])) {
            throw new \RuntimeException('Unknown key id "' . $id . '". Do not drop a key while envelopes still name it.');
        }

        return $this->keys[$id];
    }

    private static function normalise(string $material): string
    {
        $decoded = base64_decode($material, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }
        if (strlen($material) === 32) {
            return $material;
        }

        throw new \InvalidArgumentException('Keys must be 32 bytes (raw or base64).');
    }
}

final class SecretBox
{
    private const PREFIX  = 'box.v1.';
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;   // 96-bit nonce, the size GCM is defined for
    private const TAG_LEN = 16;

    public function __construct(private KeyRing $ring, private string $purpose = 'secret')
    {
    }

    public function encrypt(string $plaintext, string $context = ''): string
    {
        $keyId = $this->ring->currentId();
        $key = $this->derive($keyId);

        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($keyId, $context),
            self::TAG_LEN
        );

        self::wipe($key);

        if ($ciphertext === false || strlen($tag) !== self::TAG_LEN) {
            throw new \RuntimeException('Encryption failed.');
        }

        return self::PREFIX . $keyId . '.' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $envelope, string $context = ''): string
    {
        [$keyId, $iv, $tag, $ciphertext] = $this->parse($envelope);

        $key = $this->derive($keyId);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $this->aad($keyId, $context)
        );
        self::wipe($key);

        if ($plaintext === false) {
            // One message for every failure mode. "Wrong key" and "tampered" and
            // "wrong tenant" are the same event from the caller's point of view,
            // and distinguishing them in an error string helps only an attacker.
            throw new \RuntimeException('Secret could not be decrypted (wrong key, wrong context, or tampered).');
        }

        return $plaintext;
    }

    public function isEnvelope(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /** True when this envelope was written with a key that is no longer current. */
    public function needsRotation(string $envelope): bool
    {
        [$keyId] = $this->parse($envelope);

        return $keyId !== $this->ring->currentId();
    }

    /** Decrypt with whatever key the envelope names, re-encrypt with the current one. */
    public function rotate(string $envelope, string $context = ''): string
    {
        return $this->encrypt($this->decrypt($envelope, $context), $context);
    }

    /**
     * What a secret looks like in a UI. Rendering the last few characters lets an
     * operator confirm WHICH credential is stored without the page ever carrying
     * the credential itself — the form posts back the mask, and the save path
     * treats an unchanged mask as "leave it alone".
     */
    public static function mask(string $value, int $visible = 4): string
    {
        if ($value === '') {
            return '';
        }
        $visible = max(0, min($visible, 6));
        if (strlen($value) <= $visible) {
            return str_repeat('*', 8);
        }

        return str_repeat('*', 8) . substr($value, -$visible);
    }

    public static function looksMasked(string $value): bool
    {
        return str_starts_with($value, '********');
    }

    /**
     * Best-effort erasure of a derived key from memory. libsodium's memzero is
     * the only way to do this in PHP that the optimiser cannot elide; where the
     * extension is absent (a real possibility on shared hosting) the key simply
     * lives until the request ends, which is what would have happened anyway.
     */
    private static function wipe(string &$key): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }
    }

    private function derive(string $keyId): string
    {
        return hash_hkdf('sha256', $this->ring->master($keyId), 32, 'secretbox.v1.' . $this->purpose);
    }

    private function aad(string $keyId, string $context): string
    {
        return 'secretbox.v1|' . $this->purpose . '|' . $keyId . '|' . $context;
    }

    /** @return array{0:string,1:string,2:string,3:string} */
    private function parse(string $envelope): array
    {
        if (!$this->isEnvelope($envelope)) {
            throw new \RuntimeException('Not a SecretBox envelope.');
        }

        $body = substr($envelope, strlen(self::PREFIX));
        $dot = strpos($body, '.');
        if ($dot === false) {
            throw new \RuntimeException('Malformed envelope: no key id.');
        }

        $keyId = substr($body, 0, $dot);
        $raw = base64_decode(substr($body, $dot + 1), true);
        if ($raw === false || strlen($raw) <= self::IV_LEN + self::TAG_LEN) {
            throw new \RuntimeException('Malformed envelope: truncated payload.');
        }

        return [
            $keyId,
            substr($raw, 0, self::IV_LEN),
            substr($raw, self::IV_LEN, self::TAG_LEN),
            substr($raw, self::IV_LEN + self::TAG_LEN),
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $v1 = base64_encode(random_bytes(32));
    $v2 = base64_encode(random_bytes(32));

    $old = new SecretBox(new KeyRing(['v1' => $v1], 'v1'), 'integration');
    $envelope = $old->encrypt('client-secret-value', 'tenant:42');
    echo 'stored: ', $envelope, PHP_EOL;

    // Rotation: v2 becomes current, v1 stays in the ring until the sweep is done.
    $new = new SecretBox(new KeyRing(['v2' => $v2, 'v1' => $v1], 'v2'), 'integration');
    echo 'needs rotation: ', var_export($new->needsRotation($envelope), true), PHP_EOL;
    echo 'still readable: ', $new->decrypt($envelope, 'tenant:42'), PHP_EOL;

    $rotated = $new->rotate($envelope, 'tenant:42');
    echo 'rotated: ', $rotated, PHP_EOL;
    echo 'needs rotation now: ', var_export($new->needsRotation($rotated), true), PHP_EOL;

    try {
        $new->decrypt($rotated, 'tenant:43');
    } catch (\RuntimeException $e) {
        echo 'wrong tenant context: ', $e->getMessage(), PHP_EOL;
    }
    echo 'masked for display: ', SecretBox::mask('client-secret-value'), PHP_EOL;
}
