<?php
declare(strict_types=1);
namespace FreePBX\modules\Ucmprovisioner;

/** Connectivity/service failure; authentication and certificate failures are not fallback signals. */
final class ApiUnavailableException extends \RuntimeException {}
