<?php
declare(strict_types=1);
/* ============================================================
   VERO — Financeiro / Alertas de vencimento (A3-T13)
   Emissor idempotente da categoria `financeiro` em agro_alertas
   (contrato: dono A3 — DB_CONTRACT, tabela de alertas).
   Como "vencido" muda com o passar do tempo (sem nenhuma
   movimentação), a reemissão roda na carga das telas do
   financeiro, além das ações de baixa (PRG cobre os POSTs).
   Regras:
   - título aberto VENCIDO  → severidade `critico`;
   - título aberto vencendo em até 7 dias → severidade `atencao`.
   Origem: origem_tipo='movimentacao_financeira', origem_id=título.
   PRESERVAÇÃO DE STATUS (correção da auditoria de 04/07): alerta
   já `reconhecido`/`resolvido` NÃO volta para aberto a cada carga —
   o conteúdo (título/mensagem/severidade/data) é atualizado e o
   status do usuário é mantido; a ÚNICA exceção é a escalada
   atenção→crítico (a conta VENCEU depois do reconhecimento), que
   reabre o alerta. Alerta cuja condição sumiu (baixado/cancelado/
   renegociado) é removido.
   ============================================================ */

const VERO_FIN_AVISO_VENCIMENTO_DIAS = 7;

function fin_reemitir_alertas_vencimento(): void
{
    $t = vero_tenant();
    $pdo = vero_pdo();

    /* estado atual, por origem (título) */
    $existentes = [];
    foreach (vero_rows(
        "SELECT id, origem_id, status, severidade FROM agro_alertas
          WHERE tenant_id = :t AND categoria = 'financeiro'
            AND origem_tipo = 'movimentacao_financeira'", [':t' => $t]) as $a) {
        $existentes[(int)$a['origem_id']] = $a;
    }

    /* condição vigente: títulos abertos vencidos ou vencendo em ≤7d */
    $limite = date('Y-m-d', strtotime('+' . VERO_FIN_AVISO_VENCIMENTO_DIAS . ' days'));
    $titulos = vero_rows(
        "SELECT id, tipo, descricao, valor, data_vencimento
           FROM movimentacoes_financeiras
          WHERE tenant_id = :t AND status = 'aberto'
            AND data_vencimento IS NOT NULL AND data_vencimento <= :lim
          ORDER BY data_vencimento", [':t' => $t, ':lim' => $limite]);

    $vigentes = [];
    foreach ($titulos as $m) {
        $vencido = (string)$m['data_vencimento'] < date('Y-m-d');
        $dias = (int)floor((strtotime((string)$m['data_vencimento']) - strtotime(date('Y-m-d'))) / 86400);
        $rotuloTipo = $m['tipo'] === 'receber' ? 'Conta a receber' : 'Conta a pagar';
        $vigentes[(int)$m['id']] = [
            'severidade' => $vencido ? 'critico' : 'atencao',
            'titulo'     => $rotuloTipo . ' ' . ($vencido ? 'VENCIDA' : 'vence em ' . $dias . ' dia(s)')
                            . ' — R$ ' . numFmt((float)$m['valor'], 2),
            'mensagem'   => mb_substr((string)$m['descricao'], 0, 140)
                            . ' · vencimento ' . date('d/m/Y', strtotime((string)$m['data_vencimento']))
                            . '. Baixe ou renegocie na tela de Contas a '
                            . ($m['tipo'] === 'receber' ? 'Receber' : 'Pagar') . '.',
            'data'       => date('Y-m-d'),
        ];
    }

    /* condição sumiu → remove o alerta */
    foreach ($existentes as $origemId => $a) {
        if (!isset($vigentes[$origemId])) {
            $pdo->prepare("DELETE FROM agro_alertas WHERE tenant_id = ? AND id = ?")
                ->execute([$t, (int)$a['id']]);
        }
    }

    foreach ($vigentes as $origemId => $novo) {
        $atual = $existentes[$origemId] ?? null;
        if ($atual !== null) {
            /* atualiza conteúdo preservando o status do usuário;
               escalada atenção→crítico (venceu depois) reabre */
            $dados = [
                'severidade' => $novo['severidade'],
                'titulo'     => $novo['titulo'],
                'mensagem'   => $novo['mensagem'],
                'data'       => $novo['data'],
            ];
            if ($novo['severidade'] === 'critico' && (string)$atual['severidade'] !== 'critico'
                && (string)$atual['status'] !== 'aberto') {
                $dados['status'] = 'aberto';
            }
            vero_update('agro_alertas', (int)$atual['id'], $dados);
        } else {
            vero_insert('agro_alertas', [
                'categoria'   => 'financeiro',
                'origem_tipo' => 'movimentacao_financeira',
                'origem_id'   => $origemId,
                'severidade'  => $novo['severidade'],
                'titulo'      => $novo['titulo'],
                'mensagem'    => $novo['mensagem'],
                'requer_validacao_tecnica' => 0,
                'status'      => 'aberto',
                'data'        => $novo['data'],
            ]);
        }
    }
}
