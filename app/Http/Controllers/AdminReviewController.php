<?php

namespace App\Http\Controllers;

use App\Models\StoreReview;
use Illuminate\Http\Request;

class AdminReviewController extends Controller
{
    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $rating = $request->query('rating');

        $reviews = StoreReview::query()
            ->with([
                'store:id,name,store_code',
                'user:id,name,full_name,avatar',
                'order:id,order_code',
                'menuItem:id,item_code,item_name,image_url,product_id',
                'product:id,name,thumbnail,images',
            ])
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($subQuery) use ($keyword) {
                    $subQuery->where('comment', 'like', '%' . $keyword . '%')
                        ->orWhere('product_name', 'like', '%' . $keyword . '%')
                        ->orWhereHas('menuItem', function ($menuItemQuery) use ($keyword) {
                            $menuItemQuery->where('item_name', 'like', '%' . $keyword . '%');
                        })
                        ->orWhereHas('product', function ($productQuery) use ($keyword) {
                            $productQuery->where('name', 'like', '%' . $keyword . '%');
                        })
                        ->orWhereHas('store', function ($storeQuery) use ($keyword) {
                            $storeQuery->where('name', 'like', '%' . $keyword . '%')
                                ->orWhere('store_code', 'like', '%' . $keyword . '%');
                        })
                        ->orWhereHas('user', function ($userQuery) use ($keyword) {
                            $userQuery->where('name', 'like', '%' . $keyword . '%')
                                ->orWhere('full_name', 'like', '%' . $keyword . '%');
                        });
                });
            })
            ->when(is_numeric($rating) && (int) $rating >= 1 && (int) $rating <= 5, function ($query) use ($rating) {
                $query->where('rating', (int) $rating);
            })
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.reviews.index', compact('reviews', 'keyword', 'rating'));
    }
}