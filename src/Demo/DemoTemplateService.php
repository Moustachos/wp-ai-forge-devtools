<?php

declare(strict_types=1);

namespace AIForge\Demo;

use AIForge\Contracts\TemplateServiceInterface;
use WP_Error;

/**
 * Demo-aware template service decorator.
 *
 * Wraps TemplateService to include demo templates in listings
 * and support demo template content retrieval.
 *
 * @package AIForge
 */
class DemoTemplateService implements TemplateServiceInterface
{
    public function __construct(
        private readonly TemplateServiceInterface $service,
        private readonly DemoTemplateLoader $demoLoader
    ) {
    }

    /**
     * Create a template from an existing page.
     *
     * @param int $pageId The source page ID.
     * @param bool $neutralize Whether to neutralize the content.
     * @return int|WP_Error The created template ID or error.
     */
    public function createFromPage(int $pageId, bool $neutralize = true): int|WP_Error
    {
        return $this->service->createFromPage($pageId, $neutralize);
    }

    /**
     * Create a template from a block pattern.
     *
     * @param string $patternSlug The pattern slug.
     * @param bool $neutralize Whether to neutralize the content.
     * @return int|WP_Error The created template ID or error.
     */
    public function createFromPattern(string $patternSlug, bool $neutralize = true): int|WP_Error
    {
        return $this->service->createFromPattern($patternSlug, $neutralize);
    }

    /**
     * Neutralize the content of an existing template.
     *
     * @param int $templateId The template post ID.
     * @return true|WP_Error True on success or error.
     */
    public function neutralize(int $templateId): true|WP_Error
    {
        return $this->service->neutralize($templateId);
    }

    /**
     * Get all templates including demo templates.
     *
     * Demo templates appear first in the list.
     *
     * @return array<array{id: int|string, title: string, sourceType: string|null, sourceId: string|null, neutralized: bool, editUrl: string, isDemo?: bool}>
     */
    public function getAll(): array
    {
        $demoTemplates = $this->demoLoader->getAvailableTemplatesAsCPT();
        $realTemplates = $this->service->getAll();

        // Add isDemo: false to real templates for consistency
        $realTemplates = array_map(function ($template) {
            $template['isDemo'] = false;
            return $template;
        }, $realTemplates);

        // Demo templates first, then real templates
        return array_merge($demoTemplates, $realTemplates);
    }

    /**
     * Get a single template by ID (supports demo templates).
     *
     * @param int|string $templateId The template ID (int for CPT, string for demo).
     * @return array|null The template data or null if not found.
     */
    public function get(int|string $templateId): ?array
    {
        // Check if it's a demo template ID
        if (is_string($templateId) && str_starts_with($templateId, 'demo-')) {
            return $this->getDemoTemplate($templateId);
        }

        $template = $this->service->get((int) $templateId);

        if ($template !== null) {
            $template['isDemo'] = false;
        }

        return $template;
    }

    /**
     * Get template content by ID (supports demo templates).
     *
     * @param int|string $templateId The template ID.
     * @return string|null The template content or null if not found.
     */
    public function getContent(int|string $templateId): ?string
    {
        // Check if it's a demo template ID
        if (is_string($templateId) && str_starts_with($templateId, 'demo-')) {
            return $this->demoLoader->getContentByDemoId($templateId);
        }

        return $this->service->getContent((int) $templateId);
    }

    /**
     * List eligible pages.
     *
     * @return array<array{id: int, title: string, eligible: bool}>
     */
    public function listEligiblePages(): array
    {
        return $this->service->listEligiblePages();
    }

    /**
     * List eligible block patterns.
     *
     * @return array<array{slug: string, title: string, eligible: bool}>
     */
    public function listEligiblePatterns(): array
    {
        return $this->service->listEligiblePatterns();
    }

    /**
     * Get a demo template by its ID.
     *
     * @param string $demoId Demo template ID.
     * @return array|null The template data or null if not found.
     */
    private function getDemoTemplate(string $demoId): ?array
    {
        $demoTemplates = $this->demoLoader->getAvailableTemplatesAsCPT();

        foreach ($demoTemplates as $template) {
            if ($template['id'] === $demoId) {
                return $template;
            }
        }

        return null;
    }
}
