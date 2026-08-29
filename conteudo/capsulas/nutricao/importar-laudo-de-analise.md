---
slug: importar-laudo-de-analise
titulo: "Importar um laudo em PDF com a IA e revisar antes de gravar"
tipo: FAZER
modulo: nutricao
objetivo: importar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /nutricao/importar_laudo.php
    principal: true
permissoes: [nutricao.importar_laudo.editar]
papeis:
  nucleo: [rt_gerente, agronomo, gestor]
  consulta: [dono]
relacoes:
  relacionado: [registrar-analise-de-solo, registrar-analise-foliar]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Em vez de digitar nutriente por nutriente, você envia o PDF do laboratório e a IA extrai os resultados. Mas a IA só propõe — quem grava a análise é você, depois de conferir cada campo. É um atalho para o cadastro, não um substituto do olho do responsável técnico.

## Como fazer
1. Escolha o **tipo** do laudo (solo ou foliar) e envie o **PDF** original (arquivo válido, dentro do limite de tamanho).
2. Clique em **Extrair**: a IA lê o documento e devolve os resultados em tela.
3. **Revise TODOS os campos** — a revisão humana é obrigatória. Corrija qualquer valor, unidade ou data que a IA tenha lido errado.
4. Preencha a **data da amostra** e clique em **Confirmar** para gravar a análise de verdade.
5. Confira a classificação resultante, igual a uma análise digitada à mão.

## Erros comuns
- **Confirmar sem revisar.** A IA erra vírgula, unidade e às vezes troca nutriente. Confirmar direto grava o erro na safra — leia campo a campo.
- **Enviar arquivo que não é PDF.** Fotos, prints e planilhas são recusados; exporte o laudo em PDF original do laboratório.
- **Teto mensal de IA atingido.** Quando o custo estimado do mês estoura, a extração é bloqueada — use a digitação manual ou o CSV até virar o mês.

## Antes e depois
Antes: Ter o laudo em PDF e as faixas cadastradas. Depois: A análise gravada é classificada e alimenta o Painel de Nutrientes.

## Roteiro do vídeo
- Abrir Importar Laudo, escolher tipo e anexar um PDF.
- Clicar em Extrair e mostrar a IA preenchendo os campos.
- Narrar: "A IA propõe; você confere." Corrigir um valor de propósito.
- Preencher data da amostra, Confirmar, mostrar a análise gravada e classificada.
