<?php
declare(strict_types=1);
/* ============================================================
   VERO — CRM Consultor / Automações (protótipo)
   Rota: /crm/consultor/automacoes · as 26 regras que alimentam
   o radar, agrupadas por família. Dados fictícios do mockup.
   ============================================================ */
require_once dirname(__DIR__) . '/_lib.php';

/* TODO mover para _mock.php — [família, nome, problema, o que o sistema faz, benefício] */
$AUTOMACOES = [
    ['Comercial', 'Oportunidade parada',
     'Negócios morrem em silêncio: ninguém percebe que a oportunidade não anda.',
     'Compara o tempo na etapa com a média histórica da carteira e gera uma ação com o valor em risco.',
     'Recuperação de receita que hoje se perde por esquecimento.'],
    ['Comercial', 'Oportunidade fenológica automática',
     'A janela de venda de um insumo abre e fecha com o estágio do talhão — e passa despercebida.',
     'Ao entrar num estágio-gatilho (pré-poda, chumbinho, 2º fluxo maduro), cria a oportunidade com produto, área e valor estimado.',
     'Venda no momento certo, sem depender da memória do consultor.'],
    ['Comercial', 'Reabertura de ciclo',
     'Recompra depende do consultor lembrar quando o produtor comprou no ciclo anterior.',
     '30 dias antes da data equivalente do ciclo anterior, reabre a oportunidade com o histórico do que foi comprado.',
     'Taxa de recompra entre ciclos deixa de depender da agenda pessoal.'],
    ['Visitas', 'Briefing pré-visita',
     'O consultor entra na fazenda sem lembrar o que ficou pendente da última vez.',
     'Monta automaticamente uma tela com últimas interações, pendências, estágio dos talhões, crédito e oportunidades abertas.',
     'Visita começa com contexto; menos tempo perdido em recapitulação.'],
    ['Visitas', 'Relatório de visita gerado do registro',
     'Escrever o laudo depois da visita é a tarefa mais adiada do dia.',
     'Transforma achados, fotos, GPS e áudio do registro em um relatório técnico pronto para envio.',
     'Elimina a redigitação e garante que o registro exista.'],
    ['Visitas', 'Visita sem próxima ação',
     'Visita que termina sem próximo passo definido raramente vira negócio.',
     'Bloqueia o encerramento sem próxima ação e data; se ainda assim ficar vazio, entra no radar em 24h.',
     'Disciplina de follow-up sem cobrança manual do gestor.'],
    ['Follow-up', 'Promessa vira tarefa',
     '"Te mando a cotação amanhã" morre no WhatsApp.',
     'Toda promessa registrada na visita vira ação com data e dono, e sobe para o topo do Meu Dia no vencimento.',
     'Nada prometido ao produtor se perde.'],
    ['Follow-up', 'Cliente sem contato',
     'Clientes bons somem devagar, e só se percebe quando o concorrente já entrou.',
     'Define frequência-alvo por classe (A/B/C) e alerta quando o intervalo é ultrapassado, cruzando com queda de compra.',
     'Retenção da carteira antes da perda.'],
    ['Agenda', 'Roteiro sugerido do dia',
     'Montar rota manualmente consome a primeira hora do dia.',
     'Ordena as visitas por distância, janela do produtor e prioridade do radar; sugere encaixes no caminho.',
     'Mais visitas por dia com menos quilômetro rodado.'],
    ['Agenda', 'Encaixe por proximidade',
     'O consultor passa na porta de um cliente sem saber que ele está sem visita há 50 dias.',
     'Cruza o roteiro do dia com o raio de 15 km e lista quem vale encaixar.',
     'Aproveita deslocamento já pago.'],
    ['Relacionamento', 'Resumo do produtor sempre atualizado',
     'A memória do cliente mora na cabeça do consultor e vai embora com ele.',
     'Consolida visitas, recomendações, pedidos, reclamações e preferências numa linha do tempo única por produtor.',
     'Continuidade em troca de consultor e onboarding rápido.'],
    ['Relacionamento', 'Pós-venda programado',
     'Ninguém volta para ver se a recomendação funcionou.',
     'Agenda automaticamente a visita de avaliação no prazo técnico do produto recomendado.',
     'Prova de resultado — o argumento de venda mais forte que existe.'],
    ['Conformidade', 'Bloqueio por carência',
     'Recomendar um produto perto da colheita compromete o lote inteiro.',
     'Cruza carência do produto com a data prevista de colheita do talhão e bloqueia a recomendação com alternativa sugerida.',
     'Evita perda de lote e de certificação.'],
    ['Conformidade', 'Filtro por mercado de destino',
     'Cada importador tem sua lista de moléculas aprovadas.',
     'Filtra o catálogo pelo destino da fruta (UE, EUA, RU, interno) e sinaliza o que sai da lista.',
     'Recomendação já nasce exportável.'],
    ['Conformidade', 'Caderno de campo alimentado pela visita',
     'O mesmo dado é digitado na recomendação, no caderno e no ERP.',
     'A recomendação aprovada gera o registro no caderno de campo e o rascunho do pedido.',
     'Um registro, três destinos — e evidência pronta para auditoria.'],
    ['Nutrição & análises', 'Interpretação automática do laudo',
     'O laudo chega em PDF e fica esquecido na pasta.',
     'Lê o arquivo do laboratório, associa ao talhão, compara cada parâmetro com a faixa da cultura e marca os desvios.',
     'O laudo vira diagnóstico no mesmo dia em que é emitido.'],
    ['Nutrição & análises', 'Desvio vira recomendação e oportunidade',
     'Entre o resultado e a venda existem duas semanas de esquecimento.',
     'Cada desvio classificado gera a recomendação padrão da cultura e a oportunidade com talhão, área e dose já preenchidos.',
     'Encurta o caminho do laudo até o pedido.'],
    ['Nutrição & análises', 'Análise vencida',
     'A amostragem atrasa e o ciclo começa sem base técnica.',
     'Controla a periodicidade por cultura e estágio — solo por ciclo no repouso, foliar em pleno florescimento — e avisa antes de a janela fechar.',
     'Nenhum ciclo começa às cegas.'],
    ['Nutrição & análises', 'Alerta de salinidade',
     'Em área irrigada do semiárido o sal se acumula em silêncio.',
     'Monitora CE e PST entre laudos e alerta na tendência, antes do sintoma foliar aparecer.',
     'Correção antes da perda de vigor do talhão.'],
    ['Rentabilidade', 'Cliente com margem negativa',
     'Faturamento alto esconde cliente que dá prejuízo.',
     'Fecha o DRE por cliente a cada ciclo e sinaliza quem tem margem de contribuição ou resultado negativo, com a linha responsável.',
     'Rentabilidade deixa de ser descoberta no fim do ano.'],
    ['Rentabilidade', 'Desconto que zera a margem',
     'O desconto é dado no fechamento, sem ninguém calcular o efeito.',
     'Simula o impacto na margem antes de aprovar e bloqueia acima do teto da classe do cliente.',
     'Desconto vira decisão, não reflexo.'],
    ['Rentabilidade', 'Prazo e inadimplência no funil',
     'Vende-se a prazo para quem já está vencido.',
     'Cruza limite, títulos vencidos e prazo médio na etapa de conformidade e trava o avanço.',
     'Evita a venda que nasce com prejuízo.'],
    ['Rentabilidade', 'ROI de atendimento',
     'A frequência de visita é definida por hábito, não por retorno.',
     'Divide a margem de contribuição pelo custo de atendimento e sugere quem visitar mais, quem encaixar na rota e quem atender remotamente.',
     'Realoca o tempo do consultor para onde ele rende.'],
    ['Indicadores', 'Fechamento semanal automático',
     'Relatório de atividade toma a sexta-feira do consultor.',
     'Gera na sexta o resumo de visitas, km, oportunidades movimentadas e pendências, pronto para envio.',
     'Devolve horas de trabalho por semana.'],
    ['Gestores', 'Painel de cobertura de carteira',
     'O gestor não sabe quais clientes estão descobertos até o resultado cair.',
     'Mapa de calor de cobertura por consultor, classe e região, com lista de clientes fora da frequência-alvo.',
     'Gestão por exceção em vez de cobrança genérica.'],
    ['Gestores', 'Alerta de desvio de meta',
     'O desvio aparece no fim do mês, quando não dá mais para reagir.',
     'Projeta o fechamento com base no ritmo atual e no pipeline ponderado, alertando quando a projeção cai abaixo da meta.',
     'Tempo de reação dentro do período.'],
];

