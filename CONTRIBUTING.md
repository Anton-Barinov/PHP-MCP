# Contributing to PHP-MCP

Thank you for your interest in improving **PHP-MCP**!

## How to Contribute

1. **Fork the Repository** on GitHub.
2. **Create a Feature Branch**:
   ```bash
   git checkout -b feature/awesome-adapter
   ```
3. **Write Unit Tests**:
   Ensure all new features or bug fixes have corresponding test cases in `tests/`.
4. **Run the Test Suite**:
   ```bash
   php tests/run_tests.php
   ```
5. **Commit your changes**:
   Keep commit messages clear and concise.
6. **Open a Pull Request**:
   Explain the rationale, any edge cases considered, and client testing performed (Claude Desktop, Cursor, etc.).

## Code Guidelines
- Zero external runtime dependencies in core (keep package lightweight).
- PHP 8.1+ strictly typed (`declare(strict_types=1);`).
- Fully compliant with the official Anthropic Model Context Protocol specification (`2024-11-05`).
