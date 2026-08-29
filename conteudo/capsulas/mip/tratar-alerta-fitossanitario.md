---
slug: tratar-alerta-fitossanitario
titulo: "Reconhecer, registrar a decisão e resolver um alerta fitossanitário"
tipo: FAZER
modulo: mip
objetivo: tratar
jornada: [safra]
nivel: intermediario
duracao_seg: 155
rotas:
  - rota: /mip/alertas_fitossanitarios.php
    principal: true
tela_app: alerta_fitossanitario
permissoes: [mip.alertas_fitossanitarios.editar]
papeis:
  nucleo: [rt_gerente, gestor, encarregado]
  consulta: [operador]
relacoes:
  prerequisito: [registrar-monitoramento-mip]
  proximo: [emitir-ordem-de-pulverizacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Os alertas fitossanitários nascem sozinhos: quando um monitoramento chega ao nível de ação do alvo, o sistema abre um alerta. Esta tela é a fila dessas ocorrências. Aqui você reconhece que viu, registra a decisão de controle do responsável técnico e resolve. O sistema não recomenda produto nem dose — toda decisão de controle é do RT; aqui ela fica documentada.

## Como fazer
1. Abra **Alertas Fitossanitários** e mantenha o filtro em ativos para ver os abertos e reconhecidos.
2. No alerta, clique em **Reconhecer** — marca que a equipe tomou ciência, com seu usuário e a data.
3. Clique em **Registrar ação** e descreva a decisão tomada pelo RT; se já houve pulverização, ligue à aplicação correspondente.
4. Quando o controle estiver feito, clique em **Resolver** — este é o passo que fecha o alerta.

## Erros comuns
- **Resolver sem registrar a decisão.** O aceite formal do RT (Registrar ação) é o que dá rastreabilidade ao controle. Descreva a ação antes de fechar o alerta.
- **Registrar ação sem descrever nada.** O texto da ação é obrigatório — sem ele o sistema não grava. Diga o que foi decidido, mesmo que seja "monitorar de novo em X dias".
- **Esperar que o sistema indique o produto.** Ele nunca recomenda produto ou dose. A ação e a receita são do responsável técnico; a tela só registra.

## Antes e depois
Antes: Registrar o monitoramento MIP que atingiu o nível de ação. Depois: Emitir a OS de pulverização, se a decisão do RT for aplicar.

## Roteiro do vídeo
- Abrir Alertas Fitossanitários com alertas abertos e críticos no topo.
- Clicar em **Reconhecer** num alerta e narrar que fica registrado quem viu.
- Abrir **Registrar ação**, descrever a decisão do RT e vincular a uma aplicação.
- Clicar em **Resolver** e mostrar o alerta saindo da fila de ativos.
