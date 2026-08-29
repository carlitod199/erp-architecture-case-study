<?php
/* ============================================================
   VERO — Estoque / Catálogo Agrofit (A2-F2-13, DB-36)
   Rota: /estoque/agrofit.php (menu próprio desde a aprovação:
   micro estoque.agrofit criado pelo A0; link extra em Produtos)
   Decisões do cliente (P-22/P-59): semear defensivos do AGROFIT
   (dados abertos do MAPA, CC-BY) por UPLOAD MANUAL do CSV oficial
   "Produto Formulado"; atualização só ao cadastrar produto novo.
   Três movimentos:
   1) upload do CSV → UPSERT do catálogo local (agregado por
      NR_REGISTRO — o arquivo é desnormalizado por produto×cultura×praga);
   2) ENRIQUECIMENTO dos produtos já cadastrados: preenche SOMENTE
      campos vazios (ingrediente ativo, fabricante←titular, classe
      tox.) — o registro do RT NUNCA é sobrescrito (Regra 1);
      divergências viram relatório;
   3) CRIAR produto a partir de um nº de registro (pré-preenche o
      cadastro como defensivo — permanece 100% editável pelo RT).
   Matching: registro_mapa e NR_REGISTRO comparados como INTEIROS
   (dígitos, zeros à esquerda ignorados).
   Atribuição: dados regulatórios — fonte MAPA/Agrofit (CC-BY).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

/** Normaliza um registro MAPA para comparação (dígitos, sem zeros à esquerda). */
function agrofit_reg_norm(?string $reg): ?string
{
    if ($reg === null) return null;
    $d = ltrim(preg_replace('/\D+/', '', $reg), '0');
    return $d !== '' ? $d : null;
}

/**
 * Faz o parse do CSV oficial (UTF-8, `;`, cabeçalho com NR_REGISTRO,
 * MARCA_COMERCIAL, INGREDIENTE_ATIVO, TITULAR_DE_REGISTRO, CLASSE,
 * CLASSE_TOXICOLOGICA, CLASSE_AMBIENTAL, CULTURA, PRAGA_NOME_COMUM…)
 * e AGREGA por NR_REGISTRO. Mapeia colunas pelo NOME (robusto a ordem).
 * Retorna [nr => [marca, ingrediente, titular, classe, tox, amb, culturas[], pragas[]]].
 */
function agrofit_agregar_csv(string $conteudo): array
{
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo); /* BOM */
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $conteudo);
    rewind($fh);

    $cab = fgetcsv($fh, 0, ';', '"');
    if (!$cab) throw new RuntimeException('CSV vazio ou ilegível.');
    $idx = [];
    foreach ($cab as $i => $nome) {
        $idx[strtoupper(trim((string)$nome))] = $i;
    }
    foreach (['NR_REGISTRO', 'MARCA_COMERCIAL'] as $obr) {
        if (!isset($idx[$obr])) {
            throw new RuntimeException("Cabeçalho não contém a coluna {$obr} — confira se é o CSV oficial \"Produto Formulado\" do Agrofit.");
        }
    }
    $col = static fn(array $l, string $c): string => isset($idx[$c], $l[$idx[$c]]) ? trim((string)$l[$idx[$c]]) : '';

    $agg = [];
    $linhas = 0;
    while (($l = fgetcsv($fh, 0, ';', '"')) !== false) {
        $linhas++;
        $nr = agrofit_reg_norm($col($l, 'NR_REGISTRO'));
        if ($nr === null) continue;
        if (!isset($agg[$nr])) {
            $agg[$nr] = [
                'marca' => $col($l, 'MARCA_COMERCIAL'),
                'ingrediente' => $col($l, 'INGREDIENTE_ATIVO'),
                'titular' => $col($l, 'TITULAR_DE_REGISTRO'),
                'classe' => $col($l, 'CLASSE'),
                'tox' => $col($l, 'CLASSE_TOXICOLOGICA'),
                'amb' => $col($l, 'CLASSE_AMBIENTAL'),
                'culturas' => [], 'pragas' => [],
            ];
        }
        $cult = $col($l, 'CULTURA');
        if ($cult !== '') $agg[$nr]['culturas'][$cult] = true;
        $praga = $col($l, 'PRAGA_NOME_COMUM');
        if ($praga !== '') $agg[$nr]['pragas'][$praga] = true;
    }
    fclose($fh);
    return ['agregado' => $agg, 'linhas' => $linhas];
}

