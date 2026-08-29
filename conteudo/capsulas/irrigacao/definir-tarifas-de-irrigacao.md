---
slug: definir-tarifas-de-irrigacao
titulo: "Definir as tarifas de água e energia da irrigação"
tipo: FAZER
modulo: irrigacao
objetivo: definir
jornada: [continuo]
nivel: intermediario
duracao_seg: 120
rotas:
  - rota: /irrigacao/apontamentos_irrigacao.php
    principal: true
permissoes: [irrigacao.apontamentos_irrigacao.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [financeiro, dono]
relacoes:
  proximo: [registrar-apontamento-de-irrigacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tarifa é o preço do m³ de água (do Vale) e do kWh de energia. Cadastrada uma vez, ela faz o custo dos apontamentos de irrigação sair sozinho: você lança só a quantidade e o VERO multiplica pela tarifa. Sem tarifa, ou você digita o custo em cada apontamento, ou ele fica em zero.

## Como fazer
1. Na tela de **Apontamentos de Irrigação**, abra o bloco de **tarifas**.
2. Informe a **tarifa de água (R$/m³)** e a **tarifa de energia (R$/kWh)**.
3. Clique em **Gravar tarifas**.
4. A partir daí, todo apontamento novo com quantidade e custo em branco calcula o custo automaticamente (quantidade × tarifa).

## Erros comuns
- **Tarifa negativa.** O sistema recusa — tarifa é sempre zero ou positiva.
- **Achar que corrige o passado.** A tarifa vale para os apontamentos novos; os já lançados mantêm o custo que tinham. Reabra e salve o apontamento antigo se quiser recalcular.
- **Deixar a tarifa vazia.** Sem tarifa, o custo automático não acontece — você terá que digitar o custo em cada apontamento.

## Antes e depois
Antes: Ter os valores atuais de R$/m³ (Vale) e R$/kWh em mãos. Depois: Registrar os apontamentos de irrigação com o custo saindo automático.

## Roteiro do vídeo
- Abrir Apontamentos de Irrigação e o bloco de tarifas.
- Preencher R$/m³ e R$/kWh, gravar.
- Criar um apontamento deixando o custo vazio e mostrar o valor calculado sozinho.
