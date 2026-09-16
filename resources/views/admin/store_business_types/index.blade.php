@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Loại hình kinh doanh</h1>
            <p class="text-muted mb-0">Quản lý danh mục hiển thị trong form đăng ký đối tác Store.</p>
        </div>
        <a href="{{ route('admin.store-business-types.create') }}" class="btn btn-primary">Thêm loại hình</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('admin.store-business-types.index') }}" class="row g-3 align-items-end mb-3">
        <div class="col-md-5">
            <label class="form-label">Tìm kiếm</label>
            <input type="text" name="keyword" class="form-control" value="{{ $keyword }}"
                placeholder="Tên hoặc slug">
        </div>
        <div class="col-md-3">
            <label class="form-label">Trạng thái</label>
            <select name="status" class="form-select">
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Tất cả</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Đang kích hoạt</option>
                <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Tạm ẩn</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">Lọc</button>
            <a href="{{ route('admin.store-business-types.index') }}" class="btn btn-outline-secondary">Xóa lọc</a>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-bordered table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width:70px">#</th>
                        <th>Tên</th>
                        <th>Slug</th>
                        <th>Icon</th>
                        <th class="text-center" style="width:120px">Cửa hàng</th>
                        <th class="text-center" style="width:120px">Thứ tự</th>
                        <th class="text-center" style="width:130px">Trạng thái</th>
                        <th class="text-center" style="width:170px">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($businessTypes as $businessType)
                        <tr>
                            <td>{{ $businessType->id }}</td>
                            <td class="fw-semibold">{{ $businessType->name }}</td>
                            <td><code>{{ $businessType->slug }}</code></td>
                            <td>
                                @if ($businessType->icon_url)
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="{{ $businessType->icon_url }}" alt="" width="28" height="28"
                                            style="object-fit:contain;border-radius:6px;border:1px solid #e5e7eb;">
                                        <span class="text-muted text-truncate" style="max-width:220px;">{{ $businessType->icon_url }}</span>
                                    </div>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-center">{{ $businessType->stores_count }}</td>
                            <td class="text-center">{{ $businessType->sort_order }}</td>
                            <td class="text-center">
                                @if ($businessType->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.store-business-types.edit', $businessType) }}"
                                    class="btn btn-sm btn-primary">Sửa</a>
                                <form action="{{ route('admin.store-business-types.destroy', $businessType) }}" method="POST"
                                    class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger"
                                        {{ $businessType->stores_count > 0 ? 'disabled' : '' }}
                                        onclick="return confirm('Xóa loại hình kinh doanh này?')">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Chưa có loại hình kinh doanh.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">
            {{ $businessTypes->links() }}
        </div>
    </div>
@endsection
