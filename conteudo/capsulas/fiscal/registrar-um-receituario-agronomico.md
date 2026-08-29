---
slug: registrar-um-receituario-agronomico
titulo: "Registrar um receituário agronômico e anexar a prescrição"
tipo: FAZER
modulo: fiscal
objetivo: registrar
jornada: [safra]
nivel: intermediario
duracao_seg: 160
rotas:
  - rota: /mip/receituarios.php
    principal: true
permissoes: [mip.receituarios.editar]
papeis:
  nucleo: [rt_agronomo, tecnico, gestor]
  consulta: [dono]
relacoes:
  relacionado: [lancar-um-documento-fiscal]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
O receituário agronômico é a prescrição emitida pelo responsável técnico que autoriza a aplicação de defensivos — exigência legal. O VERO **não recomenda** produto, dose ou carência: esta tela apenas **arquiva** o documento emitido pelo RT e o vincula à aplicação, mantendo a rastreabilidade e o cumprimento fiscal/ambiental.

## Como fazer
1. Abra **Receituários** (no módulo MIP) e clique em **Novo receituário**.
2. Informe o **número** do receituário (obrigatório e único).
3. Vincule a **aplicação** correspondente e o **responsável técnico** que emitiu (quando aplicável).
4. Preencha a **data de emissão** e a **validade**, e clique em **Salvar**.
5. Com o receituário salvo, **anexe o documento** (PDF, JPG ou PNG) — é o arquivo que comprova a prescrição.

## Erros comuns
- **Repetir o número do receituário.** Cada receituário tem número único no sistema; um número já usado é barrado.
- **Vincular a uma aplicação cancelada.** Só é aceita aplicação válida e não cancelada. Confirme a aplicação antes de salvar.
- **Salvar e esquecer de anexar o PDF.** O receituário sem o documento anexado não comprova a prescrição — anexe logo após salvar.
- **Esperar que o sistema sugira produto ou dose.** Ele nunca recomenda nada: a prescrição é responsabilidade do agrônomo e aqui só se arquiva.

## Antes e depois
Antes: o RT emitir a prescrição e a aplicação estar registrada. Depois: a aplicação fica com o receituário vinculado e o documento anexado para auditoria.

## Roteiro do vídeo
- Abrir Receituários no módulo MIP.
- Narrar: "O sistema não receita nada — quem receita é o agrônomo. Aqui a gente guarda e liga à aplicação."
- Criar receituário com número, aplicação e RT.
- Salvar e anexar o PDF da prescrição.
- Mostrar o vínculo do receituário com a aplicação.
