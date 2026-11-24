<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Integration\CsvData;
use App\Services\Integration\MasterCodes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan integration:map-master-data [--dry-run] [--force]
 *
 * Itinutugma ang sariling data ng CRM sa mga code ng grupo (Bahagi C):
 *   branches.code      → BR-001 ...
 *   warehouses         → warehouse_code WH-001 ... (at branch_code)
 *   products.sku       → SKU-0001 ... (ang lumang SKU ay itinatago sa legacy_sku)
 *   users.employee_no  → EMP-0001 ... (at employment_status, can_login, branch_code)
 *
 * Paano itinutugma: una ang database/seeders/data/crm_mapping.csv;
 * kung wala roon, ang parehong pangalan (o email) sa CSV ng Bahagi C.
 * UPDATE lang: walang binubura at walang bagong row. Ipinapakita muna ang
 * preview, at nagtatanong bago mag-save.
 */
class MapMasterData extends Command
{
    protected $signature = 'integration:map-master-data
        {--dry-run : Ipakita lang ang preview, huwag mag-save}
        {--force : Mag-save nang hindi na nagtatanong}';

    protected $description = 'Map CRM branches, warehouses, products and users to the team codes (BR-, WH-, SKU-, EMP-)';

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    private array $preview = [];

    /** @var array<int, callable> */
    private array $changes = [];

    /** @var string[] */
    private array $warnings = [];

    /** @var array<string, true> mga code na nakuha na sa takbong ito */
    private array $claimed = [];

    private function claim(string $type, string $code, string $label): bool
    {
        if (isset($this->claimed["{$type}:{$code}"])) {
            $this->warnings[] = "{$label}: {$code} is already planned for another record. Skipped.";

            return false;
        }

        return $this->claimed["{$type}:{$code}"] = true;
    }

    public function handle(): int
    {
        $mapping = collect(CsvData::read('crm_mapping'))->groupBy('type');

        $this->planBranches($mapping->get('branch', collect()));
        $this->planWarehouses($mapping->get('warehouse', collect()));
        $this->planProducts($mapping->get('product', collect()));
        $this->planUsers($mapping->get('user', collect()));

        if ($this->preview) {
            $this->table(['Type', 'Record', 'Field', 'Now', 'New'], $this->preview);
        } else {
            $this->info('Nothing to change: everything is already mapped.');
        }

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }

        if (!$this->changes || $this->option('dry-run')) {
            if ($this->option('dry-run')) {
                $this->info('Dry run: nothing was saved.');
            }

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm(count($this->changes) . ' record(s) will be updated. Save these changes?', false)) {
            $this->info('Cancelled: nothing was saved.');

            return self::SUCCESS;
        }

        DB::transaction(fn () => array_map(fn (callable $change) => $change(), $this->changes));
        $this->info(count($this->changes) . ' record(s) updated.');

