---
slug: acompanhar-produtividade-por-talhao
titulo: "Acompanhar a produtividade por talhão"
tipo: CONSULTAR
modulo: operacao-agricola
objetivo: acompanhar
jornada: [safra]
nivel: intermediario
duracao_seg: 100
rotas:
  - rota: /agro/produtividade.php
    principal: true
permissoes: [agricola.produtividade]
papeis:
  nucleo: [gestor, rt_gerente, dono]
  consulta: [encarregado, operador]
relacoes:
  prerequisito: [registrar-colheita-no-campo]
  relacionado: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Este painel mostra o atingimento de produtividade por válvula: o realizado (kg colhido) contra o planejado da safra, com barras clicáveis. É a leitura de quanto cada talhão entregou frente à meta.

## Como fazer
1. Abra **Produtividade por Talhão** e selecione a **safra**.
2. Leia os KPIs e a barra de atingimento de cada válvula (realizado ÷ planejado).
3. Clique numa barra para o **drill-down** — as colheitas individuais que compõem o número daquela válvula.
4. Compare kg/ha realizado com a produtividade planejada para achar os talhões abaixo da meta.

## Antes e depois
Antes: Registrar as colheitas da safra por válvula. Depois: Cruzar produtividade com o custo realizado para avaliar a margem por talhão.

## Roteiro do vídeo
- Abrir Produtividade por Talhão e escolher a safra.
- Percorrer as barras de atingimento e clicar numa para o drill-down.
- Apontar um talhão abaixo da meta e ler o kg/ha realizado.
