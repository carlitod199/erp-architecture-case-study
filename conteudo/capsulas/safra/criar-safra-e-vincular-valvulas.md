---
slug: criar-safra-e-vincular-valvulas
titulo: "Criar uma safra e vincular as válvulas com cultura e área"
tipo: FAZER
modulo: safra
objetivo: criar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /safras/index.php
    principal: true
tela_app: safra_nova
permissoes: [agro.safras.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado]
relacoes:
  proximo: [abrir-safra-e-confirmar-poda]
  relacionado: [rolar-valvulas-de-uma-safra-anterior]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A safra é o ciclo produtivo, e o vínculo safra × válvula é a amarração central do sistema: custeio, colheita e dashboards todos dependem dele. Ao criar a safra você já escolhe as válvulas; a cultura vem da variedade de cada válvula, junto com a área plantada e a produtividade planejada. Sem esse vínculo, nada da safra tem onde se apoiar.

## Como fazer
1. Abra **Safras / Ciclos** e clique em **+ Nova safra**.
2. Informe a **identificação** (única) e a **data de início** — as duas são obrigatórias.
3. Marque as **válvulas** que entram na safra já na criação — o sistema cria os vínculos com a cultura e a área de cada uma.
4. Ajuste o status (planejada, ativa ou encerrada) e, se souber, o fim previsto.
5. Clique em **Salvar** — a safra nasce com as válvulas vinculadas, prontas para o custeio e a colheita.

## Erros comuns
- **Vincular válvula sem variedade.** A cultura vem da variedade da válvula; sem variedade a válvula é ignorada no vínculo, com aviso. Defina a variedade da válvula antes de vinculá-la.
- **Repetir a identificação de uma safra.** A identificação não pode se repetir no tenant. Se já existir, o sistema avisa e não grava.
- **Achar que precisa cadastrar as válvulas depois.** Elas entram já na criação. Se faltar alguma, use o painel de vincular/desvincular da safra para ajustar depois.

## Antes e depois
Antes: Ter as válvulas cadastradas com suas variedades. Depois: Abrir a safra e confirmar a poda de cada válvula (o dia 0 da fenologia).

## Roteiro do vídeo
- Abrir Safras / Ciclos com a lista de safras.
- Clicar em + Nova safra e preencher identificação e data de início.
- Marcar as válvulas e narrar: "A cultura e a área vêm sozinhas, da variedade da válvula."
- Destacar **Salvar**; clicar.
- Abrir a safra criada e mostrar as válvulas vinculadas.
