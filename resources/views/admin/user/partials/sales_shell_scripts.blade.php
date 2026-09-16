<script>
    (function () {
        if (window.specialistSalesShell && window.specialistSalesShell.initialized) {
            return;
        }

        var rootId = 'specialistSalesShell';

        function getRoot() {
            return document.getElementById(rootId);
        }

        function runScripts(root) {
            Array.from(root.querySelectorAll('script')).forEach(function (oldScript) {
                var newScript = document.createElement('script');

                Array.from(oldScript.attributes).forEach(function (attribute) {
                    newScript.setAttribute(attribute.name, attribute.value);
                });

                newScript.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            });
        }

        function buildUrl(link) {
            var url = new URL(link.getAttribute('href'), window.location.origin);

            if (link.dataset.preserveReturn === '1') {
                url.searchParams.set('return_to', window.location.pathname + window.location.search);
            }

            return url;
        }

        function initIndexView() {
            var container = document.querySelector('[data-specialist-sales-index]');
            if (!container || container.dataset.initialized === '1' || !window.jQuery || !window.jQuery.fn.DataTable) {
                return;
            }

            container.dataset.initialized = '1';

            function getUrlState() {
                var params = new URLSearchParams(window.location.search);

                return {
                    search: params.get('q') || '',
                    status: params.get('status') || '',
                    area: params.get('area') || '',
                    star: params.get('star') || '',
                    credit: params.get('credit') || '',
                    orderCol: parseInt(params.get('order_col') || '1', 10),
                    orderDir: params.get('order_dir') === 'asc' ? 'asc' : 'desc',
                    page: Math.max(1, parseInt(params.get('page') || '1', 10)),
                    length: Math.max(1, parseInt(params.get('length') || '20', 10))
                };
            }

            function updateUrlState(table) {
                var info = table.page.info();
                var order = table.order();
                var params = new URLSearchParams(window.location.search);
                var currentSearch = table.search() || '';
                var status = $('#saleStatusFilter').val() || '';
                var area = $('#saleAreaFilter').val() || '';
                var star = $('#saleStarFilter').val() || '';
                var credit = $('#saleCreditFilter').val() || '';

                currentSearch ? params.set('q', currentSearch) : params.delete('q');
                status ? params.set('status', status) : params.delete('status');
                area ? params.set('area', area) : params.delete('area');
                star ? params.set('star', star) : params.delete('star');
                credit ? params.set('credit', credit) : params.delete('credit');
                params.set('order_col', order.length ? order[0][0] : 1);
                params.set('order_dir', order.length ? order[0][1] : 'desc');
                params.set('page', info.page + 1);
                params.set('length', info.length);

                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            function updateDeleteSelectedButton() {
                $('#deleteSelectedSales').prop('disabled', $('.user-row-checkbox:checked').length === 0);
            }

            var urlState = getUrlState();
            $('#saleSearch').val(urlState.search);
            $('#saleStatusFilter').val(urlState.status);
            $('#saleAreaFilter').val(urlState.area);
            $('#saleStarFilter').val(urlState.star);
            $('#saleCreditFilter').val(urlState.credit);

            var salesTable = $('#salesTable').DataTable({
                destroy: true,
                dom: 'rt<"specialist-dt-footer"<"specialist-dt-left"li><"specialist-dt-right"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                pageLength: urlState.length,
                searchDelay: 300,
                displayStart: (urlState.page - 1) * urlState.length,
                search: { search: urlState.search },
                order: [[urlState.orderCol, urlState.orderDir]],
                ajax: {
                    url: container.dataset.datatableUrl,
                    type: 'GET',
                    data: function (d) {
                        d.status_filter = $('#saleStatusFilter').val();
                        d.area_filter = $('#saleAreaFilter').val();
                        d.star_filter = $('#saleStarFilter').val();
                        d.credit_filter = $('#saleCreditFilter').val();
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    lengthMenu: '_MENU_ / trang',
                    info: 'Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ chuyên viên',
                    infoEmpty: 'Hiển thị 0 đến 0 trong tổng số 0 chuyên viên',
                    infoFiltered: '(lọc từ _MAX_ chuyên viên)',
                    loadingRecords: 'Đang tải...',
                    zeroRecords: 'Không tìm thấy chuyên viên phù hợp',
                    emptyTable: 'Chưa có chuyên viên',
                    paginate: {
                        first: 'Đầu',
                        previous: '<i class="fas fa-chevron-left"></i>',
                        next: '<i class="fas fa-chevron-right"></i>',
                        last: 'Cuối'
                    }
                },
                lengthMenu: [[20, 50, 100], [20, 50, 100]],
                columns: [
                    { data: 'checkbox', orderable: false, searchable: false },
                    { data: 'id' },
                    { data: 'specialist' },
                    { data: 'phone' },
                    { data: 'status' },
                    { data: 'assigned_stores', orderable: false, searchable: false },
                    { data: 'star_level', orderable: false, searchable: false },
                    { data: 'credit_points', orderable: false, searchable: false },
                    { data: 'action', orderable: false, searchable: false },
                ],
                columnDefs: [
                    { targets: [0, 1, 4, 6, 7, 8], className: 'text-center' },
                ],
            });

            $('#saleSearch').off('.specialistSalesIndex').on('input.specialistSalesIndex', function () {
                salesTable.search(this.value).draw();
            });

            $('#saleStatusFilter, #saleStarFilter, #saleCreditFilter').off('.specialistSalesIndex').on('change.specialistSalesIndex', function () {
                salesTable.ajax.reload();
            });

            $('#saleAreaFilter').off('.specialistSalesIndex').on('input.specialistSalesIndex', function () {
                salesTable.ajax.reload();
            });

            $('#applySalesFilters').off('.specialistSalesIndex').on('click.specialistSalesIndex', function () {
                salesTable.ajax.reload();
            });

            $('#resetSalesFilters').off('.specialistSalesIndex').on('click.specialistSalesIndex', function () {
                $('#saleSearch').val('');
                $('#saleStatusFilter').val('');
                $('#saleAreaFilter').val('');
                $('#saleStarFilter').val('');
                $('#saleCreditFilter').val('');
                salesTable.search('').ajax.reload();
            });

            salesTable.on('draw', function () {
                $('#selectAllSales').prop('checked', false);
                updateDeleteSelectedButton();
                updateUrlState(salesTable);
            });

            $('#salesTable').off('.specialistSalesIndex').on('change.specialistSalesIndex', '.user-row-checkbox', function () {
                updateDeleteSelectedButton();
                $('#selectAllSales').prop('checked', $('.user-row-checkbox').length > 0 && $('.user-row-checkbox').length === $('.user-row-checkbox:checked').length);
            });

            $('#selectAllSales').off('.specialistSalesIndex').on('change.specialistSalesIndex', function () {
                $('.user-row-checkbox').prop('checked', $(this).is(':checked'));
                updateDeleteSelectedButton();
            });

            $('#deleteSelectedSales').off('.specialistSalesIndex').on('click.specialistSalesIndex', function () {
                var selectedIds = $('.user-row-checkbox:checked').map(function () {
                    return $(this).val();
                }).get();

                if (!selectedIds.length || !confirm('Xác nhận xóa ' + selectedIds.length + ' tài khoản đã chọn?')) {
                    return;
                }

                var button = $(this);
                button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Đang xóa...');

                axios.post(container.dataset.destroyMultipleUrl, { ids: selectedIds }, {
                    headers: { 'X-CSRF-TOKEN': container.dataset.csrfToken }
                })
                .then(function (response) {
                    salesTable.ajax.reload(null, false);
                    $('#selectAllSales').prop('checked', false);
                    updateDeleteSelectedButton();
                    window.showAppSuccess(response.data.message || 'Tài khoản đã được xóa.', 'Thành công');
                })
                .catch(function () {
                    window.showAppError('Xóa tài khoản thất bại. Vui lòng thử lại.', 'Lỗi');
                })
                .finally(function () {
                    button.prop('disabled', false).html('<i class="fas fa-trash"></i> Xóa đã chọn');
                });
            });
        }

        function activateTab(container, group, key, syncUrl) {
            container.querySelectorAll('[data-sales-tab-button="' + group + '"]').forEach(function (button) {
                button.classList.toggle('active', button.dataset.salesTabTarget === key);
            });

            container.querySelectorAll('[data-sales-tab-panel="' + group + '"]').forEach(function (panel) {
                panel.classList.toggle('active', panel.dataset.salesTabKey === key);
            });

            if (syncUrl && group === 'primary') {
                var url = new URL(window.location.href);
                url.searchParams.set('module_tab', key);
                history.replaceState(history.state, '', url.pathname + url.search + url.hash);
            }
        }

        function initDetailView() {
            var container = document.querySelector('[data-specialist-sales-detail]');
            if (!container || container.dataset.initialized === '1') {
                return;
            }

            container.dataset.initialized = '1';

            var activeModuleTab = container.dataset.activeModuleTab || 'profile';
            activateTab(container, 'primary', activeModuleTab, false);

            container.querySelectorAll('[data-sales-tab-button]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var group = button.dataset.salesTabButton;
                    var key = button.dataset.salesTabTarget;
                    activateTab(container, group, key, true);
                });
            });
        }

        function closeDrawers(root) {
            var scope = root || document;
            scope.querySelectorAll('.sd-drawer.is-open').forEach(function (drawer) {
                drawer.classList.remove('is-open');
                drawer.setAttribute('aria-hidden', 'true');
            });
            scope.querySelectorAll('.sd-drawer-overlay.is-open').forEach(function (overlay) {
                overlay.classList.remove('is-open');
            });
            document.body.style.overflow = '';
        }

        function openDrawer(id) {
            var drawer = document.getElementById(id);
            if (!drawer) {
                return;
            }

            var detailPage = drawer.closest('.sales-detail-page') || document;
            closeDrawers(detailPage);

            var overlay = detailPage.querySelector('.sd-drawer-overlay');
            if (overlay) {
                overlay.classList.add('is-open');
            }

            drawer.classList.add('is-open');
            drawer.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            var firstField = drawer.querySelector('input:not([type="hidden"]), select, textarea, button');
            if (firstField) {
                setTimeout(function () {
                    firstField.focus();
                }, 80);
            }
        }

        function initCurrentView() {
            initIndexView();
            initDetailView();
        }

        async function navigate(url, pushState) {
            var root = getRoot();
            if (!root) {
                window.location.href = url;
                return;
            }

            var browserUrl = new URL(url, window.location.origin);
            var requestUrl = new URL(browserUrl.toString());
            requestUrl.searchParams.set('fragment', '1');

            root.style.opacity = '0.55';
            root.style.pointerEvents = 'none';
            closeDrawers(root);

            try {
                var response = await axios.get(requestUrl.toString());
                root.innerHTML = response.data;
                runScripts(root);
                initCurrentView();

                if (pushState) {
                    history.pushState({ specialistSalesShell: true }, '', browserUrl.pathname + browserUrl.search + browserUrl.hash);
                }

                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (error) {
                window.location.href = browserUrl.toString();
            } finally {
                root.style.opacity = '';
                root.style.pointerEvents = '';
            }
        }

        document.addEventListener('click', function (event) {
            var drawerOpen = event.target.closest('[data-sales-drawer-open]');
            if (drawerOpen) {
                event.preventDefault();
                openDrawer(drawerOpen.dataset.salesDrawerOpen);
                return;
            }

            if (event.target.closest('[data-sales-drawer-close]')) {
                event.preventDefault();
                closeDrawers(document);
                return;
            }

            var link = event.target.closest('[data-specialist-sales-nav]');
            if (!link) {
                return;
            }

            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target === '_blank') {
                return;
            }

            if (!getRoot()) {
                return;
            }

            event.preventDefault();
            navigate(buildUrl(link).toString(), true);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeDrawers(document);
            }
        });

        window.addEventListener('popstate', function () {
            if (!getRoot()) {
                return;
            }

            if (!window.location.pathname.startsWith('/admin/users/sales')) {
                return;
            }

            navigate(window.location.href, false);
        });

        window.specialistSalesShell = {
            initialized: true,
            buildUrl: buildUrl,
            initCurrentView: initCurrentView,
            navigate: navigate,
            openDrawer: openDrawer,
            closeDrawers: closeDrawers,
        };

        initCurrentView();
    })();
</script>
