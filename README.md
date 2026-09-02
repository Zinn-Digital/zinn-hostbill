# HostBill module — Zinn Digital® hosting

A hosting module so a HostBill shop can sell Zinn® hosting.

It does exactly what the [WHMCS module](../whmcs/) does, through exactly the same endpoints:
create, suspend, unsuspend, terminate, single sign-on and a real connection test.

## Install

1. Copy both files into `includes/modules/Hosting/zinn/` in your HostBill root, so they land
   at `includes/modules/Hosting/zinn/class.zinn.php` and
   `includes/modules/Hosting/zinn/ZinnHostBillClient.php`.
2. In HostBill, go to **Settings → Modules → Hosting**, activate **Zinn Digital**, and add a
   connection:
   - **API address**: `https://api.zinndigital.com`
   - **API key**: your Zinn® API key (stored encrypted — it is a `password` field)
3. On the product, set:
   - **Product line** — what you are selling, e.g. `mainstream`. `GET /v1/reseller/lines`
     lists yours.
   - **Stack** — what gets built. Defaults to `wordpress`. ⛔ **Required by the platform**:
     an order that names no stack is refused, which is what this module did on every order
     until 2026-08-31 (D17360).
   - **Application** (optional) — only for the `one_click` and `headless_cms` stacks.
   - **PHP version** (optional) — e.g. `8.3`; empty takes the line's default.
4. Press **Test connection**. It makes a real call and reports what the platform said.
   ⭐ **It cannot tell you whether an order will succeed** — it reads your programme and
   provisions nothing. In particular it passes happily when your reseller plan has no site
   allowance, and the first order is then refused with _"This organization has no hosting
   plan"_.

## The API key

The same **eleven** permissions the WHMCS module needs — see
[`../README.md`](../README.md#the-api-key). ⛔ `org.create` is one of them and this page said
"six" until 2026-08-31: without it, Test connection passes and the first order answers `403`
(D17361). ⛔ Do not grant `reseller.manage` to an integration; `reseller.provision` is the key
that exists so you do not have to.

## Licence

GPL-2.0-or-later. Author: Neil Lock — CEO, Zinn Digital® Ltd — <https://zinndigital.com>
