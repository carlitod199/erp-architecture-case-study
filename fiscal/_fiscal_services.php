<?php
/* ============================================================
   VERO — Fiscal / serviços compartilhados
   - fiscal_importar_nfe_xml(): parse real do XML de NF-e (layout
     4.00): chave, número, emitente (get-or-create fornecedor por
     CNPJ), data de emissão, valor total e itens. Idempotente por
     chave — reimportar não duplica. O XML vira anexo.
   - fiscal_anexar_arquivo(): grava upload em storage/uploads/fiscal
     e registra em agro_anexos (origem fiscal_documento).
   ============================================================ */
declare(strict_types=1);

require_once __DIR__ . '/../includes/vero_crud.php';

/** Extensões e MIMEs aceitos em anexo fiscal (PT-01 — allowlist, CSO 07/07). */
const FISCAL_ANEXO_EXT  = ['xml', 'pdf', 'jpg', 'jpeg', 'png', 'p7s', 'txt'];
const FISCAL_ANEXO_MIME = ['application/xml', 'text/xml', 'application/pdf', 'image/jpeg',
    'image/png', 'text/plain', 'application/pkcs7-signature', 'application/octet-stream'];

/**
 * Valida um upload de anexo fiscal ANTES de gravar (PT-01/RCE):
 * extensão na allowlist + MIME real (finfo) coerente — rejeita .php e afins.
 * Lança RuntimeException se inválido. Testável isoladamente.
 */
function fiscal_validar_anexo(array $file): string
{
    $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, FISCAL_ANEXO_EXT, true)) {
        throw new RuntimeException('Extensão não permitida para anexo fiscal (.' . $ext
            . '). Aceitos: ' . implode(', ', FISCAL_ANEXO_EXT) . '.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp !== '' && is_readable($tmp) && function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_file($fi, $tmp) : '';
        if ($fi) finfo_close($fi);
        if ($mime !== '' && !in_array($mime, FISCAL_ANEXO_MIME, true)) {
            throw new RuntimeException('Conteúdo do anexo (' . $mime
                . ') não confere com um documento fiscal — envio recusado.');
        }
    }
    return $ext;
}

function fiscal_anexar_arquivo(int $documentoId, array $file, string $tipoDoc): void
{
    $ext = fiscal_validar_anexo($file); /* PT-01: allowlist + MIME antes de qualquer gravação */
    $dir = dirname(__DIR__) . '/storage/uploads/fiscal/' . vero_tenant();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $nomeFisico = 'doc' . $documentoId . '_' . $tipoDoc . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $destino = $dir . '/' . $nomeFisico;
    if (!move_uploaded_file((string)$file['tmp_name'], $destino)) {
        throw new RuntimeException('Falha ao gravar o arquivo no servidor.');
    }
    vero_insert('agro_anexos', [
        'origem_tipo'   => 'fiscal_documento',
        'origem_id'     => $documentoId,
        'tipo_arquivo'  => $tipoDoc,
        'nome_original' => mb_substr((string)$file['name'], 0, 255),
        'url'           => '/storage/uploads/fiscal/' . vero_tenant() . '/' . $nomeFisico,
        'tamanho_bytes' => (int)$file['size'],
        'hash_sha256'   => hash_file('sha256', $destino),
    ]);
}

/**
 * Importa um XML de NF-e (procNFe ou NFe, layout 4.00).
 * @return array{documento_id:int, ja_existia:bool, chave:string, numero:string,
 *               fornecedor:string, valor:float, itens:int}
 */
