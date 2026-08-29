---
slug: gerar-a-depreciacao-gerencial
titulo: "Gerar a depreciação gerencial do mês para virar custo no custeio"
tipo: FAZER
modulo: patrimonio
objetivo: gerar
jornada: [continuo]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /patrimonio/depreciacao_gerencial.php
    principal: true
permissoes: [patrimonio.depreciacao_gerencial.editar]
papeis:
  nucleo: [patrimonio, financeiro, gestor]
  consulta: [dono]
relacoes:
  prerequisito: [cadastrar-um-bem-patrimonial]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A depreciação gerencial calcula, por competência, quanto cada bem produtivo perdeu de valor no mês — de forma linear: (aquisição − residual) ÷ vida útil. Gerar a competência é o que transforma esse desgaste em custo dentro do custeio, dando o custo real de produção. Não substitui a depreciação contábil/fiscal.

## Como fazer
1. Abra **Depreciação Gerencial**.
2. Informe a **competência** (mês/ano) que quer processar.
3. Clique em **Gerar** — o sistema calcula um lançamento por ativo produtivo elegível e emite o custo ao custeio.
4. Confira o retorno: quantos foram **gerados**, quantos **pulados** e quantos ficaram sem cálculo por falta de vida útil.
5. Repita a cada mês. Gerar de novo a mesma competência **não duplica** (é idempotente por ativo e competência).

## Erros comuns
- **Ativo sem vida útil e sem categoria com vida.** Ele fica de fora do cálculo (aparece como "sem vida"). Defina a vida útil no ativo ou na categoria para ele depreciar.
- **Esperar que Terras depreciem.** Não depreciam por regra (vida útil zero). Categorias administrativas também não viram custo do custeio — só os bens produtivos.
- **Gerar antes de cadastrar/ajustar os bens do mês.** O que não estava cadastrado (ou com data de aquisição posterior à competência) não entra. Cadastre os bens antes de gerar.

## Antes e depois
Antes: os bens cadastrados com valor de aquisição e vida útil. Depois: conferir a depreciação como custo no custeio e no valor patrimonial atualizado.

## Roteiro do vídeo
- Abrir Depreciação Gerencial.
- Narrar: "A depreciação é o desgaste do bem virando custo — mês a mês, sem duplicar."
- Informar a competência e clicar em Gerar.
- Mostrar o resumo de gerados/pulados/sem vida.
- Cortar para o custeio com a depreciação lançada.
