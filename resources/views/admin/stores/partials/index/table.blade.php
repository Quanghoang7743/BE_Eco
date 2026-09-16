        {{-- ── Main card ── --}}
        <div class="si-card">

            {{-- DESKTOP: table --}}
            <div class="si-table-wrap">
                <table class="si-table" id="storesTable">
                    <thead>
                        <tr>
                            <th class="no-sort si-col-check" style="text-align:center;">
                                <input type="checkbox" id="selectAllStores">
                            </th>
                            <th class="si-col-code" data-col="1">Mã CH<span class="sort-icon"></span></th>
                            <th class="si-col-name" data-col="2">Tên cửa hàng<span class="sort-icon"></span></th>
                            <th class="si-col-phone" data-col="3">SĐT<span class="sort-icon"></span></th>
                            {{-- <th class="si-col-email"   data-col="4">Email<span class="sort-icon"></span></th> --}}
                            <th class="si-col-addr" data-col="5">Địa chỉ<span class="sort-icon"></span></th>
                            <th class="si-col-sale" data-col="6">Chuyên viên<span class="sort-icon"></span></th>
                            <th class="si-col-pending" data-col="7" style="text-align:center;">SP chờ<span
                                    class="sort-icon"></span></th>
                            <th class="si-col-status" data-col="8" style="text-align:center;">Trạng thái<span
                                    class="sort-icon"></span></th>
                            <th class="no-sort si-col-action" style="text-align:center;">Hành động</th>
                        </tr>
                    </thead>
                    <tbody id="storesTableBody">
                        <tr>
                            <td colspan="10">
                                <div class="si-processing"><i class="fas fa-circle-notch fa-spin"
                                        style="margin-right:8px;"></i>Đang tải...</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- MOBILE: card list --}}
            <div class="si-card-list" id="storesCardList">
                <div class="si-processing"><i class="fas fa-circle-notch fa-spin" style="margin-right:8px;"></i>Đang
                    tải...
                </div>
            </div>

            {{-- Footer --}}
            <div class="si-table-footer">
                <div class="si-length-wrap">
                    <span class="si-length-label">Hiển thị</span>
                    <select id="siLengthSelect" class="si-length-select">
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="si-length-label">mục</span>
                </div>
                <div class="si-info" id="siTableInfo">—</div>
                <div class="si-pagination" id="siPagination"></div>
            </div>
        </div>
