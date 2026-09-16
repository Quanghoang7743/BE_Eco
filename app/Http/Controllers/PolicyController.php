<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\article;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PolicyController extends Controller
{
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

    private const POLICIES = [
        [
            'slug' => 'platform-terms',
            'type' => 'Sàn',
            'title' => 'Điều khoản sử dụng sàn',
        ],
        [
            'slug' => 'store-policy',
            'type' => 'Cửa hàng',
            'title' => 'Chính sách cửa hàng',
        ],
    ];

    private function requireSuperAdmin(): void
    {
        abort_unless(in_array((string) auth()->user()?->role, ['admin'], true), 403, 'Chỉ admin mới được phép thay đổi chính sách.');
    }

    private function findPolicyDefinition(string $slug): ?array
    {
        foreach (self::POLICIES as $policy) {
            if ($policy['slug'] === $slug) {
                return $policy;
            }
        }

        return null;
    }

    private function defaultPolicyDefinition(): array
    {
        return self::POLICIES[0];
    }

    private function buildFormData(array $definition): array
    {
        $article = article::query()->where('slug', $definition['slug'])->first();

        return [
            'definition' => $definition,
            'article' => $article,
            'categories' => Category::query()->orderBy('title')->get(),
        ];
    }

    private function formatStatus(?article $article): array
    {
        if (!$article || !$article->published_at) {
            return [
                'label' => 'Nháp',
                'class' => 'secondary',
            ];
        }

        $publishedAt = Carbon::parse($article->published_at);

        return [
            'label' => $publishedAt->lte(now()) ? 'Đang hiển thị' : 'Nháp',
            'class' => $publishedAt->lte(now()) ? 'success' : 'secondary',
        ];
    }

    public function index()
    {
        $this->requireSuperAdmin();

        $policies = collect(self::POLICIES)->map(function (array $definition, int $index) {
            $article = article::query()->where('slug', $definition['slug'])->first();

            return [
                'index' => $index + 1,
                'definition' => $definition,
                'article' => $article,
                'status' => $this->formatStatus($article),
                'updated_at' => $article?->updated_at,
                'edit_url' => $article ? route('admin.policies.edit', $article->slug) : null,
            ];
        });

        return view('admin.policies.index', compact('policies'));
    }

    public function create(Request $request)
    {
        $this->requireSuperAdmin();

        $requestedSlug = (string) $request->query('slug', '');
        $definition = $this->findPolicyDefinition($requestedSlug) ?? $this->defaultPolicyDefinition();

        return view('admin.policies.create', $this->buildFormData($definition));
    }

    public function store(Request $request)
    {
        $this->requireSuperAdmin();

        $validated = $request->validate([
            'policy_slug' => 'required|in:platform-terms,store-policy',
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'category_id' => 'nullable|exists:categories,id',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240',
            'additional_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $definition = $this->findPolicyDefinition($validated['policy_slug']);
        abort_unless($definition, 404);

        $updateData = [
            'title' => $validated['title'],
            'summary' => $validated['summary'] ?? null,
            'content' => $validated['content'],
            'category_id' => $validated['category_id'] ?? null,
            'display_area' => 'policy',
            'published_at' => $request->boolean('is_published')
                ? ($validated['published_at'] ?? now())
                : null,
        ];

        $pdfPath = $this->storeArticlePdf($request);
        if ($pdfPath !== null) {
            $updateData['pdf_path'] = $pdfPath;
        }

        $additionalImagePath = $this->storeArticleAdditionalImage($request);
        if ($additionalImagePath !== null) {
            $updateData['additional_image'] = $additionalImagePath;
        }

        $article = article::updateOrCreate(
            ['slug' => $definition['slug']],
            $updateData
        );

        return redirect()
            ->route('admin.policies.edit', $article->slug)
            ->with('success', 'Đã tạo mới/chỉnh sửa chính sách thành công.');
    }

    public function edit(article $article)
    {
        $this->requireSuperAdmin();

        $definition = $this->findPolicyDefinition($article->slug);
        abort_unless($definition, 404);

        $categories = Category::query()->orderBy('title')->get();

        return view('admin.policies.edit', compact('article', 'definition', 'categories'));
    }

    public function update(Request $request, article $article)
    {
        $this->requireSuperAdmin();

        $definition = $this->findPolicyDefinition($article->slug);
        abort_unless($definition, 404);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'content' => 'required|string',
            'category_id' => 'nullable|exists:categories,id',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
            'pdf_file' => 'nullable|file|mimes:pdf|max:10240',
            'additional_image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $article->title = $validated['title'];
        $article->summary = $validated['summary'] ?? null;
        $article->content = $validated['content'];
        $article->category_id = $validated['category_id'] ?? $article->category_id;
        $article->display_area = 'policy';
        $article->published_at = $request->boolean('is_published')
            ? ($validated['published_at'] ?? now())
            : null;

        // Handle PDF delete
        if ($request->input('delete_pdf') === '1') {
            if ($article->pdf_path && Storage::disk('public')->exists($article->pdf_path)) {
                Storage::disk('public')->delete($article->pdf_path);
            }
            $article->pdf_path = null;
        }

        // Handle PDF upload
        $pdfPath = $this->storeArticlePdf($request);
        if ($pdfPath !== null) {
            if ($article->pdf_path && Storage::disk('public')->exists($article->pdf_path)) {
                Storage::disk('public')->delete($article->pdf_path);
            }
            $article->pdf_path = $pdfPath;
        }

        // Handle additional image delete
        if ($request->input('delete_additional_image') === '1') {
            if ($article->additional_image && Storage::disk('public')->exists($article->additional_image)) {
                Storage::disk('public')->delete($article->additional_image);
            }
            $article->additional_image = null;
        }

        // Handle additional image upload
        $additionalImagePath = $this->storeArticleAdditionalImage($request);
        if ($additionalImagePath !== null) {
            if ($article->additional_image && Storage::disk('public')->exists($article->additional_image)) {
                Storage::disk('public')->delete($article->additional_image);
            }
            $article->additional_image = $additionalImagePath;
        }

        $article->save();

        return redirect()
            ->route('admin.policies.edit', $article->slug)
            ->with('success', 'Đã cập nhật chính sách thành công.');
    }
}