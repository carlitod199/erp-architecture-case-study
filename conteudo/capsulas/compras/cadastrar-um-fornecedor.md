---
slug: cadastrar-um-fornecedor
titulo: "Cadastrar um fornecedor"
tipo: FAZER
modulo: compras
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 140
rotas:
  - rota: /compras/fornecedores.php
    principal: true
permissoes: [compras.fornecedores.editar]
papeis:
  nucleo: [comprador, gestor, financeiro]
  consulta: [encarregado, dono]
relacoes:
  proximo: [cotar-fornecedores-e-escolher-a-vencedora, emitir-um-pedido-de-compra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O fornecedor é a ficha de quem vende para a fazenda: nome, CNPJ/CPF, categoria, cidade/UF e condição de pagamento. Ele é referenciado nas cotações, nos pedidos e nos recebimentos — e a própria ficha vai acumulando volume, ticket, lead time e % de entregas no prazo para você comparar quem entrega melhor.

## Como fazer
1. Abra **Fornecedores** e clique em **Novo fornecedor**.
2. Preencha o **nome**, o **CNPJ/CPF**, a **categoria**, cidade/UF e a **condição de pagamento**.
3. Clique em **Salvar** — o documento é normalizado (sem pontuação) para não duplicar por diferença de formatação.
4. Depois, use a **ficha (?ficha=ID)** para ver o desempenho consolidado do fornecedor.

## Erros comuns
- **Cadastrar o mesmo fornecedor duas vezes com o CNPJ formatado diferente.** O sistema normaliza o documento para evitar isso, mas confira antes — duplicata quebra a ficha de desempenho e o histórico.
- **Deixar categoria e condição de pagamento em branco.** Sem categoria você não filtra fornecedores; sem condição de pagamento os pedidos precisam preencher tudo de novo toda vez.
- **Inativar um fornecedor com histórico achando que apaga tudo.** A inativação é lógica (campo ativo) — o histórico de compras dele continua íntegro para relatórios.

## Antes e depois
Antes: Reunir os dados do fornecedor (documento, contato, condição). Depois: Usá-lo nas cotações e nos pedidos de compra.

## Roteiro do vídeo
- Abrir Fornecedores e criar um novo.
- Preencher nome, CNPJ, categoria e condição de pagamento; salvar.
- Abrir a ficha consolidada de um fornecedor com histórico (volume, lead time, % no prazo).
- Mostrar o fornecedor disponível na cotação.
