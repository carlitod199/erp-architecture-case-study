---
slug: registrar-aplicacao-ja-executada
titulo: "Registrar direto uma aplicação que já foi feita no campo"
tipo: FAZER
modulo: mip
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 165
rotas:
  - rota: /mip/aplicacoes.php
    principal: true
tela_app: aplicacao_nova
permissoes: [mip.aplicacoes_defensivos.editar]
papeis:
  nucleo: [encarregado, rt_gerente, gestor]
  consulta: [operador]
relacoes:
  proximo: [validar-aplicacao-como-rt]
  relacionado: [emitir-ordem-de-pulverizacao, confirmar-execucao-da-aplicacao]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Quando a aplicação já aconteceu — retroativa, ou feita sem OS emitida antes — você registra tudo de uma vez: produtos, quantidades reais e a data realizada. Diferente da OS emitida, o registro direto já baixa o estoque por FEFO e lança o custo na hora. Continua valendo a Regra 1: quem definiu a receita foi o RT; aqui você só documenta o que foi usado.

## Como fazer
1. Abra **Pulverização** e clique em **+ Nova aplicação**.
2. Deixe o modo em **registro direto** (o padrão, para aplicação já executada) — este é o passo que faz o sistema baixar estoque e lançar custo ao salvar.
3. Escolha a válvula, a safra, o tipo e informe a **Data realizada** (obrigatória no registro direto).
4. Lance os produtos com as **quantidades realmente consumidas**, não as previstas.
5. Clique em **Registrar aplicação** — o estoque é baixado por FEFO e o custeio da safra é lançado, na categoria insumos.

## Erros comuns
- **Lançar quantidade prevista no lugar da consumida.** No registro direto o número que você digita é o que sai do estoque e vira custo. Confira o que foi realmente gasto no campo.
- **Usar registro direto para um serviço que ainda vai acontecer.** Se a aplicação não foi feita, emita a OS (DF/IF) — o registro direto é só para o que já foi executado.
- **Salvar sem safra e estranhar o custo sumido.** Sem a safra vinculada o custeio não tem onde entrar. Selecione a safra da válvula para o custo aparecer no Custo Realizado.

## Antes e depois
Antes: Conferir no campo os produtos e as quantidades realmente usadas. Depois: Deixar o RT validar a aplicação registrada.

## Roteiro do vídeo
- Abrir Pulverização e clicar em + Nova aplicação.
- Mostrar o modo em registro direto e narrar: "Essa aplicação já foi feita, então já baixa estoque."
- Preencher válvula, safra, data realizada e as quantidades consumidas.
- Destacar **Registrar aplicação**; clicar.
- Cortar para o estoque com o saldo abaixando e o custeio lançado na safra.
