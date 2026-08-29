<?php
declare(strict_types=1);
/* Prova A-AGRO — auditoria Relatórios 20/07 (P09/P10/P11).
   Login HTTP real qa5.gestor no tenant "Fazenda Boa Vista". Limpa o que cria. */
require __DIR__ . '/_lib.php';

const EMAIL = 'qa5.gestor@vero.test';
const SENHA = 'change_me';
$BASE = 'http://localhost/vero';
$JAR  = QA_OUT . '/cookies_qa5gestor.txt';
@unlink($JAR);

function http(string $url, array $opts = []): array {
    global $JAR;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HEADER=>true, CURLOPT_COOKIEJAR=>$JAR, CURLOPT_COOKIEFILE=>$JAR, CURLOPT_TIMEOUT=>60] + $opts);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code'=>$code, 'headers'=>substr((string)$raw,0,$hs), 'body'=>substr((string)$raw,$hs)];
}

/* login */
$r = http("$BASE/index.php");
if (!preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $r['body'], $m)) { echo "FALHA: sem CSRF no login\n"; exit(1); }
$csrf = $m[1];
$r = http("$BASE/index.php", [CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(['csrf_token'=>$csrf,'email'=>EMAIL,'senha'=>SENHA])]);
if ($r['code'] !== 302 || str_contains($r['headers'],'index.php?erro')) { echo "FALHA login (code {$r['code']})\n"; exit(1); }
echo "Login qa5.gestor OK\n\n";

$pass=0;$fail=0;
function chk(string $d,bool $ok){global $pass,$fail;echo ($ok?'[PASS] ':'[FAIL] ').$d."\n";$ok?$pass++:$fail++;}

$TALHAO = 5; $ALVO = 1; /* Tripes nível 10 */
$TAG_ENV = 'PROVAP09-ENVIADO-'.time();
$TAG_RAS = 'PROVAP09-RASCUNHO-'.time();

/* ---------- P09 (a): monitoramento ENVIADO índice 30 > nível 10 → gera alerta ---------- */
$post = ['csrf_token'=>$csrf,'acao'=>'salvar','id'=>'','data_monitoramento'=>'2026-07-20',
    'talhao_id'=>$TALHAO,'alvo_id'=>[$ALVO],'nivel_infestacao'=>['30'],'quantidade_encontrada'=>[''],
    'local_infestacao'=>[''],'severidade_qualitativa'=>[''],'unidade'=>'%','plantas_amostradas'=>'',
    'observacao'=>$TAG_ENV,'enviar'=>'1'];
