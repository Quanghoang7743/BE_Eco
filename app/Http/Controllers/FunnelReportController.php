<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FunnelReportController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeRole();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'export' => ['nullable', 'in:csv'],
        ]);

        $fromDate = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $toDate = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();

        $orders = $this->ordersMetrics($fromDate, $toDate);
        $tickets = $this->ticketsMetrics($fromDate, $toDate);
        $roomTickets = $this->roomTicketsMetrics($fromDate, $toDate);
        $winners = $this->winnersMetrics($fromDate, $toDate);

        $summary = [
            'orders_success' => $orders['count'],
            'orders_users' => $orders['users'],
            'tickets_issued' => $tickets['count'],
            'room_joined' => $roomTickets['count'],
            'winners' => $winners['count'],
        ];

        $summary['cr1'] = $this->percent($summary['tickets_issued'], $summary['orders_success']);
        $summary['cr2'] = $this->percent($summary['room_joined'], $summary['tickets_issued']);
        $summary['cr3'] = $this->percent($summary['winners'], $summary['room_joined']);

        $dailyRows = $this->buildDailyRows(
            $fromDate,
            $toDate,
            $orders['daily'],
            $tickets['daily'],
            $roomTickets['daily'],
            $winners['daily']
        );

        if (($validated['export'] ?? null) === 'csv') {
            return $this->exportCsv($fromDate, $toDate, $summary, $dailyRows);
        }

        return view('admin.reports.funnel-report', [
            'filters' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'summary' => $summary,
            'dailyRows' => $dailyRows,
            'chart' => [
                'labels' => ['Đơn thành công', 'Vé phát hành', 'Vé vào phòng', 'Trúng thưởng'],
                'values' => [
                    $summary['orders_success'],
                    $summary['tickets_issued'],
                    $summary['room_joined'],
                    $summary['winners'],
                ],
            ],
        ]);
    }

    private function ordersMetrics(Carbon $fromDate, Carbon $toDate): array
    {
        if (!Schema::hasTable('orders')) {
            return ['count' => 0, 'users' => 0, 'daily' => collect()];
        }

        $query = DB::table('orders')
            ->whereRaw('LOWER(status) = ?', ['delivered'])
            ->whereBetween('created_at', [$fromDate, $toDate]);

        $count = (int) (clone $query)->count();

        $users = 0;
        if (Schema::hasColumn('orders', 'user_id')) {
            $users = (int) (clone $query)->whereNotNull('user_id')->distinct('user_id')->count('user_id');
        }

        $daily = (clone $query)
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as total')
            ->groupBy('report_date')
            ->pluck('total', 'report_date');

        return ['count' => $count, 'users' => $users, 'daily' => $daily];
    }

    private function ticketsMetrics(Carbon $fromDate, Carbon $toDate): array
    {
        if (!Schema::hasTable('tickets')) {
            return ['count' => 0, 'daily' => collect()];
        }

        $dateColumn = Schema::hasColumn('tickets', 'created_at') ? 'created_at' : 'purchased_at';

        $query = DB::table('tickets')
            ->whereRaw("LOWER(COALESCE(source_type, '')) = ?", ['order'])
            ->whereBetween($dateColumn, [$fromDate, $toDate]);

        $count = (int) (clone $query)->count();

        $daily = (clone $query)
            ->selectRaw('DATE(' . $dateColumn . ') as report_date, COUNT(*) as total')
            ->groupBy('report_date')
            ->pluck('total', 'report_date');

        return ['count' => $count, 'daily' => $daily];
    }

    private function roomTicketsMetrics(Carbon $fromDate, Carbon $toDate): array
    {
        if (!Schema::hasTable('room_tickets')) {
            return ['count' => 0, 'daily' => collect()];
        }

        $dateColumn = Schema::hasColumn('room_tickets', 'joined_at') ? 'joined_at' : 'created_at';

        $query = DB::table('room_tickets')
            ->whereNotNull('room_id')
            ->where('is_refunded', false)
            ->whereBetween($dateColumn, [$fromDate, $toDate]);

        $count = (int) (clone $query)->count();

        $daily = (clone $query)
            ->selectRaw('DATE(' . $dateColumn . ') as report_date, COUNT(*) as total')
            ->groupBy('report_date')
            ->pluck('total', 'report_date');

        return ['count' => $count, 'daily' => $daily];
    }

    private function winnersMetrics(Carbon $fromDate, Carbon $toDate): array
    {
        if (Schema::hasTable('draws')) {
            $dateColumn = Schema::hasColumn('draws', 'completed_at') ? 'completed_at' : 'created_at';

            $query = DB::table('draws')
                ->whereRaw('LOWER(status) = ?', ['completed'])
                ->whereBetween($dateColumn, [$fromDate, $toDate]);

            $count = (int) (clone $query)->count();

            $daily = (clone $query)
                ->selectRaw('DATE(' . $dateColumn . ') as report_date, COUNT(*) as total')
                ->groupBy('report_date')
                ->pluck('total', 'report_date');

            return ['count' => $count, 'daily' => $daily];
        }

        if (!Schema::hasTable('rooms')) {
            return ['count' => 0, 'daily' => collect()];
        }

        $dateColumn = Schema::hasColumn('rooms', 'draw_completed_at') ? 'draw_completed_at' : 'created_at';

        $query = DB::table('rooms')
            ->whereRaw('LOWER(status) = ?', ['completed'])
            ->whereNotNull('winner_id')
            ->whereBetween($dateColumn, [$fromDate, $toDate]);

        $count = (int) (clone $query)->count();

        $daily = (clone $query)
            ->selectRaw('DATE(' . $dateColumn . ') as report_date, COUNT(*) as total')
            ->groupBy('report_date')
            ->pluck('total', 'report_date');

        return ['count' => $count, 'daily' => $daily];
    }

    private function buildDailyRows(
        Carbon $fromDate,
        Carbon $toDate,
        Collection $ordersDaily,
        Collection $ticketsDaily,
        Collection $roomDaily,
        Collection $winnerDaily
    ): array {
        $rows = [];
        $period = CarbonPeriod::create($fromDate->copy()->startOfDay(), $toDate->copy()->startOfDay());

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');
            $orders = (int) ($ordersDaily[$key] ?? 0);
            $tickets = (int) ($ticketsDaily[$key] ?? 0);
            $room = (int) ($roomDaily[$key] ?? 0);
            $winners = (int) ($winnerDaily[$key] ?? 0);

            $rows[] = [
                'date' => $key,
                'orders_success' => $orders,
                'tickets_issued' => $tickets,
                'room_joined' => $room,
                'winners' => $winners,
                'cr1' => $this->percent($tickets, $orders),
                'cr2' => $this->percent($room, $tickets),
                'cr3' => $this->percent($winners, $room),
            ];
        }

        return $rows;
    }

    private function percent(int $numerator, int $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return round(($numerator / $denominator) * 100, 2);
    }

    private function exportCsv(Carbon $fromDate, Carbon $toDate, array $summary, array $dailyRows): StreamedResponse
    {
        $filename = 'funnel-report-' . $fromDate->format('Ymd') . '-' . $toDate->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($summary, $dailyRows): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['S41 Funnel Report']);
            fputcsv($out, ['Orders Success', $summary['orders_success']]);
            fputcsv($out, ['Tickets Issued', $summary['tickets_issued']]);
            fputcsv($out, ['Room Joined', $summary['room_joined']]);
            fputcsv($out, ['Winners', $summary['winners']]);
            fputcsv($out, ['CR1 (%)', $summary['cr1']]);
            fputcsv($out, ['CR2 (%)', $summary['cr2']]);
            fputcsv($out, ['CR3 (%)', $summary['cr3']]);
            fputcsv($out, []);

            fputcsv($out, ['Date', 'Orders Success', 'Tickets Issued', 'Room Joined', 'Winners', 'CR1 (%)', 'CR2 (%)', 'CR3 (%)']);
            foreach ($dailyRows as $row) {
                fputcsv($out, [
                    $row['date'],
                    $row['orders_success'],
                    $row['tickets_issued'],
                    $row['room_joined'],
                    $row['winners'],
                    $row['cr1'],
                    $row['cr2'],
                    $row['cr3'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function authorizeRole(): void
    {
        $role = (string) optional(auth()->user())->role;
        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403, 'Bạn không có quyền xem báo cáo này.');
    }
}
