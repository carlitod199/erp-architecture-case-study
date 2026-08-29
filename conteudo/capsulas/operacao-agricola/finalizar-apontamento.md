---
slug: finalizar-apontamento
titulo: "Finalizar um apontamento para o custo aparecer"
tipo: FAZER
modulo: operacao-agricola
objetivo: apontar
jornada: [safra]
nivel: iniciante
duracao_seg: 160
rotas:
  - rota: /agro/apontamentos.php
    principal: true
tela_app: apontamento_novo
permissoes: [agro.apontamentos_campo.editar]
papeis:
  nucleo: [operador, encarregado, mao_de_obra]
  consulta: [gestor, rt_gerente]
relacoes:
  prerequisito: [emitir-ordem-de-servico]
  proximo: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
proxima_revisao_em: 2027-01-24
---

## Finalidade
O apontamento é o que transforma o trabalho do dia — pessoas, horas e insumos — em custo da safra. Enquanto ele fica "iniciado", nada disso entra na conta.

## Como fazer
1. Abra o apontamento iniciado na lista de **Apontamentos de Campo**.
2. Confirme as pessoas, as horas trabalhadas e os insumos usados.
3. Clique em **Finalizar** — este é o passo que gera o custo.
4. Confira em **Custo Realizado** se o valor apareceu na safra.

## Erros comuns
- **Deixar iniciado e nunca finalizar.** O trabalho não vira custo e a Ordem de Serviço não fecha. Confira a lista de pendentes toda sexta-feira.
- **Finalizar sem lançar os insumos.** O custo de mão de obra entra, mas o de produto fica de fora — o custo por hectare sai menor do que o real.

## Antes e depois
Antes: Emitir a Ordem de Serviço que autoriza o trabalho. Depois: Conferir o Custo Realizado da safra.

## Roteiro do vídeo
- Abrir a tela Apontamentos de Campo com um apontamento iniciado visível na lista.
- Narrar: "Todo apontamento nasce iniciado. Ele só vira custo quando a gente finaliza."
- Abrir o apontamento, percorrer pessoas → horas → insumos.
- Destacar o botão **Finalizar** antes de clicar; clicar.
- Cortar para **Custo Realizado** mostrando a linha nova.
