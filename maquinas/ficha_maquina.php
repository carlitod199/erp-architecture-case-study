<?php
/* ============================================================
   VERO — Máquinas / Ficha única da máquina  (tela real, LEITURA)
   Rota: /maquinas/ficha_maquina.php?id=<maquina>
   Guard: maquinas.maquinas (ver a frota — read-only; sem slug/rota
   novos = sem re-seed, mesmo padrão de agro/talhao_ficha (P-37)).
   Implementa VERO_MAQ_FICHA_METRICAS.md (arbitragem A0, 08/07):
   disponibilidade (semáforo 1a + frota 1b + preventivas 1c) e custo
   operacional/cheio por período (§2), sem gerar custeio nem baixar
   estoque (CSO: fora do escopo protegido — só SELECT).

   CONVENÇÃO DO CUSTO DE MANUTENÇÃO (A2 confirma p/ A0 — §2 "serviço+peças
   vs header único"): no cadastro real (manutencao.php), ao EXECUTAR a OS
   o header `maquina_manutencoes.custo` é SOBRESCRITO com a soma dos itens
   (peças ao custo médio do estoque + serviços — manut_baixar_pecas); sem
   itens, vale o custo manual. Logo o header JÁ INCLUI peças+serviços →
   usa-se **SÓ O HEADER** (somar itens de novo = dupla contagem). É o mesmo
   valor lançado no custeio (origem `maquina_manutencao`, categoria
   `maquinas`) e a mesma fórmula de maquinas/custo.php. O detalhamento
   peças×serviços aparece por OS (informativo), sem entrar no total 2×.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

$t = vero_tenant();

const FICHA_TIPOS = [
    'trator' => 'Trator', 'colheitadeira' => 'Colheitadeira', 'pulverizador' => 'Pulverizador',
    'implemento' => 'Implemento', 'veiculo' => 'Veículo',
    'estercadeira' => 'Estercadeira', 'rocadeira' => 'Roçadeira', 'bandejao' => 'Bandejão', /* C-14, mig 174 */
    'outro' => 'Outro',
];
/* semáforo de disponibilidade (métrica 1a) */
const FICHA_ESTADO = [
    'disponivel' => ['🟢 Disponível', 'vb-ok'],
    'manutencao' => ['🟡 Em manutenção', 'vb-warn'],
    'inativa'    => ['⚪ Inativa', 'vb-off'],
];
/* estado calculado de UMA máquina (regra 1a do spec). */
function ficha_estado(array $m): string
{
    if ((int)$m['ativo'] !== 1 || (string)$m['status'] === 'inativa') return 'inativa';
    /* 🟡 se status=manutencao OU existe QUALQUER OS aberta (prev/corr) */
    if ((string)$m['status'] === 'manutencao' || (int)($m['os_abertas'] ?? 0) > 0) return 'manutencao';
    return 'disponivel';
}

/* período [ini,fim] (§2) — default: ano corrente até hoje */
$fIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-01-01');
$fFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-d');
if ($fFim < $fIni) [$fIni, $fFim] = [$fFim, $fIni];

/* A1-56 (apontamento_id no abastecimento) ainda depende de migration — feature-detection */
$abTemApontamento = vero_row("SHOW COLUMNS FROM maquina_abastecimentos LIKE 'apontamento_id'") !== null;

