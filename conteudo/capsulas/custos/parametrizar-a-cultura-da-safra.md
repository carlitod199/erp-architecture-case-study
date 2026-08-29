---
slug: parametrizar-a-cultura-da-safra
titulo: "Parametrizar a cultura da safra"
tipo: FAZER
modulo: custos
objetivo: parametrizar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /custeio/parametros_cultura.php
    principal: true
tela_app: parametros_cultura
permissoes: [custeio.parametros_cultura.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono]
relacoes:
  prerequisito: [configurar-a-metodologia-de-custeio]
  relacionado: [montar-o-orcamento-de-producao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui você define, por cultura e safra, o que se espera dela: produtividade prevista (kg/ha), preço previsto e área prevista, além da metodologia de custeio que vai reger o orçamento. São esses parâmetros que o orçamento de produção puxa como ponto de partida e que fazem os indicadores (custo/kg, ponto de equilíbrio) terem sentido.

## Como fazer
1. Abra **Parâmetros de Cultura** e selecione a **safra** no topo.
2. Adicione ou edite um parâmetro escolhendo **cultura** e a **metodologia ativa** que vai reger o custo.
3. Informe **produtividade prevista, preço previsto e área prevista** — nenhum valor pode ser negativo.
4. Se precisar, ajuste as **unidades da cultura** (unidade comercial, fator de conversão, peso por unidade) nos campos inline.
5. Clique em **Salvar**. Um mesmo par cultura+safra é atualizado no lugar (não duplica).

## Erros comuns
- **Apagar a unidade da cultura sem querer.** Campo de unidade deixado em branco **não apaga** o que já está gravado na cultura — só grava o que você preencher. Mas lembre: unidade vale para **todas as safras**, pois é gravada na cultura, não no parâmetro.
- **Escolher uma metodologia inativa.** Só metodologia **ativa** é aceita — ative-a em Metodologias antes.
- **Deixar produtividade ou preço em branco.** Sem eles, o orçamento de produção gera indicadores vazios. Preencha o que se espera da safra.

## Antes e depois
Antes: Configurar a metodologia de custeio que a cultura vai usar. Depois: Montar o orçamento de produção puxando estes parâmetros.

## Roteiro do vídeo
- Abrir Parâmetros de Cultura e escolher a safra.
- Cadastrar uma cultura com produtividade, preço e área previstos.
- Salvar e mostrar que reeditar atualiza no lugar, sem duplicar.
