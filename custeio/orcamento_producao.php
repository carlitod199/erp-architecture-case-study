<?php
/* ============================================================
   VERO — Custos / Orçamento de Produção (A3-T26)
   Rota: /custeio/orcamento_producao.php · Guard: custeio.orcamento_producao
   SPEC: VERO_CUSTO_PRODUCAO_SPEC.md — wizard guiado (cabeçalho puxa
   defaults dos parâmetros da cultura×safra e a área plantada) →
   gera itens da metodologia → tabela EDITÁVEL de valores →
   indicadores via vero_srv_custo_indicadores (equilíbrio na v1 —
   P-76). Regras do cliente: aprovação por GESTOR/ADMIN (P-73, checagem
   por ROLE — precedente A2/bula); realizado manual SÓ p/ itens de
   origem=manual, por gestor/financeiro COM justificativa (P-74);
   MARGEM visível só p/ quem enxerga financeiro (P-75 — proxy
   financeiro.dre_agro.ver: admin/gestor/financeiro/contador têm,
   consulta/operador não); cópia entre safras (P-72); snapshot dos
   resultados APENAS no fechamento; migração do legado
   custeio_orcamentos → metodologia "Simplificada" (ação interativa,
   idempotente — nada se perde).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

/* P-73/P-74 por ROLE (precedente A2/bula); club_admin era legado — removido no A0-04 */
const ORC_ROLES_APROVA = ['gestor', 'administrador', 'super_admin'];
const ORC_ROLES_REAL_MANUAL = ['gestor', 'financeiro', 'administrador', 'super_admin'];

$t = vero_tenant();

