<?php
declare(strict_types=1);
/* ============================================================
   VERO — prova G-23b (A4-UXO1D): conversão dos confirm() inline
   para o padrão declarativo data-confirm (commit c420502).
   1) GET de 5 telas convertidas (módulos distintos): data-confirm
      presente + ZERO `return confirm('` remanescente.
   2) Exclusão REVERSÍVEL ponta a ponta em tela convertida:
      estoque/grupos_subgrupos.php → cria grupo, exclui via POST
      (soft delete ativo=0), confere no banco e limpa a linha.
   Login: qa5.gestor@vero.test (tenant 1, usuários QA da A4-06).
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_guard_host();

const G23B_PAPEL = 'g23b_gestor';
const G23B_EMAIL = 'qa5.gestor@vero.test';
const G23B_SENHA = 'change_me';

/* login manual (usuários da bateria 00 não existem no banco hoje) */
function g23b_login(): bool
{
    @unlink(qa_cookiejar(G23B_PAPEL));
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_COOKIEJAR => qa_cookiejar(G23B_PAPEL), CURLOPT_COOKIEFILE => qa_cookiejar(G23B_PAPEL)]);
    if ($r['code'] !== 200 || !preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $r['body'], $m)) return false;
    $GLOBALS['qa_csrf'][G23B_PAPEL] = $m[1];
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1], 'email' => G23B_EMAIL, 'senha' => G23B_SENHA]),
        CURLOPT_COOKIEJAR => qa_cookiejar(G23B_PAPEL), CURLOPT_COOKIEFILE => qa_cookiejar(G23B_PAPEL)]);
    return $r['code'] === 302 && !str_contains($r['headers'], 'index.php?erro');
}

qa_section('Login');
qa_check('login qa5.gestor (tenant 1)', g23b_login());

/* ── 1) GET das telas convertidas ─────────────────────────── */
qa_section('GET telas convertidas — data-confirm presente, zero return confirm(');
$telas = [
    'custeio'  => '/custeio/rateios.php',
    'estoque'  => '/estoque/grupos_subgrupos.php',
    'agro'     => '/agro/abertura_safra.php',
    'safras'   => '/safras/index.php',
    'maquinas' => '/maquinas/manutencao.php',
];
/* pessoas/responsaveis_tecnicos.php e pessoas/epis.php também foram convertidas,
   mas seus forms são condicionais por linha (RT ativo / EPI vigente) e o tenant 1
   não tem registros — checa-se ao menos o zero-inline nelas. */
$telasSemDado = ['/pessoas/responsaveis_tecnicos.php', '/pessoas/epis.php'];
foreach ($telasSemDado as $rota) {
    $r = qa_http_get_follow(G23B_PAPEL, $rota);
    $probs = qa_pagina_saudavel($r);
    qa_check("[pessoas] $rota renderiza saudável", $probs === [], $probs);
    qa_eq("[pessoas] $rota zero `return confirm('` remanescente", 0, substr_count($r['body'], "return confirm('"));
}
foreach ($telas as $mod => $rota) {
    $r = qa_http_get_follow(G23B_PAPEL, $rota);
    $probs = qa_pagina_saudavel($r);
    qa_check("[$mod] $rota renderiza saudável", $probs === [], $probs);
    $nDecl   = substr_count($r['body'], 'data-confirm="');
    $nInline = substr_count($r['body'], "return confirm('");
    qa_check("[$mod] $rota tem data-confirm renderizado (n=$nDecl)", $nDecl > 0);
    qa_eq("[$mod] $rota zero `return confirm('` remanescente", 0, $nInline);
}

/* ── 2) Exclusão reversível ponta a ponta (grupos_subgrupos) ── */
qa_section('E2E reversível — estoque/grupos_subgrupos.php (tela convertida)');
$nomeG = 'QA G23B Grupo Confirm ' . date('His');
$tid   = 1; /* tenant do qa5.gestor */

$r = qa_http_post(G23B_PAPEL, '/estoque/grupos_subgrupos.php',
    ['acao' => 'salvar_grupo', 'nome' => $nomeG, 'tipo' => 'outro', 'ativo' => 1]);
qa_check('POST salvar_grupo redireciona (302)', $r['code'] === 302, $r['code']);

$grupo = qa_row("SELECT id, ativo FROM estoque_grupos WHERE tenant_id=? AND nome=?", [$tid, $nomeG]);
qa_check('grupo criado no banco (ativo=1)', $grupo !== null && (int)$grupo['ativo'] === 1, $grupo);

if ($grupo) {
    $gid = (int)$grupo['id'];
    $r = qa_http_post(G23B_PAPEL, '/estoque/grupos_subgrupos.php',
        ['acao' => 'excluir_grupo', 'id' => $gid]);
    qa_check('POST excluir_grupo redireciona (302)', $r['code'] === 302, $r['code']);

    $dep = qa_row("SELECT ativo FROM estoque_grupos WHERE tenant_id=? AND id=?", [$tid, $gid]);
    qa_check('soft delete confirmado (ativo=0, linha preservada)',
        $dep !== null && (int)$dep['ativo'] === 0, $dep);

    /* limpeza: remove a linha de teste (reversão total do ambiente) */
    qa_stmt("DELETE FROM estoque_grupos WHERE tenant_id=? AND id=?", [$tid, $gid]);
    qa_check('limpeza: linha de teste removida',
        qa_row("SELECT id FROM estoque_grupos WHERE tenant_id=? AND id=?", [$tid, $gid]) === null);
}

qa_finish('prova_g23b_confirm');
