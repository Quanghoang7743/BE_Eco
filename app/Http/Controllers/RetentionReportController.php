<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RetentionReportController extends Controller
{
    private const DAY_OFFSETS = [1, 3, 7, 14, 30];

    public function index(Request $request)
    {
        $this->authorizeRole();

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cohort_by' => ['nullable', 'in:day,week'],
        ]);

        $cohortBy = (string) ($validated['cohort_by'] ?? 'day');
        $toDate = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();
        $fromDate = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : $toDate->copy()->subDays(29)->startOfDay();

        $report = $this->buildRetentionReport($fromDate, $toDate, $cohortBy);

        $windowDays = max(1, $fromDate->diffInDays($toDate) + 1);
        $prevTo = $fromDate->copy()->subDay()->endOfDay();
        $prevFrom = $prevTo->copy()->subDays($windowDays - 1)->startOfDay();
        $prevReport = $this->buildRetentionReport($prevFrom, $prevTo, $cohortBy);

        $summary = [
            'avg_day7' => $report['summary']['avg_day7'],
            'avg_day30' => $report['summary']['avg_day30'],
            'growth_day7' => $this->growthPercent($report['summary']['avg_day7'], $prevReport['summary']['avg_day7']),
            'growth_day30' => $this->growthPercent($report['summary']['avg_day30'], $prevReport['summary']['avg_day30']),
        ];

        return view('admin.reports.retention-report', [
            'filters' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
                'cohort_by' => $cohortBy,
            ],
            'summary' => $summary,
            'rows' => $report['rows'],
            'chart' => $report['chart'],
            'dayOffsets' => self::DAY_OFFSETS,
        ]);
    }

    private function buildRetentionReport(Carbon $fromDate, Carbon $toDate, string $cohortBy): array
    {
        $cohorts = $this->loadCohortUsers($fromDate, $toDate, $cohortBy);

        if ($cohorts->isEmpty()) {
            return [
                'rows' => [],
                'summary' => ['avg_day7' => 0.0, 'avg_day30' => 0.0],
                'chart' => ['labels' => [], 'values' => []],
            ];
        }

        $minCohortStart = Carbon::parse((string) $cohorts->keys()->first())->startOfDay();
        $maxCohortStart = Carbon::parse((string) $cohorts->keys()->last())->startOfDay();
        $activityStart = $minCohortStart->copy()->addDay();
        $activityEnd = $maxCohortStart->copy()->addDays(max(self::DAY_OFFSETS))->endOfDay();
        $today = now()->endOfDay();
        if ($activityEnd->greaterThan($today)) {
            $activityEnd = $today;
        }

        $activityByDate = $this->loadActivityByDate($activityStart, $activityEnd);

        $rows = [];
        $summaryNumerators = [7 => 0, 30 => 0];
        $summaryDenominators = [7 => 0, 30 => 0];

        foreach ($cohorts as $cohortDate => $cohortUsers) {
            $cohortStart = Carbon::parse($cohortDate)->startOfDay();
            $cohortSize = count($cohortUsers);

            $retention = [];
            foreach (self::DAY_OFFSETS as $dayOffset) {
                $targetDate = $cohortStart->copy()->addDays($dayOffset)->format('Y-m-d');
                if (Carbon::parse($targetDate)->endOfDay()->greaterThan($today)) {
                    $retention[$dayOffset] = [
                        'count' => null,
                        'rate' => null,
                    ];
                    continue;
                }

                $activeUsers = $activityByDate[$targetDate] ?? [];
                $retained = 0;
                foreach ($cohortUsers as $userId) {
                    if (isset($activeUsers[$userId])) {
                        $retained++;
                    }
                }

                $rate = $cohortSize > 0 ? round(($retained / $cohortSize) * 100, 2) : 0.0;
                $retention[$dayOffset] = [
                    'count' => $retained,
                    'rate' => $rate,
                ];

                if ($dayOffset === 7 || $dayOffset === 30) {
                    $summaryNumerators[$dayOffset] += $retained;
                    $summaryDenominators[$dayOffset] += $cohortSize;
                }
            }

            $rows[] = [
                'cohort_date' => $cohortDate,
                'cohort_label' => $this->formatCohortLabel($cohortDate, $cohortBy),
                'new_users' => $cohortSize,
                'retention' => $retention,
            ];
        }

        $avgDay7 = $summaryDenominators[7] > 0 ? round(($summaryNumerators[7] / $summaryDenominators[7]) * 100, 2) : 0.0;
        $avgDay30 = $summaryDenominators[30] > 0 ? round(($summaryNumerators[30] / $summaryDenominators[30]) * 100, 2) : 0.0;

        return [
            'rows' => $rows,
            'summary' => [
                'avg_day7' => $avgDay7,
                'avg_day30' => $avgDay30,
            ],
            'chart' => $this->buildMonthlyDay7Trend($rows),
        ];
    }

    private function loadCohortUsers(Carbon $fromDate, Carbon $toDate, string $cohortBy): Collection
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'id') || !Schema::hasColumn('users', 'created_at')) {
            return collect();
        }

        $rows = DB::table('users')
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('id, DATE(created_at) as cohort_date')
            ->orderBy('cohort_date')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        if ($cohortBy === 'week') {
            return $rows
                ->groupBy(function ($row) {
                    $date = Carbon::parse((string) $row->cohort_date)->startOfDay();
                    return $date->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
                })
                ->map(function (Collection $group) {
                    return $group->pluck('id')->map(fn ($id) => (string) $id)->unique()->values()->all();
                })
                ->sortKeys();
        }

        return $rows
            ->groupBy('cohort_date')
            ->map(function (Collection $group) {
                return $group->pluck('id')->map(fn ($id) => (string) $id)->unique()->values()->all();
            })
            ->sortKeys();
    }

    private function loadActivityByDate(Carbon $fromDate, Carbon $toDate): array
    {
        $map = [];

        if ($fromDate->greaterThan($toDate)) {
            return $map;
        }

        $this->mergeActivity($map, $this->usersActivityRows($fromDate, $toDate, 'last_active_at'));
        $this->mergeActivity($map, $this->usersActivityRows($fromDate, $toDate, 'last_login_at'));
        $this->mergeActivity($map, $this->tableActivityRows('orders', 'created_at', $fromDate, $toDate));
        $this->mergeActivity($map, $this->tableActivityRows('room_tickets', Schema::hasColumn('room_tickets', 'joined_at') ? 'joined_at' : 'created_at', $fromDate, $toDate));
        $this->mergeActivity($map, $this->tableActivityRows('ticket_ledger', 'created_at', $fromDate, $toDate));

        return $map;
    }

    private function usersActivityRows(Carbon $fromDate, Carbon $toDate, string $column): Collection
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'id') || !Schema::hasColumn('users', $column)) {
            return collect();
        }

        return DB::table('users')
            ->whereNotNull($column)
            ->whereBetween($column, [$fromDate, $toDate])
            ->selectRaw('id as user_id, DATE(' . $column . ') as activity_date')
            ->distinct()
            ->get();
    }

    private function tableActivityRows(string $table, string $dateColumn, Carbon $fromDate, Carbon $toDate): Collection
    {
        if (
            !Schema::hasTable($table)
            || !Schema::hasColumn($table, 'user_id')
            || !Schema::hasColumn($table, $dateColumn)
        ) {
            return collect();
        }

        return DB::table($table)
            ->whereNotNull('user_id')
            ->whereBetween($dateColumn, [$fromDate, $toDate])
            ->selectRaw('user_id, DATE(' . $dateColumn . ') as activity_date')
            ->distinct()
            ->get();
    }

    private function mergeActivity(array &$target, Collection $rows): void
    {
        foreach ($rows as $row) {
            $date = (string) ($row->activity_date ?? '');
            $userId = (string) ($row->user_id ?? '');
            if ($date === '' || $userId === '') {
                continue;
            }

            if (!isset($target[$date])) {
                $target[$date] = [];
            }

            $target[$date][$userId] = true;
        }
    }

    private function buildMonthlyDay7Trend(array $rows): array
    {
        $bucket = [];

        foreach ($rows as $row) {
            $monthKey = Carbon::parse((string) $row['cohort_date'])->format('Y-m');
            $day7 = $row['retention'][7] ?? null;
            if (!$day7 || $day7['rate'] === null) {
                continue;
            }

            if (!isset($bucket[$monthKey])) {
                $bucket[$monthKey] = ['retained' => 0, 'users' => 0];
            }

            $bucket[$monthKey]['retained'] += (int) ($day7['count'] ?? 0);
            $bucket[$monthKey]['users'] += (int) ($row['new_users'] ?? 0);
        }

        ksort($bucket);

        $labels = [];
        $values = [];

        foreach ($bucket as $month => $row) {
            $labels[] = Carbon::createFromFormat('Y-m', $month)->format('m/Y');
            $values[] = $row['users'] > 0 ? round(($row['retained'] / $row['users']) * 100, 2) : 0.0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function growthPercent(float $current, float $previous): float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function formatCohortLabel(string $cohortDate, string $cohortBy): string
    {
        $date = Carbon::parse($cohortDate);

        if ($cohortBy === 'week') {
            $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY)->format('d/m/Y');
            return 'Tuan bat dau ' . $weekStart;
        }

        return $date->format('d/m/Y');
    }

    private function authorizeRole(): void
    {
        $role = (string) optional(auth()->user())->role;
        abort_unless(in_array($role, ['super_admin', 'admin'], true), 403, 'Ban khong co quyen xem bao cao nay.');
    }
}
