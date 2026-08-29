---
slug: registrar-colheita-no-campo
titulo: "Registrar uma colheita pela visão do campo"
tipo: FAZER
modulo: operacao-agricola
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /agro/colheita.php
    principal: true
permissoes: [agro.colheita.editar]
papeis:
  nucleo: [encarregado, operador, gestor]
  consulta: [rt_gerente, dono]
relacoes:
  prerequisito: [cadastrar-valvula]
  proximo: [conferir-custo-realizado-da-safra]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
Esta tela é o atalho de campo para registrar a colheita sem sair da Gestão Agrícola. O formulário é o mesmo do módulo Colheita e usa as mesmas regras: a classificação soma no máximo 100% e a colheita pode gerar entrada no estoque.

## Como fazer
1. Abra **Colheita (visão agrícola)** e clique em **+ Registrar colheita**.
2. Preencha o formulário: válvula, safra, cultura/variedade, data e os quilos colhidos.
3. Distribua a **classificação em percentuais** — a soma não pode passar de 100%.
4. Clique em **Salvar** — o registro é gravado pelo handler do módulo Colheita e, se a cultura estiver configurada, entra no estoque.
5. Volte à lista e confira o previsto × realizado e a barra de realização por válvula.

## Erros comuns
- **Somar mais de 100% na classificação.** A regra crítica bloqueia: a soma dos percentuais de classificação não pode ultrapassar 100%.
- **Estranhar que a colheita não entrou no estoque.** A entrada só acontece se a cultura tiver produto e almoxarifado de colheita configurados; sem isso, registra a produção mas não movimenta estoque.
- **Registrar sem previsão e achar que houve erro.** Sem previsão a barra de realização mostra "sem previsão" — é normal; o realizado continua contando.
- **Procurar o registro completo aqui.** Esta é a visão de campo; o registro detalhado (com todas as classificações) vive no módulo Colheita, linkado no rodapé.

## Antes e depois
Antes: Cadastrar a válvula e a safra colhida. Depois: Conferir o custo realizado e a produtividade por talhão.

## Roteiro do vídeo
- Abrir Colheita (visão agrícola) e clicar em + Registrar colheita.
- Preencher válvula, kg e classificação; mostrar o bloqueio ao passar de 100%.
- Salvar e mostrar a barra de realização na lista.
