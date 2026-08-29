---
slug: registrar-uma-venda
titulo: "Registrar uma venda para baixar o estoque e gerar a conta a receber"
tipo: FAZER
modulo: comercial
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 190
rotas:
  - rota: /comercial/vendas.php
    principal: true
permissoes: [comercial.vendas.editar]
papeis:
  nucleo: [comercial, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-um-comprador]
  proximo: [emitir-um-romaneio-de-saida]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Registrar a venda é o passo que fecha o ciclo da produção: baixa o kg do lote agrícola no estoque e gera automaticamente a conta a receber no financeiro. Sem esse registro, a colheita continua parada no estoque e o dinheiro nunca aparece no fluxo.

## Como fazer
1. Abra **Vendas** e clique em **Nova venda**.
2. Escolha o **comprador** e o **lote agrícola** (lote COLH- disponível vindo da colheita) — venda nova exige lote.
3. Informe o **kg total**, a **qualidade** e o **preço** (a tabela de preços sugere um valor, que você pode ajustar).
4. Defina a **data da venda**, o **vencimento** e, se for parcelado, o **número de parcelas**.
5. Clique em **Salvar** — o sistema **baixa o kg do lote e gera a conta a receber** (uma parcela ou várias mensais).

## Erros comuns
- **Tentar vender sem lote.** Venda nova sem lote COLH- é barrada. Confirme a entrada da colheita no estoque (tela de Colheita) e selecione o lote antes.
- **Vender mais kg do que o lote tem.** O estoque não fecha. Confira o saldo do lote antes de lançar o kg.
- **Datar o vencimento errado.** A conta a receber nasce com o vencimento que você informar — se errar, o fluxo de caixa mostra a entrada no dia errado.
- **Cancelar criando outra venda.** Para desfazer, use **Cancelar** na própria venda: ela devolve o kg ao lote e cancela a conta a receber. Não crie um lançamento novo para "corrigir".

## Antes e depois
Antes: comprador cadastrado e colheita já lançada no estoque como lote. Depois: emitir o romaneio de saída e acompanhar a conta a receber no financeiro.

## Roteiro do vídeo
- Abrir Vendas com o formulário de nova venda.
- Narrar: "Uma venda faz duas coisas de uma vez: tira a fruta do estoque e cria a conta a receber."
- Escolher comprador, lote COLH-, kg e preço sugerido pela tabela.
- Definir vencimento e salvar.
- Mostrar a mensagem de estoque baixado e a conta a receber gerada.
