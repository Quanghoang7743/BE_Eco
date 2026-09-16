<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StoreAdminReportArrayExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    private array $headings;

    private array $rows;

    private string $title;

    public function __construct(array $headings, array $rows, ?string $title = null)
    {
        $this->headings = $headings;
        $this->rows = $rows;
        $this->title = $this->sanitizeTitle($title ?: 'Báo cáo');
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }

    private function sanitizeTitle(string $title): string
    {
        $title = trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '-', $title));
        if ($title === '') {
            $title = 'Báo cáo';
        }

        return mb_substr($title, 0, 31);
    }
}
