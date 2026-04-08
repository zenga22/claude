# Claude Repository
A repository used for use with Claude Code while learning to use this AI resource.

Branches are created by Claude Code for each coding project.  This may not work for serious coding projects.

**To use this for multiple projects, do not merge branches with main.**

---

# Cross-Database Lookup Tool
## Prompt

Create a php application (v7.4) to access a MySQL database (db1) and select a subset of rows from  table1, then open another database (db2) and search table2 by key (email_address) from a column in table1.  If found in table2, update column (active) and display email_address, name, user_name.  If not found in table2, insert email_address, name, user_name into table2. Also Insert 2 rows in db2.table3 with ID from table2 insert (userID) and a groupID.  Also insert a row in db2.table4 with userID with additional SQL column values.  Have a test mode, that generates the SQL Insert statements and displays them, but does not execute.

---
**PR**: https://github.com/zenga22/claude/pull/1

`git checkout claude/php-cross-db-lookup-JxIHJ`
  
### Files created

| File | Purpose |
|---|---|
| `cross_db_lookup.php` | Main script — reads db1.table1, cross-references db2.table2, inserts/updates as needed |
| `config.php` | Database credentials, source query, group IDs, and table4 default values |
| `schema.sql` | Sample DDL and test data for both databases |

### Key features

- **Test mode** (`--test` flag): Generates and displays all INSERT/UPDATE SQL statements without executing anything
- **Found in table2**: Updates `active = 1`, displays `email_address`, `name`, `user_name`
- **Not found in table2**: Inserts into `table2`, then 2 rows into `table3` (userID + configurable groupIDs), then 1 row into `table4` (userID + configurable column defaults like `role`, `status`)
- Uses PDO prepared statements for security
- PHP 7.4 compatible
- Configurable via `config.php` (or override with `config.local.php`)

---
