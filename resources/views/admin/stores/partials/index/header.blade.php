        {{-- ── Header ── --}}
        <div class="si-header">
            {{-- <h1 class="si-title">Tất cả cửa hàng</h1>
            <a href="{{ route('admin.stores.create') }}" class="si-btn si-btn-primary">
                <i class="fas fa-plus"></i> Thêm cửa hàng
            </a> --}}
            <div>
                <h1 class="si-title">{{ $isSaleUser ? 'Cửa hàng tôi phụ trách' : 'Tất cả cửa hàng' }}</h1>
                @if ($isSaleUser)
                    <div class="text-muted">Danh sách cửa hàng được phân công cho chuyên viên đang đăng nhập.</div>
                @endif
            </div>
            @if ($canManageStores)
                <a href="{{ route('admin.stores.create') }}" class="si-btn si-btn-primary">
                    <i class="fas fa-plus me-1"></i> Thêm cửa hàng
                </a>
            @endif
        </div>
