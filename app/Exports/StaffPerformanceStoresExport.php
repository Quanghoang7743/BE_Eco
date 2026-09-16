<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StaffPerformanceStoresExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    private User $staff;

    private Collection $stores;

    private string $period;

    public function __construct(User $staff, Collection $stores, string $period)
    {
        $this->staff = $staff;
        $this->stores = $stores;
        $this->period = $period;
    }

    public function collection()
    {
        return $this->stores;
    }

    public function headings(): array
    {
        return [
            'Nhan vien SALE',
            'Ky bao cao',
            'Ma CH',
            'Ten cua hang',
            'Trang thai',
            'Tong don thanh cong',
            'Doanh thu (VND)',
            'Tong gia tri ve (VND)',
            'Ty le hoa hong (%)',
            'Hoa hong du tinh theo ve (VND)',
        ];
    }

    public function map($store): array
    {
        $revenue = (float) ($store->delivered_revenue_sum ?? 0);
        $ticketValue = (float) ($store->delivered_ticket_value_sum ?? 0);
        $rate = (float) ($store->sale_commission_rate ?? 0);
        $commission = (float) ($store->estimated_commission ?? ($ticketValue * ($rate / 100)));

        return [
            $this->staff->full_name ?: $this->staff->name,
            $this->period,
            $store->store_code,
            $store->name,
            $store->is_open ? 'Dang hoat dong' : 'Tam dong',
            (int) ($store->delivered_orders_count ?? 0),
            $revenue,
            $ticketValue,
            $rate,
            $commission,
        ];
    }
}
