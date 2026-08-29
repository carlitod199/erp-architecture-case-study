<?php
declare(strict_types=1);
/* ============================================================
   VERO — scripts/uni_pratica_seed.php
   Semeia os exercícios de PRÁTICA da Universidade (Fazenda Escola).
   As tarefas ficam no banco SEPARADO da Universidade (uni_pdo).
   A verificacao_sql é um SELECT que roda no banco do SISTEMA (tenant
   do usuário) com os placeholders :tenant e :uid amarrados; ≥1 linha
   = sucesso.

   Idempotente: upsert por slug. Rode com:
     php scripts/uni_pratica_seed.php
   ============================================================ */

require_once __DIR__ . '/../includes/uni_db.php';

$pdo = uni_pdo();

/* Cápsulas âncora (pega o id pelo slug). */
$capsulaSlugs = [
    'finalizar-apontamento',
    'abrir-safra-e-confirmar-poda',
    'dar-entrada-de-nota-no-estoque',
];

$idPorSlug = [];
$st = $pdo->prepare("SELECT id, slug FROM uni_capsula WHERE slug = ?");
foreach ($capsulaSlugs as $cs) {
    $st->execute([$cs]);
    $row = $st->fetch();
    if (!$row) {
        fwrite(STDERR, "[ERRO] Cápsula não encontrada pelo slug: {$cs}. Semeadura abortada.\n");
        exit(1);
    }
    $idPorSlug[$cs] = (int)$row['id'];
}

/* Exercícios. verificacao_sql filtra SEMPRE por tenant_id = :tenant.
   Valores confirmados no schema do sistema (SHOW COLUMNS em 26/07/2026):
   - agro_apontamentos.status = enum('iniciado','pendente','validado','recusado')
     -> 'validado' é o apontamento finalizado/validado.
   - agro_safras.tenant_id / status = enum('planejada','ativa','encerrada')
     -> existência de uma safra do tenant.
   - estoque_movimentacoes.tipo = enum('entrada','saida','transferencia','ajuste')
     -> 'entrada' é a entrada de estoque. */
$exercicios = [
    [
        'capsula'        => 'finalizar-apontamento',
        'slug'           => 'pratica-finalizar-apontamento',
        'titulo'         => 'Finalize um apontamento na Fazenda Escola',
        'enunciado_md'   => "Vá até os apontamentos e **finalize (valide)** um apontamento da fazenda.\n\n"
                          . "Só depois de validado é que o custo dele entra na conta da safra. Quando terminar, volte aqui e clique em **Verificar**.",
        'verificacao_sql'=> "SELECT 1 FROM agro_apontamentos WHERE tenant_id = :tenant AND status = 'validado' LIMIT 1",
        'mensagem_ok'    => 'Boa! Você tem um apontamento validado — o custo dele já pode entrar na safra.',
        'mensagem_falha' => 'Ainda não achei apontamento validado. Abra um apontamento, finalize/valide e verifique de novo.',
        'ordem'          => 10,
    ],
    [
        'capsula'        => 'abrir-safra-e-confirmar-poda',
        'slug'           => 'pratica-abrir-safra',
        'titulo'         => 'Abra uma safra',
        'enunciado_md'   => "Abra uma **safra** na fazenda — é ela que amarra talhões, custos e colheita do ciclo.\n\n"
                          . "Depois de criar a safra, volte aqui e clique em **Verificar**.",
        'verificacao_sql'=> "SELECT 1 FROM agro_safras WHERE tenant_id = :tenant LIMIT 1",
        'mensagem_ok'    => 'Safra aberta! Agora o ciclo tem onde pendurar talhões, custos e colheita.',
        'mensagem_falha' => 'Ainda não encontrei nenhuma safra da fazenda. Abra a safra na tela de abertura e verifique de novo.',
        'ordem'          => 20,
    ],
    [
        'capsula'        => 'dar-entrada-de-nota-no-estoque',
        'slug'           => 'pratica-dar-entrada-estoque',
        'titulo'         => 'Registre uma entrada de estoque',
        'enunciado_md'   => "Dê **entrada** de um produto no estoque — como quando chega a nota do fornecedor.\n\n"
                          . "É a entrada que forma o saldo e o custo dos insumos. Feito isso, volte aqui e clique em **Verificar**.",
        'verificacao_sql'=> "SELECT 1 FROM estoque_movimentacoes WHERE tenant_id = :tenant AND tipo = 'entrada' LIMIT 1",
        'mensagem_ok'    => 'Entrada registrada! O saldo e o custo do insumo já estão no estoque.',
        'mensagem_falha' => 'Ainda não vi nenhuma entrada de estoque da fazenda. Registre a entrada da nota e verifique de novo.',
        'ordem'          => 30,
    ],
];

/* Upsert por slug (slug tem índice único esperado; usamos verificação manual
   para não depender de ON DUPLICATE e funcionar mesmo sem UNIQUE). */
$sel = $pdo->prepare("SELECT id FROM uni_tarefa_pratica WHERE slug = ? LIMIT 1");
$ins = $pdo->prepare(
    "INSERT INTO uni_tarefa_pratica
        (capsula_id, slug, titulo, enunciado_md, verificacao_sql, mensagem_ok, mensagem_falha, ordem, ativo)
     VALUES (?,?,?,?,?,?,?,?,1)"
);
$upd = $pdo->prepare(
    "UPDATE uni_tarefa_pratica
        SET capsula_id=?, titulo=?, enunciado_md=?, verificacao_sql=?,
            mensagem_ok=?, mensagem_falha=?, ordem=?, ativo=1
      WHERE slug=?"
);

$criados = 0; $atualizados = 0;
foreach ($exercicios as $e) {
    $capsulaId = $idPorSlug[$e['capsula']];
    $sel->execute([$e['slug']]);
    $existe = $sel->fetchColumn();
    if ($existe) {
        $upd->execute([
            $capsulaId, $e['titulo'], $e['enunciado_md'], $e['verificacao_sql'],
            $e['mensagem_ok'], $e['mensagem_falha'], $e['ordem'], $e['slug'],
        ]);
        $atualizados++;
        echo "atualizado: {$e['slug']}\n";
    } else {
        $ins->execute([
            $capsulaId, $e['slug'], $e['titulo'], $e['enunciado_md'], $e['verificacao_sql'],
            $e['mensagem_ok'], $e['mensagem_falha'], $e['ordem'],
        ]);
        $criados++;
        echo "criado: {$e['slug']}\n";
    }
}

echo "\nOK — {$criados} criado(s), {$atualizados} atualizado(s).\n";