/** linhas do orçamento no formato do service (join itens × custo_itens × grupos) */
function orc_linhas(int $orcId): array
{
    return vero_rows(
        "SELECT oi.*, ci.nome, ci.metodo_calculo, ci.origem, ci.percentual, ci.percentual_base,
                ci.unidade_calculo, ci.id AS item_id, g.id AS grupo_id,
                g.nome AS grupo_nome, g.tipo AS grupo_tipo, g.ordem AS grupo_ordem
           FROM agro_custo_orcamento_itens oi
           JOIN agro_custo_itens ci ON ci.id = oi.item_id
           JOIN agro_custo_grupos g ON g.id = oi.grupo_id
          WHERE oi.tenant_id = :t AND oi.orcamento_id = :o
          ORDER BY g.ordem, oi.ordem, oi.id", [':t' => vero_tenant(), ':o' => $orcId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');
    $role = (string)($_SESSION['user_role'] ?? '');

    if ($acao === 'criar') { /* wizard: cabeçalho + geração dos itens da metodologia */
        vero_require('custeio.orcamento_producao.editar');
        $culturaId = vero_int('cultura_id');
        $safraId = vero_int('safra_id');
        $fazendaId = vero_int('fazenda_id');
        $talhaoId = vero_int('talhao_id') ?: null; /* NULL = fazenda toda (P-69) */
        $metId = vero_int('metodologia_id');
        $area = vero_dec('area_ha');
        if (!$culturaId || !$safraId || !$fazendaId || !$metId || $area === null || $area <= 0) {
            vero_flash('erro', 'Cultura, safra, fazenda, metodologia e área (> 0) são obrigatórios.');
            vero_redirect('?novo=1');
        }
        foreach ([['agro_culturas', $culturaId], ['agro_safras', $safraId], ['agro_fazendas', $fazendaId]] as [$tb, $idv]) {
            if (!vero_val("SELECT id FROM {$tb} WHERE id=:i AND tenant_id=:t", [':i' => $idv, ':t' => $t])) {
                vero_flash('erro', 'Vínculo inválido.'); vero_redirect('?novo=1');
            }
        }
        if ($talhaoId && !vero_val("SELECT id FROM agro_talhoes WHERE id=:i AND tenant_id=:t AND fazenda_id=:f",
            [':i' => $talhaoId, ':t' => $t, ':f' => $fazendaId])) {
            vero_flash('erro', 'Válvula não é desta fazenda.'); vero_redirect('?novo=1');
        }
        $itensMet = vero_rows(
            "SELECT i.*, g.id AS gid FROM agro_custo_itens i
              JOIN agro_custo_grupos g ON g.id = i.grupo_id
             WHERE i.tenant_id = :t AND g.metodologia_id = :m AND i.ativo = 1 AND g.ativo = 1
             ORDER BY g.ordem, i.ordem", [':t' => $t, ':m' => $metId]);
        if (!$itensMet) { vero_flash('erro', 'Metodologia sem itens ativos.'); vero_redirect('?novo=1'); }

        /* A11: produtividade e preço previstos são sempre >= 0 */
        $prodPrev  = vero_dec('produtividade_prevista_ha');
        $precoPrev = vero_dec('preco_previsto_unidade');
        if (($prodPrev !== null && $prodPrev < 0) || ($precoPrev !== null && $precoPrev < 0)) {
            vero_flash('erro', 'Produtividade e preço previstos não podem ser negativos.');
            vero_redirect('?novo=1');
        }

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $orcId = vero_insert('agro_custo_orcamentos', [
                'cultura_id' => $culturaId, 'safra_id' => $safraId, 'fazenda_id' => $fazendaId,
                'talhao_id' => $talhaoId, 'metodologia_id' => $metId, 'area_ha' => $area,
                'produtividade_prevista_ha' => $prodPrev,
                'preco_previsto_unidade' => $precoPrev,
                'status' => 'rascunho', 'observacoes' => vero_str('observacoes', 255),
            ]);
            foreach ($itensMet as $ix => $im) {
                vero_insert('agro_custo_orcamento_itens', [
                    'orcamento_id' => $orcId, 'item_id' => (int)$im['id'], 'grupo_id' => (int)$im['gid'],
                    'ordem' => (int)$im['ordem'],
                    'formula_registrada' => (string)$im['metodo_calculo'],
                ]);
            }
            $pdo->commit();
            vero_flash('ok', 'Orçamento criado com ' . count($itensMet) . ' item(ns) da metodologia — informe os valores previstos.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
            vero_redirect('?novo=1');
        }
        vero_redirect('?ver=' . (int)$orcId);
    }

    if ($acao === 'valores') { /* tabela editável — grava todos os itens de uma vez */
        vero_require('custeio.orcamento_producao.editar');
        $orcId = vero_int('orcamento_id');
        $orc = $orcId ? vero_row("SELECT * FROM agro_custo_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $orcId, ':t' => $t]) : null;
        if (!$orc || !in_array($orc['status'], ['rascunho', 'aprovado', 'em_execucao'], true)) {
            vero_flash('erro', 'Orçamento inválido ou fechado/cancelado.');
            vero_redirect();
        }
        $qtd = (array)($_POST['qtd'] ?? []);
        $unit = (array)($_POST['unit'] ?? []);
        $vha = (array)($_POST['vha'] ?? []);
        $vtot = (array)($_POST['vtot'] ?? []);
        $realM = (array)($_POST['real_manual'] ?? []);
        $justM = (array)($_POST['just_manual'] ?? []);
        $dec = static function ($v): ?float {
            $v = trim((string)$v);
            if ($v === '') return null;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            return is_numeric($v) ? (float)$v : null;
        };
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* QA-009: (1) coluna só grava se o campo VEIO no POST — array_key_exists,
               POST parcial não zera o resto (padrão da correção T25); (2) previstos
               editáveis SÓ em rascunho — aprovado/em_execucao aceita apenas o
               realizado manual (P-74); revisar previstos = cancelar e copiar. */
            $ehRascunho = (string)$orc['status'] === 'rascunho';
            foreach (orc_linhas($orcId) as $l) {
                $oid = (int)$l['id'];
                $dados = [];
                foreach ([['quantidade_prevista', $qtd], ['valor_unitario_previsto', $unit],
                          ['valor_previsto_ha', $vha], ['valor_previsto_total', $vtot]] as [$col, $arr]) {
                    if (!array_key_exists($oid, $arr)) continue;
                    if (!$ehRascunho) {
                        throw new RuntimeException('Previstos só são editáveis em RASCUNHO — orçamento "' . $orc['status'] . '" aceita apenas realizado manual.');
                    }
                    $dados[$col] = $dec($arr[$oid]);
                }
                /* P-74: realizado manual SÓ p/ item origem=manual, role autorizada, com justificativa */
                if ((string)$l['origem'] === 'manual' && array_key_exists($oid, $realM)) {
                    $rv = $dec($realM[$oid] ?? '');
                    $rj = trim((string)($justM[$oid] ?? ''));
                    if ($rv !== null && !in_array($role, ORC_ROLES_REAL_MANUAL, true)) {
                        throw new RuntimeException('Realizado manual: apenas gestor/financeiro.');
                    }
                    if ($rv !== null && $rj === '') {
                        throw new RuntimeException("Realizado manual do item \"{$l['nome']}\" exige JUSTIFICATIVA.");
                    }
                    $dados['valor_realizado_manual'] = $rv;
                    $dados['justificativa_manual'] = $rv !== null ? mb_substr($rj, 0, 255) : null;
                }
                if ($dados) vero_update('agro_custo_orcamento_itens', $oid, $dados);
            }
            $pdo->commit();
            vero_flash('ok', 'Valores gravados.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
        }
        vero_redirect('?ver=' . $orcId);
    }

    if ($acao === 'status') {
        vero_require('custeio.orcamento_producao.editar');
        $orcId = vero_int('id');
        $novo = (string)($_POST['novo_status'] ?? '');
        $orc = $orcId ? vero_row("SELECT * FROM agro_custo_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $orcId, ':t' => $t]) : null;
        $trans = ['rascunho' => ['aprovado', 'cancelado'], 'aprovado' => ['em_execucao', 'cancelado'],
                  'em_execucao' => ['fechado'], 'fechado' => [], 'cancelado' => []];
        if (!$orc || !in_array($novo, VERO_CUSTO_STATUS_ORCAMENTO, true)
            || !in_array($novo, $trans[(string)$orc['status']] ?? [], true)) {
            vero_flash('erro', 'Transição de status inválida.');
            vero_redirect('?ver=' . (int)$orcId);
        }
        if ($novo === 'aprovado' && !in_array($role, ORC_ROLES_APROVA, true)) { /* P-73 */
            vero_flash('erro', 'Aprovação de orçamento é do GESTOR/ADMIN.');
            vero_redirect('?ver=' . $orcId);
        }
        $dados = ['status' => $novo];
        if ($novo === 'aprovado') { $dados['aprovado_por'] = vero_uid(); $dados['aprovado_em'] = date('Y-m-d H:i:s'); }
        if ($novo === 'fechado') { /* snapshot APENAS aqui (SPEC §2.3) */
            $ind = vero_srv_custo_indicadores($orc, orc_linhas($orcId));
            $dados['fechado_em'] = date('Y-m-d H:i:s');
            $dados['snapshot_resultados'] = json_encode($ind, JSON_UNESCAPED_UNICODE);
        }
        vero_update('agro_custo_orcamentos', $orcId, $dados);
        vero_flash('ok', 'Orçamento → ' . $novo . ($novo === 'fechado' ? ' (snapshot dos resultados gravado).' : '.'));
        vero_redirect('?ver=' . $orcId);
    }

    if ($acao === 'copiar') { /* P-72: cópia entre safras */
        vero_require('custeio.orcamento_producao.editar');
        $orcId = vero_int('id');
        $safraDest = vero_int('safra_destino');
        $orc = $orcId ? vero_row("SELECT * FROM agro_custo_orcamentos WHERE id=:i AND tenant_id=:t", [':i' => $orcId, ':t' => $t]) : null;
        if (!$orc || !$safraDest || !vero_val("SELECT id FROM agro_safras WHERE id=:i AND tenant_id=:t", [':i' => $safraDest, ':t' => $t])) {
            vero_flash('erro', 'Orçamento/safra de destino inválidos.');
            vero_redirect();
        }
        /* parâmetros da safra destino atualizam produtividade/preço (snapshot novo) */
        $par = vero_row("SELECT * FROM agro_custo_parametros_cultura
                          WHERE tenant_id=:t AND cultura_id=:c AND safra_id=:s AND ativo=1",
            [':t' => $t, ':c' => (int)$orc['cultura_id'], ':s' => $safraDest]);
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $novoId = vero_insert('agro_custo_orcamentos', [
                'cultura_id' => (int)$orc['cultura_id'], 'safra_id' => $safraDest,
                'fazenda_id' => (int)$orc['fazenda_id'],
                'talhao_id' => $orc['talhao_id'] !== null ? (int)$orc['talhao_id'] : null,
                'metodologia_id' => (int)$orc['metodologia_id'], 'area_ha' => $orc['area_ha'],
                'produtividade_prevista_ha' => $par['produtividade_prevista_ha'] ?? $orc['produtividade_prevista_ha'],
                'preco_previsto_unidade' => $par['preco_previsto_unidade'] ?? $orc['preco_previsto_unidade'],
                'status' => 'rascunho',
                'observacoes' => 'Copiado do orçamento #' . $orcId . ' (P-72)',
            ]);
            foreach (orc_linhas($orcId) as $l) {
                vero_insert('agro_custo_orcamento_itens', [
                    'orcamento_id' => $novoId, 'item_id' => (int)$l['item_id'], 'grupo_id' => (int)$l['grupo_id'],
                    'quantidade_prevista' => $l['quantidade_prevista'],
                    'valor_unitario_previsto' => $l['valor_unitario_previsto'],
                    'valor_previsto_ha' => $l['valor_previsto_ha'],
                    'valor_previsto_total' => $l['valor_previsto_total'],
                    'formula_registrada' => $l['formula_registrada'], 'ordem' => (int)$l['ordem'],
                    /* realizado manual NÃO copia (é da safra de origem) */
                ]);
            }
            $pdo->commit();
            vero_flash('ok', 'Orçamento copiado para a safra de destino (rascunho — realizado manual não é copiado).');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', $e->getMessage());
            vero_redirect();
        }
        vero_redirect('?ver=' . (int)$novoId);
    }

    if ($acao === 'migrar_legado') { /* custeio_orcamentos → metodologia "Simplificada" (idempotente) */
        vero_require('custeio.orcamento_producao.editar');
        if (!in_array($role, ORC_ROLES_APROVA, true)) {
            vero_flash('erro', 'Migração do legado é do GESTOR/ADMIN.');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            /* metodologia Simplificada get-or-create: 1 grupo + 1 item por categoria (mapa = a categoria) */
            $metId = vero_val("SELECT id FROM agro_custo_metodologias WHERE tenant_id=:t AND nome='Simplificada (legado)'", [':t' => $t]);
            if (!$metId) {
                $metId = vero_insert('agro_custo_metodologias', [
                    'nome' => 'Simplificada (legado)', 'tipo_ciclo' => 'perene',
                    'descricao' => 'Migrada de custeio_orcamentos — 1 item por categoria de custeio', 'padrao' => 0, 'ativo' => 1]);
                $gid = vero_insert('agro_custo_grupos', ['metodologia_id' => (int)$metId,
                    'nome' => 'Categorias de custeio', 'tipo' => 'variavel', 'ordem' => 1, 'ativo' => 1]);
                $ordem = 1;
                foreach (['mao_de_obra', 'insumos', 'maquinas', 'irrigacao', 'mip', 'depreciacao', 'outros'] as $cat) {
                    vero_insert('agro_custo_itens', ['grupo_id' => (int)$gid,
                        'nome' => ucfirst(str_replace('_', ' ', $cat)), 'metodo_calculo' => 'valor_total_area',
                        'origem' => 'custeio', 'ordem' => $ordem++, 'ativo' => 1,
                        'mapa_realizado' => json_encode(['origens' => [], 'categorias' => [$cat], 'planos' => []]),
                    ]);
                }
            }
            $metId = (int)$metId;
            $itensSimpl = vero_rows("SELECT i.id, i.nome, i.grupo_id FROM agro_custo_itens i
                JOIN agro_custo_grupos g ON g.id = i.grupo_id
               WHERE i.tenant_id=:t AND g.metodologia_id=:m ORDER BY i.ordem", [':t' => $t, ':m' => $metId]);
            $itemPorNome = [];
            foreach ($itensSimpl as $is) $itemPorNome[mb_strtolower((string)$is['nome'])] = $is;

            $migrados = 0; $pulados = 0;
            foreach (vero_rows("SELECT * FROM custeio_orcamentos WHERE tenant_id=:t", [':t' => $t]) as $leg) {
                $marca = 'Migrado de custeio_orcamentos #' . (int)$leg['id'];
                if (vero_val("SELECT id FROM agro_custo_orcamentos WHERE tenant_id=:t AND observacoes=:o",
                    [':t' => $t, ':o' => $marca])) { $pulados++; continue; }
                $safraLeg = (int)$leg['safra_id'];
                $areaLeg = (float)(vero_val("SELECT COALESCE(SUM(area_plantada_ha),0) FROM agro_safra_talhoes
                    WHERE tenant_id=:t AND safra_id=:s", [':t' => $t, ':s' => $safraLeg]) ?? 0);
                /* cultura/fazenda dominantes da safra (1º vínculo) */
                $vinc = vero_row("SELECT st.cultura_id, tl.fazenda_id FROM agro_safra_talhoes st
                    JOIN agro_talhoes tl ON tl.id = st.talhao_id
                   WHERE st.tenant_id=:t AND st.safra_id=:s LIMIT 1", [':t' => $t, ':s' => $safraLeg]);
                if (!$vinc) { $pulados++; continue; }
                $stMap = ['vigente' => 'aprovado', 'rascunho' => 'rascunho', 'encerrado' => 'fechado'];
                $novoId = vero_insert('agro_custo_orcamentos', [
                    'cultura_id' => (int)$vinc['cultura_id'], 'safra_id' => $safraLeg,
                    'fazenda_id' => (int)$vinc['fazenda_id'], 'talhao_id' => null,
                    'metodologia_id' => $metId, 'area_ha' => $areaLeg ?: 1,
                    'status' => $stMap[(string)$leg['status']] ?? 'rascunho', 'observacoes' => $marca,
                ]);
                foreach (vero_rows("SELECT categoria, SUM(valor_previsto) v FROM custeio_orcamento_itens
                    WHERE tenant_id=:t AND orcamento_id=:o GROUP BY categoria",
                    [':t' => $t, ':o' => (int)$leg['id']]) as $li) {
                    $chave = mb_strtolower(ucfirst(str_replace('_', ' ', (string)$li['categoria'])));
                    $item = $itemPorNome[$chave] ?? $itemPorNome['outros'] ?? null;
                    if (!$item) continue;
                    vero_insert('agro_custo_orcamento_itens', [
                        'orcamento_id' => (int)$novoId, 'item_id' => (int)$item['id'],
                        'grupo_id' => (int)$item['grupo_id'], 'valor_previsto_total' => (float)$li['v'],
                        'formula_registrada' => 'valor_total_area (migrado)', 'ordem' => 1,
                    ]);
                }
                $migrados++;
            }
            $pdo->commit();
            vero_flash('ok', "Migração do legado: {$migrados} orçamento(s) migrados, {$pulados} já migrado(s)/sem vínculo. O legado permanece intacto em Custos → Orçamento de Safra.");
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Migração falhou (nada gravado): ' . $e->getMessage());
        }
        vero_redirect();
    }
}

/* ── Dados ── */
$orcamentos = vero_rows(
    "SELECT o.*, c.nome AS cultura, s.identificacao AS safra, f.nome AS fazenda,
            tl.codigo AS talhao, m.nome AS metodologia
       FROM agro_custo_orcamentos o
       JOIN agro_culturas c ON c.id = o.cultura_id
       JOIN agro_safras s ON s.id = o.safra_id
       JOIN agro_fazendas f ON f.id = o.fazenda_id
       LEFT JOIN agro_talhoes tl ON tl.id = o.talhao_id
       JOIN agro_custo_metodologias m ON m.id = o.metodologia_id
      WHERE o.tenant_id = :t
      ORDER BY FIELD(o.status,'em_execucao','aprovado','rascunho','fechado','cancelado'), o.id DESC", [':t' => $t]);

$ver = null; $linhas = []; $ind = null;
if (!empty($_GET['ver'])) {
    $ver = vero_row(
        "SELECT o.*, c.nome AS cultura, c.unidade_comercial, s.identificacao AS safra, f.nome AS fazenda,
                tl.codigo AS talhao, m.nome AS metodologia
           FROM agro_custo_orcamentos o
           JOIN agro_culturas c ON c.id = o.cultura_id
           JOIN agro_safras s ON s.id = o.safra_id
           JOIN agro_fazendas f ON f.id = o.fazenda_id
           LEFT JOIN agro_talhoes tl ON tl.id = o.talhao_id
           JOIN agro_custo_metodologias m ON m.id = o.metodologia_id
          WHERE o.id=:i AND o.tenant_id=:t", [':i' => (int)$_GET['ver'], ':t' => $t]);
    if ($ver) {
        $linhas = orc_linhas((int)$ver['id']);
        $ind = $ver['status'] === 'fechado' && $ver['snapshot_resultados']
            ? json_decode((string)$ver['snapshot_resultados'], true)
            : vero_srv_custo_indicadores($ver, $linhas);
    }
}

$novo = isset($_GET['novo']);
$culturas = vero_options('agro_culturas', 'nome');
$safras = vero_options('agro_safras', 'identificacao');
$fazendas = vero_options('agro_fazendas', 'nome', 'ativo = 1');
$talhoes = vero_rows("SELECT id, codigo, fazenda_id FROM agro_talhoes WHERE tenant_id=:t AND ativo=1 ORDER BY codigo", [':t' => $t]);
$metodologias = vero_rows("SELECT id, nome, tipo_ciclo FROM agro_custo_metodologias WHERE tenant_id=:t AND ativo=1 ORDER BY nome", [':t' => $t]);
$parametrosJs = vero_rows(
    "SELECT p.cultura_id, p.safra_id, p.metodologia_id, p.produtividade_prevista_ha, p.preco_previsto_unidade, p.area_prevista_ha
       FROM agro_custo_parametros_cultura p WHERE p.tenant_id=:t AND p.ativo=1", [':t' => $t]);
$legadoN = (int)vero_val("SELECT COUNT(*) FROM custeio_orcamentos WHERE tenant_id=:t", [':t' => $t]);

$GUARD      = ['macro' => 'custos', 'micro' => 'orcamento_producao'];
$PAGE_VIEW  = 'custos_orcamento_producao';
$PAGE_TITLE = 'Orçamento de Produção';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('custeio.orcamento_producao.editar');
$podeAprovar = in_array((string)($_SESSION['user_role'] ?? ''), ORC_ROLES_APROVA, true);
$veMargem = vero_can('financeiro.dre_agro.ver'); /* P-75: proxy financeiro (consulta/operador não têm) */
$badgeSt = static fn(string $s): string => match ($s) {
    'rascunho' => '<span class="vbadge vb-warn">Rascunho</span>',
    'aprovado' => '<span class="vbadge vb-ok">Aprovado</span>',
    'em_execucao' => '<span class="vbadge vb-info">Em execução</span>',
    'fechado' => '<span class="vbadge vb-off">Fechado</span>',
    default => '<span class="vbadge vb-off">Cancelado</span>',
};
$fmtN = static fn($v, int $d = 2): string => $v === null ? '—' : numFmt((float)$v, $d);
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Orçamento de Produção por Cultura',
      'Previsto por item/grupo com indicadores e ponto de equilíbrio — o realizado deriva do custeio',
      $podeEditar ? '+ Novo orçamento' : null) ?>

  <?php if (!$ver && !$novo): ?>
  <div class="vcard">
    <div class="vtoolbar"><strong>Orçamentos</strong>
      <?php if ($podeEditar && $legadoN > 0 && $podeAprovar): ?>
      <form method="post" data-confirm="Migrar os <?= $legadoN ?> orçamento(s) do módulo antigo para a metodologia Simplificada? O legado permanece intacto." data-confirm-ok="Migrar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="migrar_legado">
        <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Migrar legado (<?= $legadoN ?>)</button>
      </form>
      <?php endif; ?>
    </div>
    <?php if (!$orcamentos): ?><div class="vempty">Nenhum orçamento — crie pelo botão acima ou migre o legado.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="vtable">
      <thead><tr><th>#</th><th>Cultura</th><th>Safra</th><th>Fazenda/Válvula</th><th>Metodologia</th>
        <th style="text-align:right">Área (ha)</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($orcamentos as $o): ?>
        <tr<?= $o['status'] === 'cancelado' ? ' style="opacity:.55"' : '' ?>>
          <td class="vnum"><?= (int)$o['id'] ?></td>
          <td><strong><?= h($o['cultura']) ?></strong></td>
          <td><?= h($o['safra']) ?></td>
          <td><?= h($o['fazenda']) ?><?= $o['talhao'] ? ' — ' . h($o['talhao']) : ' <span class="vhint">(toda)</span>' ?></td>
          <td><?= h($o['metodologia']) ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$o['area_ha'], 2) ?></td>
          <td><?= $badgeSt((string)$o['status']) ?></td>
          <td style="text-align:right"><div class="vactions"><?= vero_btn_icone(vero_ico_olho(), 'Abrir', '', '?ver=' . (int)$o['id']) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($novo && $podeEditar): ?>
  <div class="vcard">
    <div class="vtoolbar"><strong>Novo orçamento (assistente)</strong>
      <a class="vbtn vbtn-ghost vbtn-sm" href="?">voltar</a></div>
    <form class="vform" method="post" style="padding:10px 14px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="criar">
      <div class="vgrid">
        <div class="vfield"><label>1. Cultura *</label><select name="cultura_id" id="w-cultura" required>
          <option value="">—</option>
          <?php foreach ($culturas as $cid => $cn): ?><option value="<?= $cid ?>"><?= h($cn) ?></option><?php endforeach; ?>
        </select></div>
        <div class="vfield"><label>2. Safra *</label><select name="safra_id" id="w-safra" required>
          <?php foreach ($safras as $sid => $sn): ?><option value="<?= $sid ?>"><?= h($sn) ?></option><?php endforeach; ?>
        </select></div>
        <div class="vfield"><label>3. Fazenda *</label><select name="fazenda_id" id="w-fazenda" required>
          <?php foreach ($fazendas as $fid => $fn): ?><option value="<?= $fid ?>"><?= h($fn) ?></option><?php endforeach; ?>
        </select></div>
        <div class="vfield"><label>4. Válvula (vazio = fazenda toda)</label>
          <select name="talhao_id" id="w-talhao">
            <option value="">— fazenda toda —</option>
            <?php foreach ($talhoes as $tl): ?>
              <option value="<?= (int)$tl['id'] ?>" data-fazenda="<?= (int)$tl['fazenda_id'] ?>"><?= h($tl['codigo']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="vfield"><label>5. Metodologia *</label><select name="metodologia_id" id="w-met" required>
          <?php foreach ($metodologias as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= h($m['nome']) ?> (<?= h((string)$m['tipo_ciclo']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
        <?= vero_f_text('area_ha', '6. Área (ha) *', '', true) ?>
        <?= vero_f_text('produtividade_prevista_ha', '7. Produtividade prevista (/ha)', '') ?>
        <?= vero_f_text('preco_previsto_unidade', '8. Preço previsto (R$/un.)', '') ?>
        <div class="full"><?= vero_f_text('observacoes', '9. Observações', '') ?></div>
      </div>
      <div class="vhint" style="margin-top:8px">
        10. Ao criar, os itens da metodologia são gerados para você preencher os valores.
        Cultura+safra com parâmetros cadastrados preenchem metodologia/produtividade/preço/área automaticamente.
      </div>
      <div class="vform-actions"><button class="vbtn vbtn-primary" type="submit">Criar e ir para os itens</button></div>
    </form>
    <script>
    /* defaults dos parâmetros da cultura×safra (T25) */
    const PARAMS = <?= jsvar($parametrosJs) ?>;
    function aplicaParam() {
      const c = document.getElementById('w-cultura').value, s = document.getElementById('w-safra').value;
      const p = PARAMS.find(x => String(x.cultura_id) === c && String(x.safra_id) === s);
      if (!p) return;
      document.getElementById('w-met').value = p.metodologia_id;
      const set = (n, v) => { const el = document.querySelector('[name="' + n + '"]'); if (el && v !== null && el.value === '') el.value = String(v).replace('.', ','); };
      set('produtividade_prevista_ha', p.produtividade_prevista_ha);
      set('preco_previsto_unidade', p.preco_previsto_unidade);
      set('area_ha', p.area_prevista_ha);
    }
    document.getElementById('w-cultura').addEventListener('change', aplicaParam);
    document.getElementById('w-safra').addEventListener('change', aplicaParam);
    /* válvulas filtrados pela fazenda escolhida */
    function filtraTalhoes() {
      const f = document.getElementById('w-fazenda').value;
      const sel = document.getElementById('w-talhao');
      sel.value = '';
      Array.from(sel.options).forEach(o => {
        if (!o.value) return;
        o.hidden = o.dataset.fazenda !== f;
      });
    }
    document.getElementById('w-fazenda').addEventListener('change', filtraTalhoes);
    filtraTalhoes();
    </script>
  </div>
  <?php endif; ?>

  <?php if ($ver):
      $editavel = $podeEditar && in_array($ver['status'], ['rascunho', 'aprovado', 'em_execucao'], true);
      $editavelPrev = $editavel && $ver['status'] === 'rascunho'; /* QA-009: previstos só em rascunho */ ?>
  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar">
      <strong>#<?= (int)$ver['id'] ?> — <?= h($ver['cultura']) ?> · <?= h($ver['safra']) ?> ·
        <?= h($ver['fazenda']) ?><?= $ver['talhao'] ? ' — ' . h($ver['talhao']) : '' ?></strong>
      <?= $badgeSt((string)$ver['status']) ?>
      <span class="vhint"><?= h($ver['metodologia']) ?> · <?= numFmt((float)$ver['area_ha'], 2) ?> ha</span>
      <span style="flex:1"></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="?">lista</a>
      <?php if ($podeEditar): ?>
        <?php if ($ver['status'] === 'rascunho' && $podeAprovar): ?>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
            <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="novo_status" value="aprovado">
            <button class="vbtn vbtn-primary vbtn-sm" type="submit">Aprovar</button></form>
        <?php endif; ?>
        <?php if ($ver['status'] === 'aprovado'): ?>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
            <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="novo_status" value="em_execucao">
            <button class="vbtn vbtn-primary vbtn-sm" type="submit">Iniciar execução</button></form>
        <?php endif; ?>
        <?php if ($ver['status'] === 'em_execucao'): ?>
          <form method="post" data-confirm="Fechar o orçamento e gravar o snapshot dos resultados?" data-confirm-ok="Fechar" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
            <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
            <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="novo_status" value="fechado">
            <button class="vbtn vbtn-primary vbtn-sm" type="submit">Fechar (snapshot)</button></form>
        <?php endif; ?>
        <?php if (in_array($ver['status'], ['rascunho', 'aprovado'], true)): ?>
          <form method="post" data-confirm="Cancelar este orçamento?" data-confirm-danger data-confirm-ok="Cancelar orçamento" onsubmit="return window.veroConfirm ? true : confirm(this.getAttribute('data-confirm'))">
            <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
            <input type="hidden" name="acao" value="status"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
            <input type="hidden" name="novo_status" value="cancelado">
            <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Cancelar</button></form>
        <?php endif; ?>
        <form method="post" style="display:inline-flex;gap:4px">
          <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
          <input type="hidden" name="acao" value="copiar"><input type="hidden" name="id" value="<?= (int)$ver['id'] ?>">
          <select name="safra_destino">
            <?php foreach ($safras as $sid => $sn): if ((int)$sid === (int)$ver['safra_id']) continue; ?>
              <option value="<?= $sid ?>"><?= h($sn) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="vbtn vbtn-ghost vbtn-sm" type="submit">Copiar p/ safra</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($ind): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Custo previsto/ha</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= $fmtN($ind['custo_total_ha']) ?></strong></div>
      <div class="vkpi"><span class="vhint">Custo total (<?= numFmt((float)$ver['area_ha'], 0) ?> ha)</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= $fmtN($ind['custo_total_area']) ?></strong></div>
      <div class="vkpi"><span class="vhint">Custo/unidade</span>
        <strong class="vnum" style="font-size:1.15rem"><?= $ind['custo_por_unidade'] !== null ? 'R$ ' . $fmtN($ind['custo_por_unidade']) : '—' ?></strong></div>
      <div class="vkpi"><span class="vhint">Equilíbrio (produtividade)</span>
        <strong class="vnum" style="font-size:1.15rem"><?= $fmtN($ind['produtividade_equilibrio'], 1) ?> un./ha</strong>
        <span class="vhint">preço eq.: R$ <?= $fmtN($ind['preco_equilibrio']) ?></span></div>
      <?php if ($veMargem): ?>
      <div class="vkpi"><span class="vhint">Receita prevista/ha</span>
        <strong class="vnum" style="font-size:1.15rem">R$ <?= $fmtN($ind['receita_bruta_ha']) ?></strong></div>
      <div class="vkpi"><span class="vhint">Margem/ha</span>
        <strong class="vnum" style="font-size:1.15rem;color:<?= ($ind['margem_ha'] ?? 0) >= 0 ? 'var(--vero-ok,#1a7f4b)' : '#b3261e' ?>">
          R$ <?= $fmtN($ind['margem_ha']) ?></strong>
        <span class="vhint"><?= $ind['margem_pct'] !== null ? numFmt((float)$ind['margem_pct'], 1) . '%' : '—' ?></span></div>
      <?php endif; ?>
    </div>
    <?php if ($ver['status'] === 'fechado'): ?>
      <div class="vhint" style="padding:0 14px 10px">Snapshot gravado no fechamento
        (<?= $ver['fechado_em'] ? date('d/m/Y H:i', strtotime((string)$ver['fechado_em'])) : '' ?>) — valores congelados.</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Itens do orçamento</strong>
      <span class="vhint">valores em R$; quantidade e unitário são POR HECTARE nos métodos qtd×unitário e hora-máquina</span></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="valores">
      <input type="hidden" name="orcamento_id" value="<?= (int)$ver['id'] ?>">
      <div style="overflow-x:auto">
      <table class="vtable">
        <thead><tr><th>Grupo</th><th>Item</th><th>Método</th>
          <th style="text-align:right">Qtd/ha</th><th style="text-align:right">Unitário (R$)</th>
          <th style="text-align:right">R$/ha</th><th style="text-align:right">Total (R$)</th>
          <th style="text-align:right">Previsto/ha (calc.)</th>
          <th>Realizado manual</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l):
            $oid = (int)$l['id'];
            $prevHa = $ind['itens'][(int)$l['item_id']]['previsto_ha'] ?? 0.0;
            $inp = static fn(string $n, $v, int $d = 2): string => '<input type="text" name="' . $n . '[' . $oid . ']" value="'
                . ($v !== null ? numFmt((float)$v, $d) : '') . '" style="width:90px;text-align:right">'; ?>
          <tr>
            <td><span class="vhint"><?= h($l['grupo_nome']) ?></span></td>
            <td><strong><?= h($l['nome']) ?></strong>
              <?= $l['unidade_calculo'] ? '<span class="vhint">(' . h((string)$l['unidade_calculo']) . ')</span>' : '' ?></td>
            <td class="vhint"><?= h((string)$l['metodo_calculo']) ?><?=
              $l['metodo_calculo'] === 'percentual' ? ' ' . numFmt((float)$l['percentual'], 2) . '% ' . h((string)$l['percentual_base']) : '' ?></td>
            <?php if ($editavelPrev): /* QA-009: previstos só em rascunho */ ?>
              <td style="text-align:right"><?= in_array($l['metodo_calculo'], ['quantidade_valor_unitario', 'maquina_hora'], true) ? $inp('qtd', $l['quantidade_prevista'], 4) : '—' ?></td>
              <td style="text-align:right"><?= in_array($l['metodo_calculo'], ['quantidade_valor_unitario', 'maquina_hora'], true) ? $inp('unit', $l['valor_unitario_previsto']) : '—' ?></td>
              <td style="text-align:right"><?= in_array($l['metodo_calculo'], ['manual_ha', 'estoque_consumo', 'compra_recebida', 'folha_rateada', 'patrimonio_depreciacao'], true) ? $inp('vha', $l['valor_previsto_ha']) : '—' ?></td>
              <td style="text-align:right"><?= $l['metodo_calculo'] === 'valor_total_area' ? $inp('vtot', $l['valor_previsto_total']) : '—' ?></td>
            <?php else: ?>
              <td class="vnum" style="text-align:right"><?= $fmtN($l['quantidade_prevista'], 4) ?></td>
              <td class="vnum" style="text-align:right"><?= $fmtN($l['valor_unitario_previsto']) ?></td>
              <td class="vnum" style="text-align:right"><?= $fmtN($l['valor_previsto_ha']) ?></td>
              <td class="vnum" style="text-align:right"><?= $fmtN($l['valor_previsto_total']) ?></td>
            <?php endif; ?>
            <td class="vnum" style="text-align:right"><strong><?= numFmt($prevHa, 2) ?></strong></td>
            <td><?php if ((string)$l['origem'] === 'manual'): ?>
              <?php if ($editavel && in_array((string)($_SESSION['user_role'] ?? ''), ORC_ROLES_REAL_MANUAL, true)): ?>
                <div style="display:flex;gap:4px">
                  <?= $inp('real_manual', $l['valor_realizado_manual']) ?>
                  <input type="text" name="just_manual[<?= $oid ?>]" value="<?= h($l['justificativa_manual'] ?? '') ?>"
                         placeholder="justificativa *" style="width:150px">
                </div>
              <?php else: ?>
                <span class="vnum"><?= $fmtN($l['valor_realizado_manual']) ?></span>
                <?= $l['justificativa_manual'] ? '<div class="vhint">' . h((string)$l['justificativa_manual']) . '</div>' : '' ?>
              <?php endif; ?>
            <?php else: ?><span class="vhint">derivado do custeio</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if ($editavel): ?>
      <div class="vform-actions" style="padding:10px 14px">
        <button class="vbtn vbtn-primary" type="submit">Gravar valores</button>
      </div>
      <?php endif; ?>
    </form>
    <div class="vhint" style="padding:10px 14px">
      Previstos são editáveis apenas em RASCUNHO — após a aprovação, só o realizado manual muda;
      para revisar previstos, cancele e copie. Percentuais aplicam sobre a base calculada (grupo/total) — nunca sobre outro percentual.
      Realizado dos itens de origem CUSTEIO chega derivado na fase 2 (mapa por item);
      aqui só o item de origem MANUAL aceita realizado, com justificativa.
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
