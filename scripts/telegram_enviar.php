<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/telegram_enviar.php
   Entrega rápida por Telegram (bot oficial via api.telegram.org):
   builds do app, links e avisos direto no chat do time.

   Uso (CLI):
     php telegram_enviar.php "mensagem de texto"
     php telegram_enviar.php --arquivo C:\caminho\app.apk "legenda opcional"
     php telegram_enviar.php --chat-id      (descobre o chat_id após você
                                             mandar qualquer msg pro bot)

   Config no .env da raiz (vero/.env):
     TELEGRAM_BOT_TOKEN=123456:ABC...   (do @BotFather)
     TELEGRAM_CHAT_ID=...               (descoberto com --chat-id)

   Limite da API de bots: arquivos até 50 MB. Acima disso, envie o LINK
   (o chamador decide — este script só reporta o erro com clareza).
   ============================================================ */

function tg_env(string $nome): string
{
    $v = getenv($nome) ?: '';
    if ($v === '') {
        $env = dirname(__DIR__) . '/.env';
        if (is_file($env)) {
            foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
                $l = trim($l);
                if (str_starts_with($l, $nome . '=')) {
                    $v = trim(substr($l, strlen($nome) + 1), " \t\"'");
                    break;
                }
            }
        }
    }
    return $v;
}

function tg_api(string $metodo, array $campos): array
{
    $token = tg_env('TELEGRAM_BOT_TOKEN');
    if ($token === '') {
        fwrite(STDERR, "TELEGRAM_BOT_TOKEN ausente no vero/.env — crie o bot no @BotFather e cole o token.\n");
        exit(2);
    }
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$metodo}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 120, // upload de APK leva tempo
        CURLOPT_POSTFIELDS => $campos, // com CURLFile o cURL monta multipart sozinho
    ]);
    // mesmo CA bundle do resto do projeto (Windows/CLI precisa)
    $ca = getenv('BIOS_CURL_CAINFO') ?: (dirname(PHP_BINARY) . '/extras/ssl/cacert.pem');
    if (is_file($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $bruto = curl_exec($ch);
    $erro = curl_error($ch);
    curl_close($ch);
    if ($bruto === false) {
        fwrite(STDERR, "curl: {$erro}\n");
        exit(3);
    }
    $resp = json_decode((string)$bruto, true);
    if (!is_array($resp) || empty($resp['ok'])) {
        fwrite(STDERR, 'Telegram recusou: ' . substr((string)$bruto, 0, 400) . "\n");
        exit(4);
    }
    return $resp;
}

$args = array_slice($argv, 1);
if ($args === []) {
    fwrite(STDERR, "Uso: telegram_enviar.php \"mensagem\" | --arquivo <caminho> [legenda] | --chat-id\n");
    exit(1);
}

// --chat-id: descobre o chat depois que alguém manda mensagem ao bot
if ($args[0] === '--chat-id') {
    $r = tg_api('getUpdates', ['limit' => 20]);
    $vistos = [];
    foreach (($r['result'] ?? []) as $u) {
        $c = $u['message']['chat'] ?? $u['channel_post']['chat'] ?? null;
        if ($c && !isset($vistos[$c['id']])) {
            $vistos[$c['id']] = true;
            $nome = $c['title'] ?? trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            echo "chat_id: {$c['id']}  ({$c['type']}: {$nome})\n";
        }
    }
    if ($vistos === []) {
        echo "Nenhuma conversa encontrada — abra o bot no Telegram, mande um \"oi\" e rode de novo.\n";
    }
    exit(0);
}

$chat = tg_env('TELEGRAM_CHAT_ID');
if ($chat === '') {
    fwrite(STDERR, "TELEGRAM_CHAT_ID ausente no vero/.env — rode com --chat-id para descobrir.\n");
    exit(2);
}

if ($args[0] === '--arquivo') {
    $caminho = $args[1] ?? '';
    if (!is_file($caminho)) {
        fwrite(STDERR, "Arquivo não encontrado: {$caminho}\n");
        exit(1);
    }
    $mb = filesize($caminho) / 1048576;
    if ($mb > 50) {
        fwrite(STDERR, sprintf("Arquivo tem %.1f MB — a API de bots aceita até 50 MB. Envie o LINK como mensagem.\n", $mb));
        exit(5);
    }
    $campos = [
        'chat_id' => $chat,
        'document' => new CURLFile($caminho, 'application/octet-stream', basename($caminho)),
    ];
    if (!empty($args[2])) {
        $campos['caption'] = mb_substr($args[2], 0, 1024);
    }
    tg_api('sendDocument', $campos);
    echo "Arquivo enviado: " . basename($caminho) . sprintf(" (%.1f MB)\n", $mb);
    exit(0);
}

tg_api('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($args[0], 0, 4096), 'disable_web_page_preview' => 'true']);
echo "Mensagem enviada.\n";
