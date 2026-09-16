<?php

namespace App\Http\Controllers;

use App\Models\SpecialistRating;
use App\Models\SpecialistRatingReviewRequest;
use App\Services\SpecialistRatingService;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpecialistRatingController extends Controller
{
    public function __construct(
        private readonly SpecialistRatingService $ratingService
    ) {
    }

    public function index(Request $request)
    {
        if (AdminCmsAccess::isSale($request->user())) {
            return view('admin.specialists.ratings.index', [
                'isSaleUser' => true,
                ...$this->ratingService->saleDashboard((int) $request->user()->id),
            ]);
        }

        $filters = [
            'specialist_id' => $request->query('specialist_id'),
            'star_level' => $request->query('star_level', 'all'),
            'status' => $request->query('status', 'all'),
            'period_from' => $request->query('period_from'),
            'period_to' => $request->query('period_to'),
        ];

        return view('admin.specialists.ratings.index', [
            'isSaleUser' => false,
            'ratings' => $this->ratingService->adminRatings($filters),
            'filters' => $filters,
            'specialistOptions' => $this->ratingService->specialistOptions(),
            'statusLabels' => $this->ratingService->statusLabels(),
            'criteriaLabels' => $this->ratingService->criteriaLabels(),
        ]);
    }

    public function show(Request $request, SpecialistRating $rating)
    {
        $this->authorizeRatingAccess($request, $rating);

        $rating->load([
            'specialist:id,name,full_name,email,phone,role',
            'period',
            'criteriaScores',
            'reviewRequests' => fn ($query) => $query->orderByDesc('created_at'),
            'auditLogs' => fn ($query) => $query->orderByDesc('created_at')->limit(50),
        ]);

        return view('admin.specialists.ratings.show', [
            'rating' => $rating,
            'isSaleUser' => AdminCmsAccess::isSale($request->user()),
            'criteriaLabels' => $this->ratingService->criteriaLabels(),
            'statusLabels' => $this->ratingService->statusLabels(),
            'reviewStatusLabels' => $this->ratingService->reviewStatusLabels(),
        ]);
    }

    public function calculate(Request $request): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'specialist_id' => ['nullable', 'integer', 'exists:users,id'],
            'period_from' => ['nullable', 'date_format:Y-m-d'],
            'period_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $today = now('Asia/Ho_Chi_Minh');
        $from = Carbon::parse($validated['period_from'] ?? $today->copy()->startOfMonth()->toDateString())->startOfDay();
        $to = Carbon::parse($validated['period_to'] ?? $today->copy()->endOfMonth()->toDateString())->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $ratings = $this->ratingService->calculateForMany(
            ! empty($validated['specialist_id']) ? (int) $validated['specialist_id'] : null,
            $from,
            $to,
            (int) $request->user()->id
        );

        return redirect()->route('admin.specialist-ratings.index')
            ->with('success', 'Đã chạy tính điểm cho ' . count($ratings) . ' chuyên viên.');
    }

    public function approve(Request $request, SpecialistRating $rating): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->ratingService->approve($rating, (int) $request->user()->id, $validated['notes'] ?? null);

        return back()->with('success', 'Đã duyệt và gửi thông báo kết quả đánh giá cho chuyên viên.');
    }

    public function adjust(Request $request, SpecialistRating $rating): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'total_score' => ['required', 'numeric', 'min:0', 'max:100'],
            'adjustment_reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->ratingService->adjust(
            $rating,
            (float) $validated['total_score'],
            (int) $request->user()->id,
            $validated['adjustment_reason']
        );

        return back()->with('success', 'Đã điều chỉnh điểm và lưu lịch sử thay đổi.');
    }

    public function submitReviewRequest(Request $request, SpecialistRating $rating): RedirectResponse
    {
        abort_unless(AdminCmsAccess::isSale($request->user()), 403);
        abort_unless((int) $rating->specialist_id === (int) $request->user()->id, 404);
        abort_unless(in_array((string) $rating->status, [SpecialistRatingService::STATUS_APPROVED, SpecialistRatingService::STATUS_NOTIFIED], true), 404);

        $validated = $request->validate([
            'request_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $this->ratingService->submitReviewRequest(
            $rating,
            (int) $request->user()->id,
            $validated['request_reason']
        );

        return back()->with('success', 'Đã gửi yêu cầu xem xét lại kết quả đánh giá.');
    }

    public function reviewRequests(Request $request)
    {
        $this->authorizeAdminAction($request);

        $filters = [
            'status' => $request->query('status', 'all'),
            'specialist_id' => $request->query('specialist_id'),
        ];

        return view('admin.specialists.ratings.review-requests', [
            'requests' => $this->ratingService->reviewRequests($filters),
            'filters' => $filters,
            'reviewStatusLabels' => $this->ratingService->reviewStatusLabels(),
            'specialistOptions' => $this->ratingService->specialistOptions(),
        ]);
    }

    public function processReviewRequest(Request $request, SpecialistRatingReviewRequest $reviewRequest): RedirectResponse
    {
        $this->authorizeAdminAction($request);

        $validated = $request->validate([
            'status' => ['required', 'in:ACCEPTED,REJECTED'],
            'new_score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'admin_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->ratingService->processReviewRequest(
            $reviewRequest,
            $validated['status'],
            (int) $request->user()->id,
            isset($validated['new_score']) ? (float) $validated['new_score'] : null,
            $validated['admin_response'] ?? null
        );

        return back()->with('success', 'Đã xử lý yêu cầu xem xét lại.');
    }

    private function authorizeRatingAccess(Request $request, SpecialistRating $rating): void
    {
        if (AdminCmsAccess::isSale($request->user())) {
            abort_unless((int) $rating->specialist_id === (int) $request->user()->id, 404);
            abort_unless(in_array((string) $rating->status, [SpecialistRatingService::STATUS_APPROVED, SpecialistRatingService::STATUS_NOTIFIED], true), 404);
        }
    }

    private function authorizeAdminAction(Request $request): void
    {
        abort_if(AdminCmsAccess::isSale($request->user()), 403, 'Chuyên viên không có quyền thao tác chức năng admin đánh giá.');
    }
}
