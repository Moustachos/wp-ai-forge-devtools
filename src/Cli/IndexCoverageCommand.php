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
        ['facial expression', "people_count IS NOT NULL AND people_count <> 'none'", 'smil|happy|joy|laugh|serious|pensive|cheer|focused|surprised'],
        ['apparent age', "people_count IS NOT NULL AND people_count <> 'none'", 'child|kid|baby|toddler|teen|young|elderly|senior|adult'],
        ['clothing', "people_count IS NOT NULL AND people_count <> 'none'", 'suit|shirt|dress|jacket|hoodie|uniform|coat|sweater|hat'],
        ['activity', "people_count IS NOT NULL AND people_count <> 'none'", 'running|walking|jumping|sitting|standing|holding|reading|writing|working'],
        ['time of day', "setting = 'outdoor'", 'sunrise|sunset|morning|midday|golden hour|dusk|dawn|night|daylight'],
        ['season', "setting = 'outdoor'", 'spring|summer|autumn|winter|snow|foliage|blossom'],
        ['weather', "setting = 'outdoor'", 'sunny|cloudy|overcast|rain|storm|fog|wind|clear sky'],
        ['framing', '1=1', 'close-up|closeup|macro|aerial|overhead|wide shot|low angle|eye level'],
        ['named colours', '1=1', 'red|blue|green|yellow|orange|purple|pink|brown|white|black|grey'],
        ['materials', '1=1', 'wood|metal|glass|brick|concrete|fabric|stone|leather|paper'],
    ];

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

            $hit = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table}
                 WHERE status = 'indexed' AND ({$population})
                 AND (keywords REGEXP '{$terms}' OR description REGEXP '{$terms}')"
            );

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
