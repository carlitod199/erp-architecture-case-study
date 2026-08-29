<?php
declare(strict_types=1);
/* ============================================================
   VERO — api/v1/nucleo/ia_agente_infra.php
   Infraestrutura do Agente Operacional de IA: trilha de auditoria
   encadeada por hash (ia_acoes) e preferências por usuário
   (ia_preferencias).

   HASH-CHAIN (espelha EXATAMENTE o razão financeiro —
   vero_srv_fin_hash em includes/vero_services.php):
   - hash_anterior = hash da última linha do MESMO tenant (ordem por id),
     lida com FOR UPDATE para encadear com segurança sob concorrência;
   - hash = SHA-256(hash_anterior . '|' . payload_canônico), calculado no
     momento da criação e nunca recalculado;
   - payload_canônico = campos selados unidos por '|', com params_json
     serializado de forma determinística (o MESMO texto é gravado e
     hasheado, então a verificação é reprodutível).

   DEGRADAÇÃO SILENCIOSA (contrato): todas as funções são guardadas por
   verificação de existência de tabela/coluna. Em banco sem as migrations
   aplicadas elas retornam null/0/no-op — NUNCA dão fatal.
   ============================================================ */

/** PDO do projeto, funcionando tanto na API quanto em CLI de teste. */
function ia_infra_pdo(): PDO
{
    if (function_exists('vero_pdo')) {
        return vero_pdo();
    }
    return Database::getConnection();
}

/** Existe a tabela no schema atual? (cacheado) — guarda anti-fatal. */
function ia_infra_tem_tabela(string $tabela): bool
{
    static $cache = [];
    if (!array_key_exists($tabela, $cache)) {
        try {
            $st = ia_infra_pdo()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $st->execute([':t' => $tabela]);
            $cache[$tabela] = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache[$tabela] = false;
        }
    }
    return $cache[$tabela];
}

/** Serialização canônica e determinística dos params (chaves ordenadas). */
function ia_infra_params_canon(array $params): string
{
    $ordena = static function (&$v) use (&$ordena): void {
        if (is_array($v)) {
            // ordena associativos por chave; preserva listas sequenciais
            if ($v !== array_values($v)) {
                ksort($v);
            }
            foreach ($v as &$item) {
                $ordena($item);
            }
            unset($item);
        }
    };
    $ordena($params);
    return json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Registra uma ação do agente de IA na trilha encadeada (ia_acoes).
 * Espelha o hash-chain do razão: hash = SHA-256(hash_anterior . '|' . payload).
 * Retorna o id inserido, ou 0 se a tabela ainda não existir (degrade).
 *
 * @param array       $usuario     contexto autenticado (usa ['id'] e ['tenant_id'])
 * @param string      $sessionId   identificador da sessão do agente (<=64)
 * @param string      $capability  capability executada (<=80)
 * @param array       $params      parâmetros da chamada (serializados em JSON)
 * @param string      $resultado   texto do resultado/efeito
 * @param string|null $recursoTipo tipo do recurso afetado (ex.: 'movimentacao')
 * @param int|null    $recursoId   id do recurso afetado
 */
function ia_auditar_acao(
    array $usuario,
    string $sessionId,
    string $capability,
    array $params,
    string $resultado,
    ?string $recursoTipo = null,
    $recursoId = null
): int {
    if (!ia_infra_tem_tabela('ia_acoes')) {
        return 0; // migration pendente — auditoria silenciosamente adiada
    }

    $pdo      = ia_infra_pdo();
    $tenantId = (int)($usuario['tenant_id'] ?? 0);
    $usuarioId = (int)($usuario['id'] ?? 0);
    $recursoId = ($recursoId === null || $recursoId === '') ? null : (int)$recursoId;
    $paramsJson = ia_infra_params_canon($params);

    $emTransacao = $pdo->inTransaction();
    if (!$emTransacao) {
        $pdo->beginTransaction();
    }
    try {
        // trava o fim da cadeia do tenant para encadear com segurança
        $st = $pdo->prepare(
            'SELECT hash FROM ia_acoes
              WHERE tenant_id = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $st->execute([$tenantId]);
        $hashAnterior = $st->fetchColumn() ?: null;

        // payload canônico selado (mesma ideia do razão): campos unidos por '|'
        $payload = implode('|', [
            (string)$tenantId,
            (string)$usuarioId,
            $sessionId,
            $capability,
            $paramsJson,
            $resultado,
            (string)($recursoTipo ?? ''),
            (string)($recursoId ?? ''),
        ]);
        $hash = hash('sha256', (string)$hashAnterior . '|' . $payload);

        $ins = $pdo->prepare(
            'INSERT INTO ia_acoes
                (tenant_id, usuario_id, session_id, capability, params_json,
                 resultado, recurso_tipo, recurso_id, hash, hash_anterior)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $tenantId, $usuarioId, $sessionId, $capability, $paramsJson,
            $resultado, $recursoTipo, $recursoId, $hash, $hashAnterior,
        ]);
        $id = (int)$pdo->lastInsertId();

        if (!$emTransacao) {
            $pdo->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if (!$emTransacao && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return 0; // auditoria nunca derruba o fluxo que a chamou
    }
}

/** Lê uma preferência do agente; null se ausente ou tabela inexistente. */
function ia_pref_ler(int $tenant, int $uid, string $chave): ?string
{
    if (!ia_infra_tem_tabela('ia_preferencias')) {
        return null;
    }
    try {
        $st = ia_infra_pdo()->prepare(
            'SELECT valor FROM ia_preferencias
              WHERE tenant_id = ? AND usuario_id = ? AND chave = ? LIMIT 1'
        );
        $st->execute([$tenant, $uid, $chave]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (Throwable $e) {
        return null;
    }
}

/** Grava (upsert) uma preferência do agente. No-op se a tabela não existir. */
function ia_pref_gravar(int $tenant, int $uid, string $chave, string $valor): void
{
    if (!ia_infra_tem_tabela('ia_preferencias')) {
        return;
    }
    try {
        ia_infra_pdo()->prepare(
            'INSERT INTO ia_preferencias (tenant_id, usuario_id, chave, valor)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)'
        )->execute([$tenant, $uid, $chave, $valor]);
    } catch (Throwable $e) {
        // preferência é conveniência — nunca quebra o fluxo
    }
}
