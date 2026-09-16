<div class="modal fade"
     id="addCategoryModal"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog">

        <form id="add-category-form">

            @csrf

            <input type="hidden"
                   name="store_id"
                   value="{{ $selectedStore->id ?? '' }}">

            <div class="modal-content">

                <div class="modal-header">

                    <h5 class="modal-title">

                        Thêm danh mục

                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal">

                    </button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label>Tên danh mục</label>

                        <input class="form-control"
                               name="name"
                               id="newCategoryName">

                    </div>

                </div>

                <div class="modal-footer">

                    <button class="btn btn-primary"
                            type="submit">

                        Thêm

                    </button>

                </div>

            </div>

        </form>

    </div>

</div>