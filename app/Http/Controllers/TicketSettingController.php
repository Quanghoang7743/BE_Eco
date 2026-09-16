<?php

namespace App\Http\Controllers;

use App\Models\appSetting as AppSetting;
use App\Services\TicketPricingService;
use Illuminate\Http\Request;

class TicketSettingController extends Controller
{
    /**
     * GET /admin/ticket-settings
     */
    public function index()
    {
        $ticketTypes          = app(TicketPricingService::class)->getTicketTypes();
        $defaultTicketName = app(TicketPricingService::class)->getDefaultTicketTypeName();
        $setting              = appSetting::first() ?? new appSetting();

        return view('admin.ticket_setting.edit', compact('ticketTypes', 'defaultTicketName', 'setting'));
    }

    /**
     * PUT /admin/ticket-settings
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'ticket_types'         => 'nullable|array',
            'ticket_types.*.code'  => 'required_with:ticket_types|string|max:50',
            'ticket_types.*.name'  => 'required_with:ticket_types|string|max:120',
            'ticket_types.*.price' => 'required_with:ticket_types|numeric|min:0',
            'ticket_default_type'  => 'nullable|string|max:50',
            'ticket_expiry_days'   => 'nullable|integer|min:1|max:3650',
        ]);

        // Lưu danh sách loại ticket + default type
        app(TicketPricingService::class)->upsertTicketTypes(
            (array) ($validated['ticket_types'] ?? []),
            $validated['ticket_default_type'] ?? null,
            auth()->id()
        );

        // Lưu ticket_expiry_days vào app_settings
        if (!empty($validated['ticket_expiry_days'])) {
            $setting = appSetting::first() ?? new appSetting();
            $setting->ticket_expiry_days = (int) $validated['ticket_expiry_days'];
            $setting->save();
        }

        return redirect()->back()->with('success', 'Cập nhật cấu hình Ticket thành công!');
    }
}
