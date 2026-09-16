<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TicketLedgerService
{
    private ?array $columnCache = null;

    public function isEnabled(): bool
    {
        return !empty($this->getColumns());
    }

    public function record(array $data): void
    {
        $columns = $this->getColumns();

        if (empty($columns)) {
            return;
        }

        $payload = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = $data[$column];
            }
        }

        if (in_array('id', $columns, true) && empty($payload['id'])) {
            $payload['id'] = (string) Str::uuid();
        }

        if (in_array('created_at', $columns, true) && empty($payload['created_at'])) {
            $payload['created_at'] = now();
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $payload['metadata'] = json_encode($payload['metadata'], JSON_UNESCAPED_UNICODE);
        }

        if (empty($payload)) {
            return;
        }

        DB::table('ticket_ledger')->insert($payload);
    }

    private function getColumns(): array
    {
        if ($this->columnCache !== null) {
            return $this->columnCache;
        }

        $this->columnCache = Schema::getColumnListing('ticket_ledger');
        return $this->columnCache;
    }
}
