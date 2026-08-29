---
slug: lancar-um-documento-fiscal
titulo: "Lançar um documento fiscal para completar o acervo"
tipo: FAZER
modulo: fiscal
objetivo: registrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 150
rotas:
  - rota: /fiscal/documentos.php
    principal: true
permissoes: [fiscal.documentos_fiscais.editar]
papeis:
  nucleo: [fiscal, contador, gestor]
  consulta: [dono]
relacoes:
  relacionado: [registrar-uma-nfe-emitida]
versao: "1.0"
vero_versao_min: "1.3"
dono: conteudo@example.com
revisado_em: 2026-07-26
proxima_revisao_em: 2027-01-24
---

## Finalidade
A tela de Documentos Fiscais é a central do acervo: reúne NF-e, NFC-e, CT-e e outros documentos com filtros, detalhe e anexos. O registro manual serve para incluir um documento que não veio por importação de XML, deixando o acervo completo para a contabilidade.

## Como fazer
1. Abra **Documentos** e clique em **Registrar documento**.
2. Escolha o **tipo** (NF-e, NFC-e, CT-e ou Outro) e informe o **número** e o **valor** (obrigatórios).
3. Se tiver a **chave de acesso**, cole os 44 dígitos — o sistema evita duplicidade por chave.
4. Informe o **fornecedor** e a **data de emissão** quando fizer sentido.
5. Clique em **Salvar** — o documento entra no acervo e pode ser detalhado, anexado ou recusado depois.

## Erros comuns
- **Digitar chave inválida.** A chave deve ter 44 dígitos (ou ficar em branco); e não pode repetir uma já existente no acervo. Cole direto do documento.
- **Usar esta tela para importar XML.** Importação de NF-e por arquivo fica na tela de Importação de NF-e; a conciliação, na tela própria. Aqui é registro manual e gestão do acervo.
- **Excluir um documento errado.** Para tirar um documento do fluxo, use **Recusar** (a linha é preservada) e **Reativar** se precisar voltar — não apague o histórico.

## Antes e depois
Antes: ter o documento em mãos (número, valor e, de preferência, a chave). Depois: conciliar e acompanhar o acervo fiscal do período.

## Roteiro do vídeo
- Abrir Documentos com os filtros e a lista do acervo.
- Narrar: "Esta é a central de tudo que é documento fiscal — e dá para lançar na mão o que não veio por XML."
- Registrar um documento tipo Outro com número e valor.
- Mostrar o detalhe e a opção Recusar preservando a linha.
