---
slug: ratear-os-custos-indiretos
titulo: "Ratear os custos indiretos entre as safras"
tipo: FAZER
modulo: custos
objetivo: ratear
jornada: [safra]
nivel: avancado
duracao_seg: 190
rotas:
  - rota: /custeio/rateios.php
    principal: true
tela_app: rateios
permissoes: [custeio.rateios.editar]
papeis:
  nucleo: [gestor, rt_gerente, financeiro]
  consulta: [dono]
relacoes:
  relacionado: [fechar-a-safra-no-custeio, conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Ratear é distribuir os custos que não nasceram amarrados a um talhão ou safra — folha, depreciação, energia, combustível — entre as safras e válvulas certas, para que o custo por hectare fique completo e justo. Sem rateio, esses custos gerais ficam "soltos" e o custo por talhão sai menor do que a realidade.

## Como fazer
1. Abra **Rateios**. A tela tem três blocos: lançamentos sem safra, combustível por horas e as regras de critério.
2. No bloco **1 · Lançamentos sem safra**, confira os custos gerais pendentes e clique em **Atribuir às safras do período** — eles são distribuídos pela área plantada de cada safra ativa.
3. No bloco **2 · Combustível por horas**, clique em **Ratear por horas** para distribuir os abastecimentos às válvulas conforme as horas de máquina apontadas.
4. Em **3 · Regras de rateio**, use **+ Nova regra** para registrar o critério acordado com o cliente (base: área, produção, custo direto ou manual).
5. Confira as memórias de cálculo. Cada cota vira **uma linha própria**, o lançamento original nunca é apagado e toda distribuição é **reversível** pelo botão de desfazer.

## Erros comuns
- **Rodar o rateio antes de todos os lançamentos entrarem.** O que ficar para depois não é rateado retroativamente sozinho — lance tudo do período, depois distribua.
- **Ratear de novo achando que corrige.** Aplicar de novo não duplica, mas também não conserta um erro: use **Desfazer** e reaplique após ajustar.
- **Esperar combustível rateado onde não há horas.** Se a máquina não tem horas em safra ativa no mês, o abastecimento é **pulado** (e reportado), não chutado por área.

## Antes e depois
Antes: Lançar todos os custos do período, inclusive os gerais sem safra. Depois: Fechar a safra com o custo por hectare já completo.

## Roteiro do vídeo
- Abrir Rateios e mostrar os três blocos.
- Atribuir os lançamentos sem safra e mostrar as linhas com memória de cálculo.
- Ratear o combustível por horas.
- Desfazer uma distribuição para mostrar que é reversível.
