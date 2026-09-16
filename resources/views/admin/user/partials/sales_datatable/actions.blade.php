<div class="sales-row-actions">
    <a href="{{ $detailUrl }}" class="btn sales-icon-btn" title="Xem chi tiết" data-specialist-sales-nav data-preserve-return="1">
        <i class="fas fa-eye"></i>
    </a>
    <a href="{{ $editUrl }}" class="btn sales-icon-btn" title="Sửa chuyên viên">
        <i class="fas fa-pen"></i>
    </a>
    <div class="dropdown sales-action-menu">
        <button class="btn sales-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="fas fa-ellipsis-h"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <a class="dropdown-item" href="{{ $ledgerUrl }}" data-specialist-sales-nav data-preserve-return="1">
                    <i class="fas fa-receipt"></i>
                    Lịch sử ticket
                </a>
            </li>
            <li>
                <form action="{{ $toggleRoute }}" method="POST">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="dropdown-item" onclick="return confirm('Xác nhận hành động?')">
                        <i class="fas {{ $toggleIcon }}"></i>
                        {{ $toggleLabel }}
                    </button>
                </form>
            </li>
        </ul>
    </div>
</div>
