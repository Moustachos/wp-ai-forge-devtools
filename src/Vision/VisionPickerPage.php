<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Builds a self-contained HTML page for filling in a benchmark query file.
 *
 * Written to the uploads directory and opened over the site's own origin, so
 * the thumbnails load from the same host and the cookie already in the browser
 * authenticates the save call. The nonce is baked in at generation time.
 */
final class VisionPickerPage
{
    /**
     * @param list<array<string, mixed>> $queries   Raw entries, candidates included
     * @param array<int, array{url: string, title: string}> $images  Sample images by id
     */
    public static function render(array $queries, array $images, string $fileName, string $restUrl, string $token): string
    {
        $payload = wp_json_encode([
            'queries' => $queries,
            'images' => $images,
            'file' => $fileName,
            'rest' => $restUrl,
            'token' => $token,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $css = self::css();
        $js = self::js();

        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vision bench — sélection des visuels</title>
<style>{$css}</style>
</head>
<body>
<header>
  <div>
    <h1>Sélection des visuels attendus</h1>
    <p id="progress"></p>
  </div>
  <div class="actions">
    <label class="toggle"><input type="checkbox" id="all-images"> Voir toute la médiathèque</label>
    <button id="save" type="button">Enregistrer</button>
  </div>
</header>
<main id="app"></main>
<div id="toast" role="status"></div>
<script>window.BENCH = {$payload};</script>
<script>{$js}</script>
</body>
</html>
HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
:root {
  --bg: #f6f7f9; --card: #fff; --ink: #18181b; --muted: #71717a;
  --line: #e4e4e7; --accent: #2563eb; --ok: #15803d; --trap: #b45309;
}
* { box-sizing: border-box; }
body { margin: 0; font: 15px/1.5 -apple-system, system-ui, "Segoe UI", sans-serif; background: var(--bg); color: var(--ink); }
header {
  position: sticky; top: 0; z-index: 10; display: flex; gap: 24px; align-items: center;
  justify-content: space-between; padding: 14px 24px; background: var(--card);
  border-bottom: 1px solid var(--line);
}
h1 { margin: 0; font-size: 17px; }
#progress { margin: 2px 0 0; color: var(--muted); font-size: 13px; }
.actions { display: flex; align-items: center; gap: 16px; }
.toggle { font-size: 13px; color: var(--muted); display: flex; align-items: center; gap: 6px; cursor: pointer; }
button {
  font: inherit; padding: 8px 18px; border-radius: 6px; border: 1px solid var(--accent);
  background: var(--accent); color: #fff; cursor: pointer;
}
button:disabled { opacity: .5; cursor: default; }
main { padding: 24px; max-width: 1400px; margin: 0 auto; }
.q { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; }
.q.done { border-color: #bbf7d0; }
.q.trap { background: #fffbeb; border-color: #fde68a; }
.q-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
.q-head h2 { margin: 0; font-size: 16px; font-weight: 600; }
.badge { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; padding: 3px 8px; border-radius: 20px; background: #eef2ff; color: #3730a3; }
.badge.piege { background: #fef3c7; color: var(--trap); }
.badge.fine { background: #f3e8ff; color: #6b21a8; }
.badge.moyenne { background: #e0f2fe; color: #075985; }
.hint { color: var(--muted); font-size: 13px; margin: 0 0 12px; }
.count { margin-left: auto; font-size: 13px; color: var(--muted); }
.count.filled { color: var(--ok); font-weight: 600; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
figure { margin: 0; cursor: pointer; border: 2px solid transparent; border-radius: 8px; padding: 4px; background: #fafafa; transition: border-color .12s; }
figure:hover { border-color: #c7d2fe; }
figure.on { border-color: var(--accent); background: #eff6ff; }
figure img { width: 100%; height: 110px; object-fit: cover; border-radius: 4px; display: block; background: #e4e4e7; }
figcaption { font-size: 12px; color: var(--muted); margin-top: 5px; display: flex; justify-content: space-between; gap: 6px; }
figure.on figcaption { color: var(--accent); font-weight: 600; }
.extra { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--line); }
.extra summary { cursor: pointer; font-size: 13px; color: var(--muted); }
.extra[open] summary { margin-bottom: 12px; }
#toast {
  position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%) translateY(20px);
  background: var(--ink); color: #fff; padding: 11px 20px; border-radius: 6px;
  opacity: 0; transition: all .2s; pointer-events: none; font-size: 14px;
}
#toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
#toast.err { background: #b91c1c; }
CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
  var data = window.BENCH;
  var queries = data.queries;
  var images = data.images;
  var app = document.getElementById('app');
  var showAll = false;

  function imgIds() {
    return Object.keys(images).map(Number).sort(function (a, b) { return a - b; });
  }

  function progress() {
    var need = queries.filter(function (q) { return q.type !== 'piege'; });
    var done = need.filter(function (q) { return (q.expect || []).length > 0; });
    document.getElementById('progress').textContent =
      done.length + ' / ' + need.length + ' requêtes remplies · ' +
      queries.filter(function (q) { return q.type === 'piege'; }).length + ' pièges (rien à faire)';
  }

  function tile(id, q) {
    var meta = images[id] || { url: '', title: '#' + id };
    var fig = document.createElement('figure');
    fig.className = (q.expect || []).indexOf(id) > -1 ? 'on' : '';
    fig.innerHTML =
      '<img loading="lazy" src="' + meta.url + '" alt="">' +
      '<figcaption><span>#' + id + '</span><span>' + (meta.title || '') + '</span></figcaption>';
    fig.addEventListener('click', function () {
      q.expect = q.expect || [];
      var at = q.expect.indexOf(id);
      if (at > -1) { q.expect.splice(at, 1); } else { q.expect.push(id); }
      q.expect.sort(function (a, b) { return a - b; });
      render();
    });
    return fig;
  }

  function card(q, index) {
    var el = document.createElement('section');
    var filled = (q.expect || []).length > 0;
    el.className = 'q' + (q.type === 'piege' ? ' trap' : (filled ? ' done' : ''));

    var head = document.createElement('div');
    head.className = 'q-head';
    head.innerHTML =
      '<span class="badge ' + (q.type || '') + '">' + (q.type || '?') + '</span>' +
      '<h2>' + q.query + '</h2>' +
      '<span class="count' + (filled ? ' filled' : '') + '">' +
      (q.type === 'piege' ? 'piège — laisser vide' : (q.expect || []).length + ' sélectionné(s)') +
      '</span>';
    el.appendChild(head);

    if (q.hint) {
      var hint = document.createElement('p');
      hint.className = 'hint';
      hint.textContent = q.hint;
      el.appendChild(hint);
    }

    var candidates = q.candidates || [];
    if (candidates.length) {
      var grid = document.createElement('div');
      grid.className = 'grid';
      candidates.forEach(function (id) { grid.appendChild(tile(id, q)); });
      el.appendChild(grid);
    }

    // Anything already picked outside the suggested candidates stays visible.
    var extras = (q.expect || []).filter(function (id) { return candidates.indexOf(id) === -1; });
    if (extras.length) {
      var eg = document.createElement('div');
      eg.className = 'grid';
      eg.style.marginTop = '12px';
      extras.forEach(function (id) { eg.appendChild(tile(id, q)); });
      el.appendChild(eg);
    }

    if (showAll) {
      var det = document.createElement('details');
      det.className = 'extra';
      det.open = true;
      det.innerHTML = '<summary>Toute la médiathèque</summary>';
      var all = document.createElement('div');
      all.className = 'grid';
      imgIds().forEach(function (id) {
        if (candidates.indexOf(id) > -1) { return; }
        all.appendChild(tile(id, q));
      });
      det.appendChild(all);
      el.appendChild(det);
    }

    return el;
  }

  function render() {
    app.innerHTML = '';
    queries.forEach(function (q, i) { app.appendChild(card(q, i)); });
    progress();
  }

  function toast(msg, isError) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show' + (isError ? ' err' : '');
    setTimeout(function () { t.className = ''; }, 3500);
  }

  document.getElementById('all-images').addEventListener('change', function (e) {
    showAll = e.target.checked;
    render();
  });

  document.getElementById('save').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    fetch(data.rest, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-AIForge-Picker-Token': data.token },
      body: JSON.stringify({ file: data.file, queries: queries })
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (!res.ok) { throw new Error((res.body && res.body.message) || 'échec'); }
        toast('Enregistré : ' + res.body.filled + ' remplies, ' + res.body.traps + ' pièges');
      })
      .catch(function (e) { toast('Échec : ' + e.message + ' (es-tu connecté à wp-admin ?)', true); })
      .finally(function () { btn.disabled = false; });
  });

  render();
})();
JS;
    }
}
