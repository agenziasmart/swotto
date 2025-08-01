# Swotto PHP SDK

![Version](https://img.shields.io/badge/version-v2.2.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-orange.svg)
![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)
![Enterprise](https://img.shields.io/badge/enterprise-ready-success.svg)

**Enterprise-ready PHP SDK** per l'integrazione con le API SW4. Fornisce un'interfaccia type-safe, smart caching, e parsing automatico delle risposte con **87.5% di riduzione del boilerplate code**.

## Indice

- [✨ Novità v2.2.0](#-novità-v220)
- [Quick Start](#quick-start)
- [Installazione](#installazione)
- [🚀 Enhanced Methods - getParsed()](#-enhanced-methods---getparsed)
- [🔐 Dynamic Bearer Token Management](#-dynamic-bearer-token-management)
- [🎯 Smart Caching](#-smart-caching)
- [🔧 Circuit Breaker Pattern](#-circuit-breaker-pattern)
- [Configurazione Base](#configurazione-base)
- [Metodi HTTP di base](#metodi-http-di-base)
- [Gestione degli Errori](#gestione-degli-errori)
- [Utilizzo dei Metodi POP](#utilizzo-dei-metodi-pop)
- [Test e Verifica](#test-e-verifica)
- [Architettura](#architettura)
- [Sviluppo](#sviluppo)
- [Performance](#performance)
- [Troubleshooting](#troubleshooting)
- [Migration Guide](#migration-guide)
- [Changelog](#changelog)

## ✨ Novità v2.2.0

### 🔧 Circuit Breaker Pattern - Resilienza Enterprise
**NUOVO v2.2.0**: Pattern Circuit Breaker per applicazioni mission-critical con API instabili.

```php
// Configurazione con Circuit Breaker abilitato
$config = [
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_KEY',
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 5,    // Fallimenti per aprire circuito
    'circuit_breaker_recovery_timeout' => 30     // Secondi prima del recovery
];

// Client con resilienza automatica
$client = new Client(
    $config,
    $logger, 
    GuzzleHttpClient::withCircuitBreaker(
        new Configuration($config), 
        $logger, 
        $cache  // Redis/File cache per state persistence
    )
);

// Le chiamate falliscono velocemente se API è instabile
try {
    $result = $client->get('customers');
} catch (CircuitBreakerOpenException $e) {
    // Circuito aperto - API temporaneamente non disponibile
    echo "Service unavailable, retry after: {$e->getRetryAfter()} seconds";
}
```

**Benefici**:
- ✅ **Fail-fast**: Zero timeout lunghi durante problemi API
- ✅ **Auto-recovery**: Test automatico ripristino servizio  
- ✅ **Cache persistence**: State condiviso tra requests via Redis
- ✅ **Zero overhead**: Disabilitato di default, attivazione opt-in

## ✨ Novità v2.0.0 (Previous Release)

### 🚀 getParsed() Methods - 87.5% Less Boilerplate
```php
// PRIMA v1.x (8 linee di codice ripetitivo)
$response = $client->get('customers', ['query' => $query]);
$parsed = $this->parseDataResponse($response);
$data = [
    'data' => $parsed['data'],
    'paginator' => $parsed['paginator']
];

// ADESSO v2.0 (1 linea - parsing automatico!)
$data = $client->getParsed('customers', ['query' => $query]);
// Returns: ['data' => [...], 'paginator' => [...], 'success' => true]
```

### 🎯 Smart Caching Automatico
```php
// Auto-caching per dati statici (countries, currencies, etc.)
$countries = $client->getCountryPop();     // HTTP call + cache (1h)
$countries = $client->getCountryPop();     // Cache hit! No HTTP

// Dati dinamici sempre fresh
$orders = $client->get('orders');          // Always fresh (no cache)
```

### 🏗️ Modern PHP 8.1+ Architecture
- ✅ **PSR-16 Simple Cache** support per Redis, Memcached, Array cache
- ✅ **PSR-14 Event Dispatcher** per monitoring e telemetry  
- ✅ **Circuit Breaker Pattern** per resilienza enterprise
- ✅ **100% Backward Compatible** - zero breaking changes
- ✅ **Progressive Enhancement** - features solo se abilitate

## Quick Start

```php
use Swotto\Client;

// Configurazione minimal (identica a v1.x)
$client = new Client(['url' => 'https://api.swotto.it']);

// NEW v2.0: Parsing automatico delle risposte
$data = $client->getParsed('customers', ['query' => ['active' => true]]);
// Automatic parsing: data + paginator extraction!

// v1.x methods funzionano identicamente
$customers = $client->getCustomerPop();
$result = $client->get('endpoint', ['query' => ['param' => 'value']]);
```

### Quick Start con Smart Caching
```php
// Optional: Abilita smart caching
$cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
$client = new Client(['url' => 'https://api.swotto.it'], null, null, $cache);

// Auto-cached per dati statici
$countries = $client->getCountryPop();  // HTTP + cache
$countries = $client->getCountryPop();  // Cache hit!
```

## Installazione

### Requisiti

- PHP 8.1 o superiore
- Composer
- ext-json

### Installazione via Composer

Il progetto utilizza **git tags** per il versioning. Installa una versione specifica:

```bash
# Versione corrente stabile
composer require agenziasmart/swotto:v2.0.0

# Range di versioni compatibili  
composer require agenziasmart/swotto:^v2.0
```

### Configurazione composer.json

```json
{
    "require": {
        "agenziasmart/swotto": "v2.0.0"
    }
}
```

### Versioning e Release

Il progetto segue [Semantic Versioning](https://semver.org/):
- **v2.0.0**: 🚀 **Enterprise-ready release** con getParsed() methods + smart caching
- **v1.3.0**: Test suite completa, PHPStan level 8  
- **v1.2.0**: Aggiunto supporto metodi HTTP PUT
- **v1.0.x**: Versioni legacy

Tags disponibili: `git tag --list --sort=-version:refname`

## 🚀 Enhanced Methods - getParsed()

I nuovi metodi `*Parsed()` eliminano il boilerplate ripetitivo automatizzando il parsing delle risposte SW4:

### Metodi Disponibili
```php
// Tutti i metodi HTTP con parsing automatico
$data = $client->getParsed($endpoint, $options);        // GET + parsing
$data = $client->postParsed($endpoint, $options);       // POST + parsing
$data = $client->patchParsed($endpoint, $options);      // PATCH + parsing
$data = $client->putParsed($endpoint, $options);        // PUT + parsing
$data = $client->deleteParsed($endpoint, $options);     // DELETE + parsing
```

### Struttura Response Automatica
```php
// Tutti i metodi *Parsed() restituiscono:
[
    'data' => [...],           // Dati business estratti da response['data']
    'paginator' => [...],      // Paginator costruito da response['meta']['pagination']  
    'success' => true|false    // Flag success da response['success']
]
```

### Esempi Pratici

#### Prima vs Dopo
```php
// PRIMA v1.x - Boilerplate ripetitivo in ogni handler
$query = array_merge(array_filter($request->getQueryParams()), [
    'count' => 1,
    'include' => 'consent',
]);
$query['orderby'] ??= 'updated_at.desc';
$query['limit'] ??= $request->getAttribute('_pagination');
$response = $this->swotto->get('customers', ['query' => $query]);
$parsed = $this->parseDataResponse($response);
$data = [
    'data' => $parsed['data'],
    'paginator' => $parsed['paginator'],
];

// DOPO v2.0 - Una linea!
$query = $this->buildQuery($request, ['count' => 1, 'include' => 'consent'], 'updated_at.desc');
$data = $this->swotto->getParsed('customers', ['query' => $query]);
```

#### CRUD Operations
```php
// CREATE with auto-parsing
$newCustomer = $client->postParsed('customers', [
    'json' => ['name' => 'New Customer', 'email' => 'test@example.com']
]);
echo $newCustomer['data']['id'];  // Auto-extracted customer ID

// READ with auto-parsing + pagination
$customers = $client->getParsed('customers', [
    'query' => ['active' => true, 'limit' => 25]
]);
echo count($customers['data']);                    // Customer data
echo $customers['paginator']['total'];             // Total count
echo $customers['paginator']['current_page'];      // Current page

// UPDATE with auto-parsing  
$updated = $client->patchParsed('customers/123', [
    'json' => ['name' => 'Updated Name']
]);
echo $updated['success'];  // true|false

// DELETE with auto-parsing
$deleted = $client->deleteParsed('customers/123');
echo $deleted['success'];  // Deletion confirmed
```

## 🔐 Dynamic Bearer Token Management

La versione 2.0.0 introduce metodi avanzati per la gestione dinamica dei Bearer token, essenziali per applicazioni enterprise che richiedono token refresh automatici e session management robusto:

### Metodi di Gestione Token
```php
// Imposta un nuovo Bearer token runtime
$client->setAccessToken('new-bearer-token-here');

// Verifica token corrente
$currentToken = $client->getAccessToken();
echo $currentToken; // "new-bearer-token-here"

// Cancella token (torna all'autenticazione base)
$client->clearAccessToken();
```

### Pattern di Token Refresh Automatico
```php
try {
    $result = $client->getParsed('protected-endpoint');
} catch (\Swotto\Exception\AuthenticationException $e) {
    // Token scaduto - refresh automatico
    $newToken = $this->refreshTokenFromAuthServer();
    $client->setAccessToken($newToken);
    
    // Retry con nuovo token
    $result = $client->getParsed('protected-endpoint');
}
```

### Session Management Avanzato
```php
// Pattern per applicazioni multi-user
class UserSessionManager {
    public function switchUser(string $userId, string $bearerToken): void {
        $this->client->setAccessToken($bearerToken);
        $this->client->setSessionId("session-{$userId}");
        
        // Verifica validità della nuova sessione
        $sessionCheck = $this->client->checkSession();
        if (!$sessionCheck) {
            throw new InvalidSessionException("Token non valido per user {$userId}");
        }
    }
    
    public function logout(): void {
        $this->client->clearAccessToken();
        $this->client->setSessionId(null);
    }
}
```

## 🔧 Circuit Breaker Pattern

**NUOVO v2.2.0**: Implementazione robusta del Circuit Breaker pattern per applicazioni enterprise che necessitano di resilienza durante instabilità dell'API SW4.

### Configurazione Base

```php
use Swotto\Client;
use Swotto\Config\Configuration;
use Swotto\Http\GuzzleHttpClient;

// Configurazione con Circuit Breaker
$config = [
    'url' => 'https://api.sw4.it',
    'key' => 'YOUR_DEVAPP_KEY',
    
    // Circuit Breaker Configuration (opzionale)
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 5,    // Fallimenti consecutivi per aprire circuito
    'circuit_breaker_recovery_timeout' => 30,    // Secondi di attesa prima del test recovery
];

// Client con Circuit Breaker automatico
$client = new Client(
    $config,
    $logger,
    GuzzleHttpClient::withCircuitBreaker(
        new Configuration($config),
        $logger,
        $cache // PSR-16 cache per state persistence
    )
);
```

### Stati del Circuit Breaker

Il Circuit Breaker opera in tre stati principali:

```php
use Swotto\CircuitBreaker\CircuitState;

// CLOSED: Operazione normale - tutte le richieste passano
$state = CircuitState::CLOSED;

// OPEN: Stato di fallimento - richieste falliscono immediatamente
$state = CircuitState::OPEN;

// HALF_OPEN: Test recovery - richieste limitate per testare ripristino
$state = CircuitState::HALF_OPEN;
```

### Gestione delle Eccezioni

```php
use Swotto\Exception\CircuitBreakerOpenException;

try {
    $customers = $client->getParsed('customers');
    echo "API disponibile: " . count($customers['data']) . " customers";
    
} catch (CircuitBreakerOpenException $e) {
    // Circuit breaker aperto - API temporaneamente non disponibile
    $retryAfter = $e->getRetryAfter();
    
    echo "API temporaneamente non disponibile";
    echo "Riprova tra {$retryAfter} secondi";
    
    // Implementa fallback strategy
    $customers = $this->getCachedCustomers();
    
} catch (\Swotto\Exception\SwottoException $e) {
    // Altri errori API normali
    echo "Errore API: " . $e->getMessage();
}
```

### Cache Persistence per Multi-Request

Il Circuit Breaker mantiene lo stato tra diverse richieste utilizzando cache PSR-16 (opzionale):

```php
// Redis Cache (raccomandato per produzione)
$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$cache = new \Symfony\Component\Cache\Adapter\RedisAdapter($redis);

// Array Cache (per sviluppo/testing)
$cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();

// File Cache (alternativa senza Redis) 
$cache = new \Symfony\Component\Cache\Adapter\FilesystemAdapter('swotto_cb', 3600, '/tmp/cache');

// Cache utilizzata SOLO per Circuit Breaker state persistence
$client = new Client(
    $config,
    $logger, 
    null,
    $cache  // Cache per Circuit Breaker (opzionale)
);
```

### Configurazione Avanzata per Scenari Specifici

```php
// High-throughput applications (soglie più alte)
$configHighTraffic = [
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 10,   // More failures before opening
    'circuit_breaker_recovery_timeout' => 60,    // Longer recovery time
];

// Critical applications (fail-fast aggressivo) 
$configCritical = [
    'circuit_breaker_enabled' => true,
    'circuit_breaker_failure_threshold' => 3,    // Quick to open
    'circuit_breaker_recovery_timeout' => 15,    // Quick recovery test
];

// Development/Testing (circuit breaker disabilitato)
$configDev = [
    'circuit_breaker_enabled' => false,  // Default - zero overhead
];
```

### Monitoring e Metrics

```php
// Accesso diretto al Circuit Breaker per monitoring
$httpClient = GuzzleHttpClient::withCircuitBreaker($config, $logger, $cache);

// In una implementazione custom, potresti accedere allo stato
// tramite reflection o interfaccia dedicata per monitoring
$circuitState = $httpClient->getCircuitState(); // Implementazione futura
$failureCount = $httpClient->getFailureCount(); // Implementazione futura

// Logging automatico degli eventi circuit breaker
// Il logger riceve automaticamente:
// - Transizioni di stato (CLOSED -> OPEN -> HALF_OPEN -> CLOSED)
// - Conteggio fallimenti
// - Eventi di recovery
```

### Best Practices

**✅ DO:**
- Usa cache Redis/persistent per applicazioni multi-server
- Configura threshold basato su pattern di traffico reali
- Implementa fallback strategies per CircuitBreakerOpenException
- Monitor logs per ottimizzare configurazione

**❌ DON'T:**
- Non abilitare in development senza necessità
- Non usare threshold troppo bassi per API con latenza variabile
- Non ignorare CircuitBreakerOpenException senza fallback
- Non condividere cache keys tra applicazioni diverse

## 🚀 Application-Level Caching

**Importante**: Swotto SDK non implementa caching dei dati API. La responsabilità del caching dei dati business è dell'applicazione host.

### Strategia Raccomandata

```php
// L'applicazione gestisce il proprio caching
class MyAppService 
{
    private CacheInterface $appCache;
    private Client $swotto;
    
    public function __construct(Client $swotto, CacheInterface $appCache) 
    {
        $this->swotto = $swotto;
        $this->appCache = $appCache;
    }
    
    public function getCountries(): array 
    {
        $cacheKey = 'app:countries:' . $this->getOrgId();
        
        if ($this->appCache->has($cacheKey)) {
            return $this->appCache->get($cacheKey);
        }
        
        $countries = $this->swotto->fetchPop('open/country');
        $this->appCache->set($cacheKey, $countries, 3600); // 1h TTL
        
        return $countries;
    }
}
```

### Vantaggi Application-Level Caching
- **Controllo completo**: TTL, invalidazione, chiavi personalizzate
- **Multitenant safe**: Isolamento per organization_id  
- **Business logic aware**: Cache basata su logica applicativa
- **Performance ottimizzata**: Cache solo dati necessari all'app

### Token Validation Pattern 
```php
// Verifica proattiva validità token
public function ensureValidToken(): bool {
    try {
        $this->client->checkAuth();
        return true;
    } catch (\Swotto\Exception\AuthenticationException $e) {
        // Token invalido - richiedi refresh
        $this->requestTokenRefresh();
        return false;
    }
}
```


## Configurazione Base

La configurazione minima richiede l'URL dell'API Swotto:

```php
$client = new \Swotto\Client([
    'url' => 'https://api.swotto.it'
]);
```

### Configurazione completa

Per un'applicazione in produzione, si consiglia una configurazione più dettagliata:

```php
$client = new \Swotto\Client([
    'url' => 'https://api.swotto.it',  // URL dell'API (obbligatorio)
    'key' => 'YOUR_API_KEY',           // Chiave API per l'applicazione
    'access_token' => 'YOUR_TOKEN',    // Token di accesso
    'session_id' => 'SESSION_ID',      // ID sessione
    'language' => 'it',                // Lingua preferita
    'accept' => 'application/json',    // Header Accept
    'verify_ssl' => true,              // Verifica certificati SSL
]);
```

### Configurazione con logging

Per tracciare le richieste e le risposte, aggiungi un logger compatibile con PSR-3:

```php
// Configura un logger (esempio con Monolog)
$logger = new \Monolog\Logger('swotto');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('logs/swotto.log', \Monolog\Logger::DEBUG));

// Aggiungi il logger al client
$client = new \Swotto\Client([
    'url' => 'https://api.swotto.it',
    'key' => 'YOUR_API_KEY'
], $logger);
```

## Metodi HTTP di base

Il client fornisce metodi semplici per interagire con l'API:

### GET

```php
// Richiesta GET semplice
$result = $client->get('endpoint');

// Richiesta GET con parametri di query
$result = $client->get('endpoint', [
    'query' => [
        'param1' => 'value1',
        'param2' => 'value2'
    ]
]);
```

### POST

```php
// Richiesta POST con dati JSON
$result = $client->post('endpoint', [
    'json' => [
        'field1' => 'value1',
        'field2' => 'value2'
    ]
]);

// Richiesta POST con form data
$result = $client->post('endpoint', [
    'form_params' => [
        'field1' => 'value1',
        'field2' => 'value2'
    ]
]);
```

### PUT

```php
// Aggiornamento di una risorsa specifica
$result = $client->put('endpoint/123', [
    'json' => [
        'field1' => 'new_value'
    ]
]);
```

### DELETE

```php
// Eliminazione di una risorsa
$result = $client->delete('endpoint/123');
```

## Gestione degli Errori

Il client lancia eccezioni specifiche per ogni tipo di errore:

```php
try {
    $result = $client->get('endpoint');
} catch (\Swotto\Exception\ValidationException $e) {
    // Errore 400: Bad Request
    $errors = $e->getErrorData();
    echo "Errori di validazione: " . json_encode($errors);
} catch (\Swotto\Exception\AuthenticationException $e) {
    // Errore 401: Unauthorized
    echo "Autenticazione richiesta";
} catch (\Swotto\Exception\ForbiddenException $e) {
    // Errore 403: Forbidden
    echo "Accesso negato";
} catch (\Swotto\Exception\NotFoundException $e) {
    // Errore 404: Not Found
    echo "Risorsa non trovata";
} catch (\Swotto\Exception\RateLimitException $e) {
    // Errore 429: Too Many Requests
    $retryAfter = $e->getRetryAfter();
    echo "Troppe richieste. Riprova tra {$retryAfter} secondi";
} catch (\Swotto\Exception\ConnectionException $e) {
    // Errore di connessione
    echo "Impossibile connettersi a: " . $e->getUrl();
} catch (\Swotto\Exception\NetworkException $e) {
    // Errore di rete
    echo "Errore di rete: " . $e->getMessage();
} catch (\Swotto\Exception\ApiException $e) {
    // Altri errori API
    echo "Errore API: " . $e->getMessage();
    echo "Codice: " . $e->getStatusCode();
    echo "Dati: " . json_encode($e->getErrorData());
} catch (\Exception $e) {
    // Altri errori
    echo "Errore generico: " . $e->getMessage();
}
```

## Utilizzo dei Metodi POP

I metodi POP sono shortcut per recuperare dati comuni:

```php
// Recupera clienti
$customers = $client->getCustomerPop([
    'account' => 'true',
    'orderby' => 'name'
]);

// Recupera paesi
$countries = $client->getCountryPop();

// Recupera valute
$currencies = $client->getCurrencyPop();

// Recupera zone di un magazzino specifico
$warehouseId = 123;
$zones = $client->getWarehouseZonePop($warehouseId);
```

## Test di Connettività

Verifica se il servizio API è raggiungibile:

```php
if ($client->checkConnection()) {
    echo "Connessione all'API funzionante";
} else {
    echo "Impossibile connettersi all'API";
}
```

## Verifica Autenticazione

Verifica se le credenziali sono valide:

```php
try {
    $authStatus = $client->checkAuth();
    echo "Autenticazione valida";
} catch (\Swotto\Exception\AuthenticationException $e) {
    echo "Credenziali non valide";
}
```

## Verifica Sessione

Verifica se la sessione è valida:

```php
try {
    $sessionStatus = $client->checkSession();
    echo "Sessione valida: " . $sessionStatus['session_id'];
} catch (\Swotto\Exception\SwottoException $e) {
    echo "Sessione non valida";
}
```

## Cambio Configurazione Runtime

Puoi modificare alcune impostazioni durante l'esecuzione:

```php
// Cambia ID sessione
$client->setSessionId('nuovo-session-id');

// Cambia lingua
$client->setLanguage('en');

// Cambia header Accept
$client->setAccept('application/xml');
```

## Test e Verifica

Il SDK include metodi per verificare connettività e stato:

### Test di Connettività
```php
if ($client->checkConnection()) {
    echo "API raggiungibile";
}
```

### Verifica Autenticazione
```php
try {
    $client->checkAuth();
    echo "Credenziali valide";
} catch (\Swotto\Exception\AuthenticationException $e) {
    echo "Autenticazione fallita";
}
```

## Architettura

### PSR Standards Implementati

- **PSR-3**: Logging interface per tracciamento richieste/risposte
- **PSR-4**: Autoloading standard per namespace `Swotto\`
- **PSR-12**: Code style con PHP CS Fixer per consistenza
- **PSR-18**: HTTP Client interface per dependency injection

### Dependency Injection

```php
use Swotto\Client;
use Swotto\Http\GuzzleHttpClient;
use Monolog\Logger;

// Custom HTTP client implementation
$httpClient = new GuzzleHttpClient($config, $logger);
$client = new Client($config, $logger, $httpClient);
```

### Pattern Utilizzati

- **Strategy Pattern**: `HttpClientInterface` per swappable HTTP implementations
- **Trait Composition**: `PopTrait` per metodi SW4-specific
- **Factory Method**: Configuration validation e object creation

### Configurazione Completa

Tutte le opzioni supportate da `Configuration.php`:

```php
$client = new Client([
    'url' => 'https://api.swotto.it',        // Required: API endpoint
    'key' => 'your-api-key',                 // API authentication key
    'access_token' => 'bearer-token',        // OAuth/Bearer token
    'session_id' => 'session-identifier',   // Session tracking
    'language' => 'it',                      // Response language (it|en)
    'accept' => 'application/json',          // Accept header
    'verify_ssl' => true,                    // SSL certificate verification
    'headers' => [                           // Custom headers
        'X-Custom-Header' => 'value'
    ],
    'client_user_agent' => 'MyApp/1.0',     // Custom User-Agent
    'client_ip' => '192.168.1.1'            // Client IP forwarding
]);
```

## Sviluppo

### Qualità del Codice

Il progetto utilizza diversi strumenti per garantire la qualità del codice:

#### PHP CS Fixer
Formatazione automatica del codice secondo lo standard PSR-12:

```bash
# Esegui la formattazione
composer cs-fix

# Controlla la formattazione (dry run)
composer cs-dry
```

#### PHPStan
Analisi statica del codice per identificare errori potenziali:

```bash
# Esegui l'analisi
composer phpstan
```

#### Test Suite

Suite completa con **57 test** e **148 asserzioni**:

```bash
# Esegui tutti i test
composer test

# Test specifici per componente
vendor/bin/phpunit tests/ClientTest.php
vendor/bin/phpunit tests/ConfigurationTest.php
vendor/bin/phpunit tests/PopTraitTest.php
vendor/bin/phpunit tests/GuzzleHttpClientTest.php
vendor/bin/phpunit tests/ExceptionTest.php

# Test con coverage dettagliata
composer test-coverage
```

**Copertura test per modulo:**
- `ClientTest`: HTTP methods, authentication, configuration
- `ConfigurationTest`: Validation, headers, IP/UserAgent detection  
- `PopTraitTest`: Tutti i metodi POP specifici SW4
- `GuzzleHttpClientTest`: HTTP client con scenari completi
- `ExceptionTest`: Tutte le classi exception custom

### Configurazione VSCode

Per un'esperienza di sviluppo ottimale, il progetto include configurazioni per VSCode:

- Formattazione automatica al salvataggio
- Integrazione con PHP CS Fixer
- Configurazione PHPStan
- Esclusione di file non necessari

### Hooks di Sviluppo

Il progetto è configurato per eseguire automaticamente:

- Formattazione del codice al salvataggio (VSCode)
- Analisi statica con PHPStan
- Test unitari prima del commit (se configurati)

## Performance

### Ottimizzazioni HTTP Client

```php
// Pool di connessioni per high-throughput
$client = new Client([
    'url' => 'https://api.swotto.it',
    'headers' => [
        'Connection' => 'keep-alive',
        'Keep-Alive' => 'timeout=5, max=1000'
    ]
]);
```

### Rate Limiting Management

```php
try {
    $result = $client->get('endpoint');
} catch (\Swotto\Exception\RateLimitException $e) {
    $retryAfter = $e->getRetryAfter();
    sleep($retryAfter);
    // Retry logic implementation
}
```

### Connection Pooling Best Practices

- Riutilizza istanze `Client` per múltiple richieste
- Configura timeout appropriati per environment di produzione
- Implementa retry logic exponential backoff per resilienza

## Troubleshooting

### Debug Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('swotto-debug');
$logger->pushHandler(new StreamHandler('debug.log', Logger::DEBUG));

$client = new Client($config, $logger);
```

### Problemi Comuni

**SSL Certificate Verification Error**
```php
// Temporary fix per development (NON usare in production)
$client = new Client(['url' => 'https://api.swotto.it', 'verify_ssl' => false]);
```

**Connection Timeout**
```php
// Aumenta timeout per API lente
$client = new Client([
    'url' => 'https://api.swotto.it',
    'headers' => ['timeout' => 30]
]);
```

**Memory Issues con Large Datasets**
```php
// Usa pagination per dataset grandi
$customers = $client->getCustomerPop([
    'limit' => 100,
    'offset' => 0
]);
```

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md) per la storia completa delle release.

## Migration Guide

### Da v1.x a v2.0.0

**✅ Zero Breaking Changes**: Il codice v1.x funziona identicamente in v2.0.0.

#### Upgrade Immediate
```bash
# Update version in composer.json
composer require agenziasmart/swotto:v2.0.0
```

#### Gradual Adoption - Level 1: getParsed() Methods
```php
// Replace manual parsing with getParsed()
// BEFORE
$response = $client->get('customers', ['query' => $query]);
$parsed = $this->parseDataResponse($response);
$data = ['data' => $parsed['data'], 'paginator' => $parsed['paginator']];

// AFTER  
$data = $client->getParsed('customers', ['query' => $query]);
```

#### Gradual Adoption - Level 2: Smart Caching
```php
// Add optional caching
$cache = new \Symfony\Component\Cache\Adapter\ArrayAdapter();
$client = new Client(['url' => $url, 'key' => $key], null, null, $cache);

// All POP functions now auto-cached
$countries = $client->getCountryPop();  // HTTP + cache
$countries = $client->getCountryPop();  // Cache hit!
```

#### Gradual Adoption - Level 3: Full Enterprise (Future)
```php
// Future v2.1 features (non-breaking additions)
$client = new Client([
    'url' => $url,
    'key' => $key,
    'retry' => ['max_attempts' => 3],        // Smart retry
    'circuit_breaker' => true                // Circuit breaker
], null, null, $cache, $events);
```

### Deprecated Features
**Nessuna**: v2.0.0 non depreca alcuna feature esistente.

### Performance Improvements
- ✅ **87.5% less boilerplate** con getParsed() methods
- ✅ **Smart caching** automatic per dati statici  
- ✅ **Zero overhead** se cache/events non iniettati
- ✅ **Same performance** per codice v1.x esistente

## Changelog

Vedi [CHANGELOG.md](CHANGELOG.md) per la storia completa delle release.

### v2.0.0 - 🚀 Enterprise-Ready Release

#### 🚀 **New Features**
- **getParsed() Methods**: Auto-parsing per tutti i metodi HTTP (GET, POST, PATCH, PUT, DELETE)
- **Smart Caching**: Auto-caching endpoint statici con PSR-16 compatibility
- **Modern PHP 8.1+**: PSR standards + typed properties + progressive enhancement
- **Enterprise Foundation**: Architecture ready per Circuit Breaker, Smart Retry, Rate Limiting

#### ✨ **Enhancements** 
- **87.5% Boilerplate Reduction**: Da 8 linee a 1 linea per response parsing
- **Progressive Enhancement**: Features enterprise solo se iniettate (null = no overhead)
- **PSR-16 Simple Cache**: Support per Redis, Memcached, Array, File cache
- **PSR-14 Event Dispatcher**: Foundation per monitoring e telemetry

#### 🛡️ **Backward Compatibility**
- **100% Compatible**: Zero breaking changes, codice v1.x funziona identicamente
- **Constructor Enhancement**: Parametri addizionali optional, signature esistente unchanged
- **Method Preservation**: Tutti i metodi esistenti funzionano senza modifiche

#### 📊 **Quality Assurance**
- **64 tests, 181 assertions**: Full test coverage per nuove features
- **PSR12 Compliance**: Code style standards maintained
- **Static Analysis**: PHPStan level 8 compatibility
- **Enterprise Architecture**: Foundation solida per future features

### v1.3.0 - Highlights

- **PHP CS Fixer**: Implementato per mantenere uno stile di codice coerente
- **PHPStan**: Analisi statica livello 8 per maggiore sicurezza del codice
- **Test Suite**: Copertura completa con 57 test e 148 asserzioni
- **Configurazione VSCode**: Setup ottimizzato per lo sviluppo
- **Gestione Eccezioni**: Migliorata la gestione delle eccezioni con costruttori standardizzati
