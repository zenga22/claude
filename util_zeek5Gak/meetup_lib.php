<?php

declare(strict_types=1);

/**
 * Shared library for Meetup OAuth2 + GraphQL introspection.
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const MEETUP_AUTH_URL    = 'https://secure.meetup.com/oauth2/authorize';
const MEETUP_TOKEN_URL   = 'https://secure.meetup.com/oauth2/access';
const MEETUP_GRAPHQL_URL = 'https://api.meetup.com/gql';
const MEETUP_REDIRECT_URI = 'https://secularhub.org/util_zeek5Gak/callback';
const TOKEN_CACHE_FILE   = __DIR__ . '/.meetup_token.json';

// ---------------------------------------------------------------------------
// Configuration loading
// ---------------------------------------------------------------------------

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

function getConfig(string $key, string $default = ''): string
{
    return (string) ($_ENV[$key] ?? getenv($key) ?: $default);
}

// ---------------------------------------------------------------------------
// HTTP helper (cURL, no external dependencies)
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
            static fn($k, $v) => "$k: $v",
            array_keys($headers),
            $headers,
        ),
    ]);

    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("cURL error: $error");
    }

    return ['status' => $status, 'body' => (string) $response];
}

// ---------------------------------------------------------------------------
// OAuth2 token value object
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
            tokenType:    $data['token_type'] ?? 'bearer',
            expiresAt:    (int) ($data['expires_at'] ?? 0),
            refreshToken: $data['refresh_token'] ?? '',
        );
    }
}

// ---------------------------------------------------------------------------
// Token persistence
// ---------------------------------------------------------------------------

function loadCachedToken(): ?OAuth2Token
{
    // Session cache (fastest, per-process)
    if (isset($_SESSION['oauth2_token']) && is_array($_SESSION['oauth2_token'])) {
        $token = OAuth2Token::fromArray($_SESSION['oauth2_token']);
        if (!$token->isExpired()) {
            return $token;
        }
    }

    // File cache (survives across requests)
    if (!file_exists(TOKEN_CACHE_FILE)) {
        return null;
    }

    $raw  = file_get_contents(TOKEN_CACHE_FILE);
    $data = $raw !== false ? json_decode($raw, true) : null;

    if (!is_array($data) || empty($data['access_token'])) {
        return null;
    }

    $token = OAuth2Token::fromArray($data);

    if ($token->isExpired()) {
        // Try to silently refresh
        $refreshed = attemptRefresh($token->refreshToken);
        if ($refreshed !== null) {
            saveToken($refreshed);
            return $refreshed;
        }
        return null;
    }

    $_SESSION['oauth2_token'] = $token->toArray();
    return $token;
}

function saveToken(OAuth2Token $token): void
{
    $_SESSION['oauth2_token'] = $token->toArray();
    file_put_contents(TOKEN_CACHE_FILE, json_encode($token->toArray(), JSON_PRETTY_PRINT));
    @chmod(TOKEN_CACHE_FILE, 0600);
}

// ---------------------------------------------------------------------------
// OAuth2 flows
// ---------------------------------------------------------------------------

/**
 * Exchange an authorization code for an access token.
 *
 * @throws RuntimeException on failure
 */
function exchangeCode(string $code, string $clientId, string $clientSecret): OAuth2Token
{
    $body = http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => MEETUP_REDIRECT_URI,
    ]);

    $result = httpPost(MEETUP_TOKEN_URL, $body, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Accept'       => 'application/json',
    ]);

    $data = json_decode($result['body'], true);

    if ($result['status'] !== 200 || empty($data['access_token'])) {
        throw new RuntimeException(
            "HTTP {$result['status']}: " .
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
 * Attempt a silent token refresh; returns null on any failure.
 */
function attemptRefresh(string $refreshToken): ?OAuth2Token
{
    if ($refreshToken === '') {
        return null;
    }

    $clientId     = getConfig('MEETUP_CLIENT_ID');
    $clientSecret = getConfig('MEETUP_CLIENT_SECRET');

    if ($clientId === '' || $clientSecret === '') {
        return null;
    }

    $body = http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
    ]);

    try {
        $result = httpPost(MEETUP_TOKEN_URL, $body, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ]);
    } catch (RuntimeException) {
        return null;
    }

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

// ---------------------------------------------------------------------------
// GraphQL
// ---------------------------------------------------------------------------

/**
 * Execute a GraphQL operation against the Meetup API.
 *
 * @param  array<string,mixed>  $variables
 * @return array<string,mixed>
 * @throws RuntimeException on HTTP or GraphQL-level errors
 */
