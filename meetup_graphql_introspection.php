#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Meetup.com GraphQL Introspection Tool
 *
 * Authenticates via OAuth2 and fetches the full GraphQL schema
 * using introspection, saving it to a file.
 *
 * Usage:
 *   1. Set environment variables (or create a .env file):
 *      MEETUP_CLIENT_ID=your_client_id
 *      MEETUP_CLIENT_SECRET=your_client_secret
 *      MEETUP_REDIRECT_URI=http://localhost:8080/callback  (default)
 *
 *   2. Run: php meetup_graphql_introspection.php
 *
 *   3. Follow the printed authorization URL in your browser, then
 *      the local callback server captures the code automatically.
 *
 * Requirements: PHP 8.3+, ext-curl, ext-json
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const MEETUP_AUTH_URL    = 'https://secure.meetup.com/oauth2/authorize';
const MEETUP_TOKEN_URL   = 'https://secure.meetup.com/oauth2/access';
const MEETUP_GRAPHQL_URL = 'https://api.meetup.com/gql';
const CALLBACK_HOST      = '127.0.0.1';
const CALLBACK_PORT      = 8080;
const TOKEN_CACHE_FILE   = __DIR__ . '/.meetup_token.json';
const SCHEMA_OUTPUT_FILE = __DIR__ . '/meetup_schema.graphql';
const SDL_OUTPUT_FILE    = __DIR__ . '/meetup_schema_raw.json';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function env(string $key, string $default = ''): string
{
    return (string) ($_ENV[$key] ?? getenv($key) ?: $default);
}

function loadDotEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function abort(string $message, int $code = 1): never
{
    fwrite(STDERR, "\033[31mError:\033[0m $message\n");
    exit($code);
}

function info(string $message): void
{
    echo "\033[36m[info]\033[0m $message\n";
}

function success(string $message): void
{
    echo "\033[32m[ok]\033[0m $message\n";
}

// ---------------------------------------------------------------------------
// HTTP client (cURL)
// ---------------------------------------------------------------------------

/**
 * @param  array<string,string>  $headers
 * @return array{status:int,body:string}
 */
function httpPost(string $url, string $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_map(
            fn($k, $v) => "$k: $v",
            array_keys($headers),
            $headers,
        ),
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        abort("cURL error: $error");
    }

    return ['status' => $status, 'body' => (string) $response];
}

// ---------------------------------------------------------------------------
// OAuth2
// ---------------------------------------------------------------------------

final readonly class OAuth2Token
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int    $expiresAt,
        public string $refreshToken = '',
    ) {}

    public function isExpired(): bool
    {
        // Refresh 60 s before actual expiry
        return time() >= ($this->expiresAt - 60);
    }

    public function toArray(): array
    {
        return [
            'access_token'  => $this->accessToken,
            'token_type'    => $this->tokenType,
            'expires_at'    => $this->expiresAt,
            'refresh_token' => $this->refreshToken,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accessToken:  $data['access_token'],
            tokenType:    $data['token_type'],
            expiresAt:    (int) $data['expires_at'],
            refreshToken: $data['refresh_token'] ?? '',
        );
    }
}

function loadCachedToken(): ?OAuth2Token
{
    if (!file_exists(TOKEN_CACHE_FILE)) {
        return null;
    }
    $data = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }
    $token = OAuth2Token::fromArray($data);
    return $token->isExpired() ? null : $token;
}

function saveToken(OAuth2Token $token): void
{
    file_put_contents(TOKEN_CACHE_FILE, json_encode($token->toArray(), JSON_PRETTY_PRINT));
    chmod(TOKEN_CACHE_FILE, 0600);
}

/**
 * Exchange an authorization code for tokens.
 */
function exchangeCode(
    string $code,
    string $clientId,
    string $clientSecret,
    string $redirectUri,
): OAuth2Token {
    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
    ]);

    $result = httpPost(MEETUP_TOKEN_URL, $body, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept'       => 'application/json',
    ]);

    $data = json_decode($result['body'], true);

    if ($result['status'] !== 200 || empty($data['access_token'])) {
        abort(
            "Token exchange failed (HTTP {$result['status']}): " .
            ($data['error_description'] ?? $data['error'] ?? $result['body'])
        );
    }

    $expiresIn = (int) ($data['expires_in'] ?? 3600);

    return new OAuth2Token(
        accessToken:  $data['access_token'],
        tokenType:    $data['token_type'] ?? 'bearer',
        expiresAt:    time() + $expiresIn,
        refreshToken: $data['refresh_token'] ?? '',
    );
}

/**
 * Attempt to refresh using a refresh token.
 */
