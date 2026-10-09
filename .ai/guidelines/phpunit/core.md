# PHPUnit

- This project uses PHPUnit 9. Create test classes by hand in `tests/Feature` or `tests/Unit`, following the existing tests there.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run tests with `./test.sh`. It accepts phpunit arguments, e.g. `./test.sh --filter=testName` or `./test.sh tests/Feature/SomeTest.php`.
- Run the narrowest set of tests that covers the change, and rerun a test after each change to it.
- Before finishing, run the full suite with `./test.sh` (no arguments). It also checks code style (PHPCS), static analysis (PHPStan), and the inventory.
