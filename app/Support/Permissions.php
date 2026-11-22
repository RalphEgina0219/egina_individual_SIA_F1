<?php

namespace App\Support;

/**
 * Central role -> permission map used by the `permission` middleware,
 * policies inside controllers, and sent to the frontend on login / me.
 */
class Permissions
{
    public const ADMIN = 'Admin';
    public const MANAGER = 'Manager';
    public const CUSTOMER_SERVICE = 'Customer Service';
    public const MARKETING = 'Marketing';
    public const SALES = 'Sales';
    public const WAREHOUSE = 'Warehouse';

    public const ROLES = [
        self::ADMIN,
        self::MANAGER,
        self::CUSTOMER_SERVICE,
        self::MARKETING,
        self::SALES,
        self::WAREHOUSE,
    ];

    /**
     * permission => roles allowed. Admin and Manager are treated the same.
     */
    public const MAP = [
        // Existing modules
        'products.view' => [self::ADMIN, self::MANAGER, self::SALES, self::WAREHOUSE],
        'products.manage' => [self::ADMIN, self::MANAGER, self::SALES, self::WAREHOUSE],
        'inventory.view' => [self::ADMIN, self::MANAGER, self::WAREHOUSE, self::SALES],
        'inventory.manage' => [self::ADMIN, self::MANAGER, self::WAREHOUSE],
        'warehouses.view' => [self::ADMIN, self::MANAGER, self::WAREHOUSE, self::SALES],
        'warehouses.manage' => [self::ADMIN, self::MANAGER, self::WAREHOUSE],
        'orders.view' => [self::ADMIN, self::MANAGER, self::SALES],
        'orders.manage' => [self::ADMIN, self::MANAGER, self::SALES],
        'reports.sales' => [self::ADMIN, self::MANAGER],
        'users.manage' => [self::ADMIN, self::MANAGER],
        'branches.manage' => [self::ADMIN, self::MANAGER],

        // Customer Management
        'customers.dashboard' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING],
        'customers.lookup' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING, self::SALES],
        'customers.view' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING],
        'customers.view_sensitive' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.create' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.update' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.verify' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.status' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.delete' => [self::ADMIN, self::MANAGER],
        'customers.purchases' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING],
        'customers.purchases.investigate' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'customers.reports' => [self::ADMIN, self::MANAGER, self::MARKETING],
        'customers.notify' => [self::ADMIN, self::MANAGER, self::MARKETING],

        'loyalty.view' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING, self::SALES],
        'loyalty.enroll' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'loyalty.adjust' => [self::ADMIN, self::MANAGER],
        'loyalty.configure' => [self::ADMIN, self::MANAGER],
        'loyalty.sync' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],

        'rewards.view' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING],
        'rewards.manage' => [self::ADMIN, self::MANAGER],
        'redemptions.view' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE, self::MARKETING],
        'redemptions.process' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'redemptions.cancel' => [self::ADMIN, self::MANAGER],

        'feedback.view' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'feedback.record' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'feedback.respond' => [self::ADMIN, self::MANAGER, self::CUSTOMER_SERVICE],
        'feedback.assign' => [self::ADMIN, self::MANAGER],
    ];

    public static function allows(?string $role, string $permission): bool
    {
        return $role !== null && in_array($role, self::MAP[$permission] ?? [], true);
    }

    /**
     * @return string[]
     */
    public static function forRole(?string $role): array
    {
        return array_keys(array_filter(self::MAP, fn ($roles) => in_array($role, $roles, true)));
    }
}
