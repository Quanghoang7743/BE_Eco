@php
    $catTitle = $category->title ?? 'Không có tên';
@endphp

<li class="list-group-item py-1 category-item"
    data-id="{{ $category->id }}"
    data-level="{{ $level }}"
    style="padding-left: {{ $level * 20 }}px; list-style: none;">

    {{-- Nếu có chuyên mục con thì hiện nút + --}}
    @if (($category->children->isNotEmpty() || $category->articles->isNotEmpty()) && $level < $maxLevel - 1)
        <span class="toggle-btn" style="cursor: pointer; font-weight: bold; margin-right: 5px;" data-id="{{ $category->id }}">＋</span>
    @else
        <span style="display:inline-block;width:14px;"></span> 
    @endif

    <input type="checkbox"
           class="form-check-input me-2 cat-checkbox"
           value="{{ $category->id }}"
           data-title="{{ $catTitle }}">

    <span class="small">{{ $catTitle }}</span>
</li>

{{-- Nếu còn trong maxLevel thì render con đệ quy --}}
@if ($level < $maxLevel - 1 && ($category->children->isNotEmpty() || $category->articles->isNotEmpty()))
    <ul class="child-list ms-3" id="children-{{ $category->id }}" style="display: none; padding-left: 0;">
        @foreach($category->children as $child)
            @include('admin.menus._category_item', [
                'category'  => $child,
                'level'     => $level + 1,
                'maxLevel'  => $maxLevel,
                'menuType'  => $menuType
            ])
        @endforeach

        @foreach($category->articles as $article)
            <li class="list-group-item py-1 category-article-item"
                data-level="{{ $level + 1 }}"
                style="padding-left: {{ ($level + 1) * 20 }}px; list-style: none;">
                <span style="display:inline-block;width:14px;"></span>
                <input type="checkbox"
                       class="form-check-input me-2 category-post-checkbox"
                       value="{{ $article->id }}"
                       data-title="{{ $article->title ?? 'Không có tên' }}">
                <span class="small">
                    <i class="bi bi-file-text me-1 text-muted"></i>{{ $article->title ?? 'Không có tên' }}
                </span>
            </li>
        @endforeach
    </ul>
@elseif($menuType == 'footer' && $level == 1 && $category->children->isNotEmpty())
    {{-- Đẩy cấp 3 lên ngang hàng với cấp 2 nếu ở footer --}}
    @foreach($category->children as $child)
        @include('admin.menus._category_item', [
            'category'  => $child,
            'level'     => 1, 
            'maxLevel'  => $maxLevel,
            'menuType'  => $menuType
        ])
    @endforeach
@endif

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.toggle-btn').forEach(function(btn) {
        // Tránh gán lại event listener nhiều lần
        if (btn.getAttribute('data-event-bound')) return;
        btn.setAttribute('data-event-bound', 'true');
        
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const id = btn.getAttribute('data-id');
            const children = document.getElementById('children-' + id);
            if (children) {
                if (children.style.display === 'none' || children.style.display === '') {
                    children.style.display = 'block';
                    btn.textContent = '－';
                } else {
                    children.style.display = 'none';
                    btn.textContent = '＋';
                }
            }
        });
    });
});
</script>
