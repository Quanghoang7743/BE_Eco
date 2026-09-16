@if($careLogs->isEmpty())
    <div class="sd-empty">Chưa có nhật ký chăm sóc cho chuyên viên này.</div>
@else
    <div class="sd-table-wrap">
        <table class="sd-table">
            <thead>
                <tr>
                    <th>Thời gian</th>
                    <th>Cửa hàng</th>
                    <th>Hình thức</th>
                    <th>Nội dung</th>
                    <th>Vấn đề</th>
                    <th>Hẹn lần sau</th>
                </tr>
            </thead>
            <tbody>
                @foreach($careLogs as $log)
                    <tr>
                        <td>{{ $formatDate($log->created_at, 'd/m/Y H:i') }}</td>
                        <td>
                            <strong>{{ $log->store?->name ?: '-' }}</strong>
                            @if($log->store?->store_code)
                                <div class="sd-muted small">{{ $log->store->store_code }}</div>
                            @endif
                        </td>
                        <td>{{ $log->care_type ?: '-' }}</td>
                        <td>{{ $shortText($log->care_content ?: $log->content, 82) }}</td>
                        <td>
                            @if(trim((string) $log->problem_detected) !== '')
                                <span class="sd-badge sd-badge-orange">Cần theo dõi</span>
                                <div class="sd-muted small mt-1">{{ $shortText($log->problem_detected, 58) }}</div>
                            @else
                                <span class="sd-badge sd-badge-green">Không có vấn đề</span>
                            @endif
                        </td>
                        <td>{{ $log->next_follow_up_at ? $formatDate($log->next_follow_up_at, 'd/m/Y H:i') : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
