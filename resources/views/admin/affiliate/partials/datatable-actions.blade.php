<form method="POST" action="{{ route('admin.affiliate.change-upline', $report['complaint_id']) }}" class="row g-2">
    @csrf
    @method('PATCH')
    <div class="col-12">
        <label class="form-label small mb-1">Chọn hành động xử lý</label>
        <select name="action" class="form-control">
            <option value="detach">Xóa F2 khỏi F1 hiện tại</option>
            <option value="transfer">Chuyển F2 sang F1 khác</option>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label small mb-1">F1 mới (chỉ nhập khi chọn chuyển tuyến)</label>
        <input type="number" name="new_referrer_id" class="form-control" placeholder="Nhập ID user của F1 mới">
    </div>
    <div class="col-12">
        <button type="submit" class="btn btn-primary">Phê duyệt xử lý</button>
    </div>
</form>
