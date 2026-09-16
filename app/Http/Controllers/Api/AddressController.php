<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Models\Address;

class AddressController extends Controller
{
    // Lấy danh sách địa chỉ của user hiện tại
    public function index(Request $request)
    {
        $user = Auth::user();
        $addresses = Address::where('user_id', $user->id)->get();
        return response()->json($addresses);
    }

    // Thêm địa chỉ mới
    public function store(Request $request)
    {
        $user = Auth::user();
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:500',
            'alias' => 'nullable|string|max:100',
            'location' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $data['user_id'] = $user->id;
        $address = Address::create($data);
        return response()->json($address, 201);
    }

    // Cập nhật địa chỉ
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $address = Address::where('user_id', $user->id)->findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'phone' => 'sometimes|required|string|max:20',
            'address' => 'sometimes|required|string|max:500',
            'alias' => 'nullable|string|max:100',
            'location' => 'nullable|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $address->update($validator->validated());
        return response()->json($address);
    }

    // Xóa địa chỉ
    public function destroy(Request $request, $id)
    {
        $user = Auth::user();
        $address = Address::where('user_id', $user->id)->findOrFail($id);
        $address->delete();
        return response()->json(['success' => true]);
    }
}
