    <script>
        var canManageStores =
            @json($canManageStores);
        $(function() {
            /* ── URL state ── */
            function getUrlState() {
                var params = new URLSearchParams(window.location.search);
                var col = parseInt(params.get('order_col') || '1', 10);
                if (isNaN(col) || col <= 0) col = 1;
                return {

                    search: params.get('q') || '',

                    saleFilter: params.get('sale_filter') || '0',

                    statusFilter: params.get('status_filter') || 'all',

                    regionFilter: params.get('region_filter') || '',

                    noOrders: params.get('no_orders') === '1',

                    heldTickets: params.get('held_tickets') === '1',

                    orderCol: col,

                    orderDir: params.get('order_dir') === 'asc' ?
                        'asc' : 'desc',

                    page: Math.max(
                        1,
                        parseInt(
                            params.get('page') || '1'
                        )
                    ),

                    length: Math.max(
                        1,
                        parseInt(
                            params.get('length') || '20'
                        )
                    )

                };
            }

            var state = getUrlState();
            $('#storeSearch').val(state.search);
            $('#saleFilter').val(state.saleFilter);
            $('#siLengthSelect').val(state.length);
            $('#storeStatusFilter').val(
                state.statusFilter
            );

            $('#storeRegionFilter').val(
                state.regionFilter
            );

            $('#noOrdersFilter').prop(
                'checked',
                state.noOrders
            );

            $('#heldTicketsFilter').prop(
                'checked',
                state.heldTickets
            );
            /* ── DataTables ── */
            var dt = $('#storesTable').DataTable({
                dom: 'r',
                processing: false,
                serverSide: true,
                autoWidth: false,
                pageLength: state.length,
                searchDelay: 300,
                displayStart: (state.page - 1) * state.length,
                search: {
                    search: state.search
                },
                order: [
                    [state.orderCol, state.orderDir]
                ],
                ajax: {
                    url: '{{ route('admin.stores.datatable') }}',
                    type: 'GET',
                    data: function(d) {

                        d.sale_filter =
                            canManageStores ?
                            ($('#saleFilter').val() || '0') :
                            '0';

                        d.status_filter =
                            $('#storeStatusFilter').val() ||
                            'all';

                        d.region_filter =
                            $('#storeRegionFilter').val() ||
                            '';

                        d.no_orders =
                            $('#noOrdersFilter').is(':checked') ?
                            '1' : '0';

                        d.held_tickets =
                            $('#heldTicketsFilter').is(':checked') ?
                            '1' : '0';

                    }
                },
                language: {
                    processing: '',
                    zeroRecords: '',
                    emptyTable: ''
                },
                lengthMenu: [
                    [20, 50, 100],
                    [20, 50, 100]
                ],
                columns: [{
                        data: 'checkbox',
                        orderable: false,
                        searchable: false
                    },
                    {
                        data: 'store_code'
                    },
                    {
                        data: 'name',
                        orderable: true
                    },
                    {
                        data: 'phone',
                        orderable: true
                    },
                    {
                        data: 'email',
                        orderable: true
                    },
                    {
                        data: 'address',
                        orderable: true
                    },
                    {
                        data: 'sale_id',
                        orderable: true,
                        searchable: false
                    },
                    {
                        data: 'pending',
                        orderable: true,
                        searchable: false
                    },
                    {
                        data: 'status',
                        orderable: true,
                        searchable: false
                    },
                    {
                        data: 'action',
                        orderable: false,
                        searchable: false
                    },
                ],
                columnDefs: [

                    {

                        targets: [0],

                        visible: canManageStores,

                        className: 'text-center'

                    },

                    {

                        targets: [7, 8, 9],

                        className: 'text-center'

                    }

                ],
            });

            /* ── Draw handler ── */
            dt.on('draw', function() {
                var info = dt.page.info();
                var order = dt.order();
                var rows = dt.rows({
                    page: 'current'
                }).data().toArray();

                /* sort icons */
                document.querySelectorAll('#storesTable thead th[data-col]').forEach(function(th) {
                    th.classList.remove('sorted-asc', 'sorted-desc');
                    if (order.length && parseInt(th.dataset.col) === order[0][0]) {
                        th.classList.add(order[0][1] === 'asc' ? 'sorted-asc' : 'sorted-desc');
                    }
                });

                /* ── Desktop table rows ── */
                var tbody = document.getElementById('storesTableBody');
                if (!rows.length) {
                    tbody.innerHTML =
                        '<tr><td colspan="10"><div class="si-empty"><i class="fas fa-store-slash"></i>Không tìm thấy dữ liệu phù hợp</div></td></tr>';
                } else {
                    tbody.innerHTML = rows.map(function(row) {
                        return '<tr>' +
                            '<td style="text-align:center;">' + (row.checkbox || '') + '</td>' +
                            '<td><span class="si-code">' + esc(row.store_code || '') +
                            '</span></td>' +
                            '<td><span class="si-store-name">' + esc(row.name || '') +
                            '</span></td>' +
                            '<td>' +
                            '<div style="display:flex;flex-direction:column;gap:2px;">' +
                            '<div>' + renderPhone(row.phone) + '</div>' +
                            '<div style="font-size:12px;color:#777;">' +
                            (row.email ? esc(row.email) : dash()) +
                            '</div>' +
                            '</div>' +
                            '</td>' +
                            '<td style="font-size:12.5px; color:#666; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' +
                            esc(row.address || '') + '</td>' +
                            '<td>' + (row.sale_id ||
                                '<span style="color:#ccc;font-size:12px;">Chưa gán</span>') +
                            '</td>' +
                            '<td style="text-align:center;">' + renderPending(row.pending) +
                            '</td>' +
                            '<td style="text-align:center;">' + (row.status || '') + '</td>' +
                            '<td style="text-align:center;">' + (row.action || '') + '</td>' +
                            '</tr>';
                    }).join('');
                }

                /* ── Mobile cards ── */
                var cardList = document.getElementById('storesCardList');
                if (!rows.length) {
                    cardList.innerHTML =
                        '<div class="si-empty"><i class="fas fa-store-slash"></i>Không tìm thấy dữ liệu phù hợp</div>';
                } else {
                    cardList.innerHTML = rows.map(function(row) {
                        var initial = String(row.name || '?')
                            .trim()
                            .replace(/<[^>]*>/g, '') // bỏ tag HTML nếu có
                            .charAt(0)
                            .toUpperCase();
                        console.log(initial);

                        return '<div class="si-store-card">' +
                            '<div class="si-store-card-header">' +
                            '<div class="si-store-card-left">' +
                            '<div class="si-store-card-check">' + (row.checkbox || '') + '</div>' +
                            '<div class="si-store-card-avatar">' + initial + '</div>' +
                            '<div class="si-store-card-name-wrap">' +
                            '<div class="si-store-card-name">' + esc(row.name || '') + '</div>' +
                            '<span class="si-store-card-code">' + esc(row.store_code || '') +
                            '</span>' +
                            '</div>' +
                            '</div>' +
                            '<div class="si-store-card-badges">' +
                            (row.status || '') +
                            (row.pending && parseInt(row.pending) > 0 ? ' ' + renderPending(row
                                .pending) : '') +
                            '</div>' +
                            '</div>' +
                            '<div class="si-store-card-body">' +
                            '<div class="si-store-card-row">' +
                            '<span class="si-store-card-lbl">SĐT</span>' +
                            '<span class="si-store-card-val">' + renderPhone(row.phone) +
                            '</span>' +
                            '</div>' +
                            '<div class="si-store-card-row">' +
                            '<span class="si-store-card-lbl">Email</span>' +
                            '<span class="si-store-card-val">' + (row.email ? esc(row.email) :
                                dash()) + '</span>' +
                            '</div>' +
                            '<div class="si-store-card-row" style="grid-column: 1 / -1;">' +
                            '<span class="si-store-card-lbl">Địa chỉ</span>' +
                            '<span class="si-store-card-val">' + esc(row.address || '') +
                            '</span>' +
                            '</div>' +
                            '</div>' +
                            '<div class="si-store-card-footer">' +
                            '<div class="si-store-card-sale">' + (row.sale_id ||
                                '<span style="color:#ccc;font-size:12px;">Chưa gán chuyên viên</span>'
                            ) + '</div>' +
                            '<div style="display:flex;gap:6px;flex-shrink:0;">' + (row.action ||
                                '') + '</div>' +
                            '</div>' +
                            '</div>';
                    }).join('');
                }

                /* info + pagination + checkboxes + url */
                document.getElementById('siTableInfo').textContent =
                    info.recordsTotal === 0 ? 'Chưa có dữ liệu' :
                    'Hiển thị ' + (info.start + 1) + '–' + info.end + ' / ' + info.recordsTotal + ' mục';

                renderPagination(info);
                $('#selectAllStores').prop('checked', false);
                updateDeleteBtn();
                pushUrlState();
                initSaleAssignForms();
            });

            /* ── Helpers ── */
            function esc(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;')

                    // allow <strong>
                    .replace(/&lt;strong&gt;/gi, '<strong>')
                    .replace(/&lt;\/strong&gt;/gi, '</strong>');
            }

            function dash() {
                return '<span style="color:#ddd;">—</span>';
            }

            function renderPhone(phone) {
                if (!phone) return dash();
                return '<a href="tel:' + esc(phone) + '" style="color:#555;text-decoration:none;">' +
                    '<i class="fas fa-phone" style="font-size:10px;margin-right:4px;color:#ccc;"></i>' + esc(
                        phone) + '</a>';
            }

            function renderPending(val) {
                var n = parseInt(val, 10);
                if (isNaN(n) || n === 0) return '<span style="color:#ccc;">0</span>';
                return '<span class="si-badge si-badge-pending"><i class="fas fa-clock" style="font-size:10px;"></i>' +
                    n + '</span>';
            }

            /* ── Pagination ── */
            function renderPagination(info) {
                var container = document.getElementById('siPagination');
                var total = Math.ceil(info.recordsTotal / info.length) || 1;
                var cur = info.page + 1;
                var btns = [];

                btns.push('<button class="si-page-btn" id="siPagePrev"' + (cur <= 1 ? ' disabled' : '') + '>' +
                    '<i class="fas fa-chevron-left" style="font-size:11px;"></i></button>');

                getPageNumbers(cur, total).forEach(function(p) {
                    if (p === '…') {
                        btns.push('<span style="padding:0 2px;color:#ccc;font-size:14px;">…</span>');
                    } else {
                        btns.push('<button class="si-page-btn' + (p === cur ? ' active' : '') +
                            '" data-page="' + p + '">' + p + '</button>');
                    }
                });

                btns.push('<button class="si-page-btn" id="siPageNext"' + (cur >= total ? ' disabled' : '') + '>' +
                    '<i class="fas fa-chevron-right" style="font-size:11px;"></i></button>');

                container.innerHTML = btns.join('');
                container.querySelectorAll('[data-page]').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        dt.page(parseInt(this.dataset.page) - 1).draw('page');
                    });
                });
                var prev = document.getElementById('siPagePrev');
                var next = document.getElementById('siPageNext');
                if (prev) prev.addEventListener('click', function() {
                    dt.page('previous').draw('page');
                });
                if (next) next.addEventListener('click', function() {
                    dt.page('next').draw('page');
                });
            }

            function getPageNumbers(cur, total) {
                if (total <= 7) {
                    var r = [];
                    for (var i = 1; i <= total; i++) r.push(i);
                    return r;
                }
                var pages = [1];
                if (cur > 3) pages.push('…');
                for (var p = Math.max(2, cur - 1); p <= Math.min(total - 1, cur + 1); p++) pages.push(p);
                if (cur < total - 2) pages.push('…');
                pages.push(total);
                return pages;
            }

            /* ── URL state ── */
            function pushUrlState() {
                var info = dt.page.info();
                var order = dt.order();
                var params = new URLSearchParams(window.location.search);
                var q = dt.search() || '';
                q ? params.set('q', q) : params.delete('q');
                var sf = $('#saleFilter').val();
                var status =
                    $('#storeStatusFilter').val();

                status !== 'all' ?
                    params.set(
                        'status_filter',
                        status
                    ) :
                    params.delete(
                        'status_filter'
                    );

                var region =
                    $('#storeRegionFilter').val();

                region
                    ?
                    params.set(
                        'region_filter',
                        region
                    ) :
                    params.delete(
                        'region_filter'
                    );

                $('#noOrdersFilter').is(':checked') ?
                    params.set(
                        'no_orders',
                        '1'
                    ) :
                    params.delete(
                        'no_orders'
                    );

                $('#heldTicketsFilter').is(':checked') ?
                    params.set(
                        'held_tickets',
                        '1'
                    ) :
                    params.delete(
                        'held_tickets'
                    );
                sf && sf !== '0' ? params.set('sale_filter', sf) : params.delete('sale_filter');
                params.set('order_col', order.length ? order[0][0] : 1);
                params.set('order_dir', order.length ? order[0][1] : 'desc');
                params.set('page', info.page + 1);
                params.set('length', info.length);
                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            /* ── Controls ── */
            $('#storeSearch').on('input', function() {
                dt.search(this.value).draw();
            });
            $('#saleFilter').on('change', function() {
                dt.draw();
            });
            $('#storeStatusFilter')
                .on(
                    'change',
                    function() {

                        dt.draw();

                    }
                );

            $('#storeRegionFilter')
                .on(
                    'input',
                    function() {

                        dt.draw();

                    }
                );

            $('#noOrdersFilter,#heldTicketsFilter')
                .on(
                    'change',
                    function() {

                        dt.draw();

                    }
                );
            $('#siLengthSelect').on('change', function() {
                dt.page.len(parseInt(this.value)).draw();
            });

            $('#resetStoreFilters').on(
                'click',
                function() {

                    $('#storeSearch').val('');

                    $('#storeStatusFilter').val('all');

                    $('#storeRegionFilter').val('');

                    $('#noOrdersFilter')
                        .prop('checked', false);

                    $('#heldTicketsFilter')
                        .prop('checked', false);

                    if (canManageStores) {

                        $('#saleFilter').val('0');

                    }

                    dt.search('').draw();

                }
            );

            document.querySelectorAll('#storesTable thead th[data-col]').forEach(function(th) {
                th.addEventListener('click', function() {
                    var col = parseInt(this.dataset.col);
                    var order = dt.order();
                    var dir = (order.length && order[0][0] === col && order[0][1] === 'asc') ?
                        'desc' : 'asc';
                    dt.order([col, dir]).draw();
                });
            });

            /* ── Checkboxes ── */
            function updateDeleteBtn() {

                if (!canManageStores) {

                    return;

                }

                var btn =
                    document.getElementById(
                        'deleteSelectedStores'
                    );

                if (!btn) {

                    return;

                }

                var n =
                    $('.store-row-checkbox:checked')
                    .length;

                btn.disabled =
                    n === 0;

                document.getElementById(
                        'deleteBtnText'
                    ).textContent =
                    n > 0 ?
                    'Xóa ' + n + ' đã chọn' :
                    'Xóa đã chọn';

            }

            $(document).on('change', '.store-row-checkbox', function() {
                updateDeleteBtn();
                var all = $('.store-row-checkbox').length;
                var checked = $('.store-row-checkbox:checked').length;
                $('#selectAllStores').prop('checked', all > 0 && all === checked);
            });

            $('#selectAllStores').on('change', function() {
                $('.store-row-checkbox').prop('checked', $(this).is(':checked'));
                updateDeleteBtn();
            });

            /* ── Bulk delete ── */
            $('#deleteSelectedStores').on('click', function() {
                var ids = $('.store-row-checkbox:checked').map(function() {
                    return $(this).val();
                }).get();
                if (!ids.length) return;
                if (!confirm('Xác nhận xóa ' + ids.length + ' cửa hàng đã chọn?')) return;

                var btn = $(this);
                btn.prop('disabled', true).html('<i class="fas fa-circle-notch fa-spin"></i> Đang xóa...');

                Promise.all(ids.map(function(id) {
                        return axios.post('{{ route('admin.stores.destroy', '') }}/' + id, {
                            _method: 'DELETE'
                        }, {
                            headers: {
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            }
                        });
                    }))
                    .then(function() {
                        dt.ajax.reload(null, false);
                        $('#selectAllStores').prop('checked', false);
                        updateDeleteBtn();
                        showSiToast('Cửa hàng đã được xóa thành công.', 'success');
                    })
                    .catch(function() {
                        showSiToast('Xóa cửa hàng thất bại. Vui lòng thử lại.', 'danger');
                    })
                    .finally(function() {
                        btn.prop('disabled', false).html(
                            '<i class="fas fa-trash-alt"></i> <span id="deleteBtnText">Xóa đã chọn</span>'
                        );
                    });
            });

            /* ── Sale assign ── */
            function initSaleAssignForms() {
                document.querySelectorAll('.js-sale-assign-select').forEach(function(sel) {
                    sel.removeEventListener('change', handleSaleChange);
                    sel.addEventListener('change', handleSaleChange);
                });
            }

            function handleSaleChange(e) {
                var sel = e.target;
                var form = sel.closest('.js-sale-assign-form');
                if (!form) return;
                var prev = sel.dataset.currentValue ?? '';
                if (sel.value === prev) return;
                sel.disabled = true;
                fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new FormData(form)
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data.success) throw new Error(data.message || 'Cập nhật thất bại.');
                        sel.dataset.currentValue = sel.value;
                        showSiToast(data.message || 'Đã cập nhật chuyên viên phụ trách.', 'success');
                    })
                    .catch(function(err) {
                        sel.value = prev;
                        showSiToast(err.message || 'Không thể cập nhật chuyên viên.', 'danger');
                    })
                    .finally(function() {
                        sel.disabled = false;
                    });
            }

            /* ── Toast ── */
            function showSiToast(msg, type) {
                var container = document.getElementById('saleAssignToastContainer');
                var el = document.createElement('div');
                el.className = 'si-toast si-toast-' + (type || 'success');
                var d = document.createElement('div');
                d.textContent = String(msg);
                el.innerHTML = '<i class="fas fa-' + (type === 'danger' ? 'times-circle' : 'check-circle') +
                    '"></i>' + d.innerHTML;
                container.appendChild(el);
                setTimeout(function() {
                    el.style.opacity = '0';
                    el.style.transition = 'opacity .3s';
                    setTimeout(function() {
                        el.remove();
                    }, 300);
                }, 2600);
            }
            window.showSiToast = showSiToast;

            /* ─── Realtime Pusher integration for store product pending count ─── */
            (function () {
                if (typeof Pusher === 'undefined') {
                    return;
                }

                var appKey = @json(config('broadcasting.connections.pusher.key'));
                var appCluster = @json(config('broadcasting.connections.pusher.options.cluster'));

                if (!appKey) {
                    return;
                }

                var pusher = new Pusher(appKey, {
                    cluster: appCluster || undefined,
                    forceTLS: true,
                    disableStats: true,
                });

                var channel = pusher.subscribe('product-approvals');
                channel.bind('product.approval.updated', function (data) {
                    console.log('Realtime store product approval count update event received:', data);
                    dt.ajax.reload(null, false);
                });

                window.addEventListener('beforeunload', function () {
                    channel.unbind('product.approval.updated');
                    pusher.unsubscribe('product-approvals');
                    pusher.disconnect();
                });
            })();

            // Click on desktop table row to edit store
            $(document).on('click', '#storesTableBody tr', function(e) {
                if ($(e.target).closest('a, button, input, select, label, form, td:first-child, td:last-child').length) {
                    return;
                }
                var editUrl = $(this).find('a.si-action-edit').attr('href');
                if (editUrl) {
                    window.location.href = editUrl;
                }
            });

            // Click on mobile store card to edit store
            $(document).on('click', '#storesCardList .si-store-card', function(e) {
                if ($(e.target).closest('a, button, input, select, label, form, .si-store-card-check').length) {
                    return;
                }
                var editUrl = $(this).find('a.si-action-edit').attr('href');
                if (editUrl) {
                    window.location.href = editUrl;
                }
            });

            initSaleAssignForms();
            dt.draw(false);
        });
    </script>
