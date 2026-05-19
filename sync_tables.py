#!/usr/bin/env python3
"""
sync_tables.py

Connects to a remote MySQL database on Server B via an SSH tunnel,
reads all rows from Table 1, and inserts or updates matching rows
in Table 2 on the local MySQL database (Server A).

Usage:
    python sync_tables.py [--config CONFIG] [--verbose] [--test]

Modes:
    Default : runs sync, prints summary counts only
    --verbose: prints detail of every insert/update plus summary
    --test   : prints SQL statements without executing any writes
"""

import argparse
import configparser
import logging
import sys
import time
from contextlib import contextmanager
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    sys.exit("ERROR: mysql-connector-python is not installed.\n"
             "  pip install mysql-connector-python")

try:
    from sshtunnel import SSHTunnelForwarder, BaseSSHTunnelForwarderError
except ImportError:
    sys.exit("ERROR: sshtunnel is not installed.\n"
             "  pip install sshtunnel")


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

DEFAULT_CONFIG_PATH = Path(__file__).with_name("sync_tables.ini")

DEFAULT_INI = """\
# sync_tables.ini  –  copy this file alongside sync_tables.py and edit.

[ssh]
host        = ssh.serverb.example.com   ; SSH host for Server B
port        = 22
username    = ssh_user
# Provide ONE of: key_file OR password
key_file    = ~/.ssh/id_rsa
# password  = secret

[remote_db]                             ; MySQL on Server B (reached via tunnel)
host        = 127.0.0.1                 ; always 127.0.0.1 through the tunnel
port        = 3306
database    = remote_db_name
user        = db_user
password    = db_password

[local_db]                              ; MySQL on Server A (local)
host        = 127.0.0.1
port        = 3306
database    = local_db_name
user        = db_user
password    = db_password

[tables]
source_table = table1                   ; on Server B
target_table = table2                   ; on Server A
primary_key  = id                       ; comma-separated if composite PK
"""


@dataclass
class SyncConfig:
    # SSH
    ssh_host: str
    ssh_port: int
    ssh_username: str
    ssh_key_file: str | None
    ssh_password: str | None

    # Remote DB (Server B)
    remote_host: str
    remote_port: int
    remote_db: str
    remote_user: str
    remote_password: str

    # Local DB (Server A)
    local_host: str
    local_port: int
    local_db: str
    local_user: str
    local_password: str

    # Table names & PK
    source_table: str
    target_table: str
    primary_keys: list[str] = field(default_factory=list)


def load_config(path: Path) -> SyncConfig:
    if not path.exists():
        print(f"Config file not found: {path}")
        print("Creating a sample config file …")
        path.write_text(DEFAULT_INI)
        sys.exit(f"Edit '{path}' and re-run.")

    cp = configparser.ConfigParser(inline_comment_prefixes=(";", "#"))
    cp.read(path)

    def get(section: str, key: str, fallback: Any = None) -> Any:
        return cp.get(section, key, fallback=fallback)

    def getint(section: str, key: str, fallback: int = 0) -> int:
        return cp.getint(section, key, fallback=fallback)

    pks = [k.strip() for k in get("tables", "primary_key", "id").split(",") if k.strip()]

    key_file = get("ssh", "key_file")
    if key_file:
        key_file = str(Path(key_file).expanduser())

    return SyncConfig(
        ssh_host=get("ssh", "host"),
        ssh_port=getint("ssh", "port", 22),
        ssh_username=get("ssh", "username"),
        ssh_key_file=key_file or None,
        ssh_password=get("ssh", "password", fallback=None),
        remote_host=get("remote_db", "host", "127.0.0.1"),
        remote_port=getint("remote_db", "port", 3306),
        remote_db=get("remote_db", "database"),
        remote_user=get("remote_db", "user"),
        remote_password=get("remote_db", "password"),
        local_host=get("local_db", "host", "127.0.0.1"),
        local_port=getint("local_db", "port", 3306),
        local_db=get("local_db", "database"),
        local_user=get("local_db", "user"),
        local_password=get("local_db", "password"),
        source_table=get("tables", "source_table", "table1"),
        target_table=get("tables", "target_table", "table2"),
        primary_keys=pks,
    )


# ---------------------------------------------------------------------------
# Logging helpers
# ---------------------------------------------------------------------------

def setup_logging(verbose: bool) -> logging.Logger:
    level = logging.DEBUG if verbose else logging.WARNING
    logging.basicConfig(
        format="%(asctime)s  %(levelname)-7s  %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
        level=level,
    )
    return logging.getLogger("sync_tables")


# ---------------------------------------------------------------------------
# SSH tunnel context manager
# ---------------------------------------------------------------------------

