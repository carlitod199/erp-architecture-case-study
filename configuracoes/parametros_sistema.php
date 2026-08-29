<?php
/* ============================================================
   VERO — Configurações / Parâmetros do Sistema  (tela real, leitura)
   Substitui o mock. Rota: /configuracoes/parametros_sistema.php
   Guard: configuracoes.parametros_sistema
   Parâmetros operacionais em vigor (constantes VERO_ e IA_) com a
   origem de cada um — a alteração é feita em código/.env pela
   equipe técnica, não por formulário.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php'; /* T-13: vero_srv_param / vero_srv_param_set */

/* T-13: unidade de colheita por cliente (tenant_parametros). Whitelist em PHP. */
const COLHEITA_UNIDADES = ['kg' => 'Somente kg', 'caixa' => 'Somente caixa', 'ambos' => 'Ambos (kg e caixa)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    if ((string)($_POST['acao'] ?? '') === 'salvar_colheita') {
        vero_require('configuracoes.parametros_sistema.editar');
        $unidade = (string)($_POST['colheita_unidade'] ?? 'kg');
        if (!array_key_exists($unidade, COLHEITA_UNIDADES)) $unidade = 'kg'; /* categórico validado na whitelist */
        $peso = vero_dec('colheita_peso_caixa_kg') ?? 0.0;
        if (in_array($unidade, ['caixa', 'ambos'], true) && $peso <= 0) {
            vero_flash('erro', 'Para colher em caixa, informe o peso padrão da caixa (kg) maior que zero.');
            vero_redirect();
        }
        vero_srv_param_set('colheita.unidade', $unidade, 'Unidade de entrada da colheita: kg|caixa|ambos');
        if ($peso > 0) {
            vero_srv_param_set('colheita.peso_caixa_kg', number_format($peso, 2, '.', ''),
                'Peso padrão da caixa em kg (conversão caixa->kg da colheita)');
        }
        vero_flash('ok', 'Configuração de colheita salva.');
        vero_redirect();
    }
}

$colUnidade = (string)vero_srv_param('colheita.unidade', 'kg');
if (!array_key_exists($colUnidade, COLHEITA_UNIDADES)) $colUnidade = 'kg';
$colPeso    = (float)vero_srv_param('colheita.peso_caixa_kg', '0');
$podeEditarParam = vero_can('configuracoes.parametros_sistema.editar');

/* parâmetros documentados (rotulo, descricao, onde alterar) */
$catalogo = [
    'VERO_ESTOQUE_AVISO_VENCIMENTO_DIAS' => [
        'Aviso de vencimento de lotes (dias)',
        'Lotes com validade dentro desta janela geram alerta de estoque.',
        'includes/vero_services.php'],
    'IA_TETO_MENSAL_BRL' => [
        'Teto mensal de custo de IA (R$)',
        'Bloqueia novas extrações de laudo quando o custo estimado do mês atinge o teto (decisão D6).',
        'nutricao/importar_laudo.php'],
    'IA_CUSTO_ESTIMADO_BRL' => [
        'Custo estimado por extração de laudo (R$)',
        'Usado no controle do teto mensal de IA.',
        'nutricao/importar_laudo.php'],
];

/* varre constantes definidas com prefixos do sistema */
$user = get_defined_constants(true)['user'] ?? [];
$parametros = [];
foreach ($user as $nome => $valor) {
    if (!preg_match('/^(VERO_|IA_)/', (string)$nome)) continue;
    if (!is_scalar($valor)) continue;
    $doc = $catalogo[$nome] ?? [null, null, null];
    $parametros[$nome] = ['valor' => $valor, 'rotulo' => $doc[0], 'descricao' => $doc[1], 'onde' => $doc[2]];
}
/* garante os documentados mesmo quando a constante só é definida na tela de origem */
foreach ($catalogo as $nome => $doc) {
    if (!isset($parametros[$nome])) {
        $parametros[$nome] = ['valor' => null, 'rotulo' => $doc[0], 'descricao' => $doc[1], 'onde' => $doc[2]];
    }
}
ksort($parametros);

