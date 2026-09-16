<div class="sales-store-list">
    @if($storeCount > 0)
        @foreach($displayedStores as $store)
            @php
                $storeName = $store->name ?: 'CH ' . $store->id;
                $storeCode = $store->store_code ?: 'ST' . $store->id;
            @endphp
            <div class="sales-store-row">
                <span class="sales-store-chip">{{ $storeName }}</span>
                <span class="sales-store-code">{{ $storeCode }}</span>
            </div>
        @endforeach

        @if($remainingStores > 0)
            <span class="sales-store-more">+{{ $remainingStores }}</span>
        @endif
    @else
        <span class="sales-store-empty">Chưa phân công</span>
    @endif
</div>
