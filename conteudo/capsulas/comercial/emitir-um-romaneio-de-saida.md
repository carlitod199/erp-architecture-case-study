---
slug: emitir-um-romaneio-de-saida
titulo: "Registrar um romaneio de saída para conferir o embarcado com a venda"
tipo: FAZER
modulo: comercial
objetivo: registrar
jornada: [safra]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /comercial/romaneios.php
    principal: true
permissoes: [comercial.romaneios_saida.editar]
papeis:
  nucleo: [comercial, logistica, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [registrar-uma-venda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O romaneio registra o que de fato saiu no embarque, ligado a uma venda. Ele permite confrontar o peso embarcado com o kg vendido — se o carregamento saiu em várias viagens, cada uma vira um romaneio e a soma tem que bater com a venda.

## Como fazer
1. Abra **Romaneios** e clique em **Novo romaneio**.
2. Escolha a **venda** (o sistema mostra comprador, kg vendido e lote de cada uma).
3. Informe o **número do romaneio** e o **peso (kg)** embarcado (maior que zero).
4. Ajuste a **data do romaneio** se não for hoje.
5. Clique em **Salvar** — o embarque entra no confronto embarcado × vendido daquela venda.

## Erros comuns
- **Escolher venda cancelada.** Romaneio só liga a venda ativa. Se a venda foi cancelada, ele não é aceito.
- **Somar romaneios acima do kg da venda.** A tela mostra o total embarcado por venda justamente para você perceber quando passou do vendido — confira antes de fechar mais um embarque.
- **Repetir o número do romaneio.** Cada embarque tem seu número; repetir dificulta a conferência com a transportadora.

## Antes e depois
Antes: a venda já registrada. Depois: acompanhar o confronto embarcado × vendido e conferir com o comprador/transportadora.

## Roteiro do vídeo
- Abrir Romaneios agrupados por venda.
- Narrar: "O romaneio é a prova do que embarcou — e ele tem que fechar com o kg da venda."
- Criar romaneio: escolher venda, número e peso.
- Salvar e mostrar o confronto embarcado × vendido no card da venda.
