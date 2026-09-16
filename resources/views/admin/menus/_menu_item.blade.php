@php
    $title = $menu->title ?? 'Không có tên';
@endphp

<li class="dd-item" data-id="{{ $menu->id }}">
    <div class="d-flex align-items-stretch">
        <div class="dd-handle d-flex align-items-center justify-content-center bg-light rounded handle"
            style="height: 55px;width: 55px;margin: 0; cursor: move;">
            <i class="bi bi-arrows-move fs-5"></i>
        </div>

        <div class="flex-grow-1 d-flex justify-content-between align-items-center p-2 bg-white rounded shadow-sm ms-2" style="border: 1px solid #e0e0e0;">
            <div>
                <strong>{{ $title }}</strong>
                <span class="badge bg-info text-uppercase ms-1" style="font-size: 10px;">{{ $menu->type }}</span>
                @if($menu->type === 'custom')
                    <small class="text-muted ms-2">{{ $menu->url }}</small>
                @endif
            </div>
            <div class="btn-group btn-group-sm gap-2">
                <a href="#" class="btn btn-outline-warning btn-edit-menu"
                   data-id="{{ $menu->id }}"
                   data-title="{{ $title }}"
                   data-type="{{ $menu->type }}"
                   data-url="{{ $menu->url }}"
                   data-reference="{{ $menu->reference_id }}"
                   data-parent="{{ $menu->parent_id ?? '' }}"
                   title="Sửa">
                    <i class="bi bi-pencil"></i>
                </a>

                <form action="{{ route('admin.menus.destroy', $menu->id) }}" method="POST" class="d-inline"
                    onsubmit="return confirm('Bạn có chắc chắn muốn xóa menu này cùng toàn bộ menu con?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-outline-danger" title="Xóa">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    @if (!empty($menu->children) && $menu->children->isNotEmpty())
        <ol class="dd-list mt-2" style="list-style: none;">
            @foreach ($menu->children as $child)
                @include('admin.menus._menu_item', ['menu' => $child])
            @endforeach
        </ol>
    @endif
</li>
