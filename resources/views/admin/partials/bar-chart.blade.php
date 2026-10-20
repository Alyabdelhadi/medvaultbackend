{{--
    A bar chart in plain HTML. $data: label => number. $money formats the
    total and tooltips as amounts. $tone: accent | sky | navy. $wide: a full row.
    $empty: what to say when every value is 0; $emptyLink: [label, url].
--}}
@php
    $values = array_values($data);
    $max = max([1, ...$values]);
    $fmt = fn ($v) => ($money ?? false) ? '$'.number_format($v, $v == (int) $v ? 0 : 2) : number_format($v);
    // Above a bar: short, so twelve fit side by side.
    $short = fn ($v) => $v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : number_format($v, $v == (int) $v ? 0 : 1);
@endphp
<figure class="chart chart-{{ $tone ?? 'accent' }} {{ ($wide ?? false) ? 'chart-wide' : '' }}">
    <figcaption>
        <span>{{ $title }}</span>
        <b>{{ $fmt(array_sum($values)) }}</b>
    </figcaption>
    @if (array_sum($values) == 0)
        <div class="chart-empty">
            @include('admin.partials.icon', ['name' => 'chart'])
            <span>{{ $empty ?? 'Nothing yet for this period.' }}</span>
            @if (isset($emptyLink))<a class="btn small secondary" href="{{ $emptyLink[1] }}">{{ $emptyLink[0] }}</a>@endif
        </div>
    @else
    <div class="chart-bars" role="img" aria-label="{{ $title }}">
        @foreach ($data as $label => $value)
            <div class="chart-col" title="{{ $label }}: {{ $fmt($value) }}">
                <span class="chart-value">{{ $value ? $short($value) : '' }}</span>
                <span class="chart-bar" style="height:{{ $value ? max(2, round($value / $max * 100)) : 0 }}%"></span>
                <span class="chart-label">{{ $label }}</span>
            </div>
        @endforeach
    </div>
    @endif
</figure>