@contextmanager
def open_tunnel(cfg: SyncConfig):
    """Yields the local port bound by the SSH tunnel."""
    kwargs: dict[str, Any] = {
        "ssh_address_or_host": (cfg.ssh_host, cfg.ssh_port),
        "ssh_username": cfg.ssh_username,
        "remote_bind_address": (cfg.remote_host, cfg.remote_port),
    }
    if cfg.ssh_key_file:
        kwargs["ssh_pkey"] = cfg.ssh_key_file
    if cfg.ssh_password:
        kwargs["ssh_password"] = cfg.ssh_password

    print(f"Opening SSH tunnel  {cfg.ssh_username}@{cfg.ssh_host}:{cfg.ssh_port} "
          f"→ {cfg.remote_host}:{cfg.remote_port} …")

    try:
        with SSHTunnelForwarder(**kwargs) as tunnel:
            print(f"Tunnel open  (local port {tunnel.local_bind_port})")
            yield tunnel.local_bind_port
    except BaseSSHTunnelForwarderError as exc:
        sys.exit(f"SSH tunnel error: {exc}")


# ---------------------------------------------------------------------------
# Database helpers
# ---------------------------------------------------------------------------

def connect_remote(cfg: SyncConfig, local_port: int) -> mysql.connector.MySQLConnection:
    return mysql.connector.connect(
        host="127.0.0.1",
        port=local_port,
        database=cfg.remote_db,
        user=cfg.remote_user,
        password=cfg.remote_password,
        connection_timeout=30,
    )


def connect_local(cfg: SyncConfig) -> mysql.connector.MySQLConnection:
    return mysql.connector.connect(
        host=cfg.local_host,
        port=cfg.local_port,
        database=cfg.local_db,
        user=cfg.local_user,
        password=cfg.local_password,
        connection_timeout=30,
    )


def fetch_all_rows(conn, table: str) -> tuple[list[str], list[tuple]]:
    """Returns (column_names, rows) from *table*."""
    cursor = conn.cursor()
    cursor.execute(f"SELECT * FROM `{table}`")
    columns = [desc[0] for desc in cursor.description]
    rows = cursor.fetchall()
    cursor.close()
    return columns, rows


def build_upsert_sql(target_table: str, columns: list[str], primary_keys: list[str]) -> str:
    """
    Builds an INSERT … ON DUPLICATE KEY UPDATE statement.
    All columns are inserted; non-PK columns are updated on conflict.
    """
    col_list = ", ".join(f"`{c}`" % () if False else f"`{c}`" for c in columns)
    placeholders = ", ".join(["%s"] * len(columns))

    update_cols = [c for c in columns if c not in primary_keys]
    if not update_cols:
        # All columns are part of the PK — INSERT IGNORE is enough
        return (
            f"INSERT IGNORE INTO `{target_table}` ({col_list}) "
            f"VALUES ({placeholders})"
        )

    updates = ", ".join(f"`{c}` = VALUES(`{c}`)" for c in update_cols)
    return (
        f"INSERT INTO `{target_table}` ({col_list}) "
        f"VALUES ({placeholders}) "
        f"ON DUPLICATE KEY UPDATE {updates}"
    )


def row_exists(conn, table: str, pk_cols: list[str], pk_vals: tuple) -> bool:
    """Returns True when a row with the given PK already exists in *table*."""
    where = " AND ".join(f"`{c}` = %s" for c in pk_cols)
    cursor = conn.cursor()
    cursor.execute(f"SELECT 1 FROM `{table}` WHERE {where} LIMIT 1", pk_vals)
    found = cursor.fetchone() is not None
    cursor.close()
    return found


def format_row_sql(sql_template: str, values: tuple) -> str:
    """Render a parameterised SQL string with literal values (for display only)."""
    safe_vals = []
    for v in values:
        if v is None:
            safe_vals.append("NULL")
        elif isinstance(v, (int, float)):
            safe_vals.append(str(v))
        else:
            escaped = str(v).replace("'", "''")
            safe_vals.append(f"'{escaped}'")
    # Replace each %s in order
    result = sql_template
    for sv in safe_vals:
        result = result.replace("%s", sv, 1)
    return result


# ---------------------------------------------------------------------------
# Core sync logic
# ---------------------------------------------------------------------------

