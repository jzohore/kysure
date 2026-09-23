<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Menu;

enum SystemAdminMenuItem: string
{
    case DASHBOARD = 'dashboard';
    case WORKSPACES = 'workspaces';
    case SUPPORT = 'support';
    case SUBSCRIPTIONS = 'subscriptions';
    case COMPLIANCE = 'compliance';
    case ADMINS = 'admins';
    case AUDIT_LOGS = 'audit_logs';
    case CRONS = 'crons';
    case FEEDBACK = 'feedback';

    public function getLabel(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Tableau de Bord',
            self::WORKSPACES => 'Workspaces & Clients',
            self::SUPPORT => 'Support & Tickets', // 👈 Nouveau label
            self::SUBSCRIPTIONS => 'Abonnements & MRR',
            self::COMPLIANCE => 'Dossiers Compliance',
            self::ADMINS => 'Équipe Super-Admin',
            self::AUDIT_LOGS => 'Journal d\'Audit',
            self::CRONS => 'Tâches planifiées',
            self::FEEDBACK => 'Retours des testeurs',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DASHBOARD => 'lucide:layout-dashboard',
            self::WORKSPACES => 'lucide:building-2',
            self::SUPPORT => 'lucide:life-buoy', // 👈 Icône cohérente avec le côté client
            self::SUBSCRIPTIONS => 'lucide:credit-card',
            self::COMPLIANCE => 'lucide:scale',
            self::ADMINS => 'lucide:shield-check',
            self::AUDIT_LOGS => 'lucide:scroll-text',
            self::CRONS => 'lucide:timer',
            self::FEEDBACK => 'lucide:message-square-text',
        };
    }

    /**
     * Entrées réservées au ROLE_SUPER_ADMIN (gestion de l'équipe, zones sensibles).
     * Les opérateurs (ROLE_ADMIN) ne les voient pas dans la barre latérale.
     */
    public function isSuperAdminOnly(): bool
    {
        return match ($this) {
            self::ADMINS, self::AUDIT_LOGS, self::SUBSCRIPTIONS, self::COMPLIANCE, self::CRONS => true,
            default => false,
        };
    }

    public function getRoute(): string
    {
        return match ($this) {
            self::DASHBOARD => 'admin_dashboard',
            self::WORKSPACES => 'admin_organizations_list',
            self::SUPPORT => 'admin_support_list',
            self::SUBSCRIPTIONS => 'admin_subscriptions_list',
            self::COMPLIANCE => 'admin_compliance_list',
            self::ADMINS => 'account_admin_list',
            self::AUDIT_LOGS => 'admin_audit_logs_list',
            self::CRONS => 'admin_crons_list',
            self::FEEDBACK => 'admin_feedback_list',
        };
    }
}
