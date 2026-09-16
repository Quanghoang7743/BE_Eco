@extends('admin.layout')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="mb-1">Điều khoản &amp; chính sách</h1>
        <p class="text-muted mb-0">Quản lý 2 nội dung cố định cho sàn và cửa hàng bằng bảng articles.</p>
    </div>
    <a href="{{ route('admin.policies.create') }}" class="btn btn-primary">Thêm mới chính sách</a>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead>
                    <tr>
                        <th style="width: 80px;">STT</th>
                        <th>Tiêu đề chính sách</th>
                        <th style="width: 160px;">Loại</th>
                        <th>Slug</th>
                        <th style="width: 190px;">Ngày cập nhật cuối</th>
                        <th style="width: 160px;">Trạng thái</th>
                        <th style="width: 140px;" class="text-end">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($policies as $policy)
                        <tr>
                            <td>{{ $policy['index'] }}</td>
                            <td>
                                <div class="fw-semibold">{{ $policy['article']->title ?? $policy['definition']['title'] }}</div>
                                <div class="text-muted small">{{ $policy['definition']['slug'] }}</div>
                            </td>
                            <td>{{ $policy['definition']['type'] }}</td>
                            <td><code>{{ $policy['definition']['slug'] }}</code></td>
                            <td>{{ $policy['updated_at'] ? $policy['updated_at']->format('Y-m-d H:i') : '-' }}</td>
                            <td>
                                <span class="badge bg-{{ $policy['status']['class'] }}">{{ $policy['status']['label'] }}</span>
                            </td>
                            <td class="text-end">
                                @if($policy['edit_url'])
                                    <a href="{{ $policy['edit_url'] }}" class="btn btn-sm btn-warning">Chỉnh sửa</a>
                                @else
                                    <a href="{{ route('admin.policies.create', ['slug' => $policy['definition']['slug']]) }}" class="btn btn-sm btn-success">Tạo mới</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection