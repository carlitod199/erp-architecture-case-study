---
slug: conferir-custo-realizado-da-safra
titulo: "Conferir o custo realizado da safra"
tipo: FAZER
modulo: custos
objetivo: conferir
jornada: [safra]
nivel: intermediario
duracao_seg: 140
rotas:
  - rota: /custeio/custo_realizado.php
    principal: true
tela_app: custo_realizado
permissoes: [custos.custo_realizado.ver]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [finalizar-apontamento]
  relacionado: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui você vê para onde o dinheiro da safra realmente foi: mão de obra, insumos e máquinas que já foram apontados e finalizados. É a foto do custo que já aconteceu, não do planejado.

## Como fazer
1. Abra **Custo Realizado** e selecione a safra e a fazenda.
2. Leia o total e a quebra por categoria (mão de obra, insumos, máquinas).
3. Se um custo esperado não aparece, verifique se o apontamento de origem foi **finalizado**.
4. Compare com o planejado para enxergar o desvio.

## Erros comuns
- **Estranhar um custo faltando.** Quase sempre é um apontamento ainda "iniciado" lá atrás — finalize-o e o valor entra aqui.
- **Comparar safras de tamanhos diferentes sem olhar o custo por hectare.** O total absoluto engana; use o custo por hectare para comparar de verdade.

## Antes e depois
Antes: Finalizar os apontamentos que geram o custo. Depois: Fechar a safra com o resultado consolidado.

## Roteiro do vídeo
- Abrir Custo Realizado com uma safra selecionada.
- Narrar a leitura do total e da quebra por categoria.
- Mostrar o caso "custo faltando" → voltar ao apontamento iniciado → finalizar → o valor aparece.
