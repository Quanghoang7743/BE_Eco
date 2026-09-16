@extends('admin.layout')

@section('title', $pageTitle ?? 'Hồ sơ chuyên viên cửa hàng')

@section('content')
    <div id="specialistSalesShell">
        @include('admin.user.partials.sales_show_content')
    </div>
@endsection

@section('scripts')
    @include('admin.user.partials.sales_shell_scripts')
@endsection
