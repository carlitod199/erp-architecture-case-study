<?php
declare(strict_types=1);
/* ============================================================
   VERO — seed_fenologia_manga.php  (módulo MANGA · reunião 17/08)
   Semeia por TENANT: cultura Manga (dia 0 = poda de PÓS-colheita),
   4 variedades com ciclo poda→colheita (doc do gestor 25/08:
   Tommy Atkins 240d · Palmer 270d · Keitt 260d · Kent 280d) e o
   TEMPLATE de fases fenológicas de cada uma — as 10 fases do
   documento de implantação, escaladas proporcionalmente ao ciclo.
   O template nasce 'aprovada' (modelo vigente pós-simplificação
   17/07 — sem rascunho); o RT revisa/edita em
   /agro/variedade_fenologia?variedade_id=X e, na safra, ADIANTA
   as fases em /agro/safra_fases (a manga muda a cada florada).
   Idempotente: não duplica cultura/variedade/fenologia existentes.
   Rodar: php scripts/seed_fenologia_manga.php <tenant_id>
   ============================================================ */
$tenant = (int)($argv[1] ?? 0);
if ($tenant <= 0) {
    fwrite(STDERR, "Uso: php scripts/seed_fenologia_manga.php <tenant_id>\n");
    exit(1);
}
$config = require __DIR__ . '/../config/database.php';
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['host'], $config['dbname'], $config['charset']),
    $config['user'], $config['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "== seed fenologia MANGA · tenant {$tenant} ==\n";

/* fases-base (frações de um ciclo de 240 d — doc §3, dia 0 = poda pós-colheita) */
$FASES_BASE = [
    ['Poda de pós-colheita',        7],
    ['Brotação / cresc. vegetativo', 60],
    ['PB2 · paralisação do fluxo',  30],
    ['Maturação do fluxo',          21],
    ['Indução floral',              35],
    ['Floração plena',              14],
    ['Chumbinho',                   14],
    ['Desenvolvimento do fruto',    45],
    ['Poda de abertura · caulim',    7],
    ['Colheita',                     7],
]; /* soma = 240 */

$VARIEDADES = [
    ['Tommy Atkins', 240],
    ['Palmer',       270],
    ['Keitt',        260],
    ['Kent',         280],
];

/* Atividades de MÃO DE OBRA da manga (doc §3/§4 — metas em planta ou hectare).
   Pulverização/fertilização NÃO entram aqui: vão pelo módulo de aplicações
   (DF/IF). Poda, Raleio e Colheita genéricas já existem e são reaproveitadas.
   [nome, categoria, unidade_padrao, exige_producao] — frente fica NULL. */
$ATIVIDADES = [
    ['Poda de pós-colheita',                  'trato_cultural', 'planta', 1], /* dia 0 da manga (nome contém "poda" → detectada pelo fluxo poda→safra) */
    ['Retirada de resto de poda',             'trato_cultural', 'planta', 0],
    ['Roço manual',                           'trato_cultural', 'ha',     0],
    ['Roço mecânico',                         'trato_cultural', 'ha',     0],
    ['Poda de pré-indução (cabeça de gato)',  'trato_cultural', 'planta', 0],
    ['Pincado',                               'trato_cultural', 'planta', 0],
    ['Escoramento',                           'trato_cultural', 'planta', 0],
    ['Jateamento',                            'trato_cultural', 'planta', 0],
    ['Aplicação de caulim (costal)',          'trato_cultural', 'planta', 0],
];

/* cultura Manga (marco do dia 0 diferente da uva) */
$culturaId = $pdo->prepare("SELECT id FROM agro_culturas WHERE tenant_id=? AND nome LIKE 'Manga%' ORDER BY id LIMIT 1");
$culturaId->execute([$tenant]);
$culturaId = (int)($culturaId->fetchColumn() ?: 0);
if ($culturaId === 0) {
    $pdo->prepare("INSERT INTO agro_culturas (tenant_id, nome, marco_dia_zero, created_at, updated_at)
                   VALUES (?, 'Manga', 'Poda de pós-colheita', NOW(), NOW())")->execute([$tenant]);
    $culturaId = (int)$pdo->lastInsertId();
    echo "  criada cultura Manga (#{$culturaId})\n";
} else {
    $pdo->prepare("UPDATE agro_culturas SET marco_dia_zero='Poda de pós-colheita'
                    WHERE id=? AND tenant_id=? AND (marco_dia_zero IS NULL OR marco_dia_zero='')")
        ->execute([$culturaId, $tenant]);
    echo "  cultura Manga já existia (#{$culturaId}) — marco_dia_zero garantido\n";
}

$somaBase = array_sum(array_column($FASES_BASE, 1)); /* 240 */

foreach ($VARIEDADES as [$nome, $ciclo]) {
    /* variedade (com ciclo poda→colheita p/ o fim previsto da safra) */
    $q = $pdo->prepare("SELECT id FROM agro_variedades WHERE tenant_id=? AND cultura_id=? AND nome=?");
    $q->execute([$tenant, $culturaId, $nome]);
    $varId = (int)($q->fetchColumn() ?: 0);
    if ($varId === 0) {
        $pdo->prepare("INSERT INTO agro_variedades (tenant_id, cultura_id, nome, ciclo_dias, created_at, updated_at)
                       VALUES (?,?,?,?,NOW(),NOW())")->execute([$tenant, $culturaId, $nome, $ciclo]);
        $varId = (int)$pdo->lastInsertId();
        echo "  criada variedade {$nome} ({$ciclo} d) #{$varId}\n";
    } else {
        echo "  variedade {$nome} já existia (#{$varId})\n";
    }

    /* fenologia: não sobrescreve se a variedade já tem uma vigente */
    $q = $pdo->prepare("SELECT COUNT(*) FROM agro_variedade_fenologia WHERE tenant_id=? AND variedade_id=? AND ativo=1");
    $q->execute([$tenant, $varId]);
    if ((int)$q->fetchColumn() > 0) {
        echo "    fenologia já cadastrada — preservada (edite em /agro/variedade_fenologia?variedade_id={$varId})\n";
        continue;
    }
    $pdo->prepare("INSERT INTO agro_variedade_fenologia (tenant_id, variedade_id, versao, status, aprovado_em, ativo, created_at, updated_at)
                   VALUES (?,?,1,'aprovada',NOW(),1,NOW(),NOW())")->execute([$tenant, $varId]);
    $fenId = (int)$pdo->lastInsertId();

    /* fases contíguas escaladas ao ciclo (resto de arredondamento na última) */
    $ini = 0; $acumulado = 0;
    $ins = $pdo->prepare(
        "INSERT INTO agro_variedade_fases
            (tenant_id, fenologia_id, ordem, nome, dia_inicio, dia_fim, ativo, created_at, updated_at)
         VALUES (?,?,?,?,?,?,1,NOW(),NOW())");
    foreach ($FASES_BASE as $i => [$fNome, $dias]) {
        $dur = ($i === count($FASES_BASE) - 1)
            ? $ciclo - $acumulado                                   /* fecha exato no ciclo */
            : max(1, (int)round($dias / $somaBase * $ciclo));
        $ins->execute([$tenant, $fenId, $i + 1, $fNome, $ini, $ini + $dur]);
        $ini += $dur; $acumulado += $dur;
    }
    echo "    fenologia semeada: " . count($FASES_BASE) . " fases · 0→{$ciclo} d (rev. do RT em /agro/variedade_fenologia?variedade_id={$varId})\n";
}

/* atividades de mão de obra (idempotente por nome no tenant) */
$qAt = $pdo->prepare("SELECT id FROM agro_tipos_atividade WHERE tenant_id=? AND nome=?");
$insAt = $pdo->prepare(
    "INSERT INTO agro_tipos_atividade (tenant_id, nome, categoria, unidade_padrao, exige_producao, ativo, created_at, updated_at)
     VALUES (?,?,?,?,?,1,NOW(),NOW())");
foreach ($ATIVIDADES as [$nome, $cat, $un, $exProd]) {
    $qAt->execute([$tenant, $nome]);
    if ($qAt->fetchColumn()) {
        echo "  atividade \"{$nome}\" já existia\n";
        continue;
    }
    $insAt->execute([$tenant, $nome, $cat, $un, $exProd]);
    echo "  criada atividade \"{$nome}\" ({$cat} · meta/{$un})\n";
}

echo "== seed manga concluído ==\n";
