<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Blind waste tally: mark the images that do not belong, nothing else.
 *
 * Preference told us no model wins. This asks the other question — how much
 * each index throws in that should never have been proposed. Still blind, and
 * more strictly so than the preference page: the reviewer arrived here with a
 * suspicion about one specific model, and knowing which series is whose would
 * decide the count before the first click.
 */
final class SuggestionWastePage
{
    /**
     * @param list<array<string, mixed>> $cases
     */
    public static function render(array $cases, string $verdictFile, string $restUrl, string $token): string
    {
        $payload = wp_json_encode([
            'cases' => $cases,
            'file' => $verdictFile,
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
<title>Suggestion média — images hors sujet</title>
<style>{$css}</style>
</head>
<body>
<header>
  <div>
    <h1>Cliquez sur les images qui n'ont rien à faire là</h1>
    <p id="progress"></p>
  </div>
  <div class="actions">
    <button id="reveal" type="button" class="ghost">Révéler les modèles</button>
    <button id="save" type="button">Enregistrer</button>
  </div>
</header>
<main id="app"></main>
<div id="toast" role="status"></div>
<script>window.WASTE = {$payload};</script>
<script>{$js}</script>
</body>
</html>
HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
:root { --bg:#f6f7f9; --card:#fff; --ink:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#2563eb; --bad:#dc2626; }
* { box-sizing:border-box; }
body { margin:0; font:15px/1.55 -apple-system, system-ui, "Segoe UI", sans-serif; background:var(--bg); color:var(--ink); }
header { position:sticky; top:0; z-index:10; display:flex; gap:24px; align-items:center; justify-content:space-between;
  padding:14px 24px; background:var(--card); border-bottom:1px solid var(--line); }
h1 { margin:0; font-size:17px; }
#progress { margin:2px 0 0; color:var(--muted); font-size:13px; }
.actions { display:flex; gap:10px; }
button { font:inherit; padding:8px 16px; border-radius:6px; border:1px solid var(--accent); background:var(--accent); color:#fff; cursor:pointer; }
button.ghost { background:transparent; color:var(--muted); border-color:var(--line); }
main { padding:24px; max-width:1500px; margin:0 auto; }
.case { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:22px; }
.ctx { border-left:3px solid var(--line); padding-left:14px; margin-bottom:16px; }
.ctx h2 { margin:0 0 4px; font-size:15px; }
.ctx .meta { color:var(--muted); font-size:12px; margin:0 0 8px; }
.ctx p { margin:0; color:#3f3f46; font-size:13px; }
.series { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px,1fr)); gap:16px; }
.serie { border:1px solid var(--line); border-radius:8px; padding:12px; background:#fafafa; }
.serie h3 { margin:0 0 10px; font-size:12px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.model { font-size:11px; color:var(--muted); margin-top:8px; font-family:ui-monospace,monospace; display:none; }
.revealed .model { display:block; }
.imgs { display:flex; gap:8px; }
figure { margin:0; flex:1; cursor:pointer; position:relative; border-radius:4px; overflow:hidden; }
figure img { width:100%; height:96px; object-fit:cover; display:block; background:#e4e4e7; transition:opacity .12s, filter .12s; }
figure.bad img { opacity:.35; filter:grayscale(1); }
figure.bad::after { content:"✕"; position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
  color:var(--bad); font-size:34px; font-weight:700; pointer-events:none; }
figcaption { font-size:11px; color:var(--muted); text-align:center; margin-top:3px; }
figure.bad figcaption { color:var(--bad); font-weight:600; }
#toast { position:fixed; bottom:22px; left:50%; transform:translateX(-50%) translateY(20px); background:var(--ink);
  color:#fff; padding:11px 20px; border-radius:6px; opacity:0; transition:all .2s; pointer-events:none; font-size:14px; }
#toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
#toast.err { background:#b91c1c; }
CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
  var data = window.WASTE;
  var cases = data.cases;
  var app = document.getElementById('app');
  var revealed = false;

  cases.forEach(function (c) {
    c.series.forEach(function (s) { s.bad = s.bad || []; });
  });

  function progress() {
    var marked = 0, total = 0;
    cases.forEach(function (c) {
      c.series.forEach(function (s) { marked += s.bad.length; total += s.images.length; });
    });
    document.getElementById('progress').textContent =
      marked + ' image(s) signalée(s) sur ' + total + ' — cliquez seulement sur celles qui vous choquent';
  }

  function render() {
    app.innerHTML = '';
    cases.forEach(function (c) {
      var el = document.createElement('section');
      el.className = 'case' + (revealed ? ' revealed' : '');
      var ctx = c.context || {};
      var head = document.createElement('div');
      head.className = 'ctx';
      head.innerHTML =
        '<h2>' + (ctx.section_heading || '(sans titre de section)') + '</h2>' +
        '<p class="meta">' + (ctx.post_title || '') + '</p>' +
        '<p>' + (ctx.preceding_text || '').slice(-300) + '</p>';
      el.appendChild(head);

      var grid = document.createElement('div');
      grid.className = 'series';
      c.series.forEach(function (s) {
        var box = document.createElement('div');
        box.className = 'serie';
        box.innerHTML = '<h3>Série ' + s.letter + '</h3>';
        var imgs = document.createElement('div');
        imgs.className = 'imgs';
        s.images.forEach(function (im) {
          var fig = document.createElement('figure');
          fig.className = s.bad.indexOf(im.id) > -1 ? 'bad' : '';
          fig.innerHTML = '<img loading="lazy" src="' + im.url + '" alt=""><figcaption>' + im.id + '</figcaption>';
          fig.addEventListener('click', function () {
            var at = s.bad.indexOf(im.id);
            if (at > -1) { s.bad.splice(at, 1); } else { s.bad.push(im.id); }
            render();
          });
          imgs.appendChild(fig);
        });
        box.appendChild(imgs);
        var m = document.createElement('div');
        m.className = 'model';
        m.textContent = s.model;
        box.appendChild(m);
        grid.appendChild(box);
      });
      el.appendChild(grid);
      app.appendChild(el);
    });
    progress();
  }

  function toast(msg, err) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show' + (err ? ' err' : '');
    setTimeout(function () { t.className = ''; }, 3500);
  }

  document.getElementById('reveal').addEventListener('click', function () {
    revealed = !revealed;
    this.textContent = revealed ? 'Masquer les modèles' : 'Révéler les modèles';
    render();
  });

  document.getElementById('save').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    var payload = [];
    cases.forEach(function (c) {
      c.series.forEach(function (s) {
        payload.push({ block: c.block, model: s.model, shown: s.images.map(function (i) { return i.id; }), bad: s.bad });
      });
    });
    fetch(data.rest, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-AIForge-Picker-Token': data.token },
      body: JSON.stringify({ file: data.file, queries: payload })
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (!res.ok) { throw new Error((res.body && res.body.message) || 'échec'); }
        toast('Enregistré');
      })
      .catch(function (e) { toast('Échec : ' + e.message, true); })
      .finally(function () { btn.disabled = false; });
  });

  render();
})();
JS;
    }
}
