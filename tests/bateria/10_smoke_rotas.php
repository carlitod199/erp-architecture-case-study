<?php
declare(strict_types=1);
/* ============================================================
   VERO — tests/bateria/10_smoke_rotas.php  (A5-QA)
   Smoke HTTP logado de TODAS as rotas da matriz de navegação
   (bios_menu_macros — incl. micros 'oculto'), em duas passadas:
   1) super_admin: toda rota real renderiza 200 sem fatal/warning
      e sem o marcador de erro inline do bootstrap;
   2) cada perfil (gestor/operador/financeiro/consulta): o HTTP
      observado (200 × 403) tem que BATER com a expectativa
      calculada de role_permissions + vero_dbn_perm — matriz de
      acesso positiva E negativa derivada do próprio banco.
   Requer 00_massa_canonica. Uso: php 10_smoke_rotas.php
   ============================================================ */

require __DIR__ . '/_lib.php';
qa_boot_app();                       /* carrega app p/ ler a matriz de menu */
require_once QA_ROOT . '/includes/menu_agro.php';

/* ── extrai as rotas vivas da matriz ── */
$rotas = [];                          /* [rota => perm] */
foreach (bios_menu_macros() as $macro) {
    foreach ($macro['micros'] as $micro) {
        if (empty($micro['rota'])) continue;              /* placeholder sem tela real */
        $perm = bios_menu_micro_perm($macro, $micro);
        $rotas[(string)$micro['rota']] = $perm;
    }
}
ksort($rotas);
qa_section('Inventário de rotas');
qa_check('matriz de navegação com rotas reais (≥150)', count($rotas) >= 150, count($rotas));

/* ── passada 1: super_admin vê tudo saudável ── */
qa_section('Smoke super_admin (200 + página saudável)');
if (!qa_http_login('super')) {
    qa_check('login HTTP qa.super', false, 'não autenticou — base_url/WAMP fora do ar?');
    qa_finish('10_smoke_rotas');
}
qa_check('login HTTP qa.super', true);

$falhas = 0;
foreach ($rotas as $rota => $perm) {
    $r = qa_http_get('super', $rota);
    $probs = qa_pagina_saudavel($r);
    if (!qa_check("GET {$rota}", $probs === [], $probs)) $falhas++;
}
qa_check('todas as rotas saudáveis como super_admin', $falhas === 0, $falhas . ' com problema');

/* ── passada 2: cada perfil, expectativa derivada do banco ── */
foreach (['gestor', 'operador', 'financeiro', 'consulta'] as $papel) {
    qa_section("Matriz de acesso — perfil {$papel}");
    if (!qa_http_login($papel)) {
        qa_check("login HTTP qa.{$papel}", false);
        continue;
    }
    /* permissões reais do papel, como o login carrega */
    $perms = array_map('strval', array_column(qa_rows(
        "SELECT p.slug FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
           JOIN roles r ON r.id = rp.role_id
          WHERE r.tenant_id = ? AND r.slug = ?", [qa_tenant_id(), $papel]), 'slug'));
    qa_check("perfil {$papel} com permissões carregadas", count($perms) > 0, count($perms));

    /* A negação de PÁGINA do VERO não é 403 seco: _auth_denyAccess redireciona
       (302) para a LANDING do perfil (1º módulo acessível) ou /403.php. Há ainda
       wrappers canônicos (ex.: /mip/doencas.php → alvos_controle) cujo guard real
       fica um hop adiante. Seguimos a cadeia MANUALMENTE, inspecionando cada
       Location ANTES de segui-lo: se um hop aponta p/ a landing ou /403 = negado;
       se chega a um 200 sem passar pela landing = acesso. (Seguir com
       FOLLOWLOCATION passa direto pelo stub /dashboard.php→executivo e perde o
       casamento com a landing — por isso o loop manual.) */
    $landing = rtrim(bios_landing_url($papel, $perms), '/');       /* ex.: /dashboard.php */

    $classificar = static function (string $papel, string $rota, string $landing): array {
        $atual = $rota;
        for ($hop = 0; $hop < 5; $hop++) {
            $r = qa_http_get($papel, $atual);
            if ($r['code'] === 200) return ['obtido' => 'acesso', 'http' => 200, 'via' => $atual];
            if ($r['code'] === 403) return ['obtido' => 'negado', 'http' => 403, 'via' => $atual];
            if ($r['code'] !== 302) return ['obtido' => 'acesso', 'http' => $r['code'], 'via' => $atual];
            $loc = '';
            if (preg_match('#Location:\s*(\S+)#i', $r['headers'], $mm)) $loc = $mm[1];
            $locPath = rtrim((string)parse_url($loc, PHP_URL_PATH), '/');
            if ($locPath === '' ) return ['obtido' => 'acesso', 'http' => 302, 'via' => $atual];
            if (str_contains($locPath, '/index.php') || str_ends_with($locPath, '/login'))
                return ['obtido' => 'sessao_caiu', 'http' => 302, 'via' => $locPath];
            if (str_contains($locPath, '/403.php') || str_ends_with($locPath, $landing))
                return ['obtido' => 'negado', 'http' => 302, 'via' => $locPath];
            /* redirect interno para OUTRA página (wrapper canônico / PRG): segue */
            $rel = (string)parse_url($loc, PHP_URL_PATH);
            $qs  = (string)parse_url($loc, PHP_URL_QUERY);
            $atual = substr($rel, strlen(rtrim(parse_url(qa_base(), PHP_URL_PATH) ?: '', '/'))) . ($qs ? '?' . $qs : '');
            if ($atual === '' || $atual[0] !== '/') $atual = '/' . ltrim($atual, '/');
        }
        return ['obtido' => 'acesso', 'http' => 302, 'via' => $atual];
    };

    $diverg = 0;
    foreach ($rotas as $rota => $perm) {
        $esperado = vero_dbn_perm($perm, $papel, $perms) ? 'acesso' : 'negado';
        $res = $classificar($papel, $rota, $landing);
        if ($esperado !== $res['obtido']) {
            $diverg++;
            qa_check("[{$papel}] {$rota} (perm {$perm})", false,
                ['esperado' => $esperado, 'obtido' => $res['obtido'], 'http' => $res['http'], 'via' => $res['via']]);
        }
    }
    qa_check("perfil {$papel}: 0 divergências na matriz (" . count($rotas) . " rotas)", $diverg === 0, $diverg);
}

qa_finish('10_smoke_rotas');
