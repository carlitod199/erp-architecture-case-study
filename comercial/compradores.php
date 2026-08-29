<?php
/* ============================================================
   VERO — Comercial / Compradores  (CRUD real)
   Substitui o mock. Rota da matriz: /comercial/compradores.php
   Guard: comercial.compradores | Escrita: comercial.compradores.editar/excluir
   Tabela: comercial_compradores (mig. 132) — "terra pronta" fiscal:
   CNPJ/IE/endereço ficam prontos para NFe na fase 2 (D7).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';

const T = 'comercial_compradores';

/* UX 19/07 (auditoria seção 4): validação de CNPJ/CPF por dígito verificador.
   O campo é um OU outro — detecta pelo tamanho (11 dígitos = CPF, 14 = CNPJ).
   Retorna 'cpf' | 'cnpj' quando válido, null quando inválido. */
function comprador_doc_tipo(string $doc): ?string
{
    $d = preg_replace('/\D/', '', $doc);
    $n = strlen($d);
    if ($n !== 11 && $n !== 14) return null;
    if (preg_match('/^(\d)\1+$/', $d)) return null; /* 000…/111… têm DV "válido" mas são inválidos */
    if ($n === 11) { /* CPF: DV1 pesos 10..2, DV2 pesos 11..2 (mod 11) */
        for ($dv = 9; $dv < 11; $dv++) {
            $s = 0;
            for ($i = 0; $i < $dv; $i++) $s += (int)$d[$i] * (($dv + 1) - $i);
            $r = ($s * 10) % 11 % 10;
            if ($r !== (int)$d[$dv]) return null;
        }
        return 'cpf';
    }
    /* CNPJ: pesos 5,4,3,2,9,8,7,6,5,4,3,2 (DV1) e 6,5,4,3,2,9,8,7,6,5,4,3,2 (DV2) */
    foreach ([12, 13] as $dv) {
        $peso = $dv - 7; /* 5 ou 6 */
        $s = 0;
        for ($i = 0; $i < $dv; $i++) {
            $s += (int)$d[$i] * $peso;
            $peso = $peso === 2 ? 9 : $peso - 1;
        }
        $r = $s % 11;
        $r = $r < 2 ? 0 : 11 - $r;
        if ($r !== (int)$d[$dv]) return null;
    }
    return 'cnpj';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require('comercial.compradores.editar');

        $id    = vero_int('id');
        $razao = vero_str('razao_social', 180);

        if ($razao === null) {
            vero_flash('erro', 'Razão social é obrigatória.');
            vero_redirect();
        }
        $cnpj = vero_str('cnpj_cpf', 18);
        $docTipo = null;
        if ($cnpj !== null) {
            /* UX 19/07: rejeita documento com DV errado (rede do JS do modal) */
            $docTipo = comprador_doc_tipo($cnpj);
            if ($docTipo === null) {
                vero_flash('erro', "CNPJ/CPF \"{$cnpj}\" inválido — o dígito verificador não confere (CPF = 11 dígitos, CNPJ = 14). Nada foi gravado.");
                vero_redirect();
            }
            $dup = vero_val(
                "SELECT id FROM " . T . " WHERE tenant_id=:t AND cnpj_cpf=:c AND ativo=1 AND id<>:id",
                [':t' => vero_tenant(), ':c' => $cnpj, ':id' => (int)$id]);
            if ($dup) {
                vero_flash('erro', "Já existe um comprador ativo com o documento {$cnpj}.");
                vero_redirect();
            }
        }
        $uf = vero_str('uf', 2);

        $data = [
            'razao_social'       => $razao,
            'nome_fantasia'      => vero_str('nome_fantasia', 180),
            'cnpj_cpf'           => $cnpj,
            'inscricao_estadual' => vero_str('inscricao_estadual', 30),
            'email'              => vero_str('email', 150),
            'telefone'           => vero_str('telefone', 20),
            'logradouro'         => vero_str('logradouro', 180),
            'numero'             => vero_str('numero', 20),
            'bairro'             => vero_str('bairro', 100),
            'cidade'             => vero_str('cidade', 100),
            'uf'                 => $uf !== null ? strtoupper($uf) : null,
            'cep'                => vero_str('cep', 10),
            'observacao'         => vero_str('observacao', 255),
            'ativo'              => vero_int('ativo') ?? 1,
        ];

        if ($id) {
            vero_update(T, $id, $data);
            vero_flash('ok', "Comprador \"{$razao}\" atualizado.");
        } else {
            vero_insert(T, $data);
            vero_flash('ok', "Comprador \"{$razao}\" cadastrado.");
        }
        /* UX 19/07: aviso NÃO-bloqueante — PJ sem IE compromete a NF-e futura */
        if ($docTipo === 'cnpj' && $data['inscricao_estadual'] === null) {
            vero_flash('aviso', "Comprador PJ sem Inscrição Estadual — a IE será necessária para emitir NF-e (fase 2). Complete quando tiver o dado.");
        }
        vero_redirect();
    }

    if ($acao === 'excluir') {
        vero_require('comercial.compradores.excluir');
        $id = vero_int('id');
        if ($id) vero_delete(T, $id); // soft delete (tem `ativo`)
        vero_redirect();
    }
}

