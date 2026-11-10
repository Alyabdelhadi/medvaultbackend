<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $name }} · MedVault</title>
<style>
    :root { --bg: #f2f4f7; --bar: #ffffff; --text: #1d2939; --muted: #667085; --brand: #0b6bcb; --brand-text: #ffffff; --line: #e4e7ec; }
    @media (prefers-color-scheme: dark) {
        :root { --bg: #101418; --bar: #1a2027; --text: #e6e9ee; --muted: #98a2b3; --brand: #3d8fe0; --line: #2a323c; }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    header { position: sticky; top: 0; z-index: 1; display: flex; align-items: center; gap: 12px; padding: 10px 16px; background: var(--bar); border-bottom: 1px solid var(--line); }
    header h1 { flex: 1; min-width: 0; margin: 0; font-size: 15px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .download { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 16px; border-radius: 10px; background: var(--brand); color: var(--brand-text); font-weight: 600; text-decoration: none; white-space: nowrap; }
    .download:focus-visible { outline: 3px solid var(--text); outline-offset: 2px; }
    .download svg { width: 20px; height: 20px; flex: none; }
    main { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 12px; }
    canvas { display: block; width: 100%; max-width: 900px; height: auto; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.15); }
    .status { padding: 32px 16px; color: var(--muted); text-align: center; }
    .status a { color: var(--brand); }
</style>
</head>
<body>
<header>
    <h1>{{ $name }}</h1>
    <a class="download" href="{{ $downloadUrl }}" download="{{ $name }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>
        Download PDF
    </a>
</header>
<main id="pages">
    <p class="status" id="status">Loading report…</p>
</main>
{{-- PDF.js draws every page itself: phone browsers will not show a PDF inside a page. --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function () {
    var raw = @json($rawUrl);
    var pages = document.getElementById('pages');
    var status = document.getElementById('status');
    function fail() {
        status.innerHTML = 'The report could not be shown here. <a href="' + raw.replace(/"/g, '&quot;') + '">Open the PDF</a> or use Download PDF.';
    }
    if (!window.pdfjsLib) { fail(); return; }
    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    pdfjsLib.getDocument(raw).promise.then(function (pdf) {
        status.remove();
        var scale = Math.min(window.devicePixelRatio || 1, 2) * 1.5;
        var chain = Promise.resolve();
        for (var n = 1; n <= pdf.numPages; n++) {
            chain = chain.then(pdf.getPage.bind(pdf, n)).then(function (page) {
                var viewport = page.getViewport({ scale: scale });
                var canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                pages.appendChild(canvas);
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
            });
        }
        return chain;
    }).catch(fail);
})();
</script>
</body>
</html>
