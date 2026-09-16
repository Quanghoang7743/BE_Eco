@extends('layouts.email')

@section('content')
<div style="font-family: Arial, sans-serif; line-height: 1.6; color: #26352f;">
    <h2 style="color: #006c4e; margin: 0 0 16px;">Đặt lại mật khẩu Ecoluck</h2>

    <p>Chúng tôi đã nhận được yêu cầu đặt lại mật khẩu cho tài khoản <strong>{{ $identity }}</strong>.</p>

    <p>Mã xác nhận của bạn là:</p>

    <div style="margin: 22px 0; padding: 18px 20px; border-radius: 8px; background: #f0fbf6; border: 1px solid #bdebd7; text-align: center;">
        <div style="font-size: 34px; letter-spacing: 8px; font-weight: 800; color: #006c4e;">
            {{ $otp }}
        </div>
    </div>

    <p>Mã này có hiệu lực trong <strong>{{ $expiresInMinutes }} phút</strong>. Nếu bạn không yêu cầu đặt lại mật khẩu, vui lòng bỏ qua email này.</p>

    <p style="margin-top: 24px; color: #69756f; font-size: 13px;">
        Vì lý do bảo mật, không chia sẻ mã OTP này với bất kỳ ai.
    </p>
</div>
@endsection
