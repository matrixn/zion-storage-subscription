<?php
declare(strict_types=1);
namespace Zion;
final class StorageApiException extends \RuntimeException {
 public function __construct(public readonly int $httpStatus){parent::__construct('storage_request_failed');}
}