/** UPSERT do agregado no catálogo local (UNIQUE tenant+nr_registro). Retorna nº de registros. */
function agrofit_upsert(array $agg): int
{
    $t = vero_tenant();
    $uid = vero_uid();
    $sql = "INSERT INTO agrofit_catalogo
              (tenant_id, nr_registro, marca_comercial, ingrediente_ativo, titular, classe,
               classe_toxicologica, classe_ambiental, culturas, pragas, importado_em,
               created_at, updated_at, created_by, updated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW(),?,?)
            ON DUPLICATE KEY UPDATE
              marca_comercial=VALUES(marca_comercial), ingrediente_ativo=VALUES(ingrediente_ativo),
              titular=VALUES(titular), classe=VALUES(classe),
              classe_toxicologica=VALUES(classe_toxicologica), classe_ambiental=VALUES(classe_ambiental),
              culturas=VALUES(culturas), pragas=VALUES(pragas), importado_em=NOW(),
              updated_at=NOW(), updated_by=VALUES(updated_by)";
    $st = vero_pdo()->prepare($sql);
    $n = 0;
    foreach ($agg as $nr => $p) {
        $st->execute([$t, (string)$nr,
            mb_substr($p['marca'], 0, 150), mb_substr($p['ingrediente'], 0, 255),
            mb_substr($p['titular'], 0, 150), mb_substr($p['classe'], 0, 100),
            mb_substr($p['tox'], 0, 80), mb_substr($p['amb'], 0, 80),
            mb_substr(implode(', ', array_keys($p['culturas'])), 0, 5000),
            mb_substr(implode(', ', array_keys($p['pragas'])), 0, 5000),
            $uid, $uid]);
        $n++;
    }
    return $n;
}

/**
 * Enriquecimento: produtos do tenant com registro_mapa que casem com o
 * catálogo têm APENAS os campos vazios preenchidos (ingrediente_ativo,
 * fabricante←titular, classe_toxicologica). Divergências NÃO sobrescrevem —
 * viram relatório. Retorna ['enriquecidos' => n, 'divergencias' => [...]].
 */
