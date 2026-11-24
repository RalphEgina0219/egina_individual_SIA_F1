<?php

namespace App\Services\Integration;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Permissions;
use Illuminate\Support\Carbon;

/**
 * Isinusulat sa CRM ang mga kopya galing sa may-ari (HR at INV).
 * Ginagamit ng integration:sync-masterdata at ng MasterDataSeeder.
 * Upsert lang gamit ang code; walang binubura.
 */
class MasterDataImporter
{
    /**
     * Isang employee galing HR (format sa B9, o isang row ng employees.csv).
     * Ina-update ang kopya, at ang user na may parehong employee_no
     * (status, can_login, branch, at role kung $updateRole). Walang bagong user na ginagawa.
     */
    public function employee(array $e, bool $updateRole = true): Employee
    {
        $status = $e['employment_status'] ?? 'Active';
        $canLogin = array_key_exists('can_login', $e)
            ? filter_var($e['can_login'], FILTER_VALIDATE_BOOLEAN)
            : Employee::canLoginFor($status);

        $crmRole = collect($e['system_access'] ?? [])->firstWhere('system', 'CRM')['role'] ?? ($e['crm_role'] ?? null);
        $crmRole = $crmRole !== '' ? $crmRole : null;

        $employee = Employee::updateOrCreate(['employee_no' => $e['employee_no']], [
            'first_name' => $e['first_name'] ?? '',
            'last_name' => $e['last_name'] ?? '',
            'email' => $e['email'] ?? null,
            'branch_code' => $e['branch_code'] ?? null,
            'department_code' => $e['department_code'] ?? null,
            'position' => $e['position'] ?? null,
            'employment_status' => $status,
            'can_login' => $canLogin,
            'crm_role' => $crmRole,
            'source_updated_at' => isset($e['updated_at']) ? Carbon::parse($e['updated_at']) : null,
        ]);

        $user = User::where('employee_no', $e['employee_no'])->first();
        if ($user) {
            $updates = [
                'employment_status' => $status,
                'can_login' => $canLogin,
                'branch_code' => $e['branch_code'] ?? $user->branch_code,
            ];
            if ($updateRole && $crmRole && in_array($crmRole, Permissions::ROLES, true)) {
                $updates['role'] = $crmRole;
            }

            $user->update($updates);

            // B7: kapag hindi na puwedeng mag-login, burahin ang mga token niya.
            if (!$canLogin) {
                $user->tokens()->delete();
            }
        }

        return $employee;
    }

    public function branch(array $b): Branch
    {
        $branch = Branch::firstOrNew(['code' => $b['branch_code']]);
        $branch->fill([
            'name' => $b['name'] ?? $branch->name ?? $b['branch_code'],
            'city' => $b['city'] ?? $branch->city ?? '',
            'is_active' => ($b['status'] ?? 'Active') === 'Active',
        ])->save();

        return $branch;
    }

    /**
     * Galing INV: pangalan, kategorya, presyo at status lang.
     * Ang lokal na stock ng CRM ay hindi ginagalaw.
     */
    public function product(array $p): Product
    {
        $product = Product::firstOrNew(['sku' => $p['sku']]);
        $product->fill([
            'product_name' => $p['name'] ?? $product->product_name ?? $p['sku'],
            'category' => $p['category'] ?? $product->category,
            'unit_price' => $p['price'] ?? $product->unit_price ?? 0,
            'status' => $p['status'] ?? 'Active',
        ])->save();

        return $product;
    }

    public function warehouse(array $w): Warehouse
    {
        $warehouse = Warehouse::firstOrNew(['warehouse_code' => $w['warehouse_code']]);
        $warehouse->fill([
            'warehouse_name' => $w['name'] ?? $warehouse->warehouse_name ?? $w['warehouse_code'],
            'location' => $warehouse->location ?? ('From INV ' . ($w['branch_code'] ?? '')),
            'branch_code' => $w['branch_code'] ?? $warehouse->branch_code,
        ])->save();

        return $warehouse;
    }
}
