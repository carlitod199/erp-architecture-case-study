---
slug: registrar-analise-de-solo
titulo: "Registrar uma análise de solo e classificá-la"
tipo: FAZER
modulo: nutricao
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /nutricao/analise_solo.php
    principal: true
permissoes: [nutricao.analise_solo.editar]
papeis:
  nucleo: [rt_gerente, agronomo, gestor]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [cadastrar-faixas-nutricionais]
  relacionado: [importar-laudo-de-analise, registrar-analise-foliar]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Aqui você lança o laudo de solo por talhão/válvula. Ao salvar, o VERO compara cada valor com as faixas do RT, classifica cada nutriente (baixo, adequado, excessivo) e emite os alertas correspondentes. É o que transforma um PDF de laboratório em informação que orienta a adubação.

## Como fazer
1. Preencha o **cabeçalho**: a **data da amostra** (obrigatória) e o talhão/válvula de onde veio o solo.
2. Lance os **valores por nutriente** conforme o laudo do laboratório.
3. Confira as **unidades** — cada resultado precisa da sua unidade de medida.
4. Clique em **Salvar**: o sistema reclassifica os resultados contra as faixas e reemite os alertas.
5. Leia a coluna de classificação — nutriente sem faixa cadastrada fica sem cor (o sistema nunca inventa referência).

## Erros comuns
- **pH fora da escala 0–14.** O salvamento é recusado. Confira o valor digitado; o pH é adimensional, então deixe a unidade em branco (ou "pH").
- **Unidade que é um número.** Digitar "4" no campo unidade é rejeitado — unidade é medida (ppm, cmolc/dm³...), não valor.
- **Resultado sem cor.** Não é bug: falta a faixa daquele nutriente. Cadastre a faixa e salve de novo para classificar.

## Antes e depois
Antes: Ter as faixas nutricionais cadastradas para o laudo sair classificado. Depois: Ler o Painel de Nutrientes e planejar a adubação com o RT.

## Roteiro do vídeo
- Abrir Análises de Solo e iniciar um lançamento.
- Preencher data da amostra + talhão, lançar dois ou três nutrientes.
- Narrar a crítica do pH (0–14) e da unidade.
- Salvar e mostrar as classificações aparecendo + o alerta gerado.