function agrofit_enriquecer(): array
{
    $t = vero_tenant();
    $produtos = vero_rows(
        "SELECT id, codigo, nome, registro_mapa, ingrediente_ativo, fabricante, classe_toxicologica
           FROM estoque_produtos WHERE tenant_id=:t AND registro_mapa IS NOT NULL AND registro_mapa <> ''",
        [':t' => $t]);
    $enriquecidos = 0;
    $divergencias = [];
    foreach ($produtos as $p) {
        $nr = agrofit_reg_norm((string)$p['registro_mapa']);
        if ($nr === null) continue;
        $cat = vero_row("SELECT * FROM agrofit_catalogo WHERE tenant_id=:t AND nr_registro=:n",
            [':t' => $t, ':n' => $nr]);
        if (!$cat) continue;
        $upd = [];
        $mapa = [
            'ingrediente_ativo'   => mb_substr((string)$cat['ingrediente_ativo'], 0, 150),
            'fabricante'          => mb_substr((string)$cat['titular'], 0, 120),
            'classe_toxicologica' => mb_substr((string)$cat['classe_toxicologica'], 0, 40),
        ];
        foreach ($mapa as $campo => $valorCat) {
            if ($valorCat === '') continue;
            $atual = trim((string)($p[$campo] ?? ''));
            if ($atual === '') {
                $upd[$campo] = $valorCat; /* só preenche VAZIO — Regra 1 */
            } elseif (mb_strtolower($atual) !== mb_strtolower($valorCat)) {
                $divergencias[] = $p['codigo'] . ' (' . $campo . '): cadastro "' . $atual
                    . '" × Agrofit "' . $valorCat . '"';
            }
        }
        if ($upd) {
            vero_update('estoque_produtos', (int)$p['id'], $upd);
            $enriquecidos++;
        }
    }
    return ['enriquecidos' => $enriquecidos, 'divergencias' => $divergencias];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'importar') {
        vero_require('estoque.agrofit.editar');
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            vero_flash('erro', 'Envie o CSV oficial "Produto Formulado" do Agrofit (dados.agricultura.gov.br).');
            vero_redirect();
        }
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $res = agrofit_agregar_csv((string)file_get_contents($_FILES['csv']['tmp_name']));
            if (!$res['agregado']) throw new RuntimeException('Nenhum registro válido no arquivo.');
            $n = agrofit_upsert($res['agregado']);
            $enr = agrofit_enriquecer();
            $pdo->commit();
            vero_flash('ok', "Catálogo atualizado: {$n} produto(s) formulado(s) (de {$res['linhas']} linhas do CSV). "
                . "Enriquecidos {$enr['enriquecidos']} produto(s) do estoque (só campos vazios).");
            if ($enr['divergencias']) {
                vero_flash('aviso', 'Divergências NÃO sobrescritas (registro do RT prevalece): '
                    . mb_substr(implode(' | ', $enr['divergencias']), 0, 800));
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Importação não realizada: ' . h($e->getMessage()));
        }
        vero_redirect();
    }

    if ($acao === 'criar') {
        vero_require('estoque.agrofit.editar');
        $nr     = agrofit_reg_norm(vero_str('nr_registro', 20));
        $unidade = vero_str('unidade', 10) ?? 'L';
        $cat = $nr !== null ? vero_row("SELECT * FROM agrofit_catalogo WHERE tenant_id=:t AND nr_registro=:n",
            [':t' => vero_tenant(), ':n' => $nr]) : null;
        /* A2-F2-15: com o padrão de 6 dígitos ativo (pós-A0-14), o código é
           GERADO automaticamente pelo service; antes disso, campo manual */
        $codigo6 = function_exists('vero_srv_produto_proximo_codigo');
        $codigo = $codigo6 ? vero_srv_produto_proximo_codigo() : vero_str('codigo', 40);
        if (!$cat || $codigo === null) {
            vero_flash('erro', ($codigo6 ? 'Informe um' : 'Informe o código do produto e um')
                . ' nº de registro existente no catálogo (importe o CSV se ele não constar).');
            vero_redirect();
        }
        $dup = vero_val("SELECT id FROM estoque_produtos WHERE tenant_id=:t AND codigo=:c",
            [':t' => vero_tenant(), ':c' => $codigo]);
        if ($dup) {
            vero_flash('erro', "Já existe produto com o código \"{$codigo}\".");
            vero_redirect();
        }
        /* registro já usado? matching NORMALIZADO em PHP (dígitos sem zeros à
           esquerda) — CAST no SQL para no primeiro hífen ("01234-56" → 1234) */
        foreach (vero_rows("SELECT id, codigo, registro_mapa FROM estoque_produtos
                             WHERE tenant_id=:t AND registro_mapa IS NOT NULL AND registro_mapa <> ''",
            [':t' => vero_tenant()]) as $pReg) {
            if (agrofit_reg_norm((string)$pReg['registro_mapa']) === $nr) {
                vero_flash('erro', "O registro {$nr} já pertence ao produto \"{$pReg['codigo']}\" — edite-o em vez de duplicar.");
                vero_redirect();
            }
        }
        $novoId = vero_insert('estoque_produtos', [
            'grupo_id'            => vero_srv_grupo_estoque_padrao(),
            'codigo'              => $codigo,
            'nome'                => mb_substr((string)$cat['marca_comercial'], 0, 150) ?: ('Agrofit ' . $nr),
            'tipo_insumo'         => 'defensivo',
            'fabricante'          => mb_substr((string)$cat['titular'], 0, 120) ?: null,
            'registro_mapa'       => $nr,
            'ingrediente_ativo'   => mb_substr((string)$cat['ingrediente_ativo'], 0, 150) ?: null,
            'classe_toxicologica' => mb_substr((string)$cat['classe_toxicologica'], 0, 40) ?: null,
            'unidade'             => $unidade,
            'controla_validade'   => 1, /* defensivo sempre controla validade */
            'ativo'               => 1,
        ]);
        vero_flash('ok', 'Produto criado do Agrofit (' . h((string)$cat['marca_comercial']) . ') — complete a BULA '
            . '(dose, carência, intervalo, nutrientes) com o RT: o cadastro é 100% editável.');
        vero_redirect(rtrim(BIOS_BASE, '/') . '/estoque/produtos?editar=' . (int)$novoId);
    }
}

