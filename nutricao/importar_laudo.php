<?php
/* ============================================================
   VERO — Nutrição / Importar Laudo (IA)  (tela real — Seção 8)
   Rota da matriz: /nutricao/importar_laudo.php
   Guard: nutricao.importar_laudo
   Fluxo: upload PDF → API Anthropic (chave em .env ANTHROPIC_API_KEY,
   nunca hardcoded) → agro_ia_extracoes (pendente) → REVISÃO HUMANA
   OBRIGATÓRIA com campos editáveis → confirmar → analise_* com
   origem='ia' + ia_extracao_id → classificação e alertas.
   Fallback sempre disponível: digitação manual nas telas de análise.
   Teto de custo (R$ 600/mês): controle pendente de implementação —
   cada extração registra o modelo usado para auditoria.
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vero_crud.php';
require_once __DIR__ . '/../includes/vero_services.php';

const IA_MODELO   = 'claude-haiku-4-5-20251001'; // Anthropic (PDF ou imagem)
const IA_MODELO_VISAO_PADRAO = 'gpt-4o-mini'; // OpenAI (visão) — PDF (file) ou imagem
const IA_MAX_PDF  = 5242880;
const IA_EXTENSOES = ['pdf', 'jpg', 'jpeg', 'png', 'webp']; // ambos os provedores leem PDF e imagem
/* Teto D6: R$ 600/mês por tenant. Sem coluna de custo por extração, o
   controle usa ESTIMATIVA conservadora por chamada (Haiku + PDF típico
   ≈ R$ 0,05; usamos 0,50 de margem). Refinar com custo real na fase 2. */
const IA_TETO_MENSAL_BRL      = 600.0;
const IA_CUSTO_ESTIMADO_BRL   = 0.50;

/** Extrações do tenant no mês corrente e custo estimado. */
function ia_uso_mensal(): array
{
    $qtd = (int)vero_val(
        "SELECT COUNT(*) FROM agro_ia_extracoes
          WHERE tenant_id = :t AND origem_tipo IN ('analise_solo','analise_foliar')
            AND created_at >= :ini",
        [':t' => vero_tenant(), ':ini' => date('Y-m-01 00:00:00')]);
    return ['qtd' => $qtd, 'custo' => $qtd * IA_CUSTO_ESTIMADO_BRL];
}

/** Lê variável do ambiente OU do arquivo .env da RAIZ (localhost não popula
 *  $_ENV/getenv a partir do .env — mesmo padrão do ia_env() em api/v1/rotas/ia.php). */
function ia_env_raw(string $nome): string
{
    $v = trim((string)($_ENV[$nome] ?? getenv($nome) ?: ''));
    if ($v !== '') return $v;
    $env = dirname(__DIR__) . '/.env'; // raiz do projeto (vero/.env)
    if (is_file($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $l = trim($l);
            if (str_starts_with($l, $nome . '=')) {
                return trim(substr($l, strlen($nome) + 1), " \t\"'");
            }
        }
    }
    return '';
}

function ia_api_key(): ?string
{
    $k = ia_env_raw('ANTHROPIC_API_KEY');
    return $k !== '' ? $k : null;
}

/** Chave do LAUDO (OpenAI ou compatível). Própria (IA_LAUDO_API_KEY) para não
 *  colidir com o OPENAI_API_KEY do chat Groq no servidor; cai nela se não houver. */
