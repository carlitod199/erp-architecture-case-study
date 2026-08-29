<?php
/* ============================================================
   VERO — Estoque / Importação em massa de produtos  (X-10, reunião 23/07)
   Rota: /estoque/importar_produtos.php   Guard: estoque.produtos_insumos
   Sobe uma planilha CSV (template padrão) e grava produtos em lote — base bruta
   reaproveitável para todos os clientes. SEMPRE dry-run primeiro: valida linha a
   linha e mostra o que será criado/atualizado; só grava com "confirmar".
   Casamento por CÓDIGO (tenant): existe → atualiza; não existe → cria.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

/* colunas do template (ordem canônica) → coluna no banco */
const IMP_COLS = [
    'codigo'                   => 'codigo',
    'nome'                     => 'nome',
    'ingrediente_ativo'        => 'ingrediente_ativo',   // molécula
    'tipo_insumo'              => 'tipo_insumo',
    'unidade'                  => 'unidade',
    'dose_referencia'          => 'dose_referencia',
    'dose_referencia_unidade'  => 'dose_referencia_unidade',
    'lmr_dias'                 => 'lmr_dias',
    'carencia_dias'            => 'carencia_dias',
    'intervalo_aplicacoes_dias' => 'intervalo_aplicacoes_dias',
    'num_max_aplicacoes'       => 'num_max_aplicacoes',
    'classe_toxicologica'      => 'classe_toxicologica',
    'fabricante'               => 'fabricante',
    'registro_mapa'            => 'registro_mapa',
];
const IMP_TIPOS = ['defensivo', 'fertilizante', 'corretivo', 'produto_agricola', 'outro'];
const IMP_UNIDS = ['kg', 'L', 'mL', 'g', 'un'];
const IMP_INT   = ['lmr_dias', 'carencia_dias', 'intervalo_aplicacoes_dias', 'num_max_aplicacoes'];
/* A-06/D-3: colunas OPCIONAIS de saldo inicial VALORIZADO — populam custo médio
   na carga (produtos importados deixam de ficar com custo 0,00). Não são colunas
   da tabela: viram uma ENTRADA de estoque (origem "implantacao") via service. */
const IMP_SALDO_COLS = ['saldo_inicial', 'custo_unitario', 'validade'];

/* download do template CSV (cabeçalho + 1 exemplo) */
if (($_GET['acao'] ?? '') === 'template') {
    vero_require('estoque.produtos_insumos');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_produtos_vero.csv"');
    echo "\xEF\xBB\xBF"; // BOM p/ Excel abrir em UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(array_keys(IMP_COLS), IMP_SALDO_COLS), ';');
    /* exemplo com código NUMÉRICO de 6 dígitos (regra da migration 141 —
       o antigo "DEF001" ensinava um formato que o sistema não usa mais).
       ="000001" força o Excel a tratar como TEXTO (senão ele come os zeros
       à esquerda ao abrir — incidente de duplicação de 21/08). */
    fputcsv($out, ['="000001"', 'Exemplo Fungicida', 'Mancozebe', 'defensivo', 'kg', '2,5', 'kg/ha', '14', '7', '10', '4', 'III', 'Fabricante X', 'MAPA-12345', '100', '25,50', ''], ';');
    fclose($out);
    exit;
}

/* normaliza cabeçalho: minúsculo, sem acento, underscores.
   Bug 21/08: o iconv //TRANSLIT (Windows e musl/alpine) vira "ó" em "'o" —
   "Código" normalizava para "c_odigo" e nunca casava. Remove os apóstrofos
   e afins que o TRANSLIT deixa ANTES de trocar o resto por "_". */
$norm = static function (string $s): string {
    $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = str_replace(["'", '`', '^', '~', '"', '´'], '', $s);
    $s = strtolower(trim($s));
    return trim(preg_replace('/[^a-z0-9]+/', '_', $s) ?? '', '_');
};
/* Aliases do EXPORT da listagem de produtos (Código;Produto;Tipo;…): o fluxo
   "exportar → editar → reimportar" é legítimo — mapeia os nomes de exibição
   para as colunas do template. Colunas só-de-leitura do export (saldo, custo
   médio, valor, lotes, status, validade, grupo, estoques mín/máx) NÃO viram
   dados de import: são ignoradas de propósito (saldo lá é agregado, não
   confundir com saldo_inicial do template). */
