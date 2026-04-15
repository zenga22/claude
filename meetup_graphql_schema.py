#!/usr/bin/env python3
"""
Meetup.com GraphQL Schema Fetcher
Authenticates via OAuth2 and uses GraphQL introspection to retrieve and save the schema.
"""

import json
import os
import secrets
import sys
import threading
import webbrowser
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import parse_qs, urlencode, urlparse

import requests

# --- Configuration ---
MEETUP_AUTH_URL = "https://secure.meetup.com/oauth2/authorize"
MEETUP_TOKEN_URL = "https://secure.meetup.com/oauth2/access"
MEETUP_GRAPHQL_URL = "https://api.meetup.com/gql"
REDIRECT_URI = "http://localhost:8080/callback"
SCHEMA_OUTPUT_FILE = "meetup_schema.json"

# Full introspection query (based on the GraphQL spec)
INTROSPECTION_QUERY = """
query IntrospectionQuery {
  __schema {
    queryType { name }
    mutationType { name }
    subscriptionType { name }
    types {
      ...FullType
    }
    directives {
      name
      description
      locations
      args {
        ...InputValue
      }
    }
  }
}

fragment FullType on __Type {
  kind
  name
  description
  fields(includeDeprecated: true) {
    name
    description
    args {
      ...InputValue
    }
    type {
      ...TypeRef
    }
    isDeprecated
    deprecationReason
  }
  inputFields {
    ...InputValue
  }
  interfaces {
    ...TypeRef
  }
  enumValues(includeDeprecated: true) {
    name
    description
    isDeprecated
    deprecationReason
  }
  possibleTypes {
    ...TypeRef
  }
}

fragment InputValue on __InputValue {
  name
  description
  type { ...TypeRef }
  defaultValue
}

fragment TypeRef on __Type {
  kind
  name
  ofType {
    kind
    name
    ofType {
      kind
      name
      ofType {
        kind
        name
        ofType {
          kind
          name
          ofType {
            kind
            name
            ofType {
              kind
              name
              ofType {
                kind
                name
              }
            }
          }
        }
      }
    }
  }
}
"""


class OAuthCallbackHandler(BaseHTTPRequestHandler):
    """Handles the OAuth2 redirect callback."""

    auth_code = None
    error = None
    state_received = None

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path != "/callback":
            self.send_response(404)
            self.end_headers()
            return

        params = parse_qs(parsed.query)

        if "error" in params:
            OAuthCallbackHandler.error = params["error"][0]
            self._send_html(
                "<h2>Authorization failed</h2>"
                f"<p>Error: {OAuthCallbackHandler.error}</p>"
                "<p>You can close this tab.</p>"
            )
        elif "code" in params:
            OAuthCallbackHandler.auth_code = params["code"][0]
            OAuthCallbackHandler.state_received = params.get("state", [None])[0]
            self._send_html(
                "<h2>Authorization successful!</h2>"
                "<p>You can close this tab and return to the terminal.</p>"
            )
        else:
            self._send_html("<h2>Unexpected response</h2><p>No code or error received.</p>")

        # Signal the server to stop after responding
        threading.Thread(target=self.server.shutdown, daemon=True).start()

    def _send_html(self, body: str):
        html = f"<!DOCTYPE html><html><body>{body}</body></html>"
        encoded = html.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def log_message(self, format, *args):  # noqa: A002
        pass  # Suppress default request logging


