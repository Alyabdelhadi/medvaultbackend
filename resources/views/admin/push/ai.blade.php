@extends('admin.layout')
@section('title', 'AI Summary')
@section('content')
<h1>AI Summary</h1>
<p style="max-width:680px">Turns the <strong>Summarize by AI</strong> action on the results screen on and off. Off hides the button in the app and makes <code>/ai-summary</code> refuse with a 403, so nothing more is billed. Each lab must also opt in on its own edit screen.</p>
<form method="post" action="{{ route('admin.ai.save') }}" class="card" style="max-width:680px">
    @csrf
    <label class="check"><input type="checkbox" name="enabled" value="1" @checked($enabled)> <strong>Enable AI summaries</strong></label>
    <h2>Cached summaries</h2>
    <p>{{ number_format($cached) }} cached.</p>
    <label class="check"><input type="checkbox" name="flush" value="1"> Clear the cache when saving</label>
    <p class="hint">Summaries are cached per order for 30 days because results do not change. Clear only after changing the prompt or a lab's capability settings: every cleared summary is billed again the next time it is opened.</p>
    <h2>What the summaries are told</h2>
    <p class="hint">Each request carries the lab's capability flags. For a lab that records no reference ranges or no High/Low flags, the model is told not to state or imply that any value is normal: an absent flag is not a normal one.</p>
    <div class="actions"><button class="btn" type="submit">Save Changes</button></div>
</form>
@endsection
