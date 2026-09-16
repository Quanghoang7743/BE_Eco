<?php

namespace App\Http\Controllers;

use App\Models\SpecialistStoreCareLog;
use App\Models\SpecialistTask;
use App\Models\Store;
use App\Models\User;
use App\Services\SpecialistNotificationService;
use App\Support\AdminCmsAccess;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SpecialistCareController extends Controller
{
    private const CARE_TYPES = [
        'call' => 'Gọi điện',
        'visit' => 'Gặp trực tiếp',
        'zalo' => 'Hỗ trợ qua Zalo',
        'email' => 'Email',
        'onsite' => 'Hỗ trợ tại quán',
        'other' => 'Khác',
    ];

    private const TASK_TYPES = [
        'menu_update' => 'Cập nhật menu',
        'complaint_followup' => 'Xử lý khiếu nại',
        'document_collect' => 'Thu thập hồ sơ',
        'store_training' => 'Hướng dẫn vận hành',
        'risk_check' => 'Kiểm tra rủi ro',
        'other' => 'Khác',
    ];

    private const TASK_STATUS_LABELS = [
        SpecialistTask::STATUS_PENDING => 'Chưa làm',
        SpecialistTask::STATUS_IN_PROGRESS => 'Đang làm',
        SpecialistTask::STATUS_COMPLETED => 'Hoàn tất',
        SpecialistTask::STATUS_OVERDUE => 'Quá hạn',
    ];

    public function __construct(
        private readonly SpecialistNotificationService $specialistNotificationService
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $this->markOverdueTasks();

        $isSaleUser = AdminCmsAccess::isSale($user);
        $filters = [
            'store_id' => trim((string) $request->query('store_id', '')),
            'specialist_id' => $isSaleUser ? (string) $user->id : trim((string) $request->query('specialist_id', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'task_status' => trim((string) $request->query('task_status', 'all')) ?: 'all',
        ];

        $logs = $this->careLogQuery($request, $filters)
            ->paginate(15, ['*'], 'log_page')
            ->appends($request->query());

        $tasks = $this->taskQuery($request, $filters)
            ->paginate(15, ['*'], 'task_page')
            ->appends($request->query());

        return view('admin.specialists.care.index', [
            'isSaleUser' => $isSaleUser,
            'filters' => $filters,
            'logs' => $logs,
            'tasks' => $tasks,
            'storeOptions' => $this->storeOptions($request),
            'specialistOptions' => $this->specialistOptions($request),
            'careTypeLabels' => self::CARE_TYPES,
            'taskTypeLabels' => self::TASK_TYPES,
            'taskStatusLabels' => self::TASK_STATUS_LABELS,
        ]);
    }

    public function storeLog(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('specialist_store_care_logs'), 404);

        $validated = $request->validate([
            'store_id' => ['required', 'string', 'exists:stores,id'],
            'specialist_id' => ['nullable', 'integer', 'exists:users,id'],
            'care_type' => ['required', Rule::in(array_keys(self::CARE_TYPES))],
            'care_content' => ['required', 'string', 'max:5000'],
            'problem_detected' => ['nullable', 'string', 'max:5000'],
            'proposed_solution' => ['nullable', 'string', 'max:5000'],
            'result_note' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_at' => ['nullable', 'date'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        $store = Store::query()->findOrFail($validated['store_id']);
        AdminCmsAccess::abortUnlessCanViewStore($store, $request->user());

        $specialistId = AdminCmsAccess::isSale($request->user())
            ? (int) $request->user()->id
            : (int) ($validated['specialist_id'] ?? $store->sale_id ?? 0);

        if ($specialistId <= 0) {
            throw ValidationException::withMessages([
                'specialist_id' => 'Cửa hàng chưa có chuyên viên phụ trách. Vui lòng chọn chuyên viên trước khi ghi log.',
            ]);
        }

        if (! $this->specialistCanAccessStore($specialistId, (string) $store->id)) {
            throw ValidationException::withMessages([
                'store_id' => 'Chuyên viên được chọn không phụ trách cửa hàng này.',
            ]);
        }

        $attachment = $this->storeAttachment($request, 'attachment');

        SpecialistStoreCareLog::query()->create([
            'specialist_id' => $specialistId,
            'store_id' => (string) $store->id,
            'care_type' => $validated['care_type'],
            'content' => trim($validated['care_content']),
            'care_content' => trim($validated['care_content']),
            'problem_detected' => $this->nullableText($validated['problem_detected'] ?? null),
            'proposed_solution' => $this->nullableText($validated['proposed_solution'] ?? null),
            'result_note' => $this->nullableText($validated['result_note'] ?? null),
            'attachment_file_id' => $attachment['path'],
            'attachment_original_name' => $attachment['name'],
            'attachment_mime_type' => $attachment['mime'],
            'attachment_size' => $attachment['size'],
            'next_follow_up_at' => ! empty($validated['next_follow_up_at'])
                ? Carbon::parse($validated['next_follow_up_at'])->toDateTimeString()
                : null,
            'created_by' => (int) $request->user()->id,
        ]);

        return redirect()->route('admin.specialist-care.index', [
            'store_id' => (string) $store->id,
        ])->with('success', 'Đã ghi nhận nhật ký chăm sóc cửa hàng.');
    }

    public function storeTask(Request $request): RedirectResponse
    {
        abort_if(AdminCmsAccess::isSale($request->user()), 403, 'Chuyên viên không có quyền tạo nhiệm vụ.');
        abort_unless(Schema::hasTable('specialist_tasks'), 404);

        $validated = $request->validate([
            'specialist_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'sale')),
            ],
            'store_id' => ['nullable', 'string', 'exists:stores,id'],
            'task_type' => ['required', Rule::in(array_keys(self::TASK_TYPES))],
            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:5000'],
            'deadline' => ['required', 'date'],
        ]);

        if (! empty($validated['store_id']) && ! $this->specialistCanAccessStore((int) $validated['specialist_id'], (string) $validated['store_id'])) {
            throw ValidationException::withMessages([
                'store_id' => 'Chuyên viên này chưa được phân công cửa hàng đã chọn.',
            ]);
        }

        $deadline = Carbon::parse($validated['deadline']);
        $task = SpecialistTask::query()->create([
            'specialist_id' => (int) $validated['specialist_id'],
            'store_id' => $validated['store_id'] ?? null,
            'task_type' => $validated['task_type'],
            'title' => trim($validated['title']),
            'description' => $this->nullableText($validated['description'] ?? null),
            'status' => SpecialistTask::STATUS_PENDING,
            'deadline' => $deadline->toDateTimeString(),
            'due_at' => $deadline->toDateTimeString(),
            'created_by' => (int) $request->user()->id,
            'assigned_by' => (int) $request->user()->id,
        ]);

        $this->notifyTaskCreated($task);

        return redirect()->route('admin.specialist-care.index', [
            'specialist_id' => (int) $validated['specialist_id'],
            'task_status' => SpecialistTask::STATUS_PENDING,
        ])->with('success', 'Đã tạo và giao nhiệm vụ cho chuyên viên.');
    }

    public function startTask(Request $request, SpecialistTask $task): RedirectResponse
    {
        $this->authorizeTaskAccess($request, $task);

        if ((string) $task->status === SpecialistTask::STATUS_PENDING) {
            $task->forceFill([
                'status' => SpecialistTask::STATUS_IN_PROGRESS,
            ])->save();
        }

        return back()->with('success', 'Đã chuyển nhiệm vụ sang trạng thái đang làm.');
    }

    public function completeTask(Request $request, SpecialistTask $task): RedirectResponse
    {
        $this->authorizeTaskAccess($request, $task);

        $validated = $request->validate([
            'result_note' => ['required', 'string', 'max:2000'],
        ]);

        $task->forceFill([
            'status' => SpecialistTask::STATUS_COMPLETED,
            'result_note' => trim($validated['result_note']),
            'completed_at' => now('Asia/Ho_Chi_Minh'),
        ])->save();

        return back()->with('success', 'Đã hoàn thành nhiệm vụ và ghi nhận kết quả.');
    }

    private function careLogQuery(Request $request, array $filters)
    {
        if (! Schema::hasTable('specialist_store_care_logs')) {
            return SpecialistStoreCareLog::query()->whereRaw('1 = 0');
        }

        $query = SpecialistStoreCareLog::query()
            ->with([
                'store:id,store_code,name,sale_id',
                'specialist:id,name,full_name,email',
                'creator:id,name,full_name,email',
            ])
            ->orderByDesc('created_at');

        if (AdminCmsAccess::isSale($request->user())) {
            $storeIds = AdminCmsAccess::assignedStoreIds($request->user());
            if (empty($storeIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('store_id', $storeIds);
            }
        } elseif ($filters['specialist_id'] !== '' && ctype_digit($filters['specialist_id'])) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        }

        if ($filters['store_id'] !== '') {
            $query->where('store_id', $filters['store_id']);
        }

        $this->applyDateFilters($query, $filters);

        return $query;
    }

    private function taskQuery(Request $request, array $filters)
    {
        if (! Schema::hasTable('specialist_tasks')) {
            return SpecialistTask::query()->whereRaw('1 = 0');
        }

        $query = SpecialistTask::query()
            ->with([
                'store:id,store_code,name,sale_id',
                'specialist:id,name,full_name,email',
                'creator:id,name,full_name,email',
            ])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'pending' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'completed' THEN 3 ELSE 4 END")
            ->orderByRaw('COALESCE(deadline, due_at, created_at) asc');

        if (AdminCmsAccess::isSale($request->user())) {
            $storeIds = AdminCmsAccess::assignedStoreIds($request->user());
            $query->where('specialist_id', (int) $request->user()->id)
                ->where(function ($scope) use ($storeIds): void {
                    $scope->whereNull('store_id');
                    if (! empty($storeIds)) {
                        $scope->orWhereIn('store_id', $storeIds);
                    }
                });
        } elseif ($filters['specialist_id'] !== '' && ctype_digit($filters['specialist_id'])) {
            $query->where('specialist_id', (int) $filters['specialist_id']);
        }

        if ($filters['store_id'] !== '') {
            $query->where('store_id', $filters['store_id']);
        }

        if ($filters['task_status'] !== 'all' && isset(self::TASK_STATUS_LABELS[$filters['task_status']])) {
            $query->where('status', $filters['task_status']);
        }

        $this->applyDateFilters($query, $filters, 'deadline');

        return $query;
    }

    private function applyDateFilters($query, array $filters, string $dateColumn = 'created_at'): void
    {
        if ($filters['date_from'] !== '') {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }
    }

    private function storeOptions(Request $request)
    {
        $query = Store::query()
            ->select('id', 'store_code', 'name', 'sale_id')
            ->orderBy('name');

        AdminCmsAccess::applyStoreScope($query, $request->user());

        return $query->get();
    }

    private function specialistOptions(Request $request)
    {
        if (AdminCmsAccess::isSale($request->user())) {
            return collect([$request->user()]);
        }

        return User::query()
            ->select('id', 'name', 'full_name', 'email', 'role', 'status')
            ->where('role', 'sale')
            ->when(Schema::hasColumn('users', 'status'), function ($query): void {
                $query->where(function ($statusQuery): void {
                    $statusQuery->whereNull('status')->orWhere('status', 'active');
                });
            })
            ->orderBy('full_name')
            ->orderBy('name')
            ->get();
    }

    private function authorizeTaskAccess(Request $request, SpecialistTask $task): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! AdminCmsAccess::isSale($user)) {
            return;
        }

        abort_unless((int) $task->specialist_id === (int) $user->id, 404);

        if ($task->store_id !== null) {
            abort_unless(in_array((string) $task->store_id, AdminCmsAccess::assignedStoreIds($user), true), 404);
        }
    }

    private function specialistCanAccessStore(int $specialistId, string $storeId): bool
    {
        if ($specialistId <= 0 || $storeId === '') {
            return false;
        }

        if (
            Schema::hasTable('specialist_store_assignments')
            && Schema::hasColumn('specialist_store_assignments', 'specialist_id')
            && Schema::hasColumn('specialist_store_assignments', 'store_id')
        ) {
            $query = DB::table('specialist_store_assignments')
                ->where('specialist_id', $specialistId)
                ->where('store_id', $storeId);

            if (Schema::hasColumn('specialist_store_assignments', 'assigned_to')) {
                $query->where(function ($window): void {
                    $window->whereNull('assigned_to')
                        ->orWhere('assigned_to', '>=', now('Asia/Ho_Chi_Minh')->toDateTimeString());
                });
            }

            if ($query->exists()) {
                return true;
            }
        }

        return Schema::hasColumn('stores', 'sale_id')
            && Store::query()->whereKey($storeId)->where('sale_id', $specialistId)->exists();
    }

    private function storeAttachment(Request $request, string $field): array
    {
        if (! $request->hasFile($field)) {
            return [
                'path' => null,
                'name' => null,
                'mime' => null,
                'size' => null,
            ];
        }

        $file = $request->file($field);
        $path = $file->store('specialist-care-logs', 'public');

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function notifyTaskCreated(SpecialistTask $task): void
    {
        $this->specialistNotificationService->create([
            'specialist_id' => (int) $task->specialist_id,
            'type' => SpecialistNotificationService::TYPE_TASK_NEW,
            'title' => 'Nhiệm vụ mới',
            'message' => $task->title,
            'data' => [
                'task_id' => (string) $task->id,
                'store_id' => $task->store_id ? (string) $task->store_id : null,
                'deadline' => optional($task->deadline ?? $task->due_at)->toDateTimeString(),
            ],
            'action_url' => route('admin.specialist-care.index', [
                'task_status' => SpecialistTask::STATUS_PENDING,
            ]),
            'event_key' => 'task_new:' . $task->specialist_id . ':' . $task->id,
        ]);
    }

    private function markOverdueTasks(): void
    {
        if (! Schema::hasTable('specialist_tasks') || ! Schema::hasColumn('specialist_tasks', 'status')) {
            return;
        }

        $deadlineColumn = Schema::hasColumn('specialist_tasks', 'deadline')
            ? 'deadline'
            : (Schema::hasColumn('specialist_tasks', 'due_at') ? 'due_at' : null);

        if ($deadlineColumn === null) {
            return;
        }

        DB::table('specialist_tasks')
            ->whereNotIn('status', [SpecialistTask::STATUS_COMPLETED, SpecialistTask::STATUS_OVERDUE])
            ->whereNotNull($deadlineColumn)
            ->where($deadlineColumn, '<', now('Asia/Ho_Chi_Minh')->toDateTimeString())
            ->update([
                'status' => SpecialistTask::STATUS_OVERDUE,
                'updated_at' => now(),
            ]);
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
