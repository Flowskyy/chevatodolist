<?php
header('Content-Type: application/json');

$UPSTASH_URL = getenv('UPSTASH_REDIS_REST_URL');
$UPSTASH_TOKEN = getenv('UPSTASH_REDIS_REST_TOKEN');

function redisCommand($command, $args = []) {
    global $UPSTASH_URL, $UPSTASH_TOKEN;
    
    $data = array_merge([$command], $args);
    
    $ch = curl_init($UPSTASH_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $UPSTASH_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'http_code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

$action = $_GET['action'] ?? 'info';

switch ($action) {
    case 'info':
        echo json_encode([
            'url_set' => !empty($UPSTASH_URL),
            'token_set' => !empty($UPSTASH_TOKEN),
            'url' => $UPSTASH_URL,
            'token_preview' => substr($UPSTASH_TOKEN, 0, 10) . '...'
        ], JSON_PRETTY_PRINT);
        break;
        
    case 'ping':
        $result = redisCommand('PING');
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;
        
    case 'get':
        $result = redisCommand('GET', ['tasks']);
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;
        
    case 'set':
        $testData = [
            ['id' => 1, 'task_name' => 'Test Task', 'list_order' => 1, 'is_completed' => false]
        ];
        $result = redisCommand('SET', ['tasks', json_encode($testData)]);
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;
        
    case 'delete':
        $result = redisCommand('DEL', ['tasks']);
        echo json_encode($result, JSON_PRETTY_PRINT);
        break;
        
    default:
        echo json_encode([
            'available_actions' => [
                'info' => '?action=info',
                'ping' => '?action=ping',
                'get' => '?action=get',
                'set' => '?action=set (creates test data)',
                'delete' => '?action=delete (clears tasks)'
            ]
        ], JSON_PRETTY_PRINT);
}
?>