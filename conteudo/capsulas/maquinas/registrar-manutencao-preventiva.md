---
slug: registrar-manutencao-preventiva
titulo: "Registrar uma ordem de manutenção e executá-la"
tipo: FAZER
modulo: maquinas
objetivo: registrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 175
rotas:
  - rota: /maquinas/manutencao.php
    principal: true
permissoes: [maquinas.manutencao_preventiva.editar]
papeis:
  nucleo: [gestor, encarregado]
  consulta: [operador, financeiro, dono]
relacoes:
  prerequisito: [criar-plano-de-manutencao]
  relacionado: [registrar-leitura-de-horimetro]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A ordem de manutenção (OS) registra o que foi feito na máquina: peças trocadas e serviços contratados. Quando você marca a OS como executada, o VERO baixa as peças do estoque ao custo real, consolida o custo da ordem e lança tudo no custeio (categoria máquinas). É o elo entre manutenção, estoque e custo.

## Como fazer
1. Escolha a **máquina**, o **tipo** e a **data** da manutenção. Se ela nasce de um plano, vincule o **plano**.
2. Lance os **itens**: para peça, escolha o produto e a quantidade; para serviço, descreva e informe o valor.
3. Informe o **horímetro** da máquina no momento (atualiza a máquina se avançar) e o **fornecedor**, se houver.
4. Defina o **status**. Deixe **aberta** para uma OS planejada; marque **executada** quando o serviço foi de fato feito.
5. Clique em **Salvar**. Na execução, as peças saem do estoque e o custo entra no custeio.

## Erros comuns
- **Editar uma OS já executada.** Não é permitido — para corrigir, você cancela a OS (o cancelamento estorna as peças e o custeio) e cria outra.
- **Peça de lote vencido.** Se a baixa pegar um lote vencido, o sistema segura e pede confirmação; marque "confirmo usar peça de lote vencido" e reenvie, ou troque o lote.
- **Custo negativo.** O custo da manutenção não pode ser negativo — o salvamento é bloqueado.

## Antes e depois
Antes: Ter o plano preventivo e as peças no estoque. Depois: Ver o custo no Custo Realizado e o horímetro/plano reprogramados.

## Roteiro do vídeo
- Abrir Manutenções e criar uma OS aberta com um item de peça.
- Narrar a diferença entre aberta e executada.
- Marcar executada e mostrar a peça baixando do estoque + o custo no custeio.
- Mostrar que a OS executada não edita — só cancela.
