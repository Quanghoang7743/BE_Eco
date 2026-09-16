<?php

namespace App\Exports;

use App\Models\Order;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrdersExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $request;

    public function __construct($request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $query = Order::with(['items', 'user', 'store'])
            ->orderBy('created_at', 'desc');

        AdminCmsAccess::applyOrderScope($query);

        if ($this->request->filled('ids')) {
            $ids = $this->request->ids;
            if (is_string($ids)) {
                $ids = explode(',', $ids);
            }
            $query->whereIn('orders.id', array_filter($ids));
            return $query->get();
        }

        if ($this->request->filled('search')) {
            $keyword = $this->request->search;
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('id', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%");

                if (Schema::hasColumn('orders', 'order_code')) {
                    $q->orWhere('order_code', 'like', "%{$keyword}%");
                }
            });
        }

        $status = (string) $this->request->input('status', AdminCmsAccess::isSale() ? 'successful' : 'all');
        if (AdminCmsAccess::isSale() && ! in_array($status, ['successful', 'completed', 'delivered', 'call_completed'], true)) {
            $status = 'successful';
        }

        if ($status !== '' && $status !== 'all') {
            if ($status === 'successful') {
                $query->whereIn(DB::raw("LOWER(COALESCE(orders.status, ''))"), ['completed', 'delivered', 'call_completed']);
            } else {
                $query->whereRaw("LOWER(COALESCE(orders.status, '')) = ?", [strtolower($status)]);
            }
        }

        if ($this->request->filled('store_id') && Schema::hasColumn('orders', 'store_id')) {
            $query->where('orders.store_id', (string) $this->request->store_id);
        }

        if ($this->request->filled('from_date') || $this->request->filled('to_date')) {
            $from = $this->request->from_date
                ? Carbon::parse($this->request->from_date)->startOfDay()
                : Carbon::minValue();

            $to = $this->request->to_date
                ? Carbon::parse($this->request->to_date)->endOfDay()
                : Carbon::now()->endOfDay();

            $dateExpression = Schema::hasColumn('orders', 'delivered_at')
                ? 'COALESCE(orders.delivered_at, orders.created_at)'
                : 'orders.created_at';

            $query->whereRaw($dateExpression . ' BETWEEN ? AND ?', [
                $from->toDateTimeString(),
                $to->toDateTimeString(),
            ]);
        }

        return $query->get();
    }


    public function map($order): array
    {
        $paymentStatusMap = [
            'pending' => 'Chờ thanh toán',
            'paid' => 'Đã thanh toán',
            'failed' => 'Thất bại',
            'refunded' => 'Hoàn tiền',
        ];

        $orderStatusMap = [
            'pending' => 'Khởi tạo',
            'approved' => 'Duyệt',
            'packed' => 'Đóng gói',
            'shipped' => 'Xuất kho',
            'completed' => 'Hoàn thành',
            'returned' => 'Hoàn hàng',
            'cancelled' => 'Hủy đơn',
        ];

        $items = $order->items->map(function ($item) {
            return "{$item->product_name} x{$item->quantity}";
        })->implode("\n");

        return [
            $order->order_code ?: $order->id,
            optional($order->store)->name ?? 'N/A',
            $order->name,
            $order->phone,
            $order->delivery_address ?? $order->address,
            $order->payment_method,
            number_format($order->total, 0, ',', '.') . ' đ',
            $orderStatusMap[$order->status] ?? $order->status,
            $paymentStatusMap[$order->status_payment] ?? $order->status_payment,
            $items,
            optional($order->user)->name ?? 'Không rõ',
            $order->created_at ? Carbon::parse($order->created_at->toDateTimeString(), 'UTC')->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '',
        ];
    }

    public function headings(): array
    {
        return [
            'Mã đơn',
            'Cửa hàng',
            'Tên khách hàng',
            'Số điện thoại',
            'Địa chỉ',
            'Phương thức thanh toán',
            'Tổng tiền',
            'Trạng thái đơn hàng',
            'Trạng thái thanh toán',
            'Sản phẩm',
            'Người dùng',
            'Ngày tạo',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Bôi đậm dòng đầu tiên (headings)
        return [
            1 => ['font' => ['bold' => true]],
            'J2:J1000' => [
                'alignment' => [
                    'wrapText' => true,
                ],
            ],
        ];
    }
}
