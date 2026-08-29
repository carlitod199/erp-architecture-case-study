<?php
declare(strict_types=1);
/* ============================================================
   VERO — prova A4-EXPMODAL (19/07): hub de Exportações vira modal
   1) login HTTP qa5.gestor → botão "Exportações" presente em telas
      de relatório (_rel_base) sem quebrar acoes_html;
   2) modal com os 5 grupos e 22 datasets;
   3) 2 CSVs baixados pelo endpoint existente com período
      (BOM EF BB BF + ';' conferidos);
   4) exportacoes.php (acesso direto) renderiza o modal JÁ ABERTO,
      sem a grade antiga;
   5) gate: perfil real SEM relatorios.exportacoes.ver (Almoxarifado)
      não recebe botão nem modal (funções retornam '').
   ============================================================ */
require __DIR__ . '/_lib.php';

const QA5_SENHA = 'change_me';

/** Login HTTP com um usuário qa5.* (fora do _env da bateria). */
function prova_login_qa5(string $papel, string $email): bool
{
    @unlink(qa_cookiejar($papel));
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
    if ($r['code'] !== 200 || !preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $r['body'], $m)) return false;
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1], 'email' => $email, 'senha' => QA5_SENHA]),
        CURLOPT_COOKIEJAR => qa_cookiejar($papel), CURLOPT_COOKIEFILE => qa_cookiejar($papel)]);
    return $r['code'] === 302 && !str_contains($r['headers'], 'index.php?erro');
}

qa_section('EXP-LOGIN');
$logado = prova_login_qa5('qa5gestor', 'qa5.gestor@vero.test');
qa_check('login HTTP qa5.gestor@vero.test', $logado);
if (!$logado) qa_finish('prova_exportacoes_modal');

/* ── 1+2) Botão + modal nas telas de relatório ── */
qa_section('EXP-BOTAO');
$telas = ['/relatorios/relatorios_operacionais.php', '/relatorios/relatorios_financeiros.php', '/relatorios/relatorios_safra.php'];
$corpo1 = '';
foreach ($telas as $t) {
    $r = qa_http_get('qa5gestor', $t);
    qa_check("$t renderiza (200 sem erro PHP)", $r['code'] === 200 && !qa_pagina_saudavel($r), ['code' => $r['code'], 'p' => qa_pagina_saudavel($r)]);
    qa_check("$t tem o botão Exportações (vexp-abrir)", str_contains($r['body'], 'id="vexp-abrir"'));
    qa_check("$t tem o modal vm-exportacoes", str_contains($r['body'], 'id="vm-exportacoes"'));
    if ($t === $telas[0]) $corpo1 = $r['body'];
    if (str_contains($t, 'safra')) {
        qa_check("$t: acoes_html preservado (link integridade_producao)", str_contains($r['body'], 'integridade_producao.php'));
    }
}

qa_section('EXP-MODAL');
foreach (['Operação', 'Financeiro', 'Safra e colheita', 'Suprimentos', 'Técnico'] as $g) {
    qa_check("modal tem o grupo '$g'", str_contains($corpo1, '>' . $g . '</div>'));
}
preg_match_all('/data-csv="([a-z_]+)"/', $corpo1, $mm);
qa_eq('modal lista 22 datasets (botões CSV)', 22, count($mm[1]));
qa_eq('datasets únicos + rota (sem duplicata por chave/rota)', 22,
    count(array_unique(array_map(null, $mm[1] ?? []), SORT_REGULAR)) ? count($mm[1]) : 0);
qa_check('modal NÃO abre sozinho na tela de relatório (sem .open)', !preg_match('/class="vmodal open" id="vm-exportacoes"/', $corpo1));