$r = http("$BASE/mip/monitoramento.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($post)]);
$monEnv = (int)qa_val("SELECT id FROM mip_monitoramentos WHERE observacao=? ORDER BY id DESC LIMIT 1",[$TAG_ENV]);
$alEnv = $monEnv ? (int)qa_val("SELECT COUNT(*) FROM agro_alertas WHERE categoria='mip' AND origem_tipo='mip_monitoramento' AND origem_id=?",[$monEnv]) : 0;
$statusEnv = $monEnv ? (string)qa_val("SELECT status FROM mip_monitoramentos WHERE id=?",[$monEnv]) : '';
chk("P09a: monitoramento ENVIADO gravado (mon#$monEnv, status=$statusEnv)", $monEnv>0 && $statusEnv==='enviado');
chk("P09a: índice 30 >= nível 10 em ENVIADO GEROU alerta (qtd=$alEnv)", $alEnv >= 1);

/* ---------- P09 (b): RASCUNHO índice 30 → NÃO gera alerta (design A1-47) ---------- */
$post['observacao'] = $TAG_RAS; unset($post['enviar']); /* botão "Salvar rascunho" não envia enviar */
$r = http("$BASE/mip/monitoramento.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($post)]);
$monRas = (int)qa_val("SELECT id FROM mip_monitoramentos WHERE observacao=? ORDER BY id DESC LIMIT 1",[$TAG_RAS]);
$alRas = $monRas ? (int)qa_val("SELECT COUNT(*) FROM agro_alertas WHERE categoria='mip' AND origem_tipo='mip_monitoramento' AND origem_id=?",[$monRas]) : 0;
$statusRas = $monRas ? (string)qa_val("SELECT status FROM mip_monitoramentos WHERE id=?",[$monRas]) : '';
chk("P09b: monitoramento RASCUNHO gravado (mon#$monRas, status=$statusRas)", $monRas>0 && $statusRas==='rascunho');
chk("P09b: RASCUNHO acima do nível NÃO gera alerta (design; qtd=$alRas)", $alRas === 0);

/* ---------- P09 transparência: a listagem explica por que rascunho não tem alerta ---------- */
$r = http("$BASE/mip/monitoramento.php?talhao=$TALHAO");
$temTransp = str_contains($r['body'], 'rascunho (envie ao líder para gerar)')
          || str_contains($r['body'], 'Sem alerta — rascunho');
chk("P09 transparência: listagem sinaliza 'Sem alerta — rascunho' para índice acima do nível", $temTransp);

/* ---------- P10: CTA 'sem faixa — cadastrar' na análise de solo (anl 2 tem K sem faixa) ---------- */
$anlSemFaixa = (int)qa_val("SELECT r.analise_id FROM analise_solo_resultados r
     LEFT JOIN analise_faixas f ON f.tenant_id=r.tenant_id AND f.nutriente_id=r.nutriente_id AND f.tipo='solo' AND f.ativo=1
     WHERE f.id IS NULL ORDER BY r.analise_id LIMIT 1");
$r = http("$BASE/nutricao/analise_solo.php?editar=$anlSemFaixa");
$temCta = str_contains($r['body'],'sem faixa — cadastrar') && str_contains($r['body'],'faixas_nutricionais.php?nova=1');
chk("P10: análise #$anlSemFaixa exibe CTA 'sem faixa — cadastrar' com deep-link p/ faixas", $temCta);
/* e o deep-link abre o modal já com o nutriente/tipo */
$r = http("$BASE/nutricao/faixas_nutricionais.php?nova=1&tipo=solo&nutriente=5"); /* K */
$temModalAberto = str_contains($r['body'],'vmodal open') && preg_match('/value="5"\s+selected/',$r['body']);
chk("P10: deep-link abre modal de nova faixa com o nutriente preselecionado", (bool)$temModalAberto);

/* ---------- P11: pH=15 rejeitado; pH=7 aceito ---------- */
$TAG_PH_BAD = 'PROVAP11-BAD-'.time();
$TAG_PH_OK  = 'PROVAP11-OK-'.time();
$phPost = fn($valor,$unid,$tag)=>['csrf_token'=>$csrf,'acao'=>'salvar','id'=>'','data_amostra'=>'2026-07-20',
    'talhao_id'=>$TALHAO,'setor_id'=>'','safra_id'=>'','profundidade'=>'','observacao'=>$tag,
    'r_nutriente'=>[1],'r_valor'=>[$valor],'r_unidade'=>[$unid]]; /* nutriente 1 = pH */
$r = http("$BASE/nutricao/analise_solo.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($phPost('15','',$TAG_PH_BAD))]);
$badCriado = (int)qa_val("SELECT COUNT(*) FROM analise_solo WHERE observacao=?",[$TAG_PH_BAD]);
chk("P11: POST pH=15 REJEITADO (nenhuma análise gravada)", $badCriado === 0);

$r = http("$BASE/nutricao/analise_solo.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($phPost('7','',$TAG_PH_OK))]);
$okCriado = (int)qa_val("SELECT id FROM analise_solo WHERE observacao=? ORDER BY id DESC LIMIT 1",[$TAG_PH_OK]);
chk("P11: POST pH=7 ACEITO (análise #$okCriado gravada)", $okCriado > 0);
/* e o pH com unidade numérica '4' também é rejeitado */
$TAG_PH_U = 'PROVAP11-UNIT-'.time();
$r = http("$BASE/nutricao/analise_solo.php", [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>http_build_query($phPost('7','4',$TAG_PH_U))]);
$unitCriado = (int)qa_val("SELECT COUNT(*) FROM analise_solo WHERE observacao=?",[$TAG_PH_U]);
chk("P11: POST pH=7 com unidade '4' (numérica) REJEITADO", $unitCriado === 0);

/* ---------- LIMPEZA ---------- */
echo "\n== Limpeza dos dados de prova ==\n";
$pdo = qa_pdo();
foreach ([$monEnv,$monRas] as $mid) {
    if ($mid<=0) continue;
    $pdo->prepare("DELETE aa FROM mip_alerta_acoes aa JOIN agro_alertas al ON al.id=aa.alerta_id WHERE al.origem_tipo='mip_monitoramento' AND al.origem_id=?")->execute([$mid]);
    $pdo->prepare("DELETE FROM agro_alertas WHERE categoria='mip' AND origem_tipo='mip_monitoramento' AND origem_id=?")->execute([$mid]);
    $pdo->prepare("DELETE FROM mip_monitoramento_alvos WHERE monitoramento_id=?")->execute([$mid]);
    $pdo->prepare("DELETE FROM mip_monitoramentos WHERE id=?")->execute([$mid]);
    echo "  removido monitoramento #$mid (+ alertas/junção)\n";
}
foreach (qa_rows("SELECT id FROM analise_solo WHERE observacao LIKE 'PROVAP11-%'") as $a) {
    $aid=(int)$a['id'];
    $pdo->prepare("DELETE FROM agro_alertas WHERE categoria='nutricao' AND origem_tipo='analise_solo' AND origem_id=?")->execute([$aid]);
    $pdo->prepare("DELETE FROM analise_solo_resultados WHERE analise_id=?")->execute([$aid]);
    $pdo->prepare("DELETE FROM analise_solo WHERE id=?")->execute([$aid]);
    echo "  removida análise de solo #$aid\n";
}
$sobra = (int)qa_val("SELECT COUNT(*) FROM mip_monitoramentos WHERE observacao LIKE 'PROVAP09-%'")
       + (int)qa_val("SELECT COUNT(*) FROM analise_solo WHERE observacao LIKE 'PROVAP11-%'");
chk("Limpeza: nenhum dado de prova remanescente", $sobra === 0);

echo "\n>>> PASS=$pass FAIL=$fail\n";
exit($fail>0?1:0);
