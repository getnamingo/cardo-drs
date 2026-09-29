<?php

namespace Namingo\Cardo\DRS;

abstract class Adapter
{
    protected string $userAgent = 'Namingo';

    protected string $endpoint;

    protected string $apiKey;

    protected string $apiSecret;

    /** @var array<mixed> */
    protected $headers = [
        'Content-Type' => 'application/json',
    ];

    /**
     * __construct
     * Instantiate a new adapter.
     *
     * @param  string  $endpoint
     * @param string $apiKey
     * @param string $apiSecret
     */
    public function __construct(string $endpoint, string $apiKey, string $apiSecret)
    {
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;

        $this->headers = [
            'Authorization' => 'sso-key '.$this->apiKey.':'.$this->apiSecret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param  string  $method
     * @param string $path
     * @param array|string $params
     * @param array $headers
     * @retury array|string
     *
     * @throws \Exception
     */
    public function call(string $method, string $path = '', array|string $params = [], array $headers = []): array|string
    {
        $headers = array_merge($this->headers, $headers);
        $ch = curl_init(
            (
                str_contains($path, 'http')
                ? $path
                : $this->endpoint.$path.(
                    ($method == 'GET' && ! empty($params) && $headers['Content-Type'] != 'text/xml')
                    ? '?'.http_build_query($params)
                    : ''
                )
            )
        );

        $responseHeaders = [];
        $responseStatus = -1;
        $responseType = '';
        $responseBody = '';

        $query = null;

        if (! empty($params)) {
            switch ($headers['Content-Type']) {
                case 'application/json':
                    $query = json_encode($params, JSON_UNESCAPED_SLASHES);
                    break;

                case 'multipart/form-data':
                    $query = $this->flatten($params);
                    break;

                case 'text/xml':
                    $query = $params;
                    break;

                default:
                    $query = http_build_query($params);
                    break;
            }
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i.':'.$header;

            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, php_uname('s').'-'.php_uname('r').':php-'.phpversion());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', strtolower($header), 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody = curl_exec($ch);

        $responseType = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch (substr($responseType, 0, strpos($responseType, ';'))) {
            case 'application/json':
                $responseBody = json_decode($responseBody, true);
                break;
        }

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        if ($responseStatus >= 400) {
            if (\is_array($responseBody)) {
                throw new \Exception(json_encode($responseBody));
            } else {
                throw new \Exception($responseStatus.': '.$responseBody);
            }
        }

        return $responseBody;
    }

    /**
     * Flatten params array to PHP multiple format
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (\is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
	
    /**
     * Default nameservers for domain registration
     */
    protected array $defaultNameservers = [];

    /**
     * Cache instance
     */
    protected ?Cache $cache = null;

    /**
     * Connection timeout in seconds
     */
    protected int $connectTimeout = 5;

    /**
     * Request timeout in seconds
     */
    protected int $timeout = 10;

    /**
     * Set default nameservers
     *
     * @param array $nameservers
     * @return void
     */
    public function setDefaultNameservers(array $nameservers): void
    {
        $this->defaultNameservers = $nameservers;
    }

    /**
     * Set cache instance
     *
     * @param Cache|null $cache
     * @return void
     */
    public function setCache(?Cache $cache): void
    {
        $this->cache = $cache;
    }

    /**
     * Set connection timeout
     *
     * @param int $connectTimeout
     * @return void
     */
    public function setConnectTimeout(int $connectTimeout): void
    {
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Set request timeout
     *
     * @param int $timeout
     * @return void
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Get the name of the adapter
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Check if a domain is available
     *
     * @param  string  $domain
     * @return bool
     */
    abstract public function available(string $domain): bool;

    /**
     * Purchase a domain
     *
     * @param  string  $domain
     * @param  array|Contact  $contacts
     * @param  int  $periodYears
     * @param  array  $nameservers
     * @param  bool  $autorenewEnabled
     * @param  float|null  $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    abstract public function purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = [], bool $autorenewEnabled = false, ?float $purchasePrice = null): string;

    /**
     * Suggest domain names
     *
     * @param  array  $query
     * @param  array  $tlds
     * @param  int|null $limit
     * @param  string|null $filterType Filter results by type: 'premium', 'suggestion', or null for both
     * @param  int|null $priceMax
     * @param  int|null $priceMin
     * @return array
     */
    abstract public function suggest(array|string $query, array $tlds = [], int|null $limit = null, string|null $filterType = null, int|null $priceMax = null, int|null $priceMin = null): array;

    /**
     * Get the TLDs supported by the adapter
     *
     * @return array
     */
    abstract public function tlds(): array;

    /**
     * Get the domain information
     *
     * @param  string  $domain
     * @return Domain
     */
    abstract public function getDomain(string $domain): Domain;

    /**
     * Update the domain information
     *
     * @param  string  $domain
     * @param  UpdateDetails $details
     * @return bool
     */
    abstract public function updateDomain(string $domain, UpdateDetails $details): bool;

    /**
     * Update the nameservers for a domain
     *
     * @param string $domain
     * @param array $nameservers
     * @return array
     * @throws \Exception
     */
    public function updateNameservers(string $domain, array $nameservers): array
    {
        throw new \Exception('Method not implemented');
    }

    /**
     * Get the price of a domain
     *
     * @param  string  $domain
     * @param  int  $periodYears
     * @param  string  $regType
     * @param  int  $ttl
     * @return Price
     */
    abstract public function getPrice(string $domain, int $periodYears = 1, string $regType = Registrar::REG_TYPE_NEW, int $ttl = 3600): Price;

    /**
     * Renew a domain
     *
     * @param  string  $domain
     * @param  int  $periodYears
     * @return Renewal
     */
    abstract public function renew(string $domain, int $periodYears): Renewal;

    /**
     * Transfer a domain
     *
     * @param  string  $domain
     * @param  string  $authCode
     * @param  float|null  $purchasePrice Required if domain is premium
     * @return string Order ID
     */
    abstract public function transfer(string $domain, string $authCode, ?float $purchasePrice = null): string;

    /**
     * Get the authorization code for an EPP domain
     *
     * @param  string  $domain
     * @return string
     */
    abstract public function getAuthCode(string $domain): string;

    /**
     * Check transfer status for a domain
     *
     * @param  string  $domain
     * @return TransferStatus
     */
    abstract public function checkTransferStatus(string $domain): TransferStatus;

    /**
     * Cancel pending purchase orders
     *
     * @return bool
     */
    abstract public function cancelPurchase(): bool;
}
