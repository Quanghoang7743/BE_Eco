@extends('admin.layout')

@section('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nestable2/1.6.0/jquery.nestable.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .pc-config-page .stat-card {
            border: 1px solid #edf2f7;
            border-radius: 14px;
            background: #fff;
            padding: 18px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }

        .pc-config-page .stat-card span {
            display: block;
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
        }

        .pc-config-page .stat-card strong {
            color: #0f172a;
            font-size: 26px;
            line-height: 1.2;
        }

        .pc-config-page .dd {
            max-width: none;
        }

        .pc-config-page .dd-list {
            list-style: none;
        }

        .pc-config-page .dd-list .dd-list {
            padding-left: 42px;
            margin-top: 10px;
        }

        .pc-category-item {
            margin-bottom: 10px;
        }

        .pc-category-row {
            display: flex;
            align-items: stretch;
            gap: 10px;
        }

        .pc-drag-handle {
            width: 52px;
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            color: #64748b;
            cursor: move;
        }

        .pc-category-card {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            padding: 12px 14px;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.04);
        }

        .pc-category-main {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .pc-category-thumb {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #eff6ff;
            color: #2563eb;
            flex: 0 0 auto;
        }

        .pc-category-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .pc-category-name {
            max-width: 420px;
            display: inline-block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }

        .pc-category-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 14px;
        }

        .pc-config-page .dd-placeholder,
        .pc-config-page .dd-empty {
            border: 1px dashed #94a3b8;
            border-radius: 12px;
            background: #f8fafc;
            min-height: 64px;
        }

        @media (max-width: 767px) {
            .pc-category-card,
            .pc-category-actions {
                align-items: flex-start;
                flex-direction: column;
            }

            .pc-category-name {
                max-width: 220px;
            }
        }
    </style>
@endsection

@section('content')
    <div class="pc-config-page">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="mb-1">Cấu hình danh mục Header/Trang chủ</h1>
                <p class="text-muted mb-0">Kéo thả để sắp xếp, bật danh mục và chọn danh mục hiển thị ngoài FE.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.product-categories.index') }}" class="btn btn-outline-secondary">
                    <i class="bi bi-list-ul me-1"></i>Danh sách
                </a>
                <a href="{{ route('admin.product-categories.create') }}" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Thêm danh mục
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <span>Tổng danh mục</span>
                    <strong>{{ $stats['total'] }}</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <span>Đang bật</span>
                    <strong>{{ $stats['active'] }}</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <span>Hiển thị Header/Home</span>
                    <strong>{{ $stats['featured'] }}</strong>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <span>Độ sâu tối đa</span>
                    <strong>{{ $stats['max_level'] }}</strong>
                </div>
            </div>
        </div>

        {{-- <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle mt-1"></i>
            <div>
                API FE sẽ chỉ lấy danh mục có <strong>Bật</strong> và <strong>Header/Home</strong>. Sau khi lưu thứ tự hoặc đổi switch, cache API client được xóa ngay.
            </div>
        </div> --}}

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h5 class="mb-1">Cây danh mục sản phẩm</h5>
                    <small class="text-muted">Kéo icon để đổi thứ tự hoặc kéo lồng cấp. Tối đa 3 cấp.</small>
                </div>
                <button type="button" class="btn btn-primary save-category-config-btn" {{ $categoryTree->isEmpty() ? 'disabled' : '' }}>
                    <i class="bi bi-save me-1"></i>Lưu thứ tự
                </button>
            </div>
            <div class="card-body">
                @if ($categoryTree->isEmpty())
                    <div class="alert alert-warning mb-0">
                        Chưa có danh mục sản phẩm. Hãy thêm danh mục trước khi cấu hình.
                    </div>
                @else
                    <div class="dd" id="productCategoryConfigTree">
                        <ol class="dd-list">
                            @foreach ($categoryTree as $category)
                                @include('admin.product_categories._home_config_item', ['category' => $category])
                            @endforeach
                        </ol>
                    </div>
                @endif
            </div>
            @if ($categoryTree->isNotEmpty())
                <div class="card-footer bg-white d-flex justify-content-end">
                    <button type="button" class="btn btn-primary save-category-config-btn">
                        <i class="bi bi-save me-1"></i>Lưu thứ tự
                    </button>
                </div>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script src="https://cdnjs.cloudflare.com/ajax/libs/nestable2/1.6.0/jquery.nestable.min.js"></script>
    <script>
        $(function () {
            const $tree = $('#productCategoryConfigTree');
            if ($tree.length) {
                $tree.nestable({
                    maxDepth: 3
                });
            }

            $('.save-category-config-btn').on('click', function () {
                if (!$tree.length) {
                    return;
                }

                const $button = $(this);
                const originalHtml = $button.html();
                $button.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Đang lưu...');

                axios.patch(@json(route('admin.product-categories.reorder')), {
                    categories: $tree.nestable('serialize')
                }).then(function () {
                    $button.removeClass('btn-primary').addClass('btn-success').html('<i class="bi bi-check-circle me-1"></i>Đã lưu');
                    if (typeof window.showAppSuccess === 'function') {
                        window.showAppSuccess('Đã lưu cấu hình danh mục.');
                    }
                    setTimeout(function () {
                        window.location.reload();
                    }, 700);
                }).catch(function (error) {
                    console.error(error);
                    $button.removeClass('btn-primary').addClass('btn-danger').html('<i class="bi bi-x-circle me-1"></i>Lỗi');
                    if (typeof window.showAppError === 'function') {
                        window.showAppError(error.response?.data?.message || 'Không thể lưu cấu hình danh mục.');
                    }
                    setTimeout(function () {
                        $button.prop('disabled', false).removeClass('btn-danger btn-success').addClass('btn-primary').html(originalHtml);
                    }, 1600);
                });
            });

            $('.pc-display-toggle').on('change', function () {
                const $input = $(this);
                const previousChecked = !$input.is(':checked');
                const field = $input.data('field');
                const url = $input.data('url');
                const payload = {};
                payload[field] = $input.is(':checked') ? 1 : 0;

                $input.prop('disabled', true);
                axios.patch(url, payload)
                    .then(function () {
                        if (typeof window.showAppSuccess === 'function') {
                            window.showAppSuccess('Đã cập nhật cấu hình hiển thị.');
                        }
                    })
                    .catch(function (error) {
                        console.error(error);
                        $input.prop('checked', previousChecked);
                        if (typeof window.showAppError === 'function') {
                            window.showAppError(error.response?.data?.message || 'Không thể cập nhật cấu hình hiển thị.');
                        }
                    })
                    .finally(function () {
                        $input.prop('disabled', false);
                    });
            });
        });
    </script>
@endsection
