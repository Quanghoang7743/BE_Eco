<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SafeEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminProfileController extends Controller
{
    /**
     * Show the profile page for the authenticated user.
     */
    public function show()
    {
        $user = Auth::user();
        return view('admin.profile', compact('user'));
    }

    /**
     * Update the profile of the authenticated user.
     */
    public function update(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();

        if ($request->has('email')) {
            $request->merge(['email' => SafeEmail::normalizeForValidation($request->input('email'))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($user->id)],
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255', SafeEmail::validationRule(), Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'max:20'],
            'gender' => ['nullable', 'in:male,female,other'],
            'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ], [
            'name.required' => 'Tên đăng nhập không được để trống.',
            'name.unique' => 'Tên đăng nhập đã tồn tại trên hệ thống.',
            'full_name.required' => 'Họ và tên không được để trống.',
            'phone.required' => 'Số điện thoại không được để trống.',
            'avatar.image' => 'Ảnh đại diện phải là định dạng hình ảnh.',
            'avatar.max' => 'Ảnh đại diện tối đa 2MB.',
        ]);

        if (array_key_exists('email', $validated)) {
            $validated['email'] = SafeEmail::normalizeNullable($validated['email']);
        }

        // Handle password change if requested
        if ($request->filled('new_password')) {
            $request->validate([
                'current_password' => ['required', 'string'],
                'new_password' => ['required', 'string', 'min:6', 'confirmed'],
            ], [
                'current_password.required' => 'Vui lòng nhập mật khẩu hiện tại.',
                'new_password.required' => 'Vui lòng nhập mật khẩu mới.',
                'new_password.min' => 'Mật khẩu mới phải có ít nhất 6 ký tự.',
                'new_password.confirmed' => 'Xác nhận mật khẩu mới không khớp.',
            ]);

            if (!Hash::check($request->input('current_password'), $user->password)) {
                return back()->withErrors(['current_password' => 'Mật khẩu hiện tại không chính xác.'])->withInput();
            }

            $validated['password'] = Hash::make($request->input('new_password'));
        }

        // Handle avatar delete
        if ($request->input('delete_avatar') === '1') {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }
            $validated['avatar'] = null;
        }

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            $validated['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user->update($validated);

        return redirect()->route('admin.profile')->with('success', 'Hồ sơ của bạn đã được cập nhật thành công.');
    }
}
