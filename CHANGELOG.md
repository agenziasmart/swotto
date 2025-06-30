# Changelog

Tutte le modifiche significative a questo progetto verranno documentate in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
e questo progetto aderisce al [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
