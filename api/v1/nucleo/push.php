<?php
declare(strict_types=1);
/* ============================================================
   VERO Campo — api/v1/nucleo/push.php  (Onda 7.5)
   Notificações push via Expo Push API (exp.host). Fail-safe por
   contrato: push é cortesia — NUNCA pode derrubar a requisição
   que o disparou (try/catch em tudo, timeouts curtos).
   Obs.: push remoto não chega no Expo Go (SDK 53+); os tokens só
   existirão quando houver build EAS instalado nos aparelhos.
   ============================================================ */

/** Grava/renova o token Expo do aparelho do usuário autenticado. */
function push_registrar_token(int $usuarioId, string $token, ?string $plataforma): void
{
    if (!vero_has_column('app_push_tokens', 'expo_token')) {
        return; // migration ainda não aplicada — registro é silenciosamente adiado
    }
    vero_pdo()->prepare(
        'INSERT INTO app_push_tokens (tenant_id, usuario_id, expo_token, plataforma)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id),
                                 usuario_id = VALUES(usuario_id),
                                 plataforma = VALUES(plataforma)'
    )->execute([vero_tenant(), $usuarioId, $token, $plataforma]);
}

/** Envia uma notificação a todos os aparelhos registrados do tenant.
 *  Silencioso em qualquer erro (rede, CA, tabela ausente). */
function push_notificar_tenant(string $titulo, string $corpo, array $dados = []): int
{
    try {
        if (!vero_has_column('app_push_tokens', 'expo_token')) {
            return 0;
        }
        $q = vero_pdo()->prepare(
            'SELECT expo_token FROM app_push_tokens WHERE tenant_id = ? LIMIT 100'
        );
        $q->execute([vero_tenant()]);
        $tokens = $q->fetchAll(PDO::FETCH_COLUMN);
        if ($tokens === []) {
            return 0;
        }

        $mensagens = array_map(static fn(string $t): array => [
            'to'    => $t,
            'title' => $titulo,
            'body'  => $corpo,
            'data'  => $dados,
            'sound' => 'default',
        ], $tokens);

        $ch = curl_init('https://exp.host/--/api/v2/push/send');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($mensagens, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true, // P-8: TLS verificado explicitamente
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        // CA no Windows/WAMP: mesmo esquema do proxy de IA (BIOS_CURL_CAINFO)
        $ca = getenv('BIOS_CURL_CAINFO') ?: dirname(__DIR__, 3) . '/extras/ssl/cacert.pem';
        if (is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        curl_exec($ch);
        curl_close($ch);
        return count($tokens);
    } catch (Throwable $e) {
        return 0; // push é cortesia — nunca quebra o fluxo que o chamou
    }
}
