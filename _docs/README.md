# `_docs`

Project documentation for `b2b.mosconitours.core`.

## Contents

- **[tboair/](tboair/00-overview.md)** — the TBO Air flight-supplier integration: external API
  reference, current implementation, the phase-by-phase plan to complete the booking lifecycle, and a
  study of the **live** system that already books and tickets. Start at
  [`tboair/00-overview.md`](tboair/00-overview.md).
- **[tbohotel/](tbohotel/00-overview.md)** — the TBO Holidays hotel-supplier integration: the
  external API reference, a study of the **live** system that already books hotels, and the
  phase-by-phase plan. **Nothing is built yet.** Start at
  [`tbohotel/00-overview.md`](tbohotel/00-overview.md).
- **[rbac/](rbac/00-overview.md)** — Role-Based Access Control: the `/admin` area, the permission
  registry, and the native-Laravel authorization layer (Gates + Policies) guarding every route,
  nav item, and action.
- **[pricing/](pricing/00-overview.md)** — markup across products: identifying local vs international
  flights and hotels, splitting the supplier's net rate from the price an agency pays, and the
  **cumulative** two-level strategy model (Main Office + Agency). **Nothing is built yet.** [`00-overview.md`](pricing/00-overview.md) is the investigation of what exists today;
  [`01-architecture.md`](pricing/01-architecture.md) is the domain model, schema, resolution
  algorithm and the open business questions.
