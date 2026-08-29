<?php
/**
 * VERO — Crypto helpers
 * Usado para cifrar/decifrar senhas da Control iD.
 * Algoritmo: AES-256-GCM
 */

if (!function_exists('bios_load_env_file')) {
    function bios_load_env_file(): void
    {
        $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($envPath) || !is_readable($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                continue;
            }

            // Remove aspas simples ou duplas do valor
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

if (!function_exists('crypto_key_bytes')) {
    function crypto_key_bytes(): string
    {
        bios_load_env_file();

        $key = getenv('BIOS_CRYPTO_KEY') ?: ($_ENV['BIOS_CRYPTO_KEY'] ?? '');

        if (!$key) {
            throw new RuntimeException('Chave de criptografia ausente. Defina BIOS_CRYPTO_KEY no .env.');
        }

        if (str_starts_with($key, 'base64:')) {
            $raw = base64_decode(substr($key, 7), true);

            if ($raw === false) {
                throw new RuntimeException('BIOS_CRYPTO_KEY inválida: base64 malformado.');
            }
        } else {
            $raw = $key;
        }

        if (strlen($raw) !== 32) {
            throw new RuntimeException('BIOS_CRYPTO_KEY inválida: a chave precisa ter 32 bytes para AES-256-GCM.');
        }

        return $raw;
    }
}

if (!function_exists('crypto_encrypt')) {
    function crypto_encrypt(string $plainText): string
    {
        $key = crypto_key_bytes();

        $iv = random_bytes(12);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($cipherText === false) {
            throw new RuntimeException('Falha ao cifrar o conteúdo.');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipherText);
    }
}

if (!function_exists('crypto_decrypt')) {
    function crypto_decrypt(string $payload): string
    {
        $key = crypto_key_bytes();

        if (!str_starts_with($payload, 'v1:')) {
            throw new RuntimeException('Payload criptografado inválido ou versão não suportada.');
        }

        $raw = base64_decode(substr($payload, 3), true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Payload criptografado inválido.');
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);

        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Falha ao decifrar o conteúdo.');
        }

        return $plainText;
    }
}

/* ============================================================
   CAMADA CENTRAL DE CAMPOS SENSÍVEIS (Fase 2)
   Envelope: master (BIOS_CRYPTO_KEY) -> subchave por tenant+propósito (HKDF).
   Cifra de campo: XChaCha20-Poly1305 (libsodium) com AAD ligando o tenant.
   Blind index: HMAC-SHA256 (subchave por tenant+tipo) sobre valor normalizado.
   NÃO altera as funções legadas crypto_encrypt/decrypt (catraca/Sicoob).
   Versão do esquema de campo: 'f1' (rotação futura via crypto_chaves).
   ============================================================ */

if (!function_exists('crypto_subkey')) {
    /** Deriva subchave de 32 bytes do master por tenant+propósito (HKDF-SHA256). */
    function crypto_subkey(int $tenantId, string $purpose): string
    {
        $master = crypto_key_bytes(); // 32 bytes (BIOS_CRYPTO_KEY)
        $info = 'bios.v1.' . $purpose . '.tenant:' . $tenantId;
        return hash_hkdf('sha256', $master, 32, $info);
    }
}

if (!function_exists('encryptField')) {
    /**
     * Cifra um campo sensível. Mantém NULL/'' inalterados (preserva consultas de vazio).
     * Formato: 'f1:' . base64(nonce[24] . cipher+tag).
     */
    function encryptField(?string $plain, int $tenantId): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        if ($tenantId <= 0) {
            throw new RuntimeException('tenant_id obrigatório para cifrar campo.');
        }
        $key = crypto_subkey($tenantId, 'field');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = 'bios|f1|tenant:' . $tenantId;
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $key);
        sodium_memzero($key);
        return 'f1:' . base64_encode($nonce . $cipher);
    }
}

if (!function_exists('isEncryptedField')) {
    function isEncryptedField($value): bool
    {
        return is_string($value) && str_starts_with($value, 'f1:');
    }
}

if (!function_exists('decryptField')) {
    /**
     * Decifra um campo. Se o valor não estiver no formato 'f1:' (dado legado em claro
     * durante a migração faseada), retorna como está — leitura tolerante.
     */
    function decryptField(?string $payload, int $tenantId): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }
        if (!str_starts_with($payload, 'f1:')) {
            return $payload; // legado em claro: compatibilidade de leitura na migração
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Campo cifrado inválido.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = crypto_subkey($tenantId, 'field');
        $aad = 'bios|f1|tenant:' . $tenantId;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $aad, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar campo (chave/tenant incorretos ou dado adulterado).');
        }
        return $plain;
    }
}

if (!function_exists('cryptoNormalize')) {
    /** Normaliza valor para blind index (CPF/telefone só dígitos; e-mail minúsculo). */
    function cryptoNormalize(string $value, string $type): string
    {
        $t = strtolower($type);
        if (in_array($t, ['cpf', 'telefone', 'celular', 'phone', 'rg', 'documento'], true)) {
            return preg_replace('/\D+/', '', $value);
        }
        return strtolower(trim($value));
    }
}

if (!function_exists('hashLookup')) {
    /** Blind index HMAC-SHA256 (hex) por tenant+tipo. Retorna null para vazio. */
    function hashLookup(?string $value, int $tenantId, string $type): ?string
    {
        if ($value === null) {
            return null;
        }
        $norm = cryptoNormalize($value, $type);
        if ($norm === '') {
            return null;
        }
        $key = crypto_subkey($tenantId, 'blind.' . strtolower($type));
        $h = hash_hmac('sha256', $norm, $key);
        sodium_memzero($key);
        return $h;
    }
}

