# Security Policy

## Supported Versions

Only the latest `1.x` minor release receives security fixes. Older minor
versions are not patched — please upgrade.

| Version | Supported |
| ------- | --------- |
| 1.x     | ✅        |
| < 1.0   | ❌        |

## Reporting a Vulnerability

**Please do not open a public issue for security problems.**

Use GitHub's private vulnerability reporting:

1. Go to the [Security tab](https://github.com/webrek/laravel-telescope-mongodb/security)
2. Click **Report a vulnerability**
3. Fill in the form — only the maintainers will see your report

We aim to acknowledge new reports within **72 hours** and to ship a fix
or mitigation in the next patch release once the issue is confirmed.

When reporting, please include:

- The package version (`composer show webrek/laravel-telescope-mongodb`)
- Laravel and PHP versions
- MongoDB server version
- A minimal reproducer
- The impact you observed (data exposure, denial of service, etc.)

Coordinated disclosure is appreciated — we will credit reporters in the
release notes unless you ask otherwise.
