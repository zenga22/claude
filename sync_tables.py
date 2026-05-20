# -*- coding: utf-8 -*-
#!/usr/bin/env python3
"""
sync_tables.py

Connects to a remote MySQL database on Server B via an SSH tunnel,
reads all rows from Table 1, and inserts or updates matching rows
in Table 2 on the local MySQL database (Server A).

Usage:
    python sync_tables.py [--config CONFIG] [--verbose] [--test]

Modes:
    Default  : runs sync, prints summary counts only
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
from typing import Any, Dict, List, Optional, Tuple

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    sys.exit(
        "ERROR: mysql-connector-python is not installed.\n"
        "  pip install mysql-connector-python"
    )

try:
    from sshtunnel import SSHTunnelForwarder, BaseSSHTunnelForwarderError
except ImportError:
    sys.exit(
        "ERROR: sshtunnel is not installed.\n"
        "  pip install sshtunnel"
    )


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

DEFAULT_CONFIG_PATH = Path(__file__).with_name("sync_tables.ini")

DEFAULT_INI = """\
# sync_tables.ini  -  copy this file alongside sync_tables.py and edit.

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
    ssh_key_file: Optional[str]
    ssh_password: Optional[str]

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
    primary_keys: List[str] = field(default_factory=list)


def load_config(path):
    # type: (Path) -> SyncConfig
    if not path.exists():
        print("Config file not found: {}".format(path))
        print("Creating a sample config file ...")
        path.write_text(DEFAULT_INI)
        sys.exit("Edit '{}' and re-run.".format(path))

    cp = configparser.ConfigParser(inline_comment_prefixes=(";", "#"))
    cp.read(str(path))

    def get(section, key, fallback=None):
        return cp.get(section, key, fallback=fallback)

    def getint(section, key, fallback=0):
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

def setup_logging(verbose):
    # type: (bool) -> logging.Logger
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
def open_tunnel(cfg):
    # type: (SyncConfig) -> Any
    """Opens an SSH tunnel and yields the local port number."""
    kwargs = {
        "ssh_address_or_host": (cfg.ssh_host, cfg.ssh_port),
        "ssh_username": cfg.ssh_username,
        "remote_bind_address": (cfg.remote_host, cfg.remote_port),
        # Allow SSH agent and ~/.ssh key discovery as automatic fallbacks
        "allow_agent": True,
        "host_pkey_directories": [str(Path("~/.ssh").expanduser())],
    }  # type: Dict[str, Any]
    if cfg.ssh_key_file:
        kwargs["ssh_pkey"] = cfg.ssh_key_file
    if cfg.ssh_password:
        kwargs["ssh_password"] = cfg.ssh_password

    auth_desc = (
        "key {}".format(cfg.ssh_key_file) if cfg.ssh_key_file
        else "password" if cfg.ssh_password
        else "SSH agent / ~/.ssh keys"
    )
    print(
        "Opening SSH tunnel  {}@{}:{} -> {}:{} (auth: {}) ...".format(
            cfg.ssh_username, cfg.ssh_host, cfg.ssh_port,
            cfg.remote_host, cfg.remote_port, auth_desc,
        )
    )

    try:
        with SSHTunnelForwarder(**kwargs) as tunnel:
            print("Tunnel open  (local port {})".format(tunnel.local_bind_port))
            yield tunnel.local_bind_port
    except BaseSSHTunnelForwarderError as exc:
        sys.exit("SSH tunnel error: {}".format(exc))
    except ValueError as exc:
        sys.exit(
            "SSH auth error: {}\n"
            "Set key_file or password in [ssh] section of the config, "
            "or ensure your SSH agent has keys loaded (ssh-add).".format(exc)
        )


# ---------------------------------------------------------------------------
# Database helpers
# ---------------------------------------------------------------------------

def connect_remote(cfg, local_port):
    # type: (SyncConfig, int) -> Any
    return mysql.connector.connect(
        host="127.0.0.1",
        port=local_port,
        database=cfg.remote_db,
        user=cfg.remote_user,
        password=cfg.remote_password,
        connection_timeout=30,
    )


def connect_local(cfg):
    # type: (SyncConfig) -> Any
    return mysql.connector.connect(
        host=cfg.local_host,
        port=cfg.local_port,
        database=cfg.local_db,
        user=cfg.local_user,
        password=cfg.local_password,
        connection_timeout=30,
    )


def fetch_all_rows(conn, table):
    # type: (Any, str) -> Tuple[List[str], List[tuple]]
    """Returns (column_names, rows) from *table*."""
    cursor = conn.cursor()
    cursor.execute("SELECT * FROM `{}`".format(table))
    columns = [desc[0] for desc in cursor.description]
    rows = cursor.fetchall()
    cursor.close()
    return columns, rows


def build_upsert_sql(target_table, columns, primary_keys):
    # type: (str, List[str], List[str]) -> str
    """Builds an INSERT ... ON DUPLICATE KEY UPDATE statement."""
    col_list = ", ".join("`{}`".format(c) for c in columns)
    placeholders = ", ".join(["%s"] * len(columns))

    update_cols = [c for c in columns if c not in primary_keys]
    if not update_cols:
        # All columns are part of the PK - INSERT IGNORE is sufficient
        return (
            "INSERT IGNORE INTO `{}` ({}) VALUES ({})".format(
                target_table, col_list, placeholders
            )
        )

    updates = ", ".join("`{c}` = VALUES(`{c}`)".format(c=c) for c in update_cols)
    return (
        "INSERT INTO `{}` ({}) VALUES ({}) ON DUPLICATE KEY UPDATE {}".format(
            target_table, col_list, placeholders, updates
        )
    )