if (!function_exists('maskSensitive')) {
    /** Mascaramento padrão por tipo. $full=true devolve o valor completo (uso autorizado). */
    function maskSensitive(?string $value, string $type, bool $full = false): string
    {
        $v = (string)($value ?? '');
        if ($v === '') {
            return '';
        }
        if ($full) {
            return $v;
        }
        $t = strtolower($type);
        if ($t === 'cpf') {
            $d = preg_replace('/\D+/', '', $v);
            return strlen($d) >= 2 ? '***.***.***-' . substr($d, -2) : '***';
        }
        if (in_array($t, ['email', 'e-mail'], true)) {
            $p = explode('@', $v, 2);
            $u = $p[0] ?? '';
            $dom = $p[1] ?? '';
            $um = strlen($u) <= 2 ? substr($u, 0, 1) . '*' : substr($u, 0, 2) . str_repeat('*', max(1, strlen($u) - 2));
            return $dom !== '' ? $um . '@' . $dom : $um;
        }
        if (in_array($t, ['telefone', 'celular', 'phone'], true)) {
            $d = preg_replace('/\D+/', '', $v);
            return strlen($d) >= 4 ? str_repeat('*', max(0, strlen($d) - 4)) . substr($d, -4) : '****';
        }
        if (in_array($t, ['banco', 'bancario', 'conta', 'agencia'], true)) {
            return strlen($v) > 4 ? str_repeat('*', strlen($v) - 4) . substr($v, -4) : '****';
        }
        return strlen($v) <= 4 ? str_repeat('*', strlen($v)) : substr($v, 0, 1) . str_repeat('*', max(1, strlen($v) - 2)) . substr($v, -1);
    }
}

if (!function_exists('campoSensivelEfetivo')) {
    /**
     * Cutover de LEITURA (Fase 8): devolve o valor "fonte da verdade" de um campo
     * sensível a partir de uma linha já carregada, preferindo a coluna cifrada
     * `<col>_enc` (decifrada) quando presente; cai para a coluna legada em claro
     * enquanto a migração não cobriu 100% (decryptField já tolera legado).
     * Não lança em campo ausente; preserva NULL. Não mascara (quem chama decide).
     */
    function campoSensivelEfetivo(array $row, string $col, int $tenantId): ?string
    {
        $encKey = $col . '_enc';
        if (array_key_exists($encKey, $row) && $row[$encKey] !== null && $row[$encKey] !== '') {
            try {
                return decryptField((string)$row[$encKey], $tenantId);
            } catch (Throwable $e) {
                // falha de decifra não pode quebrar a tela: cai para o legado, se houver
                error_log('[VERO][cutover_leitura] ' . $col . ': ' . $e->getMessage());
            }
        }
        if (array_key_exists($col, $row)) {
            return $row[$col] === null ? null : (string)$row[$col];
        }
        return null;
    }
}

if (!function_exists('canDecryptField')) {
    /** "Need to know": só descriptografa/exibe completo com a permissão indicada. */
    function canDecryptField(string $permSlug): bool
    {
        if (function_exists('hasPermission')) {
            return hasPermission($permSlug);
        }
        $perms = $_SESSION['permissions'] ?? [];
        $role = $_SESSION['user_role'] ?? '';
        if (in_array($role, ['super_admin', 'club_admin'], true)) {
            return true;
        }
        return in_array('*', $perms, true) || in_array($permSlug, $perms, true);
    }
}

/* ============================================================
   CIFRA DE ANEXOS/BLOBS EM REPOUSO (Fase 7)
   Decisão honesta: E2EE puro (servidor sem acesso à chave) inviabilizaria
   acesso legítimo do admin/financeiro a comprovantes/documentos e a
   exportação LGPD/auditoria. Aplica-se cripto FORTE EM REPOUSO do blob com
   a chave-envelope por tenant (XChaCha20-Poly1305): backups/arquivos em disco
   ficam protegidos, e a leitura exige RBAC + auditoria no app.
   Formato: "BX1\0" . nonce[24] . cipher+tag (binário).
   ============================================================ */

if (!function_exists('encryptBlob')) {
    function encryptBlob(string $bytes, int $tenantId): string
    {
        if ($tenantId <= 0) {
            throw new RuntimeException('tenant_id obrigatório para cifrar anexo.');
        }
        $key = crypto_subkey($tenantId, 'blob');
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = 'bios|bx1|tenant:' . $tenantId;
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($bytes, $aad, $nonce, $key);
        sodium_memzero($key);
        return "BX1\0" . $nonce . $cipher;
    }
}

if (!function_exists('isEncryptedBlob')) {
    function isEncryptedBlob(string $bytes): bool
    {
        return strncmp($bytes, "BX1\0", 4) === 0;
    }
}

if (!function_exists('decryptBlob')) {
    function decryptBlob(string $stored, int $tenantId): string
    {
        if (strncmp($stored, "BX1\0", 4) !== 0) {
            return $stored; // anexo legado em claro (compatibilidade durante migração)
        }
        $rest = substr($stored, 4);
        $np = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($rest) <= $np) {
            throw new RuntimeException('Anexo cifrado inválido.');
        }
        $nonce = substr($rest, 0, $np);
        $cipher = substr($rest, $np);
        $key = crypto_subkey($tenantId, 'blob');
        $aad = 'bios|bx1|tenant:' . $tenantId;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, $aad, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            throw new RuntimeException('Falha ao decifrar anexo (chave/tenant incorretos ou dado adulterado).');
        }
        return $plain;
    }
}