/* agrupa preservando a ordem das famílias */
$grupos = [];
foreach ($AUTOMACOES as $a) $grupos[$a[0]][] = $a;

crm_shell_start([
    'modulo' => 'consultor',
    'micro'  => 'automacoes',
    'titulo' => 'Automações',
    'acoes'  => '<button type="button" class="vbtn vbtn-primary" data-toast="Nova regra · demonstrativo">＋ Nova regra</button>',
]);
?>

<?= crm_callout('<strong>' . count($AUTOMACOES) . ' automações em ' . count($grupos) . ' famílias.</strong> '
    . 'Cada uma existe para eliminar uma tarefa que hoje depende da memória ou da disciplina do consultor.', 'teal') ?>

<?php foreach ($grupos as $nome => $itens): ?>
  <div class="crm-card" style="margin-top:14px">
    <div class="crm-card__head">
      <span class="crm-card__title"><?= h($nome) ?></span>
      <?= crm_pill(count($itens) . ' regras', 'teal') ?>
    </div>
    <div class="crm-tblwrap">
      <table class="crm-tbl">
        <thead>
          <tr>
            <th style="width:190px">Automação</th>
            <th>Problema</th>
            <th>O que o sistema faz</th>
            <th>Benefício</th>
            <th class="num">Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($itens as $a): ?>
            <tr>
              <td><strong><?= h($a[1]) ?></strong></td>
              <td style="color:var(--crm-ink2)"><?= h($a[2]) ?></td>
              <td><?= h($a[3]) ?></td>
              <td><?= h($a[4]) ?></td>
              <td class="num"><?= crm_pill('Ativa', 'green') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php crm_shell_end();
