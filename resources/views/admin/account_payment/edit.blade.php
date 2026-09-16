@extends('admin.layout')

@section('content')
<h1>Cập nhật trạng thái rút tiền</h1>

@if (session('success'))
    <div class="alert alert-success mt-2 mb-2">{{ session('success') }}</div>
@endif

@if (session('error'))
    <div class="alert alert-danger mt-2 mb-2">{{ session('error') }}</div>
@endif

@if ($errors->any())
    <div class="alert alert-danger mt-2 mb-2">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form action="{{ route('admin.bank-accounts.update', $withdrawRequest->id) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')
    <input type="hidden" name="redirect_to" value="{{ request()->query('redirect_to') }}">

    <div class="mb-3">
        <label for="status" class="form-label">Trạng thái</label>
        <select name="status" id="status" class="form-control" required>
            <option value="pending" {{ $withdrawRequest->status == 'pending' ? 'selected' : '' }}>Chờ duyệt</option>
            <option value="approved" {{ $withdrawRequest->status == 'approved' ? 'selected' : '' }}>Đã duyệt</option>
            <option value="paid" {{ $withdrawRequest->status == 'paid' ? 'selected' : '' }}>Đã thanh toán</option>
            <option value="rejected" {{ $withdrawRequest->status == 'rejected' ? 'selected' : '' }}>Từ chối</option>
        </select>
    </div>

    <div id="rejectionReasonGroup" class="mb-3" style="display: {{ $withdrawRequest->status === 'rejected' ? 'block' : 'none' }};">
        <label for="rejection_reason" class="form-label">Lý do từ chối</label>
        <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="4">{{ old('rejection_reason', $withdrawRequest->rejection_reason) }}</textarea>
    </div>

    <div class="mb-3">
        <label for="image" class="form-label">Ảnh bill / chứng từ chuyển tiền (nếu có)</label>
        <input type="file" name="image" id="image" class="form-control">
        @if ($withdrawRequest->image)
            <p class="mt-2">Đã có ảnh: <br><img src="{{ asset('storage/' . $withdrawRequest->image) }}" width="200"></p>
        @endif
    </div>

    <button type="submit" class="btn btn-success">Cập nhật</button>
</form>
@endsection

@section('scripts')
<script>
    $(function () {
        function toggleRejectionReason() {
            var status = $('#status').val();
            if (status === 'rejected') {
                $('#rejectionReasonGroup').show();
                $('#rejection_reason').prop('required', true);
            } else {
                $('#rejectionReasonGroup').hide();
                $('#rejection_reason').prop('required', false);
            }
        }

        $('#status').on('change', toggleRejectionReason);
        toggleRejectionReason();
    });
</script>
@endsection
