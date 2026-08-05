# WP Rocket Nginx

Standalone WordPress plugin that generates an Nginx configuration compatible
with WP Rocket cache files. The template is derived from SatelliteWP's
Rocket-Nginx and retains its MIT attribution.

## Testing

Run the fast L1 suite from the repository root:

```bash
make test
```

Run the Docker integration suite before release:

```bash
make e2e
```

The E2E suite must remain hermetic. It simulates WP Rocket's documented option
and cache-file formats and must not depend on a private WP Rocket checkout.

## Release

The default branch is `main`. Tags use `vX.Y.Z`. The tag workflow reruns unit
and E2E tests before creating the GitHub release and attaching the plugin zip.
