    <div class="modal fade" id="createStoreAdminModal" tabindex="-1">

        <div class="modal-dialog">

            <div class="modal-content">

                <form method="POST" action="{{ route('admin.store-admins.store') }}">

                    @csrf

                    <input type="hidden" name="store_id" value="{{ $store->id }}">

                    <div class="modal-header">

                        <h5 class="modal-title">
                            Thêm admin cửa hàng
                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal">
                        </button>

                    </div>

                    <div class="modal-body">

                        <div class="mb-3">

                            <label>Tên</label>

                            <input class="form-control" name="name" required>

                        </div>

                        <div class="mb-3">

                            <label>Email</label>

                            <input class="form-control" type="email" name="email" required>

                        </div>

                        <div class="mb-3">

                            <label>Mật khẩu</label>

                            <input class="form-control" type="password" name="password" required>

                        </div>

                    </div>

                    <div class="modal-footer">

                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                            Đóng

                        </button>

                        <button class="btn btn-primary" type="submit">

                            Tạo admin

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>
