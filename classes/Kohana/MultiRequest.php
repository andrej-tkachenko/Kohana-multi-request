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
				throw new Request_Exception('Failed to set CURL options, check CURL documentation: :url',
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

		if (curl_multi_add_handle($this->cm, $curl) !== 0)
		{
			// :TODO: сделать проверку на существование функции
			// Доступно с PHP 7 >= 7.1.0
			throw new Curl_Exception(curl_multi_errno($this->cm));
		}

		$this->handles[] = $curl;
	}

	public function execute(callable $f)
	{
		// execute - if there is an active connection then keep looping
//		$active = NULL;

		/*do
		{
			$status = curl_multi_exec($this->cm, $active);
		}
		while ($active AND $status == CURLM_OK);*/

		do
		{
			curl_multi_exec($this->cm, $running);
			curl_multi_select($this->cm);
		}
		while ($running > 0);

		// Получение ответа, удаление дескриптора из набора, закрытие дескриптора
		foreach ($this->handles as $h)
		{
			$response = new Response();

			$body = curl_multi_getcontent($h);

			$code        = curl_getinfo($h, CURLINFO_HTTP_CODE);
			$header_size = curl_getinfo($h, CURLINFO_HEADER_SIZE);

			if ($body === FALSE)
			{
				$error = curl_error($h);
			}

			if (isset($error))
			{
				throw new Request_Exception('Error fetching remote :url [ status :code ] :error',
					[':url' => curl_getinfo($h, CURLINFO_EFFECTIVE_URL), ':code' => $code, ':error' => $error]);
			}

			$response->status($code);
			$response->body(substr($body, $header_size));
			$response->protocol(substr($body, 0, 8));

			/**
			 * @var $headers HTTP_Header
			 */
			$headers = $response->headers();
			$headers->parse_header_string(NULL, substr($body, 0, $header_size));

			call_user_func($f, $response);
			curl_multi_remove_handle($this->cm, $h);
			curl_close($h);
		}
	}
}