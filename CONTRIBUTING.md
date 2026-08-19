# How to Contribute

We would love to accept your patches and contributions to this project.

## Contribution process

### Building the plugin

Run `npm install` and `npm run build` to build all the JavaScript and CSS.

### Running the tests

`npm run wp-env start` brings up WordPress with the Gutenberg plugin this one
depends on. With that running:

- `composer test` runs the PHP unit tests.
- `npm run test:e2e` runs the browser tests.

The plugin behaves differently on a network, where a temporary account belongs
to the network rather than to the site that lent it. To run against one, copy
the multisite configuration over the file wp-env merges on top of its own and
start again from scratch:

```sh
cp .wp-env.multisite.json .wp-env.override.json
npm run wp-env destroy
npm run wp-env start
WP_MULTISITE=true npm run test:e2e
```

Delete `.wp-env.override.json` and destroy again to go back to a single site.

Setting `COLLECT_COVERAGE=true` on an e2e run writes a coverage report to
`artifacts/e2e-coverage`. It needs source maps to point at anything readable,
so build with `WP_DEVTOOL=source-map NODE_ENV=development npm run build` first.

### Code Reviews

All submissions, including submissions by project members, require review. We use [GitHub pull requests](https://docs.github.com/articles/about-pull-requests) for this purpose.
