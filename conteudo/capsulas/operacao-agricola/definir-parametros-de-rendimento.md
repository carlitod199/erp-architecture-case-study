---
slug: definir-parametros-de-rendimento
titulo: "Definir os parâmetros de rendimento de mão de obra"
tipo: FAZER
modulo: operacao-agricola
objetivo: configurar
jornada: [implantacao]
nivel: expert
duracao_seg: 180
rotas:
  - rota: /agro/parametros_rendimento.php
    principal: true
permissoes: [agro.tipos_atividade.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado]
relacoes:
  prerequisito: [cadastrar-tipo-de-atividade]
  proximo: [calcular-diarias-de-mao-de-obra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Estes parâmetros são o que liga a Calculadora de Mão de Obra a cada tipo de atividade: rendimento por diária, fator de ajuste e custo da diária. O sistema nunca inventa rendimento — sem esse cadastro, a calculadora avisa e espera. Os valores são versionados por vigência.

## Como fazer
1. Abra **Parâmetros de Rendimento (MO)** e clique no lápis do tipo de atividade.
2. Informe o **rendimento por diária** (quanto 1 pessoa faz por dia, na unidade da atividade).
3. Ajuste o **fator** (1,0 = normal) e, se quiser, use **Puxar custo automático** para trazer a diária própria (por equipe CLT) e a terceirizada da folha.
4. Defina o **início da vigência** — os apontamentos usam a vigência da data.
5. Clique em **Gravar parâmetros** — a versão anterior é desativada (a trilha fica) e a calculadora já usa a nova.

## Erros comuns
- **Deixar um campo vazio achando que zera.** Campo vazio NÃO mexe na chave — ele preserva o valor vigente. Para mudar, digite o novo valor.
- **Valor negativo.** A gravação é bloqueada: nenhum parâmetro pode ser negativo.
- **Esquecer a vigência.** Corrigir um valor cria uma nova vigência a partir da data informada; apontamentos antigos continuam com a vigência que valia na data deles.
- **Colheita medida por planta.** Na colheita o rendimento é em caixas (ou kg) por pessoa/dia; a calculadora parte da produção prevista e converte pelo peso da caixa, nunca pelo nº de plantas.

## Antes e depois
Antes: Cadastrar o tipo de atividade. Depois: Usar a Calculadora de Diárias para dimensionar a equipe.

## Roteiro do vídeo
- Abrir Parâmetros de Rendimento e editar um tipo (ex.: Poda).
- Informar rendimento/diária e fator; usar "Puxar custo automático" por equipe.
- Definir a vigência, gravar e mostrar o valor vigente na tabela.
