@extends('admin.layout')

@section('content')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Thêm cửa hàng</h2>
            <a href="{{ route('admin.stores.index') }}" class="btn btn-outline-secondary">Quay lại danh sách</a>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card">
            <div class="card-body">
                <form action="{{ route('admin.stores.store') }}" method="POST" class="row g-3">
                    @csrf

                    <div class="col-md-12">
                        <div class="alert alert-info mb-0">
                            Cửa hàng mới sẽ được tạo ở trạng thái đã duyệt ngay lập tức. Hãy chọn user quản trị để dùng đăng
                            nhập vào trang admin của cửa hàng.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">User quản trị <span class="text-danger">*</span></label>
                        <select name="owner_id" class="form-select" required>
                            <option value="">Chọn user</option>
                            @foreach ($users as $user)
                                @php
                                    $displayName = trim((string) ($user->full_name ?: $user->name ?: $user->email));
                                    $roleLabel = $user->role ? ' - ' . $user->role : '';
                                @endphp
                                <option value="{{ $user->id }}" {{ old('owner_id') == $user->id ? 'selected' : '' }}>
                                    {{ $displayName }}{{ $roleLabel }} ({{ $user->email }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Mã cửa hàng <span class="text-danger">*</span></label>
                        <input type="text" name="store_code" class="form-control"
                            value="{{ old('store_code', $defaultStoreCode ?? '') }}" required>
                    </div>

                    <div class="col-md-8">
                        <label class="form-label">Tên cửa hàng <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Địa chỉ <span class="text-danger">*</span></label>
                        <input type="text" name="address" id="address" class="form-control"
                            value="{{ old('address') }}" required>
                        <small class="text-muted d-block mt-1">Nhập địa chỉ rồi bấm "Tìm trên bản đồ" hoặc click trực tiếp
                            trên bản đồ.</small>
                    </div>

                    <div class="col-12">
                        <div class="d-flex gap-2 mb-2">
                            <button type="button" id="btnGeocodeAddress" class="btn btn-outline-primary btn-sm">Tìm trên
                                bản đồ</button>
                            <button type="button" id="btnUseCurrentLocation" class="btn btn-outline-secondary btn-sm">Lấy
                                vị trí hiện tại</button>
                        </div>
                        <div id="storeMap" style="height: 320px; border: 1px solid #dee2e6; border-radius: 8px;"></div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Vĩ độ</label>
                        <input type="text" name="latitude" id="latitude" class="form-control"
                            value="{{ old('latitude') }}" placeholder="Ví dụ: 10.762622">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Kinh độ</label>
                        <input type="text" name="longitude" id="longitude" class="form-control"
                            value="{{ old('longitude') }}" placeholder="Ví dụ: 106.660172">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Số điện thoại</label>
                        <input type="text" name="phone" class="form-control" value="{{ old('phone') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="{{ old('email') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Người đại diện</label>
                        <input type="text" name="representative" class="form-control"
                            value="{{ old('representative') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Loại hình kinh doanh</label>
                        <select name="business_type" class="form-select">
                            <option value="">Chọn loại hình kinh doanh</option>
                            @foreach ($businessTypes as $businessType)
                                <option value="{{ $businessType->name }}" {{ old('business_type') === $businessType->name ? 'selected' : '' }}>
                                    {{ $businessType->name }}
                                </option>
                            @endforeach
                            @if (old('business_type') && ! $businessTypes->contains('name', old('business_type')))
                                <option value="{{ old('business_type') }}" selected>{{ old('business_type') }}</option>
                            @endif
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tên ngân hàng</label>
                        <input type="text" name="bank_name" class="form-control" value="{{ old('bank_name') }}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Số tài khoản</label>
                        <input type="text" name="bank_account" class="form-control"
                            value="{{ old('bank_account') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Giờ mở cửa</label>
                        <input type="time" name="open_time" class="form-control"
                            value="{{ old('open_time', '09:00') }}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Giờ đóng cửa</label>
                        <input type="time" name="close_time" class="form-control"
                            value="{{ old('close_time', '21:00') }}">
                    </div>

                    <div class="col-md-4 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" value="1" id="is_open"
                                name="is_open" {{ old('is_open', '1') ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_open">
                                Cửa hàng đang mở bán
                            </label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Mô tả</label>
                        <textarea name="description" rows="4" class="form-control" placeholder="Mô tả ngắn về cửa hàng">{{ old('description') }}</textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Lưu cửa hàng</button>
                        <a href="{{ route('admin.stores.index') }}" class="btn btn-outline-secondary">Hủy</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        (function() {
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            const addressInput = document.getElementById('address');
            const geocodeBtn = document.getElementById('btnGeocodeAddress');
            const currentLocationBtn = document.getElementById('btnUseCurrentLocation');

            const defaultLat = parseFloat(latInput.value || '10.7769');
            const defaultLng = parseFloat(lngInput.value || '106.7009');

            L.tileLayer(
                'https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png'
            ).addTo(map);

            let marker = L.marker([defaultLat, defaultLng]).addTo(map);

            const setLatLng = (lat, lng) => {
                latInput.value = Number(lat).toFixed(6);
                lngInput.value = Number(lng).toFixed(6);
                marker.setLatLng([lat, lng]);
                map.panTo([lat, lng]);
            };

            const reverseGeocode = async (lat, lng) => {
                try {
                    const url =
                        `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}`;
                    const response = await fetch(url, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    if (!response.ok) return;
                    const data = await response.json();
                    if (data && data.display_name && !addressInput.value.trim()) {
                        addressInput.value = data.display_name;
                    }
                } catch (error) {
                    console.warn('Reverse geocode error:', error);
                }
            };

            map.on('click', function(e) {
                const {
                    lat,
                    lng
                } = e.latlng;
                setLatLng(lat, lng);
                reverseGeocode(lat, lng);
            });

            geocodeBtn.addEventListener('click', async function() {
                const query = addressInput.value.trim();
                if (!query) {
                    alert('Vui lòng nhập địa chỉ trước khi tìm trên bản đồ.');
                    return;
                }

                try {
                    const url =
                        `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=${encodeURIComponent(query)}`;
                    const response = await fetch(url, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    if (!response.ok) throw new Error('Không tìm được địa chỉ');
                    const data = await response.json();
                    if (!Array.isArray(data) || data.length === 0) {
                        alert('Không tìm thấy vị trí phù hợp cho địa chỉ này.');
                        return;
                    }

                    const lat = parseFloat(data[0].lat);
                    const lng = parseFloat(data[0].lon);
                    setLatLng(lat, lng);
                    if (data[0].display_name) {
                        addressInput.value = data[0].display_name;
                    }
                } catch (error) {
                    alert('Có lỗi khi tìm địa chỉ trên bản đồ. Vui lòng thử lại.');
                }
            });

            currentLocationBtn.addEventListener('click', function() {
                if (!navigator.geolocation) {
                    alert('Trình duyệt không hỗ trợ lấy vị trí hiện tại.');
                    return;
                }

                navigator.geolocation.getCurrentPosition(function(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    setLatLng(lat, lng);
                    reverseGeocode(lat, lng);
                }, function() {
                    alert('Không thể lấy vị trí hiện tại. Vui lòng kiểm tra quyền truy cập vị trí.');
                });
            });

            latInput.addEventListener('change', function() {
                const lat = parseFloat(latInput.value);
                const lng = parseFloat(lngInput.value);
                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                    setLatLng(lat, lng);
                }
            });

            lngInput.addEventListener('change', function() {
                const lat = parseFloat(latInput.value);
                const lng = parseFloat(lngInput.value);
                if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
                    setLatLng(lat, lng);
                }
            });
        })();
    </script>
@endsection
