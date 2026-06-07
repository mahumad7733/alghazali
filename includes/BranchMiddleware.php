<?php
/**
 * BranchMiddleware - Handles Multi-Branch Isolation
 * Provides branch filtering for queries, including support for JOINs
 */
class BranchMiddleware
{
    private $pdo;
    private $current_user_id;
    private $current_branch_id;
    private $is_super_admin;

    public function __construct($pdo, $user_id = null)
    {
        $this->pdo = $pdo;
        $this->current_user_id = $user_id ?? ($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null);

        // Determine if user is a super admin (has view_all_branches permission)
        $this->is_super_admin = $this->checkPermission('view_all_branches');

        // Get user's branch
        $this->current_branch_id = $this->getUserBranchId();
    }

    /**
     * Check if user has a specific permission
     */
    private function checkPermission($permission_name)
    {
        if (!$this->current_user_id) return false;

        // Use global has_permission if available, otherwise check DB
        if (function_exists('has_permission')) {
            return has_permission($permission_name);
        }

        // Check if user is admin
        $stmt = $this->pdo->prepare("SELECT role_id, user_type FROM users WHERE id = ?");
        $stmt->execute([$this->current_user_id]);
        $user = $stmt->fetch();

        if (!$user) return false;

        // Admin or accountant with full access
        if ($user['user_type'] === 'admin' || $user['role_id'] == 1) {
            return true;
        }

        // Check specific permission
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM role_permissions_unified rp
            JOIN unified_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.permission_code = ?
        ");
        $stmt->execute([$user['role_id'], $permission_name]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get user's branch ID from database
     */
    private function getUserBranchId()
    {
        if (!$this->current_user_id) return null;

        $stmt = $this->pdo->prepare("SELECT branch_id FROM users WHERE id = ?");
        $stmt->execute([$this->current_user_id]);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * الحصول على فلتر SQL يدعم الجداول المرتبطة (JOINs)
     * @param string $main_alias اسم مستعار للجدول الرئيسي
     * @param array $join_aliases أسماء مستعارة للجداول المرتبطة التي لها branch_id
     * @return string SQL filter string
     */
    public function getBranchFilterForJoin($main_alias, $join_aliases = [])
    {
        if ($this->is_super_admin || !$this->current_branch_id) {
            return '';
        }

        $filters = ["{$main_alias}.branch_id = {$this->current_branch_id}"];

        foreach ($join_aliases as $alias) {
            $filters[] = "{$alias}.branch_id = {$this->current_branch_id}";
        }

        return ' AND (' . implode(' AND ', $filters) . ')';
    }

    /**
     * Get simple branch filter (for single table)
     */
    public function getBranchFilter($table_name = null)
    {
        if ($this->is_super_admin || !$this->current_branch_id) {
            return '';
        }

        if ($table_name) {
            return " AND {$table_name}.branch_id = {$this->current_branch_id}";
        }

        return " AND branch_id = {$this->current_branch_id}";
    }

    /**
     * Get current branch ID
     */
    public function getCurrentBranchId()
    {
        return $this->current_branch_id;
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin()
    {
        return $this->is_super_admin;
    }
}
