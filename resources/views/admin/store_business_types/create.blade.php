@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Thêm loại hình kinh doanh</h1>
        <a href="{{ route('admin.store-business-types.index') }}" class="btn btn-outline-secondary">Quay lại</a>
    </div>

    <div class="card">
        <div class="card-body">
            @include('admin.store_business_types._form', [
                'action' => route('admin.store-business-types.store'),
                'method' => 'POST',
                'submitLabel' => 'Lưu',
            ])
        </div>
    </div>
@endsection
