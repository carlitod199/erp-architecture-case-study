---
slug: registrar-monitoramento-mip
titulo: "Registrar um monitoramento de MIP para gerar o alerta"
tipo: FAZER
modulo: mip
objetivo: monitorar
jornada: [safra]
nivel: iniciante
duracao_seg: 170
rotas:
  - rota: /mip/monitoramento.php
    principal: true
tela_app: monitoramento_mip
permissoes: [mip.monitoramentos.editar]
papeis:
  nucleo: [monitor, operador]
  consulta: [rt_gerente, gestor]
relacoes:
  relacionado: [emitir-ordem-de-servico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O monitoramento é o que transforma o que você viu no talhão — pragas, doenças, nível de infestação — em alerta fitossanitário para a equipe agir. Enquanto ele fica como rascunho, ninguém é avisado e o alerta não dispara.

## Como fazer
1. Abra **Monitoramento de MIP** e selecione a safra, a fazenda e o talhão que você percorreu.
2. Lance o que encontrou: praga ou doença, nível de infestação e os pontos avaliados.
3. Revise se cada ocorrência está com o alvo e o nível certos.
4. Clique em **Concluir** para enviar o monitoramento — este é o passo que **gera o alerta fitossanitário**.
5. Confira na lista se o monitoramento saiu de rascunho e ficou concluído.

## Erros comuns
- **Deixar como rascunho e nunca concluir.** O monitoramento fica só com você, o alerta não dispara e a equipe não fica sabendo da infestação. Confira seus rascunhos antes de sair do talhão.
- **Concluir sem informar o nível de infestação.** O alerta até aparece, mas sem gravidade a equipe não sabe se é urgente — sempre registre o nível de cada ocorrência.
- **Registrar no talhão errado.** O alerta chega para a área que não tem o problema. Confirme safra, fazenda e talhão antes de concluir.

## Antes e depois
Antes: Percorrer o talhão e avaliar os pontos de monitoramento. Depois: Emitir a Ordem de Serviço que atende ao alerta.

## Roteiro do vídeo
- Abrir a tela Monitoramento de MIP com um talhão selecionado.
- Narrar: "O que a gente vê no campo só vira alerta quando o monitoramento é concluído."
- Lançar uma ocorrência: praga → nível de infestação → pontos.
- Mostrar o estado "rascunho" e destacar que nele nenhum alerta dispara.
- Clicar em **Concluir** e mostrar o monitoramento saindo de rascunho e o alerta aparecendo.
