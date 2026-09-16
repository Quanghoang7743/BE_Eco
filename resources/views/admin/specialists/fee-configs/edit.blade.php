@extends('admin.layout')

@section('title', 'Sửa cấu hình phí')

@section('content')
    @php
        $userName = fn ($user): string => $user ? ($user->full_name ?: ($user->name ?: ($user->email ?: 'N/A'))) : 'N/A';
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="mb-1">Sửa cấu hình phí nháp</h1>
                <div class="text-muted">Chỉ cấu hình chưa duyệt mới được chỉnh sửa.</div>
            </div>
            <a href="{{ route('admin.specialist-fee-configs.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Quay lại
            </a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.specialist-fee-configs.update', $feeConfig->id) }}" class="row g-3">
                    @csrf
                    @method('PUT')
                    <div class="col-md-6">
                        <label class="form-label">Tên cấu hình</label>
                        <input type="text" name="config_name" class="form-control" value="{{ old('config_name', $feeConfig->config_name) }}" required maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Loại ticket</label>
                        <select name="ticket_type_code" class="form-control" required>
                            @foreach ($ticketTypeOptions as $ticketType)
                                <option value="{{ $ticketType['code'] }}" @selected(old('ticket_type_code', $feeConfig->ticket_type_code ?: 'TICKET_DEFAULT') === $ticketType['code'])>
                                    {{ $ticketType['name'] }} ({{ $ticketType['code'] }}){{ $ticketType['is_default'] ? ' - loại vé mặc định hệ thống' : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phí / ticket</label>
                        <input type="number" name="fee_per_ticket" class="form-control" value="{{ old('fee_per_ticket', (int) $feeConfig->fee_per_ticket) }}" min="1" step="1" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phạm vi áp dụng</label>
                        <select name="apply_scope" class="form-control" required>
                            @foreach ($scopeLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old('apply_scope', $feeConfig->apply_scope) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Chuyên viên</label>
                        <select name="specialist_id" class="form-control">
                            <option value="">Chỉ chọn khi áp dụng theo chuyên viên</option>
                            @foreach ($specialistOptions as $specialist)
                                <option value="{{ $specialist->id }}" @selected((string) old('specialist_id', $feeConfig->specialist_id) === (string) $specialist->id)>{{ $userName($specialist) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ID khu vực</label>
                        <input type="number" name="region_id" class="form-control" value="{{ old('region_id', $feeConfig->region_id) }}" min="1">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Loại cửa hàng</label>
                        <input type="text" name="store_type" class="form-control" value="{{ old('store_type', $feeConfig->store_type) }}" list="storeTypeOptions">
                        <datalist id="storeTypeOptions">
                            @foreach ($storeTypeOptions as $storeType)
                                <option value="{{ $storeType }}"></option>
                            @endforeach
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hiệu lực từ</label>
                        <input type="datetime-local" name="effective_from" class="form-control" value="{{ old('effective_from', optional($feeConfig->effective_from)->format('Y-m-d\TH:i')) }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Hiệu lực đến</label>
                        <input type="datetime-local" name="effective_to" class="form-control" value="{{ old('effective_to', optional($feeConfig->effective_to)->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Ghi chú</label>
                        <textarea name="notes" class="form-control" rows="4">{{ old('notes', $feeConfig->notes) }}</textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a href="{{ route('admin.specialist-fee-configs.index') }}" class="btn btn-outline-secondary">Hủy</a>
                        <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