/* ── Leitura ────────────────────────────────────────────────── */
$t = vero_tenant();
$q = trim((string)($_GET['q'] ?? ''));

$info = vero_row("SELECT COUNT(*) AS produtos, MAX(importado_em) AS ultima FROM agrofit_catalogo WHERE tenant_id=:t",
    [':t' => $t]);

$resultados = [];
if ($q !== '') {
    $nrQ = agrofit_reg_norm($q);
    $resultados = vero_rows(
        "SELECT * FROM agrofit_catalogo
          WHERE tenant_id = :t AND (marca_comercial LIKE :q" . ($nrQ !== null ? " OR nr_registro = :nr" : "") . ")
          ORDER BY marca_comercial LIMIT 30",
        $nrQ !== null ? [':t' => $t, ':q' => "%{$q}%", ':nr' => $nrQ] : [':t' => $t, ':q' => "%{$q}%"]);
}

$GUARD      = ['macro' => 'estoque', 'micro' => 'agrofit'];
$PAGE_VIEW  = 'estoque_agrofit';
$PAGE_TITLE = 'Catálogo Agrofit';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('estoque.agrofit.editar');
$base = rtrim(BIOS_BASE, '/');
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Catálogo Agrofit',
        'Defensivos registrados no MAPA — semeia e enriquece o cadastro sem substituir o registro do RT', null) ?>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Catálogo local</strong>
      <span class="vsub"><?= (int)$info['produtos'] ?> produto(s) formulado(s)
        <?= $info['ultima'] ? '· importado em ' . date('d/m/Y H:i', strtotime((string)$info['ultima'])) : '· nunca importado' ?></span>
      <div style="flex:1"></div>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php">← Produtos</a></div>
    <?php if ($podeEditar): ?>
    <form method="post" enctype="multipart/form-data"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:0 14px 12px">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="importar">
      <div class="vfield" style="flex:1;min-width:280px">
        <label>CSV oficial "Produto Formulado" *</label>
        <input type="file" name="csv" accept=".csv,text/csv" required>
        <div class="vhint">dados.agricultura.gov.br → dataset "Agrofit" (atualização diária; baixe pelo navegador). Arquivos grandes dependem do limite de upload do servidor.</div>
      </div>
      <button class="vbtn vbtn-primary" type="submit">Importar / atualizar catálogo</button>
    </form>
    <div class="vhint" style="padding:0 14px 12px">A importação também ENRIQUECE os produtos já cadastrados: preenche apenas campos vazios (ingrediente ativo, fabricante, classe tox.) casando pelo nº de registro — divergências viram relatório e o registro do RT nunca é sobrescrito. Atualize só quando for cadastrar um produto que não conste.</div>
    <?php endif; ?>
  </div>

  <div class="vcard" style="margin-bottom:14px">
    <div class="vtoolbar"><strong>Buscar no catálogo</strong></div>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;padding:0 14px 12px">
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Nº de registro ou marca comercial…" style="flex:1;min-width:240px">
      <button class="vbtn vbtn-ghost" type="submit">Buscar</button>
    </form>
    <?php if ($q !== '' && !$resultados): ?>
      <div class="vempty">Nada encontrado — importe/atualize o CSV se o produto for registro recente.</div>
    <?php elseif ($resultados): ?>
    <table class="vtable">
      <thead><tr><th>Registro</th><th>Marca comercial</th><th>Ingrediente ativo</th>
        <th>Classe</th><th>Tox.</th><th style="text-align:right">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($resultados as $r): ?>
        <tr>
          <td class="vnum"><strong><?= h($r['nr_registro']) ?></strong></td>
          <td><strong><?= h($r['marca_comercial']) ?></strong>
            <div class="vhint"><?= h(mb_substr((string)$r['titular'], 0, 60)) ?></div></td>
          <td class="vhint"><?= h(mb_substr((string)$r['ingrediente_ativo'], 0, 70)) ?></td>
          <td class="vhint"><?= h(mb_substr((string)$r['classe'], 0, 40)) ?></td>
          <td class="vhint"><?= h(mb_substr((string)$r['classe_toxicologica'], 0, 40)) ?></td>
          <td><div class="vactions">
            <?php if ($podeEditar): ?>
              <?= vero_btn_icone(vero_ico_seta(), 'Criar produto', "agfCriar('" . h($r['nr_registro']) . "', '" . h(addslashes((string)$r['marca_comercial'])) . "')") ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="vhint" style="padding:0 4px">Dados regulatórios: fonte MAPA/Agrofit — licença CC-BY (atribuição ao Ministério da Agricultura e Pecuária). A dose NÃO consta dos dados abertos: registre a bula com o RT no cadastro do produto.</div>
