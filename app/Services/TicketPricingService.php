<?php

namespace App\Services;

use App\Models\SystemConfig;
use Illuminate\Support\Facades\Schema;

class TicketPricingService
{
    private const DEFAULT_TYPE = 'fixed';
    private const DEFAULT_VALUE = 1000.0;
    private const DEFAULT_TICKET_CODE = 'TICKET_DEFAULT';
    private const DEFAULT_TICKET_NAME = 'Ve mac dinh';
    private ?array $cachedConfig = null;
    private ?array $cachedTicketTypes = null;

    public function getConfig(): array
    {
        if ($this->cachedConfig !== null) {
            return $this->cachedConfig;
        }

        if (!Schema::hasTable('system_configs')) {
            $this->cachedConfig = [
                'calc_type' => self::DEFAULT_TYPE,
                'ticket_value' => self::DEFAULT_VALUE,
            ];

            return $this->cachedConfig;
        }

        $rows = SystemConfig::query()
            ->whereIn('config_key', [
                SystemConfig::KEY_TICKET_CALC_TYPE,
                SystemConfig::KEY_TICKET_VALUE,
            ])
            ->pluck('config_value', 'config_key');

        $calcType = (string) ($rows[SystemConfig::KEY_TICKET_CALC_TYPE] ?? self::DEFAULT_TYPE);
        if (!in_array($calcType, ['fixed', 'percentage'], true)) {
            $calcType = self::DEFAULT_TYPE;
        }

        $ticketValue = (float) ($rows[SystemConfig::KEY_TICKET_VALUE] ?? self::DEFAULT_VALUE);
        if ($ticketValue < 0) {
            $ticketValue = 0.0;
        }

        $this->cachedConfig = [
            'calc_type' => $calcType,
            'ticket_value' => $ticketValue,
        ];

        return $this->cachedConfig;
    }

    public function getTicketTypes(): array
    {
        if ($this->cachedTicketTypes !== null) {
            return $this->cachedTicketTypes;
        }

        if (!Schema::hasTable('system_configs')) {
            $this->cachedTicketTypes = [[
                'code' => self::DEFAULT_TICKET_CODE,
                'name' => self::DEFAULT_TICKET_NAME,
                'price' => (float) config('ecoluck.ticket_unit_price', 25000),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]];

            return $this->cachedTicketTypes;
        }

        $rows = SystemConfig::query()
            ->whereIn('config_key', [
                SystemConfig::KEY_TICKET_TYPES,
                SystemConfig::KEY_TICKET_DEFAULT_TYPE,
            ])
            ->pluck('config_value', 'config_key');

        $rawTypes = json_decode((string) ($rows[SystemConfig::KEY_TICKET_TYPES] ?? '[]'), true);
        if (!is_array($rawTypes)) {
            $rawTypes = [];
        }

        $defaultCode = trim((string) ($rows[SystemConfig::KEY_TICKET_DEFAULT_TYPE] ?? ''));
        $types = [];
        $sortOrder = 0;

        foreach ($rawTypes as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            $price = (float) ($row['price'] ?? 0);
            $isActive = !array_key_exists('is_active', $row) || (bool) $row['is_active'];

            if ($code === '' || $name === '' || $price < 0 || !$isActive) {
                continue;
            }

            $types[$code] = [
                'code' => $code,
                'name' => $name,
                'price' => round($price, 2),
                'is_default' => false,
                'is_active' => true,
                'sort_order' => $sortOrder++,
            ];
        }

        if (empty($types)) {
            $fallbackCode = self::DEFAULT_TICKET_CODE;
            $types[$fallbackCode] = [
                'code' => $fallbackCode,
                'name' => self::DEFAULT_TICKET_NAME,
                'price' => (float) config('ecoluck.ticket_unit_price', 25000),
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 0,
            ];
        }

        $resolvedDefaultCode = $defaultCode !== '' && isset($types[$defaultCode])
            ? $defaultCode
            : array_key_first($types);

        foreach ($types as $code => $type) {
            $types[$code]['is_default'] = ($code === $resolvedDefaultCode);
        }

        $this->cachedTicketTypes = array_values($types);

        return $this->cachedTicketTypes;
    }

    public function getDefaultTicketTypeCode(): string
    {
        $types = $this->getTicketTypes();
        foreach ($types as $type) {
            if ((bool) ($type['is_default'] ?? false)) {
                return (string) $type['code'];
            }
        }

        return (string) ($types[0]['code'] ?? self::DEFAULT_TICKET_CODE);
    }

    public function getDefaultTicketTypeName(): string
    {
        $types = $this->getTicketTypes();
        foreach ($types as $type) {
            if ((bool) ($type['is_default'] ?? false)) {
                return (string) $type['name'];
            }
        }

        return (string) ($types[0]['name'] ?? self::DEFAULT_TICKET_NAME);
    }

