<?php

namespace Bydn\SoloSearch\Api\Data;

/**
 * A change (update or delete) for one product on one store view, sent - or waiting to be sent -
 * to SoloSearch's single-product API. Rows are kept indefinitely rather than deleted once
 * finished, as a visibility/audit trail of what was synced and how it went (see STATUS_SUCCESS/
 * STATUS_ERROR) - not just a disposable work queue. There is no DB uniqueness constraint on
 * (product_id, store_id) - a product can accumulate many STATUS_SUCCESS/STATUS_ERROR rows over
 * time, one per past sync. What IS guaranteed, at the application level (see
 * ProductChangeNotifier::enqueue()/Api\ProductQueueItemRepositoryInterface::findPendingItem()), is
 * that at most one STATUS_PENDING row exists per (product_id, store_id) at any time: a new change
 * to a product that's already pending reuses that same row (operation refreshed, attempts reset to
 * 0) instead of adding another one, so unsent work doesn't grow unbounded with repeat edits.
 */
interface ProductQueueItemInterface
{
    const ENTITY_ID = 'entity_id';
    const PRODUCT_ID = 'product_id';
    const STORE_ID = 'store_id';
    const OPERATION = 'operation';
    const STATUS = 'status';
    const ATTEMPTS = 'attempts';
    const RESULT = 'result';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    const OPERATION_UPDATE = 'update';
    const OPERATION_DELETE = 'delete';

    // Not yet sent, or sent and failed but still under ProductQueueSync::MAX_ATTEMPTS - the only
    // status getPendingItems() picks up, so a row stays here across retries on separate cron runs
    // (see ProductQueueSync::failItem()). Once MAX_ATTEMPTS is reached it moves to STATUS_ERROR and
    // stops being retried automatically; it only comes back to STATUS_PENDING (attempts reset to
    // 0) if the product changes again and gets re-queued (see ProductChangeNotifier::enqueue()).
    const STATUS_PENDING = 'pending';

    // Failed on every attempt up to ProductQueueSync::MAX_ATTEMPTS - a dead end until the product
    // changes again, not automatically retried by any cron run.
    const STATUS_ERROR = 'error';

    // Sent successfully. Kept, not deleted - see the class docblock.
    const STATUS_SUCCESS = 'success';

    /**
     * @return int|null
     */
    public function getEntityId();

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId($entityId);

    /**
     * @return int
     */
    public function getProductId();

    /**
     * @param int $productId
     * @return $this
     */
    public function setProductId($productId);

    /**
     * @return int
     */
    public function getStoreId();

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId($storeId);

    /**
     * @return string
     */
    public function getOperation();

    /**
     * @param string $operation
     * @return $this
     */
    public function setOperation($operation);

    /**
     * @return string
     */
    public function getStatus();

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus($status);

    /**
     * @return int
     */
    public function getAttempts();

    /**
     * @param int $attempts
     * @return $this
     */
    public function setAttempts($attempts);

    /**
     * @return string|null
     */
    public function getResult();

    /**
     * @param string|null $result
     * @return $this
     */
    public function setResult($result);

    /**
     * @return string
     */
    public function getCreatedAt();

    /**
     * @param string $createdAt
     * @return $this
     */
    public function setCreatedAt($createdAt);

    /**
     * @return string
     */
    public function getUpdatedAt();

    /**
     * @param string $updatedAt
     * @return $this
     */
    public function setUpdatedAt($updatedAt);
}
