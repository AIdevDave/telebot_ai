<?php

// Your telegram bot token
$botToken = 'telegram bot token'; 
// Your chatID if you have it.
$chatId = '';
// db file name
$db = new SQLite3('telebot_ai.db');

// LLMs
// Config
$config['api'] = 'openai'; // openai, mistral, ollama

$config['openai']['apikey'] = 'openai api key';
$config['openai']['url'] = 'https://api.openai.com/v1/chat/completions';
$config['openai']['model'] = 'gpt-3.5-turbo-0125';
$config['openai']['gpt-3.5-turbo-0125']['input'] = '0.0005';
$config['openai']['gpt-3.5-turbo-0125']['output'] = '0.0015';

//gpt-3.5-turbo-0125	input $0.0005 / 1K tokens	output $0.0015 / 1K tokens

$config['mistral']['apikey'] = 'mistral api key';
$config['mistral']['url'] = 'https://api.mistral.ai/v1/chat/completions';
$config['mistral']['model'] = 'mistral-tiny';
$config['mistral']['mistral-tiny']['input'] = '0.00014';
$config['mistral']['mistral-tiny']['output'] = '0.00042';

//mistral-tiny	    0.14€ / 1M tokens	0.42€ / 1M tokens
//mistral-small	    0.6€ / 1M tokens	1.8€ / 1M tokens
//mistral-medium	2.5€ / 1M tokens	7.5€ / 1M tokens

$config['ollama']['apikey'] = 'none';
$config['ollama']['url'] = 'http://127.0.0.1:11434/v1/chat/completions';
$config['ollama']['model'] = 'tinydolphin'; 
$config['ollama']['tinydolphin']['input'] = '0';
$config['ollama']['tinydolphin']['output'] = '0';
/*
//qwen:0.5b-chat

$config['ollama']['model'] = 'qwen:0.5b-chat'; 
$config['ollama']['qwen:0.5b-chat']['input'] = '0';
$config['ollama']['qwen:0.5b-chat']['output'] = '0';

$config['ollama']['model'] = 'dolphin-mistral:7b-v2.6-q2_K'; 
$config['ollama']['dolphin-mistral:7b-v2.6-q2_K']['input'] = '0';
$config['ollama']['dolphin-mistral:7b-v2.6-q2_K']['output'] = '0';
*/
// Default config values
$config['client']['history'] = 3; // set 0 for no history, on openai or mistral is input tokens paid so I recommend 3 unless you need extensive history.
$config['client']['showcost'] = 1; // 1 enable 0 disabled show cost after every message
$config['client']['debug'] = 1; // 1 enable 0 disabled show debug messages in error log and also in telegram
$config['client']['apilog'] = 1; // 1 enable 0 disabled log all api calls to db for analysis

//// Debug code is at very bottom of the script. Do not change anything in between unless you know what you are doing.

// Create tables
$db->exec('
    CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        datetime TEXT NOT NULL,
        messageid INTEGER NOT NULL,
        chatid INTEGER NOT NULL,
        request TEXT,
        reply TEXT,
        status INTEGER
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS stats (
        date DATE PRIMARY KEY,
        received INTEGER,
        sent INTEGER,
        command INTEGER,
        prompt_tokens INTEGER,
        completion_tokens INTEGER,
        total_tokens INTEGER
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS apilogs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
        api_id TEXT,
        model TEXT,
        usage_prompt_tokens INTEGER,
        usage_total_tokens INTEGER,
        usage_completion_tokens INTEGER,
        time_taken INTEGER,
        request_payload TEXT,
        response_payload TEXT
    )
');
$db->exec('
    CREATE TABLE IF NOT EXISTS config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        chatid INTEGER,
        type TEXT,
        variable TEXT,
        value TEXT
    )
');

function openaiapi($messages) {
    global $config , $chatId;

    $api=$config['api'];
    $apiurl=$config[$api]['url'];
    $apikey=$config[$api]['apikey'];
    $apimodel=$config[$api]['model'];

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey
    );

    $data = array( 
        'model' => $apimodel,
        "stream" => false,
        'messages' => $messages
    );

    // Debug
    showdebug($data, $chatId, 'api data');

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $apiurl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Start the timer
    $startTime = microtime(true);

    $response = curl_exec($ch);

    // End the timer
    $endTime = microtime(true);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return "cURL Error: $error";
    }

    $responseData = json_decode($response, true);
    curl_close($ch);

    // Check if the response is empty
    if (empty($responseData)) {
        return "API returned an empty response.";
    }

    // Calculate the time taken
    $timeTaken = round($endTime - $startTime, 3)* 1000;
    
    // Add the time taken to the response data
    $responseData['time_taken'] = $timeTaken;

    // Api log
    apilog($data, $responseData);

    return $responseData;
}

