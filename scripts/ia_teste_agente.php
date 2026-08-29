<?php
/* Teste mecânico do orquestrador (sem LLM): manifesto → tools → executar → auditar. */
declare(strict_types=1);
$cfg = require __DIR__ . '/../config/database.php';
$GLOBALS['__pdo'] = new PDO('mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'], $cfg['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function vero_pdo(): PDO { return $GLOBALS['__pdo']; } // infra usa esta

require __DIR__ . '/../api/v1/nucleo/ia_agente.php';
putenv('IA_AGENTE_BASE_URL=http://localhost/vero/api/v1');

function curl_json($m, $url, $tok, $body) {
    $ch = curl_init($url); $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_CUSTOMREQUEST=>$m, CURLOPT_HTTPHEADER=>$h, CURLOPT_TIMEOUT=>20]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    return json_decode((string)curl_exec($ch), true);
}

$login = curl_json('POST', 'http://localhost/vero/api/v1/auth/login', null, ['email'=>'qa5.gestor@vero.test','senha'=>'change_me']);
$token = $login['data']['token'] ?? null;
$usuario = $login['data']['usuario'] ?? null;
if (!$token) { echo "LOGIN FALHOU\n"; exit; }
echo "login ok — usuario #{$usuario['id']} tenant {$usuario['tenant_id']}\n\n";

$caps = ia_agente_capabilities();
$tools = ia_agente_tools();
echo "1) capabilities carregadas: " . count($caps) . "\n";
echo "   tools geradas: " . count($tools) . " (ex.: {$tools[0]['function']['name']})\n";
$nomesInvalidos = array_filter($tools, fn($t) => !preg_match('/^[a-zA-Z0-9_-]+$/', $t['function']['name']));
echo "   nomes de tool inválidos p/ OpenAI: " . count($nomesInvalidos) . "\n\n";

echo "2) slot-filling (compra.solicitar sem dados) → faltando: "
   . json_encode(ia_agente_faltando($caps['compra.solicitar'], []), JSON_UNESCAPED_UNICODE) . "\n\n";

$sess = 'sess-teste-' . substr(bin2hex(random_bytes(4)), 0, 8);

echo "3) EXECUTAR LEITURA (compras_pedidos.sync):\n";
$r = ia_agente_executar($caps['compras_pedidos.sync'], [], $usuario, $token, $sess);
echo "   ok={$r['ok']} http={$r['http']} pedidos=" . (is_array($r['data']['itens'] ?? null) ? count($r['data']['itens']) : '?') . "\n\n";

echo "4) EXECUTAR ESCRITA (compra.solicitar) + resumo + auditoria:\n";
$args = ['justificativa'=>'Teste do orquestrador de IA', 'itens'=>[['produto_id'=>13,'quantidade'=>5]]];
echo "   resumo: " . ia_agente_resumo($caps['compra.solicitar'], $args) . "\n";
$w = ia_agente_executar($caps['compra.solicitar'], $args, $usuario, $token, $sess);
echo "   ok={$w['ok']} http={$w['http']} msg={$w['mensagem']} recurso_id=" . ($w['data']['id'] ?? '?') . "\n\n";

echo "5) trilha ia_acoes desta sessão (hash-chain):\n";
$st = vero_pdo()->prepare("SELECT id, capability, resultado, recurso_id, LEFT(hash,12) h, LEFT(COALESCE(hash_anterior,'-'),12) ha FROM ia_acoes WHERE session_id=? ORDER BY id");
$st->execute([$sess]);
foreach ($st->fetchAll() as $row) echo "   #{$row['id']} {$row['capability']} rec={$row['recurso_id']} hash={$row['h']} ant={$row['ha']} :: {$row['resultado']}\n";
