# WP Block Forker

Select blocks in the WordPress block editor and fork them into a new post —
as a copy or a move. Also available headless via WP-CLI.

## Requirements

- WordPress 6.5+
- PHP 8.1+
- Node 20+ (only for building from source — not required to run an
  already-built release zip)

## Install

Download `wp-block-forker.zip` from the latest GitHub release and install it
normally via **Plugins → Add New → Upload Plugin**. The plugin checks
GitHub Releases for updates automatically once installed (see
`AGENTS.md` → Release process).

## Building from source

```
npm ci
npm run build
```

This compiles `src/` into `build/`, which the PHP side requires at runtime.
`build/` is gitignored — always run the build before activating a plugin
checked out from source.

## Usage

**Editor:** select one or more blocks → block toolbar "⋮" menu → "Fork to
new post…" → set a title, pick a post type if more than one is available,
choose copy or move → Fork.

**WP-CLI:**

```
wp block-fork <source_post_id> --blocks=<comma-separated indices> [--mode=copy|move] [--post-type=post] [--title="..."] [--yes]
```

`--blocks` indices are zero-based positions in the post's top-level blocks,
in `parse_blocks()` order. `--mode=move` requires `--yes`.

See `AGENTS.md` for architecture, the REST API contract, and design
decisions worth knowing before changing anything.
