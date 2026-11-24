<?php

namespace Database\Seeders;

use App\Services\Integration\CsvData;
use App\Services\Integration\MasterDataImporter;
use Illuminate\Database\Seeder;

/**
 * php artisan db:seed --class=MasterDataSeeder
 *
 * Bahagi C → CRM. Upsert lang gamit ang code; walang binubura,
 * kaya ligtas itong patakbuhin ulit (pati sa live database).
 *
 * Sa CRM, ang kopya ng employees (HR) lang ang ini-import dito. Ang
 * sariling branches, products, warehouses at users ng CRM ay itinutugma
 * sa mga code gamit ang: php artisan integration:map-master-data
 */
class MasterDataSeeder extends Seeder
{
    public function run(MasterDataImporter $import): void
    {
        $count = 0;
        foreach (CsvData::read('employees') as $row) {
            // Hindi pinapalitan ang role ng users dito; ang HR sync (Yugto 2) ang gagawa noon.
            $import->employee($row, updateRole: false);
            $count++;
        }

        $this->command?->info("MasterDataSeeder: {$count} employee(s) upserted from employees.csv.");
    }
}
