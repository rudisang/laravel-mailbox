# Security Policy

## Supported versions

Laravel Mailbox is pre-1.0 software. Security fixes are released for the latest `0.1.x` version only.

| Version | Supported |
|---|---|
| `0.1.x` | Yes |
| `< 0.1` | No |

## Reporting a vulnerability

Please do not disclose suspected vulnerabilities in a public issue, discussion, pull request, or social post.

Open a [private vulnerability report](https://github.com/rudisang/laravel-mailbox/security/advisories/new) through GitHub Security Advisories. Include the affected version, environment, reproduction steps or a minimal proof of concept, security impact, and any suggested remediation. Avoid attaching real captured mail or credentials; use synthetic data.

The maintainer will acknowledge the report, investigate it privately, coordinate remediation and disclosure with the reporter, and publish a security advisory when appropriate.

## Threat model summary

Captured email is hostile input and may also contain secrets. Laravel Mailbox is designed around these boundaries:

- The package is a `require-dev` local/testing tool. Its fail-closed guard returns 404 for disallowed routes and makes the transport throw. `production` is refused even if configured as an allowed environment.
- Email HTML never enters the mailbox page DOM. It is rendered in a separate opaque-origin iframe with an empty-token sandbox.
- Active markup and author-controlled resource or navigation attributes are removed at response time. A restrictive preview CSP blocks scripts, connections, forms, frames, objects, fonts, and remote resource fetches; only bounded data images and package-generated part URLs for the same message may render.
- Preview responses disable referrers, MIME sniffing, and caching. The workbench has its own restrictive CSP.
- Attachments are addressed by opaque IDs. Only allowlisted raster bytes may display inline; everything else is downloaded as `application/octet-stream` with a hardened filename and `nosniff`.
- Links are neutralized in the preview. Canonical `http`, `https`, and `mailto` destinations may be opened only by a deliberate click from the separate Links tab.
- Capture and browsing do not proxy images, check links, or make other message-directed network requests. Ordinary logs must not contain message content or recipient data.
- Raw messages, bodies, addresses, attachments, links, and tokens can all be sensitive. Storage must remain private, and captures should be removed with `mailbox:clear` or `mailbox:prune` when no longer needed.

## What is not covered

CSS is contained, not sanitized. Author style blocks are retained inside the sandboxed preview and can make that preview visually misleading. The sandbox and CSP are intended to contain CSS to that opaque document and block its network activity; they do not promise safe or faithful visual rendering.

Production use is not supported or covered by the security model. The guard intentionally provides no production override. This package is not an SMTP server, mail relay, production observability system, malware scanner, content-disarm service, or Gmail/Outlook/Apple Mail rendering emulator.

The preview controls reduce browser risk; they do not make captured content non-sensitive or safe to expose publicly. Host applications remain responsible for access to the development environment, filesystem, and optional `viewMailbox` gate.
