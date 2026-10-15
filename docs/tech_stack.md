# Tech Stack

A Laravel backend on AWS Lambda serves an Expo React Native app, with Node/TypeScript for newer services. Rolled up from job posts, shared engineer profiles and the live site; individual LinkedIn profiles were not accessible.

| Layer | Tech | Confidence |
| --- | --- | --- |
| Mobile | React Native + Expo (EAS Build, Expo Updates), TypeScript, New Architecture under evaluation | High |
| Mobile SDKs | Braze, Segment, Datadog, Branch, Stripe, Plaid | High |
| Backend | Laravel (PHP 8.3), Node/TypeScript | High |
| API | REST and GraphQL (Apollo-style client) | Medium |
| Data | PostgreSQL, Redis | High |
| Real-time | Pusher WebSockets | High |
| Compute | AWS Lambda via Laravel Vapor | High |
| Infra | AWS, Cloudflare, Vercel, Terraform, GitHub Actions | High |
| Web | Next.js marketing site, likely on Vercel | Medium |
| Internal and data | Retool, Metabase, Segment, dbt | High |
| Payments | Stripe Billing and Connect | High |

## Signals worth noting

- **Plaid** points to bank-linking; ACH and crypto are top member requests, and MoonPay is an investor.
- **Branch** powers the download-and-apply funnel via deep links.
- **Laravel owns the core domain:** reservations, payments, receipts, restaurant integrations.
- **A dedicated mobile platform hire** signals investment in release infra and code standards.

Sources: [Greenhouse: Product Engineer (NYC)](https://job-boards.greenhouse.io/dorsia/jobs/4754917007) · [Greenhouse: Product Engineer (NYC or Miami)](https://job-boards.greenhouse.io/dorsia/jobs/5198540007) · [Platform Engineer, Mobile](https://map.rezi.ai/jobs/job/27758127/platform-engineer-mobile-at-dorsia) · [Stripe: Dorsia case study](https://stripe.com/mx/customers/dorsia)