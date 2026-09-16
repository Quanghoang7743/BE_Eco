        {{-- ── Header ── --}}
        <div class="se-header">
            <div class="se-header-left">
                <div class="se-store-avatar">{{ mb_strtoupper(mb_substr($store->name, 0, 1)) }}</div>
                <div>
                    <h1 class="se-title">{{ $store->name }}</h1>
                    <p class="se-subtitle">Mã: {{ $store->store_code }}</p>
                </div>
            </div>
            <a href="{{ route('admin.stores.index') }}" class="se-btn-back">
                ← Quay lại danh sách
            </a>
        </div>
