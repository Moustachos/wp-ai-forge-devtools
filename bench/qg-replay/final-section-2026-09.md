# Lost final section: replay on every stored generation (2026-09-27)

Rule under test: `OutputValidator::lostFinalSection()` (plugin commit `0a3bf35`, branch
`fix/editor-net-final-section`). A generation's final section is lost when completion is
`'warning'`, the source's last `##` heading is reported missing by `checkSectionCoverage()`,
and none of that section's 5+-word sentences has its first 5 words anywhere in the output.

Zero API cost: the replay reads stored payloads only.

## Commands

```bash
cd wp-lab
npx wp-env run cli -- timeout 900 wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/qg-replay/final-section.php since=2026-06-01
npx wp-env run cli -- timeout 900 wp eval-file wp-content/plugins/wp-ai-forge-devtools/bench/qg-replay/final-section.php since=2000-01-01
```

The second run covers the whole history (first completed generation: 2026-02-11), so the
784 generations older than June are read too.

## Counts

| Window | Scanned | Completion warnings | Lost final sections |
|---|---|---|---|
| since 2026-06-01 | 537 | 27 | 5 |
| whole history | 1321 | 62 | 13 |

The four known cases (7470, 7475, 7494, 7499) are among the hits.

## Every hit, read by hand

Source = last `##` section of the parent's `markdown_snapshot`; output = the task's `result_content`.

| Task | Model | Template | Stored verdict | Lost heading | Class | Evidence |
|---|---|---|---|---|---|---|
| 834 | (none) | Page Services | (none, pre-QG) | Discutons de votre projet | lost | "Références clients" and the contact block both gone: no `contact@`, no "Réserver", no "Échangeons"; page stops at the cross-platform paragraph. |
| 900 | (none) | Article de blog | (none, pre-QG) | Le résultat après deux ans | lost | Output stops on the "Habitude 5" heading with no body; "six heures", "remerciera" absent. |
| 934 | (none) | Portfolio / Cas client | (none, pre-QG) | Les résultats chiffrés | lost | Results table and testimonial absent: no "27 500", no "58", no "FitFlow a transformé"; ends on the tech stack list. |
| 938 | (none) | Portfolio / Cas client | (none, pre-QG) | Impact | lost | "Web Summit" and "1,2M€" absent; page ends on the deliverables list. |
| 1202 | (none) | Page de service | (none, pre-QG) | Prendre rendez-vous | lost | Output is the intro cover only (2 text lines); every section after the intro, the appointment block included, is gone. |
| 1272 | (none) | Article de blog | (none, pre-QG) | Conclusion : la passion avant tout | lost | Output is a single empty cover block, no text at all. |
| 1276 | (none) | Article de blog | (none, pre-QG) | Conclusion : Commencez simplement | lost | Same as 1272: a single empty cover block, no text. |
| 3291 | gemini-3-flash-preview | Page de service | (none) | Demander un devis gratuit | lost | Page ends on the "Zone d'intervention" list; no "devis", no "04 94", no request to send photos. |
| 6289 | claude-sonnet-5 | Article de blog | warnings | Travailler avec nous | lost | Page ends on the missions list; "recommandation", "Prendre contact", "48 heures" and "première conversation" all absent. |
| 7470 | claude-sonnet-5 | Page d'atterrissage | warnings | Getting started | lost | Known case: trial, demo and support line (555-0147) absent; ends on the setup FAQ. |
| 7475 | claude-sonnet-5 | Page d'atterrissage | warnings | Où nous trouver | lost | Known case: address and phone block absent; ends on the maintenance tips. |
| 7494 | claude-sonnet-5 | Page d'atterrissage | warnings | Getting started | lost | Known case: trial, demo and support line absent; ends on the setup FAQ and a template button. |
| 7499 | claude-sonnet-5 | Page d'atterrissage | warnings | Où nous trouver | lost | Known case: address and phone block absent; ends on the tips paragraph. |

13 hits, 13 lost, 0 sound.

## Rule change

None. No hit is a sound page, so the rule is wired as written in the G1 decision. The
suggested fallback (60 % significant-word overlap) was not needed and was not tried.

Note on 1202, 1272 and 1276: these are not a lost final section as much as a page that lost
almost everything, yet completion rated them `'warning'` because every block was closed.
Blocking them is right; the new reason is simply the first one to catch them.

## Effect once Task 6 lands

5 runs currently stored as `warnings` (6289, 7470, 7475, 7494, 7499) would have been `fail`;
no stored `pass` run is affected. The 8 older hits carry no Quality Gate verdict. Stored
verdicts are not recomputed: the rule applies to new generations only.
