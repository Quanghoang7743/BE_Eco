<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComplaintSampleSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = (string) (DB::table('users')->orderBy('id')->value('id') ?? '1');
        $now = now();

        $store1Id = (string) Str::uuid();
        $store2Id = (string) Str::uuid();

        $stores = [
            [
                'id' => $store1Id,
                'store_code' => 'S15DEMO-STORE-001',
                'owner_id' => $ownerId,
                'name' => 'Cua hang demo 1',
                'address' => '123 Demo Street',
                'status' => 'pending',
                'is_open' => 1,
                'open_time' => '09:00:00',
                'close_time' => '21:00:00',
                'commission_rate' => 10,
                'rating_avg' => 0,
                'rating_count' => 0,
                'min_order_tickets' => 1,
                'max_order_tickets' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $store2Id,
                'store_code' => 'S15DEMO-STORE-002',
                'owner_id' => $ownerId,
                'name' => 'Cua hang demo 2',
                'address' => '456 Sample Avenue',
                'status' => 'pending',
                'is_open' => 1,
                'open_time' => '09:00:00',
                'close_time' => '21:00:00',
                'commission_rate' => 10,
                'rating_avg' => 0,
                'rating_count' => 0,
                'min_order_tickets' => 1,
                'max_order_tickets' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        $complaints = [
            [
                'id' => (string) Str::uuid(),
                'complaint_code' => 'S15DEMO-CPL-001',
                'user_id' => $ownerId,
                'store_id' => $store1Id,
                'order_id' => null,
                'complaint_type' => 'food_quality',
                'severity' => 'high',
                'title' => 'Mon an khong dung nhu mo ta',
                'description' => 'Khach hang phan anh mon an giao khong dung mo ta tren app.',
                'images' => json_encode(['https://example.com/complaints/demo1.jpg']),
                'status' => 'pending',
                'assigned_to' => null,
                'resolved_by' => null,
                'resolution_note' => null,
                'refund_requested' => 1,
                'refund_id' => null,
                'resolved_at' => null,
                'created_at' => $now->copy()->subHours(10),
                'updated_at' => $now->copy()->subHours(10),
            ],
            [
                'id' => (string) Str::uuid(),
                'complaint_code' => 'S15DEMO-CPL-002',
                'user_id' => $ownerId,
                'store_id' => $store1Id,
                'order_id' => null,
                'complaint_type' => 'delivery_delay',
                'severity' => 'normal',
                'title' => 'Don giao cham',
                'description' => 'Khach hang doi qua lau moi nhan duoc don.',
                'images' => json_encode([]),
                'status' => 'processing',
                'assigned_to' => $ownerId,
                'resolved_by' => null,
                'resolution_note' => 'Da phan cong admin cua hang xu ly.',
                'refund_requested' => 0,
                'refund_id' => null,
                'resolved_at' => null,
                'created_at' => $now->copy()->subHours(6),
                'updated_at' => $now->copy()->subHours(3),
            ],
            [
                'id' => (string) Str::uuid(),
                'complaint_code' => 'S15DEMO-CPL-003',
                'user_id' => $ownerId,
                'store_id' => $store2Id,
                'order_id' => null,
                'complaint_type' => 'wrong_item',
                'severity' => 'critical',
                'title' => 'Giao sai mon va can hoan ve',
                'description' => 'Khach hang nhan sai mon va yeu cau hoan ve.',
                'images' => json_encode(['https://example.com/complaints/demo3.jpg']),
                'status' => 'resolved',
                'assigned_to' => $ownerId,
                'resolved_by' => $ownerId,
                'resolution_note' => 'Da xac nhan loi cua cua hang, xu ly xong.',
                'refund_requested' => 1,
                'refund_id' => null,
                'resolved_at' => $now->copy()->subHour(),
                'created_at' => $now->copy()->subHours(24),
                'updated_at' => $now->copy()->subHour(),
            ],
        ];

        $messages = [];

        foreach ($complaints as $complaint) {
            $messages[] = [
                'id' => (string) Str::uuid(),
                'complaint_id' => $complaint['id'],
                'sender_id' => $ownerId,
                'sender_type' => 'customer',
                'message' => 'Toi muon phan anh van de cua don hang nay.',
                'attachments' => json_encode([]),
                'is_from_admin' => 0,
                'is_read' => 1,
                'created_at' => $now->copy()->subHours(5),
            ];

            $messages[] = [
                'id' => (string) Str::uuid(),
                'complaint_id' => $complaint['id'],
                'sender_id' => $ownerId,
                'sender_type' => 'store_admin',
                'message' => 'Chung toi da tiep nhan, dang xu ly.',
                'attachments' => json_encode([]),
                'is_from_admin' => 1,
                'is_read' => 0,
                'created_at' => $now->copy()->subHours(2),
            ];
        }

        DB::transaction(function () use ($stores, $complaints, $messages) {
            $complaintIds = DB::table('complaints')
                ->where('complaint_code', 'like', 'S15DEMO-%')
                ->pluck('id');

            if ($complaintIds->isNotEmpty()) {
                DB::table('complaint_messages')->whereIn('complaint_id', $complaintIds)->delete();
                DB::table('complaints')->whereIn('id', $complaintIds)->delete();
            }

            DB::table('stores')->where('store_code', 'like', 'S15DEMO-%')->delete();

            DB::table('stores')->insert($stores);
            DB::table('complaints')->insert($complaints);
            DB::table('complaint_messages')->insert($messages);
        });
    }
}
