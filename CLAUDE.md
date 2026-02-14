# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is **AI Forge Dev Tools**, a WordPress plugin that serves as a developer-only add-on for the main AI Forge plugin (`../wp-ai-forge`). The project is in early development stage with scaffolding in place but minimal implementation.

AI Forge is a WordPress productivity plugin that integrates AI agents (Gemini, OpenAI) for content generation via a "Content Integrator" system using templates.

## Commands

### PHP Backend
```bash
composer install    # Install dependencies, generates vendor/autoload.php
```

### Frontend (in main plugin: ../wp-ai-forge/admin/)
```bash
npm run build       # Build production assets
npm run start       # Development server with hot reload
npm run lint:js     # Lint JavaScript
npm run lint:css    # Lint CSS
```

No testing infrastructure is currently set up.

## Architecture

### Bootstrap Pattern
The plugin follows a singleton bootstrap pattern:
```php
// Entry point: wp-ai-forge-devtools.php
add_action('plugins_loaded', function () {
    DevTools::boot(__FILE__);
});
```

The `DevTools` class in `src/DevTools.php` is the core service class that should orchestrate all functionality.

### Relationship to Main Plugin
This plugin extends the main `wp-ai-forge` plugin. Reference patterns from:
- `../wp-ai-forge/src/Core.php` - Bootstrap and dependency injection pattern
- `../wp-ai-forge/src/REST/` - REST API controller implementations
- `../wp-ai-forge/src/Config/ConfigRepository.php` - Configuration handling

### Key Architectural Decisions (from main plugin)
1. **Templates via CPT**: Content Integrator exclusively uses Templates (Custom Post Type), not pages or block patterns directly
2. **User-Provided API Keys**: No internal credit system; users provide their own API keys for AI providers
3. **Demo Mode**: `DemoMode::isEnabled()` enables testing without real API keys

### Namespace
All PHP classes use the `AIForge\` namespace with PSR-4 autoloading from `src/`.

## Tech Stack
- PHP 8.2+ with strict types
- WordPress plugin architecture
- Composer for PHP dependencies
- React + WordPress Scripts for admin UI (if needed)
- REST API namespace: `aiforge/v1`
