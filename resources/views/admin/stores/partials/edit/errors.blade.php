        {{-- ── Errors ── --}}
        @if ($errors->any())
            <div class="se-alert" style="background-color: #fdf2f2; border: 1px solid #fde8e8; color: #c81e1e; padding: 12px 16px; border-radius: 6px; margin-bottom: 15px;">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('success'))
            <div class="se-alert-success" style="background-color: #f3faf7; border: 1px solid #def7ec; color: #03543f; padding: 12px 16px; border-radius: 6px; margin-bottom: 15px; font-weight: 500;">
                ✓ {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="se-alert-danger" style="background-color: #fdf2f2; border: 1px solid #fde8e8; color: #c81e1e; padding: 12px 16px; border-radius: 6px; margin-bottom: 15px; font-weight: 500;">
                ✕ {{ session('error') }}
            </div>
        @endif
