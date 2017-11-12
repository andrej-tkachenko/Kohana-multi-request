<?php

class Kohana_MultiRequest {

	/**
	 * @var resource curl_multi_init
	 */
	private $cm;
	private $handles = [];

	public function __construct($options = [])
	{
		$this->cm = curl_multi_init();

		foreach ($options as $option => $value)
		{
			if ( ! curl_multi_setopt($this->cm, $option, $value))
			{
				throw new Curl_Exception('Failed to set CURL options, check CURL documentation: :url',
					[':url' => 'http://php.net/manual/en/function.curl-multi-setopt.php']);
			}
		}
	}

	public function __destruct()
	{
		curl_multi_close($this->cm);
	}


	public function add(Request $request)
	{
		/**
		 * @var $client Request_Client_Curl
		 */
		$client = $request->client();

		if ( ! ($client instanceof Request_Client_Curl))
		{
			throw new Curl_Exception('Client must be Request_Client_Curl');
		}

		$curl = curl_init();

		$options = [];

		$options = $client->_set_curl_request_method($request, $options);

		if ($body = $request->body()) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}

		// Обработка заголовков
		if ($headers = $request->headers())
		{
			$http_headers = [];

			foreach ($headers as $key => $value)
			{
				$http_headers[] = $key.': '.$value;
			}

			$options[CURLOPT_HTTPHEADER] = $http_headers;
		}

		// Обработка cookies
		if ($cookies = $request->cookie())
		{
			$options[CURLOPT_COOKIE] = http_build_query($cookies, NULL, '; ');
		}

		$client_options = $client->options();
		$client_options[CURLOPT_RETURNTRANSFER] = TRUE;
		// Обязательно возвращать заголовки! Иначе будут ошибки в работе
		$client_options[CURLOPT_HEADER]         = TRUE;

		// Слияние параметров curl
		$options += $client_options;

		$uri = $request->uri();

		// Добавление GET-параметров
		if ($query = $request->query())
		{
			$uri .= '?'.http_build_query($query, NULL, '&', PHP_QUERY_RFC3986);
		}

		if ( ! curl_setopt_array($curl, $options))
		{
			throw new Curl_Exception('Failed to set CURL options, check CURL documentation: :url',
				[':url' => 'http://php.net/manual/en/function.curl-setopt-array.php']);
		}

		curl_setopt($curl, CURLOPT_URL, $uri);

		curl_multi_add_handle($this->cm, $curl);

		$this->handles[] = $curl;
	}

	public function execute(callable $f)
	{
		/**
		 * @link http://php.net/manual/en/function.curl-multi-exec.php#113002
		 */
		do
		{
			curl_multi_exec($this->cm, $running);
			/*
			 * Данная функция сокращает количество итераций цикла - блокируя скрипт, оптимизируя работу соединений,
			 * тем самым не позволяя нагружать CPU
			 */
			curl_multi_select($this->cm);
		}
		while ($running > 0);

		// Получение ответа, удаление дескриптора из набора, закрытие дескриптора
		foreach ($this->handles as $k => &$h)
		{
			$response = new Response();

			$body = curl_multi_getcontent($h);

			// Если CURLOPT_RETURNTRANSFER не равен FALSE
			if ( ! is_null($body))
			{
				$code        = curl_getinfo($h, CURLINFO_HTTP_CODE);
				$header_size = curl_getinfo($h, CURLINFO_HEADER_SIZE);

				$response->status($code);
				$response->body(substr($body, $header_size));
				$response->protocol(substr($body, 0, 8));

				/**
				 * @var $headers HTTP_Header
				 */
				$headers = $response->headers();
				$headers->parse_header_string(NULL, substr($body, 0, $header_size));

				call_user_func($f, $response);
			}

			curl_multi_remove_handle($this->cm, $h);
			curl_close($h);
			unset($this->handles[$k]);
		}
	}
}