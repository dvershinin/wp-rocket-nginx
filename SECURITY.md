# Security policy

Please report vulnerabilities privately through GitHub Security Advisories for
this repository. Do not open a public issue for an unpatched vulnerability.

The plugin writes Nginx configuration but deliberately does not execute shell
commands, signal processes, or reload Nginx.
