# Kohana-multi-request
Модуль для Kohana (Koseven) 3.3.x, позволяющий производить множественные асинхронные запросы с помощью библиотеки cURL.
## Использование
```php
<?php

// Например, нужно перебором по диапазону дат собрать данные о курсах валют

// Подключение модуля
Kohana::modules(['multi-request' => MODPATH.'Kohana-multi-request'] + Kohana::modules());

// Берется последний месяц
$begin = (new DateTime)->modify('-1 month');
$end   = new DateTime;

$interval  = new DateInterval('P1D');
$daterange = new DatePeriod($begin, $interval, $end);

$request = new Request('http://www.cbr.ru/scripts/XML_daily.asp', [
	'options' => [
		CURLOPT_TIMEOUT   => 30,
		CURLOPT_ENCODING  => '', // identity, deflate и gzip
	]
]);

// Callback-функция для обработки ответа
$func = function (Response $response)
{
	return $response->body();
};

// Создание объекта для множественных запросов
$multi = new MultiRequest();

foreach ($daterange as $date)
{
	/**
	 * @var $date Datetime
	 */
	$multi->add($request->query('date_req', $date->format('d/m/Y')));
}

$multi->execute($func);
```
