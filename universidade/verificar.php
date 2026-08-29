<?php
declare(strict_types=1);
/* ============================================================
   VERO — Portal da Universidade — VERIFICAR (/universidade/verificar.php)
   Endpoint POST: valida CSRF, pega o exercício por slug, roda a
   verificação no banco do SISTEMA (via uni_pratica_verificar, que
   também grava a tentativa) e volta para praticar.php com o resultado
   num flash de sessão. CSRF inválido → 403.
   ============================================================ */
require_once __DIR__ . '/../includes/uni_auth.php'; uni_auth_boot(); uni_auth_require();       // exige login (redireciona se ausente)
require_once __DIR__ . '/../includes/uni_pratica.php';

$base = BIOS_BASE;

/* Só POST. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . $base . '/universidade/praticar.php', true, 303);
    exit;
}

/* CSRF: token do form conferido contra a sessão (uni_csrf() é o token atual).
   Inválido → 403 explícito (não é navegação de formulário a preservar). */
$token = (string)($_POST['csrf_token'] ?? '');
if (!uni_csrf_check($token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CSRF inválido.';
    exit;
}

$ctx  = uni_ctx();
$slug = trim((string)($_POST['slug'] ?? ''));

$tarefa = $slug !== '' ? uni_pratica_por_slug($slug) : null;

if (!$tarefa) {
    $_SESSION['uni_pratica_flash'] = [
        'slug' => $slug,
        'ok'   => false,
        'msg'  => 'Exercício não encontrado. Recarregue a página de prática e tente de novo.',
    ];
    header('Location: ' . $base . '/universidade/praticar.php', true, 303);
    exit;
}

/* Roda a verificação no banco do sistema e grava a tentativa (função pronta). */
$res = uni_pratica_verificar($ctx, $tarefa);

$_SESSION['uni_pratica_flash'] = [
    'slug' => $slug,
    'ok'   => (bool)$res['ok'],
    'msg'  => (string)$res['mensagem'],
];

header('Location: ' . $base . '/universidade/praticar.php', true, 303);
exit;