$ambiente = [
    'Versão do PHP'        => PHP_VERSION,
    'Versão do MySQL'      => (string)vero_val("SELECT VERSION()"),
    'Base da aplicação'    => defined('BIOS_BASE') ? BIOS_BASE : '/',
    'Fuso horário'         => date_default_timezone_get(),
    'ANTHROPIC_API_KEY'    => getenv('ANTHROPIC_API_KEY') ? 'configurada (' . strlen((string)getenv('ANTHROPIC_API_KEY')) . ' caracteres)' : 'NÃO configurada',
];

$GUARD      = ['macro' => 'configuracoes', 'micro' => 'parametros_sistema'];
$PAGE_VIEW  = 'configuracoes_parametros_sistema';
$PAGE_TITLE = 'Parâmetros do Sistema';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Parâmetros do Sistema', 'Valores em vigor e onde cada um é alterado — mudanças são feitas pela equipe técnica', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Parâmetros operacionais</strong></div>
    <table class="vtable">
      <thead><tr><th>Parâmetro</th><th>Valor em vigor</th><th>O que controla</th><th>Onde alterar</th></tr></thead>
      <tbody>
      <?php foreach ($parametros as $nome => $p): ?>
        <tr>
          <td><strong class="vnum"><?= h((string)$nome) ?></strong>
            <?= $p['rotulo'] ? '<div class="vhint">' . h((string)$p['rotulo']) . '</div>' : '' ?></td>
          <td class="vnum"><?= $p['valor'] !== null ? h((string)$p['valor'])
                : '<span class="vhint">definido na tela de origem</span>' ?></td>
          <td class="vhint"><?= h((string)($p['descricao'] ?? '—')) ?></td>
          <td class="vnum"><?= h((string)($p['onde'] ?? 'código')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Colheita</strong>
      <span class="vsub">unidade de apontamento por cliente — kg é sempre o backbone interno</span></div>
    <form class="vform" method="post" style="padding:14px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar_colheita">
      <div class="vfield" style="min-width:220px">
        <label>Unidade de entrada</label>
        <select name="colheita_unidade" <?= $podeEditarParam ? '' : 'disabled' ?>>
          <?php foreach (COLHEITA_UNIDADES as $k => $rot): ?>
            <option value="<?= h($k) ?>"<?= $colUnidade === $k ? ' selected' : '' ?>><?= h($rot) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="vfield" style="min-width:180px">
        <label>Peso padrão da caixa (kg)</label>
        <input type="text" name="colheita_peso_caixa_kg" style="text-align:right"
               value="<?= $colPeso > 0 ? numFmt($colPeso, 2) : '' ?>" placeholder="ex.: 5,00"
               <?= $podeEditarParam ? '' : 'disabled' ?>>
      </div>
      <?php if ($podeEditarParam): ?>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      <?php endif; ?>
    </form>
    <div class="vhint" style="padding:0 14px 12px">
      <strong>kg</strong>: apontamento só em kg/ha (padrão). <strong>caixa</strong>: apontamento em nº de caixas,
      convertido para kg pelo peso padrão. <strong>ambos</strong>: o operador escolhe a unidade em cada registro.
      A classificação, o limite Σ % ≤ 100 e o estoque continuam sempre em kg.
    </div>
  </div>

  <div class="vcard">
    <div class="vtoolbar"><strong>Ambiente</strong></div>
    <table class="vtable">
      <tbody>
      <?php foreach ($ambiente as $rotulo => $valor): ?>
        <tr>
          <td style="width:40%"><strong><?= h((string)$rotulo) ?></strong></td>
          <td class="vnum"><?= h((string)$valor) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="vhint" style="padding:10px 14px">Chaves e senhas nunca são exibidas — apenas o status de configuração. Alterações de parâmetro passam pela equipe técnica (código/.env) e valem imediatamente.</div>
  </div>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
