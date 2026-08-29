<?php
/* Teste conversacional do agente (LLM real): pedir irrigação → confirmar → executar → auditar. */
declare(strict_types=1);
$cfg = require __DIR__ . '/../config/database.php';
$pdo = new PDO('mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'], $cfg['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function post($url, $tok, $body) {
    $ch = curl_init($url); $h = ['Content-Type: application/json'];
    if ($tok) $h[] = "Authorization: Bearer $tok";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1, CURLOPT_HTTPHEADER=>$h, CURLOPT_POSTFIELDS=>json_encode($body), CURLOPT_TIMEOUT=>90]);
    $r = curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$r, true)];
}
$BASE='http://localhost/vero/api/v1';
[$c,$l]=post("$BASE/auth/login",null,['email'=>'qa5.gestor@vero.test','senha'=>'change_me']);
$tok=$l['data']['token']??null; if(!$tok){echo "LOGIN FALHOU\n";exit;}
$sess='chatteste-'.substr(bin2hex(random_bytes(4)),0,8);
echo "sessão: $sess\n\n";

echo "=== TURNO 1: pedido de irrigação ===\n";
$msgs=[['papel'=>'user','texto'=>'Registrar irrigação de 6 horas na válvula 5A']];
[$c,$r1]=post("$BASE/ia/chat",$tok,['mensagens'=>$msgs,'session_id'=>$sess]);
$resp1=$r1['data']['resposta']??('ERRO '.json_encode($r1));
echo "IA: $resp1\n";
echo "ações: ".json_encode($r1['data']['acoes']??[],JSON_UNESCAPED_UNICODE)."\n\n";

echo "=== TURNO 2: confirmação ===\n";
$msgs[]=['papel'=>'assistente','texto'=>$resp1];
$msgs[]=['papel'=>'user','texto'=>'Sim, registre só as 6 horas mesmo, sem informar mm.'];
[$c,$r2]=post("$BASE/ia/chat",$tok,['mensagens'=>$msgs,'session_id'=>$sess]);
$resp2=$r2['data']['resposta']??('ERRO '.json_encode($r2));
echo "IA: $resp2\n";
echo "ações: ".json_encode($r2['data']['acoes']??[],JSON_UNESCAPED_UNICODE)."\n\n";

echo "=== TURNO 3: confirmação final ===\n";
$msgs[]=['papel'=>'assistente','texto'=>$resp2];
$msgs[]=['papel'=>'user','texto'=>'Sim, confirmo. Pode executar.'];
[$c,$r3]=post("$BASE/ia/chat",$tok,['mensagens'=>$msgs,'session_id'=>$sess]);
$resp3=$r3['data']['resposta']??('ERRO '.json_encode($r3));
echo "IA: $resp3\n";
echo "ações: ".json_encode($r3['data']['acoes']??[],JSON_UNESCAPED_UNICODE)."\n\n";

echo "=== auditoria ia_acoes desta sessão ===\n";
$st=$pdo->prepare("SELECT id,capability,resultado,recurso_id,LEFT(hash,10) h FROM ia_acoes WHERE session_id=? ORDER BY id");
$st->execute([$sess]);
$rows=$st->fetchAll();
foreach($rows as $x) echo "  #{$x['id']} {$x['capability']} rec={$x['recurso_id']} hash={$x['h']} :: {$x['resultado']}\n";
echo count($rows)." linha(s) de auditoria\n";