function graphqlQuery(string $query, OAuth2Token $token, array $variables = []): array
{
    $payload = json_encode(['query' => $query, 'variables' => $variables]);

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
        throw new RuntimeException(
            "GraphQL HTTP {$result['status']}: " . $result['body']
        );
    }

    if (!empty($data['errors'])) {
        $messages = array_column($data['errors'], 'message');
        throw new RuntimeException('GraphQL errors: ' . implode('; ', $messages));
    }

    return $data;
}

/**
 * Full introspection query (GraphQL June 2018 spec).
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
        args { ...InputValue }
        type { ...TypeRef }
        isDeprecated
        deprecationReason
      }
      inputFields { ...InputValue }
      interfaces { ...TypeRef }
      enumValues(includeDeprecated: true) {
        name
        description
        isDeprecated
        deprecationReason
      }
      possibleTypes { ...TypeRef }
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
// SDL builder
// ---------------------------------------------------------------------------

function typeString(array $type): string
{
    return match ($type['kind']) {
        'NON_NULL' => typeString($type['ofType']) . '!',
        'LIST'     => '[' . typeString($type['ofType']) . ']',
        default    => (string) $type['name'],
    };
}

function buildSdl(array $schema): string
{
    $lines = [];

    $builtIn = [
        '__Schema', '__Type', '__TypeKind', '__Field',
        '__InputValue', '__EnumValue', '__Directive', '__DirectiveLocation',
        'String', 'Boolean', 'Int', 'Float', 'ID',
    ];

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
        $lines[] = 'schema {';
        array_push($lines, ...$schemaBlock);
        $lines[] = '}';
        $lines[] = '';
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
                    $dep     = $val['isDeprecated']
                        ? ' @deprecated(reason: "' . addslashes((string) ($val['deprecationReason'] ?? '')) . '")'
                        : '';
                    $lines[] = "  {$val['name']}$dep";
                }
                $lines[] = '}';
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
                $lines[] = '}';
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

                    $args   = $field['args'] ?? [];
                    $dep    = $field['isDeprecated']
                        ? ' @deprecated(reason: "' . addslashes((string) ($field['deprecationReason'] ?? '')) . '")'
                        : '';

                    if ($args === []) {
                        $lines[] = "  {$field['name']}: " . typeString($field['type']) . $dep;
                    } elseif (count($args) <= 2) {
                        $parts   = array_map(
                            static fn($a) => "{$a['name']}: " . typeString($a['type'])
                                . (isset($a['defaultValue']) && $a['defaultValue'] !== null
                                    ? ' = ' . $a['defaultValue'] : ''),
                            $args,
                        );
                        $argStr  = '(' . implode(', ', $parts) . ')';
                        $lines[] = "  {$field['name']}$argStr: " . typeString($field['type']) . $dep;
                    } else {
                        $lines[] = "  {$field['name']}(";
                        foreach ($args as $i => $arg) {
                            $argDefault = isset($arg['defaultValue']) && $arg['defaultValue'] !== null
                                ? ' = ' . $arg['defaultValue'] : '';
                            $sep        = $i < count($args) - 1 ? ',' : '';
                            $lines[]    = "    {$arg['name']}: " . typeString($arg['type']) . "$argDefault$sep";
                        }
                        $lines[] = "  ): " . typeString($field['type']) . $dep;
                    }
                }
                $lines[] = '}';
                break;

            case 'UNION':
                $possible = array_column($type['possibleTypes'] ?? [], 'name');
                $lines[]  = "union $name = " . implode(' | ', $possible);
                break;
        }

        $lines[] = '';
    }

    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// Orchestration
// ---------------------------------------------------------------------------

/**
 * Run the full introspection pipeline and save output files.
 *
 * @param  array{json:string,sdl:string}  $outputPaths
 * @return array{0:string,1:string,2:int}  [jsonFile, sdlFile, typeCount]
 * @throws RuntimeException
 */
function runIntrospection(OAuth2Token $token, array $outputPaths): array
{
    $response = graphqlQuery(introspectionQuery(), $token);
    $schema   = $response['data']['__schema'];

    // Count non-built-in types
    $typeCount = count(array_filter(
        $schema['types'],
        static fn($t) => !str_starts_with((string) $t['name'], '__')
    ));

    // Raw JSON
    $jsonFile = $outputPaths['json'];
    if (file_put_contents($jsonFile, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException("Cannot write to $jsonFile — check directory permissions.");
    }

    // SDL
    $stamp   = date('Y-m-d H:i:s T');
    $header  = "# Meetup.com GraphQL Schema\n# Generated: $stamp\n# Source: introspection via " . MEETUP_GRAPHQL_URL . "\n\n";
    $sdlFile = $outputPaths['sdl'];
    if (file_put_contents($sdlFile, $header . buildSdl($schema)) === false) {
        throw new RuntimeException("Cannot write to $sdlFile — check directory permissions.");
    }

    return [$jsonFile, $sdlFile, $typeCount];
}
