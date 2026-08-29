---
slug: montar-o-orcamento-da-safra
titulo: "Montar o orçamento da safra"
tipo: FAZER
modulo: custos
objetivo: orcar
jornada: [safra]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /custeio/orcamento.php
    principal: true
tela_app: orcamento_safra
permissoes: [custeio.orcamento_safra.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [abrir-a-safra]
  relacionado: [conferir-custo-realizado-da-safra, definir-as-metas-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O orçamento é o quanto você planeja gastar na safra, quebrado por categoria: mão de obra, insumos, máquinas, irrigação e outros. É a régua contra a qual o realizado vai ser medido depois em Realizado vs Planejado — sem orçamento, não existe desvio para acompanhar.

## Como fazer
1. Abra **Orçamento de Safra** e clique em **+ Novo orçamento**.
2. Selecione a **safra**. Cada safra só pode ter um orçamento ativo por vez.
3. Preencha o **previsto por categoria** — deixe em branco (ou zero) as categorias sem previsão.
4. Clique em **Salvar**. O sistema soma tudo e mostra o **total previsto**.
5. Quando o orçamento estiver pronto, use o botão de **tornar vigente** — é isso que o marca como a versão oficial da safra e encerra qualquer outro vigente.

## Erros comuns
- **Deixar o orçamento em rascunho.** Rascunho não é a referência oficial — só o orçamento **vigente** vira régua. Torne-o vigente quando fechar os números.
- **Tentar criar um segundo orçamento na mesma safra.** O sistema bloqueia: edite o que já existe ou encerre-o antes de abrir outro.
- **Querer editar um orçamento encerrado.** Encerrado é histórico e não muda mais. Se precisar ajustar, reabra-o como rascunho.

## Antes e depois
Antes: Abrir a safra que vai receber o orçamento. Depois: Acompanhar o realizado contra este previsto em Realizado vs Planejado.

## Roteiro do vídeo
- Abrir Orçamento de Safra e clicar em + Novo orçamento.
- Selecionar a safra e preencher duas ou três categorias.
- Salvar e narrar o total previsto aparecendo.
- Tornar vigente e mostrar o badge mudando de Rascunho para Vigente.
