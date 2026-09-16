<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TicketPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketConfigController extends Controller
{
    public function getAvailableTicketTypes(Request $request): JsonResponse
    {
        $ticketTypes = collect(app(TicketPricingService::class)->getTicketTypes())
            ->map(static function (array $type): array {
                return [
                    'code' => strtoupper(trim((string) ($type['code'] ?? ''))),
                    'name' => trim((string) ($type['name'] ?? '')),
                    'price' => round((float) ($type['price'] ?? 0), 2),
                ];
            })
            ->filter(static function (array $type): bool {
                return $type['code'] !== '' && $type['name'] !== '';
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $ticketTypes,
            'meta' => [
                'authenticated' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }
}
