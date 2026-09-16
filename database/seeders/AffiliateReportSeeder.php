<?php

namespace Database\Seeders;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Database\Seeder;

class AffiliateReportSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create some users for F2, F1, and reported uplines
        $users = User::where('role', 'user')->limit(20)->get();
        if ($users->count() < 10) {
            $users = User::factory(10)->create(['role' => 'user']);
        }

        $statuses = ['pending', 'processing', 'resolved', 'closed', 'new'];
        $severities = ['low', 'normal', 'high', 'critical'];

        $titles = [
            'F1 không phê duyệt lợi nhuận hợp lệ',
            'F1 chiếm dụm hoa hồng của F2',
            'F1 không hỗ trợ marketing cho F2',
            'F1 lạm dụng quyền hạn để loại bỏ F2',
            'F1 không cung cấp dữ liệu bán hàng',
            'F1 yêu cầu F2 mua hàng không cần thiết',
            'F1 có hành vi gian lận trong tính lợi nhuận',
            'F1 từ chối trả lương cho F2 đúng hạn',
        ];

        $descriptions = [
            'F2 đã làm việc tốt nhưng không nhận được lợi nhuận đúng hẹn. F1 nói sẽ thanh toán nhưng cứ trì hoãn.',
            'F2 phát hiện F1 đã chiếm dụm một phần hoa hồng mà F2 xứng đáng nhận được.',
            'F1 không cung cấp bất kỳ hỗ trợ marketing hoặc tài liệu quảng cáo cho F2.',
            'F1 đột ngột loại bỏ F2 khỏi team mà không có lý do chính đáng.',
            'F1 từ chối cung cấp dữ liệu chi tiết về bán hàng và lợi nhuận của F2.',
            'F1 liên tục yêu cầu F2 mua hàng để duy trì vị trí mặc dù bán hàng chậm.',
            'F2 phát hiện sai sót trong cách tính lợi nhuận của F1, nghi ngờ gian lận.',
            'F1 trì hoãn thanh toán lương cho F2 trong nhiều tháng liên tiếp.',
        ];

        // Create 120 fake affiliate dispute reports for pagination testing
        for ($i = 0; $i < 120; $i++) {
            $f2User = $users->random();
            $status = $statuses[array_rand($statuses)];
            $resolvedAt = in_array($status, ['resolved', 'closed']) ? now()->subDays(rand(1, 30)) : null;

            Complaint::create([
                'complaint_code' => 'AFF-' . strtoupper(uniqid()),
                'user_id' => $f2User->id,
                'complaint_type' => 'affiliate_dispute',
                'title' => $titles[array_rand($titles)],
                'description' => $descriptions[array_rand($descriptions)],
                'severity' => $severities[array_rand($severities)],
                'status' => $status,
                'assigned_to' => rand(0, 1) ? User::where('role', 'admin')->first()?->id : null,
                'refund_requested' => rand(0, 1) ? true : false,
                'resolved_at' => $resolvedAt,
                'resolution_note' => $resolvedAt ? 'Đã xem xét và duyệt yêu cầu. Đã chuyển F2 sang F1 mới.' : null,
                'created_at' => now()->subDays(rand(0, 60)),
                'updated_at' => now()->subDays(rand(0, 60)),
            ]);
        }

        $this->command->info('Đã tạo 120 fake affiliate dispute reports!');
    }
}
