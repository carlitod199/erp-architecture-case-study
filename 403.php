<?php
/* VERO — 403 (acesso negado). Visual: includes/_error_page.php.
   R12B1: destino do botão calculado por permissão (mesma regra do pós-login). */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/functions.php';

$motivo = isset($_GET['motivo']) ? preg_replace('/[^a-z_]/', '', (string)$_GET['motivo']) : '';

/* destino do botão = dashboard ou 1ª tela permitida da matriz. Perfil sem
   NENHUMA tela → sem "Voltar" (era loop 403→dashboard→403): oferece "Sair". */
$homeUrl = null;
if (!empty($_SESSION['user_id'])) {
    $landing = bios_landing_url((string)($_SESSION['user_role'] ?? ''), (array)($_SESSION['permissions'] ?? []));
    if (strpos($landing, '/403') !== 0) {
        $homeUrl = $landing;
    } elseif ($motivo === '') {
        $motivo = 'sem_telas';
    }
}

$msgsMotivo = [
    'plano'       => ['Recurso não incluso no plano', 'Este módulo não faz parte do plano contratado pela sua empresa. Fale com o administrador para liberar o acesso.'],
    'fora_escopo' => ['Módulo indisponível', 'Este módulo não faz parte do escopo ativo da plataforma.'],
    'sem_telas'   => ['Nenhuma tela liberada', 'Seu perfil ainda não tem telas liberadas — contate o administrador da sua empresa para receber as permissões.'],
];
[$ERR_TITLE, $ERR_MSG] = $msgsMotivo[$motivo] ?? ['Acesso negado', 'Você não tem permissão para acessar este recurso. Se acha que é engano, fale com o administrador.'];

$B = rtrim((string)BIOS_BASE, '/');
if ($homeUrl !== null) {
    $ERR_ACTIONS = '<a class="ep-btn ep-btn--p" href="' . h($B . $homeUrl) . '">'
        . '<svg viewBox="0 0 24 24"><path d="m3 11 9-8 9 8"/><path d="M9 21V12h6v9"/></svg> Voltar ao início</a>';
} elseif (!empty($_SESSION['user_id'])) {
    $ERR_ACTIONS = '<a class="ep-btn ep-btn--p" href="' . h($B) . '/logout">'
        . '<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg> Sair</a>';
} else {
    $ERR_ACTIONS = '<a class="ep-btn ep-btn--p" href="' . h($B) . '/index">'
        . '<svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg> Ir para o login</a>';
}

$ERR_CODE = 403;
require __DIR__ . '/includes/_error_page.php';
