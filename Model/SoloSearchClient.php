<?php

namespace Bydn\SoloSearch\Model;

/**
 * HTTP client for SoloSearch's public API (suite - the panel, not suite-search/the widget host).
 * Endpoints are added here as SoloSearch's API grows: requestReindex() (full feed), and
 * sendProductBatch()/deleteProduct() (real-time single-product sync).
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

    /**
     * Adds/updates many products in a single call (POST /api/v1/search-engines/{uuid}/products/batch,
     * Bearer token auth, JSON body {"products": [...]}) - used by the real-time product sync
     * (Model\ProductQueueSync) instead of a full Feed Reindex.
     *
     * Best-effort, same reasoning as requestReindex(): returns null on any failure (missing
     * config, network error, non-200/malformed response) - callers must treat null as "nothing
     * was sent, try again later", not as "every product failed". A 200 response's own `results`
     * array (one entry per product, {id, success, error}) is what tells the caller which products
     * actually succeeded, since the API accepts a partially-failing batch with a 200.
     *
     * @param int|null $storeId
     * @param array $items
     * @return array|null
     */
    public function sendProductBatch($storeId, array $items)
    {
        $apiUrl = trim((string) $this->config->getApiUrl($storeId));
        $searchEngineId = trim((string) $this->config->getSearchEngineId($storeId));
        $token = trim((string) $this->config->getApiToken($storeId));

        if ($apiUrl === '' || $searchEngineId === '' || $token === '') {
            $this->logger->info(__METHOD__ . ': skipped, missing api_url/search_engine_id/api_token config');

            return null;
        }

        $url = rtrim($apiUrl, '/') . '/api/v1/search-engines/' . rawurlencode($searchEngineId) . '/products/batch';

        try {
            $this->httpClient->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
            $this->httpClient->setHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ]);
            $this->httpClient->post($url, json_encode(['products' => array_values($items)]));
            $status = $this->httpClient->getStatus();
            $body = $this->httpClient->getBody();
        } catch (\Exception $e) {
            $this->logger->warning(__METHOD__ . ": request to {$url} failed - " . $e->getMessage());

            return null;
        }

        if ($status !== 200) {
            $this->logger->warning(__METHOD__ . ": unexpected status {$status} from {$url} - {$body}");

            return null;
        }

        $decoded = json_decode((string) $body, true);

        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
            $this->logger->warning(__METHOD__ . ": malformed response from {$url} - {$body}");

            return null;
        }

        $this->logger->info(__METHOD__ . ': sent ' . count($items) . " product(s) - {$decoded['indexed']} indexed, {$decoded['failed']} failed ({$url})");

        return $decoded['results'];
    }

    /**
     * Deletes a single product from the index (DELETE /api/v1/search-engines/{uuid}/products/{id},
     * Bearer token auth) - used by the real-time product sync. No batch-delete endpoint exists yet,
     * so this is called once per product.
     *
     * The underlying Curl client has no delete()/put() of its own, only get()/post() plus
     * setOption() to override the HTTP method via CURLOPT_CUSTOMREQUEST - and since that client is
     * shared for the whole request, the override is explicitly cleared again afterwards so it
     * can't leak into a later post()/get() call (e.g. a subsequent sendProductBatch()) on the same
     * instance.
     *
     * Best-effort, same reasoning as requestReindex().
     *
     * @param int|null $storeId
     * @param int|string $productId
     * @return bool
     */
    public function deleteProduct($storeId, $productId)
    {
        $apiUrl = trim((string) $this->config->getApiUrl($storeId));
        $searchEngineId = trim((string) $this->config->getSearchEngineId($storeId));
        $token = trim((string) $this->config->getApiToken($storeId));

        if ($apiUrl === '' || $searchEngineId === '' || $token === '') {
            $this->logger->info(__METHOD__ . ': skipped, missing api_url/search_engine_id/api_token config');

            return false;
        }

        $url = rtrim($apiUrl, '/') . '/api/v1/search-engines/' . rawurlencode($searchEngineId)
            . '/products/' . rawurlencode((string) $productId);

        try {
            $this->httpClient->setTimeout(self::REQUEST_TIMEOUT_SECONDS);
            $this->httpClient->setHeaders(['Authorization' => 'Bearer ' . $token]);
            $this->httpClient->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
            $this->httpClient->get($url);
            $status = $this->httpClient->getStatus();
            $body = $this->httpClient->getBody();
        } catch (\Exception $e) {
            $this->logger->warning(__METHOD__ . ": request to {$url} failed - " . $e->getMessage());

            return false;
        } finally {
            $this->httpClient->setOptions([]);
        }

        if ($status !== 200) {
            $this->logger->warning(__METHOD__ . ": unexpected status {$status} from {$url} - {$body}");

            return false;
        }

        $this->logger->info(__METHOD__ . ": product {$productId} deleted successfully ({$url})");

        return true;
    }
}
