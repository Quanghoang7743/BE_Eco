{{-- RESET PASSWORD MODAL --}}
<div class="modal fade" id="resetAdminModal" tabindex="-1">

    <div class="modal-dialog">

        <form method="POST" id="resetAdminForm">

            @csrf

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title">

                        Reset mật khẩu

                    </h5>

                    <button class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    Bạn muốn reset mật khẩu admin này?

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                        Hủy

                    </button>

                    <button class="btn btn-warning">

                        Reset

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>



{{-- DELETE MODAL --}}

<div class="modal fade" id="deleteAdminModal" tabindex="-1">

    <div class="modal-dialog">

        <form method="POST" id="deleteAdminForm">

            @csrf
            @method('DELETE')

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title">

                        Xóa admin

                    </h5>

                    <button class="btn-close" data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    Xác nhận xóa admin này?

                </div>

                <div class="modal-footer">

                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">

                        Hủy

                    </button>

                    <button class="btn btn-danger">

                        Xóa

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>
<script>
    document.addEventListener('DOMContentLoaded', () => {

        const resetModal =
            new bootstrap.Modal(
                document.getElementById('resetAdminModal')
            );

        const deleteModal =
            new bootstrap.Modal(
                document.getElementById('deleteAdminModal')
            );



        document.querySelectorAll('.edit-admin-btn')
            .forEach(btn => {

                btn.onclick = () => {

                    location.href =
                        btn.dataset.url;

                }

            });



        document.querySelectorAll('.reset-admin-btn')
            .forEach(btn => {

                btn.onclick = () => {

                    document
                        .getElementById('resetAdminForm')
                        .action =
                        btn.dataset.action;

                    resetModal.show();

                }

            });



        document.querySelectorAll('.delete-admin-btn')
            .forEach(btn => {

                btn.onclick = () => {

                    document
                        .getElementById('deleteAdminForm')
                        .action =
                        btn.dataset.action;

                    deleteModal.show();

                }

            });

    });
</script>
