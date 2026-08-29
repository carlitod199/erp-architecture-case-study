---
slug: abrir-safra-e-confirmar-poda
titulo: "Abrir a safra e confirmar a poda (o dia 0)"
tipo: FAZER
modulo: operacao-agricola
objetivo: abrir
jornada: [safra]
nivel: intermediario
duracao_seg: 175
rotas:
  - rota: /agro/abertura_safra.php
    principal: true
tela_app: abertura_safra
permissoes: [agro.safra.abrir, agro.safra.confirmar_poda]
papeis:
  nucleo: [rt_gerente, gestor, encarregado]
  consulta: [operador]
relacoes:
  proximo: [emitir-ordem-de-servico]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Abrir a safra do semestre e, válvula por válvula, confirmar que a poda terminou. A confirmação de poda é o dia 0 da safra: é dela que a fenologia começa a contar.

## Como fazer
1. Abra **Abertura de Safra** e clique em **+ Abrir safra do semestre** — a identificação (AAAA.S-NN) sai sozinha.
2. Abra a safra recém-criada para ver as válvulas candidatas.
3. Encontre a válvula com a situação **Pronta p/ confirmar** (todas as OS de poda concluídas e ao menos um apontamento de poda).
4. Clique em **Confirmar poda** — este é o passo que **define o dia 0** (a data do último apontamento de poda) e libera a fenologia da válvula.
5. Repita a confirmação para cada válvula que já teve a poda terminada.

## Erros comuns
- **Válvula sem variedade.** O sistema trava a confirmação: sem variedade não há cultura nem ciclo, e sem isso não dá para calcular o dia 0. Defina a variedade da válvula antes.
- **Confirmar com OS de poda ainda aberta.** Enquanto houver OS de poda aberta ou em execução, o botão fica bloqueado. Conclua todas as OS de poda daquela válvula primeiro.
- **Sem apontamento de poda.** A OS concluída não basta: é preciso ao menos um apontamento de poda, porque o dia 0 é a data desse apontamento. Sem ele, não há data de origem.
- **Achar que dá para reconfirmar.** Depois de confirmada em uma safra ativa, a válvula fica travada para não duplicar safra. A confirmação não se desfaz por aqui.

## Antes e depois
Antes: Terminar as OS e os apontamentos de poda de cada válvula. Depois: Emitir as Ordens de Serviço dos tratos que vêm depois da poda.

## Roteiro do vídeo
- Abrir Abertura de Safra sem nenhuma safra ativa na lista.
- Narrar: "A safra do semestre agrupa as válvulas. Mas o relógio da fenologia só começa quando a gente confirma a poda de cada uma."
- Clicar em + Abrir safra do semestre; mostrar a identificação gerada.
- Abrir a safra e percorrer as válvulas: apontar uma Pendente (falta OS ou apontamento) e uma Pronta p/ confirmar.
- Destacar o botão **Confirmar poda** e a data do último apontamento antes de clicar; clicar.
- Mostrar a válvula virando Poda finalizada com o "dia 0" carimbado.
