---
slug: arquivar-receituario-agronomico
titulo: "Registrar um receituário agronômico e anexar o documento"
tipo: FAZER
modulo: mip
objetivo: arquivar
jornada: [safra]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /mip/receituarios.php
    principal: true
tela_app: receituario
permissoes: [mip.receituarios.editar]
papeis:
  nucleo: [rt_gerente, gestor]
  consulta: [encarregado]
relacoes:
  relacionado: [emitir-ordem-de-pulverizacao, validar-aplicacao-como-rt]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O receituário é a prescrição emitida pelo responsável técnico. Aqui você só arquiva o documento e o liga à aplicação — o sistema nunca recomenda produto, dose ou carência (Regra 1). É o registro que dá respaldo legal e rastreabilidade à pulverização, guardando o PDF ou a foto do papel original.

## Como fazer
1. Abra **Receituários** e clique em novo receituário.
2. Informe o **número** do receituário (obrigatório e único) e a data de emissão.
3. Se quiser, ligue à **aplicação** correspondente e a quem emitiu; informe a validade.
4. Clique em **Salvar** — o receituário fica registrado e liberado para receber o anexo.
5. No receituário salvo, escolha o arquivo (PDF, JPG ou PNG dentro do limite) e clique em **Anexar** — este é o passo que guarda o documento original.

## Erros comuns
- **Salvar sem o número.** O número do receituário é obrigatório e não pode repetir. Se já existir, o sistema avisa e não grava o duplicado.
- **Tentar anexar antes de salvar.** O anexo só entra depois que o receituário existe. Salve primeiro; o campo de anexo aparece em seguida.
- **Enviar um arquivo fora do formato ou grande demais.** Só PDF, JPG e PNG dentro do limite são aceitos, e o conteúdo precisa bater com um PDF/imagem real. Envie o arquivo original, não uma cópia convertida.

## Antes e depois
Antes: O RT emite o receituário e a OS de pulverização. Depois: Validar a aplicação ligada, com o documento arquivado dando respaldo.

## Roteiro do vídeo
- Abrir Receituários mostrando a lista existente.
- Criar um novo, preencher número e data e narrar que o número não pode repetir.
- Clicar em **Salvar** e mostrar o campo de anexo surgindo.
- Selecionar um PDF e clicar em **Anexar**.
- Mostrar o documento anexado na ficha do receituário.
