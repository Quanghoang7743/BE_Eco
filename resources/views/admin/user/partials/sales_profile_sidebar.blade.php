@php
    $avatar = trim((string) ($user->avatar ?? ''));
    $avatarUrl = $avatar !== ''
        ? (\Illuminate\Support\Str::startsWith($avatar, ['http://', 'https://'])
            ? $avatar
            : asset('storage/' . $avatar))
        : 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';
    $displayName = $displayName ?? ($user->full_name ?: ($user->name ?: ('#' . $user->id)));
    $birthday = $user->birthday ? $user->birthday->format('d/m/Y') : '--';
    $creditPoints = is_null($specialistCreditPoints ?? null) ? null : (int) $specialistCreditPoints;
    $creditProgress = is_null($creditPoints) ? 0 : max(0, min(100, $creditPoints));
@endphp

<div class="sales-profile-card">
    <a href="{{ route('admin.users.edit', $user->id) }}" class="sales-profile-edit">Chỉnh sửa</a>

    <div class="sales-profile-avatar-wrap">
        <img src="{{ $avatarUrl }}" alt="{{ $displayName }}" class="sales-profile-avatar">
    </div>

    <h2 class="sales-profile-name">{{ $displayName }}</h2>
    <div class="sales-profile-role">
        <span class="badge {{ $statusClass ?? 'bg-success' }}">{{ $statusLabel ?? 'Đang hoạt động' }}</span>
        <span class="sales-profile-separator">|</span>
        <span>{{ $profileLabel ?? 'Chuyên viên cửa hàng' }}</span>
    </div>

    <div class="sales-profile-score">
        <div>
            <div class="sales-profile-score-label">Số sao</div>
            <div class="sales-profile-score-value text-warning">
                <i class="fas fa-star"></i>
                {{ $specialistStarLevel ?? '---' }}
            </div>
        </div>
        <div>
            <div class="sales-profile-score-label">Điểm tín dụng</div>
            <div class="sales-profile-score-value text-primary">
                {{ is_null($creditPoints) ? '---' : number_format($creditPoints) }}
            </div>
        </div>
    </div>

    <div class="sales-profile-progress">
        <div class="sales-profile-progress-bar" style="width: {{ $creditProgress }}%"></div>
    </div>
    <div class="sales-profile-progress-note">
        <span>0</span>
        <span>100</span>
    </div>

    <div class="sales-profile-section">
        <h3>Thông tin liên hệ</h3>
        <div class="sales-profile-line">
            <i class="fas fa-phone"></i>
            <span>{{ $user->phone ?: '--' }}</span>
        </div>
        <div class="sales-profile-line">
            <i class="fas fa-cake-candles"></i>
            <span>{{ $birthday }}</span>
        </div>
        <div class="sales-profile-line">
            <i class="fas fa-envelope"></i>
            <span>{{ $user->email ?: '--' }}</span>
        </div>
        <div class="sales-profile-line">
            <i class="fas fa-user-tag"></i>
            <span>#{{ $user->id }}</span>
        </div>
    </div>

    <div class="sales-profile-section">
        <h3>Địa chỉ mặc định</h3>
        <div class="sales-profile-line align-items-start">
            <i class="fas fa-location-dot"></i>
            <span>{{ $user->address ?: ($user->gps_address ?: '--') }}</span>
        </div>
    </div>
</div>

<div class="sales-profile-card sales-profile-document">
    <h3>Tài liệu</h3>
    <div class="sales-profile-document-box">
        <div>Chưa có</div>
        <a href="{{ route('admin.users.edit', $user->id) }}" class="btn btn-primary btn-sm">Cập nhật hồ sơ</a>
    </div>
</div>
