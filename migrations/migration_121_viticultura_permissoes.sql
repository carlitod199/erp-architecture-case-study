-- ============================================================
-- VERO Agro — migration 121: Permissões da vertical Viticultura
-- Compatível com o layout REAL da tabela `permissions`
-- (colunas: slug UNIQUE, label, modulo) — confirmado no banco.
--
-- NOTA: o bloco RBAC dentro de migration_120_viticultura_backbone.sql
-- assume colunas (name, description), que NÃO existem nesta base.
-- Use ESTE arquivo para os perfis/permissões. PROPOSTA — não aplicar
-- sem validação. Idempotente via ON DUPLICATE KEY (slug é UNIQUE).
-- ============================================================
SET NAMES utf8mb4;

INSERT INTO permissions (slug, label, modulo) VALUES
  -- Cadastros base
  ('viticultura.variedades.ver',    'Variedades — visualizar',           'viticultura'),
  ('viticultura.variedades.gerir',  'Variedades — criar/editar',         'viticultura'),
  ('viticultura.fenologia.ver',     'Fenologia — visualizar',            'viticultura'),
  ('viticultura.fenologia.gerir',   'Fenologia — criar/editar',          'viticultura'),
  ('viticultura.laboratorios.ver',  'Laboratórios — visualizar',         'viticultura'),
  ('viticultura.laboratorios.gerir','Laboratórios — criar/editar',       'viticultura'),
  ('viticultura.pontos.ver',        'Pontos de amostragem — visualizar', 'viticultura'),
  ('viticultura.pontos.gerir',      'Pontos de amostragem — criar/editar','viticultura'),
  -- Aplicações (espinha)
  ('viticultura.aplicacoes.ver',    'Aplicações — visualizar',           'viticultura'),
  ('viticultura.aplicacoes.criar',  'Aplicações — criar',                'viticultura'),
  ('viticultura.aplicacoes.editar', 'Aplicações — editar',               'viticultura'),
  ('viticultura.aplicacoes.validar','Aplicações — validar tecnicamente (RT)','viticultura'),
  -- Anexos & laudos / IA
  ('viticultura.anexos.ver',        'Anexos & laudos — visualizar',      'viticultura'),
  ('viticultura.anexos.criar',      'Anexos & laudos — anexar/importar', 'viticultura'),
  ('viticultura.anexos.validar',    'Laudos IA — revisar/validar (humano)','viticultura'),
  -- Alertas
  ('viticultura.alertas.ver',       'Alertas — visualizar',              'viticultura'),
  ('viticultura.alertas.gerir',     'Alertas — reconhecer/resolver',     'viticultura')
ON DUPLICATE KEY UPDATE label = VALUES(label), modulo = VALUES(modulo);

-- Atribuição a perfis (role_permissions) — descomente após validar os IDs.
-- Ex.: dar tudo de viticultura ao perfil 'gestor' (slug em `roles`):
-- INSERT INTO role_permissions (role_id, permission_id)
-- SELECT r.id, p.id
--   FROM roles r
--   JOIN permissions p ON p.modulo = 'viticultura'
--  WHERE r.tenant_id = 1 AND r.slug = 'gestor'
-- ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);
