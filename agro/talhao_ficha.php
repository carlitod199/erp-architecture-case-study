<?php
/* ============================================================
   VERO — Agrícola / Ficha da Válvula  (tela real, leitura)
   Rota: /agro/talhao_ficha.php?id=N
   Guard: agricola.talhoes (reusa agro.talhoes.ver — decisão P-37)
   Visão consolidada da válvula: resumo (KPIs), safras & produção
   (meta × colhido × custo × faturamento por vínculo) e custos
   acumulados por categoria e por safra. Somente leitura — os
   dados nascem nos módulos de origem. Linha do tempo completa
   de atividades fica para a fase posterior (tarefa A1-13, obs.).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/_setor_espelho.php'; /* A1-37: modo unificado (arbitragem A1-32) */

$t  = vero_tenant();
$id = (int)($_GET['id'] ?? 0);

$talhao = null;
if ($id > 0) {
    $talhao = vero_row(
        "SELECT t.*, f.nome AS fazenda_nome, f.municipio, f.uf,
                v.nome AS variedade_nome, pe.nome AS porta_enxerto_nome, c.nome AS cultura_nome,
                a.nome AS area_nome
           FROM agro_talhoes t
           JOIN agro_fazendas f ON f.id = t.fazenda_id
           LEFT JOIN agro_variedades v ON v.id = t.variedade_id
           LEFT JOIN agro_culturas  c ON c.id = v.cultura_id
           LEFT JOIN agro_porta_enxertos pe ON pe.id = t.porta_enxerto_id
           LEFT JOIN agro_areas     a ON a.id = t.area_id
          WHERE t.id = :i AND t.tenant_id = :t",
        [':i' => $id, ':t' => $t]
    );
}

/* D-03: id informado mas sem registro deste tenant (inexistente/ inválido)
   → estado "não encontrado" (HTTP 404). A rota de DETALHE nunca deve cair
   na listagem completa de todas as fazendas (rota de detalhe ≠ listagem). */
$notFound = ($id > 0 && !$talhao);
if ($notFound) {
    http_response_code(404);
}

/* Sem válvula selecionada (id ausente) → seletor simples */
$listaTalhoes = [];
if (!$talhao && !$notFound) {
    $listaTalhoes = vero_rows(
        "SELECT t.id, t.codigo, t.nome, t.area_ha, t.ativo, f.nome AS fazenda
           FROM agro_talhoes t
           JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE t.tenant_id = :t
          ORDER BY t.ativo DESC, f.nome, t.codigo",
        [':t' => $t]
    );
}

