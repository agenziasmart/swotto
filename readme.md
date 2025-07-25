# Swotto PHP SDK

![Version](https://img.shields.io/badge/version-v1.3.0-blue.svg)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PSR-12](https://img.shields.io/badge/code%20style-PSR--12-orange.svg)
![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)

Libreria PHP per l'integrazione con le API SW4, fornisce un'interfaccia type-safe e completa per tutti i servizi SW4.

## Indice

- [Quick Start](#quick-start)
- [Installazione](#installazione)
- [Configurazione Base](#configurazione-base)
- [Metodi HTTP di base](#metodi-http-di-base)
- [Gestione degli Errori](#gestione-degli-errori)
- [Utilizzo dei Metodi POP](#utilizzo-dei-metodi-pop)
- [Test e Verifica](#test-e-verifica)
- [Architettura](#architettura)
- [Sviluppo](#sviluppo)
- [Performance](#performance)
- [Troubleshooting](#troubleshooting)
- [Changelog](#changelog)

## Quick Start

```php
use Swotto\Client;

// Configurazione minimal
$client = new Client(['url' => 'https://api.swotto.it']);

// Esempio di utilizzo
$customers = $client->getCustomerPop();
$result = $client->get('endpoint', ['query' => ['param' => 'value']]);
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
composer require agenziasmart/swotto:v1.3.0

# Range di versioni compatibili
composer require agenziasmart/swotto:^v1.3
```

### Configurazione composer.json

```json
{
    "require": {
        "agenziasmart/swotto": "v1.3.0"
    }
}
```

### Versioning e Release

Il progetto segue [Semantic Versioning](https://semver.org/):
- **v1.3.0**: Release corrente con test suite completa, PHPStan level 8
- **v1.2.0**: Aggiunto supporto metodi HTTP PUT
- **v1.0.x**: Versioni legacy

Tags disponibili: `git tag --list --sort=-version:refname`

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

### v1.3.0 - Highlights

- **PHP CS Fixer**: Implementato per mantenere uno stile di codice coerente
- **PHPStan**: Analisi statica livello 8 per maggiore sicurezza del codice
- **Test Suite**: Copertura completa con 57 test e 148 asserzioni
- **Configurazione VSCode**: Setup ottimizzato per lo sviluppo
- **Gestione Eccezioni**: Migliorata la gestione delle eccezioni con costruttori standardizzati
