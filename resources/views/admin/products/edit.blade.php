@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Chỉnh sửa sản phẩm</h1>

    @if (!empty($selectedStore))
        <div class="alert alert-info d-flex justify-content-between align-items-center">

            <div>
                Sản phẩm thuộc:
                <strong>{{ $selectedStore->name }}</strong>
                ({{ $selectedStore->store_code }})
            </div>

            <a href="{{ route('admin.stores.edit', $selectedStore->id) }}" class="btn btn-outline-primary btn-sm">

                Quay lại cửa hàng

            </a>

        </div>
    @endif


    <form action="{{ route('admin.products.update', $product->id) }}" method="POST" enctype="multipart/form-data">

        @csrf
        @method('PUT')

        @if (!empty($selectedStore))
            <input type="hidden" name="store_id" value="{{ $selectedStore->id }}">
        @endif


        <div class="row">

            <div class="col-md-8">

                <div class="card mb-3">

                    <div class="card-header">

                        Thông tin cơ bản

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <label>Tên sản phẩm</label>

                            <input type="text" name="name" required class="form-control"
                                value="{{ old('name', $product->name) }}">

                        </div>


                        <div class="mb-3">

                            <label>Nội dung chi tiết</label>

                            <textarea id="content" name="content" class="form-control">{{ old('content', $product->content) }}</textarea>

                        </div>




                    </div>

                </div>


                <div class="card">

                    <div class="card-header d-flex justify-content-between">

                        <h5>Lựa chọn đi kèm</h5>

                        <button type="button" class="btn btn-warning btn-sm" id="add-product-topping">

                            Thêm lựa chọn

                        </button>

                    </div>

                    <div class="card-body">

                        <div id="product-toppings-wrapper">

                            @foreach ($product->toppings ?? [] as $index => $top)
                                <div class="product-topping-row">

                                    <div class="row mb-2">

                                        <div class="col-md-5">

                                            <input class="form-control topping-name"
                                                name="toppings[{{ $index }}][name]" value="{{ $top->name }}">

                                        </div>

                                        <div class="col-md-5">

                                            <input type="number" class="form-control topping-price"
                                                name="toppings[{{ $index }}][sale_price]"
                                                value="{{ $top->sale_price }}">

                                        </div>

                                        <div class="col-md-2">

                                            <button type="button" class="btn btn-danger remove-product-topping">

                                                X

                                            </button>

                                        </div>

                                    </div>

                                </div>
                            @endforeach

                        </div>


                        <div id="product-topping-template" class="d-none">

                            <div class="product-topping-row">

                                <div class="row mb-2">

                                    <div class="col-md-5">

                                        <input class="form-control topping-name" placeholder="Tên topping">

                                    </div>

                                    <div class="col-md-5">

                                        <input type="number" class="form-control topping-price" placeholder="Giá">

                                    </div>

                                    <div class="col-md-2">

                                        <button type="button" class="btn btn-danger remove-product-topping">

                                            X

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

                        Thông tin hình ảnh

                    </div>

                    <div class="card-body">
                        <div class="d-none">
                            <label>Giá niêm yết</label>

                            <input type="number" name="price" class="form-control mb-3"
                                value="{{ old('price', $product->price) }}">


                            <label>Giá bán</label>

                            <input required type="number" name="sale_price" class="form-control mb-3"
                                value="{{ old('sale_price', $product->sale_price) }}">


                            <label>Stock</label>

                            <input type="number" name="stock" class="form-control mb-3"
                                value="{{ old('stock', $product->stock) }}">
                        </div>
                        <div class="mb-3">

                            <div class="d-flex justify-content-between mb-2">

                                <div class="fw-bold">

                                    Danh mục

                                </div>

                                <button type="button" class="btn btn-warning btn-sm" id="open-category-modal">

                                    Thêm

                                </button>

                            </div>

                            <select name="category_id" id="categorySelect" class="form-select">

                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}"
                                        {{ old('category_id', $product->category_id) == $cat->id ? 'selected' : '' }}>

                                        {{ $cat->name }}

                                    </option>
                                @endforeach

                            </select>

                        </div>

                        <label>Thumbnail</label>

                        <input type="file" name="thumbnail" class="form-control mb-2">

                        @if ($product->thumbnail)
                            <img width="100" src="{{ asset('storage/' . $product->thumbnail) }}">
                        @endif


                        <label class="mt-3">

                            Images

                        </label>

                        <input multiple type="file" name="images[]" class="form-control">

                    </div>

                </div>


                <div class="card mt-3 d-none">

                    <div class="card-header">

                        Reward

                    </div>

                    <div class="card-body">

                        <div class="row">

                            <div class="col-md-6">

                                <label>Số vé</label>

                                <input type="number" name="ticket_reward_quantity" class="form-control"
                                    value="{{ old('ticket_reward_quantity', $product->ticket_reward_quantity) }}">

                            </div>

                            <div class="col-md-6">

                                <label>Loại vé</label>

                                <select name="ticket_type_code" class="form-select">

                                    <option value="">---</option>

                                    @foreach ($ticketTypes as $ticket)
                                        <option value="{{ $ticket->code }}"
                                            {{ old('ticket_type_code', $product->ticket_type_code) == $ticket->code ? 'selected' : '' }}>

                                            {{ $ticket->name }}

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

        <button type="button" class="btn btn-success mb-2" id="add-attribute">

            + Thuộc tính

        </button>

        <button type="button" class="btn btn-primary mb-2" id="generate-combinations">

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

                    <th></th>

                </tr>

            </thead>

            <tbody>

                @foreach ($variantData as $index => $variant)
                    <tr>

                        <td>

                            {{ implode(', ', array_values($variant['attributes'] ?? [])) ?: $product->name }}

                            <input type="hidden" name="variants[{{ $index }}][attributes]"
                                value='@json($variant['attributes'])'>

                        </td>

                        <td>

                            <input type="number" name="variants[{{ $index }}][price]"
                                value="{{ $variant['price'] }}" class="form-control">

                        </td>

                        <td>

                            <input type="number" name="variants[{{ $index }}][sale_price]"
                                value="{{ $variant['sale_price'] }}" class="form-control">

                        </td>

                        <td>

                            <input type="number" step="0.01" name="variants[{{ $index }}][commission_rate]"
                                value="{{ $variant['commission_rate'] }}" class="form-control">

                        </td>

                        <td>

                            <input type="number" name="variants[{{ $index }}][ticket_reward_quantity]"
                                value="{{ $variant['ticket_reward_quantity'] }}" class="form-control">

                        </td>

                        <td>

                            <select name="variants[{{ $index }}][ticket_type_code]" class="form-select">

                                <option value="">---</option>

                                @foreach ($ticketTypes as $ticket)
                                    <option value="{{ $ticket->code }}"
                                        {{ ($variant['ticket_type_code'] ?? '') == $ticket->code ? 'selected' : '' }}>

                                        {{ $ticket->name }}

                                    </option>
                                @endforeach

                            </select>

                        </td>

                        <td>

                            <input type="number" name="variants[{{ $index }}][stock]"
                                value="{{ $variant['stock'] }}" class="form-control">

                        </td>

                        <td>

                            <input type="file" name="variants[{{ $index }}][image]" class="form-control">

                        </td>

                        <td>

                            <button type="button" class="btn btn-danger removeVariant">

                                X

                            </button>

                            <input type="hidden" name="variants[{{ $index }}][delete]" value="0">

                        </td>

                    </tr>
                @endforeach

            </tbody>

        </table>


        <button type="submit" class="btn btn-success">

            Cập nhật

        </button>

    </form>


    @include('admin.categories._modal_create')


    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

    <script>
        document.addEventListener(
            'DOMContentLoaded',
            function() {

                /*
                |--------------------------------------------------------------------------
                | TOPPINGS
                |--------------------------------------------------------------------------
                */

                document
                    .getElementById('add-product-topping')
                    ?.addEventListener('click', function() {

                        const wrapper =
                            document.getElementById(
                                'product-toppings-wrapper'
                            );

                        let maxIndex = -1;

                        wrapper.querySelectorAll('.topping-name')
                            .forEach(input => {

                                const match =
                                    input.name.match(
                                        /toppings\[(\d+)\]/
                                    );

                                if (match) {

                                    maxIndex = Math.max(
                                        maxIndex,
                                        parseInt(match[1])
                                    );

                                }

                            });

                        const index = maxIndex + 1;

                        const clone =
                            document
                            .getElementById(
                                'product-topping-template'
                            )
                            .firstElementChild
                            .cloneNode(true);

                        clone.querySelector('.topping-name').name =
                            `toppings[${index}][name]`;

                        clone.querySelector('.topping-price').name =
                            `toppings[${index}][sale_price]`;

                        wrapper.appendChild(clone);

                    });

                document.addEventListener(
                    'click',
                    function(e) {

                        if (
                            e.target.closest(
                                '.remove-product-topping'
                            )
                        ) {

                            e.target
                                .closest(
                                    '.product-topping-row'
                                )
                                .remove();

                        }

                    });

                /*
                |--------------------------------------------------------------------------
                | ATTRIBUTES
                |--------------------------------------------------------------------------
                */

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

                            if (
                                this.value ===
                                'addAttibute'
                            ) {

                                new bootstrap.Modal(
                                    document.getElementById(
                                        'addAttributeModal'
                                    )
                                ).show();

                            }

                        });

                }

                document
                    .getElementById(
                        'add-attribute'
                    )
                    ?.addEventListener(
                        'click',
                        function() {

                            const clone =
                                attributeTemplate
                                .firstElementChild
                                .cloneNode(true);

                            attributesWrapper
                                .appendChild(
                                    clone
                                );

                            attachAttributeEvents(
                                clone
                            );

                        });

                document.addEventListener(
                    'click',
                    function(e) {

                        if (
                            e.target.closest(
                                '.remove-attribute'
                            )
                        ) {

                            e.target
                                .closest(
                                    '.attribute-row'
                                )
                                ?.remove();

                        }

                    });

                /*
                |--------------------------------------------------------------------------
                | GENERATE COMBINATIONS
                |--------------------------------------------------------------------------
                */

                document
                    .getElementById(
                        'generate-combinations'
                    )
                    ?.addEventListener(
                        'click',
                        function() {

                            const attributes = [];

                            document
                                .querySelectorAll(
                                    '.attribute-row'
                                )
                                .forEach(row => {

                                    const id =
                                        row.querySelector(
                                            '.attribute-name'
                                        ).value;

                                    const values =
                                        row.querySelector(
                                            '.attribute-values'
                                        )
                                        .value
                                        .split(',')
                                        .map(v => v.trim())
                                        .filter(v => v);

                                    if (
                                        id &&
                                        values.length
                                    ) {

                                        attributes.push({
                                            id,
                                            values
                                        });

                                    }

                                });

                            if (
                                !attributes.length
                            ) {

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

                                    let hidden = '';

                                    combo.forEach(
                                        (val, i) => {

                                            hidden += `
<input type="hidden"
name="variants[${index}][attributes][${i}][attribute_id]"
value="${attributeIds[i]}">

<input type="hidden"
name="variants[${index}][attributes][${i}][value]"
value="${val}">
`;

                                        });

                                    const row =
                                        document.createElement(
                                            'tr'
                                        );

                                    row.innerHTML = `

<td>
${combo.join(', ')}
${hidden}
</td>

<td>
<input type="number"
name="variants[${index}][price]"
class="form-control">
</td>

<td>
<input type="number"
name="variants[${index}][sale_price]"
class="form-control">
</td>

<td>
<input type="number"
step="0.01"
name="variants[${index}][commission_rate]"
class="form-control">
</td>

<td>
<input type="number"
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

{{ $ticket->name }}
-
{{ number_format($ticket->ticket_value ?? 0) }}đ

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

                                });

                        });

                function generateCombinations(
                    arrays
                ) {

                    return arrays.reduce(
                        (acc, curr) => {

                            const result = [];

                            acc.forEach(a => {

                                curr.forEach(b => {

                                    result.push(
                                        a.concat([b])
                                    );

                                });

                            });

                            return result;

                        }, [
                            []
                        ]);

                }

                /*
                |--------------------------------------------------------------------------
                | ATTRIBUTE AJAX CREATE
                |--------------------------------------------------------------------------
                */

                const addAttributeForm =
                    document.getElementById(
                        'add-attribute-form'
                    );

                addAttributeForm
                    ?.addEventListener(
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
                                        "{{ route('admin.attributes.store') }}", {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector(
                                                    'meta[name="csrf-token"]'
                                                ).content,

                                                Accept: 'application/json'
                                            },
                                            body: formData
                                        }
                                    );

                                const data =
                                    await response.json();

                                if (
                                    data.success
                                ) {

                                    document
                                        .querySelectorAll(
                                            '.attribute-name'
                                        )
                                        .forEach(select => {

                                            const existed =
                                                select.querySelector(
                                                    `option[value="${data.attribute.id}"]`
                                                );

                                            if (
                                                !existed
                                            ) {

                                                const option =
                                                    document.createElement(
                                                        'option'
                                                    );

                                                option.value =
                                                    data.attribute.id;

                                                option.text =
                                                    data.attribute.name;

                                                select.insertBefore(
                                                    option,
                                                    select.lastElementChild
                                                );

                                            }

                                            select.value =
                                                data.attribute.id;

                                        });

                                    bootstrap.Modal
                                        .getInstance(
                                            document.getElementById(
                                                'addAttributeModal'
                                            )
                                        )
                                        .hide();

                                    document.getElementById(
                                        'newAttributeName'
                                    ).value = '';

                                }

                            } catch (err) {

                                console.error(
                                    err
                                );

                            }

                            btn.disabled = false;

                        });
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

            });

        ClassicEditor
            .create(
                document.querySelector(
                    '#content'
                ), {
                    ckfinder: {
                        uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'
                    }
                })
            .then(editor => {

                editor.editing.view.change(
                    writer => {

                        writer.setStyle(
                            'height',
                            '300px',
                            editor.editing.view.document.getRoot()
                        );

                        

                    });

            })
            .catch(console.error);
    </script>
@endsection
