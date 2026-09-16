<?php

namespace App\Notifications;

use App\Models\AffiliateWithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AffiliateWithdrawalApproved extends Notification
{
    use Queueable;

    public function __construct(private readonly AffiliateWithdrawalRequest $request)
    {
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Chúc mừng — Đơn rút tiền Affiliate đã được duyệt')
            ->greeting('Chúc mừng!')
            ->line('Tài khoản của bạn đã đạt đủ điều kiện và yêu cầu rút tiền đã được admin duyệt.')
            ->line('Số lượng giới thiệu: ' . $this->request->referrals_count)
            ->line('Cảm ơn bạn đã tham gia cùng Ecoluck.');
    }

    public function toArray($notifiable)
    {
        return [
            'type' => 'affiliate_withdrawal_approved',
            'request_id' => $this->request->id,
            'referrals_count' => $this->request->referrals_count,
            'message' => 'Chúc mừng anh/chị đã chính thức được kiếm tiền từ nền tảng Ecoluck.',
            'title' => 'Yêu cầu rút tiền đã được duyệt',
        ];
    }
}
