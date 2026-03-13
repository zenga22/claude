# JSON Files List/Display
### Files
| File | Purpose |
|---|---|
| `config.php` | `DB_PATH` and `JSON_DIR` constants |
| `db.php` | PDO SQLite helpers — `sync_json_files()`, `get_all_files()`, `get_file_record()` |
| `sync.php` | HTTP endpoint: scans `data/`, inserts new files, returns JSON |
| `index.php` | File list page |
| `detail.php` | Full field detail page |
| `data/*.json` | 5 sample submissions |
| `database/.htaccess` | Blocks direct HTTP access to the SQLite DB |

---

### How it works

**Auto-sync** — `index.php` calls `sync_json_files()` on every page load. It reads all `*.json` files from `data/`, checks which filenames are already in the DB, and inserts only the new ones. This is a single `SELECT` per file (unique constraint), so it stays fast even with many files.

**SQLite schema** — stores `filename`, `date_submitted`, `name`, `email`, and `synced_at`. The `filename` column has a `UNIQUE` constraint so duplicates are never inserted.

**File list page (`index.php`)**
- Stats row: total DB records, files on disk, already-indexed count
- Bootstrap table with date, name, email, filename, and a **View Details** button per row
- **Sync Now** button does an AJAX call to `sync.php` and reloads if new files were found

**Detail page (`detail.php`)**
- "Indexed Fields" summary (the 3 cached columns)
- "All JSON Fields" table — every key/value in the file, with nested objects and arrays rendered recursively as nested `list-group` elements
- Collapsible **Raw JSON** block (syntax-highlighted with dark background)
- Works gracefully if the JSON file is missing from disk (shows DB-cached fields only)
