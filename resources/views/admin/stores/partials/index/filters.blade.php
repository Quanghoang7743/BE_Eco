        {{-- ── Filters ── --}}
        <div class="si-filters">
            <div class="si-field">

                <label class="si-label">

                    Tìm kiếm

                </label>

                <div class="si-input-wrap">

                    <i class="fas fa-search si-input-icon"></i>

                    <input id="storeSearch" class="si-input" placeholder="Tên,mã,SĐT,email...">

                </div>

            </div>


            <div class="si-field">

                <label class="si-label">

                    Trạng thái

                </label>

                <select id="storeStatusFilter" class="si-select">

                    <option value="all">

                        Tất cả

                    </option>

                    <option value="active">

                        Hoạt động

                    </option>

                    <option value="suspended">

                        Tạm ngưng

                    </option>

                    <option value="pending">

                        Chờ duyệt

                    </option>

                    <option value="rejected">

                        Từ chối

                    </option>

                </select>

            </div>


            <div class="si-field">

                <label class="si-label">

                    Khu vực

                </label>

                <input id="storeRegionFilter" class="si-input" placeholder="Tên hoặc mã KV">

            </div>


            @if ($canManageStores)
                <div class="si-field">

                    <label class="si-label">
                        Chuyên viên
                    </label>

                    <select id="saleFilter" class="si-select">
                        <option value="0">
                            Tất cả
                        </option>

                        <option value="unassigned">
                            Chưa gán
                        </option>
                        @php
                            $saleUsers = \App\Models\User::where('role', 'sale')
                                ->orderBy('full_name')
                                ->orderBy('name')
                                ->get();
                        @endphp
                        @foreach ($saleUsers as $sale)
                            <option value="{{ $sale->id }}">

                                {{ $sale->full_name ?: $sale->name }}

                            </option>
                        @endforeach

                    </select>

                </div>
            @endif


            <div class="si-field">

                <label class="si-label">

                    Quick filters

                </label>

                <label>

                    <input type="checkbox" id="noOrdersFilter">

                    Chưa có đơn

                </label>

                <label>

                    <input type="checkbox" id="heldTicketsFilter">

                    Ticket treo

                </label>

            </div>


            <div class="si-field">

                <label class="si-label">

                    &nbsp;

                </label>

                <div style="display:flex;gap:8px">

                    <button id="resetStoreFilters" class="si-btn">

                        Reset

                    </button>

                    @if ($canManageStores)
                        <button id="deleteSelectedStores" class="si-btn si-btn-danger" disabled>

                            <i class="fas fa-trash"></i>

                            <span id="deleteBtnText">

                                Xóa đã chọn

                            </span>

                        </button>
                    @endif

                </div>

            </div>
        </div>
