<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_trilhas_seed.php
   Semeadura idempotente de CURSOS e TRILHAS da Universidade VERO
   no banco SEPARADO (config/database_uni.php).

   - 1 curso por MÓDULO distinto das cápsulas publicadas; vincula
     todas as cápsulas do módulo (ordem por título).
   - 1 trilha por PERFIL que é 'nucleo' em >=1 cápsula; vincula os
     cursos que contêm >=1 cápsula onde o perfil é 'nucleo'.
   - Upsert por slug + rebuild das tabelas de vínculo => rodável
     N vezes sem duplicar.

   Rodar:
     php scripts/uni_trilhas_seed.php
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$pdo = uni_pdo();

/* ---------- Mapas de apresentação ---------- */

// modulo -> título amigável do curso
$TITULO_CURSO = [
    'operacao-agricola' => 'Operação Agrícola no Campo',
    'colheita'          => 'Colheita e Romaneio',
    'mip'               => 'MIP — Monitoramento de Pragas',
    'estoque'           => 'Estoque e Almoxarifado',
    'compras'           => 'Compras e Recebimento',
    'custos'            => 'Custos e Custeio da Safra',
    'financeiro'        => 'Financeiro e Fluxo de Caixa',
];

// modulo -> resumo curto do curso
$RESUMO_CURSO = [
    'operacao-agricola' => 'Abrir a safra, emitir ordens de serviço e finalizar apontamentos para o custo aparecer.',
    'colheita'          => 'Registrar o romaneio da carga que sai do campo.',
    'mip'               => 'Registrar o monitoramento de pragas e doenças e gerar o alerta.',
    'estoque'           => 'Fazer o saldo inicial e dar entrada de nota no estoque.',
    'compras'           => 'Receber uma compra que chegou à fazenda.',
    'custos'            => 'Conferir o custo realizado da safra.',
    'financeiro'        => 'Baixar títulos para fechar o ciclo do dinheiro.',
];

// modulo -> ordem canônica de aprendizagem
$ORDEM_MODULO = [
    'operacao-agricola' => 1,
    'colheita'          => 2,
    'mip'               => 3,
    'estoque'           => 4,
    'compras'           => 5,
    'custos'            => 6,
    'financeiro'        => 7,
];

// perfil -> papel legível
$PAPEL = [
    'operador'     => 'Operador',
    'mao_de_obra'  => 'Mão de obra',
    'monitor'      => 'Monitor',
    'encarregado'  => 'Encarregado',
    'almoxarifado' => 'Almoxarifado',
    'financeiro'   => 'Financeiro',
    'gestor'       => 'Gestor',
    'rt_gerente'   => 'RT/Gerente',
    'dono'         => 'Dono',
];

// perfil -> ordem de exibição da trilha
$ORDEM_PERFIL = [
    'operador'     => 1,
    'mao_de_obra'  => 2,
    'monitor'      => 3,
    'encarregado'  => 4,
    'almoxarifado' => 5,
    'financeiro'   => 6,
    'gestor'       => 7,
    'rt_gerente'   => 8,
    'dono'         => 9,
];

// perfil -> público (quem é a trilha)
$PUBLICO = [
    'operador'     => 'Para quem executa as tarefas no campo e registra o que foi feito.',
    'mao_de_obra'  => 'Para quem trabalha na lavoura e finaliza os apontamentos das tarefas.',
    'monitor'      => 'Para quem faz o monitoramento de pragas e doenças (MIP) no campo.',
    'encarregado'  => 'Para quem lidera a turma no campo e emite as ordens de serviço.',
    'almoxarifado' => 'Para quem controla o estoque e recebe as compras da fazenda.',
    'financeiro'   => 'Para quem cuida do dinheiro: títulos, baixas e custo da safra.',
    'gestor'       => 'Para quem coordena a operação, os custos e as compras da fazenda.',
    'rt_gerente'   => 'Para o responsável técnico / gerente que planeja a safra e acompanha os custos.',
];

// perfil -> "o que você NÃO precisa aprender" (markdown, bullets)
$FORA = [
    'operador'     => "- Você não precisa abrir a safra nem configurar permissões\n- Você não precisa mexer no financeiro nem nos custos\n- Você não precisa dar entrada de nota no estoque",
    'mao_de_obra'  => "- Você não precisa emitir ordens de serviço\n- Você não precisa mexer no estoque nem no financeiro",
    'monitor'      => "- Você não precisa emitir ordens de serviço nem fechar a safra\n- Você não precisa mexer no estoque nem no financeiro",
    'encarregado'  => "- Você não precisa fechar títulos no financeiro\n- Você não precisa configurar o sistema nem as permissões",
    'almoxarifado' => "- Você não precisa mexer na operação agrícola do campo\n- Você não precisa baixar títulos no financeiro",
    'financeiro'   => "- Você não precisa emitir ordens de serviço nem apontar tarefas no campo\n- Você não precisa dar entrada de nota no estoque",
    'gestor'       => "- Você não precisa apontar tarefas no campo no dia a dia\n- Você não precisa fazer o saldo inicial do estoque manualmente",
    'rt_gerente'   => "- Você não precisa dar entrada de nota no estoque\n- Você não precisa baixar títulos no financeiro",
];

