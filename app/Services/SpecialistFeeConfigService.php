<?php

namespace App\Services;

use App\Models\SpecialistFeeConfig;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SpecialistFeeConfigService
{
    public const DEFAULT_FEE_PER_TICKET = 100;
    public const DEFAULT_TICKET_TYPE_CODE = 'TICKET_DEFAULT';

    public function currentFeePerTicket(
        ?int $specialistId = null,
        ?string $storeId = null,
        ?string $ticketTypeCode = null
    ): float
    {
        return $this->feeFor(
            $specialistId,
            $storeId,
            now('Asia/Ho_Chi_Minh'),
            null,
            null,
            $ticketTypeCode
        );
    }

    public function feeFor(
        ?int $specialistId = null,
        ?string $storeId = null,
        CarbonInterface|string|null $effectiveAt = null,
        ?string $storeType = null,
        ?int $regionId = null,
        ?string $ticketTypeCode = null
    ): float {
        if (! Schema::hasTable('specialist_fee_configs')) {
            return self::DEFAULT_FEE_PER_TICKET;
        }

        $effectiveAt = $this->toFeeConfigTimezone($effectiveAt);

        $storeContext = $this->storeContext($storeId);
        $storeType = $storeType ?: ($storeContext['store_type'] ?? null);
        $regionId = $regionId ?: ($storeContext['region_id'] ?? null);
        $ticketTypeCode = $this->normalizeTicketTypeCode($ticketTypeCode);

        $query = DB::table('specialist_fee_configs')
            ->where('effective_from', '<=', $effectiveAt->toDateTimeString())
            ->where(function ($window) use ($effectiveAt): void {
                $window->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveAt->toDateTimeString());
            });

        if (Schema::hasColumn('specialist_fee_configs', 'status')) {
            $query->where('status', SpecialistFeeConfig::STATUS_ACTIVE);
        }

        if (Schema::hasColumn('specialist_fee_configs', 'ticket_type_code')) {
            $query->where(function ($typeScope) use ($ticketTypeCode): void {
                $typeScope->whereRaw('UPPER(ticket_type_code) = ?', [$ticketTypeCode])
                    ->orWhereRaw('UPPER(ticket_type_code) = ?', [self::DEFAULT_TICKET_TYPE_CODE])
                    ->orWhereNull('ticket_type_code')
                    ->orWhere('ticket_type_code', '');
            });

            $query->orderByRaw(
                "CASE
                    WHEN UPPER(COALESCE(ticket_type_code, '')) = ? THEN 30
                    WHEN UPPER(COALESCE(ticket_type_code, '')) = ? THEN 20
                    WHEN COALESCE(ticket_type_code, '') = '' THEN 10
                    ELSE 0
                END DESC",
                [$ticketTypeCode, self::DEFAULT_TICKET_TYPE_CODE]
            );
        }

        if (Schema::hasColumn('specialist_fee_configs', 'apply_scope')) {
            $query->where(function ($scope) use ($specialistId, $storeType, $regionId): void {
                $scope->where('apply_scope', SpecialistFeeConfig::SCOPE_GLOBAL)
                    ->orWhereNull('apply_scope')
                    ->orWhere('apply_scope', '');

                if ($specialistId !== null && Schema::hasColumn('specialist_fee_configs', 'specialist_id')) {
                    $scope->orWhere(function ($specialistScope) use ($specialistId): void {
                        $specialistScope->where('apply_scope', SpecialistFeeConfig::SCOPE_SPECIALIST)
                            ->where('specialist_id', $specialistId);
                    });
                }

                if ($regionId !== null && Schema::hasColumn('specialist_fee_configs', 'region_id')) {
                    $scope->orWhere(function ($regionScope) use ($regionId): void {
                        $regionScope->where('apply_scope', SpecialistFeeConfig::SCOPE_REGION)
                            ->where('region_id', $regionId);
                    });
                }

                if ($storeType !== null && Schema::hasColumn('specialist_fee_configs', 'store_type')) {
                    $scope->orWhere(function ($typeScope) use ($storeType): void {
                        $typeScope->where('apply_scope', SpecialistFeeConfig::SCOPE_STORE_TYPE)
                            ->where('store_type', $storeType);
                    });
                }
            });

            $query->orderByRaw(
                "CASE apply_scope WHEN 'specialist' THEN 40 WHEN 'region' THEN 30 WHEN 'store_type' THEN 20 WHEN 'global' THEN 10 ELSE 0 END DESC"
            );
        }

        $fee = (float) $query
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('fee_per_ticket');

        return $fee > 0 ? $fee : self::DEFAULT_FEE_PER_TICKET;
    }

    public function normalizeTicketTypeCode(?string $ticketTypeCode): string
    {
        $ticketTypeCode = strtoupper(trim((string) $ticketTypeCode));

        return $ticketTypeCode !== '' ? $ticketTypeCode : self::DEFAULT_TICKET_TYPE_CODE;
    }

    private function toFeeConfigTimezone(CarbonInterface|string|null $effectiveAt): Carbon
    {
        if ($effectiveAt instanceof CarbonInterface) {
            return Carbon::parse(
                $effectiveAt->format('Y-m-d H:i:s'),
                $effectiveAt->getTimezone()
            )->setTimezone('Asia/Ho_Chi_Minh');
        }

        if ($effectiveAt !== null && trim((string) $effectiveAt) !== '') {
            return Carbon::parse(
                (string) $effectiveAt,
                (string) config('app.timezone', 'UTC')
            )->setTimezone('Asia/Ho_Chi_Minh');
        }

        return now('Asia/Ho_Chi_Minh');
    }

    public function expirePastActiveConfigs(): int
    {
        if (
            ! Schema::hasTable('specialist_fee_configs')
            || ! Schema::hasColumn('specialist_fee_configs', 'status')
            || ! Schema::hasColumn('specialist_fee_configs', 'effective_to')
        ) {
            return 0;
        }

        return DB::table('specialist_fee_configs')
            ->where('status', SpecialistFeeConfig::STATUS_ACTIVE)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<', now('Asia/Ho_Chi_Minh')->toDateTimeString())
            ->update([
                'status' => SpecialistFeeConfig::STATUS_EXPIRED,
                'updated_at' => now(),
            ]);
    }

    private function storeContext(?string $storeId): array
    {
        if ($storeId === null || $storeId === '' || ! Schema::hasTable('stores')) {
            return [];
        }

        $columns = ['id'];
        if (Schema::hasColumn('stores', 'business_type')) {
            $columns[] = 'business_type';
        }
        if (Schema::hasColumn('stores', 'region_id')) {
            $columns[] = 'region_id';
        }

        $store = DB::table('stores')->where('id', $storeId)->first($columns);
        if (! $store) {
            return [];
        }

        return [
            'store_type' => isset($store->business_type) ? trim((string) $store->business_type) ?: null : null,
            'region_id' => isset($store->region_id) ? (int) $store->region_id : null,
        ];
    }
}
