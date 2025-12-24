# MLP Migration Tool (PoC)

Proof‑of‑concept WordPress plugin to migrate an existing single‑site multilingual setup to a Multisite network powered by MultilingualPress (MLP).

At the moment this is intended for **internal / agency use**, not as a polished end‑user product.

## What it does

- Creates one **subsite per language** (except the default language, which stays on the main site).
- Assigns the correct **WordPress site language** (`WPLANG`) and **MLP site language** for each site.
- Relates all sites in a single **MLP site group**.
- For each language and for post types `post` and `page`:
  - Copies content from the original site to the corresponding language site.
  - Re‑creates **translation relationships** in MultilingualPress using the source plugin’s translation sets.

## Supported source plugins

- **Polylang**
  - Uses `pll_the_languages()`, `pll_default_language()`, `pll_get_post_translations()`, and the `lang` query var.
- **WPML** (experimental)
  - Uses the `icl_translations` table to discover translation groups (`trid`) and their posts.
  - For real translation sets (posts available in multiple languages), copies each language’s post to its site and links them in MultilingualPress.
  - For single-language content (no translations in WPML), duplicates the post to all language sites but does **not** create MultilingualPress relationships between those copies.

## Requirements

- WordPress **Multisite**.
- MultilingualPress (MLP) active and working.
- Exactly **one** of:
  - Polylang, or
  - WPML
- PHP 8+ (to match MLP’s requirements).

## How to use (high‑level)

1. Make a **full backup** of the database and files.
2. Ensure you are running a **Multisite network** with MLP active.
3. Activate this plugin on the network.
4. Make sure exactly one source plugin (Polylang or WPML) is active.
5. Go to **Network Admin → MLP Migration**.
6. Click **“Run PoC Migration”**.
7. Watch the notices at the top of the page for progress and errors.

## Important limitations

- Only migrates **posts** and **pages**; no custom post types, taxonomies, menus, or widgets yet.
- Designed as a **one‑way, one‑time** migration helper; it is not idempotent or reversible.
- Assumes the original site is the **main site** of the network and contains all source content.
- Very minimal UI: a single button to run the migration and a log of actions; advanced options might require code changes.

## Safety notes

- Always test on a **staging copy** of the project before running on production.
- Expect to do manual cleanup and checks after migration (menus, widgets, theme‑specific content, etc.).
- If you change the migration logic, document the changes here so other team members understand the behavior. 
