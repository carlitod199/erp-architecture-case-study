---
slug: cadastrar-um-bem-patrimonial
titulo: "Cadastrar um bem patrimonial para depreciar e controlar o patrimônio"
tipo: FAZER
modulo: patrimonio
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 160
rotas:
  - rota: /patrimonio/ativos.php
    principal: true
permissoes: [patrimonio.maquinas_ativos.editar]
papeis:
  nucleo: [patrimonio, gestor]
  consulta: [dono]
relacoes:
  proximo: [gerar-a-depreciacao-gerencial]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O cadastro de ativos é o registro central do patrimônio da fazenda — terras, benfeitorias, equipamentos, veículos e máquinas. É o valor de aquisição e a vida útil informados aqui que alimentam a depreciação gerencial e o valor patrimonial do negócio.

## Como fazer
1. Abra **Ativos Patrimoniais** e clique em **Novo ativo**.
2. Informe a **descrição** e a **categoria** (Terras, Benfeitorias, Equipamentos, Veículos ou Máquinas) — a categoria traz uma vida útil padrão.
3. Preencha o **valor de aquisição** (maior que zero) e a **data de aquisição**.
4. Ajuste, se quiser, a **vida útil em meses** e o **valor residual** (o que o bem valerá ao fim da vida útil).
5. Se o ativo estiver ligado a uma máquina cadastrada, vincule a **máquina**. Clique em **Salvar**.

## Erros comuns
- **Deixar o valor de aquisição zerado ou negativo.** É obrigatório e maior que zero — sem ele a depreciação não tem base para calcular.
- **Cadastrar terra esperando depreciação.** A categoria Terras tem vida útil zero e **não deprecia** de propósito. Isso é correto: terra não perde valor por uso.
- **Excluir um ativo que já tem depreciação lançada.** O sistema não apaga: ele **inativa (dá baixa)** para preservar o histórico. Use a baixa em vez de tentar excluir.

## Antes e depois
Antes: ter a nota/valor de aquisição do bem e saber a categoria. Depois: gerar a depreciação gerencial por competência e acompanhar o valor patrimonial.

## Roteiro do vídeo
- Abrir Ativos Patrimoniais com a lista por categoria.
- Narrar: "Todo bem da fazenda começa aqui — é o valor de aquisição que vai virar depreciação."
- Criar um equipamento com valor de aquisição, data e vida útil.
- Salvar e mostrar o ativo na lista pronto para depreciar.
