@if ((int) $item->referrals_count >= 100)
    @if (!empty($item->profile_submitted) && empty($item->approved_at))
        <form method="POST" action="{{ route('admin.affiliate.withdrawals.approve', $item->request_id) }}" style="display:inline">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-sm btn-success">Duyệt</button>
        </form>
    @elseif (!empty($item->approved_at))
        <form method="POST" action="{{ route('admin.affiliate.withdrawals.toggle-status', $item->request_id) }}" style="display:inline">
            @csrf
            @method('PATCH')
            <button type="submit" class="btn btn-sm btn-outline-secondary">Chưa duyệt</button>
        </form>
    @elseif (!empty($item->request_id))
        <span class="text-warning">Đang chờ thông tin</span>
    @else
        <span class="text-info">Đủ điều kiện, chờ gửi hồ sơ</span>
    @endif
@else
    <span class="text-muted">Chưa đủ điều kiện</span>
@endif