function fiscal_importar_nfe_xml(string $xmlConteudo): array
{
    /* XXE-safe (auditoria seg. 23/07, A-9): rejeita DOCTYPE — corta external
       entity e expansão de entidades (billion laughs); LIBXML_NONET sem rede.
       Mesmo idioma de agro/_mapa_import.php:mapa_xml_safe(). */
    if (preg_match('/<!DOCTYPE/i', $xmlConteudo)) {
        throw new RuntimeException('XML com DOCTYPE não é permitido (proteção XXE).');
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlConteudo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    if ($xml === false) {
        throw new RuntimeException('Arquivo não é um XML válido.');
    }
    /* aceita nfeProc (com protocolo) ou NFe direto; ignora namespace via registro */
    $ns = $xml->getNamespaces(true);
    $nsNfe = $ns[''] ?? 'http://www.portalfiscal.inf.br/nfe';
    $xml->registerXPathNamespace('n', $nsNfe);

    $infNFe = $xml->xpath('//n:NFe/n:infNFe');
    if (!$infNFe) $infNFe = $xml->xpath('//n:infNFe');
    if (!$infNFe) {
        throw new RuntimeException('XML não contém uma NF-e (infNFe não encontrado) — confira se é o XML da nota, não o DANFE.');
    }
    $inf = $infNFe[0];

    $chave = preg_replace('/^NFe/', '', (string)($inf->attributes()['Id'] ?? ''));
    if (!preg_match('/^\d{44}$/', (string)$chave)) {
        throw new RuntimeException('Chave de acesso inválida no XML.');
    }

    /* idempotência por chave */
    $existente = vero_val("SELECT id FROM fiscal_documentos WHERE tenant_id = :t AND chave = :c",
        [':t' => vero_tenant(), ':c' => $chave]);
    if ($existente) {
        $doc = vero_row("SELECT * FROM fiscal_documentos WHERE id = :i AND tenant_id = :t",
            [':i' => (int)$existente, ':t' => vero_tenant()]);
        return [
            'documento_id' => (int)$existente, 'ja_existia' => true, 'chave' => (string)$chave,
            'numero' => (string)($doc['numero'] ?? ''), 'fornecedor' => '',
            'valor' => (float)($doc['valor_total'] ?? 0), 'itens' => 0,
        ];
    }

    $numero  = (string)($inf->ide->nNF ?? '');
    $dhEmi   = (string)($inf->ide->dhEmi ?? $inf->ide->dEmi ?? '');
    $dataEmi = $dhEmi !== '' ? date('Y-m-d', strtotime($dhEmi)) : null;

    $cnpjEmit = preg_replace('/\D/', '', (string)($inf->emit->CNPJ ?? $inf->emit->CPF ?? ''));
    $nomeEmit = trim((string)($inf->emit->xNome ?? 'Emitente'));

    /* get-or-create fornecedor pelo CNPJ (ou nome, sem CNPJ) */
    $fornecedorId = null;
    if ($cnpjEmit !== '') {
        $fornecedorId = vero_val(
            "SELECT id FROM fornecedores WHERE tenant_id = :t AND REPLACE(REPLACE(REPLACE(cnpj_cpf,'.',''),'/',''),'-','') = :c",
            [':t' => vero_tenant(), ':c' => $cnpjEmit]);
    }
    if (!$fornecedorId && $nomeEmit !== '') {
        $fornecedorId = vero_val("SELECT id FROM fornecedores WHERE tenant_id = :t AND nome = :n",
            [':t' => vero_tenant(), ':n' => $nomeEmit]);
    }
    if (!$fornecedorId) {
        $fornecedorId = vero_insert('fornecedores', [
            'nome' => mb_substr($nomeEmit, 0, 120) ?: 'Emitente da NF-e',
            'cnpj_cpf' => $cnpjEmit ?: null,
            'ativo' => 1,
        ]);
    }

    $vNF = (float)str_replace(',', '.', (string)($inf->total->ICMSTot->vNF ?? '0'));

    $docId = vero_insert('fiscal_documentos', [
        'tipo'          => 'nfe',
        'chave'         => $chave,
        'numero'        => $numero !== '' ? $numero : null,
        'fornecedor_id' => (int)$fornecedorId,
        'valor_total'   => $vNF,
        'data_emissao'  => $dataEmi,
        'status'        => 'importado',
    ]);

    /* itens (det/prod) — tabela sem colunas de auditoria: insert direto */
    $pdo = vero_pdo();
    $ins = $pdo->prepare(
        "INSERT INTO fiscal_documento_itens
            (tenant_id, documento_id, produto_id, descricao, quantidade, valor_unitario, valor_total, created_at, updated_at)
         VALUES (?, ?, NULL, ?, ?, ?, ?, NOW(), NOW())");
    $nItens = 0;
    foreach ($inf->det as $det) {
        $prod = $det->prod;
        if (!$prod) continue;
        $ins->execute([
            vero_tenant(), (int)$docId,
            mb_substr(trim((string)$prod->xProd), 0, 180),
            (float)str_replace(',', '.', (string)$prod->qCom),
            (float)str_replace(',', '.', (string)$prod->vUnCom),
            (float)str_replace(',', '.', (string)$prod->vProd),
        ]);
        $nItens++;
    }

    return [
        'documento_id' => (int)$docId, 'ja_existia' => false, 'chave' => (string)$chave,
        'numero' => $numero, 'fornecedor' => $nomeEmit, 'valor' => $vNF, 'itens' => $nItens,
    ];
}
