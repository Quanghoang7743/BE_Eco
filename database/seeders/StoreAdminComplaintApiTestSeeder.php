<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StoreAdminComplaintApiTestSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('complaints') || !Schema::hasTable('orders')) {
            $this->command?->warn('Skipped: missing complaints/orders table.');
            return;
        }

        $stores = DB::table('orders')
            ->select('store_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('store_id')
            ->groupBy('store_id')
            ->orderByDesc('c')
            ->limit(2)
            ->get();

        if ($stores->isEmpty()) {
            $this->command?->warn('Skipped: no orders with store_id found.');
            return;
        }

        $now = now();
        $firstUserId = Schema::hasTable('users') ? DB::table('users')->orderBy('created_at')->value('id') : null;

        $insertedComplaints = 0;
        $insertedMessages = 0;

        foreach ($stores as $storeRow) {
            $storeId = (string) $storeRow->store_id;
            $orders = DB::table('orders')
                ->where('store_id', $storeId)
                ->orderByDesc('created_at')
                ->limit(3)
                ->get();

            foreach ($orders as $idx => $order) {
                $complaintId = (string) Str::uuid();
                $complaintCode = 'TEST-CPL-' . now()->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                $userId = $this->hasColumn('orders', 'user_id') ? ($order->user_id ?? $firstUserId) : $firstUserId;

                $complaintPayload = [
                    'id' => $complaintId,
                ];

                if ($this->hasColumn('complaints', 'complaint_code')) {
                    $complaintPayload['complaint_code'] = $complaintCode;
                }
                if ($this->hasColumn('complaints', 'user_id')) {
                    $complaintPayload['user_id'] = $userId;
                }
                if ($this->hasColumn('complaints', 'store_id')) {
                    $complaintPayload['store_id'] = $storeId;
                }
                if ($this->hasColumn('complaints', 'order_id')) {
                    $complaintPayload['order_id'] = $order->id;
                }
                if ($this->hasColumn('complaints', 'complaint_type')) {
                    $complaintPayload['complaint_type'] = $idx % 2 === 0 ? 'food_quality' : 'delivery_delay';
                }
                if ($this->hasColumn('complaints', 'severity')) {
                    $complaintPayload['severity'] = ['normal', 'high', 'critical'][$idx % 3];
                }
                if ($this->hasColumn('complaints', 'title')) {
                    $complaintPayload['title'] = 'Khieu nai test API list #' . ($idx + 1);
                }
                if ($this->hasColumn('complaints', 'description')) {
                    $complaintPayload['description'] = 'Du lieu mau de test endpoint GET /api/store-admin/complaints';
                }
                if ($this->hasColumn('complaints', 'images')) {
                    $complaintPayload['images'] = json_encode(['https://picsum.photos/seed/' . $complaintId . '/640/360']);
                }
                if ($this->hasColumn('complaints', 'status')) {
                    $complaintPayload['status'] = ['pending', 'processing', 'resolved'][$idx % 3];
                }
                if ($this->hasColumn('complaints', 'refund_requested')) {
                    $complaintPayload['refund_requested'] = $idx % 2 === 0 ? 1 : 0;
                }
                if ($this->hasColumn('complaints', 'created_at')) {
                    $complaintPayload['created_at'] = $now->copy()->subHours(8 - $idx);
                }
                if ($this->hasColumn('complaints', 'updated_at')) {
                    $complaintPayload['updated_at'] = $now->copy()->subHours(7 - $idx);
                }

                DB::table('complaints')->insert($complaintPayload);
                $insertedComplaints++;

                if (Schema::hasTable('complaint_messages')) {
                    $messageCustomer = [
                        'id' => (string) Str::uuid(),
                    ];
                    if ($this->hasColumn('complaint_messages', 'complaint_id')) {
                        $messageCustomer['complaint_id'] = $complaintId;
                    }
                    if ($this->hasColumn('complaint_messages', 'sender_id')) {
                        $messageCustomer['sender_id'] = $userId;
                    }
                    if ($this->hasColumn('complaint_messages', 'sender_type')) {
                        $messageCustomer['sender_type'] = 'customer';
                    }
                    if ($this->hasColumn('complaint_messages', 'message')) {
                        $messageCustomer['message'] = 'Khach hang phan anh chat luong/phuc vu.';
                    }
                    if ($this->hasColumn('complaint_messages', 'attachments')) {
                        $messageCustomer['attachments'] = json_encode([]);
                    }
                    if ($this->hasColumn('complaint_messages', 'is_from_admin')) {
                        $messageCustomer['is_from_admin'] = 0;
                    }
                    if ($this->hasColumn('complaint_messages', 'is_read')) {
                        $messageCustomer['is_read'] = 0;
                    }
                    if ($this->hasColumn('complaint_messages', 'created_at')) {
                        $messageCustomer['created_at'] = $now->copy()->subHours(6 - $idx);
                    }

                    $messageAdmin = [
                        'id' => (string) Str::uuid(),
                    ];
                    if ($this->hasColumn('complaint_messages', 'complaint_id')) {
                        $messageAdmin['complaint_id'] = $complaintId;
                    }
                    if ($this->hasColumn('complaint_messages', 'sender_id')) {
                        $messageAdmin['sender_id'] = $firstUserId;
                    }
                    if ($this->hasColumn('complaint_messages', 'sender_type')) {
                        $messageAdmin['sender_type'] = 'store_admin';
                    }
                    if ($this->hasColumn('complaint_messages', 'message')) {
                        $messageAdmin['message'] = 'Store admin da tiep nhan va dang xu ly.';
                    }
                    if ($this->hasColumn('complaint_messages', 'attachments')) {
                        $messageAdmin['attachments'] = json_encode([]);
                    }
                    if ($this->hasColumn('complaint_messages', 'is_from_admin')) {
                        $messageAdmin['is_from_admin'] = 1;
                    }
                    if ($this->hasColumn('complaint_messages', 'is_read')) {
                        $messageAdmin['is_read'] = 1;
                    }
                    if ($this->hasColumn('complaint_messages', 'created_at')) {
                        $messageAdmin['created_at'] = $now->copy()->subHours(5 - $idx);
                    }

                    DB::table('complaint_messages')->insert([$messageCustomer, $messageAdmin]);
                    $insertedMessages += 2;
                }
            }
        }

        $this->command?->info('Inserted complaints: ' . $insertedComplaints);
        $this->command?->info('Inserted messages: ' . $insertedMessages);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