/* ---------- Carrega cápsulas publicadas ---------- */

$capsulas = $pdo->query(
    "SELECT id, titulo, modulo, nivel, COALESCE(duracao_seg,0) AS duracao_seg
       FROM uni_capsula
      WHERE status='publicado'
      ORDER BY modulo ASC, titulo ASC"
)->fetchAll();

if (!$capsulas) {
    fwrite(STDERR, "Nenhuma cápsula publicada encontrada. Nada a semear.\n");
    exit(1);
}

// agrupa por módulo
$porModulo = [];               // modulo => [ {id,titulo,duracao_seg,nivel}, ... ]
foreach ($capsulas as $c) {
    $porModulo[$c['modulo']][] = $c;
}

// perfis 'nucleo' por cápsula
$papeis = $pdo->query(
    "SELECT cp.capsula_id, cp.perfil
       FROM uni_capsula_papel cp
       JOIN uni_capsula c ON c.id = cp.capsula_id
      WHERE c.status='publicado' AND cp.relevancia='nucleo'"
)->fetchAll();

$nucleoPorCapsula = [];  // capsula_id => [perfil,...]
$modulosPorPerfil = [];  // perfil => set de modulos
$capId2Modulo = [];
foreach ($capsulas as $c) { $capId2Modulo[$c['id']] = $c['modulo']; }
foreach ($papeis as $p) {
    $nucleoPorCapsula[$p['capsula_id']][] = $p['perfil'];
    $mod = $capId2Modulo[$p['capsula_id']] ?? null;
    if ($mod !== null) $modulosPorPerfil[$p['perfil']][$mod] = true;
}

/* ---------- Helper: nível predominante de um conjunto de cápsulas ---------- */
$nivelRank = ['iniciante' => 1, 'intermediario' => 2, 'expert' => 3];
$rank2nivel = [1 => 'iniciante', 2 => 'intermediario', 3 => 'expert'];
$nivelCurso = function(array $caps) use ($nivelRank, $rank2nivel): string {
    // usa o maior nível presente (mais exigente) como nível do curso
    $max = 1;
    foreach ($caps as $c) {
        $r = $nivelRank[$c['nivel']] ?? 1;
        if ($r > $max) $max = $r;
    }
    return $rank2nivel[$max];
};

/* ============================================================
   SEMEADURA
   ============================================================ */
$pdo->beginTransaction();

$cursoIdPorModulo = [];   // modulo => curso_id
$nivelPorModulo   = [];   // modulo => nivel
$cursosCriados = 0; $vincCC = 0;

$upCurso = $pdo->prepare(
    "INSERT INTO uni_curso (slug, titulo, resumo, modulo, ordem, ativo)
     VALUES (:slug, :titulo, :resumo, :modulo, :ordem, 1)
     ON DUPLICATE KEY UPDATE
        titulo = VALUES(titulo),
        resumo = VALUES(resumo),
        modulo = VALUES(modulo),
        ordem  = VALUES(ordem),
        ativo  = 1"
);
$idCurso = $pdo->prepare("SELECT id FROM uni_curso WHERE slug = :slug");
$delCC   = $pdo->prepare("DELETE FROM uni_curso_capsula WHERE curso_id = :cid");
$insCC   = $pdo->prepare(
    "INSERT INTO uni_curso_capsula (curso_id, capsula_id, ordem, obrigatorio)
     VALUES (:cid, :capid, :ordem, 1)"
);

// ordena os módulos pela ordem canônica
$modulos = array_keys($porModulo);
usort($modulos, function($a, $b) use ($ORDEM_MODULO) {
    return ($ORDEM_MODULO[$a] ?? 99) <=> ($ORDEM_MODULO[$b] ?? 99);
});

foreach ($modulos as $mod) {
    $caps  = $porModulo[$mod];
    $slug  = 'curso-' . $mod;
    $titulo = $TITULO_CURSO[$mod] ?? ucfirst(str_replace('-', ' ', $mod));
    $resumo = $RESUMO_CURSO[$mod] ?? null;
    $ordem  = $ORDEM_MODULO[$mod] ?? 99;

    $upCurso->execute([
        ':slug' => $slug, ':titulo' => $titulo, ':resumo' => $resumo,
        ':modulo' => $mod, ':ordem' => $ordem,
    ]);
    $idCurso->execute([':slug' => $slug]);
    $cid = (int)$idCurso->fetchColumn();
    $cursoIdPorModulo[$mod] = $cid;
    $nivelPorModulo[$mod]   = $nivelCurso($caps);
    $cursosCriados++;

    // rebuild vínculos curso->cápsula (cápsulas já ordenadas por título)
    $delCC->execute([':cid' => $cid]);
    $o = 0;
    foreach ($caps as $c) {
        $insCC->execute([':cid' => $cid, ':capid' => $c['id'], ':ordem' => ++$o]);
        $vincCC++;
    }
}

/* ---------- Trilhas por papel ---------- */

