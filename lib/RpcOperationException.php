<?php
namespace FreePBX\modules\Ucmprovisioner;

/** An authenticated destination result, never grounds for transport replay. */
class RpcOperationException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $receipt = [])
    {
        parent::__construct($message);
    }
}
