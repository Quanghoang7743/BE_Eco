<?php

namespace App\Http\Controllers\Api;

use App\Models\UserArticleInteraction;
use App\Models\article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClientArticleInteractionController extends ClientController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! Schema::hasTable('user_article_interactions')) {
            return response()->json([
                'success' => true,
                'articles' => [],
            ]);
        }

        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:liked,saved'],
        ]);

        $type = (string) ($validated['type'] ?? 'liked');
        $column = $type === 'saved' ? 'is_saved' : 'is_liked';

        $articles = UserArticleInteraction::query()
            ->where('user_id', (int) $user->id)
            ->where($column, true)
            ->with('article')
            ->orderByDesc($type === 'saved' ? 'saved_at' : 'liked_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (UserArticleInteraction $interaction) => $interaction->article)
            ->filter()
            ->map(fn (article $article) => $this->serializeArticle($article, (int) $user->id))
            ->values();

        return response()->json([
            'success' => true,
            'type' => $type,
            'articles' => $articles,
        ]);
    }

    public function status(Request $request, string $articleId): JsonResponse
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $article = $this->findArticle($articleId);
        if (! $article) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay bai viet.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->buildInteractionPayload($article, (int) $user->id),
        ]);
    }

    public function toggleLike(Request $request, string $articleId): JsonResponse
    {
        return $this->toggleBooleanInteraction($request, $articleId, 'like');
    }

    public function toggleSave(Request $request, string $articleId): JsonResponse
    {
        return $this->toggleBooleanInteraction($request, $articleId, 'save');
    }

    public function trackShare(Request $request, string $articleId): JsonResponse
    {
        $article = $this->findArticle($articleId);
        if (! $article) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay bai viet.',
            ], 404);
        }

        if (Schema::hasColumn('articles', 'share_count')) {
            $article->newQuery()
                ->whereKey($article->id)
                ->increment('share_count');

            $article->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'article_id' => (int) $article->id,
                'share_count' => $this->getShareCount($article),
            ],
        ]);
    }

    private function toggleBooleanInteraction(Request $request, string $articleId, string $type): JsonResponse
    {
        $user = $request->user() ?: $this->resolveApiUser($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! Schema::hasTable('user_article_interactions')) {
            return response()->json([
                'success' => false,
                'message' => 'Bang tuong tac bai viet chua duoc khoi tao.',
            ], 503);
        }

        $article = $this->findArticle($articleId);
        if (! $article) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay bai viet.',
            ], 404);
        }

        DB::transaction(function () use ($article, $user, $type): void {
            $interaction = UserArticleInteraction::query()
                ->where('user_id', (int) $user->id)
                ->where('article_id', (int) $article->id)
                ->lockForUpdate()
                ->first();

            if (! $interaction) {
                $interaction = new UserArticleInteraction([
                    'user_id' => (int) $user->id,
                    'article_id' => (int) $article->id,
                ]);
            }

            if ($type === 'like') {
                $nextValue = ! (bool) $interaction->is_liked;
                $interaction->is_liked = $nextValue;
                $interaction->liked_at = $nextValue ? now() : null;
            } else {
                $nextValue = ! (bool) $interaction->is_saved;
                $interaction->is_saved = $nextValue;
                $interaction->saved_at = $nextValue ? now() : null;
            }

            if (! $interaction->is_liked && ! $interaction->is_saved && $interaction->exists) {
                $interaction->delete();
                return;
            }

            $interaction->save();
        });

        return response()->json([
            'success' => true,
            'message' => $type === 'like' ? 'Da cap nhat trang thai thich.' : 'Da cap nhat trang thai luu bai.',
            'data' => $this->buildInteractionPayload($article, (int) $user->id),
        ]);
    }

    private function findArticle(string $articleId): ?article
    {
        if (! ctype_digit($articleId)) {
            return null;
        }

        return article::query()->whereKey((int) $articleId)->first();
    }

    private function buildInteractionPayload(article $article, int $userId): array
    {
        $interaction = null;
        if (Schema::hasTable('user_article_interactions')) {
            $interaction = UserArticleInteraction::query()
                ->where('user_id', $userId)
                ->where('article_id', (int) $article->id)
                ->first();
        }

        return [
            'article_id' => (int) $article->id,
            'is_liked' => (bool) ($interaction?->is_liked ?? false),
            'is_saved' => (bool) ($interaction?->is_saved ?? false),
            'like_count' => $this->countArticleInteractions($article, 'is_liked'),
            'save_count' => $this->countArticleInteractions($article, 'is_saved'),
            'share_count' => $this->getShareCount($article),
        ];
    }

    private function countArticleInteractions(article $article, string $column): int
    {
        if (! Schema::hasTable('user_article_interactions')) {
            return 0;
        }

        return (int) UserArticleInteraction::query()
            ->where('article_id', (int) $article->id)
            ->where($column, true)
            ->count();
    }

    private function getShareCount(article $article): int
    {
        if (! Schema::hasColumn('articles', 'share_count')) {
            return 0;
        }

        return (int) ($article->share_count ?? 0);
    }

    private function resolveArticleImagePath(?string $image): ?string
    {
        $path = trim((string) $image);
        if ($path === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        return Storage::disk('public')->exists($path) ? $path : null;
    }

    private function articleImageUrl(?string $imagePath): ?string
    {
        if (! $imagePath) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $imagePath)) {
            return $imagePath;
        }

        return '/storage/' . ltrim($imagePath, '/');
    }

    private function serializeArticle(article $article, ?int $userId = null): array
    {
        $payload = $article->toArray();
        $viewCount = (int) ($article->view_count ?? 0);
        $imagePath = $this->resolveArticleImagePath($article->image ?? null);
        $interaction = null;

        if ($userId && Schema::hasTable('user_article_interactions')) {
            $interaction = UserArticleInteraction::query()
                ->where('user_id', $userId)
                ->where('article_id', (int) $article->id)
                ->first();
        }

        $payload['view_count'] = $viewCount;
        $payload['views'] = $viewCount;
        $payload['share_count'] = $this->getShareCount($article);
        $payload['like_count'] = $this->countArticleInteractions($article, 'is_liked');
        $payload['save_count'] = $this->countArticleInteractions($article, 'is_saved');
        $payload['is_liked'] = (bool) ($interaction?->is_liked ?? false);
        $payload['is_saved'] = (bool) ($interaction?->is_saved ?? false);
        $payload['image'] = $imagePath;
        $payload['image_url'] = $this->articleImageUrl($imagePath);

        return $payload;
    }
}
