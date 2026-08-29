-- ============================================================
-- VERO Agro — migration 111: RBAC (roles) + auditoria de acesso
-- Completa o core de login/permissionamento já existente.
-- Idempotente (CREATE TABLE IF NOT EXISTS).
-- ============================================================

-- Auditoria de autenticação (esperada por includes/auth.php)
CREATE TABLE IF NOT EXISTS auth_audit_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NULL,
  user_id    BIGINT UNSIGNED NULL,
  email      VARCHAR(150) NULL,
  acao       VARCHAR(60)  NOT NULL,
  ip         VARCHAR(45)  NULL,
  user_agent VARCHAR(200) NULL,
  status     VARCHAR(20)  NOT NULL DEFAULT 'info',
  detalhes   VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aal_tenant (tenant_id),
  KEY idx_aal_user (user_id),
  KEY idx_aal_acao (acao),
  KEY idx_aal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfis (roles) por tenant
CREATE TABLE IF NOT EXISTS roles (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  BIGINT UNSIGNED NULL,
  slug       VARCHAR(60)  NOT NULL,
  nome       VARCHAR(120) NOT NULL,
  descricao  VARCHAR(255) NULL,
  ativo      TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_tenant_slug (tenant_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vínculo perfil -> permissão (catálogo global em `permissions`)
CREATE TABLE IF NOT EXISTS role_permissions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rp (role_id, permission_id),
  KEY idx_rp_perm (permission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Perfis padrão para o tenant 1
INSERT INTO roles (tenant_id, slug, nome, descricao)
SELECT 1, x.slug, x.nome, x.descricao FROM (
  SELECT 'super_admin' AS slug, 'Super Admin'   AS nome, 'Acesso total'                 AS descricao UNION ALL
  SELECT 'gestor',              'Gestor agrícola', 'Gestão operacional e financeira'  UNION ALL
  SELECT 'operador',            'Operador de campo', 'Apontamentos e consultas'       UNION ALL
  SELECT 'financeiro',          'Financeiro',      'Financeiro e custeio'             UNION ALL
  SELECT 'visualizador',        'Visualizador',    'Somente leitura'
) x
WHERE NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id=1 AND r.slug=x.slug);