function makecontext($system, $request, $history) {
    global $db, $chatId;

    $context = array();
    $context[] = array(
        'role' => 'system',
        'content' => $system
    );

    if ($history > 0) {
        $messages = $db->query("SELECT * FROM (SELECT * FROM messages WHERE chatid = '$chatId' and status in (3) ORDER BY messageid DESC LIMIT $history) AS subquery ORDER BY messageid ASC LiMIT $history");

        while ($message = $messages->fetchArray(SQLITE3_ASSOC)) {
            $context[] = array(
                'role' => 'user', 
                'content' => $message['request']
            );

            $context[] = array(
                'role' => 'assistant', 
                'content' => $message['reply']
            );
        }
    }

    $context[] = array(
        'role' => 'user',
        'content' => $request
    );

    return $context;
    
}

function apilog($api_request, $api_response) {
    global $db, $config;

    if($config['client']['apilog']==1){
        // Prepare data for insertion
        $api_id = $api_response['id'];
        $model = $api_response['model'];
        $usage_prompt_tokens = $api_response['usage']['prompt_tokens'];
        $usage_total_tokens = $api_response['usage']['total_tokens'];
        $usage_completion_tokens = $api_response['usage']['completion_tokens'];
        $time_taken = $api_response['time_taken'];
        $request_payload = base64_encode(serialize($api_request));
        $response_payload = base64_encode(serialize($api_response)); 
        
        // Insert data into the table
        $insert_query = "
            INSERT INTO apilogs (api_id, model, usage_prompt_tokens, usage_total_tokens, usage_completion_tokens, time_taken, request_payload, response_payload)
            VALUES (:api_id, :model, :usage_prompt_tokens, :usage_total_tokens, :usage_completion_tokens, :time_taken, :request_payload, :response_payload)";
        $stmt = $db->prepare($insert_query);
        $stmt->bindParam(':api_id', $api_id);
        $stmt->bindParam(':model', $model);
        $stmt->bindParam(':usage_prompt_tokens', $usage_prompt_tokens);
        $stmt->bindParam(':usage_total_tokens', $usage_total_tokens);
        $stmt->bindParam(':usage_completion_tokens', $usage_completion_tokens);
        $stmt->bindParam(':time_taken', $time_taken);
        $stmt->bindParam(':request_payload', $request_payload);
        $stmt->bindParam(':response_payload', $response_payload);
        $stmt->execute();
    }
}

function config($chatId) {
    global $db, $config;

    $stmt = $db->prepare('SELECT * FROM config WHERE type = :type AND chatid = :chatid');
    $stmt->bindValue(':chatid', $chatId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', 'client', SQLITE3_TEXT);
    $result = $stmt->execute();

    $dbConfigValues = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $dbConfigValues[$row['variable']] = $row['value'];
    }

    foreach ($config['client'] as $key => $value) {
        if (!array_key_exists($key, $dbConfigValues)) {
            // Insert default value for this key directly into $config
            $stmt = $db->prepare('INSERT INTO config (chatid, type, variable, value) VALUES (:chatid, :type, :variable, :value)');
            $stmt->bindValue(':chatid', $chatId, SQLITE3_INTEGER);
            $stmt->bindValue(':type', 'client', SQLITE3_TEXT);
            $stmt->bindValue(':variable', $key, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);
            $stmt->execute();

            $dbConfigValues[$key] = $value;
        }
    }

    // Load config values from db into $config
    $config['client'] = array_merge($config['client'], $dbConfigValues);
}

