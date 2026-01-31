<?php

declare(strict_types=1);

namespace AIForge\Demo;

use AIForge\AI\CompletionResult;
use AIForge\AI\ResponseType;

class MockResponseGenerator
{
    /**
     * Failure probability for testing (0-100)
     */
    private int $failureProbability = 25;

    public function generate(string $prompt, array $options = []): CompletionResult
    {
        // Simulate random failures for testing error UI
        if ($this->failureProbability > 0 && random_int(1, 100) <= $this->failureProbability) {
            $errors = [
                'API rate limit exceeded. Please try again later.',
                'The model is currently overloaded. Please retry.',
                'Invalid API key or authentication failed.',
                'Request timeout after 30 seconds.',
                'Content policy violation detected in the response.',
            ];

            return CompletionResult::failure(
                $errors[array_rand($errors)],
                ['mock' => true, 'simulated_failure' => true]
            );
        }

        $responseType = $options['responseType'] ?? ResponseType::GutenbergBlocks;

        $content = match ($responseType) {
            ResponseType::GutenbergBlocks => $this->generateGutenbergBlocks($prompt),
            ResponseType::PlainText => $this->generatePlainText(),
            ResponseType::AltText => $this->generateAltText(),
            ResponseType::Json => $this->generateJson(),
        };

        $estimatedTokens = (int) (strlen($prompt) / 4);

        return CompletionResult::success(
            $content,
            [
                'prompt_tokens' => $estimatedTokens,
                'completion_tokens' => null,
                'total_tokens' => null,
            ],
            ['mock' => true]
        );
    }

    private function generateGutenbergBlocks(string $prompt): string
    {
        // Extract markdown content from prompt if present
        if (preg_match('/```markdown\s*([\s\S]*?)```/i', $prompt, $matches)) {
            $markdown = trim($matches[1]);
            return $this->markdownToGutenberg($markdown);
        }

        // Default mock response
        return "<!-- wp:paragraph -->\n<p>" . esc_html(substr($prompt, 0, 100)) . "...</p>\n<!-- /wp:paragraph -->";
    }

    private function generatePlainText(): string
    {
        return "Ceci est une réponse mockée en texte brut. "
             . "Lorem ipsum dolor sit amet, consectetur adipiscing elit. "
             . "Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.";
    }

    private function generateAltText(): string
    {
        $samples = [
            "Une personne travaillant sur un ordinateur portable dans un bureau moderne",
            "Paysage de montagne avec un lac au premier plan et un ciel bleu",
            "Graphique illustrant la croissance des ventes trimestrielles",
            "Logo de l'entreprise sur fond blanc",
        ];

        return $samples[array_rand($samples)];
    }

    private function generateJson(): string
    {
        return json_encode([
            'success' => true,
            'data' => [
                'id' => 12345,
                'title' => 'Exemple de données mockées',
                'items' => ['item1', 'item2', 'item3'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function markdownToGutenberg(string $markdown): string
    {
        $lines = explode("\n", $markdown);
        $blocks = [];
        $listItems = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            if (empty($trimmedLine)) {
                if ($inList && !empty($listItems)) {
                    $blocks[] = $this->createListBlock($listItems);
                    $listItems = [];
                    $inList = false;
                }
                continue;
            }

            // Headings
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmedLine, $matches)) {
                if ($inList && !empty($listItems)) {
                    $blocks[] = $this->createListBlock($listItems);
                    $listItems = [];
                    $inList = false;
                }
                $level = strlen($matches[1]);
                $text = esc_html($matches[2]);
                $blocks[] = "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level}>{$text}</h{$level}>\n<!-- /wp:heading -->";
                continue;
            }

            // List items
            if (preg_match('/^[-*]\s+(.+)$/', $trimmedLine, $matches)) {
                $inList = true;
                $listItems[] = esc_html($matches[1]);
                continue;
            }

            // Paragraphs
            if ($inList && !empty($listItems)) {
                $blocks[] = $this->createListBlock($listItems);
                $listItems = [];
                $inList = false;
            }
            $blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html($trimmedLine) . "</p>\n<!-- /wp:paragraph -->";
        }

        // Flush remaining list items
        if ($inList && !empty($listItems)) {
            $blocks[] = $this->createListBlock($listItems);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @param array<string> $items
     */
    private function createListBlock(array $items): string
    {
        $listContent = '<ul class="wp-block-list">';
        foreach ($items as $item) {
            $listContent .= "<li>{$item}</li>";
        }
        $listContent .= '</ul>';

        return "<!-- wp:list -->\n{$listContent}\n<!-- /wp:list -->";
    }
}
