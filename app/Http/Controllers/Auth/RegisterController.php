<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Companies;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class RegisterController extends Controller
{
    public function showForm()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        // Validate dữ liệu

        // $validator = Validator::make($request->all(), [
        //     'name' => 'required|string|max:255',
        //     'email' => 'required|string|email|max:255|unique:users',
        //     'password' => 'required|string|min:6|confirmed',
        //     // 'company_code' => 'required|string|exists:companies,company_code'
        // ]);

        // if ($request->fails()) {
        //     return redirect()->back()->withErrors($request)->withInput();
        // }
        $company_code = Companies::where('company_code',$request->company_code)->first();
        if($company_code){
            return redirect()->back()
                ->withErrors("Mã công ty đã được sử dụng") // Gửi lỗi về view
                ->withInput();
        }
        if ($request->password != $request->password_confirmation) {
            return redirect()->back()
                ->withErrors("Nhập lại mật khẩu không chính xác") // Gửi lỗi về view
                ->withInput();
        }
        DB::beginTransaction();
        try {

            $company = new Companies();
            $company->name = $request->company;
            $company->company_code = $request->company_code;
            $company->save();

            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);
            $user->company_code = $request->company_code;
            $user->role = 'admin'; // Quản trị viên
            $user->save();
            DB::commit();

            return redirect()->route('login')->with('success', 'Đăng ký thành công, vui lòng đăng nhập.');
        } catch (\Exception $e) {
            // Nếu có lỗi, rollback transaction (Hủy bỏ thay đổi)
            DB::rollBack();

            return redirect()->back()
                ->withErrors("Đăng ký thất bại, vui lòng thử lại!")
                ->withInput();
        }
    }
}
