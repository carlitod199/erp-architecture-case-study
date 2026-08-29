---
slug: registrar-analise-foliar
titulo: "Registrar uma análise foliar por variedade e fase"
tipo: FAZER
modulo: nutricao
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /nutricao/analise_foliar.php
    principal: true
permissoes: [nutricao.analise_foliar.editar]
papeis:
  nucleo: [rt_gerente, agronomo, gestor]
  consulta: [dono, encarregado]
relacoes:
  prerequisito: [cadastrar-faixas-nutricionais]
  relacionado: [registrar-analise-de-solo, importar-laudo-de-analise]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A análise foliar mostra o que a planta de fato absorveu — e o valor certo depende da variedade e da fase fenológica em que a folha foi coletada. Ao salvar, o VERO classifica cada nutriente contra as faixas do RT daquele contexto e emite alertas. É a leitura mais fina do estado nutricional do vinhedo.

## Como fazer
1. Preencha o **cabeçalho**: **data da amostra** (obrigatória), talhão/válvula e a **variedade/fase fenológica** da coleta.
2. Lance os **valores por nutriente** conforme o laudo, com as respectivas unidades.
3. Clique em **Salvar**: os resultados são reclassificados contra as faixas foliares daquele contexto e os alertas, reemitidos.
4. Confira as classificações — se um nutriente sai sem cor, falta a faixa foliar para aquela variedade/fase.

## Erros comuns
- **Esquecer a variedade/fase.** A faixa foliar costuma ser específica por variedade e fase; sem esse contexto o resultado pode cair na faixa errada ou ficar sem classificação.
- **Unidade incoerente.** Um número puro no campo unidade é rejeitado; o pH, se houver, precisa estar entre 0 e 14.
- **Comparar foliar com solo.** São réguas diferentes — não use a faixa de solo para julgar um laudo foliar.

## Antes e depois
Antes: Cadastrar as faixas foliares por variedade/fase. Depois: Cruzar solo e foliar no Painel de Nutrientes e ajustar a fertirrigação com o RT.

## Roteiro do vídeo
- Abrir Análises Foliares e iniciar um lançamento.
- Destacar a escolha de variedade + fase fenológica antes dos valores.
- Lançar nutrientes, salvar, mostrar a classificação específica daquele contexto.
