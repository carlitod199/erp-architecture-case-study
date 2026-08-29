---
slug: registrar-uma-nfe-emitida
titulo: "Registrar uma NF-e emitida para guardar no acervo fiscal"
tipo: FAZER
modulo: fiscal
objetivo: registrar
jornada: [continuo]
nivel: intermediario
duracao_seg: 170
rotas:
  - rota: /fiscal/emissao_nfe.php
    principal: true
permissoes: [fiscal.emissao_nfe.editar]
papeis:
  nucleo: [fiscal, contador, gestor]
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
Hoje a emissão integrada à SEFAZ está fora do escopo. Esta tela **registra** a nota que você emitiu por fora (no emissor gratuito da SEFAZ ou no sistema do contador) e a guarda no acervo fiscal com número, chave, valor e o XML — mantendo o histórico completo sem depender de certificado.

## Como fazer
1. Abra **Emissão de NF-e** e vá ao bloco **Registrar nota emitida**.
2. Escolha o **tipo** (NF-e ou NFC-e) e informe o **número** e o **valor** da nota (obrigatórios).
3. Cole a **chave de acesso** (44 dígitos) — opcional, mas recomendado para evitar duplicidade.
4. Confirme a **data de emissão** e anexe o **XML autorizado** (opcional, mas recomendado).
5. Clique em **Registrar nota** — ela entra no acervo fiscal.

## Erros comuns
- **Confundir esta tela com emissão real.** Ela não transmite nada à SEFAZ. A emissão integrada exige certificado digital A1, credenciamento e homologação, que não fazem parte do go-live. Emita a nota por fora e registre aqui.
- **Digitar a chave com quantidade errada de dígitos.** A chave precisa ter exatamente 44 dígitos (ou ficar em branco). Cole direto do XML para não errar.
- **Registrar a mesma nota duas vezes.** Se a chave já existir no acervo, o sistema barra — não force um número diferente para "passar".

## Antes e depois
Antes: emitir a nota no emissor gratuito da SEFAZ ou no contador e ter o XML em mãos. Depois: acompanhar a nota no acervo em Documentos Fiscais.

## Roteiro do vídeo
- Abrir Emissão de NF-e mostrando o aviso de "como funciona hoje".
- Narrar: "Aqui a gente registra a nota que já foi emitida por fora — o VERO guarda tudo no acervo."
- Preencher tipo, número, valor e colar a chave de 44 dígitos.
- Anexar o XML e clicar em Registrar nota.
- Mostrar a nota entrando na lista do acervo.
