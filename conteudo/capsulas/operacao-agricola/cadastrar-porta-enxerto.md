---
slug: cadastrar-porta-enxerto
titulo: "Cadastrar um porta-enxerto"
tipo: FAZER
modulo: operacao-agricola
objetivo: cadastrar
jornada: [implantacao]
nivel: iniciante
duracao_seg: 90
rotas:
  - rota: /agro/porta_enxertos.php
    principal: true
permissoes: [agro.variedades.editar]
papeis:
  nucleo: [gestor, rt_gerente]
  consulta: [encarregado, operador]
relacoes:
  relacionado: [cadastrar-variedade, cadastrar-valvula]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O porta-enxerto é o cadastro botânico irmão da variedade. Ele é atribuído no talhão/válvula e serve de referência nas faixas nutricionais. A permissão de escrita é a mesma das variedades.

## Como fazer
1. Abra **Porta-enxertos** e clique em **+ Novo porta-enxerto**.
2. Informe o **nome** (ex.: IAC 572, Paulsen 1103, SO4) — único campo obrigatório.
3. Preencha código e descrição (vigor, tolerâncias, indicações), se tiver.
4. Clique em **Salvar** — ele passa a aparecer na lista de porta-enxertos do cadastro de talhão/válvula.

## Erros comuns
- **Repetir um nome já usado.** O nome é único por tenant e a checagem inclui inativos — se aparecer "já existe", reative o registro em vez de duplicar.
- **Esperar que o porta-enxerto se aplique sozinho.** Ele só passa a valer quando você o atribui no cadastro do talhão/válvula (campo Porta-enxerto).
- **Não encontrar a permissão.** Quem edita variedades edita porta-enxertos — é a mesma chave; não há permissão separada.

## Antes e depois
Antes: Ter as variedades e culturas do parreiral cadastradas. Depois: Atribuir o porta-enxerto no cadastro do talhão/válvula.

## Roteiro do vídeo
- Abrir Porta-enxertos e clicar em + Novo porta-enxerto.
- Digitar o nome e a descrição, salvar.
- Abrir o cadastro de uma válvula e mostrar o porta-enxerto disponível no select.
