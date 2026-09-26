# Hard corpus, first campaign (2026-09-26)

Spec S17 (`wp-ai-forge/docs/specs/2026-09-hard-ci-corpus.md`), criteria 3 to 5. Template: the landing
(`page-datterrissage`), Balanced preset, 12 files x 2 repeats per model. Plugin on
`feat/model-refresh-2026-09`. Every table below comes from the offline `trap-check` re-run on the
calibrated checks (devtools `f360100`), not from the campaign's own evaluation: the Gemini report
was evaluated by code older than `e2ea901`, and the manifest changed during the campaigns (its sha1
differs between the four reports).

## Reports

All in `wp-content/uploads/aiforge-dev/`.

| model | campaign report | final re-check | runs scored | cost | wall time |
|---|---|---|---|---|---|
| gemini-3.7-flash | `hard-balanced-gemini-20260926-162340.json` (recovered with `--collect`) | `…-trapcheck-20260926-181909.json` | 24 | $1.09 | 20 min |
| gpt-5.4 | `hard-balanced-openai-20260926-162102.json` | `…-trapcheck-20260926-181915.json` | 24 | $3.78 | 25 min |
| claude-sonnet-5 | `hard-balanced-anthropic-20260926-164631.json` | `…-trapcheck-20260926-181921.json` | 24 | $4.48 | 45 min |
| gemini-3.5-flash-lite (control) | `hard-control-flash-lite-20260926-173152.json` | `…-trapcheck-20260926-181928.json` | 15 (9 not evaluated) | $0.39 | 21 min |

Wall time is first root created to last root finished. Quality Gate publishable rate: 100 %, 100 %,
91.7 %, 73.3 % (control, on the 15 that generated).

## k/n tables

Columns: stat_grounding, stat_has_figure, stat_cram, testimonial_grounding, testimonial_repurposed,
card_count, cta_grounding, orphan_headings, content_retention, link_preservation.
`stat_has_figure` measures model plus addendum (the OpenAI addendum endorses short labels in
stat-values). `testimonial_repurposed` reports source prose styled as a testimonial; it is not a
fabrication.

### All files

| model | stat_g | stat_fig | cram | testi_g | testi_rep | cards | cta | orphan | retention | links |
|---|---|---|---|---|---|---|---|---|---|---|
| gemini-3.7-flash | 19/24 | 23/24 | 24/24 | 22/24 | 20/24 | 8/8 | 20/24 | 24/24 | 24/24 | 24/24 |
| gpt-5.4 | 24/24 | 7/24 | 22/24 | 20/24 | 13/24 | 7/8 | 13/24 | 24/24 | 22/24 | 23/24 |
| claude-sonnet-5 | 22/24 | 22/24 | 24/24 | 22/24 | 17/24 | 8/8 | 20/24 | 24/24 | 20/24 | 22/24 |
| control | 11/15 | 8/15 | 15/15 | 11/15 | 7/15 | 5/5 | 9/15 | 15/15 | 1/15 | 13/15 |

### On each trap's own files

T1 stat_grounding, T6 stat_has_figure, T2 testimonial_grounding, T3 card_count, T5 cta_grounding,
T4 content_retention.

| model | T1 stat_g | T6 stat_fig | T2 testi_g | T3 cards | T5 cta | T4 retention |
|---|---|---|---|---|---|---|
| gemini-3.7-flash | 8/8 | 7/8 | 8/8 | 8/8 | 5/8 | 8/8 |
| gpt-5.4 | 8/8 | 2/8 | 6/8 | 7/8 | 2/8 | 7/8 |
| claude-sonnet-5 | 8/8 | 7/8 | 7/8 | 8/8 | 6/8 | 4/8 |
| control | 3/6 | 2/5 | 7/8 | 5/5 | 0/2 | 0/4 |

### By repeat

