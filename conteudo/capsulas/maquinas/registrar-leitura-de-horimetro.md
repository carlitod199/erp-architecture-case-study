---
slug: registrar-leitura-de-horimetro
titulo: "Registrar uma leitura de horímetro"
tipo: FAZER
modulo: maquinas
objetivo: registrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 130
rotas:
  - rota: /maquinas/horimetro.php
    principal: true
permissoes: [maquinas.horimetro.editar]
papeis:
  nucleo: [operador, encarregado, gestor]
  consulta: [rt_gerente, dono]
relacoes:
  prerequisito: [cadastrar-maquina]
  relacionado: [criar-plano-de-manutencao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O horímetro é o relógio de horas de trabalho da máquina. Registrar a leitura mantém o horímetro atual em dia e é o que dispara os alertas dos planos de manutenção preventiva — a troca de óleo a cada X horas só avisa se as leituras estiverem em dia.

## Como fazer
1. Escolha a **máquina** e informe a **leitura** do horímetro (em horas).
2. Confirme a **data da leitura** (por padrão, hoje).
3. Clique em **Registrar**. O sistema atualiza o horímetro atual da máquina e reavalia os alertas dos planos preventivos.
4. Confira a lista: a nova leitura entra no histórico e o horímetro atual passa a refletir o valor informado.

## Erros comuns
- **Leitura menor que o horímetro atual.** O horímetro não regride — o sistema recusa. Se digitou errado, corrija; a leitura sempre avança.
- **Registrar na máquina errada.** A leitura vai para o histórico da máquina escolhida e mexe nos alertas dela. Confira antes de confirmar.
- **Deixar leituras atrasadas.** Sem leituras em dia, os alertas de manutenção preventiva não disparam na hora certa. Registre com regularidade.

## Antes e depois
Antes: Ter a máquina cadastrada. Depois: Os planos de manutenção preventiva passam a avisar quando o intervalo de horas está próximo.

## Roteiro do vídeo
- Abrir Horímetro e escolher uma máquina.
- Digitar uma leitura menor que a atual e mostrar a recusa (não regride).
- Corrigir para um valor maior, registrar, mostrar o horímetro atualizado e o alerta reavaliado.
