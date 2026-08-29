---
slug: validar-aplicacao-como-rt
titulo: "Validar como responsável técnico uma aplicação registrada"
tipo: FAZER
modulo: mip
objetivo: validar
jornada: [safra]
nivel: intermediario
duracao_seg: 130
rotas:
  - rota: /mip/aplicacoes.php
    principal: true
tela_app: aplicacao_validar
permissoes: [mip.aplicacoes_defensivos.editar]
papeis:
  nucleo: [rt_gerente]
  consulta: [gestor, encarregado]
relacoes:
  prerequisito: [confirmar-execucao-da-aplicacao]
  relacionado: [arquivar-receituario-agronomico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A validação é o aceite formal do responsável técnico sobre uma aplicação já registrada: é o carimbo de "eu, RT, confirmo que esta execução está correta". Só aplicações em status registrada aguardam validação. É a última etapa da trilha da DF/IF, importante para a rastreabilidade e para as exigências do RT.

## Como fazer
1. Na lista de **Pulverização**, localize a aplicação em status registrada.
2. Confira a execução: produtos, quantidades, data e operadores.
3. Clique no botão **Validar (RT)** — este é o passo que registra o aceite, com seu usuário e a data/hora.
4. A aplicação passa a "validada" e a trilha fica completa.

## Erros comuns
- **Tentar validar uma OS ainda emitida.** Se o documento está planejado, o sistema manda usar antes a Confirmar execução — a validação só age depois que a execução foi registrada.
- **Esperar validar algo cancelado ou já validado.** Apenas o status registrada aguarda validação; qualquer outro é recusado com a explicação do status atual.
- **Ignorar o aviso de registro do RT.** Se o RT da aplicação não tem registro formal cadastrado ou está vencido, aparece um aviso — a validação segue, mas regularize o registro em Pessoas para atender a exigência.

## Antes e depois
Antes: Confirmar a execução da aplicação com as quantidades reais. Depois: Arquivar o receituário do RT e acompanhar o boletim de pulverização.

## Roteiro do vídeo
- Abrir Pulverização e filtrar por aplicações registradas.
- Abrir uma aplicação e narrar a conferência de produtos e operadores.
- Destacar o botão **Validar (RT)** antes de clicar.
- Clicar e mostrar o status virando validada.
- Mostrar o aviso de registro do RT quando aparecer, explicando que não trava.
