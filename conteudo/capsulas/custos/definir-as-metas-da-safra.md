---
slug: definir-as-metas-da-safra
titulo: "Definir as metas da safra"
tipo: FAZER
modulo: custos
objetivo: definir
jornada: [safra]
nivel: iniciante
duracao_seg: 120
rotas:
  - rota: /custeio/metas.php
    principal: true
tela_app: metas_safra
permissoes: [custeio.metas_safra.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [abrir-a-safra]
  relacionado: [montar-o-orcamento-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Metas são os alvos que você quer bater na safra: custo total, custo por hectare, colheita em kg, produtividade, faturamento, preço médio e margem. O Dashboard Executivo confronta cada meta com o realizado — sem meta definida, não há com o que comparar o resultado.

## Como fazer
1. Abra **Metas da Safra** e selecione a **safra** no topo.
2. Escolha o **indicador** (custo por hectare, produtividade, margem…).
3. Informe o **valor da meta** — precisa ser maior que zero — e uma observação opcional.
4. Clique em **Salvar meta**. Ela vira um card e passa a valer no Dashboard Executivo.
5. Repita para cada indicador que quiser acompanhar. Cada indicador tem **uma única meta por safra** — salvar o mesmo indicador de novo apenas atualiza o valor.

## Erros comuns
- **Digitar o valor na unidade errada.** Custo por hectare é R$/ha, produtividade é kg/ha, margem é %. Confira a unidade no rótulo do indicador antes de salvar.
- **Duplicar o indicador.** Não existe meta repetida — salvar de novo sobrescreve. Se o número mudou, é só salvar por cima.
- **Definir meta na safra errada.** Confira a safra selecionada no topo antes de salvar; a meta fica amarrada a ela.

## Antes e depois
Antes: Abrir a safra que vai receber as metas. Depois: Acompanhar meta versus realizado no Dashboard Executivo.

## Roteiro do vídeo
- Abrir Metas da Safra e escolher a safra.
- Cadastrar duas metas (custo/ha e produtividade) e mostrar os cards surgindo.
- Salvar o mesmo indicador com outro valor e mostrar que atualiza, não duplica.
