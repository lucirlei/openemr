# Aesthetic Inventory Setup Guide

This guide explains how to configure the new aesthetic inventory tooling that
extends the drug catalog, inventory lots, and automated procedure consumption.

## 1. Database fields and migrations

The upgrade to database version 518 added several fields to both `drugs` and
`drug_inventory` that capture aesthetic categories, supplier information, cost
tracking, and photo metadata. A dedicated `procedure_products` table now maps
billing codes (for example CPT4 or HCPCS) to the specific inventory items that
should be consumed whenever those procedures are billed. A background service
named **Aesthetic Inventory Alerts** scans for low stock levels and lots that
are approaching expiration and pushes notifications through the CRM channel.

Ensure that your instance is running the 7.0.5 (or newer) schema so the new
columns and service registration are available. Administrators can confirm that
`AestheticInventoryAlerts` is active under *Administration → System → Background
Services*.

## 2. Seeding demonstration data

To try the workflow quickly, load the optional sample dataset that seeds
illustrative products, inventory lots, CPT4 procedure codes, and
procedure-to-product mappings:

```bash
mysql -u root -p openemr < sql/aesthetic_inventory_sample_data.sql
```

The script is idempotent and creates:

- Three aesthetic consumables (Botox vial, dermal filler syringe, and a chemical
  peel kit) with cost, supplier, and photo metadata populated in both the
  product and lot records.
- Matching inventory lots so that the Fee Sheet and dispensing logic can be
  exercised immediately.
- Three CPT4 procedure codes paired with entries in `procedure_products` that
  automatically reserve the correct inventory quantities during Fee Sheet save.

After loading the sample data you can inspect or refine the mappings in the
`procedure_products` table directly or via custom tooling.

## 3. Operational checklist

1. **Populate mappings for production use.** Insert rows into
   `procedure_products` that mirror your real aesthetic packages. Each row
   should specify the billing code, its code type, the linked product (`drug_id`)
   and the quantity to decrement per unit of service.
2. **Verify Fee Sheet behaviour.** Post a visit with procedures that reference
   your mappings and confirm that the expected inventory deductions (with
   `AUTO_PROC:*` selectors) appear in *Reports → Inventory → Activity*.
3. **Validate alerting.** Review `library/aesthetic_inventory_alerts.php` and the
   CRM/notification configuration so low-stock and expiring lots are escalated
   to the appropriate team.
4. **Update clinical content.** If you leverage layout based forms or package
   sessions, ensure they reference the correct procedure codes so the automatic
   consumption logic is triggered.

These steps will help align the new infrastructure with your aesthetic service
lines and keep consumable inventory accurate as Fee Sheet sessions are posted.