    public function resolveTicketType(?string $code): ?array
    {
        $normalizedCode = strtoupper(trim((string) $code));
        $types = $this->getTicketTypes();

        if ($normalizedCode !== '') {
            foreach ($types as $type) {
                if ((string) ($type['code'] ?? '') === $normalizedCode) {
                    return $type;
                }
            }
        }

        foreach ($types as $type) {
            if ((bool) ($type['is_default'] ?? false)) {
                return $type;
            }
        }

        return $types[0] ?? null;
    }

    public function upsertTicketTypes(array $ticketTypes, ?string $defaultTypeCode = null, ?int $updatedBy = null): array
    {
        $this->clearCache();

        if (!Schema::hasTable('system_configs')) {
            return $this->getTicketTypes();
        }

        $normalized = [];
        $seenCodes = [];

        foreach ($ticketTypes as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            $name = trim((string) ($item['name'] ?? ''));
            $price = (float) ($item['price'] ?? 0);
            $isActive = !array_key_exists('is_active', $item) || (bool) $item['is_active'];

            if ($code === '' || $name === '' || $price < 0 || !$isActive || isset($seenCodes[$code])) {
                continue;
            }

            $seenCodes[$code] = true;
            $normalized[] = [
                'code' => $code,
                'name' => $name,
                'price' => round($price, 2),
                'is_active' => true,
            ];
        }

        if (empty($normalized)) {
            $normalized[] = [
                'code' => self::DEFAULT_TICKET_CODE,
                'name' => self::DEFAULT_TICKET_NAME,
                'price' => (float) config('ecoluck.ticket_unit_price', 25000),
                'is_active' => true,
            ];
        }

        $resolvedDefault = strtoupper(trim((string) $defaultTypeCode));
        $availableCodes = array_map(static fn (array $row): string => (string) $row['code'], $normalized);
        if ($resolvedDefault === '' || !in_array($resolvedDefault, $availableCodes, true)) {
            $resolvedDefault = (string) ($availableCodes[0] ?? self::DEFAULT_TICKET_CODE);
        }

        SystemConfig::query()->updateOrCreate(
            ['config_key' => SystemConfig::KEY_TICKET_TYPES],
            [
                'config_value' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
                'value_type' => 'json',
                'description' => 'List of ticket types configured by system admin',
                'updated_by' => $updatedBy,
            ]
        );

        SystemConfig::query()->updateOrCreate(
            ['config_key' => SystemConfig::KEY_TICKET_DEFAULT_TYPE],
            [
                'config_value' => $resolvedDefault,
                'value_type' => 'string',
                'description' => 'Default ticket type code',
                'updated_by' => $updatedBy,
            ]
        );

        return $this->getTicketTypes();
    }

    public function upsertConfig(string $calcType, float $ticketValue, ?int $updatedBy = null): void
    {
        $this->clearCache();

        if (!Schema::hasTable('system_configs')) {
            return;
        }

        $calcType = in_array($calcType, ['fixed', 'percentage'], true) ? $calcType : self::DEFAULT_TYPE;
        $ticketValue = max(0, $ticketValue);

        SystemConfig::query()->updateOrCreate(
            ['config_key' => SystemConfig::KEY_TICKET_CALC_TYPE],
            [
                'config_value' => $calcType,
                'value_type' => 'string',
                'description' => 'Ticket pricing calculation type: fixed|percentage',
                'updated_by' => $updatedBy,
            ]
        );

        SystemConfig::query()->updateOrCreate(
            ['config_key' => SystemConfig::KEY_TICKET_VALUE],
            [
                'config_value' => (string) $ticketValue,
                'value_type' => 'number',
                'description' => 'Ticket pricing value in VND (fixed) or percent (percentage)',
                'updated_by' => $updatedBy,
            ]
        );
    }

    private function clearCache(): void
    {
        $this->cachedConfig = null;
        $this->cachedTicketTypes = null;
    }

    public function calculatePerTicketValue(float $productPrice): float
    {
        $config = $this->getConfig();
        $calcType = $config['calc_type'];
        $ticketValue = (float) $config['ticket_value'];

        if ($calcType === 'percentage') {
            return round(max(0, $productPrice) * ($ticketValue / 100));
        }

        return round(max(0, $ticketValue));
    }

    public function calculatePreview(float $productPrice, int $ticketQuantity, ?string $ticketTypeCode = null): array
    {
        $ticketQuantity = max(0, $ticketQuantity);
        $resolvedType = $this->resolveTicketType($ticketTypeCode);
        $resolvedCode = $resolvedType['code'] ?? null;
        $resolvedName = $resolvedType['name'] ?? null;
        $perTicket = $resolvedType !== null
            ? round((float) ($resolvedType['price'] ?? 0), 2)
            : $this->calculatePerTicketValue($productPrice);
        $total = round($perTicket * $ticketQuantity);
        $config = $this->getConfig();

        return [
            'calc_type' => $config['calc_type'],
            'ticket_value' => (float) $config['ticket_value'],
            'ticket_type_code' => $resolvedCode,
            'ticket_type_name' => $resolvedName,
            'per_ticket_value_vnd' => $perTicket,
            'ticket_quantity' => $ticketQuantity,
            'total_recovery_vnd' => $total,
        ];
    }
}
