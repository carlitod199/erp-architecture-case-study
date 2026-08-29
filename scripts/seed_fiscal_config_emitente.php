<?php
declare(strict_types=1);
/* ============================================================
   VERO — Preenche fiscal_config (tenant 1) com os dados CONFIRMADOS do
   cliente no questionário SEFAZ (13/07). Produtor Rural PESSOA FÍSICA.
   NÃO grava: Inscrição Estadual (faltou no formulário) nem nada do Bloco 4
   (tributário — depende do contador). NÃO toca certificado/senha
   (CSO: .pfx vai p/ storage/certs; senha em config/fiscal_secrets.php, nunca no banco).
   Ambiente permanece 2 (HOMOLOGAÇÃO). Idempotente.

   ⚠ IDENTIDADE FISCAL HARDCODED (Produtor Exemplo, PF). Este script viaja na
   imagem compartilhada da frota — rodá-lo no container errado gravaria
   identidade fiscal alheia (apontamento do operador de produção, 20/08).
   Por isso ele agora EXIGE intenção explícita:

   Uso: php scripts/seed_fiscal_config_emitente.php --tenant=<id> --confirmo=00000000000
        (--confirmo deve ser o CPF do emitente hardcoded, provando que o
         operador sabe DE QUEM é a identidade que vai gravar)
   ============================================================ */
if (PHP_SAPI !== 'cli') exit("Somente CLI.\n");

$T = null; $confirmo = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--tenant=(\d+)$/', $arg, $m))   { $T = (int)$m[1]; continue; }
    if (preg_match('/^--confirmo=(\d+)$/', $arg, $m)) { $confirmo = $m[1]; continue; }
    exit("Argumento desconhecido: {$arg}\n");
}
if ($T === null || $confirmo !== '00000000000') {
    exit("RECUSADO. Este seed grava a identidade fiscal de PRODUTOR EXEMPLO DA SILVA (CPF 000.000.000-00).\n"
       . "Para executar de propósito: --tenant=<id> --confirmo=00000000000\n");
}

$c = require __DIR__ . '/../config/database.php';
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['dbname'], $c['charset']),
    $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

/* trava extra: não sobrescreve identidade DIFERENTE já gravada no tenant alvo */
$atual = $pdo->query("SELECT cnpj_cpf FROM fiscal_config WHERE tenant_id={$T}")->fetchColumn();
if ($atual !== false && $atual !== null && $atual !== '' && $atual !== '00000000000') {
    exit("RECUSADO. tenant {$T} já tem emitente com documento {$atual} — não vou sobrescrever.\n");
}
/* Confirmados no questionário. cod_municipio = IBGE Petrolina/PE (2611101) — CONFIRMAR. */
$dados = [
    'tipo_pessoa'        => 'PF',                       // Produtor Rural Pessoa Física
    'razao_social'       => 'PRODUTOR EXEMPLO DA SILVA',
    'cnpj_cpf'           => '00000000000',              // CPF só dígitos (000.000.000-00)
    'uf'                 => 'PE',                        // emissão só Pernambuco
    'cod_municipio'      => '2611101',                  // Petrolina/PE (IBGE)
    'cert_validade'      => '2027-06-18',               // validade do A1
    'ambiente'           => 2,                          // HOMOLOGAÇÃO (primeiro)
    'serie_nfe'          => 1,                          // sem emissão anterior → série 1
    'proximo_numero_nfe' => 1,                          // primeira NF-e
    'ativo'              => 1,
];

$sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($dados)));
$params = [];
foreach ($dados as $k => $v) $params[":$k"] = $v;
$params[':t'] = $T;

/* get-or-create idempotente (a migration 154 já cria a linha) */
$has = (int)$pdo->query("SELECT COUNT(*) FROM fiscal_config WHERE tenant_id={$T}")->fetchColumn();
if ($has) {
    $pdo->prepare("UPDATE fiscal_config SET {$sets}, updated_at=NOW() WHERE tenant_id=:t")->execute($params);
    echo "fiscal_config ATUALIZADO (tenant {$T}).\n";
} else {
    $cols = implode(',', array_keys($dados));
    $ph   = implode(',', array_map(fn($k) => ":$k", array_keys($dados)));
    $pdo->prepare("INSERT INTO fiscal_config (tenant_id,{$cols},created_at,updated_at) VALUES (:t,{$ph},NOW(),NOW())")->execute($params);
    echo "fiscal_config CRIADO (tenant {$T}).\n";
}

/* relatório do que AINDA falta p/ emitir */
$row = $pdo->query("SELECT ie, im, crt, cnae, cert_arquivo FROM fiscal_config WHERE tenant_id={$T}")->fetch(PDO::FETCH_ASSOC);
echo "\n== PENDÊNCIAS p/ emitir (ainda NULL) ==\n";
echo $row['ie']           ? '' : "  - Inscrição Estadual de produtor rural (FALTOU no questionário)\n";
echo $row['crt']          ? '' : "  - CRT (regime tributário) — CONTADOR\n";
echo $row['cnae']         ? '' : "  - CNAE principal — CONTADOR\n";
echo $row['cert_arquivo'] ? '' : "  - certificado .pfx em storage/certs + senha em config/fiscal_secrets.php (CSO — nunca no banco)\n";
echo "  - Bloco 4 completo (NCM, unidade tributável, CFOPs, CST/CSOSN, PIS, COFINS, IPI, FUNRURAL, benefícios) — CONTADOR\n";
echo "\nAmbiente = HOMOLOGAÇÃO (2). Prova de vida só após o acima + questionário do contador.\n";
