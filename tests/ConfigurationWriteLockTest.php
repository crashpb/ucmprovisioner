<?php
declare(strict_types=1);
// Exercise the production lock boundary with two simulated MySQL sessions.
if (!interface_exists('BMO')) { interface BMO {} }
if (!class_exists('FreePBX_Helpers')) { class FreePBX_Helpers {} }
require_once dirname(__DIR__) . '/Ucmprovisioner.class.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

final class ConfigurationLockPdo extends PDO
{
    public static array $owners = [];
    public function __construct() { parent::__construct('sqlite::memory:'); }
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : parent::getAttribute($attribute); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ConfigurationLockStatement($this, str_contains($query, 'RELEASE_LOCK'));
    }
}
final class ConfigurationLockStatement extends PDOStatement
{
    private int $result = 0;
    public function __construct(private ConfigurationLockPdo $db, private bool $release) {}
    public function execute(?array $params = null): bool {
        $key = (string)$params[0]; $owner = spl_object_id($this->db);
        if ($this->release) {
            $this->result = (ConfigurationLockPdo::$owners[$key] ?? null) === $owner ? 1 : 0;
            if ($this->result) { unset(ConfigurationLockPdo::$owners[$key]); }
        } else {
            $this->result = !isset(ConfigurationLockPdo::$owners[$key]) || ConfigurationLockPdo::$owners[$key] === $owner ? 1 : 0;
            if ($this->result) { ConfigurationLockPdo::$owners[$key] = $owner; }
        }
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->result; }
}
final class ConfigurationLockModule extends \FreePBX\modules\Ucmprovisioner
{
    public function __construct() {
        parent::__construct(new class {
            public function Database(): PDO { return new ConfigurationLockPdo(); }
        });
    }
    public function localSiteId(): int { return 1; }
}
$owner = new ConfigurationLockModule(); $competitor = new ConfigurationLockModule();
$owner->withConfigurationSyncLock(function () use ($owner,$competitor): void {
    if ($owner->withConfigurationSyncLock(static fn(): int => 42) !== 42) { throw new LogicException('Nested lock failed'); }
    try { $competitor->withConfigurationSyncLock(static fn(): bool => true); throw new LogicException('Concurrent configuration writer entered'); }
    catch (RuntimeException $error) { if ($error instanceof LogicException) { throw $error; } }
});
try { $owner->withConfigurationSyncLock(static function (): void { throw new DomainException('fixture'); }); } catch (DomainException) {}
if (!$competitor->withConfigurationSyncLock(static fn(): bool => true) || ConfigurationLockPdo::$owners) { throw new LogicException('Lock was not released after failure'); }
echo "Configuration writer exclusion, reentrancy and exception release passed\n";
