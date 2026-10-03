<?php

namespace App\Constants;

final class Permissions
{
    // 1. Configuración y Administración
    public const MANAGE_SETTINGS = 'manage_settings';
    public const MANAGE_USERS = 'manage_users';
    public const MANAGE_SHIFTS = 'manage_shifts';
    public const MANAGE_EXPENSE_CATEGORIES = 'manage_expense_categories';

    // 2. Finanzas, Caja Chica y Clientes
    public const VIEW_REPORTS = 'view_reports';
    public const VIEW_EXPENSES = 'view_expenses';
    public const CREATE_EXPENSES = 'create_expenses';
    public const DELETE_CASH_MOVEMENTS = 'delete_cash_movements';
    public const MANAGE_CUSTOMERS = 'manage_customers';
    public const VIEW_CUSTOMERS_ACCOUNT = 'view_customers_account';
    public const COLLECT_CUSTOMER_DEBT = 'collect_customer_debt';

    // 3. POS, Ventas, Precios y Stock
    public const APPLY_DISCOUNTS = 'apply_discounts';
    public const MANAGE_CATALOG = 'manage_catalog';
    public const BULK_PRICE_UPDATE = 'bulk_price_update';
    public const ADJUST_STOCK = 'adjust_stock';
    public const VIEW_KARDEX = 'view_kardex';
    public const VOID_SALES = 'void_sales';

    // 4. Proveedores y Logística
    public const VIEW_SUPPLIERS = 'view_suppliers';
    public const CREATE_SUPPLIER_INVOICE = 'create_supplier_invoice';
    public const PAY_SUPPLIERS = 'pay_suppliers';
    public const MANAGE_DELIVERY_NOTES = 'manage_delivery_notes';

    // 5. Cheques, Presupuestos y Papelera
    public const VIEW_CHECKS = 'view_checks';
    public const ENDORSE_CHECKS = 'endorse_checks';
    public const MANAGE_QUOTES = 'manage_quotes';
    public const MANAGE_TRASH = 'manage_trash';

    /**
     * Retorna el conjunto completo de claves válidas para validación y seeders.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::MANAGE_SETTINGS,
            self::MANAGE_USERS,
            self::MANAGE_SHIFTS,
            self::MANAGE_EXPENSE_CATEGORIES,
            self::VIEW_REPORTS,
            self::VIEW_EXPENSES,
            self::CREATE_EXPENSES,
            self::DELETE_CASH_MOVEMENTS,
            self::MANAGE_CUSTOMERS,
            self::VIEW_CUSTOMERS_ACCOUNT,
            self::COLLECT_CUSTOMER_DEBT,
            self::APPLY_DISCOUNTS,
            self::MANAGE_CATALOG,
            self::BULK_PRICE_UPDATE,
            self::ADJUST_STOCK,
            self::VIEW_KARDEX,
            self::VOID_SALES,
            self::VIEW_SUPPLIERS,
            self::CREATE_SUPPLIER_INVOICE,
            self::PAY_SUPPLIERS,
            self::MANAGE_DELIVERY_NOTES,
            self::VIEW_CHECKS,
            self::ENDORSE_CHECKS,
            self::MANAGE_QUOTES,
            self::MANAGE_TRASH,
        ];
    }
}
