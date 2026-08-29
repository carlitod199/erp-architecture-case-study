<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/prova_r2_05_06_irrigacao.php  (A1-IRR)
   Prova dos achados R2-05 (origem do custo de irrigação) e
   R2-06 (lâmina realizada = consumo ÷ (área × 10)) no tenant 1
   (massa de referência do auditor: 450 m³/R$120 e 380 kWh/R$310).
   GET/POST autenticados via HTTP real (qa5.gestor / tenant 1).
   O apontamento AUTO criado aqui é EXCLUÍDO ao final.
   ============================================================ */

require __DIR__ . '/_lib.php';

const PAPEL = 'r2irr';
const SENHA_QA5 = 'change_me';
const EMAIL_QA5 = 'qa5.gestor@vero.test';

/** Login HTTP no tenant 1 (usuário qa5.* da auditoria A4-06). */
function r2_login(): bool
{
    @unlink(qa_cookiejar(PAPEL));
    unset($GLOBALS['qa_csrf'][PAPEL]);
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_COOKIEJAR => qa_cookiejar(PAPEL), CURLOPT_COOKIEFILE => qa_cookiejar(PAPEL)]);
    if ($r['code'] !== 200 || !preg_match('/name="csrf_token"\s+value="([0-9a-f]+)"/', $r['body'], $m)) {
        return false;
    }
    $token = $m[1];
    $r = qa_curl(qa_base() . '/index.php', [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'csrf_token' => $token, 'email' => EMAIL_QA5, 'senha' => SENHA_QA5]),
        CURLOPT_COOKIEJAR => qa_cookiejar(PAPEL), CURLOPT_COOKIEFILE => qa_cookiejar(PAPEL)]);
    $ok = $r['code'] === 302 && !str_contains($r['headers'], 'index.php?erro');
    if ($ok) $GLOBALS['qa_csrf'][PAPEL] = $token;
    return $ok;
}

/** Primeira linha <tr>…</tr> do body que contém TODAS as agulhas. */
function r2_tr(string $body, array $agulhas): string
{
    if (!preg_match_all('#<tr>(.*?)</tr>#s', $body, $m)) return '';
    foreach ($m[1] as $tr) {
        foreach ($agulhas as $a) {
            if (!str_contains($tr, $a)) continue 2;
        }
        return $tr;
    }
    return '';
}

qa_section('Setup');
qa_check('login HTTP qa5.gestor (tenant 1)', r2_login());

/* ── R2-05: massa de referência marcada como manual ───────────── */
qa_section('R2-05 consumo de água — R$120 manual + lâmina implícita');
$r = qa_http_get(PAPEL, '/irrigacao/consumo_agua.php');
qa_eq('GET consumo_agua.php HTTP 200', 200, $r['code']);
$tr = r2_tr($r['body'], ['04/07/2026', '450,0']);
qa_check('lançamento 450 m³ presente', $tr !== '');
qa_check('R$ 120,00 exibido na linha', str_contains($tr, '120,00'), $tr);
qa_check('badge manual / tarifa da época (não derivável de 0,85)',
    str_contains($tr, 'manual / tarifa da época'), $tr);
qa_check('hint honesto: não derivável da tarifa atual',
    str_contains($tr, 'não derivável da tarifa atual'), $tr);
qa_check('lâmina do consumo 11,25 mm (450 ÷ (4 × 10)) ao lado da registrada 8,0',
    str_contains($tr, '11,25') && str_contains($tr, '8,0'), $tr);
qa_check('rodapé explica a regra auto × manual', str_contains($r['body'], 'tolerância R$ 0,01'));

qa_section('R2-05 consumo de energia — R$310 manual');
$r = qa_http_get(PAPEL, '/irrigacao/consumo_energia.php');
qa_eq('GET consumo_energia.php HTTP 200', 200, $r['code']);
$tr = r2_tr($r['body'], ['04/07/2026', '380,0']);
qa_check('lançamento 380 kWh presente', $tr !== '');
qa_check('R$ 310,00 exibido na linha', str_contains($tr, '310,00'), $tr);
qa_check('badge manual / tarifa da época (não derivável de 0,92)',
    str_contains($tr, 'manual / tarifa da época'), $tr);

