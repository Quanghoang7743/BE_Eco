            {{-- ── Hidden form (store info submit) ── --}}
            <form id="storeEditForm" action="{{ route('admin.stores.update', $store->id) }}" method="POST" class="d-none">
                @csrf @method('PUT')
            </form>
