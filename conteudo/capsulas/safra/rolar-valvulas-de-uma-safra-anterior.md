---
slug: rolar-valvulas-de-uma-safra-anterior
titulo: "Rolar as válvulas de uma safra anterior para a nova"
tipo: FAZER
modulo: safra
objetivo: rolar
jornada: [safra]
nivel: intermediario
duracao_seg: 140
rotas:
  - rota: /safras/index.php
    principal: true
tela_app: safra_rolar
permissoes: [agro.safras.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado]
relacoes:
  prerequisito: [criar-safra-e-vincular-valvulas]
  relacionado: [abrir-safra-e-confirmar-poda]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Rolar uma safra copia os vínculos de válvulas de uma safra anterior para a nova, de uma vez. Como a lavoura perene repete quase as mesmas válvulas a cada ciclo, a rolagem poupa o trabalho de vincular tudo à mão — traz válvula, cultura, área e a meta de produtividade prontas para revisão.

## Como fazer
1. Abra **Safras / Ciclos** e entre na safra de destino (a nova, que vai receber as válvulas).
2. No painel **Rolar safra**, escolha a **safra de origem** — de onde os vínculos serão copiados.
3. Clique em rolar — este é o passo que copia os vínculos de válvulas ativas para a safra atual.
4. Leia o resultado: quantos vínculos foram copiados e quantos já existiam e foram mantidos.
5. **Revise as áreas e as metas de produtividade** de cada válvula, pois vêm da safra antiga.

## Erros comuns
- **Escolher a mesma safra como origem e destino.** O sistema recusa: a origem tem de ser diferente da atual. Selecione a safra anterior de verdade.
- **Esperar que a rolagem sobrescreva o que já existe.** Vínculos que já estão na safra de destino são mantidos, não duplicados. A rolagem só acrescenta o que falta.
- **Deixar as áreas e metas como vieram.** Elas são copiadas da safra antiga e podem ter mudado. Revise a área plantada e a produtividade planejada de cada válvula depois de rolar.

## Antes e depois
Antes: Criar a nova safra. Depois: Abrir a safra e confirmar a poda de cada válvula.

## Roteiro do vídeo
- Abrir Safras / Ciclos e entrar numa safra nova, ainda sem válvulas.
- Mostrar o painel Rolar safra e escolher a safra de origem.
- Clicar em rolar e ler o resultado: copiados e mantidos.
- Narrar: "Veio tudo da safra passada — agora é revisar área e meta."
- Abrir uma válvula e ajustar a produtividade planejada.