function showcost($response,$time,$chatId) {
    global $config;

    $api=$config['api'];
    $apimodel=$config[$api]['model'];
    $inputPrice = $config[$api][$apimodel]['input'] / 1000; // Cost per prompt token
    $outputPrice = $config[$api][$apimodel]['output'] / 1000; // Cost per completion token

    $output = ($response['completion_tokens'] * $outputPrice);
    $input = ($response['prompt_tokens'] * $inputPrice);
    $totalcost = ($response['prompt_tokens'] * $inputPrice) + ($response['completion_tokens'] * $outputPrice);

    if ($config['client']['showcost']==1) {
        // sendMessage("<pre>Input tokens: " . $response['prompt_tokens'] . " Output tokens: " . $response['completion_tokens'] . " Total: " . ($response['prompt_tokens'] + $response['completion_tokens']) . "\nInput cost: " . $input . " Output cost: " . $output . " Total Cost: " . $totalcost ."</pre>", $chatId);
        sendMessage("<pre>Model        : " . $apimodel . " \nInput price  : " . $config[$api][$apimodel]['input'] . " / 1k \nOutput price : " . $config[$api][$apimodel]['output'] ." / 1k".
        "\nInput tokens : " . str_pad($response['prompt_tokens'], 5, ' ', STR_PAD_LEFT) . " | ". str_pad(round($input,7), 8, ' ', STR_PAD_LEFT) . 
        "\nOutput tokens: " . str_pad($response['completion_tokens'], 5, ' ', STR_PAD_LEFT) . " | ".  str_pad(round($output,7), 8, ' ', STR_PAD_LEFT) . 
        "\nTotal tokens : " . str_pad(($response['prompt_tokens'] + $response['completion_tokens']), 5, ' ', STR_PAD_LEFT) . " | ".  str_pad(round($totalcost,7), 8, ' ', STR_PAD_LEFT) . 
        "\nTime taken   : " . round(($time / 1000), 2) . "s </pre>", $chatId);
    }
}

function showdebug($response,$chatId,$type=NULL) {
    global $config;
    if (is_null($type)) {
        $type = 'Debug :';
    }else {
        $type = 'Debug ' . $type . ' :';
    }
    if ($config['client']['debug']==1) {
        sendMessage("<pre> $type" . print_r($response, true) . "</pre>", $chatId);
        error_log( $type . print_r($response, true));
    }
    
}

function sendMessage($messageText, $chatId) {
    global $botToken;

    // Default API URL for text messages
    $apiUrl = "https://api.telegram.org/bot$botToken/sendMessage";
    $data = ['chat_id' => $chatId, 'parse_mode' => 'HTML'];

    if (!empty($messageText)) {
        // Send text only
        $data['text'] = $messageText;
    } else {
        return "Error: No message provided.";
    }

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);
    curl_close($ch);

    // stats
    stats('sent');
    
    if ($response === false) {
        
        error_log("Error sending message: " . curl_error($ch));
        return "Error sending message: " . curl_error($ch);
    } else {
        $responseData = json_decode($response, true);
        if ($responseData['ok']) {
            return "Message sent successfully!";
        } else {
            error_log("Error sending message: " . $responseData['description']);
            return "Error sending message: " . $responseData['description'];
        }
    }
}

function stats($type,$tokens=NULL) {
    global $db;

    $currentDate = date('Y-m-d');

    $checkQuery = $db->query("SELECT * FROM stats WHERE date = '$currentDate'");
    $existingRecord = $checkQuery->fetchArray(SQLITE3_ASSOC);

    $updateValues = "";
    $insertValues = "'$currentDate', 0, 0, 0, 0, 0"; 

    switch ($type) {
        case 'received':
            $updateValues = "received = received + 1";
            break;
        case 'sent':
            $updateValues = "sent = sent + 1";
            break;
        case 'command':
            $updateValues = "command = command + 1";
            break;
        case 'prompt_tokens':
            $updateValues = "prompt_tokens = prompt_tokens + $tokens";
            break;
        case 'completion_tokens':
            $updateValues = "completion_tokens = completion_tokens + $tokens";
            break;
        case 'total_tokens':
            $updateValues = "total_tokens = total_tokens + $tokens";
            break;
        default:
            // Invalid type ignored
        return;
    }

    if ($existingRecord) {
        $query = "UPDATE stats SET $updateValues WHERE date = '$currentDate'";
    } else {
        $query = "INSERT INTO stats (date, received, sent, prompt_tokens, completion_tokens, total_tokens) VALUES ($insertValues)";
    }

    $db->exec($query);
}

