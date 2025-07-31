<?php

declare(strict_types=1);

require_once 'vendor/autoload.php';

use Swotto\Client;
use Swotto\Config\Configuration;
use Swotto\Exception\AuthenticationException;
use Swotto\Exception\SwottoException;

echo "🔬 Test Autenticazione Endpoints SW4\n";
echo "=====================================\n\n";

// Configurazione con credenziali VOLUTAMENTE SBAGLIATE per testare gli errori
$config = new Configuration([
    'url' => 'https://api.sw4.test/api/v1/',  // URL placeholder
    'key' => 'devapp_token_invalid_test',
]);

$client = new Client($config);

// Test 1: Endpoint /auth
echo "📡 Test 1: Endpoint /auth\n";
echo "------------------------\n";
try {
    $authStatus = $client->checkAuth();
    echo "✅ Auth OK: " . json_encode($authStatus, JSON_PRETTY_PRINT) . "\n";
    
} catch (AuthenticationException $e) {
    echo "🔴 AUTH FALLITA (AuthenticationException)\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "HTTP Code: " . $e->getCode() . "\n";
    
    // 🎯 Dati completi SW4
    $errorData = $e->getErrorData();
    echo "\n📋 SW4 Error Data Completo:\n";
    echo json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
    
    // Parsing dettagli specifici
    if (isset($errorData['error']['details'])) {
        echo "\n🔍 Dettagli Campo per Campo:\n";
        foreach ($errorData['error']['details'] as $field => $message) {
            echo "  - {$field}: {$message}\n";
        }
    }
    
    if (isset($errorData['error']['type'])) {
        echo "\n📊 Tipo Errore SW4: " . $errorData['error']['type'] . "\n";
    }
    
    if (isset($errorData['timestamp'])) {
        echo "⏰ Timestamp: " . date('Y-m-d H:i:s', $errorData['timestamp']) . "\n";
    }
    
} catch (SwottoException $e) {
    echo "🔴 ERRORE GENERICO (SwottoException)\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Error Data: " . json_encode($e->getErrorData(), JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "❌ ERRORE NON PREVISTO: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Test 2: Endpoint /session  
echo "📡 Test 2: Endpoint /session\n";
echo "---------------------------\n";
try {
    $sessionStatus = $client->checkSession();
    echo "✅ Session OK: " . json_encode($sessionStatus, JSON_PRETTY_PRINT) . "\n";
    
} catch (AuthenticationException $e) {
    echo "🔴 SESSION FALLITA (AuthenticationException)\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "HTTP Code: " . $e->getCode() . "\n";
    
    // 🎯 Dati completi SW4
    $errorData = $e->getErrorData();
    echo "\n📋 SW4 Error Data Completo:\n";
    echo json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
    
    // Parsing dettagli specifici
    if (isset($errorData['error']['details'])) {
        echo "\n🔍 Dettagli Campo per Campo:\n";
        foreach ($errorData['error']['details'] as $field => $message) {
            echo "  - {$field}: {$message}\n";
        }
    }
    
} catch (SwottoException $e) {
    echo "🔴 ERRORE GENERICO (SwottoException)\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "Error Data: " . json_encode($e->getErrorData(), JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "❌ ERRORE NON PREVISTO: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🏁 Test completato!\n";
echo "\n📝 Come usare questo test:\n";
echo "  1. Configura URL reale SW4 se necessario\n";
echo "  2. Usa credenziali sbagliate per vedere errori\n"; 
echo "  3. Usa credenziali valide per vedere successo\n";
echo "  4. Osserva come getErrorData() preserva tutti i dettagli SW4\n";