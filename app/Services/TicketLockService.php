<?php

namespace App\Services;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketLockService
{
    public const DEFAULT_TTL_SECONDS = 300;

    public function acquire(Ticket $ticket, string $owner, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): void
    {
        $now = now();
        $expiresAt = $now->copy()->addSeconds(max(1, $ttlSeconds));

        $this->releaseIfExpired($ticket, $now);

        if (! empty($ticket->locked_by) && $ticket->locked_by !== $owner) {
            throw ValidationException::withMessages([
                'ticket' => 'Vé đang được xử lý bởi yêu cầu khác. Vui lòng thử lại sau ít phút.',
            ]);
        }

        $ticket->locked_at = $now;
        $ticket->locked_by = $owner;
        $ticket->lock_expires_at = $expiresAt;
        $ticket->save();

        DB::table('ticket_locks')->updateOrInsert(
            ['lock_key' => $this->lockKey((string) $ticket->id)],
            [
                'lock_value' => (string) $ticket->id,
                'owner' => $owner,
                'ttl_seconds' => max(1, $ttlSeconds),
                'acquired_at' => $now,
                'expires_at' => $expiresAt,
            ]
        );
    }

    public function release(Ticket $ticket, ?string $owner = null): void
    {
        if ($owner !== null && ! empty($ticket->locked_by) && $ticket->locked_by !== $owner) {
            return;
        }

        $ticket->locked_at = null;
        $ticket->locked_by = null;
        $ticket->lock_expires_at = null;
        $ticket->save();

        $this->deleteLockRow((string) $ticket->id);
    }

    public function deleteLockRow(string $ticketId): void
    {
        DB::table('ticket_locks')
            ->where('lock_key', $this->lockKey($ticketId))
            ->delete();
    }

    public function cleanupExpiredLocks(): array
    {
        $now = now();

        $expiredTicketIds = Ticket::query()
            ->whereNotNull('lock_expires_at')
            ->where('lock_expires_at', '<=', $now)
            ->pluck('id')
            ->all();

        $releasedTickets = 0;

        if (! empty($expiredTicketIds)) {
            $releasedTickets = Ticket::query()
                ->whereIn('id', $expiredTicketIds)
                ->update([
                    'locked_at' => null,
                    'locked_by' => null,
                    'lock_expires_at' => null,
                    'updated_at' => $now,
                ]);
        }

        $deletedRows = DB::table('ticket_locks')
            ->where(function ($query) use ($now) {
                $query->whereNotNull('expires_at')->where('expires_at', '<=', $now);
            })
            ->orWhereIn('lock_value', $expiredTicketIds)
            ->delete();

        return [
            'released_tickets' => (int) $releasedTickets,
            'deleted_lock_rows' => (int) $deletedRows,
        ];
    }

    private function releaseIfExpired(Ticket $ticket, $now): void
    {
        if ($ticket->lock_expires_at && $ticket->lock_expires_at->lte($now)) {
            $ticket->locked_at = null;
            $ticket->locked_by = null;
            $ticket->lock_expires_at = null;
            $ticket->save();
            $this->deleteLockRow((string) $ticket->id);
        }
    }

    private function lockKey(string $ticketId): string
    {
        return 'ticket:' . $ticketId;
    }
}
