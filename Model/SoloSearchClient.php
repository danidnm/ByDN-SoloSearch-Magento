<?php

namespace Bydn\SoloSearch\Model;

/**
 * HTTP client for SoloSearch's public API (suite - the panel, not suite-search/the widget host).
 * Endpoints are added here as SoloSearch's API grows; requestReindex() is the first one.
 */
class SoloSearchClient
{
    // Keeps a slow/unreachable SoloSearch API from stalling feed generation - this request runs
    // synchronously right after generate() in generateForStoreIfEnabled(), inside the same cron
    // run that processes every other store, so a hung connection here would delay all of them too.
    const REQUEST_TIMEOUT_SECONDS = 5;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    private $httpClient;

    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @param \Magento\Framework\HTTP\Client\Curl $httpClient
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\HTTP\Client\Curl $httpClient,
        \Bydn\SoloSearch\Helper\Config $config,
        \Psr\Log\LoggerInterface $logger
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Asks SoloSearch to re-fetch and reindex this store's feed immediately, instead of waiting
     * for its own schedule (POST /api/v1/search-engines/{uuid}/reindex, Bearer token auth).
     *
     * Best-effort: any failure (missing config, network error, non-200 response - e.g. rate
     * limited, no fetchable feeds, invalid token) is logged and swallowed, never thrown. A reindex
     * notification failing must not fail feed generation itself, which already succeeded by the
     * time this runs.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function requestReindex($storeId = null)
    {
        $apiUrl = trim((string) $this->config->getApiUrl($storeId));
        $searchEngineId = trim((string) $this->config->getSearchEngineId($storeId));
        $token = trim((string) $this->config->getApiToken($storeId));

        if ($apiUrl === '' || $searchEngineId === '' || $token === '') {
            $this->logger->info(__METHOD__ . ': skipped, missing api_url/search_engine_id/api_token config');

            return false;
        }

        $url = rtrim($apiUrl, '/') . '/api/v1/search-engines/' . rawurlencode($searchEngineId) . '/reindex';

        try {
            $this->httpClient->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
            $this->httpClient->setHeaders(['Authorization' => 'Bearer ' . $token]);
            $this->httpClient->post($url, []);
            $status = $this->httpClient->getStatus();
            $body = $this->httpClient->getBody();
        } catch (\Exception $e) {
            $this->logger->warning(__METHOD__ . ": request to {$url} failed - " . $e->getMessage());

            return false;
        }

        if ($status !== 200) {
            $this->logger->warning(__METHOD__ . ": unexpected status {$status} from {$url} - {$body}");

            return false;
        }

        $this->logger->info(__METHOD__ . ": reindex requested successfully ({$url})");

        return true;
    }
}
