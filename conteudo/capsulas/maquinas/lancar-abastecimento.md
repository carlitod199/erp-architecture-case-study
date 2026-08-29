---
slug: lancar-abastecimento
titulo: "Lançar um abastecimento de combustível"
tipo: FAZER
modulo: maquinas
objetivo: registrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /maquinas/abastecimento.php
    principal: true
permissoes: [maquinas.abastecimentos.editar]
papeis:
  nucleo: [operador, encarregado, gestor]
  consulta: [financeiro, dono]
relacoes:
  prerequisito: [cadastrar-maquina]
  relacionado: [registrar-leitura-de-horimetro]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Cada abastecimento é combustível que entra na máquina e custo que entra na safra. Ao lançar litros e valor, o VERO joga o gasto no custeio (categoria máquinas) e, se você informar o horímetro do momento, atualiza o horímetro da máquina de quebra. É a base do custo de combustível da frota.

## Como fazer
1. Escolha a **máquina** e a **data** do abastecimento.
2. Informe os **litros** e o **valor total** pagos — os dois são obrigatórios.
3. Se anotou, informe o **horímetro** no momento do abastecimento (ajuda a acompanhar o consumo por hora).
4. Clique em **Salvar**: o valor vira lançamento no custeio e, se houver horímetro, a máquina é atualizada.

## Erros comuns
- **Litros ou valor zerados.** Litros precisam ser maiores que zero e o valor não pode ser negativo — o salvamento é recusado.
- **Horímetro menor que o atual.** Se o horímetro informado for menor que o da máquina, o sistema recusa (o horímetro não regride). Confira o número no painel da máquina.
- **Esquecer o horímetro.** É opcional, mas sem ele você perde o consumo por hora — anote sempre que der.

## Antes e depois
Antes: Ter a máquina cadastrada. Depois: O custo aparece no Custo Realizado (categoria máquinas) e o consumo alimenta a ficha da máquina.

## Roteiro do vídeo
- Abrir Abastecimentos e iniciar um lançamento.
- Preencher máquina, data, litros e valor.
- Informar o horímetro e narrar que ele atualiza a máquina.
- Salvar e mostrar o custo lançado no custeio.