const IMP_ALIASES = [
    'produto'            => 'nome',
    'tipo'               => 'tipo_insumo',
    'ingrediente_ativo'  => 'ingrediente_ativo',
    'registro_mapa'      => 'registro_mapa',
    /* 21/08 (2ª rodada do export): mínimo/máximo/grupo/status também são
       CADASTRO e atualizam pela planilha. Custo médio e Valor em estoque
       seguem DERIVADOS de movimentação — nunca escritos direto. */
    'estoque_minimo'     => 'estoque_minimo',
    'estoque_maximo'     => 'estoque_maximo',
    'grupo'              => 'grupo',
    'status'             => 'status',
];
$decParse = static function (string $v): ?float {
    $v = trim($v);
    if ($v === '') return null;
    if (str_contains($v, ',')) $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
};

$preview = null;   // [['linha','codigo','nome','acao','msg'], ...]
$resumo  = ['criar' => 0, 'atualizar' => 0, 'erro' => 0];
$aplicado = null;
$ajustarSaldos = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    vero_require('estoque.produtos_insumos.editar');
    $confirmar = ($_POST['confirmar'] ?? '') === '1';
    /* ACERTO DE INVENTÁRIO OPT-IN: o fluxo "exportar →
       editar Saldo → reimportar" só mexe em saldo com este checkbox marcado —
       saldo é resultado de movimentação, nunca sobrescrito em silêncio. Com ele,
       a diferença planilha−atual vira movimentação OFICIAL de ajuste (trilha). */
    $ajustarSaldos = ($_POST['ajustar_saldos'] ?? '') === '1';

    $f = $_FILES['arquivo'] ?? null;
    if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
        vero_flash('erro', 'Selecione um arquivo CSV.');
        vero_redirect();
    }
    if ((int)($f['size'] ?? 0) > 2 * 1024 * 1024) {
        vero_flash('erro', 'Arquivo maior que 2 MB.');
        vero_redirect();
    }
    $raw = (string)file_get_contents((string)$f['tmp_name']);
    /* Excel real-life (bug 21/08): "Salvar como CSV" pode sair UTF-16
       ("CSV Unicode"/"Texto Unicode") — cada letra ganha um byte nulo e o
       cabeçalho virava c_o_d_i_g_o → "Cabeçalho inválido" mesmo usando o
       nosso template. Converte UTF-16 (com/sem BOM) antes de qualquer parse. */
    if (str_starts_with($raw, "\xFF\xFE")) {
        $raw = (string)iconv('UTF-16LE', 'UTF-8//IGNORE', substr($raw, 2));
    } elseif (str_starts_with($raw, "\xFE\xFF")) {
        $raw = (string)iconv('UTF-16BE', 'UTF-8//IGNORE', substr($raw, 2));
    } elseif (str_contains(substr($raw, 0, 200), "\x00")) {
        $raw = (string)iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
    }
    if (str_starts_with($raw, "PK\x03\x04")) { // .xlsx renomeado/enviado direto
        vero_flash('erro', 'Este arquivo é uma planilha Excel (.xlsx). No Excel use Arquivo → Salvar como → "CSV UTF-8 (separado por vírgulas)" e envie o .csv.');
        vero_redirect();
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // tira BOM UTF-8
    /* detecta delimitador na 1ª linha (; , ou TAB — "Texto Unicode" do Excel usa TAB) */
    $primeira = strtok($raw, "\r\n") ?: '';
    $cont = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")];
    arsort($cont);
    $delim = array_key_first($cont);
    if (($cont[$delim] ?? 0) === 0) $delim = ';';

    $linhas = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $linhas = array_values(array_filter($linhas, static fn($l) => trim($l) !== ''));
    if (count($linhas) < 2) {
        vero_flash('erro', 'Planilha vazia — baixe o template, preencha e envie.');
        vero_redirect();
    }
    /* cabeçalho → índice de coluna por nome normalizado */
    $head = str_getcsv($linhas[0], $delim);
    $colIx = [];
    foreach ($head as $i => $h) {
        $chave = $norm((string)$h);
        $chave = IMP_ALIASES[$chave] ?? $chave;    // aceita cabeçalho do EXPORT
        if (!isset($colIx[$chave])) $colIx[$chave] = $i;
    }
    if (!isset($colIx['codigo']) || !isset($colIx['nome'])) {
        vero_flash('erro', 'Cabeçalho inválido — as colunas "codigo" e "nome" (ou "Código"/"Produto" do export) são obrigatórias. Colunas encontradas: '
            . h(implode(', ', array_slice(array_keys($colIx), 0, 8))) . '…  Use o template ou o CSV exportado da listagem.');
        vero_redirect();
    }

    $t = vero_tenant();
    $grupoPadrao = vero_srv_grupo_estoque_padrao();
    /* almox padrão SÓ-LEITURA p/ o preview do acerto (o get-or-create fica no
       confirmar — dry-run não deve criar nada no banco) */
    $almoxPrev = (int)(vero_val("SELECT id FROM almoxarifados WHERE tenant_id=:t AND ativo=1 ORDER BY id LIMIT 1",
        [':t' => $t]) ?: 0);
    $preview = [];
    $paraGravar = [];   // [['id'=>?, 'data'=>[...]], ...]
    $codigosNoArquivo = [];

    foreach (array_slice($linhas, 1) as $n => $linhaTxt) {
        $nLinha = $n + 2; // 1-based + cabeçalho
        $c = str_getcsv($linhaTxt, $delim);
        $get = static fn(string $col) => isset($colIx[$col]) && isset($c[$colIx[$col]]) ? trim((string)$c[$colIx[$col]]) : '';

        /* Código = SEMPRE 6 dígitos numéricos com zeros à esquerda (regra
           permanente da migration 141). O Excel come os zeros ("000123"→"123");
           sem normalizar, o import não achava o produto e DUPLICAVA o cadastro
           (incidente 21/08). Reidrata o zero à esquerda e recusa não-numérico. */
        $codigo = trim($get('codigo'));
        /* aceita o formato ="000123" do próprio template (truque que impede o
           Excel de comer os zeros) e aspas soltas que o Excel às vezes deixa */
        if (preg_match('/^="?([^"]*)"?$/', $codigo, $mCod)) $codigo = trim($mCod[1]);
        $codigo = trim($codigo, '"');
        $nome   = mb_substr($get('nome'), 0, 150);
        if ($codigo === '' || $nome === '') {
            $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro', 'msg' => 'código e nome são obrigatórios'];
            $resumo['erro']++; continue;
        }
        if (!preg_match('/^\d{1,6}$/', $codigo)) {
            $preview[] = ['linha' => $nLinha, 'codigo' => mb_substr($codigo, 0, 12), 'nome' => $nome, 'acao' => 'erro',
                'msg' => 'código deve ser numérico de até 6 dígitos (o sistema completa os zeros à esquerda)'];
            $resumo['erro']++; continue;
        }
        $codigo = str_pad($codigo, 6, '0', STR_PAD_LEFT);
        if (isset($codigosNoArquivo[$codigo])) {
            $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro', 'msg' => 'código repetido na planilha (linha ' . $codigosNoArquivo[$codigo] . ')'];
            $resumo['erro']++; continue;
        }
        $codigosNoArquivo[$codigo] = $nLinha;

        /* 21/08: monta $data SÓ com as colunas PRESENTES no arquivo — o export
           da listagem não traz doses/carências, e o update apagaria esses
           campos dos produtos existentes se entrassem como NULL. */
        $temCol = static fn(string $col): bool => isset($colIx[$col]);

        $tipo = null;
        if ($temCol('tipo_insumo')) {
            $tipo = $norm($get('tipo_insumo'));      // "Produto agrícola" do export → produto_agricola
            if (!in_array($tipo, IMP_TIPOS, true)) $tipo = 'defensivo';
        }

        $data = ['nome' => $nome];
        if ($temCol('ingrediente_ativo'))       $data['ingrediente_ativo'] = mb_substr($get('ingrediente_ativo'), 0, 150) ?: null;
        if ($tipo !== null)                     $data['tipo_insumo'] = $tipo;
        if ($temCol('unidade')) { $unid = $get('unidade'); $data['unidade'] = in_array($unid, IMP_UNIDS, true) ? $unid : 'kg'; }
        if ($temCol('dose_referencia'))         $data['dose_referencia'] = $decParse($get('dose_referencia'));
        if ($temCol('dose_referencia_unidade')) $data['dose_referencia_unidade'] = mb_substr($get('dose_referencia_unidade'), 0, 12) ?: null;
        if ($temCol('classe_toxicologica'))     $data['classe_toxicologica'] = mb_substr($get('classe_toxicologica'), 0, 40) ?: null;
        if ($temCol('fabricante'))              $data['fabricante'] = mb_substr($get('fabricante'), 0, 120) ?: null;
        if ($temCol('registro_mapa'))           $data['registro_mapa'] = mb_substr($get('registro_mapa'), 0, 40) ?: null;
        foreach (IMP_INT as $ic) {
            if (!$temCol($ic)) continue;
            $v = $get($ic);
            $data[$ic] = ($v !== '' && ctype_digit(ltrim($v, '-')) && (int)$v >= 0) ? (int)$v : null;
        }
        /* 21/08 (2ª rodada do export): mínimo/máximo/grupo/status também
           atualizam pela planilha (são cadastro). Custo médio NÃO — é derivado
           das movimentações (para corrigi-lo, use o acerto de saldo com
           custo_unitario ou uma entrada valorizada). */
        if ($temCol('estoque_minimo')) $data['estoque_minimo'] = $decParse($get('estoque_minimo'));
        if ($temCol('estoque_maximo')) $data['estoque_maximo'] = $decParse($get('estoque_maximo'));
        if ($temCol('grupo') && trim($get('grupo')) !== '') {
            $gNome = trim($get('grupo'));
            $gId = vero_val("SELECT id FROM estoque_grupos WHERE tenant_id=:t AND nome=:n", [':t' => $t, ':n' => $gNome]);
            if ($gId) {
                $data['grupo_id'] = (int)$gId;
            } else {
                /* grupo desconhecido NÃO cria nem apaga — avisa e mantém o atual */
                $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'aviso',
                    'msg' => 'grupo "' . mb_substr($gNome, 0, 40) . '" não existe — mantido o grupo atual', 'saldo' => ''];
            }
        }
        if ($temCol('status') && trim($get('status')) !== '') {
            $st = $norm($get('status'));
            if (in_array($st, ['ativo', 'inativo'], true)) $data['ativo'] = $st === 'ativo' ? 1 : 0;
        }

        /* 21/08: além do id, traz flags de lote/validade e a unidade — o seed do
           saldo inicial e o acerto opt-in usam sem re-consultar por linha */
        $existe = vero_row("SELECT id, unidade, COALESCE(controla_validade,0) AS controla_validade,
                                   COALESCE(controla_lote,0) AS controla_lote
                              FROM estoque_produtos WHERE tenant_id=:t AND codigo=:c", [':t' => $t, ':c' => $codigo]);
        if (!$existe) {
            /* produto NOVO sem as colunas no arquivo → defaults do formulário */
            if ($tipo === null) { $tipo = 'defensivo'; $data['tipo_insumo'] = 'defensivo'; }
            if (!isset($data['unidade'])) $data['unidade'] = 'kg';
        }

        /* A-06/D-3: saldo inicial VALORIZADO (opcional). Regras (nunca sobrescreve
           saldo existente em silêncio): só semeia quando qtd>0, custo informado e o
           produto AINDA não tem saldo; caso contrário mostra o motivo no preview. */
        $saldoIni    = $decParse($get('saldo_inicial'));
        $custoIni    = $decParse($get('custo_unitario'));
        /* validade: aceita AAAA-MM-DD ou DD/MM/AAAA e valida o CALENDÁRIO (checkdate) —
           sem isso "31/13/2026" passaria no check de formato do service e, com sql_mode
           não-strict, viraria '0000-00-00' no lote. Data inválida → erro na linha. */
        $validadeIni = null;
        $vRaw = $get('validade');
        if ($vRaw !== '') {
            $dia = $mes = $ano = null;
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $vRaw, $m))     { $ano = (int)$m[1]; $mes = (int)$m[2]; $dia = (int)$m[3]; }
            elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $vRaw, $m)) { $dia = (int)$m[1]; $mes = (int)$m[2]; $ano = (int)$m[3]; }
            if ($ano !== null && checkdate((int)$mes, (int)$dia, (int)$ano)) {
                $validadeIni = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
            } else {
                $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                    'msg' => 'data de validade inválida (use AAAA-MM-DD ou DD/MM/AAAA)', 'saldo' => ''];
                $resumo['erro']++; continue;
            }
        }
        $saldoSeed   = null;   // ['qtd','custo','validade'] quando for semear
        $saldoAjuste = null;   // ['delta','custo'] — acerto de inventário opt-in (21/08)
        $saldoMsg    = '';
        $viraAjuste  = false;  // linha consumida pelo acerto → o seed do saldo_inicial não roda

        /* ACERTO DE INVENTÁRIO (opt-in): com o checkbox marcado, a coluna "Saldo"
           do export (ou "saldo_inicial" quando o produto JÁ tem saldo) vira saldo
           ALVO — a diferença sai como movimentação oficial no almox padrão
           (entrada com custo / saída ao custo médio), nunca escrita direta em
           estoque_saldos. Sem o checkbox, comportamento histórico intacto. */
        if ($ajustarSaldos && $existe) {
            $alvo = null;
            if ($temCol('saldo') && $get('saldo') !== '') {
                $viraAjuste = true;
                $alvo = $decParse($get('saldo'));
                if ($alvo === null) {
                    $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                        'msg' => 'coluna "Saldo" com valor inválido para o acerto de inventário', 'saldo' => ''];
                    $resumo['erro']++; continue;
                }
            }
            /* saldo atual + valor numa leitura só (SUM do tenant, todos os almox) */
            $sAt = vero_row("SELECT COALESCE(SUM(quantidade),0) AS q, COALESCE(SUM(valor_total),0) AS v
                               FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p",
                [':t' => $t, ':p' => (int)$existe['id']]);
            $saldoAtual = (float)$sAt['q'];
            if ($alvo === null && $saldoIni !== null && $saldoAtual > 0.0001) {
                /* template com saldo_inicial em produto que JÁ tem saldo: sem o
                   checkbox seria "ignorado — já possui saldo"; com ele o usuário
                   está pedindo o acerto pela planilha. */
                $viraAjuste = true;
                $alvo = $saldoIni;
            }
            if ($viraAjuste) {
                if ((int)$existe['controla_lote'] === 1 || (int)$existe['controla_validade'] === 1) {
                    /* lote/validade exigem apontar O lote certo — acerto em massa não
                       tem essa informação; a tela de estoque (por lote) resolve. */
                    $saldoMsg = 'ajuste ignorado — produto controla lote/validade (ajuste pelo estoque)';
                } elseif ($alvo < 0) {
                    $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                        'msg' => 'saldo da planilha não pode ser negativo', 'saldo' => ''];
                    $resumo['erro']++; continue;
                } else {
                    $dif  = round($alvo - $saldoAtual, 4);
                    $unid = (string)($existe['unidade'] ?? '');
                    if (abs($dif) < 0.001) {
                        $saldoMsg = 'saldo confere — sem ajuste';
                    } elseif ($dif > 0) {
                        /* custo da entrada: custo_unitario da planilha > custo médio
                           atual > 0 com aviso (produto nunca valorizado) */
                        $custoMedio = $saldoAtual > 1e-9 ? round((float)$sAt['v'] / $saldoAtual, 4) : 0.0;
                        $custoAj = ($custoIni !== null && $custoIni >= 0) ? $custoIni : $custoMedio;
                        $saldoAjuste = ['delta' => $dif, 'custo' => $custoAj];
                        $saldoMsg = 'ajuste +' . numFmt($dif, 2) . ' ' . $unid
                                  . ($custoAj <= 0 ? ' — atenção: entrada a custo 0 (informe custo_unitario p/ valorizar)' : '');
                    } else {
                        /* capacidade medida JÁ no preview p/ o erro sair na linha; o
                           service ainda bloqueia no confirmar (guard de concorrência) */
                        $saldoAlmox = $almoxPrev > 0
                            ? (float)vero_val("SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos
                                                WHERE tenant_id=:t AND produto_id=:p AND almoxarifado_id=:a",
                                [':t' => $t, ':p' => (int)$existe['id'], ':a' => $almoxPrev])
                            : 0.0;
                        if ($saldoAlmox + 1e-9 < -$dif) {
                            $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                                'msg' => 'saída de ajuste (' . numFmt(-$dif, 2) . ') maior que o saldo do almoxarifado padrão ('
                                       . numFmt($saldoAlmox, 2) . ') — ajuste pelo estoque', 'saldo' => ''];
                            $resumo['erro']++; continue;
                        }
                        $saldoAjuste = ['delta' => $dif, 'custo' => null];
                        $saldoMsg = 'ajuste −' . numFmt(-$dif, 2) . ' ' . $unid . ' (saída)';
                    }
                }
            }
        }

        if (!$viraAjuste && $saldoIni !== null && $saldoIni > 0) {
            if ($custoIni === null || $custoIni < 0) {
                $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                    'msg' => 'saldo_inicial informado sem custo_unitario válido (informe o valor — pode ser 0)', 'saldo' => ''];
                $resumo['erro']++; continue;
            }
            $saldoExistente = $existe ? (float)vero_val(
                "SELECT COALESCE(SUM(quantidade),0) FROM estoque_saldos WHERE tenant_id=:t AND produto_id=:p",
                [':t' => $t, ':p' => (int)$existe['id']]) : 0.0;
            if ($saldoExistente > 0.0001) {
                $saldoMsg = 'ignorado — produto já possui saldo (' . numFmt($saldoExistente, 2) . ')';
            } else {
                $precisaVal = false;
                if ($existe) {
                    $precisaVal = ((int)$existe['controla_validade'] === 1 || (int)$existe['controla_lote'] === 1);
                } else {
                    /* produto NOVO: defensivo controla validade (mesma regra do formulário,
                       estoque/produtos.php L147) — logo exige validade no saldo inicial. */
                    $precisaVal = ($tipo === 'defensivo');
                }
                if ($precisaVal && $validadeIni === null) {
                    $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'erro',
                        'msg' => 'produto controla validade/lote — informe a coluna "validade" para o saldo inicial', 'saldo' => ''];
                    $resumo['erro']++; continue;
                } else {
                    $saldoSeed = ['qtd' => (float)$saldoIni, 'custo' => (float)$custoIni, 'validade' => $validadeIni];
                    $saldoMsg  = 'entrada ' . numFmt((float)$saldoIni, 2) . ' @ R$ ' . numFmt((float)$custoIni, 2);
                }
            }
        }

        if ($existe) {
            $paraGravar[] = ['id' => (int)$existe['id'], 'codigo' => $codigo, 'data' => $data, 'saldo' => $saldoSeed, 'ajuste' => $saldoAjuste];
            $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'atualizar', 'msg' => 'produto existente', 'saldo' => $saldoMsg];
            $resumo['atualizar']++;
        } else {
            $data['codigo']   = $codigo;
            /* grupo/status da planilha (2ª rodada 21/08) têm prioridade no novo */
            if (!isset($data['grupo_id'])) $data['grupo_id'] = $grupoPadrao;
            if (!isset($data['ativo']))    $data['ativo']    = 1;
            /* A-06/D-3: defensivo NOVO nasce controlando validade (mesma regra do
               formulário) — coerente com o gate de validade do saldo inicial acima. */
            if ($tipo === 'defensivo') $data['controla_validade'] = 1;
            $paraGravar[] = ['id' => null, 'codigo' => $codigo, 'data' => $data, 'saldo' => $saldoSeed, 'ajuste' => null];
            $preview[] = ['linha' => $nLinha, 'codigo' => $codigo, 'nome' => $nome, 'acao' => 'criar', 'msg' => 'novo produto', 'saldo' => $saldoMsg];
            $resumo['criar']++;
        }
    }

    if ($confirmar && $resumo['erro'] === 0 && $paraGravar) {
        $pdo = vero_pdo();
        $pdo->beginTransaction();
        try {
            $almoxPadrao = vero_srv_almox_padrao();  /* A-06/D-3: saldo inicial vai p/ o almox padrão */
            $dataImpl    = date('Y-m-d');
            $seedCount   = 0;
            $ajusteCount = 0;
            foreach ($paraGravar as $g) {
                if ($g['id']) { vero_update('estoque_produtos', $g['id'], $g['data']); $prodId = (int)$g['id']; }
                else          { $prodId = (int)vero_insert('estoque_produtos', $g['data']); }
                /* saldo inicial valorizado → ENTRADA oficial (custo médio ponderado,
                   trilha em estoque_movimentacoes, origem "implantacao"). */
                if (!empty($g['saldo']) && $prodId > 0) {
                    vero_srv_estoque_entrada($prodId, $almoxPadrao,
                        (float)$g['saldo']['qtd'], (float)$g['saldo']['custo'], $dataImpl,
                        'implantacao', null, 'Saldo inicial (importação em massa)', $g['saldo']['validade'] ?? null);
                    $seedCount++;
                }
                /* acerto de inventário opt-in (21/08) → movimentação oficial pela
                   DIFERENÇA (mesmo padrão do modal Saldo Inicial): entrada com custo /
                   saída ao custo médio-FEFO, origem 'ajuste_importacao' na trilha. */
                if (!empty($g['ajuste']) && $prodId > 0) {
                    try {
                        if ((float)$g['ajuste']['delta'] > 0) {
                            vero_srv_estoque_entrada($prodId, $almoxPadrao, (float)$g['ajuste']['delta'],
                                (float)$g['ajuste']['custo'], $dataImpl,
                                'ajuste_importacao', null, 'Acerto de inventário (importação de planilha)');
                        } else {
                            /* saldo insuficiente → a service LANÇA (nada de forçar) e a
                               transação inteira reverte com o produto identificado */
                            vero_srv_estoque_saida($prodId, $almoxPadrao, -(float)$g['ajuste']['delta'], $dataImpl,
                                'ajuste_importacao', null, 'Acerto de inventário (importação de planilha)');
                        }
                    } catch (RuntimeException $e) {
                        throw new RuntimeException('Produto ' . $g['codigo'] . ': ' . $e->getMessage());
                    }
                    $ajusteCount++;
                }
            }
            $pdo->commit();
            vero_flash('ok', "Importação concluída: {$resumo['criar']} criado(s), {$resumo['atualizar']} atualizado(s)"
                . ($seedCount ? ", {$seedCount} com saldo inicial valorizado" : '')
                . ($ajusteCount ? ", {$ajusteCount} saldo(s) acertado(s) pela planilha" : '') . '.');
            vero_redirect(BIOS_BASE . '/estoque/produtos');
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Falha ao gravar: ' . h($e->getMessage()));
            vero_redirect();
        }
    }
    /* sem confirmar (ou com erro) → cai no preview abaixo */
    if ($confirmar && $resumo['erro'] > 0) {
        vero_flash('erro', 'Há linhas com erro — corrija a planilha antes de confirmar. Nada foi gravado.');
    }
}

