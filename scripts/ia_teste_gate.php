<?php
/* Prova determinística do GATE de confirmação (sem LLM). */
declare(strict_types=1);
$cfg = require __DIR__ . '/../config/database.php';
$GLOBALS['__pdo'] = new PDO('mysql:host=' . $cfg['host'] . ';dbname=' . $cfg['dbname'] . ';charset=utf8mb4',
    $cfg['user'], $cfg['pass'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function vero_pdo(): PDO { return $GLOBALS['__pdo']; }
require __DIR__ . '/../api/v1/nucleo/ia_agente.php';
putenv('IA_AGENTE_BASE_URL=http://localhost/vero/api/v1');

$ch=curl_init('http://localhost/vero/api/v1/auth/login');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_POST=>1,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['email'=>'qa5.gestor@vero.test','senha'=>'change_me'])]);
$l=json_decode(curl_exec($ch),true); $token=$l['data']['token']??null;
// usuario com tenant real (o login JSON não traz tenant_id no topo)
$usuario=['id'=>2,'tenant_id'=>1,'nome'=>'QA5-Gestor'];
$sess='gate-'.substr(bin2hex(random_bytes(4)),0,8);
$caps=ia_agente_capabilities();
$cap=$caps['irrigacao.registrar'];

// simula EXATAMENTE o dispatch do ia.php
function dispatch($cap,$arg,$usuario,$token,$sess){
    $faltam=ia_agente_faltando($cap,$arg);
    if($faltam!==[]) return ['bloqueado','PRECISA_DADOS'];
    if(ia_agente_precisa_confirmar($cap,$arg)) return ['bloqueado','CONFIRME_COM_USUARIO: '.ia_agente_resumo($cap,$arg)];
    unset($arg['confirmado']);
    $r=ia_agente_executar($cap,$arg,$usuario,$token,$sess);
    return ['executado', ($r['ok']?'OK ':'ERRO ').$r['mensagem'].(isset($r['data']['id'])?' id '.$r['data']['id']:'')];
}

echo "1) SEM confirmado (talhao 7, 6h) → precisa_confirmar=".(ia_agente_precisa_confirmar($cap,['talhao_id'=>7,'horas'=>6])?'true':'false')."\n";
[$e1,$m1]=dispatch($cap,['talhao_id'=>7,'horas'=>6],$usuario,$token,$sess);
echo "   dispatch → [$e1] $m1\n\n";

echo "2) COM confirmado=true → executa:\n";
[$e2,$m2]=dispatch($cap,['talhao_id'=>7,'horas'=>6,'confirmado'=>true],$usuario,$token,$sess);
echo "   dispatch → [$e2] $m2\n\n";

echo "3) auditoria da sessão:\n";
$st=vero_pdo()->prepare("SELECT id,capability,tenant_id,resultado,recurso_id,LEFT(hash,10) h FROM ia_acoes WHERE session_id=? ORDER BY id");
$st->execute([$sess]);
$rows=$st->fetchAll();
foreach($rows as $x) echo "   #{$x['id']} {$x['capability']} tenant={$x['tenant_id']} rec={$x['recurso_id']} hash={$x['h']} :: {$x['resultado']}\n";
echo "   ".count($rows)." linha(s) — esperado: 1 (só a execução confirmada gera trilha)\n";
// limpa a trilha de teste
vero_pdo()->prepare("DELETE FROM ia_acoes WHERE session_id=?")->execute([$sess]);
echo "   (trilha de teste removida)\n";
