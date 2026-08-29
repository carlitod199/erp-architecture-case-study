---
slug: registrar-colheita-com-classificacao
titulo: "Registrar a colheita da válvula com a classificação de qualidade"
tipo: FAZER
modulo: colheita
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 175
rotas:
  - rota: /colheita/index.php
    principal: true
tela_app: colheita_registro
permissoes: [agro.colheita.editar]
papeis:
  nucleo: [encarregado, operador, gestor]
  consulta: [rt_gerente]
relacoes:
  prerequisito: [registrar-romaneio-de-campo]
  proximo: [confirmar-entrada-da-colheita-no-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O registro de colheita fecha a produção de uma válvula numa safra: quantos kg saíram e como se dividem por qualidade (Premium, CAT 1 a 3, Perdidos). Os kg totais vêm de kg/ha × área da válvula, os kg de cada categoria de kg total × percentual, e o faturamento de kg × preço. É a base da comercialização e do resultado da safra.

## Como fazer
1. Abra **Colheita** e clique em **+ Nova colheita** (ou em Finalizar, para completar uma já iniciada).
2. Informe a **data**, a **válvula** (setor) e a **safra** — os três são obrigatórios.
3. Preencha os kg previstos e realizados da produção.
4. Distribua os **percentuais por categoria** em cada momento (previsto e realizado) e os preços — a soma dos percentuais de um momento não pode passar de 100%.
5. Clique em **Salvar colheita** — este é o passo que grava a produção e o faturamento estimado da válvula.

## Erros comuns
- **Somar mais de 100% num momento.** Se os percentuais de qualidade de previsto (ou de realizado) passam de 100%, o sistema recusa e mostra a soma. Ajuste as categorias para fechar em até 100%.
- **Faltar data, válvula ou safra.** Os três são obrigatórios; sem eles a colheita não grava. Escolha a safra da válvula para o registro entrar na trilha certa.
- **Editar uma colheita que já entrou no estoque sem cuidado.** Se ela tinha entrada ativa, o sistema estorna antes de alterar e reconfirma depois — se o estorno falhar (período fechado no custeio), a colheita não muda. Reabra a safra no fechamento antes de editar.

## Antes e depois
Antes: Registrar os romaneios das cargas que saíram do campo. Depois: Confirmar a entrada da colheita no estoque para gerar o lote.

## Roteiro do vídeo
- Abrir Colheita mostrando os registros por válvula.
- Clicar em + Nova colheita e preencher data, válvula e safra.
- Distribuir os percentuais por categoria e narrar: "A soma não pode passar de 100%."
- Mostrar o faturamento estimado sendo calculado.
- Destacar **Salvar colheita**; clicar e ver a linha na tabela.