$GUARD      = ['macro' => 'estoque', 'micro' => 'produtos_insumos'];
$PAGE_VIEW  = 'estoque_produtos_insumos';
$PAGE_TITLE = 'Importar produtos';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';
$podeEditar = vero_can('estoque.produtos_insumos.editar');
$base = BIOS_BASE;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>
  <?= vero_page_header('Importar produtos', 'Carga em massa por planilha CSV — valida antes de gravar (dry-run)') ?>

  <div class="vcard" style="padding:16px 18px">
    <ol style="margin:0 0 12px 18px;font-size:13px;line-height:1.6">
      <li>Baixe o <a href="?acao=template"><strong>template CSV</strong></a> (colunas: código, nome, molécula, tipo, unidade, dose, unidade da dose, LMR, carência, intervalo, nº máx. aplicações, classe toxicológica, fabricante, registro MAPA e — opcionais — <strong>saldo_inicial</strong>, <strong>custo_unitario</strong>, <strong>validade</strong>).</li>
      <li>Preencha no Excel/LibreOffice e salve como <strong>CSV</strong> (separador <code>;</code> ou <code>,</code>).</li>
      <li>Envie abaixo: o sistema mostra o que será <strong>criado/atualizado</strong> e os erros — nada é gravado até você <strong>confirmar</strong>.</li>
    </ol>
    <p class="vhint" style="margin:0 0 12px">Casa por <strong>código</strong>: existente → atualiza; novo → cria. Grupo de estoque = padrão da fazenda.
      Preencha <strong>saldo_inicial</strong> + <strong>custo_unitario</strong> para já valorizar o estoque (custo médio ≠ 0): vira uma entrada oficial (origem “implantação”) no almoxarifado padrão. Produtos que <strong>já têm saldo</strong> são ignorados nessa coluna — nada é sobrescrito. Se o produto controla validade/lote, informe também <strong>validade</strong> (aceita <code>AAAA-MM-DD</code> ou <code>DD/MM/AAAA</code>).
      Para <strong>acertar saldos</strong> pela planilha (fluxo exportar → editar a coluna “Saldo” → reimportar), marque a opção abaixo: a diferença vira movimentação oficial de <strong>ajuste</strong> no almoxarifado padrão (entrada ao custo médio — ou ao <code>custo_unitario</code> da planilha, se preenchido — e saída ao custo médio). Produtos que controlam <strong>lote/validade</strong> não são ajustados por aqui (use a tela de estoque).</p>

    <?php if ($podeEditar): ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="file" name="arquivo" accept=".csv,text/csv" required class="map-file">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px"
             title="Com esta opção, a coluna Saldo do export (ou saldo_inicial em produto que já tem saldo) vira o saldo ALVO: a diferença gera entrada/saída de ajuste no almoxarifado padrão. Desmarcada, a coluna de saldo é ignorada como sempre.">
        <input type="checkbox" name="ajustar_saldos" value="1"<?= $ajustarSaldos ? ' checked' : '' ?>> Ajustar saldos pela planilha (gera movimentações de ajuste)
      </label>
      <label style="display:flex;align-items:center;gap:6px;font-size:13px">
        <input type="checkbox" name="confirmar" value="1"> Confirmar gravação (sem marcar = só pré-visualiza)
      </label>
      <button type="submit" class="vbtn vbtn-primary vbtn-sm">Enviar</button>
      <a class="vbtn vbtn-ghost vbtn-sm" href="<?= $base ?>/estoque/produtos.php">← Voltar aos produtos</a>
    </form>
    <?php else: ?>
      <div class="vhint">Você não tem permissão de edição para importar produtos.</div>
    <?php endif; ?>
  </div>

  <?php if ($preview !== null): ?>
  <div class="vcard" style="margin-top:14px">
    <div class="vtoolbar">
      <span class="vsub"><strong><?= $resumo['criar'] ?></strong> a criar</span>
      <span class="vsub"><strong><?= $resumo['atualizar'] ?></strong> a atualizar</span>
      <span class="vsub" style="color:<?= $resumo['erro'] ? '#B3402A' : 'inherit' ?>"><strong><?= $resumo['erro'] ?></strong> com erro</span>
      <span class="vsub" style="margin-left:auto">
        <?= $resumo['erro'] ? 'Corrija os erros e reenvie com “Confirmar”.'
            : 'Sem erros — reenvie o arquivo com “Confirmar gravação” marcado' . ($ajustarSaldos ? ' (mantenha “Ajustar saldos” marcado)' : '') . ' para gravar.' ?>
      </span>
    </div>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr><th>Linha</th><th>Código</th><th>Nome</th><th>Ação</th><th>Saldo / ajuste</th><th>Obs.</th></tr></thead>
      <tbody>
      <?php foreach ($preview as $p):
        $cor = $p['acao'] === 'erro' ? '#B3402A' : ($p['acao'] === 'criar' ? '#1E6B34' : '#8A6D1F');
        $saldoTxt = (string)($p['saldo'] ?? '');
        /* âmbar p/ "ignorado…"/"ajuste ignorado…" (nada será feito), verde p/ ação */
        $saldoCor = (str_starts_with($saldoTxt, 'ignorado') || str_starts_with($saldoTxt, 'ajuste ignorado')) ? '#8A6D1F' : '#1E6B34'; ?>
        <tr>
          <td class="vnum"><?= (int)$p['linha'] ?></td>
          <td><strong><?= h((string)$p['codigo']) ?></strong></td>
          <td><?= h((string)$p['nome']) ?></td>
          <td><strong style="color:<?= $cor ?>"><?= h(ucfirst((string)$p['acao'])) ?></strong></td>
          <td class="vhint"<?= $saldoTxt !== '' ? ' style="color:' . $saldoCor . '"' : '' ?>><?= $saldoTxt !== '' ? h($saldoTxt) : '—' ?></td>
          <td class="vhint"><?= h((string)$p['msg']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/agro_footer_simple.php';
