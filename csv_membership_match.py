#!/usr/bin/env python3
"""
CSV/Database Membership Matcher

Reads a CSV file containing email, first name, and last name, then queries a
MySQL database to find matching members by email address or by (first name AND
last name). Verifies active membership by checking that a datetime column is
greater than the current date/time. Outputs a new CSV file with the original
fields plus matched database fields (ID, End Date, Email).

Usage:
    python csv_membership_match.py input.csv output.csv

Requirements:
    pip install mysql-connector-python
"""

import argparse
import csv
import sys
from datetime import datetime

import mysql.connector

# ---------------------------------------------------------------------------
# Database configuration – edit these values or override via environment vars
# ---------------------------------------------------------------------------
DB_CONFIG = {
    "host": "localhost",
    "port": 3306,
    "user": "your_db_user",
    "password": "your_db_password",
    "database": "your_database",
}

# ---------------------------------------------------------------------------
# Table / column names – adjust to match your schema
# ---------------------------------------------------------------------------
TABLE_NAME = "members"
COL_ID = "id"
COL_EMAIL = "email"
COL_FIRST_NAME = "first_name"
COL_LAST_NAME = "last_name"
COL_END_DATE = "end_date"  # datetime column used to verify active membership


def get_env_db_config():
    """Override DB_CONFIG values with environment variables when present."""
    import os

    overrides = {
        "host": os.environ.get("DB_HOST"),
        "port": os.environ.get("DB_PORT"),
        "user": os.environ.get("DB_USER"),
        "password": os.environ.get("DB_PASSWORD"),
        "database": os.environ.get("DB_NAME"),
    }
    config = dict(DB_CONFIG)
    for key, val in overrides.items():
        if val is not None:
            config[key] = int(val) if key == "port" else val
    return config


def connect_db(config):
    """Return a MySQL connection using the provided config dict."""
    return mysql.connector.connect(**config)


def find_member(cursor, email, first_name, last_name):
    """
    Look up a member by email first; if no match, try (first_name AND last_name).
    Only return members whose end_date > NOW (active membership).

    Returns a dict with id, end_date, email from the database, or None.
    """
    now = datetime.now()

    # --- Attempt 1: match by email ---
    if email:
        query = (
            f"SELECT {COL_ID}, {COL_END_DATE}, {COL_EMAIL} "
            f"FROM {TABLE_NAME} "
            f"WHERE {COL_EMAIL} = %s AND {COL_END_DATE} > %s "
            f"ORDER BY {COL_END_DATE} DESC LIMIT 1"
        )
        cursor.execute(query, (email.strip(), now))
        row = cursor.fetchone()
        if row:
            return {"id": row[0], "end_date": row[1], "email": row[2]}

    # --- Attempt 2: match by first name + last name ---
    if first_name and last_name:
        query = (
            f"SELECT {COL_ID}, {COL_END_DATE}, {COL_EMAIL} "
            f"FROM {TABLE_NAME} "
            f"WHERE {COL_FIRST_NAME} = %s AND {COL_LAST_NAME} = %s "
            f"AND {COL_END_DATE} > %s "
            f"ORDER BY {COL_END_DATE} DESC LIMIT 1"
        )
        cursor.execute(query, (first_name.strip(), last_name.strip(), now))
        row = cursor.fetchone()
        if row:
            return {"id": row[0], "end_date": row[1], "email": row[2]}

    return None


def process(input_path, output_path, db_config):
    """Read the input CSV, match each row against the database, write output CSV."""
    conn = connect_db(db_config)
    cursor = conn.cursor()

    matched_count = 0
    total_count = 0

    with open(input_path, newline="", encoding="utf-8") as infile, \
         open(output_path, "w", newline="", encoding="utf-8") as outfile:

        reader = csv.DictReader(infile)

        # Normalise input header names to lowercase for flexible matching
        if reader.fieldnames is None:
            print("Error: input CSV appears to be empty.", file=sys.stderr)
            sys.exit(1)

        fieldname_map = {f.strip().lower(): f for f in reader.fieldnames}

        # Resolve actual header names from the input file
        email_col = fieldname_map.get("email")
        first_col = fieldname_map.get("first name") or fieldname_map.get("first_name") or fieldname_map.get("firstname")
        last_col = fieldname_map.get("last name") or fieldname_map.get("last_name") or fieldname_map.get("lastname")

        if not email_col and not (first_col and last_col):
            print(
                "Error: CSV must contain an 'email' column or both "
                "'first name' and 'last name' columns.",
                file=sys.stderr,
            )
            sys.exit(1)

        # Build output column list: original columns + database match columns
        out_fieldnames = list(reader.fieldnames) + [
            "DB_ID",
            "DB_End_Date",
            "DB_Email",
        ]
        writer = csv.DictWriter(outfile, fieldnames=out_fieldnames)
        writer.writeheader()

        for row in reader:
            total_count += 1
            email = row.get(email_col, "").strip() if email_col else ""
            first_name = row.get(first_col, "").strip() if first_col else ""
            last_name = row.get(last_col, "").strip() if last_col else ""

            match = find_member(cursor, email, first_name, last_name)

            out_row = dict(row)
            if match:
                matched_count += 1
                out_row["DB_ID"] = match["id"]
                out_row["DB_End_Date"] = (
                    match["end_date"].strftime("%Y-%m-%d %H:%M:%S")
                    if isinstance(match["end_date"], datetime)
                    else str(match["end_date"])
                )
                out_row["DB_Email"] = match["email"]
            else:
                out_row["DB_ID"] = ""
                out_row["DB_End_Date"] = ""
                out_row["DB_Email"] = ""

            writer.writerow(out_row)

    cursor.close()
    conn.close()

    print(f"Done. {matched_count}/{total_count} records matched an active member.")
    print(f"Output written to: {output_path}")


def main():
    parser = argparse.ArgumentParser(
        description="Match CSV records against a MySQL members database."
    )
    parser.add_argument("input_csv", help="Path to the input CSV file")
    parser.add_argument("output_csv", help="Path for the output CSV file")
    parser.add_argument("--host", help="MySQL host (or set DB_HOST env var)")
    parser.add_argument("--port", type=int, help="MySQL port (or set DB_PORT env var)")
    parser.add_argument("--user", help="MySQL user (or set DB_USER env var)")
    parser.add_argument("--password", help="MySQL password (or set DB_PASSWORD env var)")
    parser.add_argument("--database", help="MySQL database (or set DB_NAME env var)")
    args = parser.parse_args()

    db_config = get_env_db_config()

    # CLI args take highest priority
    if args.host:
        db_config["host"] = args.host
    if args.port:
        db_config["port"] = args.port
    if args.user:
        db_config["user"] = args.user
    if args.password:
        db_config["password"] = args.password
    if args.database:
        db_config["database"] = args.database

    process(args.input_csv, args.output_csv, db_config)


if __name__ == "__main__":
    main()