def run_oauth_flow(client_id: str, client_secret: str) -> str:
    """
    Runs the OAuth2 Authorization Code flow.
    Opens a browser, waits for the redirect, and returns the access token.
    """
    state = secrets.token_urlsafe(16)

    params = {
        "client_id": client_id,
        "response_type": "code",
        "redirect_uri": REDIRECT_URI,
        "scope": "basic",
        "state": state,
    }
    auth_url = f"{MEETUP_AUTH_URL}?{urlencode(params)}"

    print(f"\nOpening browser for Meetup OAuth2 authorization...")
    print(f"If the browser does not open, visit:\n  {auth_url}\n")
    webbrowser.open(auth_url)

    # Start local callback server
    server = HTTPServer(("localhost", 8080), OAuthCallbackHandler)
    print("Waiting for OAuth2 callback on http://localhost:8080/callback ...")
    server.serve_forever()  # Blocks until shutdown() is called in the handler

    if OAuthCallbackHandler.error:
        raise RuntimeError(f"OAuth2 authorization error: {OAuthCallbackHandler.error}")

    if not OAuthCallbackHandler.auth_code:
        raise RuntimeError("No authorization code received.")

    if OAuthCallbackHandler.state_received != state:
        raise RuntimeError(
            f"State mismatch! Expected '{state}', got '{OAuthCallbackHandler.state_received}'. "
            "Possible CSRF attack."
        )

    print("Authorization code received. Exchanging for access token...")

    # Exchange authorization code for access token
    response = requests.post(
        MEETUP_TOKEN_URL,
        data={
            "client_id": client_id,
            "client_secret": client_secret,
            "grant_type": "authorization_code",
            "redirect_uri": REDIRECT_URI,
            "code": OAuthCallbackHandler.auth_code,
        },
        headers={"Accept": "application/json"},
        timeout=30,
    )
    response.raise_for_status()

    token_data = response.json()
    access_token = token_data.get("access_token")
    if not access_token:
        raise RuntimeError(f"No access_token in response: {token_data}")

    print("Access token obtained successfully.\n")
    return access_token


def fetch_graphql_schema(access_token: str) -> dict:
    """Runs a GraphQL introspection query and returns the result."""
    print(f"Sending introspection query to {MEETUP_GRAPHQL_URL} ...")

    response = requests.post(
        MEETUP_GRAPHQL_URL,
        json={"query": INTROSPECTION_QUERY},
        headers={
            "Authorization": f"Bearer {access_token}",
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
        timeout=60,
    )
    response.raise_for_status()

    result = response.json()

    if "errors" in result:
        raise RuntimeError(f"GraphQL errors: {json.dumps(result['errors'], indent=2)}")

    if "data" not in result:
        raise RuntimeError(f"Unexpected response (no 'data'): {json.dumps(result, indent=2)}")

    return result["data"]


def save_schema(schema: dict, output_file: str):
    """Saves the introspected schema to a JSON file."""
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(schema, f, indent=2)
    size_kb = os.path.getsize(output_file) / 1024
    print(f"Schema saved to '{output_file}' ({size_kb:.1f} KB)")


def get_credentials() -> tuple[str, str]:
    """Reads OAuth2 credentials from environment variables or prompts the user."""
    client_id = os.environ.get("MEETUP_CLIENT_ID")
    client_secret = os.environ.get("MEETUP_CLIENT_SECRET")

    if not client_id:
        print("Meetup OAuth2 Client ID not found in MEETUP_CLIENT_ID env var.")
        client_id = input("Enter your Meetup OAuth2 Client ID: ").strip()

    if not client_secret:
        print("Meetup OAuth2 Client Secret not found in MEETUP_CLIENT_SECRET env var.")
        import getpass
        client_secret = getpass.getpass("Enter your Meetup OAuth2 Client Secret: ")

    if not client_id or not client_secret:
        print("Error: Client ID and Client Secret are required.", file=sys.stderr)
        sys.exit(1)

    return client_id, client_secret


def main():
    print("=== Meetup GraphQL Schema Fetcher ===")
    print(
        "\nPrerequisite: Register an OAuth2 consumer at "
        "https://www.meetup.com/api/oauth/list/\n"
        f"  Set Redirect URI to: {REDIRECT_URI}\n"
    )

    client_id, client_secret = get_credentials()

    try:
        access_token = run_oauth_flow(client_id, client_secret)
        schema = fetch_graphql_schema(access_token)
        save_schema(schema, SCHEMA_OUTPUT_FILE)

        # Print a summary of top-level types
        types = schema.get("__schema", {}).get("types", [])
        user_types = [
            t["name"] for t in types
            if t.get("name") and not t["name"].startswith("__")
        ]
        print(f"\nSchema contains {len(user_types)} user-defined types.")
        print("Sample types:", ", ".join(user_types[:10]), "...")

    except KeyboardInterrupt:
        print("\nAborted by user.")
        sys.exit(1)
    except requests.HTTPError as e:
        print(f"\nHTTP error: {e}", file=sys.stderr)
        if e.response is not None:
            print(f"Response body: {e.response.text}", file=sys.stderr)
        sys.exit(1)
    except RuntimeError as e:
        print(f"\nError: {e}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