function getStats() {
    global $db, $config;

    $api=$config['api'];
    $apimodel=$config[$api]['model'];

    // Get total statistics
    $totalQuery = $db->query("SELECT SUM(received) AS received, SUM(sent) AS sent, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens FROM stats");
    $totalStats = $totalQuery->fetchArray(SQLITE3_ASSOC);

    // Get yearly statistics
    $currentYear = date('Y');
    $yearQuery = $db->query("SELECT SUM(received) AS received, SUM(sent) AS sent, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens FROM stats WHERE strftime('%Y', date) = '$currentYear'");
    $yearStats = $yearQuery->fetchArray(SQLITE3_ASSOC);

    // Get monthly statistics
    $currentMonth = date('Y-m');
    $monthQuery = $db->query("SELECT SUM(received) AS received, SUM(sent) AS sent, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens FROM stats WHERE strftime('%Y-%m', date) = '$currentMonth'");
    $monthStats = $monthQuery->fetchArray(SQLITE3_ASSOC);

    // Get yesterday's statistics
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $yesterdayQuery = $db->query("SELECT SUM(received) AS received, SUM(sent) AS sent, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens FROM stats WHERE date = '$yesterday'");
    $yesterdayStats = $yesterdayQuery->fetchArray(SQLITE3_ASSOC);

    // Get today's statistics
    $currentDate = date('Y-m-d');
    $todayQuery = $db->query("SELECT SUM(received) AS received, SUM(sent) AS sent, SUM(prompt_tokens) AS prompt_tokens, SUM(completion_tokens) AS completion_tokens FROM stats WHERE date = '$currentDate'");
    $todayStats = $todayQuery->fetchArray(SQLITE3_ASSOC);

    // Calculate costs
    $inputPrice = $config[$api][$apimodel]['input'] / 1000; // Cost per prompt token
    $outputPrice = $config[$api][$apimodel]['output'] / 1000; // Cost per completion token

    $totalCost = ($totalStats['prompt_tokens'] * $inputPrice) + ($totalStats['completion_tokens'] * $outputPrice);
    $yearCost = ($yearStats['prompt_tokens'] * $inputPrice) + ($yearStats['completion_tokens'] * $outputPrice);
    $monthCost = ($monthStats['prompt_tokens'] * $inputPrice) + ($monthStats['completion_tokens'] * $outputPrice);
    $yesterdayCost = ($yesterdayStats['prompt_tokens'] * $inputPrice) + ($yesterdayStats['completion_tokens'] * $outputPrice);
    $todayCost = ($todayStats['prompt_tokens'] * $inputPrice) + ($todayStats['completion_tokens'] * $outputPrice);

    // Build stats table
    $stats = "<pre>Date | Rec | Sent| Reque | Reply | Total | Cost
-----|-----|-----|-------|-------|-------|------
Total|" . str_pad($totalStats['received'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($totalStats['sent'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($totalStats['prompt_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($totalStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($totalStats['prompt_tokens'] + $totalStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad(number_format($totalCost, 4), 5, ' ', STR_PAD_LEFT) . "
Year |" . str_pad($yearStats['received'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($yearStats['sent'], 5, ' ', STR_PAD_LEFT) . "|"  . str_pad($yearStats['prompt_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($yearStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($yearStats['prompt_tokens'] + $yearStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad(number_format($yearCost, 4), 5, ' ', STR_PAD_LEFT) . "
Month|" . str_pad($monthStats['received'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($monthStats['sent'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($monthStats['prompt_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($monthStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($monthStats['prompt_tokens'] + $monthStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad(number_format($monthCost, 4), 5, ' ', STR_PAD_LEFT) . "
Yeste|" . str_pad($yesterdayStats['received'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($yesterdayStats['sent'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($yesterdayStats['prompt_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($yesterdayStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($yesterdayStats['prompt_tokens'] + $yesterdayStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad(number_format($yesterdayCost, 4), 5, ' ', STR_PAD_LEFT) . "
Today|" . str_pad($todayStats['received'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($todayStats['sent'], 5, ' ', STR_PAD_LEFT) . "|" . str_pad($todayStats['prompt_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($todayStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad($todayStats['prompt_tokens'] + $todayStats['completion_tokens'], 7, ' ', STR_PAD_LEFT) . "|" . str_pad(number_format($todayCost, 4), 5, ' ', STR_PAD_LEFT) . "
</pre>";

    return $stats;
}

