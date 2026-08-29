<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/teste_csrf_janela.php
   Harness CLI do achado A2 (auditoria Rodada 3, 22/07/2026):
   token CSRF rotativo matava formulários abertos em outra aba.

   Simula a sessão em CLI e exercita EXATAMENTE as funções usadas
   pelos POSTs internos (csrfCheck → csrf_token_valido, functions.php:116)
   e pelo login (index.php:349):
     GET form (token T1) → outra aba rotaciona (csrf_rotate) →
     POST com T1 antigo → deve ACEITAR (janela de tolerância).
   Também prova os limites: profundidade (CSRF_PREV_MAX) e
   idade (CSRF_PREV_GRACE), token forjado e token vazio.
   Não grava NADA em banco (não abre conexão).
   ============================================================ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$_SESSION = []; /* sessão simulada — as funções só usam a superglobal */
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function chk(string $desc, bool $ok, string $ctx = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $desc . ($ctx !== '' ? " | {$ctx}" : '') . "\n";
}

echo 'Config: CSRF_PREV_MAX=' . CSRF_PREV_MAX . ' tokens antigos, CSRF_PREV_GRACE='
    . CSRF_PREV_GRACE . "s\n\n";

/* 1) GET da tela com o form — token T1 no hidden */
$t1 = csrf_rotate();
chk('T1 (form renderizado) é aceito de imediato', csrf_token_valido($t1));

/* 2) usuário abre OUTRA ABA → rotação do token (o que matava o form antes) */
$t2 = csrf_rotate();
chk('após rotação em outra aba, o token NOVO (T2) vale', csrf_token_valido($t2));
chk('POST da aba antiga com T1 ainda é ACEITO (era o bug: antes morria)', csrf_token_valido($t1));

/* 3) várias abas: T1 sobrevive a até CSRF_PREV_MAX rotações... */
$tokens = [$t1, $t2];
for ($i = 0; $i < CSRF_PREV_MAX - 1; $i++) $tokens[] = csrf_rotate();
chk('T1 sobrevive a ' . CSRF_PREV_MAX . ' rotações (ainda na janela)', csrf_token_valido($t1));

/* ...e cai exatamente na rotação seguinte (histórico limitado) */
$tokens[] = csrf_rotate();
chk('T1 expira na rotação ' . (CSRF_PREV_MAX + 1) . ' (fora do histórico)', !csrf_token_valido($t1));
chk('os ' . CSRF_PREV_MAX . ' tokens mais recentes do histórico continuam válidos',
    array_reduce(array_slice($tokens, -1 - CSRF_PREV_MAX, CSRF_PREV_MAX),
        fn($c, $t) => $c && csrf_token_valido($t), true));

/* 4) expiração por IDADE: envelhece artificialmente um token do histórico */
$tv = csrf_rotate();          /* $tv vai para o histórico na próxima rotação */
csrf_rotate();
chk('token antigo dentro da idade limite vale', csrf_token_valido($tv));
foreach ($_SESSION['csrf_prev'] as &$p) {
    if (hash_equals((string)$p['t'], $tv)) $p['em'] = time() - CSRF_PREV_GRACE - 1;
}
unset($p);
chk('o MESMO token com idade > ' . CSRF_PREV_GRACE . 's é recusado', !csrf_token_valido($tv));

/* 5) segurança básica intacta */
chk('token forjado (aleatório) é recusado', !csrf_token_valido(bin2hex(random_bytes(32))));
chk('token vazio é recusado', !csrf_token_valido(''));
chk('csrf() continua devolvendo só o token ATUAL da sessão',
    csrf() === (string)$_SESSION['csrf_token']);

echo "\nRESULTADO: {$pass} PASS / {$fail} FAIL\n";
exit($fail === 0 ? 0 : 1);
