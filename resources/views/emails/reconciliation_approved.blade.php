@extends('layouts.email')

@section('content')
<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2 style="color: #006c4e; margin-bottom: 20px;">🎉 Đối soát giao dịch của bạn đã được duyệt</h2>

    <p>Xin chào <strong>{{ $store->name }}</strong>,</p>

    <p>Chúng tôi vui mừng thông báo rằng đối soát giao dịch của cửa hàng bạn đã được hệ thống duyệt và phê chuẩn.</p>

    <div style="background-color: #f7faf8; border-left: 4px solid #006c4e; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <h3 style="color: #006c4e; margin-top: 0;">📊 Thông tin đối soát</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; font-weight: bold; width: 40%;">Ngày đối soát:</td>
                <td style="padding: 8px 0;">{{ \Carbon\Carbon::parse($reconciliation->reconciliation_date)->format('d/m/Y') }}</td>
            </tr>
            <tr style="background-color: #fff;">
                <td style="padding: 8px 0; font-weight: bold;">Tổng doanh thu:</td>
                <td style="padding: 8px 0; color: #006c4e; font-weight: bold; font-size: 16px;">
                    {{ number_format($reconciliation->gross_revenue, 0, ',', '.') }} VND
                </td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-weight: bold;">Tổng thực nhận:</td>
                <td style="padding: 8px 0; color: #2ebd8e; font-weight: bold; font-size: 16px;">
                    {{ number_format($reconciliation->net_revenue, 0, ',', '.') }} VND
                </td>
            </tr>
            @if($reconciliation->notes)
            <tr style="background-color: #fff;">
                <td style="padding: 8px 0; font-weight: bold; vertical-align: top;">Ghi chú:</td>
                <td style="padding: 8px 0;">{{ $reconciliation->notes }}</td>
            </tr>
            @endif
        </table>
    </div>

    <div style="background-color: #e8f5e9; border-left: 4px solid #2ebd8e; padding: 15px; margin: 20px 0; border-radius: 4px;">
        <h4 style="color: #2ebd8e; margin: 0;">✅ Trạng thái: Đã duyệt</h4>
        <p style="margin: 5px 0; color: #666;">Ngày duyệt: {{ \Carbon\Carbon::parse($reconciliation->approved_at)->format('d/m/Y H:i') }}</p>
    </div>

    <p style="margin-top: 30px; padding: 15px; background-color: #fff3cd; border-radius: 4px; color: #856404;">
        <strong>📎 Tệp đính kèm:</strong> Chi tiết bill đối soát được đính kèm trong email này dưới dạng file PDF. Vui lòng tải xuống để lưu giữ hồ sơ.
    </p>

    <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

    <p style="color: #666; font-size: 12px;">
        Nếu bạn có bất kỳ câu hỏi nào, vui lòng liên hệ với bộ phận hỗ trợ của chúng tôi.
    </p>

    <p style="color: #999; font-size: 11px; margin-top: 20px; border-top: 1px solid #eee; padding-top: 10px;">
        Đây là email tự động từ hệ thống Ecoluck. Vui lòng không trả lời email này.
    </p>
</div>
@endsection
