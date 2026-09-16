{{-- <!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký</title>

    <link rel="icon" href="assets/img/kaiadmin/favicon.ico" type="image/x-icon" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .register-container {
            max-width: 400px;
            margin: 65px auto;
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .password-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
    </style>
</head>

<body>
    
</body>

</html> --}}
@extends('layout')

@section('title', 'Đăng ký tài khoản')
@section('content')
    <style>
        body {
            background-color: #f8f9fa;
        }

        .register-container {
            max-width: 400px;
            margin: 65px auto;
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .password-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
        }
    </style>
    <div class="container">
        <div class="register-container">
            <h3 class="text-center mb-4">Đăng ký tài khoản</h3>
            <form action="{{ route('signup') }}" method="POST">
                @csrf

                {{-- Hiển thị lỗi validate --}}
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Họ và tên --}}
                {{-- <div class="mb-3">
                    <label for="full_name" class="form-label">Họ và tên <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="full_name" name="full_name" placeholder="Nhập họ và tên"
                        value="{{ old('full_name') }}" required>
                </div> --}}

                {{-- Số điện thoại --}}
                <input type="hidden" name="ref" value="{{ request()->query('ref') }}"> 
                <div class="mb-3">
                    <label for="phone" class="form-label">Số điện thoại <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="phone" name="phone"
                        placeholder="Nhập số điện thoại" value="{{ old('phone') }}" required>
                </div>

                {{-- Mật khẩu --}}
                {{-- <div class="mb-3">
                    <label for="password" class="form-label">Mật khẩu <span class="text-danger">*</span></label>
                    <div class="password-container">
                        <input type="password" class="form-control" id="password" name="password"
                            placeholder="Nhập mật khẩu" required>
                        <i class="bi bi-eye-slash toggle-password" data-toggle="password" data-target="#password"></i>
                    </div>
                </div> --}}

                {{-- Xác nhận mật khẩu --}}
                {{-- <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Xác nhận mật khẩu <span
                            class="text-danger">*</span></label>
                    <div class="password-container">
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                            placeholder="Nhập lại mật khẩu" required>
                        <i class="bi bi-eye-slash toggle-password" data-toggle="password"
                            data-target="#password_confirmation"></i>
                    </div>
                </div> --}}

                <button type="submit" class="btn btn-primary w-100">Đăng ký</button>
                <p class="text-center mt-3">Bạn đã có tài khoản? <a href="{{ route('login') }}">Đăng nhập ngay</a></p>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.toggle-password').forEach(icon => {
            icon.addEventListener('click', function() {
                const target = document.querySelector(this.dataset.target);
                if (target.type === 'password') {
                    target.type = 'text';
                    this.classList.replace('bi-eye-slash', 'bi-eye');
                } else {
                    target.type = 'password';
                    this.classList.replace('bi-eye', 'bi-eye-slash');
                }
            });
        });
    </script>
@endsection
