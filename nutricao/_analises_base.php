<?php
/* ============================================================
   VERO — Nutrição / base compartilhada de Análises (solo|foliar)
   Incluída por analise_solo.php e analise_foliar.php, que definem:
     $ANL_TIPO   = 'solo' | 'foliar'
     $ANL_MICRO  = 'analise_solo' | 'analise_foliar'
     $ANL_TITULO, $ANL_SUB, $ANL_VIEW
   Fluxo: cabeçalho + valores por nutriente → salvar reclassifica
   contra analise_faixas (vero_srv_analise_classificar) e reemite
   alertas em agro_alertas. Sem faixa cadastrada = sem classificação
   (D5 — o sistema nunca inventa referência).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';
require_once __DIR__ . '/../agro/_fenologia_helper.php'; /* Opção B (mig 166): fase por variedade */

$permBase = 'nutricao.' . $ANL_MICRO;
$tabelaA  = $ANL_TIPO === 'solo' ? 'analise_solo' : 'analise_foliar';
$tabelaR  = $ANL_TIPO === 'solo' ? 'analise_solo_resultados' : 'analise_foliar_resultados';

const CLASSIF_ROTULOS = [
    'muito_baixo' => ['Muito baixo', 'vb-off'],
    'baixo'       => ['Baixo', 'vb-warn'],
    'adequado'    => ['Adequado', 'vb-ok'],
    'alto'        => ['Alto', 'vb-warn'],
    'excessivo'   => ['Excessivo', 'vb-off'],
];

/* P11 (auditoria Relatórios 20/07): crítica de entrada dos resultados. pH fora
   da escala 0–14 e unidade incoerente (um número no campo unidade, ex.: "4")
   são rejeitados na gravação (manual e CSV). Só valida o que está sendo
   gravado agora — o legado já persistido não é tocado. Devolve a mensagem de
   erro (string) ou null se o resultado passa. */
