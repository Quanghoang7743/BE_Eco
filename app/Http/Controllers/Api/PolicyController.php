<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\article;
use Carbon\Carbon;

class PolicyController extends Controller
{
    private const POLICIES = [
        'platform-terms' => 'Sàn',
        'store-policy' => 'Cửa hàng',
    ];

    public function index()
    {
        $articles = article::query()
            ->whereIn('slug', array_keys(self::POLICIES))
            ->get()
            ->keyBy('slug');

        $policies = collect(self::POLICIES)->map(function (string $type, string $slug) use ($articles) {
            $article = $articles->get($slug);

            return [
                'slug' => $slug,
                'type' => $type,
                'title' => $article?->title,
                'summary' => $article?->summary,
                'content' => $article?->content,
                'category_id' => $article?->category_id,
                'display_area' => $article?->display_area,
                'status' => $article?->published_at ? 'published' : 'draft',
                'published_at' => $article?->published_at
                    ? Carbon::parse($article->published_at)->toIso8601String()
                    : null,
                'updated_at' => $article?->updated_at?->toIso8601String(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'policies' => $policies,
        ]);
    }

    public function show(string $slug)
    {
        if (!array_key_exists($slug, self::POLICIES)) {
            return response()->json([
                'success' => false,
                'message' => 'Chính sách không tồn tại.',
            ], 404);
        }

        $article = article::query()->where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'article' => [
                'id' => $article->id,
                'slug' => $article->slug,
                'type' => self::POLICIES[$slug],
                'title' => $article->title,
                'summary' => $article->summary,
                'content' => $article->content,
                'category_id' => $article->category_id,
                'display_area' => $article->display_area,
                'status' => $article->published_at ? 'published' : 'draft',
                'published_at' => $article->published_at ? Carbon::parse($article->published_at)->toIso8601String() : null,
                'updated_at' => optional($article->updated_at)->toIso8601String(),
            ],
        ]);
    }
}