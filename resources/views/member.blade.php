@extends('layout')

@section('title', 'Danh sách thành viên')

@section('content')
    <style>
        .member-card {
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
            position: relative;
        }

        .member-card img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
        }

        .member-info {
            margin-left: 10px;
        }

        .stat-row {
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
        }
    </style>

    <div class="container mt-4">
        <h5 class="fw-bold mb-3">Danh sách thành viên</h5>

        <div class="mb-3">
            <form method="GET" action="{{ route('users.member') }}" id="search-form">
                <input type="text" name="q" value="{{ $query ?? '' }}" id="search-input" class="form-control"
                    placeholder="Nhập tên, SĐT hoặc ID thành viên">
            </form>
        </div>


        @foreach ($members as $member)
            <div class="member-card d-flex">
                <img src="{{ asset('storage/' . $member->avatar) }}" alt="Avatar">
                <div class="member-info flex-grow-1">
                    <div class="d-flex justify-content-between">
                        <div>
                            <strong>{{ $member->name }}</strong> - {{ $member->phone }}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark">Hệ cấp: F1</span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <div class="text-success">Hạng: Thành viên mới</div>
                        <div class="text-muted small">Ngày tham gia:
                            {{ \Carbon\Carbon::parse($member->joined_at)->format('d/m/Y') }}</div>
                    </div>

                    <div class="stat-row">
                        <div>Tổng thành viên</div>
                        <div>{{ $member->total_members ?? 0 }}</div>
                    </div>
                    <div class="stat-row">
                        <div>Số lượng nhánh</div>
                        <div>{{ $member->branch_count ?? 0 }}</div>
                    </div>
                    <div class="stat-row">
                        <div>Doanh thu cá nhân</div>
                        <div>{{ number_format($member->personal_sales ?? 0, 0, ',', '.') }}đ</div>
                    </div>
                    <div class="stat-row">
                        <div>Doanh số cá nhân</div>
                        <div>{{ number_format($member->personal_sales_completed ?? 0, 0, ',', '.') }}đ</div>
                    </div>
                    <div class="stat-row">
                        <div>Hoa hồng</div>
                        <div>{{ number_format($member->commission ?? 0, 0, ',', '.') }}đ</div>
                    </div>
                    {{-- <div class="stat-row">
                        <div>Doanh số nhóm</div>
                        <div>{{ number_format($member->group_sales ?? 0) }}đ</div>
                    </div> --}}

                </div>
            </div>
        @endforeach
    </div>
    <script>
        let typingTimer; // timer identifier
        let doneTypingInterval = 500; // time in ms

        const searchInput = document.getElementById('search-input');
        const searchForm = document.getElementById('search-form');

        searchInput.addEventListener('keyup', function() {
            clearTimeout(typingTimer);
            typingTimer = setTimeout(doneTyping, doneTypingInterval);
        });

        searchInput.addEventListener('keydown', function() {
            clearTimeout(typingTimer);
        });

        function doneTyping() {
            searchForm.submit(); // auto submit form
        }
    </script>
@endsection
