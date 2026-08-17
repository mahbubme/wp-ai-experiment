# Local development

## wp-env

We use wp-env provided by `@wordpress/env` to setup a local system.

## xDebug

In order to use xDebug, you must start `wp-env` with the `--xdebug` flag. Additionally you need to set in your PHPStorm configuration the paths under "Settings > PHP > Servers" like following:

- Name: `localhost`
- Host: `localhost`
- Port: `8888`
- Debugger: `xDebug`
- Map your local root folder to `/var/www/html/wp-content/<%= package.projectSlug >`

```shell
npm run wp-env start -- --xdebug
```

## Run WP-CLI commands

To run WP-CLI, you must pipe your commands into the container like following:

```shell
npm run wp-env -- run cli wp user list
```
