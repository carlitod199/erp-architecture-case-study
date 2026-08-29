---
slug: registrar-apontamento-de-irrigacao
titulo: "Registrar um apontamento de irrigação com água e energia"
tipo: FAZER
modulo: irrigacao
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /irrigacao/apontamentos_irrigacao.php
    principal: true
permissoes: [irrigacao.apontamentos_irrigacao.editar]
papeis:
  nucleo: [operador, encarregado, gestor]
  consulta: [rt_gerente, dono]
relacoes:
  prerequisito: [definir-tarifas-de-irrigacao]
  proximo: [consultar-consumo-de-agua-e-energia]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O apontamento de irrigação é o que transforma a rega do dia em número e em custo. Você lança por válvula e data as horas e a lâmina, mais o consumo de água (m³) e energia (kWh). Cada consumo com custo vira lançamento no custeio, amarrado à válvula e à safra — é assim que a irrigação entra na conta da lavoura.

## Como fazer
1. Escolha a **válvula** e a **data** — os dois são obrigatórios.
2. Informe as **horas** irrigadas e a **lâmina (mm)** aplicada.
3. Lance o **consumo de água (m³)** e/ou **energia (kWh)**. Se deixar o custo em branco e houver tarifa cadastrada, o VERO calcula sozinho (quantidade × tarifa).
4. Se o apontamento pertence a uma safra, confirme o **vínculo de safra** — o custo é rateado nela.
5. Clique em **Salvar**: o consumo com custo gera o lançamento no custeio (categoria irrigação).

## Erros comuns
- **Válvula ou data em branco.** O salvamento é recusado — sem esses dois o apontamento não existe.
- **Safra fechada.** Se a safra vinculada já foi encerrada, o custeio não aceita o lançamento; o sistema avisa e bloqueia. Aponte na safra correta.
- **Digitar custo e ter tarifa.** O custo digitado prevalece sobre a tarifa automática — se digitar errado, é ele que vale. Deixe em branco para o cálculo automático.

## Antes e depois
Antes: Definir as tarifas (R$/m³ e R$/kWh) para o custo sair automático. Depois: Conferir o Consumo de Água e Energia e o Custo Realizado da safra.

## Roteiro do vídeo
- Abrir Apontamentos de Irrigação e iniciar um novo.
- Preencher válvula, data, horas, lâmina.
- Lançar água em m³ deixando o custo vazio; narrar o cálculo automático pela tarifa.
- Salvar e mostrar o valor lançado no custeio.