$maquinaId = (int)($_GET['id'] ?? 0);
$maquina = $maquinaId ? vero_row(
    "SELECT m.*, f.nome AS fazenda, op.nome AS operador,
            (SELECT COUNT(*) FROM maquina_manutencoes mo
              WHERE mo.tenant_id = m.tenant_id AND mo.maquina_id = m.id AND mo.status = 'aberta') AS os_abertas,
            (SELECT COUNT(*) FROM maquina_manutencoes mc
              WHERE mc.tenant_id = m.tenant_id AND mc.maquina_id = m.id AND mc.tipo = 'corretiva' AND mc.status = 'aberta') AS corretivas_abertas
       FROM maquinas m
       LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
       LEFT JOIN agro_operadores op ON op.id = m.operador_padrao_id
      WHERE m.id = :i AND m.tenant_id = :t", [':i' => $maquinaId, ':t' => $t]) : null;

/* ══════════ disponibilidade da FROTA (1b) — contexto (ficha + hub) ══════════ */
$frota = vero_rows(
    "SELECT m.id, m.codigo, m.nome, m.tipo, m.marca, m.modelo, m.status, m.ativo, m.horimetro_atual,
            f.nome AS fazenda,
            (SELECT COUNT(*) FROM maquina_manutencoes mo
              WHERE mo.tenant_id = m.tenant_id AND mo.maquina_id = m.id AND mo.status = 'aberta') AS os_abertas
       FROM maquinas m
       LEFT JOIN agro_fazendas f ON f.id = m.fazenda_id
      WHERE m.tenant_id = :t AND m.ativo = 1
      ORDER BY FIELD(m.status,'manutencao','ativa','inativa'), m.codigo", [':t' => $t]);
$frotaOperacional = 0; $frotaDisponiveis = 0;
foreach ($frota as &$fr) {
    $fr['estado'] = ficha_estado($fr);
    if ($fr['estado'] !== 'inativa') { $frotaOperacional++; if ($fr['estado'] === 'disponivel') $frotaDisponiveis++; }
}
unset($fr);
$dispFrota = $frotaOperacional > 0 ? $frotaDisponiveis / $frotaOperacional * 100 : 0;

/* ══════════════════ FICHA (uma máquina) — cálculos §2 ══════════════════ */
if ($maquina) {
    $pIni = $fIni . ' 00:00:00';
    $pFim = $fFim . ' 23:59:59';

    /* combustível + litros no período */
    $ab = vero_row(
        "SELECT COUNT(*) AS n, COALESCE(SUM(litros),0) AS litros, COALESCE(SUM(valor_total),0) AS valor
           FROM maquina_abastecimentos
          WHERE tenant_id = :t AND maquina_id = :m AND data_abastecimento BETWEEN :i AND :f",
        [':t' => $t, ':m' => $maquinaId, ':i' => $pIni, ':f' => $pFim]);
    $combustivel = (float)$ab['valor'];
    $litros      = (float)$ab['litros'];

    /* manutenção = SÓ o header das OS executadas no período (convenção confirmada) */
    $custoManut = (float)vero_val(
        "SELECT COALESCE(SUM(custo),0) FROM maquina_manutencoes
          WHERE tenant_id = :t AND maquina_id = :m AND status = 'executada'
            AND data_manutencao BETWEEN :i AND :f",
        [':t' => $t, ':m' => $maquinaId, ':i' => $fIni, ':f' => $fFim]);

    /* horas_periodo = Δ horímetro (max−min das leituras no período); fallback = Σ horas apontadas */
    $hp = vero_row(
        "SELECT COUNT(*) AS n, MIN(horimetro) AS mn, MAX(horimetro) AS mx FROM maquina_horimetros
          WHERE tenant_id = :t AND maquina_id = :m AND data_leitura BETWEEN :i AND :f",
        [':t' => $t, ':m' => $maquinaId, ':i' => $fIni, ':f' => $fFim]);
    $horasPeriodo = ((int)$hp['n'] >= 2 && (float)$hp['mx'] > (float)$hp['mn']) ? (float)$hp['mx'] - (float)$hp['mn'] : 0.0;
    $horasFonte   = $horasPeriodo > 0 ? 'Δ horímetro' : '';
    $usoAno = vero_row(
        "SELECT COALESCE(SUM(am.horas),0) AS horas, COALESCE(SUM(am.horas * COALESCE(am.custo_hora,0)),0) AS custo
           FROM agro_apontamento_maquinas am
           JOIN agro_apontamentos ap ON ap.id = am.apontamento_id
          WHERE am.tenant_id = :t AND am.maquina_id = :m AND ap.data_apontamento BETWEEN :i AND :f",
        [':t' => $t, ':m' => $maquinaId, ':i' => $pIni, ':f' => $pFim]);
    $horasApontadas = (float)$usoAno['horas'];
    if ($horasPeriodo <= 0 && $horasApontadas > 0) { $horasPeriodo = $horasApontadas; $horasFonte = 'horas apontadas'; }

    /* depreciação gerencial (informativa; não vira custeio) */
    $depHora = ((float)$maquina['vida_util_horas'] > 0 && $maquina['valor_aquisicao'] !== null)
        ? max(0.0, ((float)$maquina['valor_aquisicao'] - (float)($maquina['valor_residual'] ?? 0)) / (float)$maquina['vida_util_horas'])
        : 0.0;
    $depreciacao = $depHora * $horasPeriodo;

    $custoOperacional = $combustivel + $custoManut;          // "de bolso"
    $custoCheio       = $custoOperacional + $depreciacao;     // + depreciação
    $custoHoraReal    = $horasPeriodo > 0 ? $custoCheio / $horasPeriodo : null;
    $custoHoraParam   = (float)$maquina['custo_hora'];
    $consumoLh        = $horasPeriodo > 0 ? $litros / $horasPeriodo : null;
    $desvio           = ($custoHoraReal !== null && $custoHoraParam > 0) ? $custoHoraReal - $custoHoraParam : null;

    $odoAtual = vero_row(
        "SELECT odometro, data_leitura FROM maquina_odometros
          WHERE tenant_id = :t AND maquina_id = :m ORDER BY data_leitura DESC, id DESC LIMIT 1",
        [':t' => $t, ':m' => $maquinaId]);

    /* preventivas vencidas / a vencer (1c) */
    $planos = vero_rows(
        "SELECT * FROM maquina_planos_manutencao
          WHERE tenant_id = :t AND maquina_id = :m AND ativo = 1 ORDER BY descricao",
        [':t' => $t, ':m' => $maquinaId]);
    $hoje = new DateTimeImmutable(date('Y-m-d'));
    $horimetroAtual = (float)$maquina['horimetro_atual'];
    foreach ($planos as &$pl) {
        $pl['sinal'] = 'ok'; $pl['msgs'] = [];
        if ((float)$pl['intervalo_horas'] > 0 && $pl['horimetro_ultima'] !== null) {
            $prox = (float)$pl['horimetro_ultima'] + (float)$pl['intervalo_horas'];
            $faltam = $prox - $horimetroAtual; $ant = (float)($pl['antecedencia_horas'] ?? 0);
            $pl['msgs'][] = $faltam <= 0
                ? 'VENCIDA há ' . numFmt(abs($faltam), 0) . ' h (prevista em ' . numFmt($prox, 0) . ' h)'
                : 'faltam ' . numFmt($faltam, 0) . ' h (em ' . numFmt($prox, 0) . ' h)';
            if ($faltam <= 0) $pl['sinal'] = 'vencido';
            elseif ($ant > 0 && $faltam <= $ant && $pl['sinal'] !== 'vencido') $pl['sinal'] = 'proximo';
        }
        if ((int)$pl['intervalo_dias'] > 0 && $pl['data_ultima'] !== null) {
            $proxD = (new DateTimeImmutable((string)$pl['data_ultima']))->modify('+' . (int)$pl['intervalo_dias'] . ' days');
            $faltamD = (int)$hoje->diff($proxD)->format('%r%a'); $antD = (int)($pl['antecedencia_dias'] ?? 0);
            $pl['msgs'][] = $faltamD < 0
                ? 'VENCIDA há ' . abs($faltamD) . ' dia(s) (' . $proxD->format('d/m/Y') . ')'
                : 'em ' . $faltamD . ' dia(s) (' . $proxD->format('d/m/Y') . ')';
            if ($faltamD < 0) $pl['sinal'] = 'vencido';
            elseif ($antD > 0 && $faltamD <= $antD && $pl['sinal'] !== 'vencido') $pl['sinal'] = 'proximo';
        }
    }
    unset($pl);

    /* medidores no período (§3) */
    $horimetros = vero_rows(
        "SELECT data_leitura, horimetro FROM maquina_horimetros
          WHERE tenant_id = :t AND maquina_id = :m AND data_leitura BETWEEN :i AND :f
          ORDER BY data_leitura DESC, id DESC LIMIT 20", [':t' => $t, ':m' => $maquinaId, ':i' => $fIni, ':f' => $fFim]);
    $odometros = vero_rows(
        "SELECT data_leitura, odometro FROM maquina_odometros
          WHERE tenant_id = :t AND maquina_id = :m AND data_leitura BETWEEN :i AND :f
          ORDER BY data_leitura DESC, id DESC LIMIT 20", [':t' => $t, ':m' => $maquinaId, ':i' => $fIni, ':f' => $fFim]);

    /* abastecimentos no período (§4) — com vínculo A1-56 quando a coluna existir */
    $selAp = $abTemApontamento ? ', apontamento_id' : '';
    $abRecentes = vero_rows(
        "SELECT data_abastecimento, litros, valor_total, horimetro{$selAp}
           FROM maquina_abastecimentos WHERE tenant_id = :t AND maquina_id = :m
            AND data_abastecimento BETWEEN :i AND :f
          ORDER BY data_abastecimento DESC, id DESC LIMIT 20", [':t' => $t, ':m' => $maquinaId, ':i' => $pIni, ':f' => $pFim]);

    /* manutenções no período (§5) — header + detalhamento peças×serviços (informativo) */
    $osRecentes = vero_rows(
        "SELECT mn.data_manutencao, mn.tipo, mn.descricao, mn.custo, mn.status, mn.horimetro, pl.descricao AS plano,
                (SELECT COALESCE(SUM(mi.valor_total),0) FROM maquina_manutencao_itens mi
                   WHERE mi.tenant_id = mn.tenant_id AND mi.manutencao_id = mn.id AND mi.produto_id IS NOT NULL) AS pecas,
                (SELECT COALESCE(SUM(mi.valor_total),0) FROM maquina_manutencao_itens mi
                   WHERE mi.tenant_id = mn.tenant_id AND mi.manutencao_id = mn.id AND mi.produto_id IS NULL) AS servicos
           FROM maquina_manutencoes mn
           LEFT JOIN maquina_planos_manutencao pl ON pl.id = mn.plano_id
          WHERE mn.tenant_id = :t AND mn.maquina_id = :m AND mn.data_manutencao BETWEEN :i AND :f
          ORDER BY mn.data_manutencao DESC, mn.id DESC LIMIT 20", [':t' => $t, ':m' => $maquinaId, ':i' => $fIni, ':f' => $fFim]);

    $usoRecente = vero_rows(
        "SELECT ap.data_apontamento, am.horas, am.custo_hora, tl.nome AS talhao, ta.nome AS atividade
           FROM agro_apontamento_maquinas am
           JOIN agro_apontamentos ap ON ap.id = am.apontamento_id
           LEFT JOIN agro_talhoes tl ON tl.id = ap.talhao_id
           LEFT JOIN agro_tipos_atividade ta ON ta.id = ap.tipo_atividade_id
          WHERE am.tenant_id = :t AND am.maquina_id = :m AND ap.data_apontamento BETWEEN :i AND :f
          ORDER BY ap.data_apontamento DESC, am.id DESC LIMIT 20", [':t' => $t, ':m' => $maquinaId, ':i' => $pIni, ':f' => $pFim]);
}

$GUARD      = ['macro' => 'maquinas', 'micro' => 'maquinas'];
$PAGE_VIEW  = 'maquinas_maquinas';
$PAGE_TITLE = $maquina ? ('Ficha — ' . $maquina['codigo']) : 'Ficha da Máquina';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$base = rtrim(BIOS_BASE, '/');
/* P-75 (CSO, decisão A0): valores em R$ só p/ quem tem o proxy financeiro.
   Sem ele, a ficha mostra toda a operação (horímetro/litros/horas/consumo/
   disponibilidade) e MASCARA só o dinheiro (•••) — mesmo padrão da Auditoria (F2-17). */
$veCusto = function_exists('vero_can') ? vero_can('financeiro.dre_agro.ver') : true;
$badgeEstado = static fn(string $e): string => '<span class="vbadge ' . (FICHA_ESTADO[$e][1] ?? 'vb-info') . '">' . h(FICHA_ESTADO[$e][0] ?? $e) . '</span>';
/* querystring do período p/ os links internos */
$qsPer = 'ini=' . h($fIni) . '&fim=' . h($fFim);
?>
<style>
.fi-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;margin-bottom:14px}
.fi-kpi{background:#fff;border:1px solid #E7E0D2;border-radius:12px;padding:13px 15px;box-shadow:0 1px 3px rgba(43,32,24,.05)}
.fi-kpi .l{font:600 10.5px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.05em;color:#8A7D6E}
.fi-kpi .v{font:700 1.28rem 'IBM Plex Mono',monospace;color:#1E1610;margin-top:3px}
.fi-kpi .s{font-size:11px;color:#8A7D6E;margin-top:2px}
.fi-kpi--warn .v{color:#8A6D1A}.fi-kpi--alert .v{color:#9A3B2A}.fi-kpi--ok .v{color:#1E6B34}
.fi-head{display:flex;flex-wrap:wrap;gap:10px 22px;align-items:center;padding:2px 2px 12px}
.fi-head .id{font:700 1.15rem 'IBM Plex Sans';color:#241B14}
.fi-head .meta{font-size:12.5px;color:#6B5F53}
.fi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(360px,100%),1fr));gap:14px}
.fi-sec{background:#fff;border:1px solid #E7E0D2;border-radius:12px;overflow:hidden}
.fi-sec>h3{font:600 12px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.04em;color:#6B5F53;background:#F5F1E8;margin:0;padding:9px 13px;border-bottom:1px solid #E1D9C7}
.fi-sec>h3 a{font-weight:400;text-transform:none;letter-spacing:0}
.fi-sec table{width:100%;border-collapse:collapse}
.fi-sec td,.fi-sec th{padding:7px 13px;border-bottom:1px solid #F0EBDF;font-size:12.5px;text-align:left}
.fi-sec th{font:600 11px 'IBM Plex Sans';color:#8A7D6E;text-transform:uppercase;letter-spacing:.03em}
.fi-empty{padding:11px 13px;color:#8A7D6E;font-size:12.5px}
.fi-plan{padding:9px 13px;border-bottom:1px solid #F0EBDF;font-size:12.5px}
.fi-plan .d{font-weight:600;color:#3A342A}.fi-plan .m{color:#6B5F53;margin-top:2px}
.fi-plan.vencido{box-shadow:inset 3px 0 0 #C2410C}.fi-plan.proximo{box-shadow:inset 3px 0 0 #B8860B}
.mf-table{width:100%;border-collapse:collapse}
.mf-table thead th{background:#F5F1E8;font:600 11px 'IBM Plex Sans';text-transform:uppercase;letter-spacing:.03em;color:#6B5F53;border-bottom:2px solid #E1D9C7;padding:9px 11px;text-align:left;white-space:nowrap}
.mf-table tbody td{padding:8px 11px;border-bottom:1px solid #F0EBDF}
.mf-table tbody tr:hover{background:#F4F1E8}
.rnum{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.fi-per{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$maquina): /* ═══════════ HUB / landing: disponibilidade da frota ═══════════ */
  if ($maquinaId) echo '<div class="vflash vflash-erro">Máquina não encontrada.</div>'; ?>
  <?= vero_page_header('Ficha da Máquina', 'Escolha uma máquina para a ficha completa — disponibilidade, medidores, abastecimentos, manutenção e custo por período', null) ?>

  <div class="fi-cards">
    <div class="fi-kpi<?= $dispFrota < 80 ? ' fi-kpi--alert' : ' fi-kpi--ok' ?>"><div class="l">Disponibilidade da frota</div><div class="v"><?= numFmt($dispFrota, 0) ?>%</div><div class="s"><?= $frotaDisponiveis ?> de <?= $frotaOperacional ?> operacionais agora</div></div>
    <div class="fi-kpi fi-kpi--ok"><div class="l">Disponíveis</div><div class="v"><?= $frotaDisponiveis ?></div></div>
    <div class="fi-kpi<?= ($frotaOperacional - $frotaDisponiveis) > 0 ? ' fi-kpi--warn' : '' ?>"><div class="l">Em manutenção</div><div class="v"><?= $frotaOperacional - $frotaDisponiveis ?></div></div>
    <div class="fi-kpi"><div class="l">Frota operacional</div><div class="v"><?= $frotaOperacional ?></div><div class="s">exclui inativas</div></div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Frota</strong><span class="vsub"><?= count($frota) ?> máquina(s)</span></div>
    <?php if (!$frota): ?>
      <div class="vempty">Nenhuma máquina cadastrada. Cadastre em <a href="<?= $base ?>/maquinas/cadastro.php">Máquinas</a>.</div>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="mf-table">
      <thead><tr><th>Código</th><th>Máquina</th><th>Tipo</th><th>Fazenda</th><th>Disponibilidade</th>
        <th class="rnum">Horímetro (h)</th><th class="rnum">OS abertas</th><th class="rnum"></th></tr></thead>
      <tbody>
      <?php foreach ($frota as $r): ?>
        <tr>
          <td><strong class="vnum"><?= h($r['codigo']) ?></strong></td>
          <td><strong><?= h($r['nome']) ?></strong><?= $r['marca'] || $r['modelo'] ? '<div class="vhint">' . h(trim(($r['marca'] ?? '') . ' ' . ($r['modelo'] ?? ''))) . '</div>' : '' ?></td>
          <td><span class="vbadge vb-info"><?= h(FICHA_TIPOS[$r['tipo']] ?? $r['tipo']) ?></span></td>
          <td><?= h($r['fazenda'] ?? '') ?: '—' ?></td>
          <td><?= $badgeEstado($r['estado']) ?></td>
          <td class="rnum"><?= numFmt((float)$r['horimetro_atual'], 1) ?></td>
          <td class="rnum"<?= (int)$r['os_abertas'] > 0 ? ' style="color:#b3261e;font-weight:700"' : '' ?>><?= (int)$r['os_abertas'] ?></td>
          <td class="rnum"><a class="vbtn vbtn-ghost vbtn-sm" href="?id=<?= (int)$r['id'] ?>&<?= $qsPer ?>">Abrir ficha →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

<?php else: /* ═══════════ FICHA de uma máquina ═══════════ */
  $estado = ficha_estado($maquina); ?>
  <div style="margin-bottom:10px"><a class="vbtn vbtn-ghost vbtn-sm" href="ficha_maquina.php?<?= $qsPer ?>">← Frota</a></div>
  <?= vero_page_header($maquina['codigo'] . ' — ' . $maquina['nome'], 'Ficha consolidada da máquina (leitura)', null) ?>

  <!-- §1 Cabeçalho + semáforo + medidores atuais -->
  <div class="vcard" style="margin-bottom:14px"><div style="padding:12px 14px">
    <div class="fi-head">
      <span class="id"><?= h($maquina['codigo'] . ' — ' . $maquina['nome']) ?></span>
      <span class="vbadge vb-info"><?= h(FICHA_TIPOS[$maquina['tipo']] ?? $maquina['tipo']) ?></span>
      <?= $badgeEstado($estado) ?>
      <?php if ((int)$maquina['corretivas_abertas'] > 0): ?><span class="vbadge vb-off"><?= (int)$maquina['corretivas_abertas'] ?> corretiva(s) aberta(s)</span><?php endif; ?>
      <span class="meta"><?= h(trim(($maquina['marca'] ?? '') . ' ' . ($maquina['modelo'] ?? '') . ' ' . ($maquina['ano'] ?? ''))) ?: '—' ?></span>
      <span class="meta">Fazenda: <strong><?= h($maquina['fazenda'] ?? '—') ?></strong></span>
      <span class="meta">Operador: <strong><?= h($maquina['operador'] ?? '—') ?></strong></span>
      <span class="meta">Horímetro: <strong><?= numFmt($horimetroAtual, 1) ?> h</strong></span>
      <?php if ($odoAtual): ?><span class="meta">Odômetro: <strong><?= numFmt((float)$odoAtual['odometro'], 0) ?> km</strong></span><?php endif; ?>
    </div>
    <form method="get" class="fi-per">
      <input type="hidden" name="id" value="<?= $maquinaId ?>">
      <label class="vhint">Período</label>
      <input type="date" name="ini" value="<?= h($fIni) ?>" onchange="this.form.submit()">
      <input type="date" name="fim" value="<?= h($fFim) ?>" onchange="this.form.submit()">
      <span class="vsub">Δ horímetro no período: <strong><?= $horasPeriodo > 0 ? numFmt($horasPeriodo, 1) . ' h' : '—' ?></strong><?= $horasFonte ? ' (' . $horasFonte . ')' : '' ?></span>
    </form>
  </div></div>

  <!-- §6 Custo operacional / cheio (P-75: valores em R$ só com o proxy financeiro) -->
  <div class="fi-cards">
    <div class="fi-kpi"><div class="l">Custo operacional</div><div class="v"><?= $veCusto ? numFmt($custoOperacional, 2) : '•••' ?></div><div class="s"><?= $veCusto ? 'comb. ' . numFmt($combustivel, 0) . ' + manut. ' . numFmt($custoManut, 0) : 'restrito' ?></div></div>
    <div class="fi-kpi"><div class="l">Custo cheio</div><div class="v"><?= $veCusto ? numFmt($custoCheio, 2) : '•••' ?></div><div class="s"><?= $veCusto ? '+ deprec. ' . numFmt($depreciacao, 0) . ($depHora > 0 ? ' (R$ ' . numFmt($depHora, 2) . '/h)' : '') : 'restrito' ?></div></div>
    <div class="fi-kpi"><div class="l">Custo/hora realizado</div><div class="v"><?= !$veCusto ? '•••' : ($custoHoraReal !== null ? 'R$ ' . numFmt($custoHoraReal, 2) : '—') ?></div><div class="s"><?= $horasPeriodo > 0 ? 'cheio ÷ ' . numFmt($horasPeriodo, 1) . ' h' : '—' ?></div></div>
    <div class="fi-kpi<?= ($veCusto && $desvio !== null) ? ($desvio > 0 ? ' fi-kpi--alert' : ' fi-kpi--ok') : '' ?>"><div class="l">Desvio vs parametrizado</div><div class="v"><?= !$veCusto ? '•••' : ($desvio !== null ? ($desvio >= 0 ? '+' : '−') . 'R$ ' . numFmt(abs($desvio), 2) : '—') ?></div><div class="s"><?= $veCusto ? ('parâmetro R$ ' . numFmt($custoHoraParam, 2) . '/h' . ($desvio !== null ? ($desvio > 0 ? ' · acima (caro)' : ' · abaixo (econômico)') : '')) : 'restrito' ?></div></div>
    <div class="fi-kpi"><div class="l">Consumo médio</div><div class="v"><?= $consumoLh !== null ? numFmt($consumoLh, 2) . ' L/h' : '—' ?></div><div class="s"><?= numFmt($litros, 1) ?> L no período</div></div>
  </div>

  <div class="fi-grid">
    <!-- §2 Disponibilidade da frota (contexto) + §1c preventivas -->
    <div class="fi-sec">
      <h3>Disponibilidade da frota &amp; preventivas</h3>
      <div class="fi-empty">Frota agora: <strong><?= numFmt($dispFrota, 0) ?>%</strong> disponível (<?= $frotaDisponiveis ?> de <?= $frotaOperacional ?> operacionais). Esta máquina: <?= $badgeEstado($estado) ?></div>
      <?php if (!$planos): ?>
        <div class="fi-empty">Nenhum plano preventivo ativo. Cadastre em <a href="<?= $base ?>/maquinas/planos_manutencao.php">Planos preventivos</a>.</div>
      <?php else: foreach ($planos as $pl): ?>
        <div class="fi-plan <?= $pl['sinal'] ?>">
          <div class="d"><?= h($pl['descricao']) ?>
            <?php if ($pl['sinal'] === 'vencido'): ?><span class="vbadge vb-off">vencida</span><?php elseif ($pl['sinal'] === 'proximo'): ?><span class="vbadge vb-warn">a vencer</span><?php endif; ?></div>
          <div class="m"><?= $pl['msgs'] ? h(implode(' · ', $pl['msgs'])) : 'sem base de cálculo (registre a última revisão)' ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- §3 Medidores -->
    <div class="fi-sec">
      <h3>Medidores no período · <a href="<?= $base ?>/maquinas/horimetro.php?maquina=<?= $maquinaId ?>">horímetro</a><?= $odometros ? ' · <a href="' . $base . '/maquinas/odometro?maquina=' . $maquinaId . '">odômetro</a>' : '' ?></h3>
      <?php if (!$horimetros && !$odometros): ?>
        <div class="fi-empty">Sem leituras de medidor no período.</div>
      <?php else: ?>
        <table><thead><tr><th>Data</th><th class="rnum">Horímetro (h)</th><?= $odometros ? '<th class="rnum">Odômetro (km)</th>' : '' ?></tr></thead><tbody>
        <?php foreach ($horimetros as $hh): ?>
          <tr><td class="vnum"><?= date('d/m/Y', strtotime((string)$hh['data_leitura'])) ?></td><td class="rnum"><?= numFmt((float)$hh['horimetro'], 1) ?></td><?= $odometros ? '<td class="rnum">—</td>' : '' ?></tr>
        <?php endforeach; ?>
        <?php foreach ($odometros as $od): if (!$horimetros): ?>
          <tr><td class="vnum"><?= date('d/m/Y', strtotime((string)$od['data_leitura'])) ?></td><td class="rnum">—</td><td class="rnum"><?= numFmt((float)$od['odometro'], 0) ?></td></tr>
        <?php endif; endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>

    <!-- §4 Abastecimentos -->
    <div class="fi-sec">
      <h3>Abastecimentos · <a href="<?= $base ?>/maquinas/abastecimento.php?maquina=<?= $maquinaId ?>">ver todos</a></h3>
      <?php if (!$abRecentes): ?>
        <div class="fi-empty">Nenhum abastecimento no período.</div>
      <?php else: ?>
        <table><thead><tr><th>Data</th><th class="rnum">Litros</th><th class="rnum">Valor (R$)</th><th class="rnum">R$/L</th><th class="rnum">Horímetro</th><?= $abTemApontamento ? '<th>Apont.</th>' : '' ?></tr></thead><tbody>
        <?php foreach ($abRecentes as $a): ?>
          <tr><td class="vnum"><?= date('d/m/Y', strtotime((string)$a['data_abastecimento'])) ?></td>
            <td class="rnum"><?= numFmt((float)$a['litros'], 1) ?></td>
            <td class="rnum"><?= $veCusto ? numFmt((float)$a['valor_total'], 2) : '•••' ?></td>
            <td class="rnum"><?= !$veCusto ? '•••' : ((float)$a['litros'] > 0 ? numFmt((float)$a['valor_total'] / (float)$a['litros'], 2) : '—') ?></td>
            <td class="rnum"><?= $a['horimetro'] !== null ? numFmt((float)$a['horimetro'], 1) : '—' ?></td>
            <?= $abTemApontamento ? '<td class="vhint">' . (($a['apontamento_id'] ?? null) ? '#' . (int)$a['apontamento_id'] : '—') . '</td>' : '' ?></tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="fi-empty">Consumo médio no período: <strong><?= $consumoLh !== null ? numFmt($consumoLh, 2) . ' L/h' : '—' ?></strong> (litros ÷ Δ horímetro). O combustível entra no custeio por rateio de horas — mecanismo do motor de custo, não da ficha.</div>
      <?php endif; ?>
    </div>

    <!-- §5 Manutenções -->
    <div class="fi-sec">
      <h3>Manutenções (OS) · <a href="<?= $base ?>/maquinas/manutencao.php?maquina=<?= $maquinaId ?>">ver todas</a></h3>
      <?php if (!$osRecentes): ?>
        <div class="fi-empty">Nenhuma OS no período.</div>
      <?php else: ?>
        <table><thead><tr><th>Data</th><th>Tipo</th><th>Descrição</th><th class="rnum">Custo (R$)</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($osRecentes as $o): ?>
          <tr<?= $o['status'] === 'cancelada' ? ' style="opacity:.55"' : '' ?>>
            <td class="vnum"><?= date('d/m/Y', strtotime((string)$o['data_manutencao'])) ?></td>
            <td><span class="vbadge <?= $o['tipo'] === 'preventiva' ? 'vb-info' : 'vb-warn' ?>"><?= h(ucfirst((string)$o['tipo'])) ?></span></td>
            <td><?= h(mb_substr((string)($o['descricao'] ?? ''), 0, 42)) ?: '—' ?>
              <?php if ($veCusto && ((float)$o['pecas'] > 0 || (float)$o['servicos'] > 0)): ?><div class="vhint">peças <?= numFmt((float)$o['pecas'], 2) ?> · serviços <?= numFmt((float)$o['servicos'], 2) ?></div><?php endif; ?>
              <?= $o['plano'] ? '<div class="vhint">plano: ' . h($o['plano']) . '</div>' : '' ?></td>
            <td class="rnum"><strong><?= $veCusto ? numFmt((float)$o['custo'], 2) : '•••' ?></strong></td>
            <td><?= match ($o['status']) { 'aberta' => '<span class="vbadge vb-warn">Aberta</span>', 'executada' => '<span class="vbadge vb-ok">Executada</span>', default => '<span class="vbadge vb-off">Cancelada</span>' } ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="fi-empty">Custo da OS executada = header `custo` (já é peças ao custo médio + serviços — <strong>não somar itens à parte</strong>); é o mesmo valor do custeio (categoria máquinas). Só OS <em>executada</em> entra no custo.</div>
      <?php endif; ?>
    </div>

    <!-- Uso em campo -->
    <div class="fi-sec">
      <h3>Uso em campo (apontamentos)</h3>
      <?php if (!$usoRecente): ?>
        <div class="fi-empty">Sem apontamentos de uso no período.</div>
      <?php else: ?>
        <table><thead><tr><th>Data</th><th>Válvula</th><th>Atividade</th><th class="rnum">Horas</th><th class="rnum">Custo-hora (R$)</th></tr></thead><tbody>
        <?php foreach ($usoRecente as $u): ?>
          <tr><td class="vnum"><?= date('d/m/Y', strtotime((string)$u['data_apontamento'])) ?></td>
            <td><?= h($u['talhao'] ?? '—') ?></td><td><?= h($u['atividade'] ?? '—') ?></td>
            <td class="rnum"><?= numFmt((float)$u['horas'], 1) ?></td>
            <td class="rnum"><?= !$veCusto ? '•••' : ($u['custo_hora'] !== null ? numFmt((float)$u['custo_hora'], 2) : '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <div class="fi-empty">No período: <strong><?= numFmt($horasApontadas, 1) ?> h</strong> apontadas · custo-hora alocado à válvula <strong><?= $veCusto ? 'R$ ' . numFmt((float)$usoAno['custo'], 2) : '•••' ?></strong> (é este apontamento que alimenta o custeio da safra — a ficha só consolida).</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
