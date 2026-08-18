<?php

namespace Bydn\SoloSearch\Api\Data;

/**
 * A single pending change (update or delete) for one product on one store view, waiting to be
 * sent to SoloSearch's single-product API. Rows are removed once sent successfully - this table
 * only ever holds work still to do, plus recently-failed rows kept around for retry/inspection.
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

    const STATUS_PENDING = 'pending';
    const STATUS_ERROR = 'error';

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