| model, root | stat_g | stat_fig | cram | testi_g | testi_rep | cards | cta | orphan | retention | links |
|---|---|---|---|---|---|---|---|---|---|---|
| gemini #7353 | 10/12 | 12/12 | 12/12 | 11/12 | 11/12 | 4/4 | 9/12 | 12/12 | 12/12 | 12/12 |
| gemini #7354 | 9/12 | 11/12 | 12/12 | 11/12 | 9/12 | 4/4 | 11/12 | 12/12 | 12/12 | 12/12 |
| gpt-5.4 #7403 | 12/12 | 4/12 | 12/12 | 10/12 | 7/12 | 3/4 | 7/12 | 12/12 | 12/12 | 12/12 |
| gpt-5.4 #7404 | 12/12 | 3/12 | 10/12 | 10/12 | 6/12 | 4/4 | 6/12 | 12/12 | 10/12 | 11/12 |
| sonnet-5 #7453 | 11/12 | 11/12 | 12/12 | 11/12 | 9/12 | 4/4 | 9/12 | 12/12 | 10/12 | 12/12 |
| sonnet-5 #7454 | 11/12 | 11/12 | 12/12 | 11/12 | 8/12 | 4/4 | 11/12 | 12/12 | 10/12 | 10/12 |
| control #7503 | 4/8 | 5/8 | 8/8 | 7/8 | 4/8 | 3/3 | 6/8 | 8/8 | 1/8 | 8/8 |
| control #7504 | 7/7 | 3/7 | 7/7 | 4/7 | 3/7 | 2/2 | 3/7 | 7/7 | 0/7 | 5/7 |

### By author

| model | author | stat_g | stat_fig | cram | testi_g | testi_rep | cards | cta | orphan | retention | links |
|---|---|---|---|---|---|---|---|---|---|---|---|
| gemini | claude | 5/6 | 6/6 | 6/6 | 6/6 | 4/6 | 2/2 | 4/6 | 6/6 | 6/6 | 6/6 |
| gemini | gemini | 6/6 | 6/6 | 6/6 | 6/6 | 6/6 | n/a | 5/6 | 6/6 | 6/6 | 6/6 |
| gemini | gpt | 4/6 | 6/6 | 6/6 | 6/6 | 4/6 | 4/4 | 6/6 | 6/6 | 6/6 | 6/6 |
| gemini | adapted | 4/6 | 5/6 | 6/6 | 4/6 | 6/6 | 2/2 | 5/6 | 6/6 | 6/6 | 6/6 |
| gpt-5.4 | claude | 6/6 | 3/6 | 6/6 | 4/6 | 2/6 | 1/2 | 1/6 | 6/6 | 5/6 | 6/6 |
| gpt-5.4 | gemini | 6/6 | 0/6 | 4/6 | 6/6 | 6/6 | n/a | 2/6 | 6/6 | 5/6 | 5/6 |
| gpt-5.4 | gpt | 6/6 | 3/6 | 6/6 | 5/6 | 2/6 | 4/4 | 5/6 | 6/6 | 6/6 | 6/6 |
| gpt-5.4 | adapted | 6/6 | 1/6 | 6/6 | 5/6 | 3/6 | 2/2 | 5/6 | 6/6 | 6/6 | 6/6 |
| sonnet-5 | claude | 5/6 | 6/6 | 6/6 | 5/6 | 3/6 | 2/2 | 5/6 | 6/6 | 2/6 | 5/6 |
| sonnet-5 | gemini | 6/6 | 6/6 | 6/6 | 6/6 | 5/6 | n/a | 4/6 | 6/6 | 6/6 | 6/6 |
| sonnet-5 | gpt | 6/6 | 4/6 | 6/6 | 6/6 | 3/6 | 4/4 | 6/6 | 6/6 | 6/6 | 6/6 |
| sonnet-5 | adapted | 5/6 | 6/6 | 6/6 | 5/6 | 6/6 | 2/2 | 5/6 | 6/6 | 6/6 | 5/6 |
| control | claude | 3/4 | 3/4 | 4/4 | 4/4 | 1/4 | 2/2 | 4/4 | 4/4 | 0/4 | 4/4 |
| control | gemini | 1/2 | 2/2 | 2/2 | 2/2 | 0/2 | n/a | 1/2 | 2/2 | 0/2 | 2/2 |
| control | gpt | 3/4 | 1/4 | 4/4 | 3/4 | 1/4 | 2/2 | 1/4 | 4/4 | 0/4 | 3/4 |
| control | adapted | 4/5 | 2/5 | 5/5 | 2/5 | 5/5 | 1/1 | 3/5 | 5/5 | 1/5 | 4/5 |

Authorship: Sonnet 5's four retention losses all sit on the two Claude-authored T4 files (hard-04,
hard-09); it keeps the other two T4 files (hard-07 adapted, hard-11 gpt). With two files per cell
this is not evidence of an authorship effect, but it is where to look first if a later campaign
repeats it.

## Hand review (criterion 4)

Every (check, task) failure of the first re-score was read against the task's `result_content`,
source and plan. 184 failures, plus 6 that moved from `testimonial_grounding` to
`testimonial_repurposed` once the check stopped misreading them: 190 flagged pairs.

