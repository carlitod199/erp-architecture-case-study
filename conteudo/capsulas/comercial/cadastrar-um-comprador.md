---
slug: cadastrar-um-comprador
titulo: "Cadastrar um comprador para vender e faturar para ele"
tipo: FAZER
modulo: comercial
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /comercial/compradores.php
    principal: true
permissoes: [comercial.compradores.editar]
papeis:
  nucleo: [comercial, gestor]
  consulta: [dono]
relacoes:
  proximo: [registrar-uma-venda, firmar-um-contrato-de-venda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O comprador é o cliente para quem a fazenda vende. Sem ele cadastrado, não dá para registrar venda nem firmar contrato. É também aqui que ficam os dados fiscais (CNPJ/CPF e Inscrição Estadual) que a NF-e vai precisar.

## Como fazer
1. Abra **Compradores** e clique em **Novo comprador**.
2. Informe a **razão social** (obrigatória) e, se houver, o **nome fantasia**.
3. Preencha o **CNPJ/CPF** — o sistema valida o dígito verificador e recusa documento inválido.
4. Para comprador pessoa jurídica, informe a **Inscrição Estadual** (necessária para emitir NF-e depois).
5. Complete e-mail, telefone e endereço se tiver, e clique em **Salvar**.

## Erros comuns
- **Digitar CNPJ/CPF errado.** O sistema barra documento com dígito verificador incorreto e não grava nada. Confira o número antes de salvar.
- **Cadastrar o mesmo documento duas vezes.** Não é permitido comprador ativo repetido com o mesmo CNPJ/CPF. Procure na lista antes de criar um novo.
- **Deixar PJ sem Inscrição Estadual.** O sistema salva, mas avisa: sem IE você não conseguirá emitir NF-e para esse comprador. Complete assim que tiver o dado.

## Antes e depois
Antes: ter os dados do cliente (documento e, se PJ, a IE). Depois: registrar uma venda ou firmar um contrato de pré-venda com ele.

## Roteiro do vídeo
- Abrir Compradores com a lista visível.
- Narrar: "O comprador precisa existir aqui antes de qualquer venda — e é dele que sai a IE da nota fiscal."
- Criar novo comprador PJ, preencher razão social, CNPJ e IE.
- Mostrar a validação do documento funcionando.
- Salvar e apontar o comprador já disponível para venda.