def row_exists(conn, table, pk_cols, pk_vals):
    # type: (Any, str, List[str], tuple) -> bool
    """Returns True when a row with the given PK already exists in *table*."""
    where = " AND ".join("`{}` = %s".format(c) for c in pk_cols)
    cursor = conn.cursor()
    cursor.execute("SELECT 1 FROM `{}` WHERE {} LIMIT 1".format(table, where), pk_vals)
    found = cursor.fetchone() is not None
    cursor.close()
    return found


def format_row_sql(sql_template, values):
    # type: (str, tuple) -> str
    """Render a parameterised SQL string with literal values (for display only)."""
    safe_vals = []
    for v in values:
        if v is None:
            safe_vals.append("NULL")
        elif isinstance(v, (int, float)):
            safe_vals.append(str(v))
        else:
            escaped = str(v).replace("'", "''")
            safe_vals.append("'{}'".format(escaped))
    result = sql_template
    for sv in safe_vals:
        result = result.replace("%s", sv, 1)
    return result


# ---------------------------------------------------------------------------
# Core sync logic
# ---------------------------------------------------------------------------

def sync(cfg, verbose, test_mode, logger):
    # type: (SyncConfig, bool, bool, logging.Logger) -> None
    inserts = 0
    updates = 0
    skipped = 0

    with open_tunnel(cfg) as tunnel_port:
        print("Connecting to remote DB ({}) on Server B ...".format(cfg.remote_db))
        try:
            remote_conn = connect_remote(cfg, tunnel_port)
        except MySQLError as exc:
            sys.exit("Remote DB connection failed: {}".format(exc))

        print("Connecting to local DB  ({}) on Server A ...".format(cfg.local_db))
        try:
            local_conn = connect_local(cfg)
        except MySQLError as exc:
            remote_conn.close()
            sys.exit("Local DB connection failed: {}".format(exc))

        try:
            # ---- Read source data -------------------------------------------
            print("Reading rows from `{}` ...".format(cfg.source_table))
            columns, rows = fetch_all_rows(remote_conn, cfg.source_table)
            print("  {:,} row(s) fetched from `{}`.".format(len(rows), cfg.source_table))

            if not rows:
                print("Nothing to sync.")
                return

            # ---- Validate primary key columns --------------------------------
            for pk in cfg.primary_keys:
                if pk not in columns:
                    sys.exit(
                        "Primary key column '{}' not found in "
                        "`{}` columns: {}".format(pk, cfg.source_table, columns)
                    )

            pk_indices = [columns.index(pk) for pk in cfg.primary_keys]
            upsert_sql = build_upsert_sql(cfg.target_table, columns, cfg.primary_keys)

            if test_mode:
                print("\n[TEST MODE]  No writes will be performed.\n")

            # ---- Process each row -------------------------------------------
            local_cursor = local_conn.cursor() if not test_mode else None

            for row in rows:
                pk_vals = tuple(row[i] for i in pk_indices)
                if test_mode:
                    rendered = format_row_sql(upsert_sql, row)
                    print("[TEST]  {}".format(rendered))
                    inserts += 1
                else:
                    exists = row_exists(
                        local_conn, cfg.target_table, cfg.primary_keys, pk_vals
                    )
                    action = "UPDATE" if exists else "INSERT"
                    try:
                        local_cursor.execute(upsert_sql, row)
                        affected = local_cursor.rowcount
                        # rowcount == 1 -> insert, 2 -> update, 0 -> no-op
                        if affected == 2:
                            updates += 1
                            if verbose:
                                rendered = format_row_sql(upsert_sql, row)
                                logger.debug(
                                    "UPDATE  pk=%s  |  %s", pk_vals, rendered
                                )
                        elif affected == 1:
                            inserts += 1
                            if verbose:
                                rendered = format_row_sql(upsert_sql, row)
                                logger.debug(
                                    "INSERT  pk=%s  |  %s", pk_vals, rendered
                                )
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
    print("\n" + separator)
    if test_mode:
        print("  TEST MODE - no rows written")
        print("  Rows inspected : {:>8,}".format(inserts))
    else:
        print("  Rows inserted  : {:>8,}".format(inserts))
        print("  Rows updated   : {:>8,}".format(updates))
        print("  Rows skipped   : {:>8,}".format(skipped))
        print("  Total processed: {:>8,}".format(inserts + updates + skipped))
    print(separator)


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def parse_args():
    parser = argparse.ArgumentParser(
        description=(
            "Sync all rows from Table 1 on Server B (via SSH tunnel) "
            "into Table 2 on Server A."
        )
    )
    parser.add_argument(
        "--config", "-c",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to INI config file (default: {})".format(DEFAULT_CONFIG_PATH),
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


def main():
    args = parse_args()
    logger = setup_logging(args.verbose)

    cfg_path = Path(args.config).expanduser()
    cfg = load_config(cfg_path)

    if args.test:
        print("=== TEST MODE ===  (read-only - no data will be changed)\n")
    if args.verbose:
        print("=== VERBOSE MODE ===\n")

    start = time.time()
    sync(cfg, verbose=args.verbose, test_mode=args.test, logger=logger)
    elapsed = time.time() - start
    print("  Elapsed        : {:>7.2f}s".format(elapsed))
    print()


if __name__ == "__main__":
    main()
