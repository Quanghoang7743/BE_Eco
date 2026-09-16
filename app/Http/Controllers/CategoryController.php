<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return view('admin.categories.index');
    }

    public function datatable(Request $request)
    {
        $query = Category::query()
            ->leftJoin('categories as parents', 'categories.parent_category_id', '=', 'parents.id')
            ->select('categories.*', 'parents.title as parent_title');

        $recordsTotal = (clone $query)->count('categories.id');

        if ($search = $request->input('search.value')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('categories.title', 'like', "%{$search}%")
                    ->orWhere('categories.slug', 'like', "%{$search}%")
                    ->orWhere('categories.description', 'like', "%{$search}%")
                    ->orWhere('parents.title', 'like', "%{$search}%");
            });
        }

        $recordsFiltered = (clone $query)->count('categories.id');

        $columns = [
            1 => 'categories.id',
            2 => 'categories.title',
            3 => 'categories.slug',
            4 => 'parents.title',
            5 => 'categories.description',
        ];

        $orderField = $columns[$request->input('order.0.column', 1)] ?? 'categories.id';
        $orderDir = $request->input('order.0.dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($orderField, $orderDir);

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $categories = $query->skip($start)->take($length)->get();

        $data = $categories->map(function (Category $category) {
            return [
                'checkbox' => '<input type="checkbox" class="category-row-checkbox" value="' . $category->id . '">',
                'id' => $category->id,
                'title' => $category->title,
                'slug' => $category->slug,
                'parent' => $category->parent_title ?? '-',
                'description' => $category->description ?? '-',
                'action' => '<a href="' . route('admin.categories.edit', $category->id) . '" class="btn btn-warning btn-sm">Sửa</a>'
                    . '<form action="' . route('admin.categories.destroy', $category->id) . '" method="POST" style="display:inline-block">'
                    . csrf_field() . method_field('DELETE')
                    . '<button type="submit" class="btn btn-danger btn-sm" onclick="return confirm(\'Xóa danh mục này?\')">Xóa</button>'
                    . '</form>',
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = Category::whereNull('parent_category_id')->get(); // Lấy tất cả danh mục cha
        return view('admin.categories.create', compact('categories'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate dữ liệu
        $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'parent_category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string',
        ]);

        // Tạo slug nếu chưa nhập
        $slug = $request->slug ?? Str::slug($request->title);

        // Lưu vào CSDL
        Category::create([
            'title' => $request->title,
            'slug' => $slug,
            'parent_category_id' => $request->parent_category_id,
            'description' => $request->description,
        ]);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Chuyên mục đã được thêm thành công!');
    }


    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category)
    {
        $categories = Category::where('id', '!=', $category->id)->whereNull('parent_category_id')->get();
        return view('admin.categories.edit', compact('category', 'categories'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,' . $category->id,
            'parent_category_id' => 'nullable|exists:categories,id|not_in:' . $category->id,
            'description' => 'nullable|string',
        ]);
    
        $slug = $request->slug ?? Str::slug($request->title);
    
        $category->update([
            'title' => $request->title,
            'slug' => $slug,
            'parent_category_id' => $request->parent_category_id,
            'description' => $request->description,
        ]);
    
        return redirect()->route('admin.categories.index')->with('success', 'Chuyên mục đã được cập nhật!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();
        return redirect()->route('admin.categories.index')->with('success', 'Xóa danh mục thành công');
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:categories,id',
        ]);

        $deleted = Category::whereIn('id', $request->input('ids'))->delete();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$deleted} danh mục.",
            ]);
        }

        return redirect()->route('admin.categories.index')
            ->with('success', "Đã xóa {$deleted} danh mục.");
    }
}
