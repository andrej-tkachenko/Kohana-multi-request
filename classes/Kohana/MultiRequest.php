<?php

class Kohana_MultiRequest {

	/**
	 * @var resource curl_multi_init
	 */
	private $cm;
	private $handles = [];
	private $storage;

	/**
	 * @param array $options опции для curl_multi
	 * @link https://curl.haxx.se/libcurl/c/curl_multi_setopt.html
	 * @throws Curl_Exception
	 */
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

		$this->storage = new SplObjectStorage;
	}

	public function __destruct()
	{
		curl_multi_close($this->cm);
	}

	/**
	 * @param Request $request кроме options никакие другие параметры не поддерживаются
	 * @throws Curl_Exception
	 */
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

		if ($body = $request->body())
		{
			$options[CURLOPT_POSTFIELDS] = $body;
		}
		else
		{
			$request->headers('content-length', (string) $request->content_length());
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

		/*
		 * Получение идентификатора ресурса
		 * Из-за curl_multi_add_handle происходит коллекционирование ресурсов curl, из-за чего get_resources() возвращает
		 * в каждой новой вставке полный массив всех ранее добавленных ресурсов
		 */
//		$curl_resource_id = key(get_resources(get_resource_type($curl)));
		$curl_resource_id = (int) $curl;

		$this->handles[$curl_resource_id] = $curl;

		$this->storage->attach($request, $curl_resource_id);
	}

	/**
	 * @param callable $f
	 * @throws Kohana_Exception
	 * @throws Request_Exception
	 */
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
		foreach ($this->handles as $resource_id => $curl)
		{
			$response = new Response();

			$error = NULL;

			$body          = curl_multi_getcontent($curl);
			$status        = curl_getinfo($curl, CURLINFO_HTTP_CODE);
			$effective_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
			$header_size   = curl_getinfo($curl, CURLINFO_HEADER_SIZE);

			// Будет выброшено исключение, если статус будет нулевым
			try
			{
				$response->status($status);
			}
			catch (Kohana_Exception $e)
			{
				$error = $e;
			}

			if ($header_size > 0)
			{
				$response->body(substr($body, $header_size));
				$response->protocol(substr($body, 0, 8));

				/**
				 * @var $headers HTTP_Header
				 */
				$headers = $response->headers();
				$headers->parse_header_string(NULL, substr($body, 0, $header_size));
			}

			foreach ($this->storage as $request)
			{
				if ($this->storage->getInfo() == $resource_id)
				{
					call_user_func($f, $response, $request);

					$this->storage->detach($request);

					break;
				}
			}

			curl_multi_remove_handle($this->cm, $curl);
			curl_close($curl);
			unset($this->handles[$resource_id]);

			if ($error !== NULL)
			{
				throw new Request_Exception('Error fetching remote :url [ status :code ] :error', [
					':url'   => $effective_url,
					':code'  => $status,
					':error' => $error->getMessage(),
				], $error->getCode(), $error);
			}
		}
	}
}