function anl_validar_resultado(array $nutr, float $valor, ?string $unid): ?string
{
    $rot     = (string)($nutr['simbolo'] ?: ($nutr['nome'] ?? 'nutriente'));
    $ehPh    = strtolower(trim((string)($nutr['simbolo'] ?? ''))) === 'ph';
    $unid    = $unid !== null ? trim($unid) : '';
    if ($ehPh) {
        if ($valor < 0 || $valor > 14) {
            return 'pH deve estar na escala 0–14 (valor informado: '
                . number_format($valor, 2, ',', '.') . ').';
        }
        /* pH é adimensional (escala) — unidade coerente: vazia, "-", "pH" */
        if ($unid !== '' && !in_array(strtolower($unid), ['-', 'ph', 'adimensional', 'escala'], true)) {
            return 'pH é adimensional (escala 0–14) — a unidade "' . $unid . '" não é válida; deixe em branco.';
        }
    }
    /* geral: unidade nunca é um número puro (ex.: "4") */
    if ($unid !== '' && preg_match('/^-?\d+([.,]\d+)?$/', $unid)) {
        return 'Unidade inválida para ' . $rot . ': "' . $unid . '" parece um número, não uma unidade de medida.';
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'salvar') {
        vero_require($permBase . '.editar');

        $id   = vero_int('id');
        $data = vero_date('data_amostra');
        if ($data === null) {
            vero_flash('erro', 'A data da amostra é obrigatória.');
            vero_redirect();
        }
        $talhaoId = vero_int('talhao_id');
        $setorId  = vero_int('setor_id');
        $fazendaId = null;
        if ($talhaoId) {
            $talhao = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $talhaoId, ':t' => vero_tenant()]);
            if (!$talhao) $talhaoId = null;
            else $fazendaId = (int)$talhao['fazenda_id'];
        }
        if ($setorId) {
            $okSetor = vero_val("SELECT id FROM agro_setores WHERE id=:i AND tenant_id=:t",
                [':i' => $setorId, ':t' => vero_tenant()]);
            if (!$okSetor) $setorId = null;
        }

        $cab = [
            'fazenda_id'   => $fazendaId,
            'talhao_id'    => $talhaoId,
            'setor_id'     => $setorId,
            'safra_id'     => vero_int('safra_id'),
            'data_amostra' => $data,
            'origem'       => 'manual',
            'status'       => 'registrado',
            'observacao'   => vero_str('observacao', 255),
        ];
        if ($ANL_TIPO === 'solo') {
            $cab['profundidade'] = vero_str('profundidade', 20);
        } else {
            $cab['variedade_id'] = vero_int('variedade_id');
            $cab['fenologia_id'] = vero_int('fenologia_id');
            $parte = vero_str('parte_folha', 20);
            $cab['parte_folha'] = in_array($parte, ['limbo', 'peciolo', 'folha_inteira'], true) ? $parte : null;
            /* Opção B (mig 166): resolve a fase POR VARIEDADE da amostra (variedade da
               válvula + dias desde a poda), quando há válvula + safra vinculadas. O
               resolver usa a variedade REAL da válvula e a poda (agro_safra_talhoes.
               data_poda ou início da safra). fenologia_id (cultura) segue como fallback;
               sem vínculo/poda/fenologia-variedade, fica NULL (comportamento antigo). */
            $cab['variedade_fase_id'] = null;
            $cab['dias_desde_poda']   = null;
            $safraFoliar = vero_int('safra_id');
            if ($talhaoId && $safraFoliar) {
                $stFoliar = vero_val(
                    "SELECT id FROM agro_safra_talhoes WHERE tenant_id=:t AND safra_id=:s AND talhao_id=:ta",
                    [':t' => vero_tenant(), ':s' => $safraFoliar, ':ta' => $talhaoId]);
                if ($stFoliar) {
                    $vf = vero_a1_fenologia_variedade_resolver((int)$stFoliar, $safraFoliar, $data);
                    if ($vf) {
                        $cab['variedade_fase_id'] = (int)$vf['id'];
                        $cab['dias_desde_poda']   = (int)$vf['dias'];
                    }
                }
            }
        }

        $rNutr  = (array)($_POST['r_nutriente'] ?? []);
        $rValor = (array)($_POST['r_valor'] ?? []);
        $rUnid  = (array)($_POST['r_unidade'] ?? []);
        $parseDec = static function ($v): ?float {
            $v = trim((string)$v);
            if ($v === '') return null;
            if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v);
            return is_numeric($v) ? (float)$v : null;
        };

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $ok = vero_val("SELECT id FROM {$tabelaA} WHERE id=:i AND tenant_id=:t",
                    [':i' => $id, ':t' => vero_tenant()]);
                if (!$ok) throw new RuntimeException('Análise inválida.');
                /* origem preservada na edição (manual/excel/ia) */
                unset($cab['origem']);
                vero_update($tabelaA, $id, $cab);
                $pdo->prepare("DELETE FROM {$tabelaR} WHERE tenant_id=? AND analise_id=?")
                    ->execute([vero_tenant(), $id]);
                $anlId = $id;
            } else {
                $anlId = vero_insert($tabelaA, $cab);
            }
            $qtd = 0;
            foreach ($rNutr as $ix => $nutrId) {
                $valor = $parseDec($rValor[$ix] ?? '');
                if ($valor === null) continue;
                $nutrId = (int)$nutrId;
                $nutrRow = vero_row("SELECT id, simbolo, nome, unidade_padrao FROM analise_nutrientes WHERE id=:i AND tenant_id=:t",
                    [':i' => $nutrId, ':t' => vero_tenant()]);
                if (!$nutrRow) continue;
                $unidVal = trim((string)($rUnid[$ix] ?? '')) ?: null;
                /* P11: crítica de entrada (pH 0–14, unidade coerente) */
                $erroVal = anl_validar_resultado($nutrRow, $valor, $unidVal);
                if ($erroVal !== null) throw new RuntimeException($erroVal);
                /* tabela de resultados não tem colunas de auditoria — PDO direto */
                $pdo->prepare("INSERT INTO {$tabelaR} (tenant_id, analise_id, nutriente_id, valor, unidade)
                               VALUES (?,?,?,?,?)")
                    ->execute([vero_tenant(), $anlId, $nutrId, $valor, $unidVal]);
                $qtd++;
            }
            $cont = vero_srv_analise_classificar($ANL_TIPO, $anlId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao salvar a análise: ' . h($e->getMessage()));
            vero_redirect();
        }

        vero_flash('ok', "Análise salva com {$qtd} resultado(s): {$cont['classificados']} classificado(s), "
            . "{$cont['sem_faixa']} sem faixa cadastrada, {$cont['alertas']} alerta(s) gerado(s).");
        if ($cont['sem_faixa'] > 0) {
            vero_flash('aviso', 'Resultados sem faixa não são classificados — cadastre as referências em Nutrição → Faixas Nutricionais.');
        }
        vero_redirect(BIOS_BASE . '/nutricao/' . $ANL_MICRO . '.php?editar=' . $anlId);
    }

    if ($acao === 'importar_csv') {
        vero_require($permBase . '.editar');

        $data = vero_date('data_amostra');
        $file = $_FILES['csv'] ?? null;
        if ($data === null || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int)$file['size'] > 1048576
            || !in_array(strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            vero_flash('erro', 'Informe a data e um arquivo CSV válido (até 1 MB). No Excel: Salvar como → CSV.');
            vero_redirect();
        }

        $talhaoId = vero_int('talhao_id');
        $fazendaId = null;
        if ($talhaoId) {
            $talhao = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $talhaoId, ':t' => vero_tenant()]);
            if ($talhao) $fazendaId = (int)$talhao['fazenda_id']; else $talhaoId = null;
        }

        $aplic = $ANL_TIPO === 'solo' ? "('solo','ambos')" : "('foliar','ambos')";
        $nutrs = vero_rows("SELECT id, nome, simbolo FROM analise_nutrientes
                             WHERE tenant_id = :t AND ativo = 1 AND aplicacao IN {$aplic}", [':t' => vero_tenant()]);

        /* CSV: colunas nutriente;valor;unidade (com ou sem cabeçalho; delimitador ; ou ,) */
        $conteudo = (string)file_get_contents((string)$file['tmp_name']);
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo); // BOM
        $linhas = preg_split('/\r\n|\r|\n/', $conteudo) ?: [];
        $delim = substr_count($conteudo, ';') >= substr_count($conteudo, ',') ? ';' : ',';

        $itens = [];
        $naoCasados = [];
        foreach ($linhas as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $cols = str_getcsv($ln, $delim);
            $rotulo = trim((string)($cols[0] ?? ''));
            $valorRaw = trim((string)($cols[1] ?? ''));
            if ($rotulo === '' || $valorRaw === '') continue;
            if ($delim === ';') $valorRaw = str_replace(',', '.', str_replace('.', '', $valorRaw));
            elseif (str_contains($valorRaw, ',')) $valorRaw = str_replace(',', '.', str_replace('.', '', $valorRaw));
            if (!is_numeric($valorRaw)) continue; // pula cabeçalho e linhas inválidas
            $nutrId = vero_srv_casar_nutriente($nutrs, $rotulo, $rotulo);
            if ($nutrId === null) { $naoCasados[] = $rotulo; continue; }
            $itens[$nutrId] = ['valor' => (float)$valorRaw, 'unidade' => trim((string)($cols[2] ?? '')) ?: null];
        }
        if (!$itens) {
            vero_flash('erro', 'Nenhum nutriente reconhecido no CSV. Formato esperado: nutriente;valor;unidade (uma linha por nutriente, símbolo ou nome do catálogo).');
            vero_redirect();
        }
        /* P11: mesma crítica de entrada da digitação manual, agora no CSV */
        $nutrById = [];
        foreach ($nutrs as $nx) $nutrById[(int)$nx['id']] = $nx;
        foreach ($itens as $nid => $it) {
            if (!isset($nutrById[$nid])) continue;
            $erroCsv = anl_validar_resultado($nutrById[$nid], (float)$it['valor'], $it['unidade']);
            if ($erroCsv !== null) { vero_flash('erro', 'CSV recusado — ' . $erroCsv); vero_redirect(); }
        }

        $cab = [
            'fazenda_id'   => $fazendaId,
            'talhao_id'    => $talhaoId,
            'setor_id'     => vero_int('setor_id'),
            'safra_id'     => vero_int('safra_id'),
            'data_amostra' => $data,
            'origem'       => 'excel',
            'status'       => 'registrado',
            'observacao'   => vero_str('observacao', 255),
        ];
        if ($ANL_TIPO === 'solo') $cab['profundidade'] = vero_str('profundidade', 20);

        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $anlId = vero_insert($tabelaA, $cab);
            foreach ($itens as $nutrId => $item) {
                $pdo->prepare("INSERT INTO {$tabelaR} (tenant_id, analise_id, nutriente_id, valor, unidade)
                               VALUES (?,?,?,?,?)")
                    ->execute([vero_tenant(), $anlId, $nutrId, $item['valor'], $item['unidade']]);
            }
            $cont = vero_srv_analise_classificar($ANL_TIPO, $anlId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro na importação: ' . h($e->getMessage()));
            vero_redirect();
        }
        vero_flash('ok', 'CSV importado: ' . count($itens) . " resultado(s), {$cont['classificados']} classificado(s), {$cont['alertas']} alerta(s). Revise os valores.");
        if ($naoCasados) {
            vero_flash('aviso', 'Não reconhecidos no catálogo de nutrientes: ' . h(implode(', ', array_unique($naoCasados))) . '.');
        }
        vero_redirect(BIOS_BASE . '/nutricao/' . $ANL_MICRO . '.php?editar=' . $anlId);
    }

    if ($acao === 'excluir') {
        vero_require($permBase . '.excluir');
        $id = vero_int('id');
        if ($id) {
            $ok = vero_val("SELECT id FROM {$tabelaA} WHERE id=:i AND tenant_id=:t",
                [':i' => $id, ':t' => vero_tenant()]);
            if ($ok) {
                $pdo = vero_pdo();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id=? AND categoria='nutricao' AND origem_tipo=? AND origem_id=?")
                        ->execute([vero_tenant(), $tabelaA, $id]);
                    $pdo->prepare("DELETE FROM {$tabelaR} WHERE tenant_id=? AND analise_id=?")
                        ->execute([vero_tenant(), $id]);
                    $pdo->prepare("DELETE FROM {$tabelaA} WHERE tenant_id=? AND id=?")
                        ->execute([vero_tenant(), $id]);
                    $pdo->commit();
                    vero_flash('ok', 'Análise excluída (resultados e alertas removidos).');
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    vero_flash('erro', 'Erro ao excluir: ' . h($e->getMessage()));
                }
            }
        }
        vero_redirect(BIOS_BASE . '/nutricao/' . $ANL_MICRO . '.php');
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$modoForm = isset($_GET['novo']) || !empty($_GET['editar']);