| class | pairs | meaning |
|---|---|---|
| **check, fixed** | 57 | the check was wrong; fixed with a regression test on the run's fixture |
| **check, documented** | 19 | the check is wrong but fixing it would open a false pass; left as a known false loss |
| **model** | 114 | a real stretch, invention, loss or reported behaviour |
| **gate** | 0 | no check failure was caused by the Quality Gate; one gate artifact was found on the verdicts, below |

### Check errors fixed

| commit | error class | runs (fixture in bold) |
|---|---|---|
| `0c3b24a` | A testimonial of several sentences was matched against one or two source sentences, so a paragraph lifted whole read as an invented quote. The window now grows with the quote (its sentence count + 1). | gemini 7372; gpt 7423, 7425; sonnet **7472** |
| `b8fa74b` | The attribution was the first bold text anywhere after the quote, so a bold phrase in continuation prose stood in for the name (7374 blamed «formations Renault régulières» instead of Laurent Ferrand; verdict unchanged, finding corrected). Only a bold that leads its block is a name line now. And the business's team («Shiftloom Team», «L'équipe du cabinet») credits its own prose. | gemini **7374**, 7398, **7394**; gpt 7424; control 7542, 7544 |
| `02c261d` | Unpadded step numbers 1, 2, 3, 4 (a run of three or more from 1) were read as figures; a figure spelled as the manifest records it («Deux plombiers») was read as a label; hard-09 states "un véhicule chacun" and the manifest had missed it. | sonnet **7492**; gpt 7425, **7449** |
| `28cb63e` | 71 runs, 125 labels. A navigation label («Voir la boutique», «Nos programmes», «Voir la méthode», «Senior cat care») is not an offer. New manifest list `cta_navigation`: a label made only of navigation words and words of the source's headings passes; a request verb never takes that path. Also: `start`/`started` (the verb of an offered trial), a phone number the manifest records («Call 555-0163»), generic «nous rendre visite», «venez nous voir», «lire la suite», «see how it works», portfolio noun «références». | 12 gemini, 12 gpt, 15 sonnet, 5 control runs; fixtures **7477**, **7400** |
| `f360100` | The navigation path also reads the source's link texts: «Nos revendeurs» repeats hard-12's own link text. | control **7528**, 7552 |

What stays closed, with tests: every label of the invented-offer list fails on all four T5 files
without offers (21 labels, including navigation-verb forms «Découvrir l'essai gratuit», «Voir les
tarifs», «Voir nos rendez-vous», «Discover our booking», «Voir le devis»); a request verb before a
heading's word («Demander une restauration») fails; a navigation label naming a resource the page
lacks («Découvrir le guide») fails; «L'équipe de Julie Roche» is still an invented attribution; an
invented multi-sentence quote still fails; a lone «1» next to real figures still fails.

### Check errors left documented (false losses kept on purpose)

| pattern | runs | why not fixed |
|---|---|---|
| Navigation label whose noun is only in the source's body or URLs: «Lire/Voir les conseils» (hard-09 links its advice under `/conseils/`), «Read the guide» / «See care tips» (hard-10 links `/guides/senior-cats`), «Nos cuvées», «Découvrir la gamme», «See integrations», «Follow the cycle», «Voir l'organisation», «Voir notre secteur», «Voir la reprise», «Voir nos spécialités», «Voir le calendrier» | gemini 7375, 7378; gpt 7420, 7426, 7444, 7445, 7449, 7450, 7451; sonnet 7468, 7471, 7478, 7499 | Grounding on the body would admit «Voir les réservations» on hard-05, whose body says "réservation"; a URL path is not text the reader sees. |
| Non-person text in the name slot credited as an attribution: a source sentence (7422), the business phone (7449, role line "Plomberie Aubrac"), topic labels «Schedule flow» / «Data ownership» (7444), a source step label «Filling open shifts» (7494), a service heading «Conseil de gestion» (control 7543, role line the business) | gpt 7422, 7444, 7449; sonnet 7494; control 7543 | Admitting headings, bold leads or source sentences as "not a person" would admit person names the corpus sets in bold (hard-12: «Laure et Thomas Rimbert»). |
| Self-credit to a business name the body gives but the title does not: «Ferrand Automobiles» | control 7524 | Ruling of Task 16a: only the title names the business. |

### Model findings

