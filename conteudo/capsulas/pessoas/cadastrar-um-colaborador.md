---
slug: cadastrar-um-colaborador
titulo: "Cadastrar um colaborador para ele entrar na folha e nos apontamentos"
tipo: FAZER
modulo: pessoas
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /pessoas/colaboradores.php
    principal: true
permissoes: [pessoas.operadores.editar]
papeis:
  nucleo: [rh, gestor]
  consulta: [dono]
relacoes:
  proximo: [montar-uma-equipe, fechar-a-folha-do-mes]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O cadastro do colaborador é o que faz a pessoa existir para o sistema: sem ele não dá para montar equipe, apontar trabalho, calcular premiação nem gerar a folha. É aqui que você define o tipo de vínculo e o custo do trabalho dessa pessoa.

## Como fazer
1. Abra **Colaboradores** e clique em **Novo colaborador**.
2. Informe o **nome** e o **tipo de vínculo** (CLT, diarista, etc.) — os dois são obrigatórios.
3. Preencha função, documento e, se for CLT, o **salário mensal**.
4. Deixe o **custo/hora** em branco: o sistema calcula sozinho a partir do salário mais os encargos vigentes. Só preencha se quiser forçar um valor manual.
5. Clique em **Salvar** — a partir daqui a pessoa já aparece para equipes, apontamentos e folha.

## Erros comuns
- **Cadastrar o mesmo nome duas vezes.** O sistema barra homônimo ativo. Se são pessoas diferentes, use o documento para diferenciar antes de salvar.
- **Esperar o custo/hora sem ter encargos configurados.** O cálculo automático depende da configuração de Encargos CLT vigente. Sem ela, o custo/hora fica zerado e o custo de mão de obra sai errado.
- **Deixar o salário de um CLT em branco.** Sem salário mensal, esse colaborador não entra na geração da folha.

## Antes e depois
Antes: ter o vínculo e o valor combinado com a pessoa. Depois: montar a equipe e começar a apontar o trabalho dela.

## Roteiro do vídeo
- Abrir Colaboradores com a lista visível.
- Narrar: "Todo mundo que trabalha na fazenda começa aqui — é este cadastro que liga a pessoa à folha e aos apontamentos."
- Clicar em Novo colaborador, preencher nome, vínculo CLT e salário.
- Mostrar o custo/hora sendo preenchido sozinho depois de salvar.
- Fechar mostrando a pessoa já disponível na lista.
