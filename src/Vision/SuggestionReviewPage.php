<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Blind A/B/C/D comparison of media suggestions.
 *
 * Which model produced a series is in the payload but never shown until the
 * reviewer asks: knowing which one is the candidate would settle the question
 * before looking at the images. Series order is shuffled per block so position
 * carries no signal either.
 */
final class SuggestionReviewPage
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
<title>Suggestion média — comparaison à l'aveugle</title>
<style>{$css}</style>
</head>
<body>
<header>
  <div>
    <h1>Quelle série d'images conviendrait le mieux ?</h1>
    <p id="progress"></p>
  </div>
  <div class="actions">
    <button id="reveal" type="button" class="ghost">Révéler les modèles</button>
    <button id="save" type="button">Enregistrer</button>
  </div>
</header>
<main id="app"></main>
<div id="toast" role="status"></div>
<script>window.REVIEW = {$payload};</script>
<script>{$js}</script>
</body>
</html>
HTML;
    }

    private static function css(): string
    {
        return <<<'CSS'
:root { --bg:#f6f7f9; --card:#fff; --ink:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#2563eb; --ok:#15803d; }
* { box-sizing: border-box; }
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
.case.done { border-color:#bbf7d0; }
.ctx { border-left:3px solid var(--line); padding-left:14px; margin-bottom:18px; }
.ctx h2 { margin:0 0 4px; font-size:15px; }
.ctx .meta { color:var(--muted); font-size:12px; margin:0 0 8px; }
.ctx p { margin:0; color:#3f3f46; font-size:13px; }
.ctx .after { color:var(--muted); }
.series { display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px; }
.serie { border:2px solid var(--line); border-radius:8px; padding:12px; cursor:pointer; background:#fafafa; transition:border-color .12s; }
.serie:hover { border-color:#c7d2fe; }
.serie.on { border-color:var(--accent); background:#eff6ff; }
.serie h3 { margin:0 0 10px; font-size:13px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
.serie.on h3 { color:var(--accent); }
.model { font-size:11px; color:var(--muted); margin-top:8px; font-family:ui-monospace, monospace; display:none; }
.revealed .model { display:block; }
.imgs { display:flex; gap:8px; }
.imgs figure { margin:0; flex:1; }
.imgs img { width:100%; height:96px; object-fit:cover; border-radius:4px; display:block; background:#e4e4e7; }
.imgs figcaption { font-size:11px; color:var(--muted); text-align:center; margin-top:3px; }
.rank { font-size:10px; color:var(--muted); }
.none { margin-top:12px; font-size:13px; color:var(--muted); }
.none label { cursor:pointer; }
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
  var data = window.REVIEW;
  var cases = data.cases;
  var app = document.getElementById('app');
  var revealed = false;

  function progress() {
    var done = cases.filter(function (c) { return c.verdict !== null && c.verdict !== undefined; }).length;
    document.getElementById('progress').textContent = done + ' / ' + cases.length + ' cas jugés';
  }

  function render() {
    app.innerHTML = '';
    cases.forEach(function (c, ci) {
      var el = document.createElement('section');
      el.className = 'case' + (c.verdict != null ? ' done' : '') + (revealed ? ' revealed' : '');

      var ctx = c.context || {};
      var head = document.createElement('div');
      head.className = 'ctx';
      head.innerHTML =
        '<h2>' + (ctx.section_heading || '(sans titre de section)') + '</h2>' +
        '<p class="meta">' + (ctx.post_title || '') + ' — image ' + (ctx.image_position || '') +
        ', densité ' + (ctx.text_density || '') + '</p>' +
        '<p>' + (ctx.preceding_text || '').slice(-320) + '</p>' +
        '<p class="after">' + (ctx.following_text || '').slice(0, 220) + '</p>';
      el.appendChild(head);

      var grid = document.createElement('div');
      grid.className = 'series';

      c.series.forEach(function (s) {
        var box = document.createElement('div');
        box.className = 'serie' + (c.verdict === s.letter ? ' on' : '');
        var imgs = s.images.map(function (im, i) {
          return '<figure><img loading="lazy" src="' + im.url + '" alt="">' +
                 '<figcaption><span class="rank">#' + (i + 1) + '</span> ' + im.id + '</figcaption></figure>';
        }).join('');
        box.innerHTML = '<h3>Série ' + s.letter + '</h3><div class="imgs">' + imgs + '</div>' +
                        '<div class="model">' + s.model + '</div>';
        box.addEventListener('click', function () {
          c.verdict = (c.verdict === s.letter) ? null : s.letter;
          render();
        });
        grid.appendChild(box);
      });

      el.appendChild(grid);

      var none = document.createElement('div');
      none.className = 'none';
      none.innerHTML = '<label><input type="radio" name="eq' + ci + '"' +
        (c.verdict === 'equal' ? ' checked' : '') + '> aucune ne se détache</label>';
      none.querySelector('input').addEventListener('click', function () {
        c.verdict = (c.verdict === 'equal') ? null : 'equal';
        render();
      });
      el.appendChild(none);

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
    var payload = cases.map(function (c) {
      var chosen = (c.series.filter(function (s) { return s.letter === c.verdict; })[0] || {}).model || null;
      return { block: c.block, verdict: c.verdict, winner: chosen,
               section: (c.context || {}).section_heading || '' };
    });
    fetch(data.rest, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-AIForge-Picker-Token': data.token },
      body: JSON.stringify({ file: data.file, queries: payload })
    })
      .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
      .then(function (res) {
        if (!res.ok) { throw new Error((res.body && res.body.message) || 'échec'); }
        toast('Verdicts enregistrés');
      })
      .catch(function (e) { toast('Échec : ' + e.message, true); })
      .finally(function () { btn.disabled = false; });
  });

  render();
})();
JS;
    }
}
