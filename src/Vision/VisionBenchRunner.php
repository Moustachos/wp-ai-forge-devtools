<?php

declare(strict_types=1);

namespace AIForge\Vision;

use RuntimeException;
use wpdb;

/**
 * Indexes one image sample with several vision models and measures which index
 * answers a human-written query set best.
 *
 * The media index holds one row per attachment (UNIQUE KEY attachment_id), so
 * the models cannot coexist. Each pass therefore snapshots the sample's rows,
 * wipes them, indexes with its own model, harvests the result, and the original
 * rows are put back at the end — in a finally, because losing a real index to a
 * benchmark would be indefensible.
 *
 * The vision model is forced through the main plugin's own
 * `aiforge_media_vision_model` filter rather than by touching provider code.
 */
final class VisionBenchRunner
{
    /** Entry type marking a query nothing in the sample should answer. */
    public const TYPE_TRAP = 'piege';

    /** Closed-taxonomy columns whose values must come from a known set. */
    private const TAXONOMY_COLUMNS = ['tone', 'usage_type', 'category', 'image_type', 'color_mood', 'people_count', 'setting', 'subject_position', 'negative_space'];

    public function __construct(private readonly wpdb $db)
    {
    }

    private function table(): string
    {
        return $this->db->prefix . 'aiforge_media_index';
    }

    /**
     * Image attachments to benchmark on, oldest first so a given --images
     * count always resolves to the same sample.
     *
     * @return list<int>
     */
    public function resolveSample(int $count): array
    {
        $ids = $this->db->get_col(
            $this->db->prepare(
                "SELECT ID FROM {$this->db->posts}
                 WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%'
                 ORDER BY ID ASC LIMIT %d",
                $count
            )
        );

        return array_map('intval', $ids);
    }