$upTrilha = $pdo->prepare(
    "INSERT INTO uni_trilha (slug, titulo, publico, fora_do_escopo_md, tempo_estimado_min, ordem, ativo)
     VALUES (:slug, :titulo, :publico, :fora, :tempo, :ordem, 1)
     ON DUPLICATE KEY UPDATE
        titulo = VALUES(titulo),
        publico = VALUES(publico),
        fora_do_escopo_md = VALUES(fora_do_escopo_md),
        tempo_estimado_min = VALUES(tempo_estimado_min),
        ordem = VALUES(ordem),
        ativo = 1"
);
$idTrilha = $pdo->prepare("SELECT id FROM uni_trilha WHERE slug = :slug");
$delTC = $pdo->prepare("DELETE FROM uni_trilha_curso  WHERE trilha_id = :tid");
$delTP = $pdo->prepare("DELETE FROM uni_trilha_perfil WHERE trilha_id = :tid");
$insTC = $pdo->prepare(
    "INSERT INTO uni_trilha_curso (trilha_id, curso_id, ordem, nivel)
     VALUES (:tid, :cid, :ordem, :nivel)"
);
$insTP = $pdo->prepare(
    "INSERT INTO uni_trilha_perfil (trilha_id, perfil) VALUES (:tid, :perfil)"
);

$trilhasCriadas = 0; $vincTC = 0; $vincTP = 0;
$mapa = [];  // relato: trilha slug => [cursos titulos]

// perfis nucleo, ordenados
$perfis = array_keys($modulosPorPerfil);
usort($perfis, function($a, $b) use ($ORDEM_PERFIL) {
    return ($ORDEM_PERFIL[$a] ?? 99) <=> ($ORDEM_PERFIL[$b] ?? 99);
});

foreach ($perfis as $perfil) {
    // módulos onde este perfil é núcleo -> cursos, na ordem canônica
    $mods = array_keys($modulosPorPerfil[$perfil]);
    usort($mods, function($a, $b) use ($ORDEM_MODULO) {
        return ($ORDEM_MODULO[$a] ?? 99) <=> ($ORDEM_MODULO[$b] ?? 99);
    });

    // tempo estimado = soma das durações das cápsulas (distintas) dos cursos da trilha
    $capsSeen = [];
    $totalSeg = 0;
    foreach ($mods as $mod) {
        foreach ($porModulo[$mod] as $c) {
            if (isset($capsSeen[$c['id']])) continue;
            $capsSeen[$c['id']] = true;
            $totalSeg += (int)$c['duracao_seg'];
        }
    }
    $tempoMin = (int)round($totalSeg / 60);

    $slug = $perfil;
    $papel = $PAPEL[$perfil] ?? ucfirst($perfil);
    $titulo = 'Trilha do ' . $papel;

    $upTrilha->execute([
        ':slug' => $slug,
        ':titulo' => $titulo,
        ':publico' => $PUBLICO[$perfil] ?? null,
        ':fora' => $FORA[$perfil] ?? null,
        ':tempo' => $tempoMin,
        ':ordem' => $ORDEM_PERFIL[$perfil] ?? 99,
    ]);
    $idTrilha->execute([':slug' => $slug]);
    $tid = (int)$idTrilha->fetchColumn();
    $trilhasCriadas++;

    // rebuild vínculos trilha->curso e trilha->perfil
    $delTC->execute([':tid' => $tid]);
    $delTP->execute([':tid' => $tid]);

    $o = 0;
    $mapa[$slug] = [];
    foreach ($mods as $mod) {
        $cid = $cursoIdPorModulo[$mod];
        $insTC->execute([
            ':tid' => $tid, ':cid' => $cid, ':ordem' => ++$o,
            ':nivel' => $nivelPorModulo[$mod],
        ]);
        $vincTC++;
        $mapa[$slug][] = $TITULO_CURSO[$mod] ?? $mod;
    }
    $insTP->execute([':tid' => $tid, ':perfil' => $perfil]);
    $vincTP++;
}

$pdo->commit();

/* ---------- Contagens finais (do banco) ---------- */
$q = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
echo "== Semeadura Universidade VERO — cursos & trilhas ==\n";
echo "  Cursos ...................... {$q('SELECT COUNT(*) FROM uni_curso')}\n";
echo "  Vínculos curso->cápsula ..... {$q('SELECT COUNT(*) FROM uni_curso_capsula')}\n";
echo "  Trilhas ..................... {$q('SELECT COUNT(*) FROM uni_trilha')}\n";
echo "  Vínculos trilha->curso ...... {$q('SELECT COUNT(*) FROM uni_trilha_curso')}\n";
echo "  Vínculos trilha->perfil ..... {$q('SELECT COUNT(*) FROM uni_trilha_perfil')}\n";
echo "  (nesta execução: {$cursosCriados} cursos, {$trilhasCriadas} trilhas upsertados)\n\n";

echo "== Mapa trilha -> cursos ==\n";
foreach ($mapa as $slug => $cursos) {
    echo "  [{$slug}] " . implode(' · ', $cursos) . "\n";
}
