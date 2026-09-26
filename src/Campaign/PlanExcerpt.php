<?php

declare(strict_types=1);

namespace AIForge\Campaign;

/**
 * The MAPPING and NOTES blocks of a stored integration plan. Most losses in a
 * campaign are planner decisions, so a failed check ships with them.
 */
final class PlanExcerpt
{
    /**
     * @return array{mapping: string, notes: string}
     */
    public static function extract(string $plan): array
    {
        return [
            'mapping' => self::block($plan, 'MAPPING', ['NOTES', 'MAPPING_JSON']),
            'notes' => self::block($plan, 'NOTES', ['MAPPING_JSON']),
        ];
    }

    /**
     * @param string[] $next Headers that end the block.
     */
    private static function block(string $plan, string $header, array $next): string
    {
        $pattern = '/^' . $header . '\s*$\R(.*?)(?=^(?:' . implode('|', $next) . ')\s*$|\z)/ms';

        return preg_match($pattern, $plan, $m) === 1 ? trim($m[1]) : '';
    }
}
