@extends('admin.layout')
@section('title', 'Result Categories')
@section('content')
<h1>Result Categories</h1>
<p>Map raw <code>cat_name</code> values from the labs to the filter labels shown in the app (e.g. <em>Blood Test</em>, <em>IV Drip</em>).</p>
@if ($usingDefaults)<div class="notice warning">None saved yet: the app is getting the built-in defaults shown below. Saving stores them.</div>@endif
<form method="post" action="{{ route('admin.categories.update') }}">
    @csrf
    <div class="table-wrap">
    <table>
        <thead><tr><th style="width:240px">Display label</th><th>cat_name values <span class="muted" style="text-transform:none">(comma-separated, must match the lab exactly)</span></th></tr></thead>
        <tbody>
        @foreach ([...$rows, ['label' => '', 'cat_names' => []], ['label' => '', 'cat_names' => []], ['label' => '', 'cat_names' => []]] as $row)
            <tr>
                <td><input name="label[]" type="text" value="{{ $row['label'] }}" placeholder="Blood Test"></td>
                <td><input name="cat_names[]" type="text" value="{{ implode(', ', $row['cat_names']) }}" placeholder="Hematology, BioChemistry" style="max-width:none"></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p class="hint">Clear a label to remove its row. Three blank rows are added every time for new categories.</p>
    <div class="actions"><button class="btn" type="submit">Save Categories</button></div>
</form>
@endsection
