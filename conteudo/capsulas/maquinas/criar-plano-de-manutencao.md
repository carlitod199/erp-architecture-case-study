---
slug: criar-plano-de-manutencao
titulo: "Criar um plano de manutenção preventiva"
tipo: FAZER
modulo: maquinas
objetivo: cadastrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /maquinas/planos_manutencao.php
    principal: true
permissoes: [maquinas.manutencao_preventiva.editar]
papeis:
  nucleo: [gestor, encarregado]
  consulta: [operador, dono]
relacoes:
  prerequisito: [cadastrar-maquina]
  relacionado: [registrar-leitura-de-horimetro, registrar-manutencao-preventiva]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O plano preventivo é o lembrete automático da manutenção: "troca de óleo a cada 250 horas" ou "revisão a cada 90 dias". Com o plano criado, o VERO dispara alertas conforme as leituras de horímetro e o calendário — vence o que chegar primeiro. É o que evita a manutenção esquecida.

## Como fazer
1. Escolha a **máquina** e escreva a **descrição** do plano (o que será feito).
2. Informe o **intervalo em horas** de horímetro **e/ou** em **dias** — pelo menos um dos dois, e o que vencer primeiro dispara o alerta.
3. Ajuste a **antecedência** (com quantas horas/dias antes o alerta aparece).
4. Se já houve execução, informe o **horímetro** e a **data da última** para o plano contar a partir dali.
5. Clique em **Salvar**. O alerta passa a disparar na antecedência configurada.

## Erros comuns
- **Não informar nenhum intervalo.** Sem horas nem dias o plano não sabe quando vencer — informe pelo menos um.
- **Antecedência ou horímetro negativos.** Não são aceitos; use zero ou valor positivo.
- **Esquecer da leitura de horímetro.** O alerta por horas só dispara se as leituras estiverem em dia. Mantenha o horímetro atualizado.

## Antes e depois
Antes: Ter a máquina cadastrada. Depois: Registrar as ordens de manutenção quando o alerta disparar — vinculadas a este plano.

## Roteiro do vídeo
- Abrir Planos de Manutenção e criar um.
- Preencher máquina, descrição e intervalo em horas; narrar o "vence o primeiro".
- Ajustar antecedência e a última execução; salvar.
- Mostrar o alerta sendo previsto pelo horímetro atual.
