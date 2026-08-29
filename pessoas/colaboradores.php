<?php
/* ============================================================
   VERO — Pessoas / Colaboradores  (CRUD real)
   Rota da matriz: /pessoas/colaboradores.php (micro `operadores`,
   mantido para preservar as permissões pessoas.operadores.*)
   Guard: pessoas.operadores | Escrita: pessoas.operadores.editar/excluir
   Tabela: agro_operadores (+ tipo_vinculo/salario/documento da mig. 130)
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; /* vero_srv_encargos_* + vero_srv_param: Custo/hora automático da folha */

const T = 'agro_operadores';

const VINCULOS = ['clt' => 'CLT', 'diarista' => 'Diarista', 'terceirizado' => 'Terceirizado', 'outro' => 'Outro'];

/* REGRA: "Função" deixa de ser texto livre — lista fechada p/
   padronizar a nomenclatura. O valor GRAVADO é o próprio rótulo (chave = rótulo)
   porque a coluna `funcao` é exibida crua em folha, equipes, histórico e RT.
   Valores legados fora da lista continuam exibindo e, na edição, aparecem como
   opção extra "(legado)" — sem UPDATE em massa no banco. */
const FUNCOES = [
    'Trabalhador Rural'      => 'Trabalhador Rural',
    'Podador'                => 'Podador',
    'Tratorista'             => 'Tratorista',
    'Operador de Máquinas'   => 'Operador de Máquinas',
    'Irrigante'              => 'Irrigante',
    'Aplicador de Defensivos'=> 'Aplicador de Defensivos',
    'Auxiliar Agrícola'      => 'Auxiliar Agrícola',
    'Auxiliar de Colheita'   => 'Auxiliar de Colheita',
    'Diarista de Colheita'   => 'Diarista de Colheita',
    'Monitor de Pragas (MIP)'=> 'Monitor de Pragas (MIP)',
    'Encarregado de Turma'   => 'Encarregado de Turma',
    'Encarregado de Campo'   => 'Encarregado de Campo',
    'Supervisor de Campo'    => 'Supervisor de Campo',
    'Técnico Agrícola'       => 'Técnico Agrícola',
    'Administrativo'         => 'Administrativo',
];

/* ── Base do Custo/hora automático (puxado da folha) ─────────────────────
   custo/hora = (salário + encargos vigentes, quando CLT) ÷ horas do mês.
   horas do mês = dias úteis × jornada (h/dia) — MESMA base da diária de MO,
   então custo/hora × jornada = diária (coerência com o custeio). Parâmetros
   por tenant (mo.dias_uteis_mes / mo.jornada_horas_dia) com defaults 22 e 8. */
$encCfg = vero_srv_encargos_vigente();
$encPct = 0.0;
if ($encCfg) {
    foreach (['fgts_pct', 'inss_patronal_pct', 'rat_pct', 'terceiros_pct', 'ferias_pct', 'decimo_pct', 'outros_pct'] as $c) {
        $encPct += (float)($encCfg[$c] ?? 0);
    }
}
$diasUteisMes = (float)(vero_srv_param('mo.dias_uteis_mes', '22') ?: 22);
$jornadaHoras = (float)(vero_srv_param('mo.jornada_horas_dia', '8') ?: 8);
$horasMes     = max(1.0, round($diasUteisMes * $jornadaHoras, 2));

