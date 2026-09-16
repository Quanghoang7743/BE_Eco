<form method="POST" action="{{ route('admin.complaints.assign', $complaint->id) }}" class="d-flex gap-2 align-items-start">
    @csrf
    @method('PATCH')
    <div class="flex-grow-1">
        <select name="assigned_to" class="form-control form-control-sm mb-2">
            <option value="">-- Chọn chuyên viên Sale --</option>
            @foreach ($assigneeOptions as $opt)
                <option value="{{ $opt['value'] }}" {{ (string) $complaint->assigned_to === (string) $opt['value'] ? 'selected' : '' }}>
                    {{ $opt['label'] }}
                </option>
            @endforeach
        </select>
        <select name="status" class="form-control form-control-sm">
            <option value="">Giữ trạng thái hiện tại</option>
            <option value="new" {{ $complaint->status === 'new' ? 'selected' : '' }}>Mới tiếp nhận</option>
            <option value="pending" {{ $complaint->status === 'pending' ? 'selected' : '' }}>Mới tiếp nhận (cũ)</option>
            <option value="processing" {{ $complaint->status === 'processing' ? 'selected' : '' }}>Đang xử lý</option>
        </select>
    </div>
    <button class="btn btn-sm btn-primary" type="submit">Lưu</button>
</form>
