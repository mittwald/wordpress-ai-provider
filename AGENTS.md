# CLAUDE.md

This file provides guidance to agentic coding agents when working with code in this repository.

## Project Overview

WordPress plugin that integrates mittwald's AI Hosting platform with the WordPress AI Experiments Plugin (`wp-ai-client`). It implements an OpenAI-compatible AI provider for chat completions, streaming, function calling, and image generation.

## Commands

```bash
composer install              # Install dependencies
composer run analyse          # Run PHPStan static analysis (level 10)
composer run format           # Check code formatting (WordPress Coding Standards)
composer run format:fix       # Auto-fix formatting issues
```

No test framework is configured yet. CI runs `composer run analyse` across PHP 7.4–8.5.

## Architecture

All source code lives in `includes/` under the `Mittwald\AiProvider\` namespace (PSR-4 autoloaded). The main plugin file `mittwald-ai-provider.php` bootstraps the autoloader and registers the provider.

**Key classes and their inheritance:**

- `MittwaldAIProvider` extends `AbstractApiProvider` — Registers the provider, creates model instances, manages API credentials. Base URL: `https://llm.aihosting.mittwald.de/v1`
- `MittwaldTextGenerationModel` extends `AbstractOpenAiCompatibleTextGenerationModel` — Chat/text completions with optional vision
- `MittwaldImageGenerationModel` extends `AbstractOpenAiCompatibleImageGenerationModel` — Image generation with special `gpt-image-*` parameter handling
- `MittwaldModelMetadataDirectory` extends `AbstractOpenAiCompatibleModelMetadataDirectory` — Fetches models from API, defines per-model capabilities, implements sorting preferences

The abstract base classes come from the `wordpress/wp-ai-client` SDK. Most logic is inherited; these classes override specific methods for mittwald-specific behavior.

## Conventions

- **PHP version:** 7.4 minimum, `declare(strict_types=1)` on all files
- **Static analysis:** PHPStan level 10 with `treatPhpDocTypesAsCertain: false`
- **Code style:** WordPress Coding Standards via PHPCS, text domain `mittwald-ai-provider`
- **Commit messages:** Conventional Commits (`feat:`, `fix:`, `chore:`, with optional scope like `chore(cgl):`)
- **PR descriptions:** Do not include a test plan section
