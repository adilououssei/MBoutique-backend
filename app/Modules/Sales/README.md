# Sales module

Sale, SaleItem (Product-only, snapshot pricing). Checkout orchestrates Inventory and CashRegister through SaleService, in one atomic transaction. No cart persisted server-side, no cancellation/refund yet — see docs/sales.md.

See `docs/modules.md` for the standard internal folder anatomy (Http/Controllers, Http/Requests, Http/Resources, Models, Policies, Providers, Routes, Database/Migrations, Database/Factories, Tests) applied to every module, and `docs/database.md` for this module's entities.
