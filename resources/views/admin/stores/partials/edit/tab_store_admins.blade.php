            {{-- ────────────────────────────────────────────────────────
     TAB 5 — Admin cửa hàng
──────────────────────────────────────────────────────────── --}}
            <div class="se-tab-pane {{ $activeTab === 'store-admins' ? 'active' : '' }}" id="pane-store-admins"
                role="tabpanel">

                <div class="se-table-card">

                    <table class="se-table">

                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Admin</th>
                                <th>SĐT</th>
                                <th>Email</th>
                                <th>Trạng thái</th>
                                <th>Quyền</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>

                        <tbody>

                            @forelse($storeAdmins as $admin)
                                <tr>

                                    <td>{{ $admin->id }}</td>

                                    <td>

                                        <div class="se-product-name">
                                            {{ $admin->full_name ?? $admin->name }}
                                        </div>

                                        <div class="se-product-sku">
                                            username: {{ $admin->username }}
                                        </div>

                                    </td>

                                    <td>
                                        {{ $admin->phone ?? 'N/A' }}
                                    </td>

                                    <td>
                                        {{ $admin->email ?? 'N/A' }}
                                    </td>

                                    <td>

                                        @if ($admin->is_active)
                                            <span class="se-badge se-badge-success">
                                                Hoạt động
                                            </span>
                                        @else
                                            <span class="se-badge se-badge-danger">
                                                Khóa
                                            </span>
                                        @endif

                                    </td>

                                    <td>

                                        <span class="se-badge se-badge-warn">

                                            {{ $admin->role ?? 'Store Admin' }}

                                        </span>

                                    </td>

                                    <td>

                                        <div style="display:flex;gap:6px;flex-wrap:wrap">

                                            <button class="se-btn se-btn-warn se-btn-sm reset-admin-btn"
                                                data-action="{{ route('admin.store-admins.reset-password', [
                                                    'userId' => $admin->id,
                                                    'storeId' => $store->id,
                                                ]) }}">
                                                reset PW
                                            </button>

                                            <button class="se-btn se-btn-danger se-btn-sm delete-admin-btn"
                                                data-action="{{ route('admin.store-admins.destroy', [
                                                    'userId' => $admin->id,
                                                    'storeId' => $store->id,
                                                ]) }}">

                                                Xóa

                                            </button>

                                        </div>

                                    </td>

                                </tr>

                            @empty

                                <tr>

                                    <td colspan="7">

                                        <div class="se-empty">

                                            <span class="se-empty-icon">
                                                👤
                                            </span>

                                            Chưa có admin cửa hàng

                                        </div>

                                    </td>

                                </tr>
                            @endforelse

                        </tbody>

                    </table>

                </div>

            </div>
