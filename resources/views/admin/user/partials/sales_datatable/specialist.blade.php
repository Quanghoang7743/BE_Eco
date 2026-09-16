<a href="{{ $detailUrl }}" class="sales-person-link" data-specialist-sales-nav data-preserve-return="1">
    <span class="sales-person-avatar">
        <img src="{{ $avatarUrl }}" alt="{{ $displayName }}">
    </span>
    <span class="sales-person-copy">
        <span class="sales-person-name">{{ $displayName }}</span>
        @if($user->email)
            <span class="sales-person-meta">{{ $user->email }}</span>
        @endif
    </span>
</a>
