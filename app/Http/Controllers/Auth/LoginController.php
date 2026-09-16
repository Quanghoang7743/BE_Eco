<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\appSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'phone' => ['required', 'regex:/^0[0-9]{9}$/'],
            'password' => ['required', 'string', 'min:6'],
        ], [
            'phone.required' => 'Vui lòng nhập số điện thoại.',
            'phone.regex' => 'Nhập sai định dạng số điện thoại.',
            'password.required' => 'Vui lòng nhập mật khẩu.',
        ]);

        $credentials = [
            'phone' => $request->phone,
            'password' => $request->password,
        ];

        $remember = $request->boolean('remember');

        if (!Auth::attempt($credentials, $remember)) {

            $exists = User::where('phone', $request->phone)->exists();

            return back()
                ->withErrors([
                    'phone' => $exists
                        ? 'Số điện thoại hoặc mật khẩu không đúng.'
                        : 'Số điện thoại không tồn tại.'
                ])
                ->withInput(
                    $request->only('phone')
                );
        }

        $user = Auth::user();
        if ($user instanceof User && ! $user->isActiveForAuth()) {
            Auth::logout();

            return back()
                ->withErrors([
                    'phone' => 'Tai khoan dang bi khoa hoac khong con hoat dong.',
                ])
                ->withInput(
                    $request->only('phone')
                );
        }

        $request->session()->regenerate();

        return redirect()
            ->route('admin')
            ->with(
                'success',
                'Đăng nhập thành công!'
            );
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('login')->with('success', 'Bạn đã đăng xuất thành công!');
    }

    public function showSignupForm()
    {
        return view('signup');
    }

    public function signup(Request $request)
    {
        $referrerId = User::resolveReferrerId($request->input('ref'));
        $AppSetting = appSetting::first();
        $data = $request->validate([
            // 'full_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:users|regex:/^0[0-9]{9}$/',
            // 'password'  => 'required|string|min:6|confirmed',
        ], [
            'phone.required' => 'Vui lòng nhập phone.',
            'phone.regex' => 'Nhập sai định dạng số điện thoại',
            'phone.unique' => 'Số điện thoại đã tồn tại.',
        ]);

        // Create new user
        $user = User::create([
            'full_name' => $data['phone'],
            'phone'     => $data['phone'],
            'name'      => $data['phone'],
            'balance'      => $AppSetting->donated,
            'referrer_id'      => $referrerId,
            'password'  => Hash::make($data['phone']),
        ]);

        // Log the user in
        Auth::login($user);

        $soTien = number_format($AppSetting->donated, 0, '.', ',');
        return redirect()->route('home')->with('success', "Đăng ký thành công, bạn đã nhận được {$soTien}đ");
    }
}