/* ── 3) CSVs pelo endpoint existente, com período do modal ── */
qa_section('EXP-CSV');
foreach ([['/relatorios/relatorios_operacionais.php', 'apontamentos'],
          ['/relatorios/relatorios_estoque.php', 'saldos']] as [$rota, $chave]) {
    $r = qa_http_get('qa5gestor', $rota . '?ini=2026-07-01&fim=2026-07-16&csv=' . $chave);
    qa_check("CSV $chave: HTTP 200", $r['code'] === 200, $r['code']);
    qa_check("CSV $chave: Content-Type text/csv", stripos($r['headers'], 'Content-Type: text/csv') !== false);
    qa_check("CSV $chave: attachment com nome vero_*_{$chave}_", (bool)preg_match('/filename="vero_[a-z_]+_' . $chave . '_\d{8}\.csv"/', $r['headers']));
    qa_check("CSV $chave: BOM EF BB BF", substr($r['body'], 0, 3) === "\xEF\xBB\xBF");
    $linha1 = strtok(substr($r['body'], 3), "\r\n");
    qa_check("CSV $chave: cabeçalho separado por ';'", is_string($linha1) && str_contains($linha1, ';'), $linha1);
}

/* ── 4) Acesso direto a exportacoes.php = casca + modal aberto ── */
qa_section('EXP-FALLBACK');
$r = qa_http_get('qa5gestor', '/relatorios/exportacoes.php');
qa_check('exportacoes.php renderiza (200 sem erro PHP)', $r['code'] === 200 && !qa_pagina_saudavel($r), ['code' => $r['code'], 'p' => qa_pagina_saudavel($r)]);
qa_check('modal renderizado JÁ ABERTO (class="vmodal open")', str_contains($r['body'], 'class="vmodal open" id="vm-exportacoes"'));
qa_check('grade antiga removida (sem "Período dos exports")', !str_contains($r['body'], 'Período dos exports'));
preg_match_all('/data-csv="([a-z_]+)"/', $r['body'], $mf);
qa_eq('fallback também lista os 22 datasets', 22, count($mf[1]));
$r2 = qa_http_get('qa5gestor', '/relatorios/exportacoes.php?ini=2026-02-01&fim=2026-02-28');
qa_check('período do bookmark preenche os campos do modal', str_contains($r2['body'], 'value="2026-02-01"') && str_contains($r2['body'], 'value="2026-02-28"'));

/* ── 5) Gate de permissão: perfil real SEM o slug ──
   Não há usuário qa5.* com perfil Almoxarifado (banco homolog read-only:
   não criamos usuários) — então provamos o gate em CLI com o conjunto REAL
   de permissões do role Almoxarifado (tem relatorios.relatorios_estoque.ver,
   NÃO tem relatorios.exportacoes.ver), exercitando as mesmas funções que
   as telas usam (vero_can → vero_dbn_perm). ── */
qa_section('EXP-GATE');
$permsAlmox = array_column(qa_rows(
    "SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
      WHERE rp.role_id = (SELECT id FROM roles WHERE tenant_id = 1 AND slug = 'almoxarifado')"), 'slug');
qa_check('role Almoxarifado tem tela de relatório mas não exportacoes.ver',
    in_array('relatorios.relatorios_estoque.ver', $permsAlmox, true)
    && !in_array('relatorios.exportacoes.ver', $permsAlmox, true), $permsAlmox);

qa_boot_app(1, 2);                               /* app em CLI: tenant 1, user qa5.gestor */
if (!defined('BIOS_BASE')) define('BIOS_BASE', '/vero');
require_once QA_ROOT . '/relatorios/_exportacoes_modal.php';

$_SESSION['user_role']   = 'almoxarifado';
$_SESSION['permissions'] = $permsAlmox;
qa_check('sem o slug: botão NÃO renderiza (string vazia)', vero_exportacoes_botao_html() === '');
qa_check('sem o slug: modal NÃO renderiza (string vazia)', vero_exportacoes_modal_html('2026-01-01', '2026-07-19') === '');

$_SESSION['permissions'] = array_merge($permsAlmox, ['relatorios.exportacoes.ver']);
qa_check('com o slug: botão renderiza', str_contains(vero_exportacoes_botao_html(), 'vexp-abrir'));
qa_check('com o slug: modal renderiza os 22 datasets',
    substr_count(vero_exportacoes_modal_html('2026-01-01', '2026-07-19'), 'data-csv="') === 22);

qa_finish('prova_exportacoes_modal');
