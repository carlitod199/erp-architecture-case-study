<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/login_throttle.php  (PT-03 + PT-05 — CSO 08/07)
   Proteção a força bruta e normalização de timing no login, compartilhada
   entre o login WEB (index.php) e o da API (api/v1/rotas/auth.php).
   Usa a tabela auth_audit_logs (acao='login', status='falha'|'sucesso').

   Política (arbitragem A0):
   - por (conta+IP): FALHAS_PAR falhas na janela → bloqueia o par;
   - por IP isolado: FALHAS_IP falhas na janela → bloqueia o IP (ataque
     distribuído a várias contas de um mesmo IP);
   - chave conta+IP (não só conta) evita que um atacante externo TRANQUE a
     conta de um usuário legítimo (DoS de lockout).
   - mensagem SEMPRE genérica; nunca revela se a conta existe (PT-05).
   ============================================================ */

if (!defined('BIOS_LOGIN_DUMMY_HASH')) {
    // hash bcrypt cost 12 fixo — para password_verify "dummy" quando a conta
    // não existe, normalizando o tempo de resposta (mata o oráculo de timing).
    define('BIOS_LOGIN_DUMMY_HASH', '$2y$12$jcnOokgFAUzHY6KZYS0qYOzhf9/P4KfjVriPReVrOB/kS6oazVC.y');
    define('BIOS_LOGIN_FALHAS_PAR', 5);    // conta+IP
    define('BIOS_LOGIN_FALHAS_IP', 20);    // IP isolado
    define('BIOS_LOGIN_JANELA_MIN', 15);   // minutos
}

/** Roda um password_verify descartável para igualar o tempo ao do caminho real. */
function bios_login_dummy_verify(string $senha): void
{
    password_verify($senha !== '' ? $senha : 'x', BIOS_LOGIN_DUMMY_HASH);
}

/** IP do cliente (melhor esforço, sem confiar cegamente em headers de proxy). */
function bios_login_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Está bloqueado por excesso de tentativas? (não bloqueia sucesso anterior —
 * só conta falhas na janela). Retorna true se deve recusar antes de validar.
 */
function bios_login_throttle_bloqueado(PDO $pdo, string $email, string $ip): bool
{
    try {
        // INTERVAL não aceita placeholder em prepare nativo → injeta o inteiro
        // da CONSTANTE validada (não entra dado do usuário; sem risco de injeção).
        $jan = (int)BIOS_LOGIN_JANELA_MIN;
        $sqlPar = "SELECT COUNT(*) FROM auth_audit_logs
                    WHERE acao='login' AND status='falha' AND email=:e AND ip=:ip
                      AND created_at >= (NOW() - INTERVAL {$jan} MINUTE)";
        $st = $pdo->prepare($sqlPar);
        $st->execute([':e' => $email, ':ip' => $ip]);
        if ((int)$st->fetchColumn() >= BIOS_LOGIN_FALHAS_PAR) {
            return true;
        }
        $sqlIp = "SELECT COUNT(*) FROM auth_audit_logs
                   WHERE acao='login' AND status='falha' AND ip=:ip
                     AND created_at >= (NOW() - INTERVAL {$jan} MINUTE)";
        $st2 = $pdo->prepare($sqlIp);
        $st2->execute([':ip' => $ip]);
        return (int)$st2->fetchColumn() >= BIOS_LOGIN_FALHAS_IP;
    } catch (Throwable $e) {
        // A-8: fail-CLOSED. Se o backend do throttle falha, não dá para saber
        // quantas tentativas houve — recusar temporariamente é mais seguro que
        // liberar brute-force ilimitado. Erro na tabela de auditoria é anômalo e
        // curto; enquanto durar, o login é recusado com mensagem genérica.
        error_log('[login_throttle] backend indisponível, fail-closed: ' . $e->getMessage());
        return true;
    }
}

/** Registra a tentativa (falha/sucesso) para alimentar o contador e a auditoria. */
function bios_login_throttle_log(PDO $pdo, string $email, string $ip, bool $ok, ?int $tenantId = null, ?int $userId = null): void
{
    try {
        $st = $pdo->prepare(
            "INSERT INTO auth_audit_logs (tenant_id, user_id, email, acao, ip, user_agent, status, detalhes, created_at)
             VALUES (:t, :u, :e, 'login', :ip, :ua, :status, :det, NOW())");
        $st->execute([
            ':t' => $tenantId,
            ':u' => $userId,
            ':e' => mb_substr($email, 0, 150),
            ':ip' => $ip,
            ':ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            ':status' => $ok ? 'sucesso' : 'falha',
            ':det' => $ok ? 'login ok' : 'credenciais/tentativa inválida',
        ]);
    } catch (Throwable $e) {
        // não propaga: log é auxiliar, não pode quebrar o login.
    }
}
