@extends('layout')

@section('title', 'Thông tin tài khoản nhận hoa hồng')

@section('content')
    <div class="container">
        <div class=" card shadow-sm p-4 rounded-4 mt-4">
            <h5 class="mb-4 text-center fw-bold"><i class="fas fa-wallet me-2 text-primary"></i>Thông tin tài khoản </h5>

            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('account.accoutPayment.store') }}">
                @csrf

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-university me-2 text-secondary"></i>Ngân hàng
                    </label>
                    <select class="form-select" name="bank_name" required>
                        <option value="">-- Chọn ngân hàng --</option>
                        @if(isset($banks) && $banks->isNotEmpty())
                            @foreach($banks as $bank)
                                <option value="{{ $bank->name }}" {{ old('bank_name', optional($bankAccount)->bank?->name ?? optional($bankAccount)->bank_name) === $bank->name ? 'selected' : '' }}>{{ $bank->name }}</option>
                            @endforeach
                        @else
                            <option value="Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)" {{ old('bank_name', optional($bankAccount)->bank?->name ?? optional($bankAccount)->bank_name) === 'Ngân hàng TMCP Ngoại thương Việt Nam (Vietcombank)' ? 'selected' : '' }}>Vietcombank - Ngân hàng TMCP Ngoại thương Việt Nam</option>
                            <option value="Ngân hàng TMCP Kỹ thương Việt Nam (Techcombank)">Techcombank - Ngân hàng TMCP Kỹ thương Việt Nam</option>
                            <option value="Ngân hàng TMCP Đầu tư và Phát triển Việt Nam (BIDV)">BIDV - Ngân hàng TMCP Đầu tư và Phát triển Việt Nam</option>
                            <option value="Ngân hàng TMCP Công Thương Việt Nam (VietinBank)">VietinBank - Ngân hàng TMCP Công Thương Việt Nam</option>
                            <option value="Ngân hàng TMCP Á Châu (ACB)">ACB - Ngân hàng TMCP Á Châu</option>
                            <option value="Ngân hàng TMCP Tiên Phong (TPBank)">TPBank - Ngân hàng TMCP Tiên Phong</option>
                            <option value="Ngân hàng TMCP Quân đội (MB Bank)">MB Bank - Ngân hàng TMCP Quân đội</option>
                            <option value="Ngân hàng TMCP Sài Gòn Thương Tín (Sacombank)">Sacombank - Ngân hàng TMCP Sài Gòn Thương Tín</option>
                            <option value="Ngân hàng TMCP Việt Nam Thịnh Vượng (VPBank)">VPBank - Ngân hàng TMCP Việt Nam Thịnh Vượng</option>
                            <option value="Ngân hàng TMCP Sài Gòn - Hà Nội (SHB)">SHB - Ngân hàng TMCP Sài Gòn - Hà Nội</option>
                            <option value="Ngân hàng TMCP Phát triển TP.HCM (HDBank)">HDBank - Ngân hàng TMCP Phát triển TP.HCM</option>
                            <option value="Ngân hàng TMCP Đông Nam Á (SeABank)">SeABank - Ngân hàng TMCP Đông Nam Á</option>
                            <option value="Ngân hàng TMCP Xuất Nhập khẩu Việt Nam (Eximbank)">Eximbank - Ngân hàng TMCP Xuất Nhập khẩu Việt Nam</option>
                            <option value="Ngân hàng TMCP Bảo Việt (Baoviet Bank)">Baoviet Bank - Ngân hàng TMCP Bảo Việt</option>
                            <option value="Ngân hàng TMCP Bắc Á (Bac A Bank)">Bac A Bank - Ngân hàng TMCP Bắc Á</option>
                            <option value="Ngân hàng TMCP Quốc tế Việt Nam (VIB)">VIB - Ngân hàng TMCP Quốc tế Việt Nam</option>
                            <option value="Ngân hàng TMCP An Bình (ABBANK)">ABBANK - Ngân hàng TMCP An Bình</option>
                            <option value="Ngân hàng TMCP Nam Á (Nam A Bank)">Nam A Bank - Ngân hàng TMCP Nam Á</option>
                            <option value="Ngân hàng TMCP Bản Việt (VietCapital Bank)">VietCapital Bank - Ngân hàng TMCP Bản Việt</option>
                            <option value="Ngân hàng TMCP Xăng dầu Petrolimex (PG Bank)">PG Bank - Ngân hàng TMCP Xăng dầu Petrolimex</option>
                            <option value="Ngân hàng TMCP Đại Chúng Việt Nam (PVcomBank)">PVcomBank - Ngân hàng TMCP Đại Chúng Việt Nam</option>
                            <option value="Ngân hàng TNHH MTV Shinhan Việt Nam">Shinhan Bank - Ngân hàng TNHH MTV Shinhan Việt Nam</option>
                            <option value="Ngân hàng TNHH MTV Woori Việt Nam">Woori Bank - Ngân hàng TNHH MTV Woori Việt Nam</option>
                            <option value="Ngân hàng TNHH MTV HSBC (Việt Nam)">HSBC - Ngân hàng TNHH MTV HSBC (Việt Nam)</option>
                            <option value="Ngân hàng TNHH MTV Standard Chartered Việt Nam">Standard Chartered - Ngân hàng TNHH MTV Standard Chartered Việt Nam</option>
                            <option value="Ngân hàng CIMB Việt Nam">CIMB Bank - Ngân hàng CIMB Việt Nam</option>
                        @endif
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-user me-2 text-secondary"></i>Tên chủ tài khoản
                    </label>
                    <input type="text" class="form-control" name="account_name" value="{{ old('account_name', optional($bankAccount)->account_name) }}" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-hashtag me-2 text-secondary"></i>Số tài khoản
                    </label>
                    <input type="text" class="form-control" name="account_number" value="{{ old('account_number', optional($bankAccount)->account_number) }}" required>
                </div>

                <button type="submit"
                    class="btn btn-primary w-100 rounded-pill fw-bold d-flex justify-content-center align-items-center gap-2">
                    <i class="fas fa-check-circle"></i> Lưu thông tin tài khoản
                </button>
            </form>
        </div>
    </div>


    <script>
        const accountTypeElement = document.getElementById('account_type');
        if (accountTypeElement) {
            accountTypeElement.addEventListener('change', function() {
                const type = this.value;
                const bankDetails = document.getElementById('bank_details');
                const swiftDetails = document.getElementById('swift_details');

                if (bankDetails) {
                    bankDetails.style.display = type === 'bank' ? 'block' : 'none';
                }
                if (swiftDetails) {
                    swiftDetails.style.display = type === 'bank' ? 'block' : 'none';
                }
            });
        }
    </script>
@endsection
