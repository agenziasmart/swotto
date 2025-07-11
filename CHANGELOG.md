# Changelog

Tutte le modifiche significative a questo progetto verranno documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
e questo progetto aderisce al [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2025-07-11

### Added
- **PHP CS Fixer**: Integrato per formattazione automatica del codice secondo PSR-12
- **PHPStan**: Analisi statica del codice a livello 8 senza errori
- **Test Coverage Completa**: 57 test con 148 asserzioni
  - `ConfigurationTest`: Test per validazione configurazione, headers, IP/UserAgent detection
  - `PopTraitTest`: Test per tutti i metodi POP specifici SW4
  - `ExceptionTest`: Test per tutte le classi exception
  - `GuzzleHttpClientTest`: Test per HTTP client con scenari completi
- **VSCode Integration**: Configurazione per auto-fix on save
- **Nuovi script Composer**: `cs-fix`, `cs-dry` per formattazione codice

### Changed
- **Codice Formattato**: Tutto il codice ora segue PSR-12 con formattazione automatica
- **Type Hints**: Migliorati type hints in tutto il codebase
- **Exception Handling**: Costruttori delle exception standardizzati
- **Configuration**: Aggiunte chiavi `client_user_agent` e `client_ip`

### Technical Details
- Aggiornata versione SDK da 1.2.0 a 1.3.0
- Rimossa costante `AUTHOR` non utilizzata da `GuzzleHttpClient`
- Corretta gestione null safety in `GuzzleHttpClient`
- Migliorata configurazione PHPStan con gestione deprecations
- Nessuna breaking change: tutti i metodi esistenti rimangono invariati

### Development Tools
- **PHP CS Fixer**: Configurato con regole avanzate PSR-12
- **PHPStan**: Level 8 con configurazione personalizzata
- **PHPUnit**: 57 test completamente funzionanti
- **VSCode**: Auto-formattazione on save configurata

## [1.2.0] - 2025-06-30

### Added
- Supporto completo per metodi HTTP PUT
- Il metodo `put()` è ora disponibile nell'interfaccia `ClientInterface` e implementato nella classe `Client`
- Gestione completa dei metodi PUT attraverso `GuzzleHttpClient`

### Changed
- Aggiornata versione SDK da 1.1.0 a 1.2.0
- Aggiornata versione interna in `GuzzleHttpClient` da 1.0.9 a 1.2.0

### Technical Details
- Il metodo PUT era già presente nell'interfaccia e implementazione
- Nessuna breaking change: tutti i metodi esistenti rimangono invariati
- Compatibile con tutte le versioni precedenti dell'SDK

## [1.1.0] - Precedente

### Features
- Supporto per metodi GET, POST, PATCH, DELETE
- Gestione autenticazione e sessioni
- Configurazione flessibile
- Logging integrato
- Gestione errori completa
