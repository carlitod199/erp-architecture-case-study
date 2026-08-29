---
slug: cadastrar-um-centro-de-custo
titulo: "Cadastrar um centro de custo"
tipo: FAZER
modulo: financeiro
objetivo: cadastrar
jornada: [continuo]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /financeiro/centros_custo.php
    principal: true
tela_app: centros_custo
permissoes: [financeiro.centros_custo.editar]
papeis:
  nucleo: [financeiro, gestor]
  consulta: [dono]
relacoes:
  relacionado: [montar-o-plano-de-contas]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Centro de custo é o destino do gasto — para onde o custeio aponta. Mão de obra (MDO), insumos (INS), máquinas (MAQ) e irrigação (IRR) são criados automaticamente pelos módulos; aqui você cadastra o resto (administrativo, sede, projetos), para organizar os custos que não nascem dos módulos operacionais.

## Como fazer
1. Abra **Centros de Custo** e clique em **+ Novo centro**.
2. Informe um **código** curto (vira maiúsculas automaticamente) e o **nome**.
3. Adicione uma descrição, se ajudar a diferenciar dos demais.
4. Clique em **Salvar**. O código precisa ser **único** — o sistema recusa duplicados.
5. Para tirar um de circulação, **inative-o** em vez de apagar (o botão faz isso quando há custeio ligado).

## Erros comuns
- **Recriar os automáticos.** MDO, INS, MAQ e IRR aparecem sozinhos na primeira emissão de custo — não precisa cadastrá-los à mão.
- **Repetir um código.** O código é a chave; se já existe, o sistema bloqueia. Escolha outro.
- **Tentar excluir um centro em uso.** Se há lançamentos de custeio nele, o sistema apenas o **inativa** — o histórico é preservado.

## Antes e depois
Antes: Mapear com o cliente quais destinos de custo existem além dos módulos. Depois: Usar o centro ao classificar despesas e lançamentos.

## Roteiro do vídeo
- Abrir Centros de Custo e clicar em + Novo centro.
- Cadastrar um centro "ADM — Administrativo".
- Tentar repetir o código e mostrar o bloqueio.
