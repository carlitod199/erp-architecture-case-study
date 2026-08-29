---
slug: consultar-onde-esta-cada-bem
titulo: "Consultar onde está cada bem por fazenda"
tipo: CONSULTAR
modulo: patrimonio
objetivo: consultar
jornada: [continuo]
nivel: iniciante
duracao_seg: 90
rotas:
  - rota: /patrimonio/localizacao_ativos.php
    principal: true
permissoes: [patrimonio.localizacao_ativos.ver]
papeis:
  nucleo: [patrimonio, gestor]
  consulta: [dono]
relacoes:
  relacionado: [cadastrar-um-bem-patrimonial]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A Localização de Ativos mostra onde está cada bem, agrupado por fazenda: máquinas, veículos, implementos e ativos patrimoniais vinculados. É uma tela de leitura, útil para inventário e para saber rapidamente o que está em cada unidade.

## Como consultar
1. Abra **Localização de Ativos**.
2. Percorra os blocos por **fazenda** — cada bem aparece com sua classe (Máquina, Veículo, Implemento ou Ativo) e um detalhe.
3. Verifique o bloco **Sem localização definida** ao final: são bens sem fazenda vinculada.
4. Para mudar a localização de um bem, edite o cadastro do bem (máquina, veículo ou implemento) e ajuste a fazenda — esta tela apenas reflete o que está lá.

## Antes e depois
Antes: os bens cadastrados e vinculados às suas fazendas. Depois: usar o inventário para conferência física ou para corrigir a fazenda de um bem no cadastro.

## Roteiro do vídeo
- Abrir Localização de Ativos.
- Narrar: "Aqui você não move nada — é o retrato de onde cada bem está, fazenda por fazenda."
- Percorrer uma fazenda e mostrar máquinas, veículos e implementos.
- Apontar o bloco Sem localização definida e explicar como corrigir no cadastro do bem.
