---
slug: cadastrar-implemento
titulo: "Cadastrar um implemento agrícola"
tipo: FAZER
modulo: maquinas
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /maquinas/implementos.php
    principal: true
permissoes: [maquinas.implementos.editar]
papeis:
  nucleo: [gestor, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-maquina]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O implemento é o que se acopla ao trator: grade, pulverizador, roçadeira, subsolador. Cadastrá-lo organiza a frota, permite dizer a qual máquina ele está acoplado e deixa o inventário de equipamentos completo para as operações e o controle patrimonial.

## Como fazer
1. Na tela de **Implementos**, clique em **+ Novo implemento**.
2. Informe o **nome** — é o campo obrigatório.
3. Preencha o **tipo** e escolha a **fazenda**.
4. Se ele fica acoplado a uma máquina, selecione a **máquina** correspondente.
5. Clique em **Salvar**. O implemento entra na lista como ativo.

## Erros comuns
- **Salvar sem nome.** O nome é obrigatório — sem ele o cadastro é recusado.
- **Não indicar a máquina acoplada.** É opcional, mas sem esse vínculo você perde a visão de qual trator carrega qual implemento.
- **Confundir implemento com máquina.** Implemento não tem horímetro nem abastecimento próprios; se for equipamento motorizado, cadastre em Máquinas.

## Antes e depois
Antes: Ter a máquina de tração cadastrada. Depois: Usar o implemento nas operações agrícolas e no controle da frota.

## Roteiro do vídeo
- Abrir Implementos e clicar em + Novo implemento.
- Preencher nome, tipo e fazenda; vincular a uma máquina.
- Salvar e mostrar o implemento na lista com o vínculo.