function refreshToken(
    string $refreshToken,
    string $clientId,
    string $clientSecret,
): ?OAuth2Token {
    if ($refreshToken === '') {
        return null;
    }

    $body = http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]);

    $result = httpPost(MEETUP_TOKEN_URL, $body, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept'       => 'application/json',
    ]);

    $data = json_decode($result['body'], true);

    if ($result['status'] !== 200 || empty($data['access_token'])) {
        return null;
    }

    $expiresIn = (int) ($data['expires_in'] ?? 3600);

    return new OAuth2Token(
        accessToken:  $data['access_token'],
        tokenType:    $data['token_type'] ?? 'bearer',
        expiresAt:    time() + $expiresIn,
        refreshToken: $data['refresh_token'] ?? $refreshToken,
    );
}

/**
 * Run a minimal local HTTP server on CALLBACK_PORT to capture the OAuth2
 * redirect and extract the authorization code.
 */
function runCallbackServer(): string
{
    $sock = @stream_socket_server(
        'tcp://' . CALLBACK_HOST . ':' . CALLBACK_PORT,
        $errno,
        $errstr,
    );

    if ($sock === false) {
        abort("Cannot bind to port " . CALLBACK_PORT . ": $errstr ($errno)");
    }

    info("Waiting for OAuth2 callback on http://" . CALLBACK_HOST . ":" . CALLBACK_PORT . "/callback …");

    $code = null;

    while (true) {
        $conn = @stream_socket_accept($sock, 30.0);
        if ($conn === false) {
            abort("Timed out waiting for OAuth2 callback. Re-run the script.");
        }

        $request = '';
        while (!str_contains($request, "\r\n\r\n")) {
            $chunk = fread($conn, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }

        // Parse the request line: GET /callback?code=XXX&state=YYY HTTP/1.1
        preg_match('#^GET\s+(\S+)\s+HTTP#', $request, $m);
        $path = $m[1] ?? '/';

        parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $params);
        $code = $params['code'] ?? null;

        if ($code !== null) {
            $html = '<html><body style="font-family:sans-serif;text-align:center;padding:4rem">'
                . '<h2 style="color:#4CAF50">&#10003; Authorization successful!</h2>'
                . '<p>You can close this tab and return to your terminal.</p>'
                . '</body></html>';
            fwrite(
                $conn,
                "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n$html"
            );
        } else {
            $error = htmlspecialchars($params['error_description'] ?? $params['error'] ?? 'Unknown error');
            $html  = "<html><body><h2 style='color:red'>Authorization failed</h2><pre>$error</pre></body></html>";
            fwrite(
                $conn,
                "HTTP/1.1 400 Bad Request\r\nContent-Type: text/html\r\nContent-Length: " . strlen($html) . "\r\n\r\n$html"
            );
        }

        fclose($conn);

        if ($code !== null) {
            break;
        }
    }

    fclose($sock);

    if ($code === null) {
        abort("No authorization code received.");
    }

    return $code;
}

/**
 * Full OAuth2 authorization-code flow: open browser → callback → token.
 */
function authorize(string $clientId, string $clientSecret, string $redirectUri): OAuth2Token
{
    $state = bin2hex(random_bytes(16));

    $authUrl = MEETUP_AUTH_URL . '?' . http_build_query([
        'client_id'     => $clientId,
        'response_type' => 'code',
        'redirect_uri'  => $redirectUri,
        'scope'         => 'basic',
        'state'         => $state,
    ]);

    echo "\n";
    echo "\033[33m┌─────────────────────────────────────────────────────────────────┐\033[0m\n";
    echo "\033[33m│  Open the following URL in your browser to authorize:           │\033[0m\n";
    echo "\033[33m└─────────────────────────────────────────────────────────────────┘\033[0m\n";
    echo "\n  $authUrl\n\n";

    // Attempt to auto-open in browser
    $opener = match (true) {
        PHP_OS_FAMILY === 'Darwin'  => 'open',
        PHP_OS_FAMILY === 'Windows' => 'start',
        default                     => 'xdg-open',
    };
    @exec("$opener " . escapeshellarg($authUrl) . " 2>/dev/null &");

    $code  = runCallbackServer();
    $token = exchangeCode($code, $clientId, $clientSecret, $redirectUri);

    saveToken($token);
    success("Tokens saved to " . TOKEN_CACHE_FILE);

    return $token;
}

// ---------------------------------------------------------------------------
// GraphQL
// ---------------------------------------------------------------------------

/**
 * Execute a GraphQL query against Meetup's API.
 *
 * @return array<string,mixed>
 */
