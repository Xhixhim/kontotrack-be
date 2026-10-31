@php
    use App\Support\DocumentFormat as F;

    $cell = fn ($value, bool $amount) => $value === null ? '' : ($amount ? F::money($value) : (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? F::date($value) : $value));
@endphp
<!DOCTYPE html>
<html lang="sq">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.styles')
</head>
<body>
    <h1>{{ $title }}</h1>
    <p><strong>{{ $business->legal_name }}</strong> · NUI {{ $business->nui }}</p>
    <p class="muted">{{ $period }} · {{ $business->currency }}</p>

    @foreach ($sections as $section)
        <div style="margin-top: 14px;">
            @if ($section['heading'])<h3>{{ $section['heading'] }}</h3>@endif
            <table>
                @if (collect($section['columns'])->contains(fn ($column) => $column['label'] !== ''))
                    <thead><tr>@foreach ($section['columns'] as $column)<th @class(['number' => $column['amount']])>{{ $column['label'] }}</th>@endforeach</tr></thead>
                @endif
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>@foreach ($row as $index => $value)<td @class(['number' => $section['columns'][$index]['amount'] ?? false])>{{ $cell($value, $section['columns'][$index]['amount'] ?? false) }}</td>@endforeach</tr>
                    @endforeach
                    @foreach ($section['totals'] as $row)
                        <tr class="total-row">@foreach ($row as $index => $value)<td @class(['number' => $section['columns'][$index]['amount'] ?? false])>{{ $value === '' ? '' : $cell($value, $section['columns'][$index]['amount'] ?? false) }}</td>@endforeach</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach

    <div class="footer">KontoTrack · {{ $business->legal_name }} · NUI {{ $business->nui }} · {{ $generatedAt }}</div>
</body>
</html>
