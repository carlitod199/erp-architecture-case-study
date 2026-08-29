---
slug: imprimir-boletim-de-pulverizacao
titulo: "Consultar e imprimir o boletim diário de pulverização"
tipo: CONSULTAR
modulo: mip
objetivo: consultar
jornada: [safra]
nivel: iniciante
duracao_seg: 110
rotas:
  - rota: /mip/boletim_pulverizacao.php
    principal: true
permissoes: [mip.aplicacoes_defensivos.ver]
papeis:
  nucleo: [rt_gerente, encarregado]
  consulta: [gestor, operador]
relacoes:
  prerequisito: [confirmar-execucao-da-aplicacao]
  relacionado: [validar-aplicacao-como-rt]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O boletim consolida num único documento todas as DFs e IFs de um período, uma linha por documento, preservando número, carência e RT de cada um. Ele agrupa para impressão sem fundir as caldas: os documentos seguem distintos e rastreáveis. É a folha que vai a campo ou ao arquivo, com o cabeçalho canônico do emissor.

## Como consultar
1. Abra o **Boletim de Pulverização**.
2. Ajuste a data final e quantos dias para trás quer incluir no período.
3. Se precisar, filtre por série (DF ou IF) e por fazenda.
4. Confira a tabela consolidada e use a impressão do navegador para gerar o documento.

O boletim é somente leitura: para lançar ou corrigir uma aplicação, use a tela de Pulverização.

## Antes e depois
Antes: Confirmar a execução das aplicações do período. Depois: Arquivar o boletim impresso junto ao restante da rastreabilidade da safra.

## Roteiro do vídeo
- Abrir o Boletim de Pulverização já com um período com aplicações.
- Ajustar a data final e o número de dias e mostrar a tabela mudando.
- Filtrar por série DF e por fazenda.
- Acionar a impressão e mostrar o cabeçalho canônico com o emissor.