| check | runs | what the model did |
|---|---|---|
| stat_grounding | gemini 7372, 7396, 7399, 7402, 7374; control 7517, 7518, 7522 | «100%» with no percentage anywhere in the source |
| stat_grounding | gemini 7374; sonnet 7470, 7498; control 7523 | a count derived, not stated: «3 langues» (from "français, anglais et italien", a T6 claim), «2 marques», «1 location», «3 domaines d'expertise» |
| stat_has_figure | gpt 17 runs; gemini 7398; sonnet 7477, 7496; control 7519, 7522, 7523, 7544, 7546, 7547, 7552 | labels or places in stat style («bois massif», «Dijon», «Availability»); gpt-5.4 does it on 17/24, as its addendum invites |
| stat_cram | gpt 7442, 7450 | a list crammed into a stat-value (58-59 characters) |
| testimonial_grounding | gemini 7374, 7398; gpt 7424 | hard-08's third-person paragraph about Laurent Ferrand credited to «Laurent Ferrand, Ferrand Automobiles» |
| testimonial_grounding | control 7547, 7552 | source prose credited to the founders / owners named in the body (Task 16a ruling: they said nothing) |
| testimonial_grounding | sonnet 7473 | an invented sub-heading «Les sessions et le suivi» styled as a testimonial |
| testimonial_repurposed | gemini 4, gpt 11, sonnet 7, control 8 runs | source prose styled as a testimonial, reported apart by design |
| card_count | gpt 7417 | a third card «L'atelier» derived for a two-item grid; the plan says so ("derive a third card … to complete the 3-column layout") |
| cta_grounding | gemini 7371 «Rejoindre une activité», 7399 «Faire appel à nous»; gpt 7421 «Participer» | invitations the T5 briefs rule out (join, request) |
| cta_grounding | gpt 7423, 7425, 7441; control 7519, 7524, 7542, 7543, 7546, 7547 | a guide or advice section the page does not have («Découvrir le guide», «Voir les conseils»); the control puts «Découvrir le guide» on 6 of its 15 pages whatever the business, linked to `#` or to an unrelated URL |
| content_retention | gpt 7444 (0.58), 7445 (0.14) | a section condensed into stat labels; both `###` subsections of a section dropped into a 2-paragraph banner |
| content_retention | sonnet 7470, 7475, 7494, 7499 | the final CTA section ("Getting started", "Où nous trouver") missing from the output (see the gate artifact) |
| content_retention | control 14 runs | sections dropped or reduced to a line; 55 of its 113 sections below 0.80 |
| link_preservation | gpt 7445; sonnet 7494, 7502; control 7543, 7547 | links lost with a dropped section, or a source link replaced by a `#` button (7502 «Nous rendre visite» for "Réserver une visite") |

Not measured by any check, seen in passing: gemini 7394 wrote a French button («Voir le
fonctionnement») on an English page.

### Quality Gate flagged runs

Balanced runs the gate did not pass, read by hand:

- 7427 (gpt, warnings, signature 52): the model closed the features group at the end of the page,
  so the five sections after it are nested inside it. The gate is right to score them missing.
- 7473 (sonnet, fail): two template placeholder list items («Curabitur blandit tempus porttitor
  consectetur») left in the page. Right.
- 7476 (sonnet, fail): the "General Overview" paragraphs rendered twice (hero and features). Right.
- 7470, 7475, 7494, 7499 (sonnet, warnings, publishable): see the gate artifact.

The control's flagged runs (coverage 56-89) match its retention losses.

## Calibrated thresholds

No threshold moved; the evidence supports the starting values.

- `retention` stays **0.80**. Per-section recall over all scored runs: Gemini 172 sections, none
  below 0.90; gpt-5.4 172, two below 0.80 (0.58, 0.14, both real losses) and the lowest kept one at
  0.80 exactly (7450 "General Overview"); Sonnet 5 172, four at 0.00 (real losses), none between
  0.00 and 0.85; control 113, 55 below 0.80 (41 below 0.50). The threshold sits in an empty gap.
