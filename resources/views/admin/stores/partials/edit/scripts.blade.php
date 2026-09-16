    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        (function() {

            /* ── Custom tabs ── */
            const tabBtns = document.querySelectorAll('.se-tab-btn');
            const tabPanes = document.querySelectorAll('.se-tab-pane');

            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const target = this.dataset.tab;

                    tabBtns.forEach(b => {
                        b.classList.remove('active');
                        b.setAttribute('aria-selected', 'false');
                    });
                    tabPanes.forEach(p => p.classList.remove('active'));

                    this.classList.add('active');
                    this.setAttribute('aria-selected', 'true');
                    document.getElementById(target)?.classList.add('active');

                    const activeTab = target.replace('pane-', '');
                    const url = new URL(window.location.href);
                    url.searchParams.set('active_tab', activeTab);
                    window.history.replaceState({}, '', url.toString());
                });
            });

            /* ── Leaflet map ── */
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            const addressInput = document.getElementById('address');
            const geocodeBtn = document.getElementById('btnGeocodeAddress');
            const currentBtn = document.getElementById('btnUseCurrentLocation');

            const defaultLat = parseFloat(latInput.value || '10.7769');
            const defaultLng = parseFloat(lngInput.value || '106.7009');

            const map = L.map('storeMap').setView([defaultLat, defaultLng], 13);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png').addTo(map);
            let marker = L.marker([defaultLat, defaultLng]).addTo(map);

            const setLatLng = (lat, lng) => {
                latInput.value = Number(lat).toFixed(6);
                lngInput.value = Number(lng).toFixed(6);
                marker.setLatLng([lat, lng]);
                map.panTo([lat, lng]);
            };

            map.on('click', e => setLatLng(e.latlng.lat, e.latlng.lng));

            geocodeBtn.addEventListener('click', async () => {
                const q = addressInput.value.trim();
                if (!q) {
                    alert('Vui lòng nhập địa chỉ trước khi tìm trên bản đồ.');
                    return;
                }
                try {
                    const res = await fetch(
                        `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(q)}`, {
                            headers: {
                                Accept: 'application/json'
                            }
                        });
                    if (!res.ok) throw new Error();
                    const data = await res.json();
                    if (!data.length) {
                        alert('Không tìm thấy vị trí phù hợp.');
                        return;
                    }
                    setLatLng(parseFloat(data[0].lat), parseFloat(data[0].lon));
                    if (data[0].display_name) addressInput.value = data[0].display_name;
                } catch {
                    alert('Có lỗi khi tìm địa chỉ. Vui lòng thử lại.');
                }
            });

            currentBtn.addEventListener('click', () => {
                if (!navigator.geolocation) {
                    alert('Trình duyệt không hỗ trợ vị trí hiện tại.');
                    return;
                }
                navigator.geolocation.getCurrentPosition(
                    p => setLatLng(p.coords.latitude, p.coords.longitude),
                    () => alert('Không thể lấy vị trí. Kiểm tra quyền truy cập.')
                );
            });

            latInput.addEventListener('change', () => {
                const lat = parseFloat(latInput.value),
                    lng = parseFloat(lngInput.value);
                if (!isNaN(lat) && !isNaN(lng)) setLatLng(lat, lng);
            });
            lngInput.addEventListener('change', () => {
                const lat = parseFloat(latInput.value),
                    lng = parseFloat(lngInput.value);
                if (!isNaN(lat) && !isNaN(lng)) setLatLng(lat, lng);
            });

            /* ── Sale select2 + info box ── */
            const saleSelect = document.getElementById('sale_id');
            const saleNameEl = document.getElementById('saleInfoName');
            const salePhoneEl = document.getElementById('saleInfoPhone');
            const saleEmailEl = document.getElementById('saleInfoEmail');
            const saleAvatarEl = document.getElementById('saleAvatarEl');

            const updateSaleInfo = () => {
                const sel = saleSelect.options[saleSelect.selectedIndex];
                const hasVal = sel && sel.value !== '';
                const name = hasVal ? sel.text.trim() : 'Chưa chọn chuyên viên';
                const phone = hasVal ? (sel.dataset.phone || 'N/A') : 'N/A';
                const email = hasVal ? (sel.dataset.email || 'N/A') : 'N/A';
                saleNameEl.textContent = name;
                salePhoneEl.textContent = 'SĐT: ' + phone;
                saleEmailEl.textContent = 'Email: ' + email;
                saleAvatarEl.textContent = hasVal ? name.trim().split(' ').slice(-1)[0][0].toUpperCase() : '—';
            };

            if (window.jQuery && window.jQuery.fn.select2) {
                window.jQuery('.select2-sale').select2({
                        width: '100%',
                        placeholder: '-- Chưa gán --',
                        allowClear: true
                    })
                    .on('change', updateSaleInfo);
            } else {
                saleSelect.addEventListener('change', updateSaleInfo);
            }
            updateSaleInfo();

            /* ── Toast helper ── */
            const toastContainer = document.getElementById('seToastContainer');
            const showToast = (msg, type = 'success') => {
                const el = document.createElement('div');
                el.className = 'se-toast' + (type === 'danger' ? ' danger' : '');
                el.innerHTML = `<span class="se-toast-icon">${type === 'danger' ? '✕' : '✓'}</span>${String(msg)}`;
                toastContainer.appendChild(el);
                setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transition = 'opacity .3s';
                    setTimeout(() => el.remove(), 300);
                }, 2600);
            };

            /* ── Ajax stock update ── */
            document.querySelectorAll('.js-stock-update-form').forEach(form => {
                form.addEventListener('submit', async e => {
                    e.preventDefault();
                    const submitBtn = form.querySelector('.js-stock-submit-btn');
                    const stockInput = form.querySelector('.js-stock-input');
                    if (!submitBtn || !stockInput) return;

                    submitBtn.disabled = true;
                    stockInput.disabled = true;
                    const orig = submitBtn.textContent;
                    submitBtn.textContent = '...';

                    try {
                        const res = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new FormData(form),
                        });
                        let payload = null;
                        try {
                            payload = await res.json();
                        } catch {}

                        if (!res.ok) {
                            let msg = 'Cập nhật tồn kho thất bại.';
                            if (payload?.errors?.stock?.[0]) msg = payload.errors.stock[0];
                            else if (payload?.message) msg = payload.message;
                            showToast(msg, 'danger');
                            return;
                        }

                        const row = form.closest('tr');
                        const newStock = Number(stockInput.value || 0);
                        if (row) {
                            row.classList.toggle('out-of-stock', newStock === 0);
                            // Update badge
                            const existBadge = row.querySelector('.se-badge-danger');
                            if (newStock === 0 && !existBadge) {
                                const nameCell = row.querySelector('.se-product-name');
                                if (nameCell) {
                                    const b = document.createElement('span');
                                    b.className = 'se-badge se-badge-danger';
                                    b.style.marginLeft = '6px';
                                    b.textContent = 'Hết hàng';
                                    nameCell.after(b);
                                }
                            } else if (newStock > 0 && existBadge) {
                                existBadge.remove();
                            }
                        }
                        showToast(payload?.message ?? 'Cập nhật tồn kho thành công!');
                    } catch {
                        showToast('Không thể kết nối máy chủ. Vui lòng thử lại.', 'danger');
                    } finally {
                        submitBtn.disabled = false;
                        stockInput.disabled = false;
                        submitBtn.textContent = orig;
                    }
                });
            });

            /* ─── Realtime Pusher integration for store product approvals ─── */
            (function () {
                if (typeof Pusher === 'undefined') {
                    return;
                }

                var storeId = @json($store->id);
                var appKey = @json(config('broadcasting.connections.pusher.key'));
                var appCluster = @json(config('broadcasting.connections.pusher.options.cluster'));

                if (!appKey || !storeId) {
                    return;
                }

                Pusher.logToConsole = true;

                var pusher = new Pusher(appKey, {
                    cluster: appCluster || undefined,
                    forceTLS: true,
                    disableStats: true,
                });

                var replaceProductApprovalsTab = async () => {
                    try {
                        const response = await fetch(window.location.href, {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest',
                                'Cache-Control': 'no-cache',
                            },
                            cache: 'no-store',
                        });

                        if (!response.ok) {
                            return;
                        }

                        const html = await response.text();
                        const parser = new DOMParser();
                        const nextDocument = parser.parseFromString(html, 'text/html');

                        // 1. Replace the tab pane content
                        const currentPane = document.getElementById('pane-store-product-approvals');
                        const nextPane = nextDocument.getElementById('pane-store-product-approvals');
                        if (currentPane && nextPane) {
                            currentPane.innerHTML = nextPane.innerHTML;
                        }

                        // 2. Replace the tab badge
                        const currentBtn = document.querySelector('button[data-tab="pane-store-product-approvals"]');
                        const nextBtn = nextDocument.querySelector('button[data-tab="pane-store-product-approvals"]');
                        if (currentBtn && nextBtn) {
                            const currentBadge = currentBtn.querySelector('.se-tab-badge');
                            const nextBadge = nextBtn.querySelector('.se-tab-badge');
                            if (nextBadge) {
                                if (currentBadge) {
                                    currentBadge.innerText = nextBadge.innerText;
                                } else {
                                    const newBadge = document.createElement('span');
                                    newBadge.className = 'se-tab-badge';
                                    newBadge.innerText = nextBadge.innerText;
                                    currentBtn.appendChild(newBadge);
                                }
                            } else if (currentBadge) {
                                currentBadge.remove();
                            }
                        }
                    } catch (error) {
                        console.error('Unable to refresh product approvals tab', error);
                    }
                };

                let lastProcessedEventTime = 0;
                const processUpdate = (source, data) => {
                    console.log('Realtime product approval update event received via ' + source + ':', data);
                    const now = Date.now();
                    if (now - lastProcessedEventTime < 1000) {
                        console.log('Ignoring duplicate realtime event within 1s');
                        return;
                    }
                    lastProcessedEventTime = now;
                    replaceProductApprovalsTab();
                };

                // Subscribe to store-specific channel
                var storeChannel = pusher.subscribe('product-approvals.store.' + storeId);
                storeChannel.bind('product.approval.updated', function (data) {
                    processUpdate('store-channel', data);
                });

                // Subscribe to global channel as backup
                var globalChannel = pusher.subscribe('product-approvals');
                globalChannel.bind('product.approval.updated', function (data) {
                    if (data && String(data.store_id) === String(storeId)) {
                        processUpdate('global-channel', data);
                    }
                });

                window.addEventListener('beforeunload', function () {
                    storeChannel.unbind('product.approval.updated');
                    pusher.unsubscribe('product-approvals.store.' + storeId);

                    globalChannel.unbind('product.approval.updated');
                    pusher.unsubscribe('product-approvals');

                    pusher.disconnect();
                });
            })();

        })();
    </script>
