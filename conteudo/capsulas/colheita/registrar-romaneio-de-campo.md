---
slug: registrar-romaneio-de-campo
titulo: "Registrar um romaneio da carga que saiu do campo"
tipo: FAZER
modulo: colheita
objetivo: registrar
jornada: [safra]
nivel: iniciante
duracao_seg: 150
rotas:
  - rota: /agro/romaneios_colheita.php
    principal: true
tela_app: romaneio_colheita
permissoes: [agro.romaneios_colheita.editar]
papeis:
  nucleo: [operador, encarregado]
  consulta: [gestor, rt_gerente]
relacoes:
  proximo: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O romaneio é a trilha do que saiu do campo: quanto pesou, de qual válvula veio e para onde foi. É o que liga cada carga à safra e, depois, ao registro de colheita.

## Como fazer
1. Abra **Romaneios de Colheita** e clique em **+ Nova carga**.
2. Preencha o número do romaneio, a válvula e o peso em kg (maior que zero).
3. Escolha a **safra** da válvula (ela aparece na lista só depois de escolher a válvula) — é o que rastreia o lote.
4. Se quiser, informe a classificação, o destino e o registro de colheita ligado.
5. Clique em **Salvar** — este é o passo que **registra a carga** e soma o peso no total do dia.

## Erros comuns
- **Peso zero ou em branco.** O sistema não salva: romaneio, válvula e peso maior que zero são obrigatórios. Confira a balança antes de lançar.
- **Escolher a safra antes da válvula.** A lista de safras só carrega depois que você seleciona a válvula. Selecione a válvula primeiro e a safra certa aparece.
- **Deixar a carga sem safra.** Sem a safra, o lote fica sem rastreabilidade e não aparece corretamente na trilha por safra. Escolha a safra da válvula sempre que houver.

## Antes e depois
Antes: Colher e pesar a carga no campo. Depois: Conferir o Custo Realizado da safra com a produção já lançada.

## Roteiro do vídeo
- Abrir Romaneios de Colheita mostrando o total de kg do dia.
- Clicar em + Nova carga e abrir o formulário.
- Preencher romaneio, válvula e peso; narrar que sem peso não salva.
- Escolher a válvula e mostrar a lista de safras carregando em seguida.
- Destacar o botão **Salvar** antes de clicar; clicar.
- Mostrar a carga nova na tabela e o total de kg subindo.