function graphqlQuery(string $query, OAuth2Token $token, array $variables = []): array
{
    $payload = json_encode([
        'query'     => $query,
        'variables' => $variables,
    ]);

    $result = httpPost(
        MEETUP_GRAPHQL_URL,
        $payload,
        [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $token->accessToken,
        ],
    );

    $data = json_decode($result['body'], true);

    if ($result['status'] !== 200) {
        abort("GraphQL request failed (HTTP {$result['status']}): " . $result['body']);
    }

    if (isset($data['errors'])) {
        $messages = array_column($data['errors'], 'message');
        abort("GraphQL errors: " . implode('; ', $messages));
    }

    return $data;
}

/**
 * Full GraphQL introspection query (follows the June 2018 spec).
 */
function introspectionQuery(): string
{
    return <<<'GRAPHQL'
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
    GRAPHQL;
}

// ---------------------------------------------------------------------------
// Schema SDL converter
// ---------------------------------------------------------------------------

/**
 * Convert an introspection __Type node to a nullable PHP type string.
 */
function typeString(array $type): string
{
    return match ($type['kind']) {
        'NON_NULL' => typeString($type['ofType']) . '!',
        'LIST'     => '[' . typeString($type['ofType']) . ']',
        default    => $type['name'],
    };
}

/**
 * Build a human-readable GraphQL SDL from the raw introspection JSON.
 */
