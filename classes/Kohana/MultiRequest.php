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

		/* =========================================================================== */
		$options = [];

		// Set the request method
		$options = $client->_set_curl_request_method($request, $options);

		// Set the request body. This is perfectly legal in CURL even
		// if using a request other than POST. PUT does support this method
		// and DOES NOT require writing data to disk before putting it, if
		// reading the PHP docs you may have got that impression. SdF
		// This will also add a Content-Type: application/x-www-form-urlencoded header unless you override it
		if ($body = $request->body()) {
			$options[CURLOPT_POSTFIELDS] = $body;
		}

		// Process headers
		if ($headers = $request->headers())
		{
			$http_headers = array();

			foreach ($headers as $key => $value)
			{
				$http_headers[] = $key.': '.$value;
			}

			$options[CURLOPT_HTTPHEADER] = $http_headers;
		}

		// Process cookies
		if ($cookies = $request->cookie())
		{
			$options[CURLOPT_COOKIE] = http_build_query($cookies, NULL, '; ');
		}

//		$response = Response::factory();
		// Get any exisiting response headers
//		$response_header = $response->headers();

		// Implement the standard parsing parameters
//		$options[CURLOPT_HEADERFUNCTION]        = array($response_header, 'parse_header_string');

		$client_options = $client->options();
		$client_options[CURLOPT_RETURNTRANSFER] = TRUE;
		$client_options[CURLOPT_HEADER]         = TRUE;

		// Apply any additional options set to
		$options += $client_options;

		$uri = $request->uri();

		if ($query = $request->query())
		{
			$uri .= '?'.http_build_query($query, NULL, '&');
		}
		/* =========================================================================== */

		// Set connection options
		if ( ! curl_setopt_array($curl, $options))
		{
			throw new Request_Exception('Failed to set CURL options, check CURL documentation: :url',
				[':url' => 'http://php.net/manual/en/function.curl-setopt-array.php']);
		}

		if ( ! UTF8::is_ascii($request->uri()))
		{
			$request->uri(curl_escape($curl, $request->uri()));
		}

		curl_setopt($curl, CURLOPT_URL, $uri);

		if (curl_multi_add_handle($this->cm, $curl) !== 0)
		{
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

		// echo the content, remove the handlers, then close them
		foreach ($this->handles as $h)
		{
			$response = new Response();

			$body = curl_multi_getcontent($h);

			// Get the response information
			$code        = curl_getinfo($h, CURLINFO_HTTP_CODE);
			$header_size = curl_getinfo($h, CURLINFO_HEADER_SIZE);

			if ($body === FALSE)
			{
				$error = curl_error($h);
			}

			if (isset($error))
			{
				throw new Request_Exception('Error fetching remote :url [ status :code ] :error',
					array(':url' => curl_getinfo($h, CURLINFO_EFFECTIVE_URL), ':code' => $code, ':error' => $error));
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