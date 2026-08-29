<?php
// Recebe o formulário "Fale com um especialista" e grava o lead em leads/leads.csv
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: demonstracao.html');
    exit;
}

// honeypot: humanos não preenchem este campo
if (!empty($_POST['site'] ?? '')) {
    header('Location: obrigado.html');
    exit;
}

function campo(string $nome, int $max): string {
    $v = trim((string)($_POST[$nome] ?? ''));
    $v = str_replace(["\r", "\n", "\t"], ' ', $v);
    $v = mb_substr($v, 0, $max);
    // anti-injeção de fórmula em planilha
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
        $v = "'" . $v;
    }
    return $v;
}

$nome     = campo('nome', 120);
$empresa  = campo('empresa', 120);
$cultura  = campo('cultura', 60);
$area     = campo('area', 20);
$telefone = campo('telefone', 40);
$email    = campo('email', 120);
$mensagem = campo('mensagem', 600);

if ($nome === '' || $empresa === '' || $cultura === '' || $telefone === '' ||
    !filter_var(ltrim($email, "'"), FILTER_VALIDATE_EMAIL)) {
    header('Location: demonstracao.html?erro=1');
    exit;
}

$dir = __DIR__ . '/leads';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/.htaccess', "Require all denied\n");
}

$arquivo = $dir . '/leads.csv';
$novo = !file_exists($arquivo);
$fp = fopen($arquivo, 'ab');
if ($fp) {
    flock($fp, LOCK_EX);
    if ($novo) {
        fwrite($fp, "\xEF\xBB\xBF"); // BOM: Excel pt-BR abre os acentos corretamente
        fputcsv($fp, ['data', 'nome', 'fazenda_empresa', 'cultura', 'area_ha', 'telefone', 'email', 'mensagem', 'ip'], ';');
    }
    fputcsv($fp, [
        date('d/m/Y H:i:s'),
        $nome, $empresa, $cultura, $area, $telefone, $email, $mensagem,
        $_SERVER['REMOTE_ADDR'] ?? '',
    ], ';');
    flock($fp, LOCK_UN);
    fclose($fp);
}

header('Location: obrigado.html');
exit;
