<?php
declare(strict_types=1);
/* ============================================================
   VERO — migration 210: permissões do Packing House na SÉRIE (catálogo)
   O módulo packing (migs 193-203) criou as tabelas ph_*, mas as
   permissões packing.* ficaram FORA da série. Esta migration semeia
   APENAS O CATÁLOGO (tabela permissions) — os slugs que o guard do
   menu deriva (permbase 'packing') e que as telas/API exigem.

   NÃO CONCEDE NADA A NINGUÉM. Grants são decisão de contrato/política
   por cliente e ficam no comando explícito da migration 211
   (--grant=<tenant_id>). Racional: em produção cada cliente tem
   contrato e política de papéis próprios (ex.: gestor lê, administrador
   escreve) — uma migration que concede à base inteira muda política
   de acesso como efeito colateral (bloqueio do deploy 20/08).

   Idempotente. Rodar: php migrations/migration_210_packing_permissoes.php
   ============================================================ */
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== migration 210: catálogo de permissões do Packing House ==\n";

$perms = [
    'packing.ver'             => 'Packing House — acessar',
    'packing.recepcao.ver'    => 'Packing — Recepção de cargas (ver)',
    'packing.recepcao.editar' => 'Packing — Recepção de cargas (aceitar/criar)',
    'packing.apontar.ver'     => 'Packing — Posto de produção (ver)',
    'packing.apontar.editar'  => 'Packing — Posto de produção (registrar caixa)',
];

$sel = $pdo->prepare("SELECT id FROM permissions WHERE slug = :s");
$ins = $pdo->prepare("INSERT INTO permissions (slug, label, modulo) VALUES (:s, :l, 'Packing House')");
foreach ($perms as $slug => $label) {
    $sel->execute([':s' => $slug]);
    $id = $sel->fetchColumn();
    if ($id) {
        echo "  = {$slug} já existe (#{$id})\n";
    } else {
        $ins->execute([':s' => $slug, ':l' => $label]);
        echo "  + {$slug} criada (#" . (int)$pdo->lastInsertId() . ")\n";
    }
}
echo "OK (catálogo apenas; grants: ver migration_211 --grant).\n";
