# Contributing

Thanks for helping improve Luminate! To keep the codebase healthy and friendly with enterprise WordPress workflows (à la Inpsyde), please follow these guidelines:

1. **Coding standards** – run `composer lint` before opening a PR. This enforces PSR-12 plus the WordPress-Core/Extra/Docs sniffs.
2. **Tests** – run `composer test` to execute the PHPUnit suite. Add coverage for new behaviour and keep WordPress mocks up to date.
3. **Design** – prefer dependency injection over direct calls to WordPress globals. Use the `Luminate\Contracts\WordPress` abstraction whenever you need to interact with core APIs so everything stays testable.
4. **i18n & escaping** – translate user-facing strings and escape output using the appropriate WordPress helpers.
5. **Commits/PRs** – keep changes focused, document the motivation in the pull request, and note any follow-up work or limitations.

If you add a new dependency, remember to update `composer.lock` and mention it in your PR description. Thanks again for contributing!