/* ── Listagem ───────────────────────────────────────────────── */
$q       = trim((string)($_GET['q'] ?? ''));
$page    = max(1, (int)($_GET['pg'] ?? 1));
$perPage = 20;

$where  = "c.tenant_id = :t";
$params = [':t' => vero_tenant()];
if ($q !== '') {
    $where .= " AND (c.razao_social LIKE :q1 OR c.nome_fantasia LIKE :q2 OR c.cnpj_cpf LIKE :q3 OR c.cidade LIKE :q4)";
    foreach ([1, 2, 3, 4] as $qi) $params[":q{$qi}"] = "%{$q}%"; /* QA-011 */
}

$total = (int)vero_val("SELECT COUNT(*) FROM " . T . " c WHERE {$where}", $params);
$rows  = vero_rows(
    "SELECT c.*,
            (SELECT COUNT(*) FROM comercial_vendas v
              WHERE v.tenant_id = c.tenant_id AND v.comprador_id = c.id) AS vendas
       FROM " . T . " c
      WHERE {$where}
      ORDER BY c.ativo DESC, c.razao_social
      LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
    $params
);

$edit = null;
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM " . T . " WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
}

$GUARD      = ['macro' => 'comercial', 'micro' => 'compradores'];
$PAGE_VIEW  = 'comercial_compradores';
$PAGE_TITLE = 'Compradores';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('comercial.compradores.editar');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Compradores', 'Clientes da produção — dados fiscais prontos para NFe (fase 2)',
        $podeEditar ? '+ Novo comprador' : null) ?>

  <div class="vcard">
    <div class="vtoolbar">
      <form method="get" style="display:flex;gap:8px;flex:1;flex-wrap:wrap">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Buscar por razão social, documento ou cidade…">
        <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
      </form>
      <span class="vsub"><?= $total ?> registro(s)</span>
    </div>

    <?php if (!$rows): ?>
      <div class="vempty">Nenhum comprador cadastrado.</div>
    <?php else: ?>
    <table class="vtable">
      <thead><tr>
        <th>Razão social</th><th>CNPJ/CPF</th><th>IE</th><th>Cidade/UF</th>
        <th>Contato</th>
        <th style="text-align:right">Vendas</th>
        <th>Status</th><th style="text-align:right">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= h($r['razao_social']) ?></strong>
            <?= $r['nome_fantasia'] ? '<div class="vhint">' . h($r['nome_fantasia']) . '</div>' : '' ?></td>
          <td class="vnum"><?= h($r['cnpj_cpf'] ?? '') ?: '—' ?></td>
          <td class="vnum"><?= h($r['inscricao_estadual'] ?? '') ?: '—' ?></td>
          <td><?= $r['cidade'] ? h($r['cidade'] . '/' . $r['uf']) : '—' ?></td>
          <td class="vhint"><?= h($r['telefone'] ?? $r['email'] ?? '') ?: '—' ?></td>
          <td class="vnum" style="text-align:right"><?= (int)$r['vendas'] ?></td>
          <td><?= vero_b_ativo($r['ativo']) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can('comercial.compradores.excluir') && (int)$r['ativo'] === 1): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Inativar este comprador?') ?>
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
      <h2><?= $edit ? 'Editar comprador' : 'Novo comprador' ?></h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-form')">×</button>
    </header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">
      <div class="vgrid">
        <div class="full"><?= vero_f_text('razao_social', 'Razão social', $edit['razao_social'] ?? '', true) ?></div>
        <?= vero_f_text('nome_fantasia', 'Nome fantasia', $edit['nome_fantasia'] ?? '') ?>
        <?= vero_f_text('cnpj_cpf', 'CNPJ / CPF', $edit['cnpj_cpf'] ?? '') ?>
        <?= vero_f_text('inscricao_estadual', 'Inscrição estadual', $edit['inscricao_estadual'] ?? '') ?>
        <?= vero_f_text('telefone', 'Telefone', $edit['telefone'] ?? '') ?>
        <div class="full"><?= vero_f_text('email', 'E-mail', $edit['email'] ?? '') ?></div>
        <?= vero_f_text('logradouro', 'Logradouro', $edit['logradouro'] ?? '') ?>
        <?= vero_f_text('numero', 'Número', $edit['numero'] ?? '') ?>
        <?= vero_f_text('bairro', 'Bairro', $edit['bairro'] ?? '') ?>
        <?= vero_f_text('cidade', 'Cidade', $edit['cidade'] ?? '') ?>
        <?= vero_f_text('uf', 'UF', $edit['uf'] ?? '', false, 'Sigla com 2 letras') ?>
        <?= vero_f_text('cep', 'CEP', $edit['cep'] ?? '') ?>
        <div class="full"><?= vero_f_text('observacao', 'Observação', $edit['observacao'] ?? '') ?></div>
        <?php if ($edit): ?>
          <?= vero_f_select('ativo', 'Status', [1 => 'Ativo', 0 => 'Inativo'], (int)$edit['ativo'], true, '') ?>
        <?php endif; ?>
      </div>
      <!-- UX 19/07: aviso vivo (não bloqueia) e erro de DV (bloqueia o submit;
           o servidor revalida — comprador_doc_tipo é a rede final) -->
      <div id="cmp-ie-aviso" class="vhint" style="display:none;margin-top:8px;color:#B57C1A">⚠ PJ sem Inscrição Estadual — necessária para a NF-e futura (não impede de salvar).</div>
      <div id="cmp-doc-erro" role="alert" style="display:none;margin-top:8px;padding:8px 10px;border-radius:8px;background:#FBEDE9;color:#B3402A;font-size:12.5px;font-weight:600"></div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-form')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Salvar</button>
      </div>
    </form>
  </div>