/* ── R2-06: lâmina realizada no planejado × realizado ─────────── */
qa_section('R2-06 planejado × realizado — lâmina realizada 11,25 mm');
$r = qa_http_get(PAPEL, '/irrigacao/planejado_realizado.php');
qa_eq('GET planejado_realizado.php HTTP 200', 200, $r['code']);
$tr = r2_tr($r['body'], ['11,25']);
qa_check('coluna Realizada — consumo (mm) presente', str_contains($r['body'], 'Realizada — consumo (mm)'));
qa_check('realizada 11,25 mm na linha do planejamento', $tr !== '');
qa_check('apontada 8,0 mm ao lado', str_contains($tr, '8,0'), $tr);
qa_check('divergência sinalizada (≠ apontada)', str_contains($tr, '≠ apontada'), $tr);
qa_check('fórmula no title (450,0 m³ ÷ (4,00 ha × 10))',
    str_contains($tr, '450,0 m³ ÷ (4,00 ha × 10)'), $tr);
qa_check('rodapé com a fórmula da lâmina realizada',
    str_contains($r['body'], 'consumo de água (m³) ÷ (área da válvula em ha × 10)'));

/* ── Lançamento AUTO (C-21): cria, prova o badge, exclui ──────── */
qa_section('C-21 lançamento auto — qtd × tarifa marcada como auto');
$maxAntes = (int)qa_val("SELECT COALESCE(MAX(id),0) FROM irrigacao_apontamentos WHERE tenant_id = 1");
$r = qa_http_post(PAPEL, '/irrigacao/apontamentos_irrigacao.php', [
    'acao' => 'salvar', 'talhao_id' => 1, 'data_apontamento' => '2026-07-18',
    'horas' => '2', 'lamina_mm' => '2,5',
    'agua_qtd' => '100,0', 'agua_custo' => '',            /* custo em branco → auto */
    'energia_qtd' => '', 'energia_custo' => '',
]);
qa_eq('POST salvar apontamento → 302', 302, $r['code']);
$novo = qa_row("SELECT a.id, c.custo, c.quantidade FROM irrigacao_apontamentos a
                  JOIN irrigacao_consumos c ON c.apontamento_id = a.id AND c.tipo = 'agua'
                 WHERE a.tenant_id = 1 AND a.id > ?", [$maxAntes]);
qa_check('apontamento novo criado', $novo !== null);
$novoId = $novo ? (int)$novo['id'] : 0;
if ($novo) {
    qa_eqf('custo gravado automaticamente = 100 × 0,85 = 85,00', 85.00, $novo['custo']);
    $r = qa_http_get(PAPEL, '/irrigacao/consumo_agua.php');
    $tr = r2_tr($r['body'], ['18/07/2026', '100,0']);
    qa_check('badge auto (100,0 × tarifa R$ 0,8500) na tela de consumo',
        str_contains($tr, 'auto (100,0 × tarifa R$ 0,8500)'), $tr);
}

qa_section('Limpeza — exclui o apontamento auto');
if ($novoId) {
    $r = qa_http_post(PAPEL, '/irrigacao/apontamentos_irrigacao.php',
        ['acao' => 'excluir', 'id' => $novoId]);
    qa_eq('POST excluir → 302', 302, $r['code']);
    qa_eq('apontamento removido', 0,
        qa_val("SELECT COUNT(*) FROM irrigacao_apontamentos WHERE tenant_id = 1 AND id = ?", [$novoId]));
    qa_eq('consumos removidos', 0,
        qa_val("SELECT COUNT(*) FROM irrigacao_consumos WHERE tenant_id = 1 AND apontamento_id = ?", [$novoId]));
    qa_eq('massa de referência intacta (2 consumos: 450 m³ e 380 kWh)', 2,
        qa_val("SELECT COUNT(*) FROM irrigacao_consumos WHERE tenant_id = 1"));
} else {
    qa_skip('exclusão', 'apontamento não foi criado');
}

qa_finish('prova_r2_05_06_irrigacao');
