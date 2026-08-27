# wp-ai-experiment

WordPress AI experiment plugin

## Table of Contents

* [Requirements](#requirements)
* [Installation](#installation)
* [Connecting an AI provider](#connecting-an-ai-provider)
* [Features](#features)
* [Using the abilities from WP-CLI](#using-the-abilities-from-wp-cli)
* [Generating an excerpt in the editor](#generating-an-excerpt-in-the-editor)
* [Using the abilities from an MCP client](#using-the-abilities-from-an-mcp-client)
* [Troubleshooting](#troubleshooting)
* [Further reading](#further-reading)
* [Copyright and License](#copyright-and-license)
* [Contributing](#contributing)

## Requirements

| Requirement | Why |
| --- | --- |
| WordPress 7.0 or newer | The Abilities API and the PHP AI Client SDK are bundled in core from 7.0. |
| PHP 8.2 or newer | Enforced by the plugin header. |
| An AI provider plugin | Core ships the AI client but no provider. Without one, the AI abilities return a "no connected provider" error. |
| [MCP Adapter](https://github.com/WordPress/mcp-adapter) | Declared in `Requires Plugins`, so WordPress will not activate this plugin without it. |
| Node.js 22 | Only to build the editor assets. Not needed at runtime. |

## Installation

### 1. Install the MCP Adapter

This plugin declares `Requires Plugins: mcp-adapter`, so WordPress refuses to
activate it until the adapter is present. Install and activate it first.

### 2. Install an AI provider

Pick whichever provider you have an API key for and activate it:

* [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/) (Gemini)
* [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)
* [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/) (Claude)

You can activate more than one. The plugin never names a provider or a model, so
whichever connected model can satisfy the request is the one that gets used.

### 3. Install this plugin

```shell
composer require mahbub/wp-ai-experiment
```

### 4. Build the editor assets

The compiled JavaScript is not committed, and the editor panel is skipped when it
is missing:

```shell
npm install && npm run build
```

Everything else - the abilities, WP-CLI, REST and MCP - works without this step.

## Connecting an AI provider

API keys are managed by WordPress core, not by this plugin or by the provider
plugins.

1. Go to **Settings → Connectors** (`/wp-admin/options-connectors.php`).
2. Find your provider in the list and paste in its API key.
3. Save.

To confirm the site can reach a model, run an ability that needs one:

```shell
wp ability run wp-ai-experiment/draft-post-excerpt \
  --input='{"post_id":1}' --user=admin
```

A `no connected AI provider` error means step 2 has not taken effect. A `429` or
`503` means the key works but the account is out of quota or the provider is
busy - see [Troubleshooting](#troubleshooting).

## Features

### Abilities

The plugin registers five abilities in the `wp-ai-experiment` category. All five
are exposed over the REST API and to MCP clients, and each one checks
capabilities before it runs.

| Ability | What it does | Needs AI |
| --- | --- | --- |
| `wp-ai-experiment/get-site-summary` | Site name, URL, language, version, active theme and content counts. | No |
| `wp-ai-experiment/find-content` | Searches posts and pages with filters for status, type, author and date. | No |
| `wp-ai-experiment/draft-post-excerpt` | Drafts an excerpt for one post and returns it as a suggestion. Does not save. | Yes |
| `wp-ai-experiment/update-post-excerpt` | Replaces a post's excerpt and reports the previous value. | No |
| `wp-ai-experiment/analyze-post-content` | Structured review: summary, tone, reading level, SEO issues, suggested tags. | Yes |

Drafting and applying are deliberately separate abilities. Nothing writes to a
post until you ask it to, which keeps model output reviewable before it lands on
something published.

### Editor integration

`draft-post-excerpt` is wired into the block editor as an **AI Excerpt** panel.
See [Generating an excerpt in the editor](#generating-an-excerpt-in-the-editor).

### MCP Adapter configuration

The MCP Adapter ships three generic discovery tools and does not promote any
ability on its own, so an agent would otherwise reach these five only through a
second hop. This plugin adds them to the adapter's default server by name:

```php
add_filter( 'mcp_adapter_default_server_config', ... );
```

Each one therefore appears as a first-class MCP tool with its own schema and
description. Two things are needed for that, and both are set for every ability
in `src/Abilities/Registrar.php`:

* `meta.show_in_rest` - without it the ability is invisible to REST and its run
  route answers 404 rather than 403.
* `meta.mcp.public` - the adapter only turns an ability into a tool when it opts
  in here.

Confirm the server picked them up (expect 8 tools: the adapter's 3 plus these 5):

```shell
wp mcp-adapter list
```

## Using the abilities from WP-CLI

WP-CLI is the quickest way to try an ability without an editor or an agent.

List everything this plugin registers:

```shell
wp ability list --category=wp-ai-experiment
```

Inspect one, including the schema callers have to satisfy:

```shell
wp ability get wp-ai-experiment/draft-post-excerpt
```

Check your input before spending an API call on it. This reports exactly which
property is wrong:

```shell
wp ability validate wp-ai-experiment/draft-post-excerpt \
  --input='{"post_id":44,"tone":"bogus"}'
# Error: input[tone] is not one of neutral, informative, conversational, and promotional.
```

Check whether a given user is allowed to run it. This prints nothing and exits
`0` when permitted, non-zero when not - handy for confirming the capability
checks actually bite:

```shell
wp ability can-run wp-ai-experiment/update-post-excerpt \
  --input='{"post_id":44,"excerpt":"x"}' --user=admin
```

Run one. Start with the ability that needs no AI provider, so you are testing the
plumbing rather than your API key:

```shell
wp ability run wp-ai-experiment/get-site-summary --user=admin
```

Then the full draft-and-apply cycle:

```shell
# Ask for a suggestion. Nothing is saved.
wp ability run wp-ai-experiment/draft-post-excerpt \
  --input='{"post_id":44,"max_words":25,"tone":"informative"}' --user=admin

# Apply one once you are happy with it.
wp ability run wp-ai-experiment/update-post-excerpt \
  --input='{"post_id":44,"excerpt":"The excerpt text."}' --user=admin
```

`--user` matters: every ability checks capabilities, and without it WP-CLI runs
as nobody and the permission check fails.

## Generating an excerpt in the editor

1. Build the assets if you have not already (`npm install && npm run build`).
2. Edit a post whose post type supports excerpts. Note that **pages do not by
   default** - the panel is hidden wherever the core Excerpt field is.
3. Open the **Document** sidebar and find the **AI Excerpt** panel.
4. Choose a tone and a word limit, then click **Generate excerpt**.
5. Review the suggestion and click **Apply**, or **Regenerate** for another.

Two behaviours worth knowing:

* **The post is saved first if it has unsaved changes.** The ability reads the
  stored post, so without that step the suggestion would describe the last saved
  revision rather than what is on screen. You will see "Saving post…" before
  "Drafting excerpt…".
* **Applying does not save.** The excerpt goes into the editor, where it stays
  undoable and is written by the normal **Update** button. To prove it, apply a
  suggestion and then, before pressing Update, run:

  ```shell
  wp post get 44 --field=excerpt
  ```

  It still shows the old value. Press Update and run it again to see it change.

## Using the abilities from an MCP client

The adapter exposes the default server over HTTP at:

```text
/wp-json/mcp/mcp-adapter-default-server
```

For a local client that speaks STDIO, `bin/mcp-stdio.sh` wraps
`wp mcp-adapter serve`. `.mcp.json` in this repository registers three servers
that differ only by the WordPress user they run as - admin, author and
subscriber - which is what makes it easy to see the permission callbacks
rejecting work the current user is not allowed to do.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Plugin will not activate | The MCP Adapter is not installed or not active. |
| No **AI Excerpt** panel | Assets not built, or the post type has no excerpt support. |
| `no connected AI provider` | No provider plugin is active, or no key is saved under Settings → Connectors. |
| `429 - You exceeded your current quota` | The key is valid but the account has no credit. |
| `503 - high demand` | The provider is overloaded. Retry, or connect a second provider. |
| Ability runs on CLI but 404s over REST | `meta.show_in_rest` is not set on that ability. |
| `405` from the run route | Wrong HTTP verb. Read-only abilities require `GET`, updates require `POST`. |

A model that rejects the `temperature` parameter - OpenAI's reasoning models do -
is handled automatically: the request is retried once without it rather than
failing, so switching between providers does not require any code change.

## Further reading

* [Local development](./docs/local-development.md)

## Copyright and License

This package is [free software](https://www.gnu.org/philosophy/free-sw.en.html) distributed under
the terms of the GNU General Public License version 2 or (at your option) any later version. For the
full license, see [LICENSE](./LICENSE).

## Contributing

All feedback, bug reports and pull requests are welcome.
