@extends('admin.layout')
@section('title', $lab->exists ? $lab->title : 'Add New Lab')
@section('content')
@php
    $v = fn ($field, $default = '') => old($field, $lab->{$field} ?? $default);
    $checked = fn ($field, $default = false) => old($field, $lab->{$field} ?? $default) ? 'checked' : '';
@endphp
<h1>{{ $lab->exists ? 'Edit Lab' : 'Add New Lab' }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif

<form method="post" enctype="multipart/form-data" action="{{ $lab->exists ? route('admin.labs.update', $lab) : route('admin.labs.store') }}">
    @csrf
    @if ($lab->exists) @method('put') @endif

    <div class="card">
        <label for="title">Name</label>
        <input id="title" name="title" type="text" value="{{ $v('title') }}" required>
        <label for="content">Description</label>
        <textarea id="content" name="content">{{ $v('content') }}</textarea>
        <p class="hint">Shown in the app as plain text; any HTML is removed.</p>
        <label for="status">Status</label>
        <select id="status" name="status">
            @foreach (['publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private'] as $value => $label)
                <option value="{{ $value }}" @selected($v('status', 'publish') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="hint">Only published labs appear in the app or are polled for results.</p>
    </div>

    <h2>Lab Details</h2>
    <div class="card grid">
        <div>
            <label for="image_url">Lab image URL</label>
            <input id="image_url" name="image_url" type="url" value="{{ $v('image_url') }}">
            <input name="image_file" type="file" accept="image/*">
            <p class="hint">Upload to replace the URL.@if ($lab->thumbnail_url) Falls back to the imported featured image when empty.@endif</p>
            @if ($lab->image_url ?: $lab->thumbnail_url)
                <a class="image-preview" href="{{ $lab->image_url ?: $lab->thumbnail_url }}" data-lightbox="lab-image" data-caption="{{ $lab->title }}"><img src="{{ $lab->image_url ?: $lab->thumbnail_url }}" alt="{{ $lab->title }}"></a>
            @endif
        </div>
        @foreach (['location' => 'Location', 'google_map_url' => 'Google Maps URL', 'latitude' => 'Latitude (e.g. 33.8938)', 'longitude' => 'Longitude (e.g. 35.5018)', 'appointment_phone' => 'Appointment phone', 'mobile_phone' => 'Mobile phone', 'phone' => 'Phone number', 'email' => 'Email', 'website' => 'Website link', 'refer_link' => 'Refer link (overrides the global default)', 'facebook' => 'Facebook URL', 'instagram' => 'Instagram URL', 'twitter' => 'Twitter / X URL', 'linkedin' => 'LinkedIn URL', 'youtube' => 'YouTube URL', 'tiktok' => 'TikTok URL'] as $field => $label)
            <div>
                <label for="{{ $field }}">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="text" value="{{ $v($field) }}" @if ($field === 'refer_link') placeholder="{{ config('medvault.refer_link') }}" @endif>
            </div>
        @endforeach
    </div>
    <div class="card">
        <label for="refer_text">Refer text</label>
        <textarea id="refer_text" name="refer_text">{{ $v('refer_text') }}</textarea>
        <p class="hint">Shown alongside the refer link in the app.</p>
        <label>Lab slides</label>
        <p class="hint" style="margin-top:0">Shown in the app in this order. Use the bin to remove one and + to add images.</p>
        {{-- Kept slides post back as slides[]; a removed tile simply stops posting. --}}
        <div class="slide-grid" id="mv-slides">
            @foreach (old('slides', $lab->slides ?? []) as $slide)
                <div class="slide-tile">
                    <a href="{{ $slide }}" data-lightbox="lab-slides" data-caption="Slide {{ $loop->iteration }}" class="zoom-thumb"><img src="{{ $slide }}" alt="Slide {{ $loop->iteration }}" loading="lazy"></a>
                    <input type="hidden" name="slides[]" value="{{ $slide }}">
                    <button type="button" class="slide-remove" title="Remove slide" aria-label="Remove slide">@include('admin.partials.icon', ['name' => 'trash'])</button>
                </div>
            @endforeach
            <label class="slide-tile slide-add" title="Add slides" data-trash="{{ view('admin.partials.icon', ['name' => 'trash'])->render() }}">
                @include('admin.partials.icon', ['name' => 'plus'])
                <span>Add</span>
                <input id="mv-slide-files" name="slide_files[]" type="file" accept="image/*" multiple hidden>
            </label>
        </div>
        <script>
        (function () {
            var grid = document.getElementById('mv-slides');
            var input = document.getElementById('mv-slide-files');
            var add = grid.querySelector('.slide-add');
            var picked = []; // new files, kept in step with input.files

            function sync() {
                var dt = new DataTransfer();
                picked.forEach(function (f) { dt.items.add(f); });
                input.files = dt.files;
            }

            grid.addEventListener('click', function (e) {
                var btn = e.target.closest('.slide-remove');
                if (!btn) return;
                e.preventDefault();
                var tile = btn.closest('.slide-tile');
                if (tile.dataset.file !== undefined) {
                    var file = tile._file;
                    picked = picked.filter(function (f) { return f !== file; });
                    sync();
                }
                tile.remove();
            });

            input.addEventListener('change', function () {
                // A second pick adds to the first instead of replacing it.
                var fresh = Array.prototype.slice.call(input.files).filter(function (f) { return picked.indexOf(f) < 0; });
                picked = picked.concat(fresh);
                sync();
                fresh.forEach(function (file) {
                    var tile = document.createElement('div');
                    tile.className = 'slide-tile is-new';
                    tile.dataset.file = '';
                    tile._file = file;
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = file.name;
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'slide-remove';
                    btn.title = 'Remove slide';
                    btn.setAttribute('aria-label', 'Remove slide');
                    btn.innerHTML = grid.querySelector('.slide-add').dataset.trash;
                    tile.appendChild(img);
                    tile.appendChild(btn);
                    grid.insertBefore(tile, add);
                });
            });
        })();
        </script>
    </div>

    <h2>Lab Results API</h2>
    <div class="card">
        <p class="hint">Leave <strong>Enabled</strong> off for a directory-only lab with no results API. When no lab is enabled, the server falls back to the original Verdun settings.</p>
        <label class="check"><input type="checkbox" name="api_enabled" value="1" {{ $checked('api_enabled') }}> This lab has a results API</label>

        <label for="api_driver">Response format</label>
        <select id="api_driver" name="api_driver">
            @foreach ($options['drivers'] as $value => $label)
                <option value="{{ $value }}" @selected($v('api_driver', 'vmc_v1') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="hint">Pick a different format only when a facility's JSON genuinely differs.</p>

        <label for="api_base">Base URL</label>
        <input id="api_base" name="api_base" type="url" value="{{ $v('api_base') }}" placeholder="https://example.net/public/index.php/api/mobile">
        <p class="hint">No trailing slash. Endpoint paths are appended by the driver.</p>

        <label for="api_pdf_base">PDF base URL</label>
        <input id="api_pdf_base" name="api_pdf_base" type="url" value="{{ $v('api_pdf_base') }}" placeholder="https://example.net/storage/app/xxx/">
        <p class="hint">Only for facilities that serve report PDFs from a folder.</p>

        <label for="api_pdf_mode">PDF delivery</label>
        <select id="api_pdf_mode" name="api_pdf_mode">
            @foreach ($options['pdfModes'] as $value => $label)
                <option value="{{ $value }}" @selected($v('api_pdf_mode', 'static_base') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="hint">Both proxied modes stream through MedVault, so the lab token, extra headers and the facility's own URL stay on the server. Choose <strong>proxied</strong> for a folder that needs extra headers (a tunnel) or has no access control of its own: a directly fetchable report URL is one a patient can share with anybody.</p>

        <label for="mobile_format">Username format</label>
        <select id="mobile_format" name="mobile_format">
            @foreach ($options['mobileFormats'] as $value => $label)
                <option value="{{ $value }}" @selected($v('mobile_format', 'lb_trunk_zero') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="hint">How the mobile number is spelled when logging in to this lab. The wrong choice means "Invalid credentials" with no other symptom.</p>

        <label for="ready_signal">Result-ready signal</label>
        <select id="ready_signal" name="ready_signal">
            @foreach ($options['readySignals'] as $value => $label)
                <option value="{{ $value }}" @selected($v('ready_signal', 'status') === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <p class="hint">What tells the poller a result went from pending to available. Choose <strong>None</strong> when the facility returns a constant status, or every order alerts the moment it is created.</p>

        <label for="api_headers">Extra headers</label>
        <textarea id="api_headers" name="api_headers" placeholder="X-Api-Key: ..." style="font-family:monospace">{{ old('api_headers', $lab->api_headers) }}</textarea>
        <p class="hint">One <code>Key: Value</code> per line. Sent on every call to this lab. Stored encrypted; never reaches the app.</p>

        <label for="api_timeout">Timeout (seconds) and priority</label>
        <div class="actions" style="margin:0">
            <input id="api_timeout" name="api_timeout" type="number" min="1" max="60" value="{{ $v('api_timeout', 15) }}"> seconds
            <input name="api_priority" type="number" min="0" max="999" value="{{ $v('api_priority', 10) }}"> priority
        </div>
        <p class="hint">Lower priority is tried first and supplies the lab name shown after sign-in. 0 counts as 10, as before.</p>

        <h3 style="margin:18px 0 4px;font-size:15px">What this lab's data contains</h3>
        <p class="hint">Uncheck anything the facility does not record. The app hides the matching UI rather than drawing an empty version of it: a results gauge with no reference range reads to a patient as "normal".</p>
        <label class="check"><input type="checkbox" name="caps_ranges" value="1" {{ $checked('caps_ranges') }}> Reference ranges (<code>ref_range</code>)</label>
        <label class="check"><input type="checkbox" name="caps_flags" value="1" {{ $checked('caps_flags') }}> High / Low flags (<code>result_status</code>)</label>
        <label class="check"><input type="checkbox" name="caps_pdf" value="1" {{ $checked('caps_pdf') }}> Report PDFs (<code>pdf_path</code>)</label>

        <h3 style="margin:18px 0 4px;font-size:15px">Radiology</h3>
        <label class="check"><input type="checkbox" name="orders_enabled" value="1" {{ $checked('orders_enabled', true) }}> Fetch lab orders (<code>/request</code>)</label>
        <p class="hint">Untick for a radiology-only post: a facility whose imaging runs on a separate server is a second lab with its own base URL, radiology on and lab orders off. Fetching orders from that server would list a second, different copy of them.</p>
        <label class="check"><input type="checkbox" name="radiology_enabled" value="1" {{ $checked('radiology_enabled') }}> Also fetch imaging studies (<code>/radiology</code>)</label>
        <label for="radiology_base">Radiology base URL</label>
        <input id="radiology_base" name="radiology_base" type="url" value="{{ $v('radiology_base') }}" placeholder="Empty: the Base URL above">
        <p class="hint">Only when imaging runs on its own server. Patients get a separate login and token there, and being unknown to one server never blocks the other. No trailing slash.</p>
        <label for="radiology_pdf_base">Radiology PDF base URL</label>
        <input id="radiology_pdf_base" name="radiology_pdf_base" type="url" value="{{ $v('radiology_pdf_base') }}" placeholder="https://radiology.example.net/">
        <p class="hint">The folder study reports are served from on that server, delivered the same way as this lab's PDFs.</p>
        <p class="hint">Studies are listed with this lab's orders under the Radiology category, with the report PDF as their only content. A study counts as ready only once it has a report (<code>report_datetime</code> or <code>pdf_path</code>), never on <code>status</code>, and the first poll after switching this on only records, so past studies do not all alert at once. Leave off until the facility fills in the study name and report fields.</p>

        <h3 style="margin:18px 0 4px;font-size:15px">Paid features</h3>
        <label class="check"><input type="checkbox" name="ai_summary" value="1" {{ $checked('ai_summary') }}> <strong>AI summaries</strong></label>
        <p class="hint">Each summary is a billed API call. Off by default, and off everywhere while the global switch on the AI Summary page is off.</p>
    </div>

    <div class="actions sticky-actions">
        <button class="btn" type="submit">{{ $lab->exists ? 'Update Lab' : 'Create Lab' }}</button>
        <a class="btn secondary" href="{{ route('admin.labs.index') }}">Cancel</a>
        @if ($lab->exists)
            {{-- In the same bar, at the far end. It submits its own form below:
                 forms cannot nest, and this one must not send the lab's fields. --}}
            <button class="btn {{ $lab->status === 'trash' ? 'secondary' : 'danger' }}" type="submit" form="mv-lab-trash" style="margin-inline-start:auto">{{ $lab->status === 'trash' ? 'Restore' : 'Move to Trash' }}</button>
        @endif
    </div>
</form>

@if ($lab->exists)
    <form id="mv-lab-trash" method="post" action="{{ $lab->status === 'trash' ? route('admin.labs.restore', $lab) : route('admin.labs.trash', $lab) }}" @if ($lab->status !== 'trash') data-confirm="Move this lab to the trash? It disappears from the app." data-confirm-detail="You can restore it from the trash later." data-confirm-action="Move to trash" @endif>
        @csrf
    </form>
@endif
@endsection
