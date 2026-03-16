<?php

require_once 'data.php';

// --- CONFIGURATION ---
// Simple .env parser for environments without Docker
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }
}

$access_token = $_ENV['VK_TOKEN'] ?? getenv('VK_TOKEN');
$confirmation_token = $_ENV['CONFIRMATION_TOKEN'] ?? getenv('CONFIRMATION_TOKEN');
$secret_key = $_ENV['SECRET_KEY'] ?? getenv('SECRET_KEY');
$group_id = $_ENV['VK_GROUP_ID'] ?? getenv('VK_GROUP_ID');

// --- SESSION HANDLING ---
$session_dir = __DIR__ . '/sessions';
if (!is_dir($session_dir)) {
    mkdir($session_dir, 0777, true);
}

function get_session($user_id) {
    global $session_dir;
    $file = $session_dir . '/' . $user_id . '.json';
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}

function save_session($user_id, $data) {
    global $session_dir;
    $file = $session_dir . '/' . $user_id . '.json';
    file_put_contents($file, json_encode($data));
}

function delete_session($user_id) {
    global $session_dir;
    $file = $session_dir . '/' . $user_id . '.json';
    if (file_exists($file)) {
        unlink($file);
    }
}

// --- VK API HELPER ---
function vk_request($method, $params) {
    global $access_token;
    $params['access_token'] = $access_token;
    $params['v'] = '5.131';
    
    $url = 'https://api.vk.com/method/' . $method . '?' . http_build_query($params);
    $result = file_get_contents($url);
    return json_decode($result, true);
}