function handleIncomingMessage($update) {
    global $db, $botToken;

    $chatId = $update['message']['chat']['id'];
    $messageId = $update['message']['message_id'];

    $existingMessage = $db->querySingle("SELECT messageid FROM messages WHERE messageid = $messageId");

    // Stats for received messages
    stats('received');

    if (!$existingMessage) {
        $datetime = date('Y-m-d H:i:s');

        if (isset($update['message']['text'])) {
            // If it's a text message
            $messageText = $db->escapeString($update['message']['text']);
            $db->exec("INSERT INTO messages (datetime, messageid, chatid, request, reply, status) VALUES ('$datetime', $messageId, $chatId, '$messageText', '', 1)");

        } else {
            // Placeholder for other types of messages
            $sendResult = sendMessage('This input is not implemented yet.', $chatId);
        }
    } else {
        // The message has already been processed
    }
}

function processUnprocessedMessages() {
    global $db, $config;

    $lockFilePath = '/tmp/telebot_ai.lock';
    $lockFile = fopen($lockFilePath, 'w');

    if (!$lockFile) {
        echo "Error opening lock file.\n";
        exit;
    }

    if (!flock($lockFile, LOCK_EX | LOCK_NB)) {
        echo "Script is already running. Exiting.\n";
        fclose($lockFile);
        exit;
    }

    $query = $db->query("SELECT * FROM messages WHERE status = 1 ORDER BY messageid ASC");

    while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
        $messageId = $row['messageid'];
        $request = $row['request'];
        $chatId = $row['chatid']; 

        // Load chat IDs config before using default values
        config($chatId);

        // Commands from chat
        if (strpos($request, '/') === 0) {
            // Check if the request starts with '/'
            $command = explode(' ', substr($request, 1));
            //stats
            stats('command');
            // Handle commands
            if ($command[0] === 'help') {
                // Display help message
                $message = "Available commands:
/help - Display this help message
/stats - Display bot statistics
/version or /v - Display version information
/showdebug - Display debug information (".($config['client']['debug']==1 ? 'enabled' : 'disabled').")
/showcost - Display cost information (".($config['client']['showcost']==1 ? 'enabled' : 'disabled').")
/apilog - Enable or disable API log (".($config['client']['apilog']==1 ? 'enabled' : 'disabled').")
/context 0-10 - How many messages sent as context (".$config['client']['history'].")
/delete history - Delete all messages
/delete all - Delete all messages
/delete last - Delete last message
/ignore or /i - Bot will ignore text from user";
                sendMessage($message, $chatId);
                $db->exec("UPDATE messages SET status = 4 WHERE messageid = $messageId");

            } elseif (($command[0] === 'delete' && ($command[1] === 'history' || $command[1] === 'all' || $command[1] === 'last')) || ($command[0]==='delete' && $command[1] === NULL)) {
                // Delete history or last message
                if($command[1] === 'history' || $command[1] === 'all') {
                    $db->exec("UPDATE messages SET status = 9");
                    sendMessage('All messages deleted', $chatId);
                } elseif ($command[1] === 'last' || $command[1] === NULL) {
                    //not finished yet
                    $lastmesssageid=$db->querySingle("SELECT messageid FROM messages ORDER BY messageid DESC LIMIT 1");
                    $db->exec("UPDATE messages SET status = 9 WHERE messageid = $lastmesssageid");
                    sendMessage('Last message deleted', $chatId);
                }
                
            } elseif ($command[0] === 'version'||$command[0] === 'v' ) {
                // Display version information
                $api=$config['api'];
                $apimodel=$config[$api]['model'];
                $inputPrice = $config[$api][$apimodel]['input']; 
                $outputPrice = $config[$api][$apimodel]['output']; 
                $message = "Model          : ".$apimodel."  
API               : ".$api."
Cost per 1k : input $".$inputPrice." output $".$outputPrice."";
                sendMessage($message, $chatId);
                $db->exec("UPDATE messages SET status = 4 WHERE messageid = $messageId");

            } elseif ($command[0] === 'ignore' || $command[0] === 'i') {
                // Ignore text from user
                $db->exec("UPDATE messages SET status = 4 WHERE messageid = $messageId");
                
            } elseif ($command[0] === 'stats') {
                // Display bot statistics
                $stats = getStats();
                sendMessage($stats, $chatId);
                $db->exec("UPDATE messages SET reply = 'command processed', status = 4 WHERE messageid = $messageId");

            } elseif ($command[0] === 'new') {
                // Start a new conversation
                sendMessage('New conversation started', $chatId);
                $db->exec("UPDATE messages SET status = 9");

            } elseif ($command[0] === 'showdebug') {
                // toggle debug mode
                if ($config['client']['debug']==1) { $config['client']['debug']=0; } else { $config['client']['debug']=1; }
                sendMessage('Debug '.($config['client']['debug']==1 ? 'enabled' : 'disabled'), $chatId);
                $db->exec("UPDATE config SET value = '".$config['client']['debug'] ."' WHERE variable = 'debug' AND chatid = $chatId");

            } elseif ($command[0] === 'showcost') {
                // toggle show cost mode
                if ($config['client']['showcost']==1) { $config['client']['showcost']=0; } else { $config['client']['showcost']=1; }
                sendMessage('Cost '.($config['client']['showcost']==1 ? 'enabled' : 'disabled'), $chatId);
                $db->exec("UPDATE config SET value = '".$config['client']['showcost'] ."' WHERE variable = 'showcost' AND chatid = $chatId");

            } elseif ($command[0] === 'apilog') {
                // toggle api log mode
                if ($config['client']['apilog']==1) { $config['client']['apilog']=0; } else { $config['client']['apilog']=1; }
                sendMessage('API log '.($config['client']['apilog']==1 ? 'enabled' : 'disabled'), $chatId);
                $db->exec("UPDATE config SET value = '".$config['client']['apilog'] ."' WHERE variable = 'apilog' AND chatid = $chatId");

            } elseif ($command[0] === 'context' && !is_null($command[1])) {
                // set context size
                if ($command[1] > 10) {
                    $config['client']['history'] = 10;
                } elseif ($command[1] < 1) {
                    $config['client']['history'] = 3;
                } elseif (!is_numeric($command[1])) {
                    $config['client']['history'] = 3;
                } else {
                    $config['client']['history'] = $command[1];
                }
                sendMessage('Context size set to '.$config['client']['history'], $chatId);
                $db->exec("UPDATE config SET value = '".$config['client']['history'] ."' WHERE variable = 'history' AND chatid = $chatId");

            }
        
            // Update message status in the database
            $db->exec("UPDATE messages SET reply = 'command processed', status = 4 WHERE messageid = $messageId");
        
            // Skip the rest of the processing for this message
            continue;
        } else {
            // Process text message
            // System prompt
            $system = "you are AI assistand T.A.B.B. (telegram ai bot buddy) that can have friendly and funny conversations behaving like human. 
            Always try to give short answers and leave conversation open without asking what else to do, reply in language that user sends request unless asked to use different language. 
            avoid sending #tags or emotions if possble!
            when asked for current date or time, it is critical to reply following time only in this format : It is ".date('l Y-m-d H:i:s')." 
            ";
            // Preparing context for OpenAI API
            $context = makecontext($system, $request, $config['client']['history'] );
            // OpenAI API call
            $response = openaiapi($context);
            // Extract reply from OpenAI API response
            $reply = $response['choices'][0]['message']['content'];

            if ($response === '' || $response === null || !empty($response['message'])) {
                $reply = 'Error: ' . $response['error']['message'];                
            }
            // Send reply to telegram chat
            sendMessage($reply, $chatId);
            $stmt = $db->prepare("UPDATE messages SET reply = :reply, status = 3 WHERE messageid = :messageId");
            
            // remove date and time from saving to DB to avoid confusion with older date and time in context
            if (strpos($reply, 'It is') !== false) {
                $reply = " ";
            }
            
            $stmt->bindParam(':reply', $reply);
            $stmt->bindParam(':messageId', $messageId);
            $stmt->execute();

            // Show cost
            showcost($response['usage'],$response['time_taken'], $chatId);
            
            // Debug messages
            showdebug($response, $chatId , 'response');

            // Stats counters
            stats('prompt_tokens', $response['usage']['prompt_tokens']);
            stats('completion_tokens', $response['usage']['completion_tokens']);
            stats('total_tokens', $response['usage']['total_tokens']);
        }
    }

    flock($lockFile, LOCK_UN);
    fclose($lockFile);
    
    //echo "Script is finished.\n";
}

