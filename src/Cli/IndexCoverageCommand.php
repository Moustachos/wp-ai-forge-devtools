<?php

declare(strict_types=1);

namespace AIForge\Cli;

use WP_CLI;

/**
 * Reports which query dimensions the media index cannot answer.
 *
 * Two search failures were reported in use before this existed, and both had
 * the same shape: the query named something no row describes. `person` had
 * been stripped by a no-repeat rule, and nothing ever asked for a facial
 * expression. Each was found by a user, one at a time, and each cost a full
 * reindex to fix.
 *
 * A missing dimension is invisible from the search side, because the engine
 * cannot distinguish "no image matches" from "no image says". It is visible
 * from the index side, by taking a population the taxonomy can be trusted for
 * and asking how many of those rows carry the vocabulary a query would need.
 * Of 147 rows showing a human, 8 mentioned an expression. That is a gap; a low
 * count over a small population is merely a small corpus.
 *
 * Run it after any reindex. It costs nothing: no model call, only SQL.
 */
final class IndexCoverageCommand
{
    /**
     * Each probe is a dimension, the rows it could apply to, and the words a
     * query would have to land on. The populations lean on taxonomy columns
     * rather than on keywords, since the taxonomies are the part of the index
     * that is reliably filled.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const PROBES = [
        // Whole words, never stems: the boundaries below mean `smil` matches
        // neither "smile" nor "smiling", so every form is spelled out.
        ['facial expression', self::PEOPLE, 'smile|smiles|smiling|happy|joy|joyful|laughing|laughter|serious|pensive|cheerful|focused|surprised'],
        ['apparent age', self::PEOPLE, 'child|children|kid|kids|baby|babies|toddler|teen|teenager|young|youth|elderly|senior|adult|adults'],
        ['clothing', self::PEOPLE, 'suit|shirt|dress|jacket|hoodie|uniform|coat|sweater|hat'],
        ['activity', self::PEOPLE, 'running|walking|jumping|sitting|standing|holding|reading|writing|working'],
        ['time of day', self::OUTDOOR, 'sunrise|sunset|morning|midday|noon|golden hour|dusk|dawn|twilight|night|daylight|afternoon|evening'],
        ['season', self::OUTDOOR, 'spring|summer|autumn|fall|winter|snow|foliage|blossom'],
        ['weather', self::OUTDOOR, 'sunny|cloudy|overcast|rain|storm|fog|mist|haze|wind|clear sky'],
        ['framing', '1=1', 'close-up|closeup|macro|aerial|overhead|wide shot|low angle|eye level'],
        ['named colours', '1=1', 'red|blue|green|yellow|orange|purple|pink|brown|white|black|grey'],
        ['materials', '1=1', 'wood|metal|glass|brick|concrete|fabric|stone|leather|paper'],
    ];

    private const PEOPLE = "people_count IS NOT NULL AND people_count <> 'none'";

    private const OUTDOOR = "setting = 'outdoor'";

    /**
     * The people probes read low by construction, and the expression one most
     * of all: a photograph of a back, a pair of hands or a distant crowd holds
     * a person and no describable face or outfit. Narrowing the population to
     * rows that name a face was tried and abandoned, because deriving it from
     * keywords is circular and leaves too few rows to judge.
     *
     * So these shares are a floor, not an estimate. Read them as "at most this
     * fraction could have been described, and this many were". On the lab the
     * floor and a portrait-only cross-check agreed anyway: 28% over every row
     * holding a person, 35% over the rows the taxonomy calls portraits.
     */

    /** Below this share of its population, a dimension is unanswerable. */
    private const GAP = 35;

    /** Between the two, a query lands sometimes, which is arguably worse. */
    private const PARTIAL = 60;

    /** Under this many rows, a share says nothing worth acting on. */
    private const MIN_POPULATION = 40;

    /**
     * ## OPTIONS
     *
     * [--gap=<percent>]
     * : Coverage below which a dimension is reported as a gap. Default 35.
     *
     * ## EXAMPLES
     *
     *     wp aiforge-dev index-coverage
     *
     * @param string[]              $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'aiforge_media_index';
        $gap = isset($assoc['gap']) ? (int) $assoc['gap'] : self::GAP;

        $indexed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'indexed'");
        if ($indexed === 0) {
            WP_CLI::error('The media index holds no indexed row.');
        }

        WP_CLI::log("Indexed rows: {$indexed}");

        $rows = [];
        $gaps = [];

        foreach (self::PROBES as [$label, $population, $terms]) {
            $pop = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'indexed' AND ({$population})"
            );

            if ($pop === 0) {
                continue;
            }

            // Whole words only. Without the boundaries `wind` matches
            // "windows" and `rain` matches "train", which reported a weather
            // coverage the index did not have.
            $bounded = '\b(' . $terms . ')\b';
            $hit = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE status = 'indexed' AND ({$population})
                 AND (keywords REGEXP %s OR description REGEXP %s)",
                $bounded,
                $bounded
            ));

            $share = (int) round(100 * $hit / $pop);
            $verdict = '';

            if ($pop >= self::MIN_POPULATION && $share < $gap) {
                $verdict = 'GAP';
                $gaps[] = $label;
            } elseif ($pop >= self::MIN_POPULATION && $share < self::PARTIAL) {
                $verdict = 'partial';
            } elseif ($pop < self::MIN_POPULATION) {
                $verdict = 'too few rows to judge';
            }

            $rows[] = [
                'dimension' => $label,
                'population' => $pop,
                'described' => $hit,
                'coverage' => $share . '%',
                'verdict' => $verdict,
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['dimension', 'population', 'described', 'coverage', 'verdict']);

        if ($gaps === []) {
            WP_CLI::success('Every probed dimension is described often enough to be searchable.');

            return;
        }

        WP_CLI::warning(sprintf(
            '%d dimension(s) a user can legitimately search for are not described: %s.',
            \count($gaps),
            implode(', ', $gaps)
        ));
        WP_CLI::log('Fixing one means changing the indexation prompt and reindexing, so collect them all before paying for the pass.');
    }
}
