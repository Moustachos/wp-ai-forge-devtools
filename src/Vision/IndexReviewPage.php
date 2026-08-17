<?php

declare(strict_types=1);

namespace AIForge\Vision;

/**
 * Blind adjudication of the facets the indexes disagree on.
 *
 * The reviewer answers the question the models were asked, not which model
 * looks right, so the answer stays usable whatever the majority said. Images
 * are shown uncropped: `object-fit: cover` hides exactly the corner where the
 * disputed text or the fourth person sits.
 */
final class IndexReviewPage
{
    private const LABELS = [
        'has_text' => 'Y a-t-il du texte lisible dans cette image ?',
        'people_count' => 'Combien de personnes voit-on ?',
        'image_type' => 'De quelle nature est cette image ?',
    ];

    private const VALUES = [
        '0' => 'Aucun texte',
        '1' => 'Du texte',
        'none' => 'Aucune',
        'single' => 'Une',
        'couple' => 'Deux',
        'small_group' => 'Un petit groupe',
        'crowd' => 'Une foule',
        'photo' => 'Photo',
        'illustration' => 'Illustration',
        'graphic' => 'Graphique',
        'screenshot' => 'Capture d\'écran',
        'render_3d' => 'Rendu 3D',
    ];

    /**
     * @param list<array<string, mixed>> $cases
     */
    public static function render(array $cases, string $verdictFile, string $restUrl, string $token): string
    {
        $payload = wp_json_encode([
            'cases' => $cases,
            'labels' => self::LABELS,
            'values' => self::VALUES,
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
<title>Index média — arbitrage des désaccords</title>
<style>{$css}</style>
</head>
<body>
<header>
  <div>
    <h1>Répondez à la question posée par l'image</h1>
    <p id="progress"></p>
  </div>
  <div class="actions">
    <button id="reveal" type="button" class="ghost">Révéler les modèles</button>
    <button id="save" type="button">Enregistrer</button>
  </div>
</header>
<main id="app"></main>
<div id="lightbox"><img alt=""></div>
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
:root { --bg:#f6f7f9; --card:#fff; --ink:#18181b; --muted:#71717a; --line:#e4e4e7; --accent:#2563eb; --ok:#16a34a; }
* { box-sizing:border-box; }
body { margin:0; font:15px/1.55 -apple-system, system-ui, "Segoe UI", sans-serif; background:var(--bg); color:var(--ink); }
header { position:sticky; top:0; z-index:10; display:flex; gap:24px; align-items:center; justify-content:space-between;
  padding:14px 24px; background:var(--card); border-bottom:1px solid var(--line); }
h1 { margin:0; font-size:17px; }
#progress { margin:2px 0 0; color:var(--muted); font-size:13px; }
.actions { display:flex; gap:10px; }
button { font:inherit; padding:8px 16px; border-radius:6px; border:1px solid var(--accent); background:var(--accent); color:#fff; cursor:pointer; }
button.ghost { background:transparent; color:var(--muted); border-color:var(--line); }
main { padding:24px; max-width:1100px; margin:0 auto; }
.case { display:grid; grid-template-columns:340px 1fr; gap:24px; background:var(--card); border:1px solid var(--line);
  border-radius:10px; padding:20px; margin-bottom:20px; align-items:start; }
.case.done { border-color:var(--ok); }
.shot { background-color:#fafafa;
  background-image:linear-gradient(45deg,#eee 25%,transparent 25%,transparent 75%,#eee 75%),
                   linear-gradient(45deg,#eee 25%,transparent 25%,transparent 75%,#eee 75%);
  background-size:16px 16px; background-position:0 0,8px 8px; border:1px solid var(--line); border-radius:8px; padding:6px; cursor:zoom-in; }
.shot img { width:100%; max-height:320px; object-fit:contain; display:block; }
.shot figcaption { font-size:11px; color:var(--muted); text-align:center; margin-top:6px; font-family:ui-monospace,monospace; }
.q { margin:0 0 14px; font-size:16px; font-weight:600; }
.opts { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
.opts button { background:transparent; color:var(--ink); border-color:var(--line); }
.opts button.on { background:var(--ok); border-color:var(--ok); color:#fff; }
.said { font-size:12px; color:var(--muted); font-family:ui-monospace,monospace; display:none; }
.revealed .said { display:block; }
.said span { display:inline-block; margin-right:14px; }
#lightbox { position:fixed; inset:0; background:rgba(24,24,27,.92); display:none; align-items:center; justify-content:center; z-index:50; cursor:zoom-out; }
#lightbox.on { display:flex; }
#lightbox img { max-width:94vw; max-height:94vh; object-fit:contain; }
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
  var lightbox = document.getElementById('lightbox');
  var revealed = false;

  function label(v) { return data.values[v] || v; }

  function progress() {
    var done = cases.filter(function (c) { return c.verdict != null; }).length;
    document.getElementById('progress').textContent = done + ' / ' + cases.length + ' arbitré(s)';
  }

  function render() {
    app.innerHTML = '';
    cases.forEach(function (c, idx) {
      var el = document.createElement('section');
      el.className = 'case' + (revealed ? ' revealed' : '') + (c.verdict != null ? ' done' : '');

      var fig = document.createElement('figure');
      fig.className = 'shot';
      fig.style.margin = '0';
      fig.innerHTML = '<img loading="lazy" src="' + c.url + '" alt="">' +
        '<figcaption>#' + c.attachment_id + ' · ' + (c.width || '?') + '×' + (c.height || '?') + '</figcaption>';
      fig.addEventListener('click', function () {
        lightbox.querySelector('img').src = c.full || c.url;
        lightbox.className = 'on';
      });
      el.appendChild(fig);

      var right = document.createElement('div');
      var q = document.createElement('p');
      q.className = 'q';
      q.textContent = data.labels[c.facet] || c.facet;
      right.appendChild(q);

      var opts = document.createElement('div');
      opts.className = 'opts';
      c.options.forEach(function (o) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = label(o);
        b.className = c.verdict === o ? 'on' : '';
        b.addEventListener('click', function () {
          c.verdict = c.verdict === o ? null : o;
          render();
          if (c.verdict != null && idx + 1 < cases.length) {
            app.children[idx + 1].scrollIntoView({ behavior: 'smooth', block: 'center' });
          }
        });
        opts.appendChild(b);
      });
      right.appendChild(opts);

      var said = document.createElement('div');
      said.className = 'said';
      said.innerHTML = Object.keys(c.answers).map(function (m) {
        return '<span>' + m + ' : ' + label(c.answers[m]) + '</span>';
      }).join('');
      right.appendChild(said);

      el.appendChild(right);
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

  lightbox.addEventListener('click', function () { lightbox.className = ''; });

  document.getElementById('reveal').addEventListener('click', function () {
    revealed = !revealed;
    this.textContent = revealed ? 'Masquer les modèles' : 'Révéler les modèles';
    render();
  });

  document.getElementById('save').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    var payload = cases.map(function (c) {
      return { attachment_id: c.attachment_id, facet: c.facet, verdict: c.verdict, answers: c.answers };
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