function buildSdl(array $schema): string
{
    $lines = [];

    $builtIn = [
        '__Schema', '__Type', '__TypeKind', '__Field',
        '__InputValue', '__EnumValue', '__Directive', '__DirectiveLocation',
        'String', 'Boolean', 'Int', 'Float', 'ID',
    ];

    // Root operation types
    $queryType        = $schema['queryType']['name']        ?? null;
    $mutationType     = $schema['mutationType']['name']     ?? null;
    $subscriptionType = $schema['subscriptionType']['name'] ?? null;

    $schemaBlock = [];
    if ($queryType !== null && $queryType !== 'Query') {
        $schemaBlock[] = "  query: $queryType";
    }
    if ($mutationType !== null && $mutationType !== 'Mutation') {
        $schemaBlock[] = "  mutation: $mutationType";
    }
    if ($subscriptionType !== null && $subscriptionType !== 'Subscription') {
        $schemaBlock[] = "  subscription: $subscriptionType";
    }

    if ($schemaBlock !== []) {
        $lines[] = "schema {";
        array_push($lines, ...$schemaBlock);
        $lines[] = "}";
        $lines[] = "";
    }

    foreach ($schema['types'] as $type) {
        $name = $type['name'];

        if (in_array($name, $builtIn, true) || str_starts_with($name, '__')) {
            continue;
        }

        $desc = $type['description'] ?? null;
        if ($desc !== null && $desc !== '') {
            $lines[] = '"""';
            $lines[] = $desc;
            $lines[] = '"""';
        }

        switch ($type['kind']) {
            case 'SCALAR':
                $lines[] = "scalar $name";
                break;

            case 'ENUM':
                $lines[] = "enum $name {";
                foreach ($type['enumValues'] ?? [] as $val) {
                    $valDesc = $val['description'] ?? null;
                    if ($valDesc !== null && $valDesc !== '') {
                        $lines[] = "  \"\"\"$valDesc\"\"\"";
                    }
                    $dep = $val['isDeprecated']
                        ? ' @deprecated(reason: "' . addslashes($val['deprecationReason'] ?? '') . '")'
                        : '';
                    $lines[] = "  {$val['name']}$dep";
                }
                $lines[] = "}";
                break;

            case 'INPUT_OBJECT':
                $lines[] = "input $name {";
                foreach ($type['inputFields'] ?? [] as $field) {
                    $fieldDesc = $field['description'] ?? null;
                    if ($fieldDesc !== null && $fieldDesc !== '') {
                        $lines[] = "  \"\"\"$fieldDesc\"\"\"";
                    }
                    $default = isset($field['defaultValue']) && $field['defaultValue'] !== null
                        ? ' = ' . $field['defaultValue']
                        : '';
                    $lines[] = "  {$field['name']}: " . typeString($field['type']) . $default;
                }
                $lines[] = "}";
                break;

            case 'INTERFACE':
            case 'OBJECT':
                $keyword    = $type['kind'] === 'INTERFACE' ? 'interface' : 'type';
                $interfaces = $type['interfaces'] ?? [];
                $impl       = $interfaces !== []
                    ? ' implements ' . implode(' & ', array_column($interfaces, 'name'))
                    : '';
                $lines[] = "$keyword $name$impl {";

                foreach ($type['fields'] ?? [] as $field) {
                    $fieldDesc = $field['description'] ?? null;
                    if ($fieldDesc !== null && $fieldDesc !== '') {
                        $lines[] = "  \"\"\"$fieldDesc\"\"\"";
                    }

                    $args = $field['args'] ?? [];
                    if ($args === []) {
                        $argStr = '';
                    } elseif (count($args) <= 2) {
                        $parts  = array_map(
                            fn($a) => "{$a['name']}: " . typeString($a['type'])
                                . (isset($a['defaultValue']) && $a['defaultValue'] !== null ? ' = ' . $a['defaultValue'] : ''),
                            $args,
                        );
                        $argStr = '(' . implode(', ', $parts) . ')';
                    } else {
                        $lines[] = "  {$field['name']}(";
                        foreach ($args as $i => $arg) {
                            $argDefault = isset($arg['defaultValue']) && $arg['defaultValue'] !== null
                                ? ' = ' . $arg['defaultValue']
                                : '';
                            $sep = $i < count($args) - 1 ? ',' : '';
                            $lines[] = "    {$arg['name']}: " . typeString($arg['type']) . "$argDefault$sep";
                        }
                        $dep = $field['isDeprecated']
                            ? ' @deprecated(reason: "' . addslashes($field['deprecationReason'] ?? '') . '")'
                            : '';
                        $lines[] = "  ): " . typeString($field['type']) . $dep;
                        continue;
                    }

                    $dep = $field['isDeprecated']
                        ? ' @deprecated(reason: "' . addslashes($field['deprecationReason'] ?? '') . '")'
                        : '';
                    $lines[] = "  {$field['name']}$argStr: " . typeString($field['type']) . $dep;
                }
                $lines[] = "}";
                break;

            case 'UNION':
                $possibleTypes = array_column($type['possibleTypes'] ?? [], 'name');
                $lines[] = "union $name = " . implode(' | ', $possibleTypes);
                break;
        }

        $lines[] = "";
    }

    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main(): void
{
    // Load optional .env file
    loadDotEnv(__DIR__ . '/.env');

    $clientId     = env('MEETUP_CLIENT_ID');
    $clientSecret = env('MEETUP_CLIENT_SECRET');
    $redirectUri  = env('MEETUP_REDIRECT_URI', 'http://' . CALLBACK_HOST . ':' . CALLBACK_PORT . '/callback');

    if ($clientId === '' || $clientSecret === '') {
        abort(
            "Missing OAuth2 credentials.\n" .
            "  Set MEETUP_CLIENT_ID and MEETUP_CLIENT_SECRET in your environment or in a .env file.\n" .
            "  Register your app at: https://www.meetup.com/api/oauth/list/"
        );
    }

    // ── 1. Obtain a valid access token ──────────────────────────────────────
    $token = loadCachedToken();

    if ($token !== null) {
        info("Using cached access token (expires at " . date('Y-m-d H:i:s', $token->expiresAt) . ")");
    } else {
        // Try refresh first if we have a stale cached file
        if (file_exists(TOKEN_CACHE_FILE)) {
            $cached = json_decode(file_get_contents(TOKEN_CACHE_FILE), true);
            if (!empty($cached['refresh_token'])) {
                info("Attempting token refresh …");
                $token = refreshToken($cached['refresh_token'], $clientId, $clientSecret);
                if ($token !== null) {
                    saveToken($token);
                    success("Token refreshed successfully.");
                } else {
                    info("Refresh failed; re-authorizing …");
                }
            }
        }

        if ($token === null) {
            $token = authorize($clientId, $clientSecret, $redirectUri);
        }
    }

    // ── 2. Run GraphQL introspection ─────────────────────────────────────────
    info("Running GraphQL introspection query against " . MEETUP_GRAPHQL_URL . " …");

    $response = graphqlQuery(introspectionQuery(), $token);
    $schema   = $response['data']['__schema'];

    $typeCount = count(array_filter(
        $schema['types'],
        fn($t) => !str_starts_with($t['name'], '__')
    ));

    success("Introspection complete — $typeCount user-defined types found.");

    // ── 3. Save raw introspection JSON ───────────────────────────────────────
    file_put_contents(SDL_OUTPUT_FILE, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    success("Raw introspection JSON saved to " . SDL_OUTPUT_FILE);

    // ── 4. Build & save SDL representation ───────────────────────────────────
    info("Converting introspection result to SDL …");
    $sdl = buildSdl($schema);

    // Prepend a header comment
    $stamp  = date('Y-m-d H:i:s T');
    $header = "# Meetup.com GraphQL Schema\n# Generated: $stamp\n# Source: introspection via " . MEETUP_GRAPHQL_URL . "\n\n";

    file_put_contents(SCHEMA_OUTPUT_FILE, $header . $sdl);
    success("SDL schema saved to " . SCHEMA_OUTPUT_FILE);

    echo "\n";
    echo "\033[32m✓ Done!\033[0m\n";
    echo "  JSON introspection : " . SDL_OUTPUT_FILE    . "\n";
    echo "  GraphQL SDL        : " . SCHEMA_OUTPUT_FILE . "\n";
}

main();
