<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/nucleo/api.php
   Envelope JSON e helpers de requisição da API do app.
   Envelope (contrato D3 do app): { ok, data, message, error, sync:{server_time} }
   ============================================================ */

/** Relógio do BANCO (bug 22/07): o server_time é o cursor do delta do app e
 *  é comparado com updated_at, que o MySQL carimba com o relógio DELE. O PHP
 *  daqui roda 3h à frente do DBaaS — usar date() deixava o cursor "no futuro"
 *  e registros novos sumiam do delta por horas. Fallback: date() se o banco
 *  estiver indisponível (pior caso = o comportamento antigo). */
function api_hora_banco(): string
{
    static $hora = null;
    if ($hora === null) {
        try {
            $hora = (string)vero_pdo()->query('SELECT NOW()')->fetchColumn();
        } catch (Throwable) {
            $hora = date('Y-m-d H:i:s');
        }
    }
    return $hora;
}

/** Resposta de sucesso. Sempre inclui sync.server_time (base do delta no app). */
function api_ok(mixed $data = null, ?string $message = null, int $http = 200): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => true,
        'data' => $data,
        'message' => $message,
        'sync' => ['server_time' => api_hora_banco()],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Resposta de erro com código estável (o app trata por `error`, não pelo texto). */
function api_erro(string $codigo, string $message, int $http = 400): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok' => false,
        'error' => $codigo,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Lê e valida o corpo JSON. */
function api_corpo(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || trim($bruto) === '') {
        return [];
    }
    $json = json_decode($bruto, true);
    if (!is_array($json)) {
        api_erro('json_invalido', 'Corpo da requisição não é um JSON válido.', 422);
    }
    return $json;
}

/** Campo obrigatório do corpo. */
function api_exigir_campo(array $corpo, string $campo): mixed
{
    if (!isset($corpo[$campo]) || $corpo[$campo] === '' || $corpo[$campo] === null) {
        api_erro('campo_obrigatorio', "Informe o campo '{$campo}'.", 422);
    }
    return $corpo[$campo];
}

/* ───────────────── Idempotência por client_uuid (decisão D6/offline-first) ─────────────────
   O app reenvia a fila quando volta o sinal; o mesmo client_uuid nunca duplica.
   Uso nas rotas de escrita:
     api_idempotente($clientUuid, 'apontamentos', function () { ...insert...; return [$id, $data]; });
   O callable devolve [recurso_id, data_da_resposta]. No replay, devolve a resposta gravada. */
function api_idempotente(string $clientUuid, string $recursoTipo, callable $executar): never
{
    $clientUuid = trim($clientUuid);
    if ($clientUuid === '' || strlen($clientUuid) > 64) {
        api_erro('client_uuid_invalido', 'client_uuid ausente ou inválido.', 422);
    }
    $pdo = vero_pdo();
    $tenant = vero_tenant();

    $q = $pdo->prepare(
        'SELECT recurso_id, resposta_json FROM app_idempotencia
          WHERE tenant_id = ? AND client_uuid = ? LIMIT 1'
    );
    $q->execute([$tenant, $clientUuid]);
    $existente = $q->fetch();
    if ($existente) {
        $data = $existente['resposta_json'] !== null ? json_decode((string)$existente['resposta_json'], true) : null;
        api_ok($data ?? ['id' => (int)$existente['recurso_id']], 'Registro já recebido (idempotente).');
    }

    $pdo->beginTransaction();
    try {
        [$recursoId, $data] = $executar();
        $pdo->prepare(
            'INSERT INTO app_idempotencia (tenant_id, usuario_id, client_uuid, recurso_tipo, recurso_id, resposta_json)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $tenant, vero_uid(), $clientUuid, $recursoTipo,
            $recursoId !== null ? (int)$recursoId : null,
            $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // corrida entre reenvios simultâneos: o UNIQUE segura; devolve o gravado
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $q->execute([$tenant, $clientUuid]);
            $g = $q->fetch();
            if ($g) {
                $data = $g['resposta_json'] !== null ? json_decode((string)$g['resposta_json'], true) : null;
                api_ok($data ?? ['id' => (int)$g['recurso_id']], 'Registro já recebido (idempotente).');
            }
        }
        throw $e;
    }
    api_ok($data, 'Registrado.', 201);
}
