<?php

namespace App\Http\Controllers;

use App\Models\article;
use App\Http\Requests\StorearticleRequest;
use App\Http\Requests\UpdatearticleRequest;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ArticleController extends Controller
{
    private function displayAreaOptions(): array
    {
        return [
            'article' => 'Khu bài viết',
            'rules' => 'Khu thể lệ',
            'policy' => 'Khu chính sách',
        ];
    }

    private function displayAreaLabel(?string $displayArea): string
    {
        return $this->displayAreaOptions()[$displayArea] ?? 'Khu bài viết';
    }

    private function storeArticleImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        $disk = Storage::disk('public');
        $disk->makeDirectory('articles');

        $path = $request->file('image')->store('articles', 'public');
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'image' => 'Khong the luu anh dai dien. Vui long thu lai.',
            ]);
        }

        return $path;
    }

    private function storeArticlePdf(Request $request): ?string
    {
        if (! $request->hasFile('pdf_file')) {
            return null;
        }

        $disk = Storage::disk('public');
        $disk->makeDirectory('articles/pdfs');

        $path = $request->file('pdf_file')->store('articles/pdfs', 'public');
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'pdf_file' => 'Không thể lưu file PDF chi tiết. Vui lòng thử lại.',
            ]);
        }

        return $path;
    }

    private function storeArticleAdditionalImage(Request $request): ?string
    {
        if (! $request->hasFile('additional_image')) {
            return null;
        }

        $disk = Storage::disk('public');
        $disk->makeDirectory('articles');

        $path = $request->file('additional_image')->store('articles', 'public');
        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            throw ValidationException::withMessages([
                'additional_image' => 'Không thể lưu ảnh bổ sung. Vui lòng thử lại.',
            ]);
        }

        return $path;
    }

    private function resolveSectionArticle(string $displayArea, ?string $slug = null)
    {
        $query = article::query()->where('display_area', $displayArea);

        $article = $slug
            ? $query->where('slug', $slug)->firstOrFail()
            : $query->orderByDesc('published_at')->orderByDesc('id')->firstOrFail();

        $relatedArticles = article::query()
            ->where('display_area', $displayArea)
            ->where('id', '!=', $article->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->take(3)
            ->get();

        return view('article_detail', compact('article', 'relatedArticles'));
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $categories = Category::all();
        return view('admin.articles.index', compact('categories'));
    }

    public function datatable(Request $request)
    {
        $query = article::query()
            ->leftJoin('categories', 'articles.category_id', '=', 'categories.id')
            ->select('articles.*', 'categories.title as category_title');

        $recordsTotal = (clone $query)->count('articles.id');

        if ($categoryId = $request->input('category_id')) {
            $query->where('articles.category_id', $categoryId);
        }

        if ($search = $request->input('search.value')) {
            $query->where(function ($builder) use ($search) {
                $builder->where('articles.title', 'like', "%{$search}%")
                    ->orWhere('articles.slug', 'like', "%{$search}%")
                    ->orWhere('articles.summary', 'like', "%{$search}%")
                    ->orWhere('categories.title', 'like', "%{$search}%")
                    ->orWhere('articles.display_area', 'like', "%{$search}%")
                    ->orWhereRaw("CASE articles.display_area WHEN 'article' THEN 'Khu bài viết' WHEN 'rules' THEN 'Khu thể lệ' WHEN 'policy' THEN 'Khu chính sách' END LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = (clone $query)->count('articles.id');

        $columns = [
            2 => 'articles.id',
            3 => 'articles.title',
            4 => 'articles.slug',
            5 => 'categories.title',
            6 => 'articles.display_area',
            7 => 'articles.published_at',
            8 => 'articles.created_at',
        ];

        $orderField = $columns[$request->input('order.0.column', 8)] ?? 'articles.created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderField, $orderDir);

        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $articles = $query->skip($start)->take($length)->get();

        $data = $articles->map(function (article $article) {
            $imageHtml = $article->image
                ? '<img src="' . asset('storage/' . $article->image) . '" class="rounded border" style="width: 90px; height: 50px; object-fit: cover;" alt="Ảnh bài viết">'
                : '<div class="rounded border d-flex align-items-center justify-content-center bg-light text-muted" style="width: 90px; height: 50px;"><i class="fa-regular fa-image" style="font-size: 16px;"></i></div>';

            $publishedAt = $article->published_at
                ? Carbon::parse($article->published_at)->format('Y-m-d H:i')
                : '-';
            $createdAt = $article->created_at
                ? Carbon::parse($article->created_at)->format('Y-m-d H:i')
                : '-';

            $categoryTitle = $article->category_title ?? '-';
            if ($article->is_hot || strtolower($categoryTitle) === 'hot') {
                $categoryHtml = '<span class="badge" style="background-color: #fef2f2; color: #ef4444; border: 1px solid #fee2e2; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; display: inline-block;">Hot</span>';
            } elseif (strtolower($categoryTitle) === 'mới' || strtolower($categoryTitle) === 'new') {
                $categoryHtml = '<span class="badge" style="background-color: #eff6ff; color: #3b82f6; border: 1px solid #dbeafe; padding: 6px 12px; border-radius: 6px; font-weight: 600; font-size: 12px; display: inline-block;">Mới</span>';
            } else {
                $categoryHtml = $article->category_title 
                    ? '<span class="badge" style="background-color: #f9fafb; color: #374151; border: 1px solid #e5e7eb; padding: 6px 12px; border-radius: 6px; font-weight: 500; font-size: 12px; display: inline-block;">' . e($categoryTitle) . '</span>'
                    : '-';
            }

            $isPublished = $article->published_at && Carbon::parse($article->published_at)->isPast();
            if ($isPublished) {
                $statusHtml = '<span class="d-inline-flex align-items-center text-xs font-semibold" style="background-color: #f0fdf4; color: #16a34a; border: 1px solid #dcfce7; border-radius: 9999px; padding: 4px 12px; font-size: 13px; font-weight: 500; gap: 6px;">'
                    . '<span style="width: 6px; height: 6px; border-radius: 50%; background-color: #22c55e; display: inline-block;"></span>'
                    . 'Xuất bản</span>';
            } else {
                $statusHtml = '<span class="d-inline-flex align-items-center text-xs font-semibold" style="background-color: #f9fafb; color: #4b5563; border: 1px solid #e5e7eb; border-radius: 9999px; padding: 4px 12px; font-size: 13px; font-weight: 500; gap: 6px;">'
                    . '<span style="width: 6px; height: 6px; border-radius: 50%; background-color: #9ca3af; display: inline-block;"></span>'
                    . 'Nháp</span>';
            }

            $actionHtml = '<div class="d-flex align-items-center justify-content-center gap-2">'
                . '<form action="' . route('admin.articles.destroy', $article->id) . '" method="POST" style="display:inline-block; margin:0;" onsubmit="return confirm(\'Xóa bài viết này?\')">'
                . csrf_field() . method_field('DELETE')
                . '<button type="submit" class="btn-action-delete" title="Xóa" style="border: 1px solid #fee2e2; color: #ef4444; background-color: #fff; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; cursor: pointer;" onmouseover="this.style.backgroundColor=\'#fef2f2\'" onmouseout="this.style.backgroundColor=\'#fff\'"><i class="fa-solid fa-trash-can"></i></button>'
                . '</form>'
                . '</div>';

            return [
                'checkbox' => '<input type="checkbox" class="article-row-checkbox form-check-input" value="' . $article->id . '" style="cursor: pointer; width: 18px; height: 18px;">',
                'id' => $article->id,
                'title' => '<a href="' . route('admin.articles.edit', $article->id) . '" class="article-title-text fw-medium text-dark" style="font-size: 14px; text-decoration: none;">' . e($article->title) . '</a>',
                'slug' => $article->slug,
                'category' => $categoryHtml,
                'display_area' => $this->displayAreaLabel($article->display_area),
                'image' => $imageHtml,
                'published_at' => $publishedAt,
                'created_at' => '<span class="text-secondary" style="font-size: 13px;">' . $createdAt . '</span>',
                'status' => $statusHtml,
                'action' => $actionHtml,
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
        $categories = Category::all();
        $displayAreas = $this->displayAreaOptions();

        return view('admin.articles.create', compact('categories', 'displayAreas'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'content' => 'nullable|string',
            'slug' => 'nullable|string|unique:articles,slug',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240',
            'additional_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'category_id' => 'nullable|exists:categories,id',
            'display_area' => 'nullable|in:article,rules,policy',
            'published_at' => 'nullable|date',
        ]);

        // Tạo slug nếu không có
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        $data['display_area'] = $data['display_area'] ?? 'article';
        $data['is_hot'] = $request->has('is_hot') ? 1 : 0;

        // Lưu ảnh đại diện
        $imagePath = $this->storeArticleImage($request);
        if ($imagePath !== null) {
            $data['image'] = $imagePath;
        }

        // Lưu file PDF chi tiết
        $pdfPath = $this->storeArticlePdf($request);
        if ($pdfPath !== null) {
            $data['pdf_path'] = $pdfPath;
        }

        // Lưu ảnh bổ sung
        $additionalImagePath = $this->storeArticleAdditionalImage($request);
        if ($additionalImagePath !== null) {
            $data['additional_image'] = $additionalImagePath;
        }

        // Tạo bài viết
        $article = article::create($data);

        return redirect()->route('admin.articles.index')->with('success', 'Thêm bài viết thành công!');
    }


    /**
     * Display the specified resource.
     */
    public function show($slug)
    {
        $article = Article::where('slug', $slug)->firstOrFail();
        $relatedArticles = Article::where('display_area', $article->display_area)
            ->where('id', '!=', $article->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->take(3)
            ->get();

        return view('article_detail', compact('article', 'relatedArticles'));
    }

    public function rules(?string $slug = null)
    {
        return $this->resolveSectionArticle('rules', $slug);
    }

    public function policy(?string $slug = null)
    {
        return $this->resolveSectionArticle('policy', $slug);
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(article $article)
    {
        $categories = Category::all();
        $displayAreas = $this->displayAreaOptions();

        return view('admin.articles.edit', compact('article', 'categories', 'displayAreas'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, article $article)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string',
            'content' => 'required|string',
            'slug' => 'nullable|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'display_area' => 'nullable|in:article,rules,policy',
            'published_at' => 'nullable|date',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240',
            'additional_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        // Update the article's attributes
        $article->title = $validated['title'];
        $article->summary = $validated['summary'];
        $article->content = $validated['content'];
        $article->slug = $validated['slug'] ?? $this->generateSlug($validated['title']); // Generate slug if not provided
        $article->category_id = $validated['category_id'];
        $article->display_area = $validated['display_area'] ?? $article->display_area ?? 'article';
        $article->published_at = $validated['published_at'];
        $article->is_hot = $request->has('is_hot') ? 1 : 0;

        // Handle image delete if requested
        if ($request->input('delete_image') === '1') {
            if ($article->image && Storage::disk('public')->exists($article->image)) {
                Storage::disk('public')->delete($article->image);
            }
            $article->image = null;
        }

        // Handle image upload if a new image is provided
        $imagePath = $this->storeArticleImage($request);
        if ($imagePath !== null) {
            // Delete the old image if exists
            if ($article->image && Storage::disk('public')->exists($article->image)) {
                Storage::disk('public')->delete($article->image);
            }

            // Store the new image
            $article->image = $imagePath;
        }

        // Handle PDF delete if requested
        if ($request->input('delete_pdf') === '1') {
            if ($article->pdf_path && Storage::disk('public')->exists($article->pdf_path)) {
                Storage::disk('public')->delete($article->pdf_path);
            }
            $article->pdf_path = null;
        }

        // Handle PDF upload if a new PDF file is provided
        $pdfPath = $this->storeArticlePdf($request);
        if ($pdfPath !== null) {
            // Delete the old PDF if exists
            if ($article->pdf_path && Storage::disk('public')->exists($article->pdf_path)) {
                Storage::disk('public')->delete($article->pdf_path);
            }
            $article->pdf_path = $pdfPath;
        }

        // Handle additional image delete if requested
        if ($request->input('delete_additional_image') === '1') {
            if ($article->additional_image && Storage::disk('public')->exists($article->additional_image)) {
                Storage::disk('public')->delete($article->additional_image);
            }
            $article->additional_image = null;
        }

        // Handle additional image upload if a new additional image is provided
        $additionalImagePath = $this->storeArticleAdditionalImage($request);
        if ($additionalImagePath !== null) {
            // Delete the old additional image if exists
            if ($article->additional_image && Storage::disk('public')->exists($article->additional_image)) {
                Storage::disk('public')->delete($article->additional_image);
            }
            $article->additional_image = $additionalImagePath;
        }

        // Save the updated article
        $article->save();

        // Redirect back to the articles index page with a success message
        return redirect()->route('admin.articles.index')->with('success', 'Bài viết đã được cập nhật thành công.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(article $article)
    {
        $article->delete();
        return redirect()->route('admin.articles.index')->with('success', 'Xóa bài viết thành công');
    }

    public function destroyMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|distinct|exists:articles,id',
        ]);

        $deleted = article::whereIn('id', $request->input('ids'))->delete();

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => "Đã xóa {$deleted} bài viết.",
            ]);
        }

        return redirect()->route('admin.articles.index')
            ->with('success', "Đã xóa {$deleted} bài viết.");
    }

    public function articleAffilate(){
        return view('article_affilate');
    }
}
