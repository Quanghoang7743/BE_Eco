<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketPricingService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Buff tickets cho một user theo số điện thoại.
 *
 * Cách dùng:
 *   php artisan db:seed --class=BuffTicketsSeeder
 *
 * Tuỳ chỉnh qua biến môi trường (hoặc sửa trực tiếp $defaults bên dưới):
 *   BUFF_PHONE=0987474663 BUFF_AMOUNT=20000 BUFF_TYPE=TICKET_DEFAULT php artisan db:seed --class=BuffTicketsSeeder
 */
class BuffTicketsSeeder extends Seeder
{
    // ── Cấu hình mặc định ────────────────────────────────────────────────────
    private string $phone  = '09874747663';
    private int    $amount = 20000;
    private ?string $ticketTypeCode = null; // null = dùng loại mặc định trong hệ thống
    // ─────────────────────────────────────────────────────────────────────────

    public function run(): void
    {
        // Cho phép override qua env khi chạy lệnh
        $phone          = (string) (env('BUFF_PHONE',  $this->phone));
        $amount         = (int)    (env('BUFF_AMOUNT', $this->amount));
        $ticketTypeCode = env('BUFF_TYPE', $this->ticketTypeCode) ?: null;

        // ── Tìm user ─────────────────────────────────────────────────────────
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            $this->command->error("Không tìm thấy user với số điện thoại: {$phone}");
            return;
        }

        $this->command->info("User: [{$user->id}] {$user->name} ({$user->phone})");

        // ── Lấy thông tin loại ticket ─────────────────────────────────────────
        /** @var TicketPricingService $pricingService */
        $pricingService = app(TicketPricingService::class);
        $resolvedType   = $pricingService->resolveTicketType($ticketTypeCode);

        if (!$resolvedType) {
            $this->command->error('Không tìm thấy loại ticket phù hợp trong hệ thống.');
            return;
        }

        $resolvedCode  = (string) $resolvedType['code'];
        $purchasedPrice = (float) $resolvedType['price'];

        $this->command->info("Loại ticket: {$resolvedCode} — giá: " . number_format($purchasedPrice) . ' VND');
        $this->command->info("Số lượng buff: " . number_format($amount) . ' vé');

        // ── Tạo batch_id chung cho lần buff này ──────────────────────────────
        $batchId    = 'BUFF_' . strtoupper(Str::random(10));
        $now        = Carbon::now();
        $chunkSize  = 500; // insert theo batch để tránh quá tải bộ nhớ
        $inserted   = 0;

        $this->command->getOutput()->progressStart($amount);

        for ($offset = 0; $offset < $amount; $offset += $chunkSize) {
            $count  = min($chunkSize, $amount - $offset);
            $rows   = [];

            for ($i = 0; $i < $count; $i++) {
                $uuid = (string) Str::uuid();
                $rows[] = [
                    'id'              => $uuid,
                    'ticket_code'     => 'TK-' . strtoupper(Str::random(12)),
                    'user_id'         => $user->id,
                    'status'          => 'available',
                    'ticket_type_code'=> $resolvedCode,
                    'value_amount'    => $purchasedPrice,
                    'purchased_price' => $purchasedPrice,
                    'purchased_at'    => $now,
                    'source_type'     => 'manual_buff',
                    'source_id'       => null,
                    'batch_id'        => $batchId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                    // expired_at sẽ được tính tự động bởi model booted()
                    // nhưng vì dùng insert() (bypass model), ta tính thủ công:
                    'expired_at'      => $now->copy()->addDays(
                        max(1, (int) (\App\Models\appSetting::query()->value('ticket_expiry_days')
                            ?? \App\Models\appSetting::DEFAULT_TICKET_EXPIRY_DAYS))
                    ),
                ];
            }

            Ticket::insert($rows);
            $inserted += $count;
            $this->command->getOutput()->progressAdvance($count);
        }

        $this->command->getOutput()->progressFinish();

        $this->command->info("✅ Đã buff {$inserted} vé cho user [{$user->id}] {$user->name}.");
        $this->command->info("   Batch ID: {$batchId}");
    }
}
