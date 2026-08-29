---
slug: firmar-um-contrato-de-venda
titulo: "Firmar um contrato de pré-venda para travar o preço antes da colheita"
tipo: FAZER
modulo: comercial
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /comercial/contratos_venda.php
    principal: true
permissoes: [comercial.contratos_venda.editar]
papeis:
  nucleo: [comercial, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-um-comprador]
  proximo: [registrar-uma-venda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O contrato de pré-venda trava preço e quantidade antes da colheita: kg contratado × preço/kg, com vencimento. Quando o contrato está ativo, ele entra no fluxo de caixa como previsto e serve de referência para as vendas que forem faturadas contra ele.

## Como fazer
1. Abra **Contratos de venda** e clique em **Novo contrato**.
2. Escolha o **comprador** e informe o **kg contratado** e o **preço/kg** (ambos maiores que zero).
3. Se quiser, vincule **cultura** e **safra** e defina a **data de vencimento**.
4. Clique em **Salvar** — o contrato nasce como **rascunho** com número automático (ex.: CT2026-0001).
5. Quando o negócio estiver acertado, mude o status para **Ativo** — só assim ele passa a valer no fluxo de caixa.

## Erros comuns
- **Deixar o contrato em rascunho.** Rascunho não entra no fluxo de caixa. Ative o contrato quando o acordo estiver fechado.
- **Tentar mudar o preço de um contrato ativo já faturado.** Se já existe venda vinculada, o preço fica travado e a edição é bloqueada — é essa a proteção do contrato. Cancele e refaça se o preço mudou de fato.
- **Editar um contrato cumprido ou cancelado.** Não é possível; esses status são finais. Respeite o caminho rascunho → ativo → cumprido/cancelado.

## Antes e depois
Antes: comprador cadastrado e preço combinado com ele. Depois: registrar as vendas que faturam contra o contrato e acompanhar o saldo (kg contratado − kg faturado).

## Roteiro do vídeo
- Abrir Contratos de venda com a lista por status.
- Narrar: "O contrato trava o preço antes da fruta existir — é a proteção contra a oscilação do mercado."
- Criar contrato: comprador, kg e preço/kg; salvar como rascunho.
- Mudar o status para Ativo e mostrar ele entrando como previsto no fluxo.
- Fechar apontando o saldo a faturar.
