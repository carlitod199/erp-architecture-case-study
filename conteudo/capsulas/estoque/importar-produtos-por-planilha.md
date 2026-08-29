---
slug: importar-produtos-por-planilha
titulo: "Importar produtos em massa por planilha"
tipo: FAZER
modulo: estoque
objetivo: importar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /estoque/importar_produtos.php
    principal: true
permissoes: [estoque.produtos_insumos.editar]
papeis:
  nucleo: [gestor, almoxarife]
  consulta: [estoquista, dono]
relacoes:
  relacionado: [cadastrar-um-produto-no-estoque, organizar-grupos-e-subgrupos-de-produtos]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Quando você tem dezenas ou centenas de produtos para cadastrar, a importação por planilha faz isso de uma vez. Você sobe um CSV no template padrão, o sistema valida linha a linha e mostra o que vai criar ou atualizar — e só grava depois que você confirma. O casamento é pelo código: existe, atualiza; não existe, cria.

## Como fazer
1. Abra **Importar Produtos** e baixe o **template CSV** (já vem com o cabeçalho e um exemplo).
2. Preencha a planilha (código, nome, ingrediente ativo, tipo, unidade, doses, carência etc.) e salve como CSV.
3. Suba o arquivo — o sistema roda **sempre um dry-run primeiro**: mostra o que será criado e atualizado, com os erros por linha.
4. Revise a prévia e clique em **Confirmar** para gravar de fato.

## Erros comuns
- **Achar que subir o arquivo já grava.** Não grava: a primeira passada é só validação. Os produtos só entram quando você clica em Confirmar.
- **Mudar o cabeçalho ou a ordem das colunas do template.** Use o template como está; tipo e unidade fora da lista aceita (defensivo, fertilizante… / kg, L, mL, g, un) travam a linha.
- **Reusar um código já existente sem querer.** Código repetido não duplica — ele atualiza o produto existente. Confira a prévia para não sobrescrever um cadastro certo.

## Antes e depois
Antes: Ter os grupos definidos e a planilha preenchida no template. Depois: Fazer o saldo inicial dos produtos importados e conferir alguns cadastros na tela de Produtos.

## Roteiro do vídeo
- Abrir Importar Produtos e baixar o template.
- Mostrar a planilha preenchida com algumas linhas.
- Subir o arquivo e percorrer a prévia do dry-run (criados x atualizados x erros).
- Confirmar e mostrar os produtos novos na lista.