</div>
<script>
/* UX 19/07: validação client de CNPJ/CPF por DV (espelha comprador_doc_tipo
   do servidor) + aviso vivo de IE vazia p/ PJ. Campo vazio é permitido. */
(function () {
  const form = document.querySelector('#vm-form form');
  if (!form) return;
  const doc = form.querySelector('input[name="cnpj_cpf"]');
  const ie  = form.querySelector('input[name="inscricao_estadual"]');
  const avisoIe = document.getElementById('cmp-ie-aviso');
  const erroBox = document.getElementById('cmp-doc-erro');

  function docTipo(v) { /* 'cpf' | 'cnpj' | null(inválido) | ''(vazio) */
    const d = String(v || '').replace(/\D/g, '');
    if (!d) return '';
    if (d.length !== 11 && d.length !== 14) return null;
    if (/^(\d)\1+$/.test(d)) return null;
    if (d.length === 11) {
      for (let dv = 9; dv < 11; dv++) {
        let s = 0;
        for (let i = 0; i < dv; i++) s += +d[i] * ((dv + 1) - i);
        if (((s * 10) % 11) % 10 !== +d[dv]) return null;
      }
      return 'cpf';
    }
    for (const dv of [12, 13]) {
      let peso = dv - 7, s = 0;
      for (let i = 0; i < dv; i++) { s += +d[i] * peso; peso = peso === 2 ? 9 : peso - 1; }
      let r = s % 11; r = r < 2 ? 0 : 11 - r;
      if (r !== +d[dv]) return null;
    }
    return 'cnpj';
  }

  function atualizarAvisos() {
    const t = docTipo(doc ? doc.value : '');
    if (avisoIe) avisoIe.style.display =
      (t === 'cnpj' && ie && !ie.value.trim()) ? 'block' : 'none';
    if (t !== null && erroBox) erroBox.style.display = 'none';
  }
  if (doc) doc.addEventListener('input', atualizarAvisos);
  if (ie)  ie.addEventListener('input', atualizarAvisos);
  atualizarAvisos();

  form.addEventListener('submit', function (e) {
    const t = docTipo(doc ? doc.value : '');
    if (t === null) {
      e.preventDefault();
      const dLen = String(doc.value).replace(/\D/g, '').length;
      erroBox.textContent = 'CNPJ/CPF inválido — ' +
        (dLen === 11 || dLen === 14
          ? 'o dígito verificador não confere.'
          : 'use 11 dígitos (CPF) ou 14 (CNPJ); você digitou ' + dLen + '.');
      erroBox.style.display = 'block';
      doc.focus();
    }
  });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