def sync(cfg: SyncConfig, verbose: bool, test_mode: bool, logger: logging.Logger) -> None:
    inserts = 0
    updates = 0
    skipped = 0

    with open_tunnel(cfg) as tunnel_port:
        print(f"Connecting to remote DB ({cfg.remote_db}) on Server B …")
        try:
            remote_conn = connect_remote(cfg, tunnel_port)
        except MySQLError as exc:
            sys.exit(f"Remote DB connection failed: {exc}")

        print(f"Connecting to local DB  ({cfg.local_db}) on Server A …")
        try:
            local_conn = connect_local(cfg)
        except MySQLError as exc:
            remote_conn.close()
            sys.exit(f"Local DB connection failed: {exc}")

        try:
            # ---- Read source data -------------------------------------------
            print(f"Reading rows from `{cfg.source_table}` …")
            columns, rows = fetch_all_rows(remote_conn, cfg.source_table)
            print(f"  {len(rows):,} row(s) fetched from `{cfg.source_table}`.")

            if not rows:
                print("Nothing to sync.")
                return

            # ---- Validate primary key columns --------------------------------
            for pk in cfg.primary_keys:
                if pk not in columns:
                    sys.exit(
                        f"Primary key column '{pk}' not found in "
                        f"`{cfg.source_table}` columns: {columns}"
                    )

            pk_indices = [columns.index(pk) for pk in cfg.primary_keys]
            upsert_sql = build_upsert_sql(cfg.target_table, columns, cfg.primary_keys)

            if test_mode:
                print("\n[TEST MODE]  No writes will be performed.\n")

            # ---- Process each row -------------------------------------------
            local_cursor = local_conn.cursor() if not test_mode else None

            for row in rows:
                pk_vals = tuple(row[i] for i in pk_indices)
                exists = (
                    False  # irrelevant in test mode; determined below otherwise
                    if test_mode
                    else row_exists(local_conn, cfg.target_table, cfg.primary_keys, pk_vals)
                )
                action = "UPDATE" if exists else "INSERT"

                if test_mode:
                    rendered = format_row_sql(upsert_sql, row)
                    print(f"[TEST {action}]  {rendered}")
                    # For counting purposes in test mode assume insert
                    inserts += 1
                else:
                    try:
                        local_cursor.execute(upsert_sql, row)
                        affected = local_cursor.rowcount
                        # rowcount == 1 → insert, 2 → update, 0 → no-op
                        if affected == 2:
                            updates += 1
                            if verbose:
                                rendered = format_row_sql(upsert_sql, row)
                                logger.debug("UPDATE  pk=%s  |  %s", pk_vals, rendered)
                        elif affected == 1:
                            inserts += 1
                            if verbose:
                                rendered = format_row_sql(upsert_sql, row)
                                logger.debug("INSERT  pk=%s  |  %s", pk_vals, rendered)
                        else:
                            skipped += 1
                            if verbose:
                                logger.debug("SKIP    pk=%s  (no change)", pk_vals)
                    except MySQLError as exc:
                        logger.error("Row pk=%s failed: %s", pk_vals, exc)
                        skipped += 1

            if not test_mode:
                local_conn.commit()
                if local_cursor:
                    local_cursor.close()

        finally:
            remote_conn.close()
            local_conn.close()

    # ---- Summary ------------------------------------------------------------
    separator = "-" * 48
    print(f"\n{separator}")
    if test_mode:
        print(f"  TEST MODE — no rows written")
        print(f"  Rows inspected : {inserts:>8,}")
    else:
        print(f"  Rows inserted  : {inserts:>8,}")
        print(f"  Rows updated   : {updates:>8,}")
        print(f"  Rows skipped   : {skipped:>8,}")
        print(f"  Total processed: {inserts + updates + skipped:>8,}")
    print(separator)


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Sync all rows from Table 1 on Server B (via SSH tunnel) "
            "into Table 2 on Server A."
        )
    )
    parser.add_argument(
        "--config", "-c",
        default=str(DEFAULT_CONFIG_PATH),
        help=f"Path to INI config file (default: {DEFAULT_CONFIG_PATH})",
    )
    parser.add_argument(
        "--verbose", "-v",
        action="store_true",
        help="Print detail of every insert/update.",
    )
    parser.add_argument(
        "--test", "-t",
        action="store_true",
        help="Display SQL statements without executing any writes.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    logger = setup_logging(args.verbose)

    cfg_path = Path(args.config).expanduser()
    cfg = load_config(cfg_path)

    if args.test:
        print("=== TEST MODE ===  (read-only — no data will be changed)\n")
    if args.verbose:
        print("=== VERBOSE MODE ===\n")

    start = time.monotonic()
    sync(cfg, verbose=args.verbose, test_mode=args.test, logger=logger)
    elapsed = time.monotonic() - start
    print(f"  Elapsed        : {elapsed:>7.2f}s")
    print()


if __name__ == "__main__":
    main()
