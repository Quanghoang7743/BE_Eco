<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

trait UsesUuidPrimaryKey
{
    use HasUuids;

    public function initializeUsesUuidPrimaryKey(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }

    public function getKeyType()
    {
        return 'string';
    }

    public function getIncrementing()
    {
        return false;
    }
}