        return self::SUCCESS;
    }

    private function planBranches($mapping): void
    {
        $master = collect(CsvData::read('branches'));

        foreach (Branch::orderBy('id')->get() as $branch) {
            if (MasterCodes::isBranch($branch->code)) {
                continue;
            }

            $code = $mapping->firstWhere('match_value', $branch->code)['code']
                ?? $master->first(fn ($row) => $this->same($row['name'], $branch->name))['branch_code']
                ?? null;

            if (!$code) {
                $this->warnings[] = "Branch {$branch->code} ({$branch->name}): no match. Add it to crm_mapping.csv.";
                continue;
            }
            if (Branch::where('code', $code)->whereKeyNot($branch->id)->exists()) {
                $this->warnings[] = "Branch {$branch->code}: {$code} is already used by another branch. Skipped.";
                continue;
            }

            if (!$this->claim('branch', $code, "Branch {$branch->code}")) {
                continue;
            }
            $this->preview[] = ['branch', "#{$branch->id} {$branch->name}", 'code', $branch->code, $code];
            $this->changes[] = fn () => $branch->update(['code' => $code]);
        }
    }

    private function planWarehouses($mapping): void
    {
        $master = collect(CsvData::read('warehouses'));

        foreach (Warehouse::orderBy('id')->get() as $warehouse) {
            if ($warehouse->warehouse_code) {
                continue;
            }

            $code = $mapping->first(fn ($row) => $this->same($row['match_value'], $warehouse->warehouse_name))['code']
                ?? $master->first(fn ($row) => $this->same($row['name'], $warehouse->warehouse_name))['warehouse_code']
                ?? null;

            if (!$code) {
                $this->warnings[] = "Warehouse \"{$warehouse->warehouse_name}\": no match. Add it to crm_mapping.csv.";
                continue;
            }
            if (Warehouse::where('warehouse_code', $code)->exists()) {
                $this->warnings[] = "Warehouse \"{$warehouse->warehouse_name}\": {$code} is already used. Skipped.";
                continue;
            }

            if (!$this->claim('warehouse', $code, "Warehouse \"{$warehouse->warehouse_name}\"")) {
                continue;
            }
            $branchCode = $master->firstWhere('warehouse_code', $code)['branch_code'] ?? null;
            $this->preview[] = ['warehouse', "#{$warehouse->id} {$warehouse->warehouse_name}", 'warehouse_code / branch_code', '-', "{$code} / " . ($branchCode ?? '-')];
            $this->changes[] = fn () => $warehouse->update(['warehouse_code' => $code, 'branch_code' => $branchCode]);
        }
    }

    private function planProducts($mapping): void
    {
        $master = collect(CsvData::read('products'));

        foreach (Product::orderBy('id')->get() as $product) {
            if ($product->hasInventorySku()) {
                continue;
            }

            $sku = $mapping->firstWhere('match_value', $product->sku)['code']
                ?? $master->first(fn ($row) => $this->same($row['name'], $product->product_name))['sku']
                ?? null;

            if (!$sku) {
                $this->warnings[] = "Product \"{$product->product_name}\" ({$product->sku}): no INV SKU. Sales of it will show stock sync = failed until INV adds it.";
                continue;
            }
            if (Product::where('sku', $sku)->whereKeyNot($product->id)->exists()) {
                $this->warnings[] = "Product \"{$product->product_name}\": {$sku} is already used by another product. Skipped.";
                continue;
            }

            if (!$this->claim('product', $sku, "Product \"{$product->product_name}\"")) {
                continue;
            }
            $this->preview[] = ['product', "#{$product->id} {$product->product_name}", 'sku (old kept in legacy_sku)', $product->sku, $sku];
            $this->changes[] = fn () => $product->update(['legacy_sku' => $product->legacy_sku ?? $product->sku, 'sku' => $sku]);
        }
    }

    private function planUsers($mapping): void
    {
        $employees = collect(CsvData::read('employees'))->keyBy('employee_no');

        foreach (User::orderBy('id')->get() as $user) {
            if ($user->employee_no) {
                continue;
            }

            $employeeNo = $mapping->first(fn ($row) => $this->same($row['match_value'], $user->email))['code']
                ?? $employees->first(fn ($row) => $this->same($row['email'], $user->email))['employee_no']
                ?? null;

            if (!$employeeNo) {
                $this->warnings[] = "User {$user->email}: no employee_no match. Add it to crm_mapping.csv if this is a real employee.";
                continue;
            }
            if (User::where('employee_no', $employeeNo)->exists()) {
                $this->warnings[] = "User {$user->email}: {$employeeNo} is already used by another user. Skipped.";
                continue;
            }

            if (!$this->claim('user', $employeeNo, "User {$user->email}")) {
                continue;
            }
            $employee = $employees->get($employeeNo, []);
            $status = $employee['employment_status'] ?? 'Active';
            $updates = [
                'employee_no' => $employeeNo,
                'employment_status' => $status,
                'can_login' => in_array($status, ['Active', 'On Leave'], true),
                'branch_code' => $employee['branch_code'] ?? null,
            ];

            if (!empty($employee['crm_role']) && $employee['crm_role'] !== $user->role) {
                $this->warnings[] = "User {$user->email}: CRM role is {$user->role} but HR says {$employee['crm_role']}. Role is NOT changed here; HR sync will update it in Yugto 2.";
            }

            $this->preview[] = ['user', "#{$user->id} {$user->email}", 'employee_no / branch_code', '-', "{$employeeNo} / " . ($updates['branch_code'] ?? '-')];
            $this->changes[] = fn () => $user->update($updates);
        }
    }

    private function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
