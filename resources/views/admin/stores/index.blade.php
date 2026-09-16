@extends('admin.layout')

@section('content')
    @include('admin.stores.partials.index.styles')

    <div class="si-root">
        @include('admin.stores.partials.index.header')
        @include('admin.stores.partials.index.alerts')
        @include('admin.stores.partials.index.filters')
        @include('admin.stores.partials.index.table')
    </div>

    <div id="saleAssignToastContainer"></div>
@endsection

@section('scripts')
    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    @include('admin.stores.partials.index.scripts')
@endsection
