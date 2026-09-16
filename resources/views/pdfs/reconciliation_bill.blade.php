<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 24px;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            color: #1f2937;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background-color: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #006c4e;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            color: #006c4e;
            margin: 0;
            font-size: 28px;
        }
        .header .subtitle {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        .store-info {
            background-color: #f7faf8;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
            border-left: 4px solid #006c4e;
        }
        .store-info h3 {
            color: #006c4e;
            margin: 0 0 10px 0;
            font-size: 14px;
        }
        .store-info .info-row {
            display: flex;
            margin: 8px 0;
        }
        .store-info .label {
            font-weight: bold;
            width: 150px;
            color: #333;
        }
        .store-info .value {
            color: #666;
        }
        .section-title {
            color: #006c4e;
            font-size: 16px;
            font-weight: bold;
            margin-top: 30px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        table th {
            background-color: #f7faf8;
            color: #006c4e;
            font-weight: bold;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #006c4e;
        }
        table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        table tr:nth-child(even) {
            background-color: #fbfdfc;
        }
        .text-right {
            text-align: right;
        }
        .amount {
            font-weight: bold;
            color: #006c4e;
        }
        .summary-box {
            background-color: #e8f5e9;
            border-left: 4px solid #2ebd8e;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 12px 0;
            font-size: 16px;
        }
        .summary-row .label {
            font-weight: 600;
            color: #333;
        }
        .summary-row .value {
            font-weight: bold;
            color: #2ebd8e;
        }
        .summary-row.total {
            border-top: 2px solid #2ebd8e;
            padding-top: 12px;
            margin-top: 15px;
            font-size: 18px;
        }
        .summary-row.total .value {
            color: #006c4e;
            font-size: 20px;
        }
        .footer {
            text-align: center;
            color: #999;
            font-size: 12px;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .status-badge {
            display: inline-block;
            background-color: #2ebd8e;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📋 BILL ĐỐI SOÁT GIAO DỊCH</h1>
            <div class="subtitle">Ecoluck Reconciliation System</div>
        </div>

        <!-- Store Information -->
        <div class="store-info">
            <h3>Thông tin cửa hàng</h3>
            <div class="info-row">
                <span class="label">Tên cửa hàng:</span>
                <span class="value">{{ $store->name }}</span>
            </div>
            <div class="info-row">
                <span class="label">Mã cửa hàng:</span>
                <span class="value">{{ $store->store_code }}</span>
            </div>
            <div class="info-row">
                <span class="label">Địa chỉ:</span>
                <span class="value">{{ $store->address ?? 'N/A' }}</span>
            </div>
            <div class="info-row">
                <span class="label">Email:</span>
                <span class="value">{{ $store->email ?? 'N/A' }}</span>
            </div>
            <div class="info-row">
                <span class="label">Điện thoại:</span>
                <span class="value">{{ $store->phone ?? 'N/A' }}</span>
            </div>
        </div>

        <!-- Reconciliation Details -->
        <div class="section-title">Chi tiết đối soát</div>
        <table>
            <thead>
                <tr>
                    <th>Mục</th>
                    <th class="text-right">Giá trị</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Ngày đối soát</td>
                    <td class="text-right">{{ \Carbon\Carbon::parse($reconciliation->reconciliation_date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Doanh thu từ chuyển khoản</td>
                    <td class="text-right amount">{{ number_format($financialRecon->revenue_transfer, 0, ',', '.') }} VND</td>
                </tr>
                <tr>
                    <td>Doanh thu từ tiền mặt</td>
                    <td class="text-right amount">{{ number_format($financialRecon->revenue_cash, 0, ',', '.') }} VND</td>
                </tr>
                <tr>
                    <td>Doanh thu từ COD</td>
                    <td class="text-right amount">{{ number_format($financialRecon->revenue_cod, 0, ',', '.') }} VND</td>
                </tr>
                <tr>
                    <td><strong>Tổng doanh thu</strong></td>
                    <td class="text-right amount" style="background-color: #f7faf8; border-top: 2px solid #006c4e; border-bottom: 2px solid #006c4e;">
                        {{ number_format($financialRecon->revenue_total, 0, ',', '.') }} VND
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Deductions -->
        <div class="section-title">Khoảng trừ</div>
        <table>
            <thead>
                <tr>
                    <th>Loại</th>
                    <th class="text-right">Số tiền</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Hoa hồng Affiliate</td>
                    <td class="text-right">- {{ number_format($financialRecon->affiliate_commission, 0, ',', '.') }} VND</td>
                </tr>
                <tr>
                    <td>Thưởng vé số</td>
                    <td class="text-right">- {{ number_format($financialRecon->ticket_reward, 0, ',', '.') }} VND</td>
                </tr>
            </tbody>
        </table>

        <!-- Summary -->
        <div class="summary-box">
            <div class="summary-row">
                <span class="label">Tổng doanh thu:</span>
                <span class="value">{{ number_format($financialRecon->revenue_total, 0, ',', '.') }} VND</span>
            </div>
            <div class="summary-row">
                <span class="label">Tổng trừ:</span>
                <span class="value">{{ number_format($financialRecon->affiliate_commission + $financialRecon->ticket_reward, 0, ',', '.') }} VND</span>
            </div>
            <div class="summary-row total">
                <span class="label">Tổng thực nhận:</span>
                <span class="value">{{ number_format($financialRecon->net_amount, 0, ',', '.') }} VND</span>
            </div>
        </div>

        <!-- Approval Status -->
        <div style="text-align: center; margin: 30px 0;">
            <span class="status-badge">✅ ĐÃ DUYỆT PHÁT HÀNH</span>
            <div style="margin-top: 15px; color: #666; font-size: 13px;">
                Ngày duyệt: {{ \Carbon\Carbon::parse($reconciliation->approved_at)->format('d/m/Y H:i') }}
            </div>
        </div>

        <!-- Notes -->
        @if($reconciliation->notes)
        <div class="section-title">Ghi chú</div>
        <p style="background-color: #f7faf8; padding: 15px; border-radius: 6px; border-left: 4px solid #006c4e;">
            {{ $reconciliation->notes }}
        </p>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Đây là bill đối soát do hệ thống Ecoluck tạo ra tự động.</p>
            <p>Vui lòng lưu giữ bill này để phục vụ cho các mục đích kế toán và kiểm toán.</p>
            <p style="margin-top: 20px; font-size: 11px; color: #ccc;">
                Generated at {{ now()->format('d/m/Y H:i:s') }}
            </p>
        </div>
    </div>
</body>
</html>
