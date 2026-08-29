---
slug: comparar-o-custo-entre-safras
titulo: "Comparar o custo entre safras"
tipo: CONSULTAR
modulo: custos
objetivo: comparar
jornada: [safra]
nivel: iniciante
duracao_seg: 100
rotas:
  - rota: /custeio/comparativo_safras.php
    principal: true
tela_app: comparativo_safras
permissoes: [custos.comparativo_safras.ver]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono, encarregado]
relacoes:
  relacionado: [conferir-custo-realizado-da-safra, fechar-a-safra-no-custeio]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O comparativo coloca até quatro safras lado a lado — área, produção, faturamento, custo por categoria, custo/ha, custo/kg, resultado e margem — para responder "a 2026.2 foi melhor que a 2026.1 no mesmo parreiral?". Como a safra do VERO é o ciclo de poda, dá para comparar ciclos do mesmo talhão ao longo do tempo.

## Como fazer
1. Abra **Comparativo entre Safras**. Ele já vem com as duas safras mais recentes.
2. Selecione as **safras** que quer confrontar — até quatro de uma vez.
3. Leia as linhas lado a lado, com atenção ao **custo/ha** e ao **custo/kg**, que neutralizam diferenças de tamanho.
4. Olhe a quebra por **categoria** para descobrir onde uma safra gastou mais que a outra.

## Erros comuns
- **Comparar pelo custo total absoluto.** Safras de áreas diferentes têm totais diferentes por natureza — compare pelo custo por hectare e por kg, não pelo total.
- **Comparar safra fechada com safra em andamento.** Uma safra ainda aberta tem custo incompleto; verifique o status antes de tirar conclusões.

## Antes e depois
Antes: Conferir o custo realizado de cada safra. Depois: Usar o aprendizado para calibrar o orçamento da próxima.

## Roteiro do vídeo
- Abrir o Comparativo com duas safras.
- Adicionar uma terceira e narrar a leitura de custo/ha e custo/kg.
- Apontar a categoria que mais variou entre elas.