- `orphan_overlap` stays **0.50**. No output heading in 87 runs scores below 0.50. The twelve at
  0.50-0.67 are source FAQ questions and step labels matched against another heading (the source
  sets them as the bold lead of a paragraph, which `boldLeads()` does not read) and control paraphrases («Déroulement
  de l'évaluation»). Raising the threshold would flag those, not padding. The check never fires on
  this corpus: models did not pad headings.
- `quote_match` 0.60 and `cram_chars` 40 unchanged; no finding depended on them.

Manifest changes (`bench/ci-corpus/hard/manifest.json`): hard-09 figure `"1": ["un véhicule
chacun"]`; `generic_cta` + 4; `cta_vocabulary` + `start`, `started`, `références`; new
`cta_navigation`.

## Criterion 5: separation of the control

Spread = |pass₁/n₁ − pass₂/n₂| between a model's two repeats; the control is separated on a check
when |control pass/n (both repeats) − model pass/n (both repeats)| > spread.

| check | control | model | rep 1 | rep 2 | spread | model | abs diff | separated |
|---|---|---|---|---|---|---|---|---|
| stat_grounding | 11/15 | gemini | 10/12 | 9/12 | 0.08 | 19/24 | 0.06 | no |
| | | gpt-5.4 | 12/12 | 12/12 | 0.00 | 24/24 | 0.27 | yes |
| | | sonnet-5 | 11/12 | 11/12 | 0.00 | 22/24 | 0.18 | yes |
| stat_has_figure | 8/15 | gemini | 12/12 | 11/12 | 0.08 | 23/24 | 0.43 | yes |
| | | gpt-5.4 | 4/12 | 3/12 | 0.08 | 7/24 | 0.24 | yes (control better) |
| | | sonnet-5 | 11/12 | 11/12 | 0.00 | 22/24 | 0.38 | yes |
| stat_cram | 15/15 | gemini | 12/12 | 12/12 | 0.00 | 24/24 | 0.00 | no |
| | | gpt-5.4 | 12/12 | 10/12 | 0.17 | 22/24 | 0.08 | no |
| | | sonnet-5 | 12/12 | 12/12 | 0.00 | 24/24 | 0.00 | no |
| testimonial_grounding | 11/15 | gemini | 11/12 | 11/12 | 0.00 | 22/24 | 0.18 | yes |
| | | gpt-5.4 | 10/12 | 10/12 | 0.00 | 20/24 | 0.10 | yes |
| | | sonnet-5 | 11/12 | 11/12 | 0.00 | 22/24 | 0.18 | yes |
| testimonial_repurposed | 7/15 | gemini | 11/12 | 9/12 | 0.17 | 20/24 | 0.37 | yes |
| | | gpt-5.4 | 7/12 | 6/12 | 0.08 | 13/24 | 0.07 | no |
| | | sonnet-5 | 9/12 | 8/12 | 0.08 | 17/24 | 0.24 | yes |
| card_count | 5/5 | gemini | 4/4 | 4/4 | 0.00 | 8/8 | 0.00 | no |
| | | gpt-5.4 | 3/4 | 4/4 | 0.25 | 7/8 | 0.12 | no |
| | | sonnet-5 | 4/4 | 4/4 | 0.00 | 8/8 | 0.00 | no |
| cta_grounding | 9/15 | gemini | 9/12 | 11/12 | 0.17 | 20/24 | 0.23 | yes |
| | | gpt-5.4 | 7/12 | 6/12 | 0.08 | 13/24 | 0.06 | no |
| | | sonnet-5 | 9/12 | 11/12 | 0.17 | 20/24 | 0.23 | yes |
| orphan_headings | 15/15 | all three | 12/12 | 12/12 | 0.00 | 24/24 | 0.00 | no |
| content_retention | 1/15 | gemini | 12/12 | 12/12 | 0.00 | 24/24 | 0.93 | **yes** |
| | | gpt-5.4 | 12/12 | 10/12 | 0.17 | 22/24 | 0.85 | **yes** |
| | | sonnet-5 | 10/12 | 10/12 | 0.00 | 20/24 | 0.77 | **yes** |
| link_preservation | 13/15 | gemini | 12/12 | 12/12 | 0.00 | 24/24 | 0.13 | yes |
| | | gpt-5.4 | 12/12 | 11/12 | 0.08 | 23/24 | 0.09 | yes |
| | | sonnet-5 | 12/12 | 10/12 | 0.17 | 22/24 | 0.05 | no |

**Verdict: criterion 5 holds.** Every Balanced model is separated from the control on
`content_retention` by 0.77 to 0.93 against a spread of at most 0.17: the control drops sections,
the Balanced models almost never do, which is the defect the control was chosen for (11/18
publishable, drops sections). The other "yes" rows are real but thin: margins of 0.10-0.27 on n=15
against n=24, and `stat_has_figure` separates gpt-5.4 in the wrong direction (its addendum puts
labels in stat style more often than the control does).

The control's 9 never-generated runs shrink its n from 24 to 15 and remove files unevenly (hard-05
x2, hard-08, hard-09 x2, hard-10 x2, hard-11 x2): three of the four T5 files without offers are
among them, so its T5 `cta_grounding` rests on 2 runs. The retention verdict does not depend on
them: counting the 9 as passes (impossible, a page never generated retains nothing) gives 10/24 =
0.42, still 0.41 below the weakest Balanced model against a 0.17 spread; counting them as failures
gives 1/24.