// Receive incoming messages from telegram
$update = json_decode(file_get_contents('php://input'), true);

if (isset($update['message'])) {
    handleIncomingMessage($update);
} else {
    echo "No message received.\n";
}

// Respond with "OK" after successfully receiving a message
http_response_code(200);
echo "OK";

// Process unprocessed messages
processUnprocessedMessages();




// Uncoment script below to test your config of DB, LLM API, Telegram API
// Run it by executing      php index.php
 /*

echo "Testing script\n";

// Test database access
if ($db instanceof SQLite3) {
    // Database exists and is accessible
    echo "\nDatabase exists and accessible.\n";

    // Test if database is writable
    $testTableName = 'test_table';
    $testTableCreationQuery = "CREATE TABLE IF NOT EXISTS $testTableName (id INTEGER PRIMARY KEY AUTOINCREMENT, test_column TEXT)";
    if ($db->exec($testTableCreationQuery)) {
        echo "Database is writable.\n";
        // Clean up test table
        $db->exec("DROP TABLE IF EXISTS $testTableName");
    } else {
        echo "Database is not writable.\n";
    }
} else {
    echo "Database does not exist or inaccessible.\n";
}

// Test LLM API configuration
$api = $config['api'];
if (isset($config[$api])) {
    echo "LLM API provider :" . $api . "\n";
    echo "LLM API URL      :" . $config[$api]['url'] . "\n";
    echo "LLM API key      :" . $config[$api]['apikey'] . "\n";
    echo "LLM API model    :" . $config[$api]['model'] . "\n";
    
    // Test LLM API
    $system = "This is testing message from telegram bot AI assistant.";
    $request = "Hi, are you there?";
    $context = makecontext($system, $request, '0' );
    $response = openaiapi($context);
    $reply = $response['choices'][0]['message']['content'];
    
    if ($response === '' || $response === null || !empty($response['message'])) {
        $reply = 'Error: ' . $response['error']['message'];                
    } else {
        echo "LLM API request: " . $request . "\n";
        echo "LLM API response: " . $reply . "\n";
    }
} else {
    echo "LLM API config is not set.\n";
}

// getting chat id from table messages if there is any
$chatId = $db->query('SELECT chatid FROM messages ORDER BY datetime DESC LIMIT 1')->fetchArray()[0];
echo "Chat ID: " . $chatId . "\n";

// Send a test message via Telegram
sendMessage("This is a test message.", $chatId);
echo "Check telegram chat for test message\n";

 */
// End of test code


// Close the database
$db->close();

?>