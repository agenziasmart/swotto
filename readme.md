# Guida all'Implementazione di Swotto Client v1.2.0

Questa guida descrive i passaggi per implementare e utilizzare il client Swotto v1.2.0 nella tua applicazione PHP.

## Installazione

### Requisiti

- PHP 8.1 o superiore
- Composer
- ext-json

### Aggiungere la dipendenza

Aggiungi il client Swotto al tuo progetto tramite Composer:

```bash
composer require agenziasmart/swotto
```

In alternativa, puoi aggiungere manualmente la dipendenza al tuo file `composer.json`:

```json
{
    "require": {
        "agenziasmart/swotto": "^1.2"
    }
}
```

E quindi eseguire:

```bash
composer update
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
