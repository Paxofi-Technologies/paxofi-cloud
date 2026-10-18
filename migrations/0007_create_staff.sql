-- Staff members and their roles (SRS ACC-005, SEC-017; threat model
-- THREAT-MODEL-IDENTITY T-31..T-33, D-6, §6.16). Staff are a separate table
-- from customer identities: a customer can never become staff by changing a
-- column. Roles are the fixed sets in StaffRole; permissions are never stored.
CREATE TABLE staff_members (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii NOT NULL,
    email_lookup CHAR(64) CHARACTER SET ascii NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'invited',
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_staff_members_email_lookup (email_lookup),
    CONSTRAINT chk_staff_members_status CHECK (status IN ('invited', 'active', 'disabled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per grant. granted_by is NULL only for the operator bootstrap on the
-- host. A Super Admin grant stays 'pending' until a different staff member,
-- who is neither the requester nor the grantee, approves it (SEC-017). Note:
-- a CHECK passes when it evaluates to NULL, so every comparison is guarded.
CREATE TABLE staff_role_assignments (
    id CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    staff_id CHAR(32) CHARACTER SET ascii NOT NULL,
    role VARCHAR(32) CHARACTER SET ascii NOT NULL,
    status VARCHAR(16) CHARACTER SET ascii NOT NULL,
    granted_by CHAR(32) CHARACTER SET ascii NULL,
    approved_by CHAR(32) CHARACTER SET ascii NULL,
    revoked_by CHAR(32) CHARACTER SET ascii NULL,
    requested_at DATETIME(6) NOT NULL,
    activated_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    -- At most one open (pending or active) grant per staff member and role.
    open_marker TINYINT GENERATED ALWAYS AS (IF(status IN ('pending', 'active'), 1, NULL)) STORED,
    UNIQUE KEY uq_staff_role_open (staff_id, role, open_marker),
    KEY idx_staff_role_status (role, status),
    CONSTRAINT fk_staff_role_staff FOREIGN KEY (staff_id) REFERENCES staff_members (id),
    CONSTRAINT fk_staff_role_granted_by FOREIGN KEY (granted_by) REFERENCES staff_members (id),
    CONSTRAINT fk_staff_role_approved_by FOREIGN KEY (approved_by) REFERENCES staff_members (id),
    CONSTRAINT fk_staff_role_revoked_by FOREIGN KEY (revoked_by) REFERENCES staff_members (id),
    CONSTRAINT chk_staff_role_name CHECK (role IN ('support', 'billing', 'provisioning_operator', 'abuse', 'catalogue', 'finance', 'security_admin', 'super_admin')),
    CONSTRAINT chk_staff_role_status CHECK (status IN ('pending', 'active', 'revoked')),
    CONSTRAINT chk_staff_role_not_self CHECK (granted_by IS NULL OR granted_by <> staff_id),
    CONSTRAINT chk_staff_role_active_time CHECK (status <> 'active' OR activated_at IS NOT NULL),
    CONSTRAINT chk_staff_role_super_two_person CHECK (
        role <> 'super_admin' OR status <> 'active' OR (
            granted_by IS NOT NULL AND approved_by IS NOT NULL
            AND approved_by <> granted_by AND approved_by <> staff_id
        )
    ),
    CONSTRAINT chk_staff_role_only_super_approved CHECK (role = 'super_admin' OR approved_by IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