</div>

<?php if ($podeEditar): ?>
<div class="vmodal" id="vm-criar">
  <div class="vbox">
    <header><h2 id="agf-titulo">Criar produto do Agrofit</h2>
      <button class="vclose" type="button" onclick="vModalClose('vm-criar')">×</button></header>
    <form class="vform" method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="criar">
      <input type="hidden" name="nr_registro" id="agf-nr">
      <div class="vgrid">
        <?php if (function_exists('vero_srv_produto_proximo_codigo')): /* A2-F2-15 */ ?>
          <div class="vfield"><label>Código interno</label>
            <input type="text" value="automático (próximo nº de 6 dígitos)" disabled>
            <div class="vhint">gerado na criação — ajuste depois no cadastro se precisar</div></div>
        <?php else: ?>
          <?= vero_f_text('codigo', 'Código interno do produto', '', true, 'ex.: DEF-002 — único no estoque') ?>
        <?php endif; ?>
        <?= vero_f_text('unidade', 'Unidade de uso', 'L', true, 'L, kg…') ?>
      </div>
      <div class="vhint" style="margin-top:8px">Cria como DEFENSIVO (controla validade) com nome/fabricante/ingrediente/classe tox. do catálogo — você será levado ao cadastro para o RT completar a bula (tudo editável).</div>
      <div class="vform-actions">
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-criar')">Cancelar</button>
        <button class="vbtn vbtn-primary" type="submit">Criar e abrir cadastro</button>
      </div>
    </form>
  </div>
</div>
<script>
function agfCriar(nr, marca) {
  document.getElementById('agf-nr').value = nr;
  document.getElementById('agf-titulo').textContent = 'Criar produto — ' + marca + ' (reg. ' + nr + ')';
  vModalOpen('vm-criar');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
