---
slug: confirmar-entrada-da-colheita-no-estoque
titulo: "Confirmar a entrada da colheita no estoque (gerar o lote)"
tipo: FAZER
modulo: colheita
objetivo: confirmar
jornada: [safra]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /colheita/index.php
    principal: true
tela_app: colheita_entrada
permissoes: [agro.colheita.editar]
papeis:
  nucleo: [encarregado, gestor]
  consulta: [rt_gerente]
relacoes:
  prerequisito: [registrar-colheita-com-classificacao]
  proximo: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Registrar a colheita diz quanto foi produzido; confirmar a entrada no estoque transforma esses kg num lote real, valorizado. O lote entra a custo provisório — a média do custeio acumulado da safra dividida pelos kg colhidos — e é revalorizado no fechamento. É o que liga a produção do campo ao estoque de fruta pronta para comercializar.

## Como fazer
1. Na lista de **Colheita**, ache o registro já salvo da válvula.
2. Clique em **Confirmar entrada no estoque** — este é o passo que cria o lote e dá entrada nos kg colhidos.
3. Leia a mensagem: ela mostra o código do lote, os kg e o custo provisório por kg.
4. Se precisar corrigir, use **Estornar** para devolver o saldo e marcar o lote como estornado; o registro de colheita permanece.

## Erros comuns
- **Confirmar duas vezes a mesma colheita.** Se já existe entrada ativa, o sistema avisa e nada é duplicado — mostra o lote que já existia. Não force uma segunda entrada.
- **Confirmar com a safra fechada no custeio.** Se o período está fechado, a entrada é bloqueada. Reabra a safra em Custos → Fechamento de Safra e tente de novo.
- **Estranhar o custo "provisório".** O custo por kg da entrada é uma média provisória; o valor definitivo sai na revalorização do fechamento. Não trate esse número como final.

## Antes e depois
Antes: Registrar a colheita da válvula com a classificação. Depois: Conferir o Custo Realizado da safra com a produção já no estoque.

## Roteiro do vídeo
- Abrir Colheita com um registro salvo sem entrada.
- Clicar em **Confirmar entrada no estoque**.
- Ler em voz alta a mensagem com lote, kg e custo provisório.
- Mostrar o lote COLH- aparecendo no estoque.
- Demonstrar o **Estornar** devolvendo o saldo e mantendo a colheita.
