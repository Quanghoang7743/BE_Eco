<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;

class WinnerController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'winner_keyword' => ['nullable', 'string', 'max:255'],
            'room_code' => ['nullable', 'string', 'max:50'],
            'ticket_keyword' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Room::query()
            ->from('rooms')
            ->leftJoin('users', 'users.id', '=', 'rooms.winner_id')
            ->leftJoin('tickets', 'tickets.id', '=', 'rooms.winner_ticket_id')
            ->where('rooms.status', 'COMPLETED')
            ->whereNotNull('rooms.winner_id')
            ->select([
                'rooms.id',
                'rooms.room_code',
                'rooms.room_number',
                'rooms.room_date',
                'rooms.draw_completed_at',
                'rooms.prize_type',
                'rooms.prize_value',
                'rooms.prize_description',
                'rooms.winner_id',
                'rooms.winner_name',
                'rooms.winner_ticket_id',
                'rooms.tx_hash',
                'rooms.block_hash',
                'users.phone as winner_phone',
                'users.email as winner_email',
                'users.avatar as winner_avatar',
                'tickets.ticket_code as winner_ticket_code',
            ])
            ->selectRaw("COALESCE(NULLIF(users.full_name, ''), NULLIF(users.name, ''), rooms.winner_name) as winner_display_name");

        if (!empty($validated['date_from'])) {
            $query->whereDate('rooms.room_date', '>=', $validated['date_from']);
        }

        if (!empty($validated['date_to'])) {
            $query->whereDate('rooms.room_date', '<=', $validated['date_to']);
        }

        if (!empty($validated['winner_keyword'])) {
            $keyword = trim((string) $validated['winner_keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('users.full_name', 'like', '%' . $keyword . '%')
                    ->orWhere('users.name', 'like', '%' . $keyword . '%')
                    ->orWhere('users.phone', 'like', '%' . $keyword . '%')
                    ->orWhere('rooms.winner_name', 'like', '%' . $keyword . '%');
            });
        }

        if (!empty($validated['room_code'])) {
            $query->where('rooms.room_code', 'like', '%' . trim((string) $validated['room_code']) . '%');
        }

        if (!empty($validated['ticket_keyword'])) {
            $keyword = trim((string) $validated['ticket_keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('tickets.ticket_code', 'like', '%' . $keyword . '%')
                    ->orWhere('rooms.winner_ticket_id', 'like', '%' . $keyword . '%');
            });
        }

        $winners = $query
            ->orderByDesc('rooms.draw_completed_at')
            ->orderByDesc('rooms.room_date')
            ->orderByDesc('rooms.id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.winners.index', compact('winners'));
    }
}