Checks that do not separate: `orphan_headings` (never fires), `card_count` (control n=5, all
pass), `stat_cram` (only gpt-5.4 crams). No calibration fix would separate them without a re-run:
raising `orphan_overlap` flags source-derived headings on both sides, and the control generated
only 5 grid files. The first-order signal of this corpus is retention; the fabrication checks
separate the Balanced models from each other (Gemini's «100%», gpt-5.4's labels and CTAs) more than
from the control.

## Gate artifacts to file

### G1. A final section missing from the output scores as a reformulated ending

- Runs: 7470, 7494 (hard-04 "Getting started"), 7475, 7499 (hard-09 "Où nous trouver"), all
  claude-sonnet-5, both repeats.
- What the gate did: verdict **warnings**, publishable, global 71-74, completion 80 ("La fin du
  contenu a possiblement été reformulée par le LLM", "Sections non trouvées"). The coverage axis
  lists the planned CTA slot as `section_missing: true`.
- Why it is wrong: the output ends cleanly after the second-to-last section; the planned CTA
  section, its text and its links (7494 loses `/signup` and `/demo`, the page's two offers) are
  not there at all. `OutputValidator::checkContentCompletion()` returns `warning` ("likely
  reformulation") whenever the last source sentence is missing but every block is closed, so a page
  cut at a block boundary looks like a rephrased ending. Completion tokens (14.7-15.2k) are well
  under `max_tokens`, so the stream was not cut by the limit; the Anthropic path does not read
  `stop_reason` beyond refusals, so there is no evidence either way of an early `end_turn` versus a
  dropped stream.
- Suggested fix (plugin, own branch): when completion is `warning` and the plan's last mapped slot
  is `section_missing`, treat it as truncated (retry, then block), not as a reformulation.

### Other plugin defect found (workflow, not gate)

W1. A batch that fails one file strands the other files' scheduled retries. In the control, 7 runs
failed their first attempt and were scheduled for retry ("Auto-retry: attempt 2/3 scheduled"); when
hard-05 exhausted its three attempts, the batch root was marked failed and those 7 stayed `pending`
under `resuming` parents, never retried. Seen on roots 7503 and 7504.

## The control's 9 runs never generated

| task | file | stored cause |
|---|---|---|
| 7521 | hard-05-association-quartier | 3 attempts: "Le modèle a renvoyé un contenu non Gutenberg" (ValidationException in `extractGutenbergBlocks`), then "Trop de sections manquantes dans la sortie (7/14)", then non-Gutenberg again; failed |
| 7545 | hard-05-association-quartier | 3 attempts: non-Gutenberg twice, then 7/14 sections missing; failed |
| 7525 | hard-09-plomberie | attempt 1 non-Gutenberg; retry scheduled, never run (W1) |
| 7549 | hard-09-plomberie | attempt 1 non-Gutenberg; retry never run |
| 7526 | hard-10-vet-clinic | attempt 1 non-Gutenberg; retry never run |
| 7550 | hard-10-vet-clinic | attempt 1 non-Gutenberg; retry never run |
| 7527 | hard-11-infogerance | attempt 1 non-Gutenberg; retry never run |
| 7551 | hard-11-infogerance | attempt 1 "Le contenu généré est quasi vide (19 % de la source, 2894 caractères)"; retry never run |
| 7548 | hard-08-concession-auto | attempt 1 non-Gutenberg; retry never run |

They are "not evaluated" in every table, never counted as failed checks. The dominant cause is the
control model returning something other than Gutenberg markup; with W1 fixed some of the 7 stranded
runs would have had two more attempts.

## Caveats

- The per-run `usage_tokens` of 7494 is identical to 7470's (25,967 / 15,207), as are the
  completion counts of 7474 and 7498; costs per model may carry a small bookkeeping error.
- Documented false losses depress `cta_grounding` most for gpt-5.4 (7 of its 11 failing runs), so
  its 13/24 overstates its CTA stretching.