/** Custo/hora derivado da folha (função pura). CLT soma encargos vigentes; demais vínculos usam só o salário. */
function colab_custo_hora_folha(float $salario, string $vinculo, ?array $encCfg, float $horasMes): ?float
{
    if ($salario <= 0 || $horasMes <= 0) return null;
    $mensal = ($vinculo === 'clt' && $encCfg)
        ? (float) vero_srv_encargos_calc($salario, $encCfg)['custo_total']
        : $salario;
    return round($mensal / $horasMes, 2);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('pessoas.operadores.editar');

        $id      = vero_int('id');
        $nome    = vero_str('nome', 150);
        $vinculo = vero_str('tipo_vinculo', 20);

        if ($nome === null || $vinculo === null || !isset(VINCULOS[$vinculo])) {
            vero_flash('erro', 'Nome e tipo de vínculo são obrigatórios.');
            vero_redirect();
        }
        /* sem constraint no banco — aviso amigável para homônimo ativo */
        $dup = vero_val(
            "SELECT id FROM " . T . " WHERE tenant_id=:t AND nome=:n AND ativo=1 AND id<>:id",
            [':t' => vero_tenant(), ':n' => $nome, ':id' => (int)$id]
        );
        if ($dup) {
            vero_flash('erro', "Já existe um colaborador ativo chamado \"{$nome}\". Use o documento para diferenciar e tente novamente.");
            vero_redirect();
        }

        /* A11: valores monetários não podem ser negativos (folha/custeio) */
        $salario = vero_dec('salario_mensal');
        $custoH  = vero_dec('custo_hora');
        /* Custo/hora AUTOMÁTICO da folha quando não informado:
           salário + encargos vigentes ÷ horas do mês. Se o usuário digitou um
           valor, respeita (override manual). */
        if (($custoH === null || $custoH <= 0) && $salario !== null && $salario > 0) {
            $auto = colab_custo_hora_folha((float)$salario, $vinculo, $encCfg, $horasMes);
            if ($auto !== null) $custoH = $auto;
        }
        $custoH = $custoH ?? 0;
        /* diária direta (diarista/trabalhador rural): base da MÉDIA de custo da MO
           na calculadora. Opcional; não pode ser negativa. */
        $vDiaria = vero_dec('valor_diaria');
        if (($salario !== null && $salario < 0) || $custoH < 0 || ($vDiaria !== null && $vDiaria < 0)) {
            vero_flash('erro', 'Salário, custo/hora e diária não podem ser negativos.');
            vero_redirect();
        }

        $papelPk = vero_str('funcao_packing', 12);
        if ($papelPk !== null && !in_array($papelPk, ['colhedor', 'embalador', 'ambos'], true)) $papelPk = null;

        /* REGRA: função só aceita valor da lista FUNCOES (whitelist). Exceção de
           compatibilidade: na edição, o valor legado JÁ gravado no registro é
           aceito de volta (a tela oferece a opção "(legado)") p/ não sumir dado.
           Fora disso, valor desconhecido vira null (mesmo padrão do funcao_packing). */
        $funcao = vero_str('funcao', 80);
        if ($funcao !== null && !isset(FUNCOES[$funcao])) {
            $legado = $id
                ? vero_val("SELECT funcao FROM " . T . " WHERE id=:id AND tenant_id=:t",
                    [':id' => (int)$id, ':t' => vero_tenant()])
                : null;
            if ($legado === null || $funcao !== (string)$legado) $funcao = null;
        }

        $data = [
            'nome'           => $nome,
            'funcao'         => $funcao,
            'funcao_packing' => $papelPk,
            'tipo_vinculo'   => $vinculo,
            'documento'      => vero_str('documento', 20),
            'salario_mensal' => $salario,
            'valor_diaria'   => $vDiaria,
            'custo_hora'     => $custoH,
            'ativo'          => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Colaborador \"{$nome}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Colaborador \"{$nome}\" cadastrado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('pessoas.operadores.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q        = trim((string)($_GET['q'] ?? ''));
$fVinculo = trim((string)($_GET['vinculo'] ?? ''));
$page     = max(1, (int)($_GET['pg'] ?? 1));
$perPage  = 20;

$where  = "o.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (o.nome LIKE :q1 OR o.funcao LIKE :q2 OR o.documento LIKE :q3)";
    foreach ([1, 2, 3] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011 */
}
if ($fVinculo !== '' && isset(VINCULOS[$fVinculo])) {
    $where .= " AND o.tipo_vinculo = :v";
    $params[':v'] = $fVinculo;
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " o WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT o.* FROM " . T . " o
      WHERE {$where}
      ORDER BY o.ativo DESC, o.nome
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'pessoas', 'micro' => 'operadores'];
$PAGE_VIEW  = 'pessoas_colaboradores';
$PAGE_TITLE = 'Colaboradores';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('pessoas.operadores.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Colaboradores', 'Equipe própria e diaristas — base do apontamento, da premiação e da folha',
        $podeEditar ? '+ Novo colaborador' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <select name="vinculo" onchange="this.form.submit()">
          <option value="">Todos os vínculos</option>
          <?php foreach (VINCULOS as $vk => $vl): ?>
            <option value="<?= $vk ?>"<?= $fVinculo === $vk ? ' selected' : '' ?>><?= h($vl) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por nome, função ou documento…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum colaborador encontrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Nome</th><th>Função</th><th>Vínculo</th><th>Documento</th>
        <th style="text-align:right">Salário (R$)</th>
        <th style="text-align:right">Custo/hora (R$)</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['nome']) ?></strong></td>
          <td><?= h($r['funcao'] ?? '') ?: '—' ?></td>
          <td><span class="vbadge <?= $r['tipo_vinculo'] === 'clt' ? 'vb-info' : 'vb-warn' ?>"><?= h(VINCULOS[$r['tipo_vinculo']] ?? $r['tipo_vinculo']) ?></span></td>
          <td class="vnum"><?= h($r['documento'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= $r['salario_mensal'] !== null ? numFmt((float)$r['salario_mensal'], 2) : '—' ?></td>
          <td class="vnum" style="text-align:right"><?= numFmt((float)$r['custo_hora'], 2) ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('pessoas.operadores.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este colaborador?') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal<?= $edit ? ' open' : '' ?>" id="vm-form">
  <div class="vbox">
    <header>
      <h2><?= $edit ? 'Editar colaborador' : 'Novo colaborador' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('nome', 'Nome completo', $edit['nome'] ?? '', true) ?></div>
        <?php
        /* Função = lista fechada (padronização 18/08). Valor legado fora da
           lista entra como opção extra "(legado)" já selecionada, p/ o dado
           não sumir ao salvar sem mexer no campo. */
        $funcaoAtual = (string)($edit['funcao'] ?? '');
        $funcaoOpts  = FUNCOES;
        if ($funcaoAtual !== '' && !isset(FUNCOES[$funcaoAtual])) {
            $funcaoOpts = [$funcaoAtual => $funcaoAtual . ' (legado)'] + $funcaoOpts;
        }
        ?>
        <?= vero_f_select('funcao', 'Função', $funcaoOpts, $funcaoAtual, false, '— Selecione a função —') ?>
        <?= vero_f_select('funcao_packing', 'Função no packing', ['colhedor' => 'Colhedor', 'embalador' => 'Embalador', 'ambos' => 'Ambos'], $edit['funcao_packing'] ?? '', false, '— Não atua no packing —') ?>
        <?= vero_f_select('tipo_vinculo', 'Tipo de vínculo', VINCULOS, $edit['tipo_vinculo'] ?? 'clt', true, '') ?>
        <?= vero_f_text('documento', 'Documento (CPF)', $edit['documento'] ?? '') ?>
        <?php /* A11: sem min="0" possível (vero_f_text gera type=text; money é mascarado). Guarda >=0 é no servidor (handler acima). */ ?>
        <?= vero_f_text('salario_mensal', 'Salário mensal (R$)', $edit && $edit['salario_mensal'] !== null ? numFmt((float)$edit['salario_mensal'], 2) : '', false, 'Base p/ encargos, folha e custo/hora') ?>
        <?= vero_f_text('valor_diaria', 'Valor da diária (R$)', $edit && ($edit['valor_diaria'] ?? null) !== null ? numFmt((float)$edit['valor_diaria'], 2) : '', false, 'Diarista/trabalhador rural — a calculadora tira a média das diárias') ?>
        <?= vero_f_text('custo_hora', 'Custo/hora (R$)', $edit ? numFmt((float)$edit['custo_hora'], 2) : '', false, 'Puxado da folha (salário + encargos ÷ horas). Ajustável.') ?>
        <div class="full" style="margin-top:-6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <button type="button" class="vbtn vbtn-ghost vbtn-sm" id="colab-folha-btn">↻ Calcular da folha</button>
          <span class="vhint" style="margin:0">
            <?= $encCfg
                ? 'Base: salário + ' . h(numFmt($encPct, 1)) . '% de encargos ÷ ' . h(numFmt($horasMes, 0)) . ' h/mês (' . h(numFmt($diasUteisMes, 0)) . ' dias × ' . h(numFmt($jornadaHoras, 0)) . ' h)'
                : 'Sem tabela de encargos vigente — usa salário ÷ ' . h(numFmt($horasMes, 0)) . ' h/mês. Cadastre em Pessoas → Encargos CLT para incluir os encargos.' ?>
          </span>
        </div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* Custo/hora automático da folha: salário (+ encargos CLT) ÷ horas do mês.
   Preenche ao digitar o salário / trocar o vínculo; o usuário pode ajustar. */
(function () {
  const ENC_PCT   = <?= json_encode($encPct) ?>;   // % de encargos vigentes (CLT)
  const HORAS_MES = <?= json_encode($horasMes) ?>;  // horas/mês (dias úteis × jornada)
  const salInp   = document.querySelector('#vm-form [name="salario_mensal"]');
  const custoInp = document.querySelector('#vm-form [name="custo_hora"]');
  const vincSel  = document.querySelector('#vm-form [name="tipo_vinculo"]');
  const btn      = document.getElementById('colab-folha-btn');
  if (!salInp || !custoInp) return;
  const parse = s => { const n = parseFloat(String(s || '').replace(/\./g, '').replace(',', '.').replace(/[^0-9.\-]/g, '')); return isFinite(n) ? n : 0; };
  const fmt   = n => n.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  /* ao EDITAR um registro já salvo, respeita o custo/hora atual até mexerem no salário */
  let manual = <?= $edit ? 'true' : 'false' ?>;
  function calc(force) {
    const sal = parse(salInp.value);
    if (sal <= 0 || HORAS_MES <= 0) return;
    const pct = (vincSel && vincSel.value === 'clt') ? ENC_PCT : 0;
    custoInp.value = fmt(sal * (1 + pct / 100) / HORAS_MES);
    if (force) manual = false;
  }
  salInp.addEventListener('input', () => { manual = false; calc(); });
  if (vincSel) vincSel.addEventListener('change', () => { if (!manual) calc(); });
  custoInp.addEventListener('input', () => { manual = true; });
  if (btn) btn.addEventListener('click', () => calc(true));
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
