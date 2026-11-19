<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\Scale;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('local', 'testing'), 403, 'Demo accounts are local/testing only.');
        foreach (['admin'] as $role) {
            User::firstOrCreate(['email' => $role . '@gmail.com'], ['name' => ucfirst($role) . ' Demo', 'password' => 'med-demo-2026!', 'role' => $role, 'active' => true, 'email_verified_at' => now()]);
        }
        Scale::firstOrCreate(['simulation' => false], ['device_identifier' => 'esp32-01', 'name' => 'Dispensary scale', 'active' => true]);
        foreach ([['PAR-500', 'Paracetamol', '500 mg', 'Tablet', 'Unilab'], ['AMX-500', 'Amoxicillin', '500 mg', 'Capsule', 'Ritemed'], ['CET-10', 'Cetirizine', '10 mg', 'Tablet', 'Unilab'], ['ASC-500', 'Ascorbic acid', '500 mg', 'Tablet', 'Generika']] as [$code, $name, $strength, $form, $maker]) {
            Medicine::firstOrCreate(['code' => $code], ['generic_name' => $name, 'strength' => $strength, 'dosage_form' => $form, 'manufacturer' => $maker, 'low_stock_threshold' => 20]);
            Medicine::firstOrCreate(['code' => 'SIM-' . $code], ['generic_name' => $name . ' (demo)', 'strength' => $strength, 'dosage_form' => $form, 'manufacturer' => $maker, 'low_stock_threshold' => 20, 'simulation' => true]);
        }
    }
}