$kpi = null; $safras = []; $custosCategoria = []; $custosSafra = []; $mip = null;
if ($talhao) {
    /* ── KPIs do resumo ──
       (subqueries correlacionadas em t.* — placeholder nomeado não pode
        repetir com prepares nativos, padrão das demais telas) */
    $kpi = vero_row(
        "SELECT
            (SELECT COUNT(*) FROM agro_setores s
              WHERE s.tenant_id = t.tenant_id AND s.talhao_id = t.id AND s.ativo = 1)            AS valvulas,
            (SELECT COUNT(*) FROM agro_alertas al
              WHERE al.tenant_id = t.tenant_id AND al.talhao_id = t.id AND al.status = 'aberto') AS alertas,
            (SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
              WHERE cl.tenant_id = t.tenant_id AND cl.talhao_id = t.id)                          AS custo_total,
            (SELECT COALESCE(SUM(cr.kg_total_realizado),0) FROM colheita_registros cr
              WHERE cr.tenant_id = t.tenant_id AND cr.talhao_id = t.id)                          AS kg_total
           FROM agro_talhoes t
          WHERE t.id = :i AND t.tenant_id = :t",
        [':t' => $t, ':i' => $id]
    );

    /* ── Safras & produção (um bloco por vínculo safra×válvula) ── */
    $safras = vero_rows(
        "SELECT st.id, st.area_plantada_ha, st.produtividade_planejada, st.unidade_produtividade,
                s.identificacao AS safra, s.status AS safra_status, s.data_inicio,
                c.nome AS cultura,
                COALESCE((SELECT SUM(cr.kg_total_realizado) FROM colheita_registros cr
                  WHERE cr.tenant_id = st.tenant_id AND cr.safra_talhao_id = st.id), 0)   AS kg_realizado,
                COALESCE((SELECT SUM(cr.faturamento_realizado) FROM colheita_registros cr
                  WHERE cr.tenant_id = st.tenant_id AND cr.safra_talhao_id = st.id), 0)   AS faturamento,
                COALESCE((SELECT SUM(cl.valor) FROM custeio_lancamentos cl
                  WHERE cl.tenant_id = st.tenant_id AND cl.safra_talhao_id = st.id), 0)   AS custo
           FROM agro_safra_talhoes st
           JOIN agro_safras   s ON s.id = st.safra_id
           JOIN agro_culturas c ON c.id = st.cultura_id
          WHERE st.tenant_id = :t AND st.talhao_id = :i
          ORDER BY s.data_inicio DESC, s.identificacao DESC",
        [':t' => $t, ':i' => $id]
    );

    /* ── Custos por categoria (acumulado da válvula) ── */
    $custosCategoria = vero_rows(
        "SELECT COALESCE(cl.categoria,'outros') AS categoria, SUM(cl.valor) AS total
           FROM custeio_lancamentos cl
          WHERE cl.tenant_id = :t AND cl.talhao_id = :i
          GROUP BY COALESCE(cl.categoria,'outros')
          ORDER BY total DESC",
        [':t' => $t, ':i' => $id]
    );

    /* ── Custos por safra (custo/ha e custo/kg no PHP) ── */
    $custosSafra = vero_rows(
        "SELECT s.identificacao AS safra, SUM(cl.valor) AS total
           FROM custeio_lancamentos cl
           JOIN agro_safras s ON s.id = cl.safra_id
          WHERE cl.tenant_id = :t AND cl.talhao_id = :i
          GROUP BY s.id, s.identificacao
          ORDER BY s.data_inicio DESC",
        [':t' => $t, ':i' => $id]
    );
    $semSafra = (float)(vero_val(
        "SELECT COALESCE(SUM(cl.valor),0) FROM custeio_lancamentos cl
          WHERE cl.tenant_id = :t AND cl.talhao_id = :i AND cl.safra_id IS NULL",
        [':t' => $t, ':i' => $id]
    ) ?? 0);

    /* ── Fitossanitário (resumo — detalhe em mip/historico_talhao.php) ── */
    $mip = vero_row(
        "SELECT COUNT(*) AS leituras, MAX(m.data_monitoramento) AS ultima
           FROM mip_monitoramentos m
          WHERE m.tenant_id = :t AND m.talhao_id = :i",
        [':t' => $t, ':i' => $id]
    );

    /* ── Chuva (clima_registros): lançamentos da válvula ou da fazenda toda ── */
    $chuva = vero_row(
        "SELECT
            COALESCE(SUM(CASE WHEN c.data >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN c.chuva_mm END), 0) AS mm_30d,
            COALESCE(SUM(CASE WHEN YEAR(c.data) = YEAR(CURDATE()) THEN c.chuva_mm END), 0)                 AS mm_ano
           FROM clima_registros c
           JOIN agro_talhoes t ON t.tenant_id = c.tenant_id AND t.id = :i
          WHERE c.tenant_id = :t
            AND (c.talhao_id = t.id OR (c.talhao_id IS NULL AND c.fazenda_id = t.fazenda_id))",
        [':t' => $t, ':i' => $id]
    );
}

