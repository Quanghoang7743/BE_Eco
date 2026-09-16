<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AffiliateReportTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('complaints')) {
            $this->command?->warn('Skipped: missing users or complaints table.');
            return;
        }

        $now = now();

        $rootUser = DB::table('users')->where('email', 'affiliate-root@example.com')->first();
        if (! $rootUser) {
            $rootUserId = DB::table('users')->insertGetId([
                'name' => 'affiliate_root',
                'full_name' => 'Affiliate Root',
                'email' => 'affiliate-root@example.com',
                'phone' => '0909000101',
                'role' => 'sale',
                'password' => bcrypt('password'),
                'referrer_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $rootUserId = (int) $rootUser->id;
            DB::table('users')->where('id', $rootUserId)->update([
                'name' => 'affiliate_root',
                'full_name' => 'Affiliate Root',
                'phone' => '0909000101',
                'role' => 'sale',
                'updated_at' => $now,
            ]);
        }

        $uplineUser = DB::table('users')->where('email', 'affiliate-upline@example.com')->first();
        if (! $uplineUser) {
            $uplineUserId = DB::table('users')->insertGetId([
                'name' => 'affiliate_upline',
                'full_name' => 'Affiliate Upline',
                'email' => 'affiliate-upline@example.com',
                'phone' => '0909000102',
                'role' => 'sale',
                'password' => bcrypt('password'),
                'referrer_id' => $rootUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $uplineUserId = (int) $uplineUser->id;
            DB::table('users')->where('id', $uplineUserId)->update([
                'name' => 'affiliate_upline',
                'full_name' => 'Affiliate Upline',
                'phone' => '0909000102',
                'role' => 'sale',
                'referrer_id' => $rootUserId,
                'updated_at' => $now,
            ]);
        }

        $f2One = DB::table('users')->where('email', 'affiliate-f2-1@example.com')->first();
        if (! $f2One) {
            $f2OneId = DB::table('users')->insertGetId([
                'name' => 'affiliate_f2_1',
                'full_name' => 'Affiliate F2 One',
                'email' => 'affiliate-f2-1@example.com',
                'phone' => '0909000103',
                'role' => 'user',
                'password' => bcrypt('password'),
                'referrer_id' => $uplineUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $f2OneId = (int) $f2One->id;
            DB::table('users')->where('id', $f2OneId)->update([
                'name' => 'affiliate_f2_1',
                'full_name' => 'Affiliate F2 One',
                'phone' => '0909000103',
                'role' => 'user',
                'referrer_id' => $uplineUserId,
                'updated_at' => $now,
            ]);
        }

        $f2Two = DB::table('users')->where('email', 'affiliate-f2-2@example.com')->first();
        if (! $f2Two) {
            $f2TwoId = DB::table('users')->insertGetId([
                'name' => 'affiliate_f2_2',
                'full_name' => 'Affiliate F2 Two',
                'email' => 'affiliate-f2-2@example.com',
                'phone' => '0909000104',
                'role' => 'user',
                'password' => bcrypt('password'),
                'referrer_id' => $uplineUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $f2TwoId = (int) $f2Two->id;
            DB::table('users')->where('id', $f2TwoId)->update([
                'name' => 'affiliate_f2_2',
                'full_name' => 'Affiliate F2 Two',
                'phone' => '0909000104',
                'role' => 'user',
                'referrer_id' => $uplineUserId,
                'updated_at' => $now,
            ]);
        }

        DB::table('complaint_messages')->whereIn('complaint_id', function ($query) {
            $query->select('id')
                ->from('complaints')
                ->where('complaint_code', 'like', 'AFFTEST-%');
        })->delete();

        DB::table('complaints')->where('complaint_code', 'like', 'AFFTEST-%')->delete();

        $complaints = [
            [
                'id' => (string) Str::uuid(),
                'complaint_code' => 'AFFTEST-' . now()->format('ymdHis') . '-01',
                'user_id' => $f2OneId,
                'store_id' => null,
                'order_id' => null,
                'complaint_type' => 'affiliate_dispute',
                'severity' => 'normal',
                'title' => 'Report F1 nay',
                'description' => 'F2 user_id: ' . $f2OneId . "\nUpline user_id: " . $uplineUserId . "\n\nReason:\nYeu cau admin kiem tra luong gan ket va doi soat hoa hong.",
                'images' => json_encode([]),
                'status' => 'pending',
                'assigned_to' => null,
                'resolved_by' => null,
                'resolution_note' => null,
                'refund_requested' => 0,
                'refund_id' => null,
                'resolved_at' => null,
                'created_at' => $now->copy()->subHours(4),
                'updated_at' => $now->copy()->subHours(4),
            ],
            [
                'id' => (string) Str::uuid(),
                'complaint_code' => 'AFFTEST-' . now()->format('ymdHis') . '-02',
                'user_id' => $f2TwoId,
                'store_id' => null,
                'order_id' => null,
                'complaint_type' => 'affiliate_dispute',
                'severity' => 'high',
                'title' => 'Chuyen tuyen sang F1 moi',
                'description' => 'F2 user_id: ' . $f2TwoId . "\nUpline user_id: " . $uplineUserId . "\n\nReason:\nCan chuyen F2 sang F1 khac sau khi admin xac minh.",
                'images' => json_encode([]),
                'status' => 'processing',
                'assigned_to' => null,
                'resolved_by' => null,
                'resolution_note' => 'Cho super admin phan quyet.',
                'refund_requested' => 0,
                'refund_id' => null,
                'resolved_at' => null,
                'created_at' => $now->copy()->subHours(2),
                'updated_at' => $now->copy()->subHours(2),
            ],
        ];

        DB::table('complaints')->insert($complaints);

        if (Schema::hasTable('complaint_messages')) {
            $messages = [
                [
                    'id' => (string) Str::uuid(),
                    'complaint_id' => $complaints[0]['id'],
                    'sender_id' => $f2OneId,
                    'sender_type' => 'customer',
                    'message' => 'Day la report test affiliate 1.',
                    'attachments' => json_encode([]),
                    'is_from_admin' => 0,
                    'is_read' => 0,
                    'created_at' => $now->copy()->subHours(4),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'complaint_id' => $complaints[0]['id'],
                    'sender_id' => $rootUserId,
                    'sender_type' => 'store_admin',
                    'message' => 'Da nhan report va dang doi soat.',
                    'attachments' => json_encode([]),
                    'is_from_admin' => 1,
                    'is_read' => 1,
                    'created_at' => $now->copy()->subHours(3),
                ],
                [
                    'id' => (string) Str::uuid(),
                    'complaint_id' => $complaints[1]['id'],
                    'sender_id' => $f2TwoId,
                    'sender_type' => 'customer',
                    'message' => 'Day la report test affiliate 2.',
                    'attachments' => json_encode([]),
                    'is_from_admin' => 0,
                    'is_read' => 0,
                    'created_at' => $now->copy()->subHours(2),
                ],
            ];

            DB::table('complaint_messages')->insert($messages);
        }

        $this->command?->info('Inserted affiliate demo users: 4');
        $this->command?->info('Inserted affiliate demo complaints: 2');
    }
}