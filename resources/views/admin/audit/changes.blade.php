{{-- What one audit row changed: $changes from AuditLog::changes. --}}
@foreach ($changes as $change)
    <div class="audit-change">
        <strong>{{ ['created' => 'New', 'updated' => 'Changed', 'deleted' => 'Deleted'][$change['event']] ?? ucfirst($change['event']) }} {{ $change['model'] }}</strong>
        <span class="muted">{{ $change['label'] }}</span>
        @if (! empty($change['fields']))
            <table class="audit-fields">
                @foreach ($change['fields'] as $field => [$before, $after])
                    <tr>
                        <th>{{ str_replace('_', ' ', $field) }}</th>
                        @if ($change['event'] === 'created')
                            <td colspan="2">{{ is_bool($after) ? ($after ? 'yes' : 'no') : $after }}</td>
                        @else
                            <td class="audit-before">{{ $before === null || $before === '' ? '(empty)' : (is_bool($before) ? ($before ? 'yes' : 'no') : $before) }}</td>
                            <td>{{ $after === null || $after === '' ? '(empty)' : (is_bool($after) ? ($after ? 'yes' : 'no') : $after) }}</td>
                        @endif
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endforeach
