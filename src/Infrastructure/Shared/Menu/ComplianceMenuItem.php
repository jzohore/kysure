<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Menu;

enum ComplianceMenuItem: string
{
    case DASHBOARD = 'dashboard';
    case CLIENTS = 'clients';
    case DOSSIERS = 'folders';
    case SCREENING = 'screening';
    case PRODUCTS = 'products';

    public function getLabel(): string
    {
        return match ($this) {
            self::DASHBOARD => 'Tableau de Bord',
            self::CLIENTS => 'Mes clients',
            self::DOSSIERS => 'Dossiers',
            self::SCREENING => 'Criblage LCB-FT',
            self::PRODUCTS => 'Produits',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::DASHBOARD => 'lucide:layout-dashboard',
            self::CLIENTS => 'lucide:users-round',
            self::DOSSIERS => 'lucide:shield-check',
            self::SCREENING => 'lucide:user-round-search',
            self::PRODUCTS => 'lucide:package',
        };
    }

    public function getRoute(): string
    {
        return match ($this) {
            self::DASHBOARD => 'app_dashboard',
            self::CLIENTS => 'app_clients_list',
            self::DOSSIERS => 'app_compliance_list',
            self::SCREENING => 'app_screening_list',
            self::PRODUCTS => 'app_products_list',
        };
    }

    /**
     * Détermine si l'item doit afficher un compteur dynamique.
     */
    public function hasBadge(): bool
    {
        return self::DOSSIERS === $this;
    }
}
