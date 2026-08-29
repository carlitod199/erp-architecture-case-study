---
slug: cadastrar-um-almoxarifado
titulo: "Cadastrar um almoxarifado"
tipo: FAZER
modulo: estoque
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 120
rotas:
  - rota: /estoque/almoxarifados.php
    principal: true
permissoes: [estoque.almoxarifados.editar]
papeis:
  nucleo: [gestor, almoxarife, estoquista]
  consulta: [encarregado, dono]
relacoes:
  proximo: [transferir-produto-entre-almoxarifados]
  relacionado: [cadastrar-um-produto-no-estoque]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O almoxarifado é o lugar físico onde o estoque fica guardado — depósito da sede, galpão de uma fazenda, tanque de combustível. Cada saldo e cada movimentação pertencem a um almoxarifado, e é isso que permite transferir produto de um para outro e inventariar cada local separadamente.

## Como fazer
1. Abra **Almoxarifados** e clique em **Novo almoxarifado**.
2. Dê um **nome** claro e, se fizer sentido, vincule à **fazenda** e escolha o **tipo**.
3. Clique em **Salvar** — ele já fica disponível para receber entradas e transferências.
4. Para desativar um antigo, use **Excluir** (inativa) — só funciona se ele estiver sem saldo.

## Erros comuns
- **Repetir um nome já existente.** Não pode haver dois almoxarifados ativos com o mesmo nome — o sistema recusa; use nomes distintos (ex.: "Galpão Sede" e "Galpão Fazenda B").
- **Tentar inativar um almoxarifado com saldo.** Transfira todo o estoque para outro local antes; enquanto houver saldo, o sistema bloqueia a inativação.
- **Criar almoxarifados demais.** O "Almoxarifado Central" já é criado pelo sistema quando necessário — crie novos só para locais que você realmente controla separado.

## Antes e depois
Antes: Definir os locais físicos de estoque da operação. Depois: Cadastrar produtos e transferir saldo entre os almoxarifados.

## Roteiro do vídeo
- Abrir Almoxarifados e criar um novo.
- Preencher nome, vincular a uma fazenda e salvar.
- Mostrar o novo almoxarifado disponível na lista de transferência.
- Tentar inativar um com saldo e mostrar a mensagem de bloqueio.
