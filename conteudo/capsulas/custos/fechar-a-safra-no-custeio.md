---
slug: fechar-a-safra-no-custeio
titulo: "Fechar a safra no custeio"
tipo: FAZER
modulo: custos
objetivo: fechar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /custeio/fechamento.php
    principal: true
tela_app: fechamento_safra
permissoes: [custeio.fechamento_safra.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono]
relacoes:
  prerequisito: [ratear-os-custos-indiretos, conferir-custo-realizado-da-safra]
  relacionado: [comparar-o-custo-entre-safras]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Fechar a safra grava um retrato do custo total apurado naquele momento — é a referência oficial para o resultado final. A partir do fechamento, a safra **não aceita mais lançamentos de custeio**, o que impede que números novos mexam num período que já foi consolidado.

## Como fazer
1. Abra **Fechamento de Safra** e localize a safra na lista, com o custo apurado ao lado.
2. Antes de fechar, se houver custos indiretos, aplique o **rateio** da safra — o próprio botão de ratear fica aqui.
3. Clique em **Fechar** na safra desejada.
4. Confirme. O sistema grava o **snapshot do custo total** e a partir daí trava novos lançamentos de custeio nessa safra.
5. Leia o aviso: se sobrar custo indireto **sem rateio**, a mensagem avisa que o custo por talhão está incompleto — vale rerratear antes de dar por encerrada.

## Erros comuns
- **Fechar com indiretos ainda soltos.** A mensagem avisa "custos indiretos sem rateio". Se ignorar, o custo por hectare fica menor que o real — rateie e refeche.
- **Precisar de um ajuste depois de fechar.** Não force por fora: use **Reabrir**, lance a correção e feche de novo — o snapshot é recalculado ao refechar.
- **Reabrir e esquecer do rateio.** Se havia rateio aplicado, ao reabrir você precisa desfazer e reaplicar após os ajustes — a própria tela avisa.

## Antes e depois
Antes: Ratear os custos indiretos e conferir o custo realizado. Depois: Comparar o resultado desta safra com outras.

## Roteiro do vídeo
- Abrir Fechamento de Safra com o custo apurado visível.
- Fechar uma safra e mostrar o snapshot gravado no histórico.
- Reabrir para demonstrar o ciclo fechado → reaberto → refechado.
