# Dorsia

Teardown of Dorsia, the members-only reservations app that sells certainty: members prepay a dynamically priced minimum spend to lock a hard-to-get table, and the restaurant is paid whether or not they show.

Built from public sources: Dorsia's FAQ, Stripe's case study, job posts and the live site. Anything marked *inferred* is reasoned from those signals, not confirmed by Dorsia.

## Docs

| Doc | Answers | Status |
| --- | --- | --- |
| [Product overview](docs/product_overview.md) | How Dorsia makes money: membership, minimum spend, wallet, breakage | Done |
| [Tech stack](docs/tech_stack.md) | What it runs on, with a confidence rating per layer | Done |
| [Service map](docs/service_map.md) | How the backend likely splits into domains, and the booking flow *(inferred)* | Done |
| [Build plan](docs/build_plan.md) | How to build it | Empty |

Read them in that order: the product explains the money, the stack explains the tools, the service map connects the two.

## Key findings

- **Two charges, not one.** An annual membership ($175 to $25K by tier) buys access; a per-reservation minimum spend, credited to the bill, locks the table.
- **Float and breakage are the business model.** Cancel 8+ hours out and the prepayment returns as dining credit, not cash; inside 8 hours, 50% is forfeited. Premium's $4,000 in Fun Coupons keeps money inside the Wallet.
- **Tables are priced like airline seats.** Sell-out speed, custom-request bids and searches for unavailable slots likely feed a dynamic minimum per slot *(inferred)*.
- **Laravel on AWS Lambda behind an Expo app.** Laravel (via Vapor) owns the core domain; Node/TypeScript covers newer services; Stripe Billing and Connect handle subscriptions and payouts within 24 hours.
- **The wallet ledger is the riskiest domain.** Every forfeit, credit and coupon must reconcile across Reservations, Memberships and Stripe.

## Sources

- [Dorsia FAQ](https://www.dorsia.com/faq)
- [Stripe: Dorsia case study](https://stripe.com/mx/customers/dorsia)
- [Greenhouse: Product Engineer (NYC)](https://job-boards.greenhouse.io/dorsia/jobs/4754917007)
- [Greenhouse: Product Engineer (NYC or Miami)](https://job-boards.greenhouse.io/dorsia/jobs/5198540007)
- [Platform Engineer, Mobile](https://map.rezi.ai/jobs/job/27758127/platform-engineer-mobile-at-dorsia)
