<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập</title>

    <link rel="icon" href="assets/img/kaiadmin/favicon.ico" type="image/x-icon" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; }

        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: #f0f4f0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }

        /* ── Card ─────────────────────────────────── */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 20px;
            padding: 40px 36px 36px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,.06), 0 10px 40px -8px rgba(0,0,0,.1);
        }

        /* ── Logo / Header ────────────────────────── */
        .login-logo {
            width: 52px; height: 52px;
            background: #2d5a0e;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
        }
        .login-logo i { font-size: 26px; color: #e6f3de; }
        .login-title {
            text-align: center;
            font-size: 22px; font-weight: 700; color: #111;
            margin: 0 0 4px;
        }
        .login-sub {
            text-align: center;
            font-size: 13px; color: #999;
            margin: 0 0 28px;
        }

        /* ── Alerts ───────────────────────────────── */
        .alert {
            border-radius: 10px;
            font-size: 13px;
            padding: 12px 14px;
            margin-bottom: 18px;
            border: none;
        }
        .alert-danger {
            background: #fef2f2;
            color: #b91c1c;
            border-left: 3px solid #ef4444;
        }
        .alert-danger ul { margin: 6px 0 0 0; padding-left: 16px; }
        .alert-danger ul li { margin-bottom: 2px; }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border-left: 3px solid #22c55e;
        }
        .alert-banned {
            background: #fef2f2;
            color: #b91c1c;
            border-left: 3px solid #dc2626;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 18px;
            display: flex; align-items: flex-start; gap: 10px;
            font-size: 13px;
        }
        .alert-banned i { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
        .alert-banned strong { display: block; margin-bottom: 2px; font-size: 13px; }

        /* ── Form fields ──────────────────────────── */
        .field-group { margin-bottom: 16px; }
        .field-group label {
            display: block;
            font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .5px;
            color: #888; margin-bottom: 6px;
        }
        .field-group label .req { color: #ef4444; margin-left: 2px; }

        .field-wrap { position: relative; }
        .field-wrap .field-icon {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: #bbb; font-size: 16px; pointer-events: none;
        }
        .field-wrap input {
            width: 100%;
            padding: 10px 40px 10px 38px;
            font-size: 14px;
            border: 1.5px solid #e4e4e4;
            border-radius: 10px;
            background: #fafafa;
            color: #111;
            outline: none;
            transition: border-color .15s, background .15s, box-shadow .15s;
            font-family: inherit;
        }
        .field-wrap input:focus {
            border-color: #3b6d11;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(59,109,17,.1);
        }
        .field-wrap input::placeholder { color: #ccc; }
        .toggle-password {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            cursor: pointer; color: #bbb; font-size: 16px;
            transition: color .15s;
        }
        .toggle-password:hover { color: #555; }

        /* ── Remember ─────────────────────────────── */
        .remember-row {
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 22px;
        }
        .remember-row input[type="checkbox"] {
            width: 16px; height: 16px;
            border: 1.5px solid #d4d4d4;
            border-radius: 4px;
            accent-color: #2d5a0e;
            cursor: pointer;
        }
        .remember-row label {
            font-size: 13px; color: #555;
            cursor: pointer; margin: 0;
        }

        /* ── Submit button ────────────────────────── */
        .btn-login {
            width: 100%;
            padding: 11px;
            font-size: 14px; font-weight: 700;
            border: none; border-radius: 10px;
            background: #2d5a0e; color: #e6f3de;
            cursor: pointer;
            transition: background .15s, transform .1s, box-shadow .15s;
            font-family: inherit;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-login:hover { background: #3b6d11; box-shadow: 0 4px 14px rgba(45,90,14,.25); }
        .btn-login:active { transform: scale(.98); }

        /* ── Footer ───────────────────────────────── */
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 13px; color: #888;
        }
        .login-footer a { color: #2d5a0e; font-weight: 600; text-decoration: none; }
        .login-footer a:hover { text-decoration: underline; }

        /* ── Divider ──────────────────────────────── */
        .divider {
            display: flex; align-items: center; gap: 10px;
            margin: 20px 0;
            font-size: 12px; color: #ccc;
        }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: #f0f0f0; }

        
    </style>
</head>

<body>
    <div class="login-card">

        {{-- Logo --}}
        <div class="login-logo">
            <i class="bi bi-cpu"></i>
        </div>
        <h1 class="login-title">Chào mừng trở lại</h1>
        <p class="login-sub">Đăng nhập để tiếp tục sử dụng dịch vụ</p>

        {{-- Alerts --}}
        @if ($errors->any())
            <div class="alert alert-danger">
                <strong>Có lỗi xảy ra:</strong>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('banned'))
            <div class="alert-banned">
                <i class="bi bi-shield-exclamation"></i>
                <div>
                    <strong>Tài khoản bị khóa!</strong>
                    {{ session('error') }}
                </div>
            </div>
        @elseif (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if (session('success'))
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-1"></i> {{ session('success') }}
            </div>
        @endif

        {{-- Form --}}
        <form action="{{ route('login') }}" method="POST">
            @csrf

            <div class="field-group">
                <label for="phone">Số điện thoại <span class="req">*</span></label>
                <div class="field-wrap">
                    <i class="bi bi-envelope field-icon"></i>
                    <input type="text" id="phone" name="phone"
                        placeholder="Nhập địa chỉ phone"
                        value="{{ old('phone') }}" required>
                </div>
            </div>

            <div class="field-group">
                <label for="password">Mật khẩu <span class="req">*</span></label>
                <div class="field-wrap">
                    <i class="bi bi-lock field-icon"></i>
                    <input type="password" id="password" name="password"
                        placeholder="Nhập mật khẩu"
                        value="{{ old('password') }}" required>
                    <i class="bi bi-eye-slash toggle-password" id="togglePassword"></i>
                </div>
            </div>

            <div class="remember-row">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Ghi nhớ tài khoản</label>
            </div>

            <button type="submit" class="btn-login">
                <i class="bi bi-box-arrow-in-right"></i>
                Đăng nhập
            </button>
        </form>

        {{-- <div class="login-footer">
            Bạn chưa có tài khoản? <a href="signup">Đăng ký ngay</a>
        </div> --}}

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Lấy dữ liệu từ LocalStorage và điền vào form nếu có
            if (localStorage.getItem("remember") === "true") {
                document.getElementById("phone").value = localStorage.getItem("phone") || "";
                document.getElementById("password").value = localStorage.getItem("password") || "";
                document.getElementById("remember").checked = true;
            }

            // Xử lý sự kiện khi click vào mắt mật khẩu
            document.querySelectorAll('.toggle-password').forEach(item => {
                item.addEventListener('click', function() {
                    let input = this.previousElementSibling;
                    if (input.type === "password") {
                        input.type = "text";
                        this.classList.remove("bi-eye-slash");
                        this.classList.add("bi-eye");
                    } else {
                        input.type = "password";
                        this.classList.remove("bi-eye");
                        this.classList.add("bi-eye-slash");
                    }
                });
            });

            // Xử lý sự kiện khi submit form
            document.querySelector("form").addEventListener("submit", function(event) {
                event.preventDefault(); // Ngăn chặn form gửi đi để kiểm tra lưu dữ liệu

                let phone = document.getElementById("phone").value;
                let password = document.getElementById("password").value;
                let remember = document.getElementById("remember").checked;
                if (remember) {
                    // Lưu vào LocalStorage nếu người dùng chọn "Ghi nhớ tài khoản"
                    localStorage.setItem("phone", phone);
                    localStorage.setItem("password", password);
                    localStorage.setItem("remember", "true");
                } else {
                    // Xóa dữ liệu khỏi LocalStorage nếu không chọn "Ghi nhớ tài khoản"
                    localStorage.removeItem("phone");
                    localStorage.removeItem("password");
                    localStorage.removeItem("remember");
                }

                // alert("Đăng nhập thành công! (Dữ liệu đã lưu vào LocalStorage)");
                this.submit(); // Nếu muốn form gửi đi, bỏ comment dòng này
            });
        });
    </script>
</body>

</html>