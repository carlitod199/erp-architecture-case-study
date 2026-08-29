<?php
/* ============================================================
   VERO — Máquinas / Visão Geral  (tela real, leitura)
   Substitui o mock. Rota: /maquinas/index.php (destino do menu)
   Guard: maquinas.maquinas
   A2-F2-7: painel da frota com KPIs reconciliáveis, CUSTO-HORA
   EFETIVO por máquina no ano = (combustível + manutenção executada
   + depreciação gerencial) ÷ horas trabalhadas (Δ horímetro no ano),
   comparado ao custo/hora informado; consumo médio L/h; alertas de
   manutenção preventiva (categoria maquinas); uso de máquina por
   atividade (agro_apontamento_maquinas — leitura, lado A2 da F2-9).
   Depreciação gerencial: tarifa/h = (aquisição − residual) ÷ vida
   útil — NÃO substitui a depreciação contábil do Patrimônio (A3).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();
$ano = (int)($_GET['ano'] ?? date('Y'));
if ($ano < 2000 || $ano > 2100) $ano = (int)date('Y');

/* ---- KPIs da frota ---- */
$frota = vero_row(
    "SELECT COUNT(*) AS total,
            SUM(status = 'ativa') AS ativas,
            SUM(status = 'manutencao') AS em_manutencao
       FROM maquinas WHERE tenant_id = :t AND ativo = 1", [':t' => $t]);
$veiculosN = (int)vero_val("SELECT COUNT(*) FROM veiculos WHERE tenant_id=:t AND ativo=1", [':t' => $t]);
$implementosN = (int)vero_val("SELECT COUNT(*) FROM implementos WHERE tenant_id=:t AND ativo=1", [':t' => $t]);
$alertasMaq = (int)vero_val(
    "SELECT COUNT(*) FROM agro_alertas WHERE tenant_id=:t AND categoria='maquinas' AND status='aberto'", [':t' => $t]);

/* ---- por máquina: combustível, manutenção, horas trabalhadas no ano ---- */
$maqs = vero_rows(
    "SELECT m.*, op.nome AS operador,
            (SELECT COALESCE(SUM(ab.valor_total),0) FROM maquina_abastecimentos ab
              WHERE ab.tenant_id = m.tenant_id AND ab.maquina_id = m.id
                AND YEAR(ab.data_abastecimento) = :a) AS combustivel,
            (SELECT COALESCE(SUM(ab.litros),0) FROM maquina_abastecimentos ab
              WHERE ab.tenant_id = m.tenant_id AND ab.maquina_id = m.id
                AND YEAR(ab.data_abastecimento) = :a2) AS litros,
            (SELECT COALESCE(SUM(mn.custo),0) FROM maquina_manutencoes mn
              WHERE mn.tenant_id = m.tenant_id AND mn.maquina_id = m.id
                AND mn.status = 'executada' AND YEAR(mn.data_manutencao) = :a3) AS manutencao,
            (SELECT MAX(h.horimetro) - MIN(h.horimetro) FROM maquina_horimetros h
              WHERE h.tenant_id = m.tenant_id AND h.maquina_id = m.id
                AND YEAR(h.data_leitura) = :a4) AS horas_ano
       FROM maquinas m
       LEFT JOIN agro_operadores op ON op.id = m.operador_padrao_id
      WHERE m.tenant_id = :t AND m.ativo = 1
      ORDER BY m.codigo", [':t' => $t, ':a' => $ano, ':a2' => $ano, ':a3' => $ano, ':a4' => $ano]);

$totComb = 0.0; $totManut = 0.0;
foreach ($maqs as $m) { $totComb += (float)$m['combustivel']; $totManut += (float)$m['manutencao']; }

/* ---- alertas abertos (categoria maquinas) ---- */
$alertas = vero_rows(
    "SELECT severidade, titulo, mensagem, data FROM agro_alertas
      WHERE tenant_id = :t AND categoria = 'maquinas' AND status = 'aberto'
      ORDER BY (severidade = 'critico') DESC, id DESC LIMIT 8", [':t' => $t]);

/* ---- uso de máquina por atividade (leitura — agro_apontamento_maquinas) ---- */
$usoAtividade = vero_rows(
    "SELECT am.horas, am.custo_hora, ap.data_apontamento, m.codigo AS maq_codigo, m.nome AS maq_nome,
            ta.nome AS atividade, tl.codigo AS talhao
       FROM agro_apontamento_maquinas am
       JOIN agro_apontamentos ap ON ap.id = am.apontamento_id
       JOIN maquinas m ON m.id = am.maquina_id
       LEFT JOIN agro_tipos_atividade ta ON ta.id = ap.tipo_atividade_id
       LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
      WHERE am.tenant_id = :t
      ORDER BY ap.data_apontamento DESC, am.id DESC LIMIT 8", [':t' => $t]);

/* P-75 (CSO): valores em R$ só com o proxy financeiro; sem ele, mascara (•••). */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;

$GUARD      = ['macro' => 'maquinas', 'micro' => 'maquinas'];
$PAGE_VIEW  = 'maquinas';
$PAGE_TITLE = 'Máquinas — Visão Geral';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Máquinas — Visão Geral',
        'Frota, custo-hora efetivo (combustível + manutenção + depreciação gerencial) e alertas de revisão', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 14px">
      <div class="vfield"><label>Ano</label>
        <select name="ano" onchange="this.form.submit()">
          <?php for ($a = (int)date('Y'); $a >= (int)date('Y') - 4; $a--): ?>
            <option value="<?= $a ?>"<?= $ano === $a ? ' selected' : '' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select></div>
      <span style="flex:1"></span>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/cadastro.php">Cadastro</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/abastecimento.php">Abastecimentos</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/manutencao.php">Manutenções</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/planos_manutencao.php">Planos</a>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/maquinas/disponibilidade_frota.php">Disponibilidade</a>
    </form>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;padding:12px 14px">
      <div class="vkpi"><span class="vhint">Frota</span>
        <strong class="vnum" style="font-size:1.25rem"><?= (int)$frota['total'] ?></strong>
        <span class="vhint"><?= (int)$frota['ativas'] ?> ativa(s) · <?= $veiculosN ?> veíc. · <?= $implementosN ?> impl.</span></div>
      <div class="vkpi"><span class="vhint">Em manutenção</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= (int)$frota['em_manutencao'] ? '#b3261e' : 'inherit' ?>">
          <?= (int)$frota['em_manutencao'] ?></strong></div>
      <div class="vkpi"><span class="vhint">Combustível (<?= $ano ?>)</span>
        <strong class="vnum" style="font-size:1.25rem">R$ <?= $veCusto ? numFmt($totComb, 2) : '•••' ?></strong></div>
      <div class="vkpi"><span class="vhint">Manutenção (<?= $ano ?>)</span>
        <strong class="vnum" style="font-size:1.25rem">R$ <?= $veCusto ? numFmt($totManut, 2) : '•••' ?></strong></div>
      <div class="vkpi"><span class="vhint">Alertas de revisão</span>
        <strong class="vnum" style="font-size:1.25rem;color:<?= $alertasMaq ? '#b3261e' : 'inherit' ?>"><?= $alertasMaq ?></strong>
        <span class="vhint">planos preventivos</span></div>
    </div>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Custo-hora efetivo por máquina (<?= $ano ?>)</strong>
      <span class="vsub">(combustível + manutenção + depreciação gerencial) ÷ Δ horímetro no ano</span></div>
    <?php if (!$maqs): ?>
      <div class="vempty">Nenhuma máquina ativa.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Máquina</th><th>Operador padrão</th>
        <th style="text-align:right">Horas (<?= $ano ?>)</th>
        <th style="text-align:right">Combustível</th>
        <th style="text-align:right">L/h</th>
        <th style="text-align:right">Manutenção</th>
        <th style="text-align:right">Depreciação/h</th>
        <th style="text-align:right">Custo-hora efetivo</th>
        <th style="text-align:right">Informado</th>
      </tr></thead>
      <tbody>
      <?php foreach ($maqs as $m):
          $horas = $m['horas_ano'] !== null ? (float)$m['horas_ano'] : 0.0;
          $deprH = null;
          if ($m['valor_aquisicao'] !== null && $m['vida_util_horas'] !== null && (float)$m['vida_util_horas'] > 0) {
              $deprH = ((float)$m['valor_aquisicao'] - (float)($m['valor_residual'] ?? 0)) / (float)$m['vida_util_horas'];
          }
          $lh = $horas > 0 && (float)$m['litros'] > 0 ? (float)$m['litros'] / $horas : null;
          $chEfetivo = $horas > 0
              ? ((float)$m['combustivel'] + (float)$m['manutencao']) / $horas + ($deprH ?? 0.0)
              : null;
      ?>
        <tr>
          <td><strong><?= h($m['codigo'] . ' — ' . $m['nome']) ?></strong>
            <div class="vhint"><?= h(ucfirst((string)$m['tipo'])) ?> · horímetro <?= numFmt((float)$m['horimetro_atual'], 1) ?> h</div></td>
          <td><?= h($m['operador'] ?? '—') ?></td>
          <td class="vnum" style="text-align:right"><?= $horas > 0 ? numFmt($horas, 1) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$m['combustivel'], 2) : '•••' ?>
            <span class="vhint"><?= numFmt((float)$m['litros'], 0) ?> L</span></td>
          <td class="vnum" style="text-align:right"><?= $lh !== null ? numFmt($lh, 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$m['manutencao'], 2) : '•••' ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ($deprH !== null ? numFmt($deprH, 2) : '<span class="vhint">sem dados</span>') ?></td>
          <td class="vnum" style="text-align:right"><strong><?= !$veCusto ? '•••' : ($chEfetivo !== null ? 'R$ ' . numFmt($chEfetivo, 2) : '—') ?></strong>
            <?= $veCusto && $chEfetivo === null && ($deprH !== null || (float)$m['combustivel'] > 0)
                ? '<div class="vhint">sem Δ horímetro no ano</div>' : '' ?></td>
          <td class="vnum" style="text-align:right"><?= !$veCusto ? '•••' : ((float)$m['custo_hora'] > 0 ? numFmt((float)$m['custo_hora'], 2) : '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:8px 14px">Horas do ano = maior − menor leitura de horímetro no período (registre leituras para melhorar a precisão). Depreciação gerencial não substitui a contábil do Patrimônio.</div>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px">
    <div class="vcard">
      <div class="vtoolbar"><strong>Alertas de manutenção preventiva</strong>
        <span class="vsub">categoria máquinas</span></div>
      <?php if (!$alertas): ?>
        <div class="vempty">Nenhum alerta aberto — planos em dia.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th></th><th>Alerta</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($alertas as $al): ?>
          <tr>
            <td><?= $al['severidade'] === 'critico'
                  ? '<span class="vbadge vb-off">crítico</span>' : '<span class="vbadge vb-warn">atenção</span>' ?></td>
            <td><strong><?= h($al['titulo']) ?></strong>
              <div class="vhint"><?= h(mb_substr((string)$al['mensagem'], 0, 90)) ?></div></td>
            <td class="vnum"><?= date('d/m/Y', strtotime((string)$al['data'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="vcard">
      <div class="vtoolbar"><strong>Uso de máquina por atividade</strong>
        <span class="vsub">apontamentos de campo</span></div>
      <?php if (!$usoAtividade): ?>
        <div class="vempty">Nenhum uso registrado — o bloco "Máquinas" do apontamento de campo alimenta este painel.</div>
      <?php else: ?>
      <table class="vtable">
        <thead><tr><th>Data</th><th>Máquina</th><th>Atividade</th><th>Válvula</th>
          <th style="text-align:right">Horas</th><th style="text-align:right">Custo (R$)</th></tr></thead>
        <tbody>
        <?php foreach ($usoAtividade as $u): ?>
          <tr>
            <td class="vnum"><?= date('d/m/Y', strtotime((string)$u['data_apontamento'])) ?></td>
            <td><strong class="vnum"><?= h($u['maq_codigo']) ?></strong> <?= h($u['maq_nome']) ?></td>
            <td><?= h($u['atividade'] ?? '—') ?></td>
            <td class="vnum"><?= h($u['talhao'] ?? '—') ?></td>
            <td class="vnum" style="text-align:right"><?= numFmt((float)$u['horas'], 1) ?></td>
            <td class="vnum" style="text-align:right"><?= $veCusto ? numFmt((float)$u['horas'] * (float)($u['custo_hora'] ?? 0), 2) : '•••' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
