<?php

$port = 8080;
$address = '127.0.0.1';

const DEFAULT_SCRIPT = '/phpl.cgi';
const DEFAULT_PAGE = 'index.html';

/**
 * Парсим запрос и достаем все необходимые данные для работы
 * @param $request
 * @return array
 */
function parseRequest($request): array
{
    $request = explode("\r\n\r\n", $request);
    $headers = explode("\r\n", $request[0]);
    $body = $request[1] ?? '';
    $headerLine = array_shift($headers);
    preg_match('/(GET|POST) (\/\S*) HTTP\/1.\d/', $headerLine, $matches);
    $method = strtolower($matches[1]) ?? '';
    $path = $matches[2] ?? '';
    $queryParams = parse_url($path);
    return [
        'method' => $method,
        'path' => $queryParams['path'] ?? '',
        'query' => $queryParams['query'] ?? '',
        'body' => $body,
    ];
}

/** Обработка запроса, вызов cgi программы с параметрами запроса и отправка ответа клиенту
 * @param $client
 * @param $request
 * @return void
 */
function handleRequest($client, $request)
{
    $method = $request['method'];
    $path = $request['path'];
    $query = $request['query'];
    $body = $request['body'];

    if(!$path || $path === '/'){
        $path = DEFAULT_SCRIPT;
    }
    if(!$query){
        $query = DEFAULT_PAGE;
    }

    // Принимаем только get и post запросы
    if (!in_array($method, ['get', 'post'])) {
        echo "Error: Invalid method\n";
        socket_write($client, "HTTP/1.1 405 Method Not Allowed\r\n\r\n");
        return;
    }

    // Переменные среды необходимые для PHP/FI 1
    $env = [
        'REQUEST_METHOD' => strtoupper($method),
        'QUERY_STRING' => $query,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'CONTENT_LENGTH' => strlen($body)
    ];

    // Подготовка команды и параметров для запуска
    $cmd = "." . $path;
    $params = str_replace('+', ' ', $query);

    // Дескрипторы ввода вывода
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    // Запуск процесса
    $process = proc_open($cmd . ' ' . $params, $descriptors, $pipes, '.', $env);
    if (is_resource($process)) {
        // Записываем тело запроса в stdin
        if($body) {
            fwrite($pipes[0], $body);
        }
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        if($errors){
            var_dump($errors);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        // Отправляем клиенту ответ
        $statusCode = strpos($output, 'Location:') !== false ? '302 Found' : '200 OK';
        $response = "HTTP/1.1 {$statusCode}\r\n{$output}";
        socket_write($client, $response);
    }
}

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_bind($socket, $address, $port);
socket_listen($socket);

echo "Сервер запущен http://$address:$port\n";

while (true) {
    $client = socket_accept($socket);
    $request = socket_read($client, 1024);
    $parsedRequest = parseRequest($request);

    handleRequest($client, $parsedRequest);

    socket_close($client);
}

socket_close($socket);

?>
