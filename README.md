# Magenx_AutoProductLinks

Fills Magento's native **Related Products**, **Up-Sells** and **Cross-Sells** from
merchant-defined rules, on a cron.

Links are written straight into `catalog_product_link`, so the storefront needs no
change at all — the existing product-page rails simply stop being empty. On this
project's headless storefront that also means **no GraphQL document changes and no
persisted-query allowlist changes**, and therefore none of the hash-drift risk that
comes with them.

Cross-sells can be driven from mined order history ("customers who bought this also
bought"), which is what finally puts real data behind the storefront's *Frequently
Bought Together* rail — that rail renders Magento's native cross-sells, which until
now nothing populated.

## The guarantee

**Links you add by hand are never moved, overwritten or deleted.**

Every link the module writes gets a row in `magenx_auto_link_ledger`, and only links
with a ledger row are ever touched. A hand-picked link never gets one — including the
case where a rule independently computes a product you had already linked yourself,
which is subtracted out before anything is claimed. Manual links also keep their
positions (auto links are numbered from 1000 up, so yours sort first) and do not count
against a rule's link limit.

Every run logs `manual_links_skipped`, the number of times the module declined to claim
someone else's link. That is the standing audit trail for this promise.

## What a rule is

| Part | Meaning |
|---|---|
| **Fills** | Related / Up-Sells / Cross-Sells |
| **Apply this rule to these products** | The *source* condition tree — which products get links |
| **Link to these products** | The *target* condition tree — the candidate pool |
| **Choose products by** | `Similar products` or `Bought together` |
| **Must match the source product on** | Attributes a candidate must share with the source (colour, size, manufacturer…), plus *shares a category* and *price band* |
| **Price band (%)** | Half-width of the band, e.g. 20 = within ±20% of the source's price |
| **Maximum links per product** | Cap on auto links (manual links are extra) |
| **Show best** | How the survivors are ranked before the cap |
| **Priority** | Lower runs first and wins a contested slot |

Both condition trees are the stock Magento rule widget — the same attribute picker as a
Catalog Price Rule, with any/all and is / is-not / greater-than.

Nothing is hardcoded per link type: a rule declares what it fills, so *Bought together*
can drive Related Products just as well as Cross-Sells.

### Example rules

- **Related** — Choose by *Similar products*, match on `colour` + *shares a category*,
  show best sellers first.
- **Up-Sells** — Choose by *Similar products*, match on *shares a category* + *price band*,
  price band `60`, target tree `price greater than {…}`, show most expensive first.
- **Cross-Sells / Frequently Bought Together** — Choose by *Bought together*, target tree
  narrowed to an Accessories category, show strongest match first.

## Configuration

**Stores → Configuration → Magenx → Auto Product Links** (`magenx_auto_product_links/…`)

| Path | Default | Notes |
|---|---|---|
| `general/enabled` | `0` | Off by default — this writes to a core catalog table |
| `general/dry_run` | `1` | On by default: the first run reports instead of writing |
| `general/invalidate_cache` | `1` | Broadcast `cat_p_<id>` after a run |
| `general/max_links_default` | `8` | |
| `general/auto_position_base` | `1000` | Auto links start here so manual links sort first |
| `limits/max_source_products_per_run` | `5000` | Per rule; a bigger catalog converges over several nights |
| `limits/target_pool_cap` | `5000` | A rule whose target tree matches more is skipped with a warning |
| `limits/batch_size` | `100` | Source products per transaction |
| `copurchase/lookback_months` | `6` | |
| `copurchase/min_support` | `3` | Orders a pair must appear in |
| `copurchase/max_orders_per_run` | `100000` | |
| `copurchase/max_items_per_order` | `50` | Very large orders are skipped — see below |
| `cron/copurchase_schedule` | `0 3 * * *` | |
| `cron/rules_schedule` | `30 3 * * *` | **Must run after** the mining job |

Rules live at **Marketing → Promotions → Auto Product Link Rules**
(ACL `Magenx_AutoProductLinks::rules`).

Runs are logged to `var/log/magenx_auto_product_links.log`.

## Deploy

```bash
bin/magento module:enable Magenx_AutoProductLinks
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode
```

Then enable the section, leave **Dry Run** on, create a rule, press **Run Now**, and read
the log before switching Dry Run off.

**Order matters:** the mining job must run before the rule job, and the rule job should
run after the catalog reindex so it works from current prices.

## Caveats

- **Product links are global.** `catalog_product_link` has no store, website or
  customer-group column, so a rule's *store view* setting only decides which store's
  attribute values, prices and orders are read — not who sees the resulting links. There
  is no way to give two store views different auto links through native links.
  Customer-group scoping is not offered for the same reason: it would silently write
  links every group sees.
- **Ranking may not reach the storefront.** The order is written as the link `position`,
  but Magento's stock GraphQL link resolvers do not guarantee position ordering in every
  2.4.x patch. Verify against your own backend before relying on *Show best*.
- **New links can take hours to appear** unless the purge chain is configured: Full Page
  Cache in Varnish mode, a populated `http_cache_hosts`, and an edge that forwards
  `PURGE` to the storefront's `/api/revalidate`. Without it the storefront serves its own
  cached rails until they expire.
- **Very large orders are excluded from mining.** The number of pairs an order
  contributes grows with the square of its line count, so one 200-line order would
  contribute ~39,800 pairs and drown ordinary baskets.
- **Condition trees are pushed down to SQL**, which is what makes a nightly whole-catalog
  pass viable. A condition on an attribute the SQL builder cannot map is skipped: inside
  an *all/AND* group that is harmless, but inside an *any/OR* group it can quietly match
  fewer products than expected.
- **Co-purchase counts warm up.** A store with no order history produces no pairs, and
  the aggregate fills month by month.
- **Links are counted at parent level.** A configurable's parent product is what gets
  linked, since a link on an invisible variant renders nothing on the storefront.

## Tables

| Table | Purpose |
|---|---|
| `magenx_auto_link_rule` | The rules, including both serialized condition trees |
| `magenx_auto_link_ledger` | Every link the module authored — the manual-link guarantee |
| `magenx_auto_link_copurchase` | Mined co-purchase pair counts, bucketed by month |

Run cursors are kept in Magento's own `flag` table, not a table of ours.

## Extending

Add a way of choosing link targets by implementing
`Magenx\AutoProductLinks\Api\TargetStrategyInterface` and contributing it to the
`strategies` argument of `Magenx\AutoProductLinks\Model\Strategy\StrategyPool` in
`di.xml`. It then appears in the rule form and runs under every existing guard — link
caps, ordering, the ledger diff, dry run and cache invalidation — without touching the
runner.
