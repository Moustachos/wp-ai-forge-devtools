<?php

declare(strict_types=1);

namespace AIForge\Admin;

class DeveloperPage
{
    private const PAGE_SLUG = 'ai-forge-developer';
    private const PARENT_SLUG = 'ai-forge';
    private const CAPABILITY = 'manage_options';

    public function register(): void
    {
        add_action('admin_menu', $this->addSubmenuPage(...), 20);
        add_action('admin_menu', $this->moveToEnd(...), 1000);
    }

    public function moveToEnd(): void
    {
        global $submenu;

        if (!isset($submenu[self::PARENT_SLUG])) {
            return;
        }

        $items = $submenu[self::PARENT_SLUG];
        $devItem = null;
        $reordered = [];

        foreach ($items as $item) {
            if (($item[2] ?? '') === self::PAGE_SLUG) {
                $devItem = $item;
                continue;
            }
            $reordered[] = $item;
        }

        if ($devItem !== null) {
            $reordered[] = $devItem;
        }

        $submenu[self::PARENT_SLUG] = $reordered;
    }

    public function addSubmenuPage(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Développeur', 'ai-forge-devtools'),
            __('Développeur', 'ai-forge-devtools'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            $this->render(...)
        );
    }

    public function render(): void
    {
        echo '<div id="aiforge-dev-root" class="wrap aiforge-developer-page"></div>';
    }

    public static function getPageSlug(): string
    {
        return self::PAGE_SLUG;
    }
}
