@extends('admin.layout')

@section('content')
    @include('admin.stores.partials.edit.styles')

    <div class="se-page store-edit-root">
        @include('admin.stores.partials.edit.header')
        @include('admin.stores.partials.edit.errors')

        <div class="se-card">
            @include('admin.stores.partials.edit.tabs')
            @include('admin.stores.partials.edit.store_form')
            @include('admin.stores.partials.edit.tab_store_info')
            @include('admin.stores.partials.edit.tab_store_products')
            @include('admin.stores.partials.edit.tab_store_inventory')
            @include('admin.stores.partials.edit.tab_product_approvals')
            @include('admin.stores.partials.edit.tab_store_admins')
            @include('admin.stores.partials.edit.tab_store_customers')
            @include('admin.stores.partials.edit.tab_store_orders')
            @include('admin.stores.partials.edit.tab_store_reviews')
            @include('admin.stores.partials.edit.tab_store_limit_topup')
            @include('admin.stores.partials.edit.footer')
        </div>
    </div>

    @include('admin.stores.partials.edit.toast')
    @include('admin.stores.partials.edit.create_store_admin_modal')
    @include('admin.stores.partials.modals')
    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    @include('admin.stores.partials.edit.scripts')
@endsection
