<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreApprovalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('stores')) {
            $this->command?->warn('Skipped: users/stores table not found.');
            return;
        }

        $now = now();

        $approverId = $this->upsertUser(
            'store-approver-demo@ecoluck.local',
            'Approver Demo',
            'Nguoi duyet demo',
            'admin',
            '0900001999'
        );

        $owners = [
            $this->upsertUser('owner-demo-01@ecoluck.local', 'Owner Demo 01', 'Chu cua hang 01', 'account_user', '0900001001'),
            $this->upsertUser('owner-demo-02@ecoluck.local', 'Owner Demo 02', 'Chu cua hang 02', 'account_user', '0900001002'),
            $this->upsertUser('owner-demo-03@ecoluck.local', 'Owner Demo 03', 'Chu cua hang 03', 'account_user', '0900001003'),
            $this->upsertUser('owner-demo-04@ecoluck.local', 'Owner Demo 04', 'Chu cua hang 04', 'account_user', '0900001004'),
            $this->upsertUser('owner-demo-05@ecoluck.local', 'Owner Demo 05', 'Chu cua hang 05', 'account_user', '0900001005'),
        ];

        $statuses = [
            'pending', 'pending', 'pending', 'pending', 'pending',
            'active', 'active', 'active', 'active',
            'rejected', 'rejected', 'rejected',
        ];

        foreach ($statuses as $index => $status) {
            $n = $index + 1;
            $storeCode = sprintf('APP-DEMO-%03d', $n);
            $existing = DB::table('stores')->where('store_code', $storeCode)->first();

            $ownerId = (string) $owners[$index % count($owners)];
            $isApproved = in_array($status, ['active', 'rejected'], true);

            $payload = [
                'store_code' => $storeCode,
                'owner_id' => $ownerId,
                'name' => 'Cua hang demo ' . $n,
                'description' => 'Du lieu demo cho man hinh duyet cua hang.',
                'address' => ($n * 9) . ' Nguyen Trai, Quan ' . (($n % 10) + 1) . ', TP.HCM',
                'phone' => '0912' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'email' => 'store-demo-' . $n . '@ecoluck.local',
                'license_number' => 'GPLK-DEMO-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'license_image' => 'https://picsum.photos/seed/store-approval-' . $n . '/640/360',
                'representative' => 'Dai dien demo ' . $n,
                'business_type' => $n % 2 === 0 ? 'Restaurant' : 'Coffee',
                'bank_name' => $n % 2 === 0 ? 'Vietcombank' : 'ACB',
                'bank_account' => '0011' . str_pad((string) ($n * 97), 8, '0', STR_PAD_LEFT),
                'status' => $status,
                'is_open' => $status === 'active',
                'open_time' => '08:00:00',
                'close_time' => '22:00:00',
                'approved_by' => $isApproved ? (string) $approverId : null,
                'approved_at' => $isApproved ? $now->copy()->subDays(max(1, 13 - $n)) : null,
                'rejection_reason' => $status === 'rejected' ? 'Ho so phap ly chua hop le (du lieu demo).' : null,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('stores')->where('id', $existing->id)->update($payload);
                continue;
            }

            $payload['id'] = (string) Str::uuid();
            $payload['created_at'] = $now->copy()->subDays(15 - $n);
            DB::table('stores')->insert($payload);
        }

        $this->command?->info('Seeded store approval demo data: 12 stores (pending/active/rejected).');
    }

    private function upsertUser(string $email, string $name, string $fullName, string $role, string $phone): int
    {
        $existing = DB::table('users')->where('email', $email)->first();

        $payload = [
            'name' => $name,
            'full_name' => $fullName,
            'phone' => $phone,
            'role' => $role,
            'email' => $email,
            'password' => Hash::make('password'),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update($payload);
            return (int) $existing->id;
        }

        $payload['created_at'] = now();
        return (int) DB::table('users')->insertGetId($payload);
    }
}
