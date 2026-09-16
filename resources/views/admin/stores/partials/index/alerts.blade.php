        {{-- ── Success flash ── --}}
        @if (session('success'))
            <div class="si-alert">
                <i class="fas fa-check-circle"></i>
                {{ session('success') }}
            </div>
        @endif

        @if (session('warning'))
            <div class="si-alert si-alert-warning">
                <i class="fas fa-exclamation-circle"></i>
                {{ session('warning') }}
            </div>
        @endif