function ia_groq_key(): ?string
{
    $k = ia_env_raw('IA_LAUDO_API_KEY');
    if ($k === '') $k = ia_env_raw('OPENAI_API_KEY');
    return $k !== '' ? $k : null;
}
function ia_base_url(): string
{
    /* Endpoint do LAUDO — desacoplado do IA_BASE_URL global (que aponta o chat p/
       Groq). Default OpenAI; override só via IA_LAUDO_BASE_URL se quiser outro. */
    $u = ia_env_raw('IA_LAUDO_BASE_URL');
    return $u !== '' ? rtrim($u, '/') : 'https://api.openai.com/v1';
}
function ia_modelo_visao(): string
{
    $m = ia_env_raw('OPENAI_MODELO_VISAO');
    return $m !== '' ? $m : IA_MODELO_VISAO_PADRAO;
}
/** Provedor de IA disponível: 'anthropic' (PDF+imagem) > 'groq' (imagem) > null. */
function ia_provedor(): ?string
{
    if (ia_api_key() !== null)  return 'anthropic';
    if (ia_groq_key() !== null) return 'groq';
    return null;
}
function ia_mime_de_ext(string $ext): string
{
    return ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
}
function ia_prompt_laudo(string $tipo): string
{
    $contexto = $tipo === 'solo'
        ? 'análise de solo (profundidade, pH, MO, P, K, Ca, Mg, S, Al, CTC, V%, micronutrientes)'
        : 'análise foliar (parte da folha, N, P, K, Ca, Mg, S, micronutrientes)';
    return "Extraia deste laudo de {$contexto} um JSON com exatamente esta estrutura, sem texto fora do JSON:\n"
        . '{"laboratorio": string|null, "data": "AAAA-MM-DD"|null, "talhao": string|null, '
        . ($tipo === 'solo' ? '"profundidade": string|null, ' : '"parte_folha": "limbo"|"peciolo"|"folha_inteira"|null, ')
        . '"resultados": [{"nutriente": string, "simbolo": string|null, "valor": number, "unidade": string|null}], '
        . '"confianca": number entre 0 e 1 refletindo a legibilidade do laudo}'
        . "\nUse ponto como separador decimal. Não invente valores: omita nutrientes ilegíveis e reduza a confiança.";
}
/** POST HTTP genérico p/ as APIs de IA. Devolve ['body','http']. */
function ia_http_post(string $url, string $payload, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true, // P-8
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    $erro = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Falha de rede na chamada à IA: ' . $erro);
    return ['body' => (string)$body, 'http' => $http];
}
/** Extrai o JSON esperado do texto devolvido pela IA (tolera cerca de código). */
function ia_parse_resultado(string $texto): array
{
    if (preg_match('/\{.*\}/s', $texto, $m)) $texto = $m[0];
    $json = json_decode($texto, true);
    if (!is_array($json) || !isset($json['resultados']) || !is_array($json['resultados'])) {
        throw new RuntimeException('A IA não retornou o JSON esperado — revise o laudo ou digite manualmente.');
    }
    $json['_texto_bruto'] = $texto;
    return $json;
}

/** Dispatcher da extração: escolhe o provedor pela chave configurada.
 *  Anthropic (PDF ou imagem) tem prioridade; senão OpenAI (SÓ imagem). */
function ia_extrair_laudo(string $caminho, string $tipo, string $ext): array
{
    $prov = ia_provedor();
    if ($prov === null) {
        throw new RuntimeException('Nenhuma chave de IA no .env (OPENAI_API_KEY p/ OpenAI ou ANTHROPIC_API_KEY) — use a digitação manual até configurar.');
    }
    if ($prov === 'anthropic') return ia_extrair_anthropic($caminho, $tipo, $ext);
    return ia_extrair_groq($caminho, $tipo, $ext);
}

/** Anthropic (Claude): aceita PDF (bloco document) ou imagem (bloco image). */
function ia_extrair_anthropic(string $caminho, string $tipo, string $ext): array
{
    $key = (string)ia_api_key();
    $mime = ia_mime_de_ext($ext);
    $b64  = base64_encode((string)file_get_contents($caminho));
    $bloco = $ext === 'pdf'
        ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
        : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
    $payload = json_encode([
        'model'      => IA_MODELO,
        'max_tokens' => 2048,
        'messages'   => [['role' => 'user', 'content' => [$bloco, ['type' => 'text', 'text' => ia_prompt_laudo($tipo)]]]],
    ], JSON_UNESCAPED_UNICODE);
    $r = ia_http_post('https://api.anthropic.com/v1/messages', (string)$payload, [
        'Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01',
    ]);
    $dados = json_decode($r['body'], true);
    if ($r['http'] !== 200) {
        throw new RuntimeException('API da IA (Anthropic) retornou HTTP ' . $r['http'] . ': ' . ($dados['error']['message'] ?? 'erro desconhecido'));
    }
    $texto = '';
    foreach (($dados['content'] ?? []) as $bloco) {
        if (($bloco['type'] ?? '') === 'text') $texto .= (string)($bloco['text'] ?? '');
    }
    return ia_parse_resultado($texto);
}