function send_message($user_id, $message, $keyboard = null) {
    $params = [
        'user_id' => $user_id,
        'message' => $message,
        'random_id' => rand(0, 2147483647)
    ];
    if ($keyboard) {
        $params['keyboard'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    return vk_request('messages.send', $params);
}

// --- KEYBOARDS ---
function get_main_menu_keyboard() {
    return [
        'one_time' => false,
        'buttons' => [
            [['action' => ['type' => 'text', 'label' => 'BDI (Депрессия)', 'payload' => json_encode(['command' => 'start_test', 'test' => 'depression'])]]],
            [['action' => ['type' => 'text', 'label' => 'BAI (Тревожность)', 'payload' => json_encode(['command' => 'start_test', 'test' => 'anxiety'])]]],
            [['action' => ['type' => 'text', 'label' => 'RAS (Ассертивность)', 'payload' => json_encode(['command' => 'start_test', 'test' => 'assertiveness'])]]]
        ]
    ];
}

function get_answers_keyboard($options) {
    $buttons = [];
    foreach ($options as $i => $opt) {
        // Ограничиваем подпись кнопки 35 символами
        $label = $opt;
        $buttons[] = [['action' => ['type' => 'text', 'label' => $label, 'payload' => json_encode(['command' => 'answer', 'index' => $i])]]];
    }
    return [
        'one_time' => false,
        'buttons' => $buttons
    ];
}

function get_show_answers_keyboard() {
    return [
        'one_time' => false,
        'buttons' => [
            [['action' => ['type' => 'text', 'label' => 'Показать мои ответы', 'payload' => json_encode(['command' => 'show_answers'])]]]
        ]
    ];
}

// Клавиатура под результатом теста: "Мои ответы" и "Пройти ещё тесты"
function get_result_keyboard() {
    return [
        'one_time' => false,
        'buttons' => [
            [[
                'action' => [
                    'type' => 'text',
                    'label' => 'Мои ответы',
                    'payload' => json_encode(['command' => 'show_answers'])
                ]
            ]],
            [[
                'action' => [
                    'type' => 'text',
                    'label' => 'Пройти ещё тесты',
                    'payload' => json_encode(['command' => 'back_to_menu'])
                ]
            ]]
        ]
    ];
}

// --- CALLBACK LOGIC ---
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    exit;
}

// Secret key check
if ($secret_key && (!isset($data['secret']) || $data['secret'] !== $secret_key)) {
    exit('Invalid secret');
}

switch ($data['type']) {
    case 'confirmation':
        echo $confirmation_token;
        break;

    case 'message_new':
        $message_obj = $data['object']['message'] ?? $data['object'];
        $user_id = $message_obj['from_id'];
        $text = $message_obj['text'];
        $payload = isset($message_obj['payload']) ? json_decode($message_obj['payload'], true) : null;

        handle_message($user_id, $text, $payload);
        echo 'ok';
        break;

    default:
        echo 'ok';
        break;
}

// --- MESSAGE HANDLER ---
function handle_message($user_id, $text, $payload) {
    global $DEPRESSION_QUESTIONS, $ANXIETY_QUESTIONS, $ASSERTIVENESS_QUESTIONS, $TEST_INSTRUCTIONS, $TEST_INTERPRETATIONS;
    
    $session = get_session($user_id);

    // 1. Обработка нажатий по кнопкам (payload)
    if ($payload) {
        switch ($payload['command']) {
            case 'start_test':
                $test_type = $payload['test'];
                $questions = [];
                switch ($test_type) {
                    case 'depression': $questions = $DEPRESSION_QUESTIONS; break;
                    case 'anxiety': $questions = $ANXIETY_QUESTIONS; break;
                    case 'assertiveness': $questions = $ASSERTIVENESS_QUESTIONS; break;
                }
                
                $session = [
                    'test_type' => $test_type,
                    'current_question' => 0,
                    'answers' => [],
                    'questions' => $questions
                ];
                save_session($user_id, $session);

                // Отправляем инструкцию вместе с первым вопросом и кнопками
                $instruction = $TEST_INSTRUCTIONS[$test_type];
                $first_question = $questions[0];
                $msg = $instruction . "\n\nВопрос 1:\n" . $first_question['question'];
                send_message($user_id, $msg, get_answers_keyboard($first_question['options']));

                return;

            case 'answer':
                if (!$session) {
                    send_message($user_id, "Сессия не найдена. Начните заново.", get_main_menu_keyboard());
                    return;
                }
                
                $index = $payload['index'];
                $session['answers'][] = $index;
                $session['current_question']++;
                
                if ($session['current_question'] >= count($session['questions'])) {
                    finish_test($user_id, $session);
                } else {
                    save_session($user_id, $session);
                    ask_question($user_id, $session);
                }
                return;

            case 'show_answers':
                if (!$session) {
                    send_message($user_id, "Ответы не найдены.", get_main_menu_keyboard());
                    return;
                }
                
                $response = "Твои ответы:\n\n";
                foreach ($session['questions'] as $i => $q) {
                    $selected = $q['options'][$session['answers'][$i]];
                    $response .= "Вопрос " . ($i+1) . ": " . $q['question'] . "\n";
                    $response .= "* " . $selected . "\n\n";
                }
                send_message($user_id, $response, get_main_menu_keyboard());
                delete_session($user_id);
                return;

            case 'back_to_menu':
                if ($session) {
                    delete_session($user_id);
                }
                $welcome = "Выберите тест, который хотите пройти.";
                send_message($user_id, $welcome, get_main_menu_keyboard());
                return;
        }
    }

    // 2. Обработка текстового ответа без payload (на случай, если VK не прислал payload,
    //    но пользователь нажал кнопку и в чате виден только текст варианта)
    if (!$payload && $session && $session['current_question'] < count($session['questions'])) {
        $current = $session['current_question'];
        $question = $session['questions'][$current];
        $options = $question['options'];

        // Ищем введённый текст среди вариантов ответа
        $matchedIndex = null;
        foreach ($options as $i => $optText) {
            // В VK-клавиатуре мы могли обрезать длинные варианты до 35 символов,
            // поэтому сравниваем именно с тем текстом, который был на кнопке.
            $label = mb_strlen($optText) > 35 ? mb_substr($optText, 0, 32) . '...' : $optText;
            if (trim($label) === trim($text)) {
                $matchedIndex = $i;
                break;
            }
        }

        if ($matchedIndex !== null) {
            // Эмулируем ту же логику, что и для payload 'answer'
            $session['answers'][] = $matchedIndex;
            $session['current_question']++;

            if ($session['current_question'] >= count($session['questions'])) {
                finish_test($user_id, $session);
            } else {
                save_session($user_id, $session);
                ask_question($user_id, $session);
            }
            return;
        }
    }

    // 3. Если это не кнопка и не ответ на вопрос — показываем главное меню
    $welcome = "Добрый день! Это бот для прохождения психологических тестов. Выберите тест, который хотите пройти.";
    send_message($user_id, $welcome, get_main_menu_keyboard());
}

function ask_question($user_id, $session) {
    $q_idx = $session['current_question'];
    $q = $session['questions'][$q_idx];
    $message = "Вопрос " . ($q_idx + 1) . ":\n" . $q['question'];
    send_message($user_id, $message, get_answers_keyboard($q['options']));
}

function finish_test($user_id, $session) {
    global $TEST_INTERPRETATIONS;
    
    $score = 0;
    foreach ($session['answers'] as $i => $ans_idx) {
        $score += $session['questions'][$i]['scores'][$ans_idx];
    }
    
    $interpretation = "Результат не определен.";
    $ranges = $TEST_INTERPRETATIONS[$session['test_type']]['ranges'];
    foreach ($ranges as $range) {
        if ($score >= $range[0] && $score <= $range[1]) {
            $interpretation = $range[2];
            break;
        }
    }

    // Сохраняем сессию, чтобы потом можно было показать ответы
    save_session($user_id, $session);

    // Отправляем краткий результат + две кнопки: "Мои ответы" и "Пройти ещё тесты"
    $result_message = "Тест завершен!\nТвой итоговый балл: $score\n\n$interpretation";
    send_message($user_id, $result_message, get_result_keyboard());
}
