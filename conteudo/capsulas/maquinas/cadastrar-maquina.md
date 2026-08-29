---
slug: cadastrar-maquina
titulo: "Cadastrar uma máquina da frota"
tipo: FAZER
modulo: maquinas
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 160
rotas:
  - rota: /maquinas/cadastro.php
    principal: true
permissoes: [maquinas.maquinas.editar]
papeis:
  nucleo: [gestor, encarregado]
  consulta: [operador, financeiro, dono]
relacoes:
  proximo: [registrar-leitura-de-horimetro, lancar-abastecimento, criar-plano-de-manutencao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A máquina é o cadastro-base de tudo em Máquinas: sem ela não há horímetro, abastecimento, manutenção nem custo por hora. Aqui você registra trator, colheitadeira, pulverizador, bandejão e afins, com o horímetro atual, o custo/hora e os dados de depreciação que alimentam o custeio.

## Como fazer
1. Clique em **+ Nova máquina** e informe **código**, **nome** e **tipo** — os três são obrigatórios.
2. Escolha a **fazenda**, a **marca**, o **modelo** e o **ano**.
3. Informe o **horímetro atual** e o **custo/hora** (usados no custo das operações).
4. Se for controlar depreciação, preencha **valor de aquisição**, **valor residual** e **vida útil (horas)**; para máquinas com tanque, a **capacidade do tanque (L)**.
5. Defina o **operador padrão** e o **status** (ativa, em manutenção, inativa) e clique em **Salvar**.

## Erros comuns
- **Código repetido.** Cada máquina tem um código único no tenant; o sistema recusa duplicado. Use uma numeração clara (TR-01, CO-02...).
- **Valores negativos.** Horímetro, custo/hora, valores e capacidade não podem ser negativos — o salvamento é bloqueado.
- **Tipo em branco ou inválido.** O tipo tem que ser um da lista (trator, colheitadeira, pulverizador...); sem ele o cadastro não salva.

## Antes e depois
Antes: Ter a fazenda cadastrada. Depois: Registrar leituras de horímetro, abastecimentos e planos de manutenção da máquina.

## Roteiro do vídeo
- Abrir Cadastro de Máquinas e clicar em + Nova máquina.
- Preencher código, nome, tipo; mostrar a recusa de código duplicado.
- Completar horímetro, custo/hora e depreciação; salvar.
