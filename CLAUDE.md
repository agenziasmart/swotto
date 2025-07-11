# Swotto PHP SDK

## Informazioni del Progetto

**Nome**: Swotto PHP SDK
**Descrizione**: Libreria PHP creata per facilitare l'uso delle API SW4 in progetti PHP
**Versione**: 1.2.0
**Tipo**: Libreria PHP

## Struttura del Progetto

- **API SW4**: Le API principali si trovano in `/var/www/agenziasmart/sw4/api`
- **Swotto SDK**: Questa libreria (`/var/www/agenziasmart/sw4/swotto`) fornisce un'interfaccia PHP semplificata per utilizzare le API SW4

## Scopo

La libreria Swotto è stata sviluppata per:
- Semplificare l'integrazione delle API SW4 in progetti PHP
- Fornire un'interfaccia type-safe e ben documentata
- Gestire automaticamente l'autenticazione e le richieste HTTP
- Offrire un SDK completo per tutti i servizi SW4

## Dipendenze

- PHP >= 8.1
- Guzzle HTTP Client
- PSR interfaces per logging e HTTP

## Comandi Utili

- `composer test` - Esegue i test PHPUnit
- `composer cs` - Verifica lo stile del codice (PSR12)
- `composer phpstan` - Analisi statica del codice