/** OpenAI (OpenAI-compatível, visão): imagem via image_url; PDF via content
 *  part type=file (file_data data URI) — suportado pelo gpt-4o/gpt-4o-mini. */
function ia_extrair_groq(string $caminho, string $tipo, string $ext): array
{
    $key = (string)ia_groq_key();
    $b64  = base64_encode((string)file_get_contents($caminho));
    if ($ext === 'pdf') {
        $anexo = ['type' => 'file', 'file' => [
            'filename'  => 'laudo.pdf',
            'file_data' => 'data:application/pdf;base64,' . $b64,
        ]];
    } else {
        $anexo = ['type' => 'image_url', 'image_url' => [
            'url' => 'data:' . ia_mime_de_ext($ext) . ';base64,' . $b64,
        ]];
    }
    $payload = json_encode([
        'model'       => ia_modelo_visao(),
        'temperature' => 0,
        'max_tokens'  => 2048,
        'messages'    => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => ia_prompt_laudo($tipo)],
            $anexo,
        ]]],
    ], JSON_UNESCAPED_UNICODE);
    $r = ia_http_post(ia_base_url() . '/chat/completions', (string)$payload, [
        'Content-Type: application/json', 'Authorization: Bearer ' . $key,
    ]);
    $dados = json_decode($r['body'], true);
    if ($r['http'] !== 200) {
        throw new RuntimeException('API da IA (OpenAI) retornou HTTP ' . $r['http'] . ': ' . ($dados['error']['message'] ?? 'erro desconhecido'));
    }
    return ia_parse_resultado((string)($dados['choices'][0]['message']['content'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'extrair') {
        vero_require('nutricao.importar_laudo.editar');

        $uso = ia_uso_mensal();
        if ($uso['custo'] + IA_CUSTO_ESTIMADO_BRL > IA_TETO_MENSAL_BRL) {
            vero_flash('erro', 'Teto mensal de custo de IA atingido (estimativa R$ '
                . numFmt($uso['custo'], 2) . ' de R$ ' . numFmt(IA_TETO_MENSAL_BRL, 2)
                . ') — use a digitação manual ou o CSV até o próximo mês.');
            vero_redirect();
        }

        $tipo = ($_POST['tipo'] ?? '') === 'foliar' ? 'foliar' : 'solo';
        $file = $_FILES['laudo'] ?? null;
        $ext  = $file ? strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) : '';

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !in_array($ext, IA_EXTENSOES, true)
            || (int)$file['size'] > IA_MAX_PDF) {
            vero_flash('erro', 'Envie um PDF ou imagem (JPG/PNG/WEBP) de até ' . round(IA_MAX_PDF / 1048576, 1) . ' MB.');
            vero_redirect();
        }
        /* valida o CONTEÚDO real (não confia na extensão) */
        $mimeReal   = function_exists('mime_content_type') ? (string)(mime_content_type((string)$file['tmp_name']) ?: '') : '';
        $conteudoOk = $ext === 'pdf' ? ($mimeReal === 'application/pdf') : (@getimagesize((string)$file['tmp_name']) !== false);
        if (!$conteudoOk) {
            vero_flash('erro', 'O arquivo não confere com o tipo (PDF ou imagem válida).');
            vero_redirect();
        }
        $dir = dirname(__DIR__) . '/storage/uploads/laudos/' . vero_tenant();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $nomeFisico = 'laudo_' . $tipo . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $destino = $dir . '/' . $nomeFisico;
        if (!move_uploaded_file((string)$file['tmp_name'], $destino)) {
            vero_flash('erro', 'Falha ao gravar o arquivo no servidor.');
            vero_redirect();
        }
        $anexoId = vero_insert('agro_anexos', [
            'origem_tipo'   => 'laudo_nutricional',
            'origem_id'     => 0,
            'tipo_arquivo'  => 'laudo_' . $tipo,
            'nome_original' => mb_substr((string)$file['name'], 0, 255),
            'url'           => '/storage/uploads/laudos/' . vero_tenant() . '/' . $nomeFisico,
            'tamanho_bytes' => (int)$file['size'],
            'hash_sha256'   => hash_file('sha256', $destino),
        ]);

        try {
            $json = ia_extrair_laudo($destino, $tipo, $ext);
        } catch (Throwable $e) {
            vero_flash('erro', 'Extração falhou: ' . h($e->getMessage()));
            vero_redirect();
        }
        $textoBruto = (string)($json['_texto_bruto'] ?? '');
        unset($json['_texto_bruto']);
        $extId = vero_insert('agro_ia_extracoes', [
            'origem_tipo'    => 'analise_' . $tipo,
            'anexo_id'       => $anexoId,
            'modelo'         => ia_provedor() === 'groq' ? ia_modelo_visao() : IA_MODELO,
            'texto_original' => $textoBruto,
            'json_extraido'  => json_encode($json, JSON_UNESCAPED_UNICODE),
            'confianca'      => isset($json['confianca']) ? max(0, min(1, (float)$json['confianca'])) : null,
            'status_revisao' => 'pendente',
        ]);
        vero_flash('ok', 'Laudo extraído pela IA — revise TODOS os campos antes de confirmar (revisão humana obrigatória).');
        vero_redirect(BIOS_BASE . '/nutricao/importar_laudo?rev=' . $extId);
    }

    if ($acao === 'confirmar') {
        vero_require('nutricao.importar_laudo.editar');
        $extId = vero_int('extracao_id');
        $ext = $extId ? vero_row("SELECT * FROM agro_ia_extracoes WHERE id=:i AND tenant_id=:t AND status_revisao='pendente'",
            [':i' => $extId, ':t' => vero_tenant()]) : null;
        if (!$ext) {
            vero_flash('erro', 'Extração inválida ou já revisada.');
            vero_redirect(BIOS_BASE . '/nutricao/importar_laudo');
        }
        $tipo = $ext['origem_tipo'] === 'analise_foliar' ? 'foliar' : 'solo';
        $tabelaA = 'analise_' . $tipo;
        $tabelaR = $tabelaA . '_resultados';

        $data = vero_date('data_amostra');
        if ($data === null) {
            vero_flash('erro', 'Informe a data da amostra.');
            vero_redirect(BIOS_BASE . '/nutricao/importar_laudo?rev=' . $extId);
        }
        $talhaoId = vero_int('talhao_id');
        $fazendaId = null;
        if ($talhaoId) {
            $talhao = vero_row("SELECT * FROM agro_talhoes WHERE id=:i AND tenant_id=:t",
                [':i' => $talhaoId, ':t' => vero_tenant()]);
            if ($talhao) $fazendaId = (int)$talhao['fazenda_id']; else $talhaoId = null;
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
            $cab = [
                'fazenda_id'    => $fazendaId,
                'talhao_id'     => $talhaoId,
                'safra_id'      => vero_fk_tenant('agro_safras', vero_int('safra_id')), // A-5
                'data_amostra'  => $data,
                'origem'        => 'ia',
                'ia_extracao_id'=> $extId,
                'status'        => 'validado',
                'observacao'    => vero_str('observacao', 255),
            ];
            if ($tipo === 'solo') $cab['profundidade'] = vero_str('profundidade', 20);
            else {
                $parte = vero_str('parte_folha', 20);
                $cab['parte_folha'] = in_array($parte, ['limbo', 'peciolo', 'folha_inteira'], true) ? $parte : null;
            }
            $anlId = vero_insert($tabelaA, $cab);

            $qtd = 0;
            foreach ($rNutr as $ix => $nutrId) {
                $valor = $parseDec($rValor[$ix] ?? '');
                $nutrId = (int)$nutrId;
                if ($valor === null || !$nutrId) continue;
                $okN = vero_val("SELECT id FROM analise_nutrientes WHERE id=:i AND tenant_id=:t",
                    [':i' => $nutrId, ':t' => vero_tenant()]);
                if (!$okN) continue;
                $pdo->prepare("INSERT INTO {$tabelaR} (tenant_id, analise_id, nutriente_id, valor, unidade)
                               VALUES (?,?,?,?,?)")
                    ->execute([vero_tenant(), $anlId, $nutrId, $valor,
                               trim((string)($rUnid[$ix] ?? '')) ?: null]);
                $qtd++;
            }
            if ($qtd === 0) throw new RuntimeException('Nenhum resultado válido — confira os valores extraídos.');

            $cont = vero_srv_analise_classificar($tipo, $anlId);
            vero_update('agro_ia_extracoes', $extId, [
                'status_revisao'     => 'revisado_aprovado',
                'revisado_por'       => vero_uid(),
                'revisado_em'        => date('Y-m-d H:i:s'),
                'origem_id'          => $anlId,
                'observacao_revisao' => vero_str('observacao_revisao', 2000),
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            vero_flash('erro', 'Erro ao gravar a análise: ' . h($e->getMessage()));
            vero_redirect(BIOS_BASE . '/nutricao/importar_laudo?rev=' . $extId);
        }
        vero_flash('ok', "Análise gravada a partir do laudo (origem IA, revisada): {$qtd} resultado(s), "
            . "{$cont['classificados']} classificado(s), {$cont['alertas']} alerta(s).");
        vero_redirect(BIOS_BASE . '/nutricao/analise_' . $tipo . '.php?editar=' . $anlId);
    }

    if ($acao === 'rejeitar') {
        vero_require('nutricao.importar_laudo.editar');
        $extId = vero_int('extracao_id');
        if ($extId) {
            vero_update('agro_ia_extracoes', $extId, [
                'status_revisao' => 'rejeitado',
                'revisado_por'   => vero_uid(),
                'revisado_em'    => date('Y-m-d H:i:s'),
                'observacao_revisao' => vero_str('observacao_revisao', 2000),
            ]);
            vero_flash('ok', 'Extração rejeitada — nada foi gravado nas análises.');
        }
        vero_redirect(BIOS_BASE . '/nutricao/importar_laudo');
    }
}

/* ── Dados ──────────────────────────────────────────────────── */
$rev = null;
$revJson = [];
if (!empty($_GET['rev'])) {
    $rev = vero_row("SELECT * FROM agro_ia_extracoes WHERE id=:i AND tenant_id=:t",
        [':i' => (int)$_GET['rev'], ':t' => vero_tenant()]);
    if ($rev) $revJson = json_decode((string)$rev['json_extraido'], true) ?: [];
}

$pendentes = vero_rows(
    "SELECT e.*, a.nome_original FROM agro_ia_extracoes e
       LEFT JOIN agro_anexos a ON a.id = e.anexo_id
      WHERE e.tenant_id = :t AND e.origem_tipo IN ('analise_solo','analise_foliar')
      ORDER BY e.id DESC LIMIT 20",
    [':t' => vero_tenant()]);

$nutrientes = vero_rows("SELECT id, nome, simbolo, unidade_padrao FROM analise_nutrientes
                          WHERE tenant_id = :t AND ativo = 1 ORDER BY ordem, nome", [':t' => vero_tenant()]);
$talhoes = vero_rows(
    "SELECT t.id, CONCAT(f.nome, ' — ', t.codigo) AS label
       FROM agro_talhoes t JOIN agro_fazendas f ON f.id = t.fazenda_id
      WHERE t.tenant_id = :t AND t.ativo = 1 ORDER BY f.nome, t.codigo", [':t' => vero_tenant()]);
$safras = vero_options('agro_safras', 'identificacao');

/** casa o nutriente extraído com o catálogo (helper compartilhado) */
$casarNutriente = static fn(array $r): ?int =>
    vero_srv_casar_nutriente($nutrientes, (string)($r['simbolo'] ?? ''), (string)($r['nutriente'] ?? ''));

$GUARD      = ['macro' => 'nutricao', 'micro' => 'importar_laudo'];
$PAGE_VIEW  = 'nutricao_importar_laudo';
$PAGE_TITLE = 'Importar Laudo (IA)';
$EXTRA_HEAD = vero_assets();
require __DIR__ . '/../includes/agro_header.php';

$podeEditar = vero_can('nutricao.importar_laudo.editar');
$provIA     = ia_provedor();          // 'anthropic' (PDF+imagem) | 'groq' (imagem) | null
$temChave   = $provIA !== null;
$ehOpenAI     = $provIA === 'groq';
$confianca  = $rev && $rev['confianca'] !== null ? (float)$rev['confianca'] : null;
$confBaixa  = $confianca !== null && $confianca < 0.7;
?>
<div class="vwrap">
  <?= vero_flash_html() ?>

<?php if ($rev && $rev['status_revisao'] === 'pendente' && $podeEditar):
    $tipoRev = $rev['origem_tipo'] === 'analise_foliar' ? 'foliar' : 'solo'; ?>
  <div class="vhead">
    <div>
      <h1>Revisão do laudo extraído — <?= $tipoRev === 'solo' ? 'Solo' : 'Foliar' ?></h1>
      <div class="vsub">Revisão humana obrigatória (D6): confira TODOS os campos contra o PDF antes de confirmar. Nada é gravado sem a sua aprovação.</div>
    </div>
    <a class="vbtn vbtn-ghost" href="<?= BIOS_BASE ?>/nutricao/importar_laudo">← Voltar</a>
  </div>

  <div class="vflash <?= $confBaixa ? 'vflash-aviso' : 'vflash-ok' ?>">
    Confiança da extração: <strong><?= $confianca !== null ? numFmt($confianca * 100, 0) . '%' : 'não informada' ?></strong>
    · Modelo: <?= h((string)$rev['modelo']) ?>
    <?= $confBaixa ? ' — ATENÇÃO: confiança baixa, revise com cuidado redobrado.' : '' ?>
    <?php if ($rev['anexo_id']):
        $anx = vero_row("SELECT url, nome_original FROM agro_anexos WHERE id=:i AND tenant_id=:t",
            [':i' => (int)$rev['anexo_id'], ':t' => vero_tenant()]); ?>
      · <a href="<?= BIOS_BASE . h($anx['url'] ?? '') ?>" target="_blank">Abrir PDF original</a>
    <?php endif; ?>
  </div>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
    <input type="hidden" name="acao" value="confirmar">
    <input type="hidden" name="extracao_id" value="<?= (int)$rev['id'] ?>">

    <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
      <div class="vgrid" style="grid-template-columns:repeat(4,1fr)">
        <div class="vfield">
          <label>Data da amostra *</label>
          <input type="date" name="data_amostra" required
                 value="<?= h(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($revJson['data'] ?? '')) ? $revJson['data'] : date('Y-m-d')) ?>">
        </div>
        <div class="vfield">
          <label>Válvula <?= !empty($revJson['talhao']) ? '<span class="vhint">(laudo: ' . h((string)$revJson['talhao']) . ')</span>' : '' ?></label>
          <select name="talhao_id">
            <option value="">— Não informado —</option>
            <?php foreach ($talhoes as $t): ?>
              <option value="<?= (int)$t['id'] ?>"><?= h($t['label']) ?></option>
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
        <?php if ($tipoRev === 'solo'): ?>
          <?= vero_f_text('profundidade', 'Profundidade', (string)($revJson['profundidade'] ?? '')) ?>
        <?php else: ?>
          <?= vero_f_select('parte_folha', 'Parte da folha',
                ['limbo' => 'Limbo', 'peciolo' => 'Pecíolo', 'folha_inteira' => 'Folha inteira'],
                $revJson['parte_folha'] ?? null, false, '— Não informada —') ?>
        <?php endif; ?>
        <div class="vfield" style="grid-column:1/-2">
          <label>Observação da análise</label>
          <input type="text" name="observacao" value="<?= h('Laboratório: ' . (string)($revJson['laboratorio'] ?? '—')) ?>">
        </div>
        <div class="vfield">
          <label>Observação da revisão</label>
          <input type="text" name="observacao_revisao" placeholder="opcional">
        </div>
      </div>
    </div>

    <div class="vcard" style="margin-bottom:16px">
      <div class="vtoolbar"><strong style="font-size:14px">Resultados extraídos (editáveis)</strong>
        <div class="vhint">Corrija o nutriente, o valor e a unidade conforme o PDF; linhas sem nutriente casado exigem sua escolha</div>
      </div>
      <div class="vdata-wrap">
      <table class="vdata">
        <thead><tr>
          <th>Extraído do laudo</th><th style="width:260px">Nutriente do catálogo</th>
          <th class="num" style="width:140px">Valor</th><th style="width:120px">Unidade</th>
        </tr></thead>
        <tbody>
        <?php foreach (($revJson['resultados'] ?? []) as $r):
            $casado = $casarNutriente((array)$r); ?>
          <tr<?= $casado === null ? ' style="background:#F7EFD9"' : '' ?>>
            <td><strong><?= h((string)($r['simbolo'] ?? '')) ?></strong> <?= h((string)($r['nutriente'] ?? '')) ?>
              <?= $casado === null ? ' <span class="vbadge vb-warn">não casado</span>' : '' ?></td>
            <td>
              <select name="r_nutriente[]">
                <option value="">— Ignorar esta linha —</option>
                <?php foreach ($nutrientes as $n): ?>
                  <option value="<?= (int)$n['id'] ?>"<?= $casado === (int)$n['id'] ? ' selected' : '' ?>>
                    <?= h(($n['simbolo'] ? $n['simbolo'] . ' — ' : '') . $n['nome']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="r_valor[]" style="text-align:right"
                       value="<?= h(str_replace('.', ',', (string)($r['valor'] ?? ''))) ?>"></td>
            <td><input type="text" name="r_unidade[]" value="<?= h((string)($r['unidade'] ?? '')) ?>"></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($revJson['resultados'])): ?>
          <tr><td colspan="4" class="vempty">A IA não extraiu resultados — rejeite e use a digitação manual.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px">
      <button class="vbtn vbtn-ghost" type="submit"
              formaction="" onclick="document.querySelector('[name=acao]').value='rejeitar'">Rejeitar extração</button>
      <button class="vbtn vbtn-primary" type="submit">Confirmar revisão e gravar análise</button>
    </div>
  </form>

<?php else: ?>
  <?= vero_page_header('Importar Laudo (IA)',
        'PDF do laboratório → extração por IA → revisão humana obrigatória → análise classificada. O fallback manual continua nas telas de análise.', null) ?>

  <?php if (!$temChave): ?>
    <div class="vflash vflash-aviso">
      <strong>Nenhuma chave de IA configurada no .env</strong> — configure <code>OPENAI_API_KEY</code> ou
      <code>ANTHROPIC_API_KEY</code> (ambas leem PDF e imagem) no servidor (nunca no código). Enquanto isso, use a digitação manual
      em Análise de Solo / Análise Foliar.
    </div>
  <?php endif; ?>

  <?php if ($podeEditar): ?>
  <div class="vcard" style="padding:18px 22px;margin-bottom:16px">
    <form method="post" enctype="multipart/form-data" id="iaFormExtrair" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="csrf_token" value="<?= h(csrf()) ?>">
      <input type="hidden" name="acao" value="extrair">
      <div class="vfield">
        <label>Tipo de laudo *</label>
        <?php $tipoPre = (($_GET['tipo'] ?? '') === 'foliar') ? 'foliar' : ((($_GET['tipo'] ?? '') === 'solo') ? 'solo' : ''); ?>
        <select name="tipo" required>
          <option value="solo"<?= $tipoPre === 'solo' ? ' selected' : '' ?>>Análise de solo</option>
          <option value="foliar"<?= $tipoPre === 'foliar' ? ' selected' : '' ?>>Análise foliar</option>
        </select>
      </div>
      <div class="vfield" style="flex:1;min-width:260px">
        <label>Laudo — PDF ou imagem *</label>
        <input type="file" name="laudo" accept=".pdf,image/*" required<?= $temChave ? '' : ' disabled' ?>>
      </div>
      <button class="vbtn vbtn-primary" type="submit"<?= $temChave ? '' : ' disabled' ?>>Extrair com IA</button>
    </form>
    <?php $uso = ia_uso_mensal(); ?>
    <div class="vhint" style="margin-top:8px">
      Modelo: <?= h($ehOpenAI ? ia_modelo_visao() : IA_MODELO) ?> · PDF/imagem até 5 MB ·
      uso do mês: <strong><?= $uso['qtd'] ?></strong> extração(ões) ≈ R$ <?= numFmt($uso['custo'], 2) ?>
      de R$ <?= numFmt(IA_TETO_MENSAL_BRL, 2) ?> (estimativa conservadora de R$ <?= numFmt(IA_CUSTO_ESTIMADO_BRL, 2) ?>/extração)
    </div>
  </div>

  <!-- Overlay de carregamento: vídeo 3D da logo VERO (igual à tela de login) -->
  <div id="iaLoading" aria-hidden="true" aria-live="polite" role="status">
    <div class="ial-box">
      <div class="ial-spin">
        <video id="iaLoadingVid" class="ial-vid" muted loop playsinline preload="auto" aria-hidden="true">
          <source src="<?= h(BIOS_BASE) ?>/assets/img/vero-splash-3d.webm" type="video/webm">
          <source src="<?= h(BIOS_BASE) ?>/assets/img/vero-splash-3d.mp4" type="video/mp4">
        </video>
      </div>
      <div class="ial-txt">Lendo o laudo com IA…</div>
      <div class="ial-sub">Isso pode levar alguns segundos. Não feche a página.</div>
    </div>
  </div>
  <style>
    #iaLoading{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;
      background:rgba(9,32,34,.72);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px)}
    #iaLoading.on{display:flex}
    #iaLoading .ial-box{display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;padding:8px}
    #iaLoading .ial-spin{width:220px;height:220px;display:flex;align-items:center;justify-content:center;
      filter:drop-shadow(0 0 30px rgba(0,80,89,.30))}
    #iaLoading .ial-vid{width:100%;height:100%;object-fit:contain;display:block}
    #iaLoading .ial-txt{color:#fff;font-weight:700;font-size:1.05rem;letter-spacing:.2px}
    #iaLoading .ial-sub{color:rgba(255,255,255,.72);font-size:.82rem}
  </style>
  <script>
    (function(){
      var f=document.getElementById('iaFormExtrair'),ov=document.getElementById('iaLoading'),v=document.getElementById('iaLoadingVid');
      if(f&&ov)f.addEventListener('submit',function(){
        // só dispara quando o form passou na validação nativa (arquivo obrigatório)
        ov.classList.add('on');ov.setAttribute('aria-hidden','false');
        if(v){try{v.currentTime=0;v.play();}catch(e){}}
      });
    })();
  </script>
  <?php endif; ?>

  <div class="vcard">
    <div class="vtoolbar"><strong style="font-size:14px">Extrações recentes</strong></div>
    <?php if (!$pendentes): ?>
      <div class="vempty">Nenhuma extração realizada ainda.</div>
    <?php else: ?>
    <div class="vdata-wrap">
    <table class="vdata">
      <thead><tr>
        <th>#</th><th>Tipo</th><th>Arquivo</th><th>Modelo</th>
        <th class="num">Confiança</th><th>Status</th><th>Enviado em</th><th class="num">Ações</th>
      </tr></thead>
      <tbody>
      <?php foreach ($pendentes as $p): ?>
        <tr>
          <td class="vnum"><?= (int)$p['id'] ?></td>
          <td><?= $p['origem_tipo'] === 'analise_foliar' ? 'Foliar' : 'Solo' ?></td>
          <td class="vhint"><?= h($p['nome_original'] ?? '—') ?></td>
          <td class="vhint"><?= h((string)$p['modelo']) ?></td>
          <td class="num"><?= $p['confianca'] !== null ? numFmt((float)$p['confianca'] * 100, 0) . '%' : '—' ?></td>
          <td><?= match ($p['status_revisao']) {
                'pendente'            => '<span class="vbadge vb-warn">Pendente de revisão</span>',
                'revisado_aprovado'   => '<span class="vbadge vb-ok">Aprovada</span>',
                'revisado_corrigido'  => '<span class="vbadge vb-ok">Corrigida</span>',
                default               => '<span class="vbadge vb-off">Rejeitada</span>',
          } ?></td>
          <td class="vhint"><?= date('d/m/Y H:i', strtotime((string)$p['created_at'])) ?></td>
          <td><div class="vactions">
            <?php if ($p['status_revisao'] === 'pendente' && $podeEditar): ?>
              <?= vero_btn_icone(vero_ico_lapis(), 'Revisar', '', '?rev=' . (int)$p['id']) ?>
            <?php elseif ($p['origem_id']): ?>
              <?= vero_btn_icone(vero_ico_olho(), 'Ver análise', '', BIOS_BASE . '/nutricao/' . ($p['origem_tipo'] === 'analise_foliar' ? 'analise_foliar' : 'analise_solo') . '.php?editar=' . (int)$p['origem_id']) ?>
            <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/agro_footer_simple.php';
