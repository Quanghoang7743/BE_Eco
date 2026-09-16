@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Thêm mới sản phẩm</h1>

    @if (!empty($selectedStore))
        <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">

            <div>

                Đang thêm sản phẩm cho cửa hàng:

                <strong>

                    {{ $selectedStore->name }}

                </strong>

                <span class="text-muted">

                    ({{ $selectedStore->store_code }})

                </span>

            </div>

            <a href="{{ route('admin.stores.edit', $selectedStore->id) }}" class="btn btn-sm btn-outline-primary">

                Quay lại sửa cửa hàng

            </a>

        </div>
    @endif


    <form action="{{ route('admin.products.store') }}" method="POST" enctype="multipart/form-data">

        @csrf


        @if (!empty($selectedStore))
            <input type="hidden" name="store_id" value="{{ $selectedStore->id }}">

            <input type="hidden" name="active_tab" value="store-product-approvals">
        @endif


        <div class="row">


            <div class="col-md-8">


                <div class="card mb-3">

                    <div class="card-header">

                        Thông tin cơ bản

                    </div>

                    <div class="card-body">


                        <div class="mb-3">

                            <label class="form-label">

                                Tên sản phẩm

                            </label>

                            <input type="text" name="name" required value="{{ old('name') }}"
                                class="form-control @error('name') is-invalid @enderror">

                            @error('name')
                                <div class="invalid-feedback">

                                    {{ $message }}

                                </div>
                            @enderror

                        </div>



                        <div class="mb-3 d-none">

                            <label class="form-label">

                                Nội dung tóm tắt

                            </label>

                            <textarea name="summary" class="form-control @error('summary') is-invalid @enderror">{{ old('summary') }}</textarea>

                            @error('summary')
                                <div class="invalid-feedback">

                                    {{ $message }}

                                </div>
                            @enderror

                        </div>



                        <div class="mb-3">

                            <label class="form-label">

                                Nội dung chi tiết

                            </label>

                            <textarea id="content" name="content" class="form-control @error('content') is-invalid @enderror">{{ old('content') }}</textarea>

                            @error('content')
                                <div class="invalid-feedback">

                                    {{ $message }}

                                </div>
                            @enderror

                        </div>


                        <div class="row">


                            <div class="col-md-6 d-none">

                                <div class="mb-3">

                                    <label>

                                        SKU

                                    </label>

                                    <input type="text" name="sku" value="{{ old('sku') }}" class="form-control">

                                </div>

                            </div>



                            <div class="col-md-6 d-none">

                                <div class="mb-3">

                                    <label>

                                        Slug

                                    </label>

                                    <input type="text" name="slug" value="{{ old('slug') }}" class="form-control">

                                </div>

                            </div>


                        </div>


                        <div class="row">


                            <div class="col-md-6">

                                <div class="d-flex align-items-center justify-content-between mb-2">

                                    <div class="fw-semibold">

                                        Danh mục

                                    </div>

                                    <button type="button" class="btn btn-sm btn-warning px-3" id="open-category-modal">

                                        <i class="fas fa-plus"></i>

                                        Thêm

                                    </button>

                                </div>

                                <select required name="category_id" id="categorySelect" class="form-select">

                                    <option value="">-- chọn --</option>

                                    @foreach ($categories as $cat)
                                        <option value="{{ $cat->id }}"
                                            {{ old('category_id') == $cat->id ? 'selected' : '' }}>

                                            {{ $cat->name }}

                                        </option>
                                    @endforeach

                                </select>

                            </div>


                            <div class="col-md-6">

                                <label>

                                    Hoa hồng CTV (%)

                                </label>

                                <input type="number" step="0.01" name="commission_rate"
                                    value="{{ old('commission_rate') }}" class="form-control">

                            </div>


                        </div>

                    </div>

                </div>



                <div class="card">

                    <div class="card-header d-flex align-items-center justify-content-between">

                        <h5 class="mb-0">
                            Lựa chọn đi kèm
                        </h5>

                        <button type="button" class="btn btn-sm btn-warning" id="add-product-topping">
                            <i class="fas fa-plus me-1"></i>
                            Thêm lựa chọn
                        </button>

                    </div>

                    <div class="card-body">

                        <div id="product-toppings-wrapper"></div>





                        <div id="product-topping-template" class="d-none">
                            <div class="product-topping-row">
                                <div class="row mb-3">

                                    <div class="col-md-5">
                                        <input type="text" class="form-control topping-name" placeholder="Tên topping">
                                    </div>

                                    <div class="col-md-5">
                                        <input type="number" class="form-control topping-price" placeholder="Giá topping">
                                    </div>

                                    <div class="col-md-2">
                                        <button type="button" class="btn btn-danger remove-product-topping">

                                            <i class="fas fa-trash"></i>

                                        </button>
                                    </div>

                                </div>
                            </div>
                        </div>

                    </div>

                </div>










            </div>



            <div class="col-md-4">


                <div class="card">

                    <div class="card-header">

                        Ảnh + Giá

                    </div>

                    <div class="card-body">


                        <label>

                            Giá niêm yết

                        </label>

                        <input type="number" name="price" value="{{ old('price') }}" class="form-control mb-3">



                        <label>

                            Giá bán

                        </label>

                        <input type="number" required name="sale_price" value="{{ old('sale_price') }}"
                            class="form-control mb-3">



                        <label>

                            Stock

                        </label>

                        <input type="number" name="stock" value="{{ old('stock') }}" class="form-control mb-3">


                        <label>

                            Thumbnail

                        </label>

                        <input type="file" name="thumbnail" class="form-control mb-3">


                        <label>

                            Images

                        </label>

                        <input multiple type="file" name="images[]" class="form-control">


                    </div>

                </div>

                <div class="card mb-3">

                    <div class="card-header">

                        Ticket / Reward

                    </div>


                    <div class="card-body">

                        <div class="row">





                            <div class="col-md-6">
                                <label>
                                    Số vé reward
                                </label>

                                <input type="number" name="ticket_reward_quantity" value="1" min="0"
                                    class="form-control">

                            </div>



                            <div class="col-md-6">

                                <label>

                                    Loại vé

                                </label>

                                <select name="ticket_type_code" id="ticket_type_code" class="form-select">

                                    <option value="">
                                        -- chọn --
                                    </option>
                                    @foreach ($ticketTypes as $ticket)
                                        <option value="{{ $ticket->code }}"
                                            data-price="{{ $ticket->ticket_value ?? 0 }}">

                                            {{ $ticket->name }}
                                            -
                                            {{ number_format($ticket->ticket_value ?? 0) }}đ

                                        </option>
                                    @endforeach

                                </select>

                            </div>

                        </div>

                    </div>

                </div>


            </div>

        </div>



        <hr>

        <h4>

            Biến thể

        </h4>

        <div id="attributes-wrapper"></div>


        <button type="button" class="btn btn-success mb-3" id="add-attribute">

            + Thêm thuộc tính

        </button>


        <button type="button" class="btn btn-primary mb-3" id="generate-combinations">

            Sinh tổ hợp

        </button>



        <table class="table table-bordered" id="variants-table">

            <thead>

                <tr>

                    <th>Biến thể</th>

                    <th>Price</th>

                    <th>Sale</th>

                    <th>Commission</th>

                    <th>Reward</th>

                    <th>Ticket</th>

                    <th>Stock</th>

                    <th>Image</th>

                </tr>

            </thead>

            <tbody></tbody>

        </table>


        <button type="submit" class="btn btn-success">

            Lưu sản phẩm

        </button>


        <a href="{{ !empty($selectedStore)
            ? route('admin.stores.edit', [
                'store' => $selectedStore->id,
                'active_tab' => 'store-products',
            ])
            : route('admin.stores.index') }}"
            class="btn btn-secondary">

            Quay lại

        </a>

    </form>
    @include('admin.categories._modal_create')



    <div id="attribute-template" class="d-none">

        <div class="attribute-row">

            <div class="row mb-3">

                <div class="col-4">

                    <select class="attribute-name form-select">

                        <option value="">

                            -- chọn thuộc tính --

                        </option>

                        @foreach ($attributes as $attribute)
                            <option value="{{ $attribute->id }}">

                                {{ $attribute->name }}

                            </option>
                        @endforeach

                        <option value="addAttibute">

                            Thuộc tính khác

                        </option>

                    </select>

                </div>



                <div class="col-8 d-flex gap-2">

                    <input class="attribute-values form-control" placeholder="Đỏ,Xanh,Vàng">

                    <button type="button" class="btn btn-danger remove-attribute">

                        <i class="fas fa-trash"></i>

                    </button>

                </div>

            </div>

        </div>

    </div>

    <div class="modal fade" id="addAttributeModal" tabindex="-1" aria-labelledby="addAttributeModalLabel"
        aria-hidden="true">

        <div class="modal-dialog">

            <form id="add-attribute-form">

                @csrf
                <input type="hidden" name="store_id" value="{{ $selectedStore->id ?? '' }}">
                <div class="modal-content">

                    <div class="modal-header">

                        <h5 class="modal-title">

                            Thêm thuộc tính mới

                        </h5>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng">

                        </button>

                    </div>

                    <div class="modal-body">

                        <input type="text" class="form-control" name="attribute_name" id="newAttributeName"
                            placeholder="Nhập tên thuộc tính">

                    </div>

                    <div class="modal-footer">

                        <button type="submit" class="btn btn-primary">

                            <i class="far fa-save"></i>

                            Thêm

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>



    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>


    <script>
        document.addEventListener(

            'DOMContentLoaded',

            function() {
                document.getElementById('add-product-topping')
                    .addEventListener('click', function() {

                        const wrapper =
                            document.getElementById(
                                'product-toppings-wrapper'
                            );

                        const index =
                            wrapper.querySelectorAll(
                                '.product-topping-row'
                            ).length;

                        const clone =
                            document.getElementById(
                                'product-topping-template'
                            )
                            .firstElementChild
                            .cloneNode(true);

                        clone.querySelector('.topping-name')
                            .name =
                            `toppings[${index}][name]`;

                        clone.querySelector('.topping-price')
                            .name =
                            `toppings[${index}][sale_price]`;

                        wrapper.appendChild(clone);

                    });
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.remove-product-topping')) {
                        e.target.closest('.product-topping-row').remove();
                    }
                });
                const attributesWrapper =
                    document.getElementById(
                        'attributes-wrapper'
                    );

                const attributeTemplate =
                    document.getElementById(
                        'attribute-template'
                    );




                function attachAttributeEvents(
                    row
                ) {

                    const select =
                        row.querySelector(
                            '.attribute-name'
                        );

                    select.addEventListener(

                        'change',

                        function() {

                            const selected =
                                this.value;

                            if (
                                selected === "addAttibute"
                            ) {

                                new bootstrap.Modal(

                                    document.getElementById(
                                        'addAttributeModal'
                                    )

                                ).show();

                            }

                        }

                    );

                }



                document
                    .getElementById(
                        'add-attribute'
                    )

                    .addEventListener(

                        'click',

                        function() {

                            const clone =
                                attributeTemplate
                                .firstElementChild
                                .cloneNode(
                                    true
                                );

                            attributesWrapper
                                .appendChild(
                                    clone
                                );

                            attachAttributeEvents(
                                clone
                            );

                        }

                    );




                document.addEventListener(

                    'click',

                    function(e) {

                        if (
                            e.target.closest(
                                '.remove-attribute'
                            )
                        ) {

                            const row =
                                e.target.closest(
                                    '.attribute-row'
                                );

                            if (
                                row
                            ) {

                                row.remove();

                            }

                        }

                    }

                );




                document
                    .querySelector(
                        '.btn-close'
                    )

                    .addEventListener(

                        'click',

                        function() {

                            document
                                .querySelectorAll(
                                    '.attribute-name'
                                )

                                .forEach(

                                    function(select) {

                                        select.value = '';

                                    }

                                )

                        }

                    );



                document
                    .getElementById(
                        'generate-combinations'
                    )

                    .addEventListener(

                        'click',

                        function() {


                            const attributes = [];


                            document
                                .querySelectorAll(
                                    '.attribute-row'
                                )

                                .forEach(

                                    row => {

                                        const id =
                                            row
                                            .querySelector(
                                                '.attribute-name'
                                            )
                                            .value;

                                        const values =
                                            row
                                            .querySelector(
                                                '.attribute-values'
                                            )
                                            .value
                                            .split(',')
                                            .map(
                                                v => v.trim()
                                            )
                                            .filter(
                                                v => v
                                            );

                                        if (
                                            id &&
                                            values.length
                                        ) {

                                            attributes.push({

                                                id,
                                                values

                                            });

                                        }

                                    }

                                );



                            if (attributes.length === 0) {

                                alert(
                                    'Vui lòng thêm ít nhất 1 thuộc tính'
                                );

                                return;

                            }

                            const combinations =
                                generateCombinations(

                                    attributes.map(
                                        a => a.values
                                    )

                                );



                            const attributeIds =
                                attributes.map(
                                    a => a.id
                                );


                            const tbody =
                                document.querySelector(
                                    '#variants-table tbody'
                                );

                            tbody.innerHTML = '';



                            combinations.forEach(

                                (combo, index) => {


                                    let hiddenInputs = '';


                                    combo.forEach(

                                        (val, i) => {


                                            hiddenInputs += `

<input
type="hidden"
name="variants[${index}][attributes][${i}][attribute_id]"
value="${attributeIds[i]}">

`;


                                            hiddenInputs += `

<input
type="hidden"
name="variants[${index}][attributes][${i}][value]"
value="${val}">

`;

                                        }

                                    );



                                    const row =
                                        document.createElement(
                                            'tr'
                                        );


                                    row.innerHTML = `

<td>

${combo.join(', ')}

${hiddenInputs}

</td>


<td>

<input
type="number"
name="variants[${index}][price]"
class="form-control">

</td>


<td>

<input
type="number"
name="variants[${index}][sale_price]"
class="form-control">

</td>


<td>

<input
type="number"
step="0.01"
name="variants[${index}][commission_rate]"
class="form-control">

</td>


<td>

<input
type="number"
name="variants[${index}][ticket_reward_quantity]"
class="form-control">

</td>


<td>

<select
name="variants[${index}][ticket_type_code]"
class="form-select">

<option value="">

---

</option>

@foreach ($ticketTypes as $ticket)

<option value="{{ $ticket->code }}">

{{ $ticket->name }} - {{ number_format($ticket->ticket_value ?? 0) }}đ

</option>

@endforeach

</select>

</td>


<td>

<input
type="number"
name="variants[${index}][stock]"
class="form-control">

</td>


<td>

<input
type="file"
name="variants[${index}][image]"
class="form-control">

</td>

`;

                                    tbody.appendChild(
                                        row
                                    );

                                }

                            );

                        }

                    );




                function generateCombinations(
                    arrays
                ) {

                    return arrays.reduce(

                        (acc, curr) => {

                            const result = [];

                            acc.forEach(

                                a => {

                                    curr.forEach(

                                        b => {

                                            result.push(
                                                a.concat(
                                                    [b]
                                                )
                                            );

                                        }

                                    );

                                }

                            );

                            return result;

                        },

                        [
                            []
                        ]

                    );

                }


                const addAttributeForm =
                    document.getElementById(
                        'add-attribute-form'
                    );

                addAttributeForm.addEventListener(

                    'submit',

                    async function(e) {

                        e.preventDefault();

                        const formData =
                            new FormData(this);

                        const btn =
                            this.querySelector(
                                'button[type=submit]'
                            );

                        btn.disabled = true;

                        try {

                            const response =
                                await fetch(

                                    "{{ route('admin.attributes.store') }}",

                                    {

                                        method: 'POST',

                                        headers: {

                                            'X-CSRF-TOKEN': document.querySelector(
                                                'meta[name="csrf-token"]'
                                            ).content,

                                            'Accept': 'application/json'

                                        },

                                        body: formData

                                    }

                                );

                            const data =
                                await response.json();


                            if (data.success) {

                                document
                                    .querySelectorAll('.attribute-name')
                                    .forEach(select => {

                                        /* tránh thêm trùng */

                                        const existed =
                                            select.querySelector(
                                                `option[value="${data.attribute.id}"]`
                                            );

                                        if (!existed) {

                                            const option =
                                                document.createElement(
                                                    'option'
                                                );

                                            option.value =
                                                data.attribute.id;

                                            option.text =
                                                data.attribute.name;

                                            /* insert trước option "Thuộc tính khác" */

                                            select.insertBefore(

                                                option,

                                                select.lastElementChild

                                            );

                                        }

                                        /* select luôn thuộc tính vừa tạo */

                                        select.value =
                                            data.attribute.id;

                                    });

                                document
                                    .getElementById(
                                        'newAttributeName'
                                    )
                                    .value = '';

                                bootstrap.Modal
                                    .getInstance(

                                        document.getElementById(
                                            'addAttributeModal'
                                        )

                                    )
                                    .hide();

                            }

                        } catch (err) {

                            console.error(
                                err
                            );

                            alert(
                                'Có lỗi xảy ra'
                            );

                        }

                        btn.disabled = false;

                    }

                );
                document
                    .getElementById('open-category-modal')
                    .addEventListener(
                        'click',
                        function() {

                            new bootstrap.Modal(
                                document.getElementById(
                                    'addCategoryModal'
                                )
                            ).show();

                        }
                    );


                document
                    .getElementById(
                        'add-category-form'
                    )
                    .addEventListener(
                        'submit',
                        async function(e) {

                            e.preventDefault();

                            const formData =
                                new FormData(this);

                            const btn =
                                this.querySelector(
                                    'button[type=submit]'
                                );

                            btn.disabled = true;

                            try {

                                const response =
                                    await fetch(
                                        "{{ route('admin.product-categories.ajax-store') }}", {
                                            method: 'POST',

                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector(
                                                    'meta[name="csrf-token"]'
                                                ).content,

                                                'Accept': 'application/json'
                                            },

                                            body: formData
                                        }
                                    );

                                const data =
                                    await response.json();

                                if (data.success) {

                                    const select =
                                        document.getElementById(
                                            'categorySelect'
                                        );

                                    const option =
                                        document.createElement(
                                            'option'
                                        );

                                    option.value =
                                        data.category.id;

                                    option.text =
                                        data.category.name;

                                    select.appendChild(
                                        option
                                    );

                                    select.value =
                                        data.category.id;

                                    document.getElementById(
                                        'newCategoryName'
                                    ).value = '';

                                    bootstrap.Modal
                                        .getInstance(
                                            document.getElementById(
                                                'addCategoryModal'
                                            )
                                        )
                                        .hide();

                                }

                            } catch (err) {

                                console.error(err);

                                alert(
                                    'Có lỗi xảy ra'
                                );

                            }

                            btn.disabled = false;

                        }
                    );
            }


        );




        ClassicEditor

            .create(

                document.querySelector(
                    '#content'
                ),

                {

                    ckfinder: {

                        uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'

                    }

                }

            )

            .then(

                editor => {

                    editor.editing.view.change(

                        writer => {

                            writer.setStyle(

                                'min-height',

                                '300px',

                                editor.editing.view.document.getRoot()

                            );

                        }

                    );

                }

            )

            .catch(

                error => {

                    console.error(
                        error
                    );

                }

            );
    </script>
@endsection