$GUARD      = ['macro' => 'agricola', 'micro' => 'talhoes'];
$PAGE_VIEW  = 'agricola_talhao_ficha';
$PAGE_TITLE = 'Ficha — ' . vero_a1_rotulo_area();
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$fmtMeta = static function (?string $v, ?string $u): string {
    if ($v === null) return '—';
    return numFmt((float)$v, 0) . ' ' . h(str_replace('_', '/', (string)$u));
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

  <?php if ($notFound): /* D-03: id informado que não existe / não é do tenant */ ?>
    <?= vero_page_header('Ficha — ' . vero_a1_rotulo_area() . ' não encontrada',
          'O registro solicitado não existe ou não pertence a esta conta', null) ?>
    <div class="vcard">
      <div class="vempty">
        <?= vero_a1_rotulo_area() ?> não encontrada. O identificador informado não corresponde a
        nenhuma válvula desta fazenda — verifique o link ou escolha uma válvula na lista.
        <div style="margin-top:12px">
          <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/agro/talhao_ficha">← Escolher uma válvula</a>
        </div>
      </div>
    </div>

  <?php elseif (!$talhao): ?>
    <?= vero_page_header('Ficha — ' . vero_a1_rotulo_area(),
          'Selecione para abrir a visão consolidada (produção, custos e safras)', null) ?>
    <div class="vcard">
      <?php if (!$listaTalhoes): ?>
        <div class="vempty">Nenhum válvula cadastrada. Cadastre em <a href="<?= BIOS_BASE ?>/agro/talhoes">Válvulas</a>.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr>
          <th>Fazenda</th><th>Válvula</th><th style="text-align:right">Área (ha)</th><th>Status</th><th style="text-align:right"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($listaTalhoes as $r): ?>
          <tr>
            <td><?= h($r['fazenda']) ?></td>
            <td><strong class="vnum"><?= h($r['codigo']) ?></strong> <?= $r['nome'] ? h((string)$r['nome']) : '' ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$r['area_ha'], 2) ?></td>
            <td><?= vero_b_ativo($r['ativo']) ?></td>
            <td style="text-align:right"><div class="vactions"><?= vero_btn_icone(vero_ico_olho(), 'Abrir ficha', '', '?id=' . (int)$r['id']) ?></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

  <?php else:
      $areaRef   = (float)$talhao['area_ha'];
      $custoT    = (float)$kpi['custo_total'];
      $kgT       = (float)$kpi['kg_total'];
      $tituloTal = trim($talhao['fazenda_nome'] . ' — ' . $talhao['codigo']);
  ?>
    <?= vero_page_header('Ficha — ' . vero_a1_rotulo_area() . ' ' . h($tituloTal),
          'Visão consolidada: cadastro, safras, produção e custos (somente leitura — os dados nascem nos módulos de origem)', null) ?>

    <div class="vtoolbar" style="padding:0 0 14px;gap:8px;flex-wrap:wrap">
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/agro/talhao_ficha">← Trocar válvula</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/agro/talhoes?q=<?= urlencode((string)$talhao['codigo']) ?>">Editar cadastro</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/agro/mapa">Ver no mapa<?= $talhao['geometria'] ? ' ✓' : '' ?></a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/custeio/custos">Custos completos</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= BIOS_BASE ?>/mip/historico_talhao?talhao=<?= (int)$talhao['id'] ?>">Histórico fitossanitário</a>
      <?= vero_b_ativo($talhao['ativo']) ?>
    </div>

    <!-- Resumo (KPIs) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Área cadastral</div>
        <strong class="vnum" style="font-size:20px"><?= numFmt($areaRef, 2) ?> ha</strong>
      </div>
      <?php if (!vero_a1_valvula_unificada()): /* A1-37: no 1:1 é sempre 1 — sem valor */ ?>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Válvulas ativas</div>
        <strong class="vnum" style="font-size:20px"><?= (int)$kpi['valvulas'] ?></strong>
      </div>
      <?php endif; ?>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Alertas abertos</div>
        <strong class="vnum" style="font-size:20px;<?= (int)$kpi['alertas'] > 0 ? 'color:#b3261e' : '' ?>"><?= (int)$kpi['alertas'] ?></strong>
      </div>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Custo acumulado</div>
        <strong class="vnum" style="font-size:20px;color:#005059">R$ <?= numFmt($custoT, 2) ?></strong>
      </div>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Produção colhida (total)</div>
        <strong class="vnum" style="font-size:20px"><?= numFmt($kgT, 0) ?> kg</strong>
      </div>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Custo médio</div>
        <strong class="vnum" style="font-size:20px">
          <?= $areaRef > 0 ? 'R$ ' . numFmt($custoT / $areaRef, 2) . '/ha' : '—' ?>
        </strong>
        <div class="vhint"><?= $kgT > 0 ? 'R$ ' . numFmt($custoT / $kgT, 2) . '/kg colhido' : 'sem colheita p/ custo/kg' ?></div>
      </div>
      <div class="vcard" style="padding:14px 16px">
        <div class="vhint">Chuva 30 dias</div>
        <strong class="vnum" style="font-size:20px"><?= numFmt((float)($chuva['mm_30d'] ?? 0), 1) ?> mm</strong>
        <div class="vhint"><?= numFmt((float)($chuva['mm_ano'] ?? 0), 1) ?> mm no ano</div>
      </div>
    </div>

    <!-- Dados técnicos (DB-15) -->
    <?php
      $idadeAnos = $talhao['data_plantio']
          ? (int)floor((time() - strtotime((string)$talhao['data_plantio'])) / 31557600) : null;
      $tecnicos = [
          'Cultura / variedade' => $talhao['variedade_nome']
              ? trim(($talhao['cultura_nome'] ? $talhao['cultura_nome'] . ' — ' : '') . $talhao['variedade_nome']) : null,
          'Porta-enxerto'  => $talhao['porta_enxerto_nome'] ?: null,
          'Área produtiva' => $talhao['area_nome'] ?: null,
          'Tipo de solo'   => $talhao['tipo_solo'] ?: null,
          'Plantio'        => $talhao['data_plantio']
              ? dateBR((string)$talhao['data_plantio']) . ($idadeAnos !== null ? " ({$idadeAnos} ano(s))" : '') : null,
          'Espaçamento'    => ($talhao['espacamento_linha_m'] !== null && $talhao['espacamento_planta_m'] !== null)
              ? numFmt((float)$talhao['espacamento_linha_m'], 2) . ' × ' . numFmt((float)$talhao['espacamento_planta_m'], 2) . ' m' : null,
          'Nº de plantas'  => $talhao['num_plantas'] !== null
              ? numFmt((float)$talhao['num_plantas'], 0)
                . ($areaRef > 0 ? ' (' . numFmt((float)$talhao['num_plantas'] / $areaRef, 0) . '/ha)' : '') : null,
          'Observações'    => $talhao['observacao'] ?: null,
      ];
      $tecnicos = array_filter($tecnicos, static fn($v) => $v !== null);
    ?>
    <div class="vcard" style="margin-bottom:16px;padding:14px 18px">
      <strong style="display:block;margin-bottom:10px">Dados técnicos</strong>
      <?php if (!$tecnicos): ?>
        <div class="vhint">Nenhum dado técnico informado — preencha no cadastro da válvula (solo, plantio, espaçamento, variedade).</div>
      <?php else: ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 16px">
        <?php foreach ($tecnicos as $rotulo => $valor): ?>
          <div><div class="vhint"><?= h((string)$rotulo) ?></div><strong><?= h((string)$valor) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Safras & produção -->
    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar" style="justify-content:space-between">
        <strong>Safras &amp; produção</strong>
        <span class="vsub"><?= count($safras) ?> vínculo(s)</span>
      </div>
      <?php if (!$safras): ?>
        <div class="vempty">Esta válvula ainda não foi vinculada a nenhuma safra. Vincule em <a href="<?= BIOS_BASE ?>/safras/index">Safras</a>.</div>
      <?php else: ?>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr>
          <th>Safra</th><th>Cultura</th>
          <th style="text-align:right">Área plantada (ha)</th>
          <th style="text-align:right">Meta</th>
          <th style="text-align:right">Colhido (kg)</th>
          <th style="text-align:right">kg/ha</th>
          <th style="text-align:right">Atingimento</th>
          <th style="text-align:right">Custo (R$)</th>
          <th style="text-align:right">Faturamento colheita (R$)</th>
        </tr></thead>
        <tbody>
        <?php foreach ($safras as $s):
            $areaV = (float)$s['area_plantada_ha'] > 0 ? (float)$s['area_plantada_ha'] : ($areaRef > 0 ? $areaRef : null);
            $kgha  = ($areaV !== null && $areaV > 0) ? (float)$s['kg_realizado'] / $areaV : null;
            /* atingimento só quando a meta está em kg/ha ou t/ha (conversão segura) */
            $metaKgHa = null;
            if ($s['produtividade_planejada'] !== null) {
                if ($s['unidade_produtividade'] === 'kg_ha')     $metaKgHa = (float)$s['produtividade_planejada'];
                elseif ($s['unidade_produtividade'] === 't_ha')  $metaKgHa = (float)$s['produtividade_planejada'] * 1000;
            }
            $pct = ($metaKgHa !== null && $metaKgHa > 0 && $kgha !== null) ? $kgha / $metaKgHa * 100 : null;
        ?>
          <tr>
            <td><strong class="vnum"><?= h($s['safra']) ?></strong>
                <span class="vhint"><?= h((string)$s['safra_status']) ?></span></td>
            <td><?= h($s['cultura']) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$s['area_plantada_ha'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= $fmtMeta($s['produtividade_planejada'], $s['unidade_produtividade']) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$s['kg_realizado'], 0) ?></td>
            <td class="vnum" style="text-align:right"><?= $kgha !== null ? numFmt($kgha, 0) : '—' ?></td>
            <td class="vnum" style="text-align:right;<?= $pct !== null ? ($pct >= 100 ? 'color:#1a7f4b' : ($pct < 80 ? 'color:#b3261e' : '')) : '' ?>">
              <?= $pct !== null ? numFmt($pct, 1) . '%' : '—' ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$s['custo'], 2) ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$s['faturamento'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="vhint" style="padding:10px 14px">
        Atingimento calculado apenas para metas em kg/ha ou t/ha. Faturamento da colheita = classificação previsto×realizado;
        o faturamento de vendas está em Custos → Resultado da Safra.
      </div>
      <?php endif; ?>
    </div>

    <!-- Custos -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(340px,100%),1fr));gap:16px;align-items:start">
      <div class="vcard">
        <div class="vtoolbar"><strong>Custos por categoria (acumulado)</strong></div>
        <?php if (!$custosCategoria): ?>
          <div class="vempty">Nenhum custo lançado para esta válvula.</div>
        <?php else: ?>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr><th>Categoria</th><th class="num">Total (R$)</th><th style="width:42%">Participação</th></tr></thead>
          <tbody>
          <?php foreach ($custosCategoria as $c): $p = $custoT > 0 ? (float)$c['total'] / $custoT * 100 : 0; ?>
            <tr>
              <td><?= h(ucfirst(str_replace('_', ' ', (string)$c['categoria']))) ?></td>
              <td class="num"><strong><?= numFmt((float)$c['total'], 2) ?></strong></td>
              <td><div style="display:flex;align-items:center;gap:10px">
                <div style="flex:1;height:8px;background:#EEE6D6;border-radius:4px;overflow:hidden"><div style="height:100%;width:<?= number_format($p, 1, '.', '') ?>%;background:#005059;border-radius:4px"></div></div>
                <span class="vnum" style="min-width:46px;text-align:right"><?= numFmt($p, 1) ?>%</span>
              </div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <div class="vhint" style="padding:8px 14px"><a href="<?= BIOS_BASE ?>/custeio/custos">Ver lançamentos</a></div>
        <?php endif; ?>
      </div>

      <div class="vcard">
        <div class="vtoolbar"><strong>Custos por safra</strong></div>
        <?php if (!$custosSafra && $semSafra <= 0): ?>
          <div class="vempty">Nenhum custo lançado para esta válvula.</div>
        <?php else: ?>
        <div class="vdata-wrap">
        <table class="vdata">
          <thead><tr><th>Safra</th><th class="num">Total (R$)</th><th class="num">R$/ha</th></tr></thead>
          <tbody>
          <?php foreach ($custosSafra as $c): ?>
            <tr>
              <td><strong class="vnum"><?= h($c['safra']) ?></strong></td>
              <td class="num"><?= numFmt((float)$c['total'], 2) ?></td>
              <td class="num"><?= $areaRef > 0 ? numFmt((float)$c['total'] / $areaRef, 2) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($semSafra > 0): ?>
            <tr>
              <td><span style="color:#8A6D1A;font-weight:600">Sem safra vinculada</span></td>
              <td class="num" style="color:#8A6D1A"><?= numFmt($semSafra, 2) ?></td>
              <td class="num">—</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
        </div>
        <div class="vhint" style="padding:10px 14px">
          R$/ha usa a área cadastral da válvula (<?= numFmt($areaRef, 2) ?> ha).
          Monitoramento MIP: <?= (int)($mip['leituras'] ?? 0) ?> leitura(s)<?=
            !empty($mip['ultima']) ? ', última em ' . dateBR((string)$mip['ultima']) : '' ?>.
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