    /**
     * @param list<int> $attachmentIds
     * @return list<array<string, mixed>>
     */
    public function snapshot(array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $attachmentIds));

        return $this->db->get_results("SELECT * FROM {$this->table()} WHERE attachment_id IN ($in)", ARRAY_A) ?: [];
    }

    /** @param list<int> $attachmentIds */
    public function wipe(array $attachmentIds): void
    {
        if ($attachmentIds === []) {
            return;
        }
        $in = implode(',', array_map('intval', $attachmentIds));
        $this->db->query("DELETE FROM {$this->table()} WHERE attachment_id IN ($in)");
    }

    /**
     * @param list<int>                    $attachmentIds
     * @param list<array<string, mixed>>   $rows
     */
    public function restore(array $attachmentIds, array $rows): void
    {
        $this->wipe($attachmentIds);

        foreach ($rows as $row) {
            unset($row['id']);
            $this->db->insert($this->table(), $row);
        }
    }

    /**
     * Rows produced for the sample, keyed by attachment.
     *
     * @param list<int> $attachmentIds
     * @return array<int, array<string, mixed>>
     */
    public function harvest(array $attachmentIds): array
    {
        $out = [];
        foreach ($this->snapshot($attachmentIds) as $row) {
            $out[(int) $row['attachment_id']] = $row;
        }

        return $out;
    }

    /**
     * Free, judgement-free quality signals on a harvested index.
     *
     * @param array<int, array<string, mixed>>   $rows
     * @param array<string, list<string>>        $allowed  Column => accepted values
     * @return array<string, int|float>
     */
    public static function indexHealth(array $rows, array $allowed): array
    {
        $indexed = 0;
        $failed = 0;
        $offTaxonomy = 0;
        $keywordCount = 0;
        $descriptionChars = 0;

        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'indexed') {
                $failed++;
                continue;
            }
            $indexed++;

            foreach (self::TAXONOMY_COLUMNS as $col) {
                if (!isset($allowed[$col]) || $allowed[$col] === []) {
                    continue;
                }
                foreach (self::facetValues($row[$col] ?? null) as $value) {
                    if (!\in_array($value, $allowed[$col], true)) {
                        $offTaxonomy++;
                    }
                }
            }

            $keywords = $row['keywords'] ?? '';
            $decoded = \is_string($keywords) ? json_decode($keywords, true) : null;
            $keywordCount += \is_array($decoded) ? \count($decoded) : \count(array_filter(explode(',', (string) $keywords)));
            $descriptionChars += mb_strlen((string) ($row['description'] ?? ''));
        }

        $total = $indexed + $failed;

        return [
            'indexed' => $indexed,
            'failed' => $failed,
            'failure_rate' => $total > 0 ? round(100 * $failed / $total, 1) : 0.0,
            'off_taxonomy' => $offTaxonomy,
            'avg_keywords' => $indexed > 0 ? round($keywordCount / $indexed, 1) : 0.0,
            'avg_description_chars' => $indexed > 0 ? (int) round($descriptionChars / $indexed) : 0,
        ];
    }

    /**
     * Facet columns hold either a scalar or a JSON array — several of them are
     * multi-valued (`usage_type`, `tone`, `category`). Reading them as scalars
     * flags every multi-valued row as off-taxonomy, which is how this metric
     * first reported 9 violations on 3 perfectly valid images.
     *
     * @return list<string>
     */
    private static function facetValues(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (\is_array($raw)) {
            return array_map('strval', $raw);
        }

        $decoded = json_decode((string) $raw, true);
        if (\is_array($decoded)) {
            return array_map('strval', $decoded);
        }

        return [(string) $raw];
    }

    /**
     * How much each facet actually separates the images.
     *
     * A model can be perfectly consistent and still produce a useless index: if
     * it labels 90% of the library `muted`, that facet filters nothing and no
     * query on it will ever narrow anything down. Internal agreement says
     * nothing about this — only the spread of values does.
     *
     * `dominant_share` is the fraction carried by the most frequent value (1.0
     * = everything identical). `entropy` is Shannon entropy normalised by the
     * number of distinct values observed, so 0 is one value everywhere and 1 is
     * a perfectly even spread.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param list<string>                     $facets
     * @return array<string, array{fill_rate: float, distinct: int, dominant: string, dominant_share: float, entropy: float}>
     */
    public static function facetDiscrimination(array $rows, array $facets): array
    {
        $indexed = array_filter($rows, static fn ($r) => ($r['status'] ?? '') === 'indexed');
        $total = \count($indexed);
        $out = [];

        foreach ($facets as $facet) {
            $counts = [];
            $filled = 0;

            foreach ($indexed as $row) {
                $values = self::facetValues($row[$facet] ?? null);
                if ($values === []) {
                    continue;
                }
                $filled++;
                foreach ($values as $v) {
                    $counts[$v] = ($counts[$v] ?? 0) + 1;
                }
            }

            $sum = array_sum($counts);
            $entropy = 0.0;
            if ($sum > 0 && \count($counts) > 1) {
                foreach ($counts as $n) {
                    $p = $n / $sum;
                    $entropy -= $p * log($p);
                }
                $entropy /= log(\count($counts));
            }

            arsort($counts);
            $out[$facet] = [
                'fill_rate' => $total > 0 ? round(100 * $filled / $total, 1) : 0.0,
                'distinct' => \count($counts),
                'dominant' => $counts === [] ? '' : (string) array_key_first($counts),
                'dominant_share' => $sum > 0 ? round(reset($counts) / $sum, 3) : 0.0,
                'entropy' => round($entropy, 3),
            ];
        }

        return $out;
    }

    /**
     * Load and validate the query file.
     *
     * @return list<array{query: string, expect: list<int>}>
     */
    public static function loadQueries(string $path): array
    {
        if (!file_exists($path)) {
            throw new RuntimeException("Query file not found: $path");
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!\is_array($raw)) {
            throw new RuntimeException("Query file is not valid JSON: $path");
        }

        $queries = [];
        foreach ($raw as $i => $entry) {
            if (!isset($entry['query']) || !\is_string($entry['query']) || trim($entry['query']) === '') {
                throw new RuntimeException("Entry $i has no usable 'query'.");
            }
            if (!\array_key_exists('expect', $entry) || !\is_array($entry['expect'])) {
                throw new RuntimeException("Entry $i has no 'expect' array.");
            }

            $type = isset($entry['type']) ? (string) $entry['type'] : '';
            $isTrap = $type === self::TYPE_TRAP;

            // An empty `expect` on a non-trap entry is an unfilled row, not a
            // trap. Scoring it as one would quietly reward every model for
            // returning nothing, so refuse the file instead.
            if ($entry['expect'] === [] && !$isTrap) {
                throw new RuntimeException(
                    "Entry $i (\"{$entry['query']}\") has an empty 'expect' but is not typed as '"
                    . self::TYPE_TRAP . "'. Fill in the expected attachment ids, or mark it as a trap."
                );
            }

            $queries[] = [
                'query' => trim($entry['query']),
                'type' => $type,
                'expect' => array_values(array_map('intval', $entry['expect'])),
            ];
        }

        return $queries;
    }

    /**
     * Attachments referenced by the query set that are missing from the sample —
     * those queries could never be answered, whatever the model.
     *
     * @param list<array{query: string, expect: list<int>}> $queries
     * @param list<int>                                     $sample
     * @return list<int>
     */
    public static function unreachableExpectations(array $queries, array $sample): array
    {
        $missing = [];
        foreach ($queries as $q) {
            foreach ($q['expect'] as $id) {
                if (!\in_array($id, $sample, true) && !\in_array($id, $missing, true)) {
                    $missing[] = $id;
                }
            }
        }

        return $missing;
    }
}
