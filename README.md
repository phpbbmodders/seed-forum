# seed-forum

[![Tests](https://github.com/phpbbmodders/seed-forum/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbbmodders/seed-forum/actions/workflows/tests.yml)

Set up disposable local phpBB 3.3.x boards with SQLite, test fixtures,
extension checkouts and optional styles. Configuration is stored in YAML.

**Only use this tooling with disposable boards.** `--reset` deletes the
board's configuration, SQLite database and cached state before reinstalling.
The installer and fixture users have public test credentials. Keep boards
local, not internet-facing.

## Features

- Download phpBB when the board directory is missing or empty, with SHA-256 verification.
- Bind-mount local extensions and enable or disable them through phpBB's CLI.
- Seed standard forum fixtures and optional Knowledgebase fixtures.
- Copy and install local styles, with an optional default style.
- Create or update an optional nginx site, with validation and rollback.
- Run mount, unmount, seed, style and cache operations without resetting the database.
- Validate configuration and preview operations with `--dry-run`.

## Requirements

- Linux with Bash and bind-mount support.
- Python 3.9 or newer with PyYAML.
- PHP CLI compatible with the board, including SQLite support.
- `jq`, `sqlite3`, `curl`, `unzip` and `sha256sum` for reset operations.
- `sudo` access for mounts and permission repairs; the web group is `www-data`.
- nginx and PHP-FPM only when using automatic nginx site setup.

Other Linux setups may need changes to the permission or web-server settings.
This is local development tooling, not a production deployment tool.

## Installation

```bash
git clone https://github.com/phpbbmodders/seed-forum.git
cd seed-forum
```

Install the requirements using your system's package manager. For example,
Debian and Ubuntu provide PyYAML as `python3-yaml`.

## Quick Start

Create `config/local.yml` with a minimal core-only board configuration:

```yaml
board:
  root: /tmp/seed-forum-board
  server_name: localhost
  server_port: '8093'
  seeds: []
extensions: {}
styles: {}
```

Choose a board directory reserved for disposable testing. Validate the
configuration and inspect the plan before resetting:

```bash
bin/reset-board.sh --validate
bin/reset-board.sh --reset --dry-run
```

To carry out the destructive installation:

```bash
bin/reset-board.sh --reset
```

This example installs no extensions or fixtures and does not configure a
web server. To add standard fixtures, set `board.seeds` to
`[bin/seed-standard-fixtures.php]`. The configured port determines board URLs;
it does not start a server unless nginx site setup is configured.

`config/local.yml` is ignored by Git. For an extension-enabled configuration,
start with [config/local.yml.example](config/local.yml.example) and replace its
machine-specific paths. Running the command without an operation prints help.

## Documentation

The [usage guide](docs/usage.md) covers configuration, extension selection,
styles, nginx, fixtures, seed protection, mount records and all command options.

For local regression tests:

```bash
python3 -m unittest discover -s tests -v
```

## Contributing

Contributions are welcome!

- **Bug reports**: [Open an issue](https://github.com/phpbbmodders/seed-forum/issues).
- **Everything else** (questions, feature requests, ideas, general discussion): [Use Discussions](https://github.com/phpbbmodders/seed-forum/discussions).
- Pull requests are welcome for bug fixes or discussed features.

## Acknowledgments

- Code, testing and documentation assisted by [Claude](https://www.anthropic.com/claude) and [Codex](https://openai.com/codex/).
- Built around phpBB's CLI installer and forum APIs.

## License

This project is licensed under the **GNU General Public License v2.0**.

See [LICENSE](LICENSE) for more information.
