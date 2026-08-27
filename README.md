# wp-ai-experiment

WordPress AI experiment plugin

## Table of Contents

* [Installation](#installation)
* [Documentation](#documentation)
* [Copyright and License](#copyright-and-license)
* [Contributing](#contributing)

## Installation

The best way to use this package is:

```shell
composer require mahbub/wp-ai-experiment
```

Requires WordPress 7.0 or newer: it builds on the Abilities API and on the PHP AI
Client SDK that core bundles.

## Documentation

* [Abilities](./docs/abilities.md) - the ability category and five abilities this
  plugin registers, the Abilities API behaviour worth knowing before adding more,
  and how abilities project into the REST API and AI tool calling.
* [AI workflows](./docs/ai-workflows.md) - the two AI-powered workflows this plugin
  runs on core's bundled PHP AI Client SDK, how they stay provider-agnostic, and how to
  connect a provider.
* [Local development](./docs/local-development.md)

## Copyright and License

This package is [free software](https://www.gnu.org/philosophy/free-sw.en.html) distributed under
the terms of the GNU General Public License version 2 or (at your option) any later version. For the
full license, see [LICENSE](./LICENSE).

## Contributing

All feedback, bug reports and pull requests are welcome.