$edit = null;
$editResultados = [];
if (!empty($_GET['editar'])) {
    $edit = vero_row("SELECT * FROM {$tabelaA} WHERE id=:id AND tenant_id=:t",
        [':id' => (int)$_GET['editar'], ':t' => vero_tenant()]);
    if ($edit) {
        foreach (vero_rows("SELECT * FROM {$tabelaR} WHERE tenant_id=:t AND analise_id=:a",
            [':t' => vero_tenant(), ':a' => (int)$edit['id']]) as $er) {
            $editResultados[(int)$er['nutriente_id']] = $er;
        }
    } else {
        $modoForm = false;
    }
}

if ($modoForm) {
    $aplic = $ANL_TIPO === 'solo' ? "('solo','ambos')" : "('foliar','ambos')";
    $nutrientes = vero_rows(
        "SELECT id, nome, simbolo, unidade_padrao FROM analise_nutrientes
          WHERE tenant_id = :t AND ativo = 1 AND aplicacao IN {$aplic}
          ORDER BY ordem, nome", [':t' => vero_tenant()]);
    $talhoes = vero_rows(
        "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
           FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
    $setores = vero_rows(
        "SELECT s.id, CONCAT(COALESCE(f.nome,'—'), ' — ', s.codigo) AS label
           FROM agro_setores s LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
          WHERE s.tenant_id = :t AND s.ativo = 1 ORDER BY f.nome, s.codigo", [':t' => vero_tenant()]);
    $safras = vero_options('agro_safras', 'identificacao');
    if ($ANL_TIPO === 'foliar') {
        $variedades = vero_options('agro_variedades', 'nome', 'ativo = 1');
        $fenologias = vero_rows("SELECT id, codigo, nome FROM agro_fenologia_estagios
                                  WHERE tenant_id = :t AND ativo = 1 ORDER BY ordem", [':t' => vero_tenant()]);
    }
} else {
    /* opções mínimas para o modal de importação CSV */
    $talhoes = vero_rows(
        "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
           FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
          WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
    $setores = vero_rows(
        "SELECT s.id, CONCAT(COALESCE(f.nome,'—'), ' — ', s.codigo) AS label
           FROM agro_setores s LEFT JOIN agro_fazendas f ON f.id = s.fazenda_id
          WHERE s.tenant_id = :t AND s.ativo = 1 ORDER BY f.nome, s.codigo", [':t' => vero_tenant()]);
    $safras = vero_options('agro_safras', 'identificacao');

    $page    = max(1, (int)($_GET['pg'] ?? 1));
    $perPage = 15;
    $where  = "a.tenant_id = :t";
    $params = [':t' => vero_tenant()];
    $total = (int)vero_val("SELECT COUNT(*) FROM {$tabelaA} a WHERE {$where}", $params);
    $rows  = vero_rows(
        "SELECT a.*, f.nome AS fazenda, t.codigo AS talhao, se.codigo AS valvula,
                (SELECT COUNT(*) FROM {$tabelaR} r WHERE r.tenant_id = a.tenant_id AND r.analise_id = a.id) AS resultados,
                (SELECT COUNT(*) FROM agro_alertas al
                  WHERE al.tenant_id = a.tenant_id AND al.categoria = 'nutricao'
                    AND al.origem_tipo = '{$tabelaA}' AND al.origem_id = a.id AND al.status = 'aberto') AS alertas
           FROM {$tabelaA} a
           LEFT JOIN agro_fazendas f ON f.id = a.fazenda_id
           LEFT JOIN agro_talhoes t ON t.id = a.talhao_id
           LEFT JOIN agro_setores se ON se.id = a.setor_id
          WHERE {$where}
          ORDER BY a.data_amostra DESC, a.id DESC
          LIMIT " . (($page - 1) * $perPage) . ", {$perPage}",
        $params);
}

$GUARD      = ['macro' => 'nutricao', 'micro' => $ANL_MICRO];
$PAGE_VIEW  = $ANL_VIEW;
$PAGE_TITLE = $ANL_TITULO;
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can($permBase . '.editar');

$badgeOrigem = static fn(string $o): string => match ($o) {
    'ia'    => '<span class="vbadge vb-info">IA</span>',
    'excel' => '<span class="vbadge vb-info">Excel</span>',
    default => '<span class="vbadge vb-ok">Manual</span>',
};
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if (!$modoForm): ?>
  <div class="vhead">
    <div>
      <h1><?= h($ANL_TITULO) ?></h1>
      <div class="vsub"><?= h($ANL_SUB) ?></div>
    </div>
    <?php if ($podeEditar): ?>
      <div style="display:flex;gap:8px">
        <?php if (vero_can('nutricao.importar_laudo')): /* A1-50refino: importador embutido (menu oculto — ONDA6) */ ?>
          <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/nutricao/importar_laudo?tipo=<?= $ANL_TIPO === 'foliar' ? 'foliar' : 'solo' ?>"
             title="Enviar o PDF do laudo e extrair os resultados com IA (revisão humana obrigatória); sem chave de IA, use a digitação manual">📄 Importar laudo (IA)</a>
        <?php endif; ?>
        <button class="vbtn vbtn-ghost" type="button" onclick="vModalOpen('vm-csv')">Importar CSV</button>
        <a class="vbtn vbtn-primary" href="?novo=1">+ Nova análise</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($podeEditar): ?>
  <div class="vmodal" id="vm-csv">
    <div class="vbox">
      <header>
        <h2>Importar análise via CSV (Excel)</h2>
        <button class="vclose" type="button" onclick="vModalClose('vm-csv')">×</button>
      </header>
      <form class="vform" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
        <input type="hidden" name="acao" value="importar_csv">
        <div class="vgrid">
          <div class="vfield">
            <label>Data da amostra *</label>
            <input type="date" name="data_amostra" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="vfield">
            <label>Válvula</label>
            <select name="talhao_id">
              <option value="">— Não informado —</option>
              <?php foreach ($talhoes as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= h($t['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="vfield">
            <label>Válvula</label>
            <select name="setor_id">
              <option value="">— Não informada —</option>
              <?php foreach ($setores as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="vfield">
            <label>Safra</label>
            <select name="safra_id">
              <option value="">— Não informada —</option>
              <?php foreach ($safras as $sid => $sn): ?>
                <option value="<?= $sid ?>"><?= h($sn) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if ($ANL_TIPO === 'solo'): ?>
            <?= vero_f_text('profundidade', 'Profundidade', '', false, 'Ex.: 0-20 cm') ?>
          <?php endif; ?>
          <div class="full vfield">
            <label>Arquivo CSV *</label>
            <input type="file" name="csv" accept=".csv,.txt" required>
            <div class="vhint">No Excel: Salvar como → CSV. Uma linha por nutriente: <strong>nutriente;valor;unidade</strong> (símbolo ou nome do catálogo, ex.: P;4;mg/dm3).</div>
          </div>
          <div class="full"><?= vero_f_text('observacao', 'Observação (laboratório, laudo…)', '') ?></div>
        </div>
        <div class="vform-actions">
          <button class="vbtn vbtn-ghost" type="button" onclick="vModalClose('vm-csv')">Cancelar</button>
          <button class="vbtn vbtn-primary" type="submit">Importar e classificar</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><span class="vsub"><?= $total ?> registro(s)</span></div>
    <?php if (!$rows): ?>
      <div class="vempty">Nenhuma análise registrada.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>Data amostra</th><th>Fazenda / Válvula</th><th>Válvula</th>
        <?= $ANL_TIPO === 'solo' ? '<th>Profundidade</th>' : '<th>Parte da folha</th>' ?>
        <th>Origem</th>
        <th class="num">Resultados</th>
        <th class="num">Alertas abertos</th>
        <th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="vnum"><?= date('d/m/Y', strtotime((string)$r['data_amostra'])) ?></td>
          <td><?= $r['fazenda'] ? h($r['fazenda']) . ($r['talhao'] ? ' — ' . h($r['talhao']) : '') : '—' ?></td>
          <td class="vnum"><?= h($r['valvula'] ?? '—') ?></td>
          <td><?= $ANL_TIPO === 'solo'
                ? (h($r['profundidade'] ?? '') ?: '—')
                : (h(str_replace('_', ' ', (string)($r['parte_folha'] ?? ''))) ?: '—') ?></td>
          <td><?= $badgeOrigem((string)$r['origem']) ?></td>
          <td class="num"><?= (int)$r['resultados'] ?></td>
          <td class="num">
            <?= (int)$r['alertas'] > 0 ? '<span class="vbadge vb-off">' . (int)$r['alertas'] . '</span>' : '0' ?>
          </td>
          <td class="num"><div class="vactions">
            <?php if ($podeEditar): ?><?= vero_btn_editar((int)$r['id']) ?><?php endif; ?>
            <?php if (vero_can($permBase . '.excluir')): ?>
              <?= vero_btn_excluir((int)$r['id'], 'Excluir esta análise? Resultados e alertas serão removidos.') ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?= vero_pagination($page, $total, $perPage) ?>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php if (!$podeEditar): ?>
    <div class="vflash vflash-erro">Sem permissão para registrar análises.</div>
  <?php else: ?>
  <div class="vhead">
    <div>
      <h1><?= $edit ? 'Editar análise' : 'Nova análise' ?> — <?= $ANL_TIPO === 'solo' ? 'Solo' : 'Foliar' ?></h1>
      <div class="vsub">Preencha apenas os nutrientes do laudo; ao salvar, cada valor é classificado contra a faixa cadastrada</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/nutricao/<?= $ANL_MICRO ?>.php">← Voltar à lista</a>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <div class="vgrid" style="grid-template-columns:repeat(4,1fr)">
        <div class="vfield">
          <label>Data da amostra *</label>
          <input type="date" name="data_amostra" required
                 value="<?= h($edit ? (string)$edit['data_amostra'] : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label>Válvula</label>
          <select name="talhao_id">
            <option value="">— Não informado —</option>
            <?php foreach ($talhoes as $t): ?>
              <option value="<?= (int)$t['id'] ?>"<?= $edit && (int)($edit['talhao_id'] ?? 0) === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Válvula</label>
          <select name="setor_id">
            <option value="">— Não informada —</option>
            <?php foreach ($setores as $s): ?>
              <option value="<?= (int)$s['id'] ?>"<?= $edit && (int)($edit['setor_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= h($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="vfield">
          <label>Safra</label>
          <select name="safra_id">
            <option value="">— Não informada —</option>
            <?php foreach ($safras as $sid => $sn): ?>
              <option value="<?= $sid ?>"<?= $edit && (int)($edit['safra_id'] ?? 0) === $sid ? ' selected' : '' ?>><?= h($sn) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($ANL_TIPO === 'solo'): ?>
          <?= vero_f_text('profundidade', 'Profundidade', $edit['profundidade'] ?? '', false, 'Ex.: 0-20 cm') ?>
        <?php else: ?>
          <?= vero_f_select('variedade_id', 'Variedade', $variedades, $edit['variedade_id'] ?? null, false, '— Não informada —') ?>
          <div class="vfield">
            <label>Fase fenológica</label>
            <select name="fenologia_id">
              <option value="">— Não informada —</option>
              <?php foreach ($fenologias as $f): ?>
                <option value="<?= (int)$f['id'] ?>"<?= $edit && (int)($edit['fenologia_id'] ?? 0) === (int)$f['id'] ? ' selected' : '' ?>>
                  <?= h($f['codigo'] . ' — ' . $f['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?= vero_f_select('parte_folha', 'Parte da folha',
                ['limbo' => 'Limbo', 'peciolo' => 'Pecíolo', 'folha_inteira' => 'Folha inteira'],
                $edit['parte_folha'] ?? null, false, '— Não informada —') ?>
        <?php endif; ?>
        <div class="vfield" style="grid-column:1/-1">
          <label>Observação</label>
          <input type="text" name="observacao" value="<?= h($edit['observacao'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Resultados por nutriente</strong>
        <div class="vhint">Deixe em branco os nutrientes que não constam no laudo</div>
      </div>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr>
          <th>Nutriente</th>
          <th class="num" style="width:150px">Valor</th>
          <th style="width:130px">Unidade</th>
          <th style="width:170px">Classificação</th>
        </tr></thead>
        <tbody>
        <?php foreach ($nutrientes as $n):
            $er = $editResultados[(int)$n['id']] ?? null;
            $classif = $er['classificacao'] ?? null;
        ?>
          <tr>
            <td><strong><?= h($n['simbolo'] ?: $n['nome']) ?></strong> <span class="vhint"><?= h($n['nome']) ?></span>
              <input type="hidden" name="r_nutriente[]" value="<?= (int)$n['id'] ?>"></td>
            <td><input type="text" name="r_valor[]" style="text-align:right" placeholder="—"
                       data-simbolo="<?= h($n['simbolo'] ?? '') ?>" data-rotulo="<?= h($n['simbolo'] ?: $n['nome']) ?>"
                       value="<?= $er ? rtrim(rtrim(number_format((float)$er['valor'], 4, ',', '.'), '0'), ',') : '' ?>"></td>
            <td><input type="text" name="r_unidade[]" value="<?= h($er['unidade'] ?? $n['unidade_padrao'] ?? '') ?>"></td>
            <td>
              <?php if ($classif !== null && isset(CLASSIF_ROTULOS[$classif])): ?>
                <span class="vbadge <?= CLASSIF_ROTULOS[$classif][1] ?>"><?= CLASSIF_ROTULOS[$classif][0] ?></span>
              <?php elseif ($er): /* P10: sem faixa → CTA para cadastrar a referência do nutriente */ ?>
                <a class="vhint" style="text-decoration:underline;white-space:nowrap"
                   href="<?= BIOS_BASE ?>/nutricao/faixas_nutricionais?nova=1&tipo=<?= $ANL_TIPO ?>&nutriente=<?= (int)$n['id'] ?>"
                   title="Cadastrar a faixa de referência deste nutriente para que a análise seja classificada">sem faixa — cadastrar</a>
              <?php else: ?>
                <span class="vhint">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/nutricao/<?= $ANL_MICRO ?>.php">Cancelar</a>
      <button class="vbtn vbtn-primary" type="submit">Salvar e classificar</button>
    </div>
  </form>
  <script>
  /* P11: espelha no cliente as críticas do servidor (anl_validar_resultado) —
     pH fora de 0–14 e unidade que é um número puro. Rede final é o servidor. */
  (function () {
    var form = document.querySelector('form[method="post"]');
    if (!form) return;
    var UNID_NUM = /^-?\d+([.,]\d+)?$/;
    form.addEventListener('submit', function (e) {
      var linhas = form.querySelectorAll('input[name="r_valor[]"]');
      for (var i = 0; i < linhas.length; i++) {
        var inp = linhas[i];
        var raw = inp.value.trim();
        if (raw === '') continue;
        var num = parseFloat(raw.indexOf(',') >= 0 ? raw.replace(/\./g, '').replace(',', '.') : raw);
        var unidInp = inp.closest('tr').querySelector('input[name="r_unidade[]"]');
        var unid = unidInp ? unidInp.value.trim() : '';
        var rot = inp.dataset.rotulo || 'nutriente';
        var ehPh = (inp.dataset.simbolo || '').toLowerCase() === 'ph';
        if (ehPh && !isNaN(num) && (num < 0 || num > 14)) {
          e.preventDefault(); alert('pH deve estar na escala 0–14 (valor informado: ' + raw + ').'); inp.focus(); return;
        }
        if (ehPh && unid !== '' && ['-', 'ph', 'adimensional', 'escala'].indexOf(unid.toLowerCase()) < 0) {
          e.preventDefault(); alert('pH é adimensional (escala 0–14) — a unidade "' + unid + '" não é válida; deixe em branco.'); if (unidInp) unidInp.focus(); return;
        }
        if (unid !== '' && UNID_NUM.test(unid)) {
          e.preventDefault(); alert('Unidade inválida para ' + rot + ': "' + unid + '" parece um número, não uma unidade de medida.'); if (unidInp) unidInp.focus(); return;
        }
      }
    });
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
