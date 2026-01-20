<?php

declare(strict_types=1);

namespace AIForge\Demo;

/**
 * Loader for demo templates.
 *
 * Demo templates are static HTML files stored in templates/demo/ directory.
 *
 * @package AIForge
 */
class DemoTemplateLoader
{
    private const DEMO_PATH = AIFORGE_DEV_PATH . 'templates/demo/';

    /**
     * Get available templates formatted like TemplateService::getAll() output.
     *
     * @return array<array{id: string, title: string, sourceType: string|null, sourceId: string|null, neutralized: bool, editUrl: string, isDemo: bool}>
     */
    public function getAvailableTemplatesAsCPT(): array
    {
        $templates = [];

        foreach ($this->scanDirectory() as $template) {
            $templates[] = [
                'id' => 'demo-' . $template['id'],
                'title' => $template['title'],
                'sourceType' => null,
                'sourceId' => null,
                'neutralized' => true,
                'editUrl' => '',
                'isDemo' => true,
            ];
        }

        return $templates;
    }

    /**
     * Get content from a demo template using ID.
     *
     * @param string $demoId Demo template ID (e.g., "demo-about-page").
     * @return string|null Template content or null if not found.
     */
    public function getContentByDemoId(string $demoId): ?string
    {
        if (!str_starts_with($demoId, 'demo-')) {
            return null;
        }

        $id = substr($demoId, 5); // Remove "demo-" prefix
        $filepath = self::DEMO_PATH . $id . '.html';

        if (!file_exists($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);

        if ($content === false) {
            return null;
        }

        // Remove the title comment from the content
        $content = preg_replace('/^\s*<!--\s*Title:\s*.+?\s*-->\s*/i', '', $content);

        return trim($content);
    }

    /**
     * Scan the demo directory for template files.
     *
     * @return array<array{id: string, title: string}>
     */
    private function scanDirectory(): array
    {
        if (!is_dir(self::DEMO_PATH)) {
            return [];
        }

        $files = glob(self::DEMO_PATH . '*.html');

        if ($files === false) {
            return [];
        }

        $templates = [];
        foreach ($files as $file) {
            $id = basename($file, '.html');
            $title = $this->extractTitle($file) ?? $this->formatTitle($id);

            $templates[] = [
                'id' => $id,
                'title' => $title,
            ];
        }

        return $templates;
    }

    /**
     * Extract title from template file comment.
     *
     * @param string $filepath Path to template file.
     * @return string|null Title or null if not found.
     */
    private function extractTitle(string $filepath): ?string
    {
        $content = file_get_contents($filepath, false, null, 0, 200);

        if ($content === false) {
            return null;
        }

        if (preg_match('/<!--\s*Title:\s*(.+?)\s*-->/', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Format an ID as a title.
     *
     * @param string $id Template ID.
     * @return string Formatted title.
     */
    private function formatTitle(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
