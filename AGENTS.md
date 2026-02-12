# WordPress Webhook Framework - Agent Guidelines

## Commands
- `composer install` - Install dependencies
- `composer run-script phpstan` - Run static analysis (PHPStan level 6)
- `composer run-script phpcs` - Lint code (WordPress coding standards)
- `composer run-script phpcbf` - Auto-fix code style issues

## Code Style
- **PHP 8.0+ library** with WordPress coding standards (WPCS 3.1)
- **Namespace**: `Citation\WP_Webhook_Framework` (PSR-4)
- **Strict types**: Always use `declare(strict_types=1);` after opening PHP tag
- **Naming**: snake_case for methods/properties/functions (WordPress convention), PascalCase for classes
- **Yoda conditions**: Use for all comparisons (e.g., `null === $var`)
- **Early returns**: Exit early to reduce nesting
- **PHPStan annotations**: Required for complex types, especially arrays (e.g., `@phpstan-var array<string,string>`)
- **Docblocks**: Required for all classes, methods, properties; explain *why* not *what*
- **Type hints**: Use strict PHP 8.0 types + PHPStan types for precision
- **Error handling**: Direct exception throwing in Action Scheduler context; use `wp_trigger_error()` elsewhere
- **Dependencies**: Uses WooCommerce Action Scheduler 3.7+ for async webhooks
- **Testing**: No test suite currently configured

## Architecture
Entity-based webhook framework using registry pattern. Webhooks extend abstract `Webhook` class, implement `init()` method, and register via `Service_Provider`.

**Core structure**: See @README.md for architecture overview and quick examples.

## Documentation
- **Location**: Add all documentation to `/docs` directory
- **Style**: Keep docs brief but fully describe functionality—no unnecessary prose, just essential details
- **Maintenance**: After making changes to functionality, architecture, or APIs, update relevant documentation in `/docs` to keep it current
- **Code**: Make code examples type safe
- **Relative Links**: Use relative links for internal references within the docs folder
- **Avoid redundancy**: If a concept is explained in one doc, reference it in others instead of repeating

## Documentation Standards

### Format
- **All documentation files must use MDX format** (`.mdx` extension) with Astro Starlight frontmatter
- Required frontmatter fields:
  - `title` (required) - Page title displayed in browser tab and page header
  - `description` (optional but recommended) - Used for SEO and social media previews
  - `sidebar` (optional) - Configure sidebar display with `order`, `label`, `hidden`, `badge`
- Use relative links with `.mdx` extension for internal documentation references
- Example frontmatter:
```yaml
---
title: Configuration
description: Configure webhook behavior using methods, filters, and the registry.
sidebar:
  order: 2
---
```

See [Astro Starlight Frontmatter Reference](https://starlight.astro.build/reference/frontmatter/) for all available options.

## Reference Documentation
Add new docs with an "@" mention to the "AGENTS.md" including a quick explanation. Keep the docs always up to date.
- @README.mdx - Quick start, basic usage, architecture overview
- @docs/custom-webhooks.mdx - Creating webhooks, registry pattern, plugin integrations (WooCommerce, CF7, Gravity Forms)
- @docs/hooks-and-filters.mdx - All available hooks and filters with examples
- @docs/configuration.mdx - Constants, configuration methods, precedence rules
- @docs/notifications.mdx - Notification system, opt-in pattern, custom handlers
- @docs/webhook-statefulness.mdx - Webhook statefulness rules and best practices
- @docs/failure-handling.mdx - Failure monitoring, retry mechanism, blocking behavior
