<?php
/* ============================================================
   VERO — includes/financeiro_helpers.php
   Helpers compartilhados do módulo financeiro
   ============================================================ */

if (!function_exists('finCurrentTenantId')) {
    function finCurrentTenantId(): int
    {
        return (int)($_SESSION['tenant_id'] ?? 0);
    }
}

if (!function_exists('finCurrentUserId')) {
    function finCurrentUserId(): ?int
    {
        if (function_exists('currentUser')) {
            $u = currentUser();
            if (is_array($u) && isset($u['id'])) {
                return (int)$u['id'];
            }
        }
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }
}

if (!function_exists('finRequestData')) {
    function finRequestData(): array
    {
        $data = $_POST ?: [];
        $raw = file_get_contents('php://input');
        if ($raw) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }
        return $data;
    }
}

if (!function_exists('finCheckCsrf')) {
    function finCheckCsrf(?array $data = null): void
    {
        $data ??= finRequestData();
        $token = (string)($data['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        /* BUG-CSRF (QA 19/07): valida com a janela de tolerância multi-aba de
           functions.php (fallback estrito se o helper não estiver carregado). */
        $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
        $valido = function_exists('csrf_token_valido')
            ? csrf_token_valido($token)
            : ($sessionToken !== '' && $token !== '' && hash_equals($sessionToken, $token));
        if (!$valido) {
            http_response_code(403);
            echo json_encode([
                'ok' => false,
                'message' => 'Sessão renovada — o token de segurança expirou. Tente novamente.',
                'error' => 'csrf',
                'csrf_token' => (string)($_SESSION['csrf_token'] ?? ''),
                'data' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

if (!function_exists('finJson')) {
    function finJson(bool $ok, string $message, mixed $data = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('finInsertLog')) {
    function finInsertLog(PDO $pdo, int $tenantId, ?int $usuarioId, string $entidade, ?int $entidadeId, string $acao, mixed $antes = null, mixed $depois = null): void
    {
        $stmt = $pdo->prepare("\n            INSERT INTO financeiro_logs\n                (tenant_id, usuario_id, entidade, entidade_id, acao, dados_antes, dados_depois, ip, user_agent)\n            VALUES\n                (?, ?, ?, ?, ?, ?, ?, ?, ?)\n        ");
        $stmt->execute([
            $tenantId,
            $usuarioId,
            $entidade,
            $entidadeId,
            $acao,
            $antes === null ? null : json_encode(finSanitizeSensitive($antes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $depois === null ? null : json_encode(finSanitizeSensitive($depois), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }
}

if (!function_exists('finSanitizeSensitive')) {
    function finSanitizeSensitive(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitive = [
            'senha', 'password', 'secret', 'token', 'access_token', 'refresh_token',
            'client_secret', 'client_secret_encrypted', 'certificado_senha', 'certificado_senha_enc',
            'sandbox_token', 'authorization', 'bearer', 'chave', 'key'
        ];

        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string)$k);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if (str_contains($lk, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $out[$k] = empty($v) ? null : '[mascarado]';
            } elseif (is_array($v)) {
                $out[$k] = finSanitizeSensitive($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('finColumnExists')) {
    function finColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("\n            SELECT COUNT(*)\n              FROM INFORMATION_SCHEMA.COLUMNS\n             WHERE TABLE_SCHEMA = DATABASE()\n               AND TABLE_NAME = ?\n               AND COLUMN_NAME = ?\n        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('badgeAtivo')) {
    function badgeAtivo(int|bool $ativo): string
    {
        $ativo = (int)$ativo === 1;
        $label = $ativo ? 'Ativo' : 'Inativo';
        $type = $ativo ? 'success' : 'neutral';

        if (function_exists('badge')) {
            return badge($label, $type, $ativo ? 'ti-check' : 'ti-circle-off');
        }

        $safeLabel = function_exists('h') ? h($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<span class="bios-badge bios-badge--' . $type . '">' . $safeLabel . '</span>';
    }
}

if (!function_exists('badgeFinanceiroStatus')) {
    function badgeFinanceiroStatus(?string $status): string
    {
        $status = strtolower(trim((string)$status));
        $map = [
            'pago' => ['Pago', 'success', 'ti-check'],
            'paga' => ['Paga', 'success', 'ti-check'],
            'confirmado' => ['Confirmado', 'success', 'ti-check'],
            'pendente' => ['Pendente', 'warning', 'ti-clock'],
            'gerado' => ['Gerado', 'info', 'ti-file-invoice'],
            'registrado' => ['Registrado', 'info', 'ti-file-check'],
            'vencido' => ['Vencido', 'danger', 'ti-alert-circle'],
            'vencida' => ['Vencida', 'danger', 'ti-alert-circle'],
            'cancelado' => ['Cancelado', 'neutral', 'ti-ban'],
            'cancelada' => ['Cancelada', 'neutral', 'ti-ban'],
            'estornado' => ['Estornado', 'neutral', 'ti-arrow-back-up'],
            'estornada' => ['Estornada', 'neutral', 'ti-arrow-back-up'],
        ];

        [$label, $type, $icon] = $map[$status] ?? [ucfirst($status ?: '—'), 'neutral', 'ti-circle'];
        if (function_exists('badge')) {
            return badge($label, $type, $icon);
        }
        $safeLabel = function_exists('h') ? h($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<span class="bios-badge bios-badge--' . $type . '">' . $safeLabel . '</span>';
    }
}

if (!function_exists('finDecimal')) {
    function finDecimal(mixed $value): float
    {
        if (is_string($value)) {
            $value = str_replace(['R$', ' ', '.'], '', $value);
            $value = str_replace(',', '.', $value);
        }
        return round((float)$value, 2);
    }
}

if (!function_exists('finMoney')) {
    function finMoney(mixed $value): string
    {
        if (function_exists('brl')) {
            return brl((float)$value);
        }
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}
