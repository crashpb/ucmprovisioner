<?php
/**
 * FreePBX entry point for UCM Provisioner.
 *
 * FreePBX calls this class for page routing, authenticated actions, schema
 * upgrades and inter-site RPC dispatch. Device policy, file generation,
 * directory synchronization and replication live in focused classes under
 * lib/; this class connects those services to the FreePBX BMO lifecycle.
 *
 * @package FreePBX\modules\Ucmprovisioner
 * @author crashpb
 * @license GPL-3.0-or-later
 */

namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use PDO;
use FreePBX\modules\Ucmprovisioner\LogicalExtensionService;

require_once __DIR__ . '/lib/ServiceFilePermissions.php';
require_once __DIR__ . '/lib/AssetFilename.php';

class Ucmprovisioner extends FreePBX_Helpers implements BMO
{
    /** @var \FreePBX */
    private $FreePBX;
    /** @var \PDO */
    private $db;
    /** @var array<string,object> lazily-created engine instances */
    private array $engines = [];
    /** @var array<int,object> request-local SSH/SFTP connection cache */
    private array $siteConnections = [];
    private array $replicationCapabilities = [];
    private int $replicationActivityId = 0;
    private string $replicationProgressError = '';
    /** Connection edits used only by the current read-only Sites action. */
    private array $siteActionDrafts = [];
    private array $siteActionSavedBase = [];
    /** @var array<string,array<string,mixed>> request-local remote read provenance */
    private array $siteReadStates = [];
    /** @var \FreePBX\modules\Ucmprovisioner\SiteSnapshotCache|null */
    private $siteSnapshotCache = null;
    /** Prevent destination soft-lock guards from treating a verified sync import as a local edit. */
    private bool $configurationSyncApplying = false;
    private bool $configurationWriteLocked = false;
    /** One live dependency inventory per destination/import request. */
    private array $configurationSyncLiveAssets = [];
    private bool $configurationSyncReconciling = false;
    /** Side effects run only after the object and its ownership have committed. */
    private array $configurationSyncAfterCommit = [];
    private array $configurationSyncScheduleLocks = [];

    public function __construct($freepbx = null)
    {
        if ($freepbx === null) {
            throw new \Exception('Need a FreePBX Object');
        }
        $this->FreePBX = $freepbx;
        $this->db = $freepbx->Database();

        // Load the bundled SSH/SFTP dependency used for remote-site management.
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }
        // Load first-party service classes even when Composer is unavailable on the PBX.
        spl_autoload_register(function ($class) {
            $prefix = 'FreePBX\\modules\\Ucmprovisioner\\';
            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }
            $rel = substr($class, strlen($prefix));
            $path = __DIR__ . '/lib/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* BMO lifecycle                                                     */
    /* ---------------------------------------------------------------- */
    public function install()
    {
        // module.xml creates fresh tables; this versioned pass also repairs indexes on
        // already-installed systems because CREATE TABLE IF NOT EXISTS cannot add them.
        $this->ensureSchema(true);
        $this->ensureModelCatalog();
        // Existing accepted AD ownership stays usable after upgrade. The shared
        // compatibility check excludes manual/ambiguous identities and never
        // edits their override values or publishes phone/PBX configuration.
        $logical = new \FreePBX\modules\Ucmprovisioner\LogicalExtensionService($this->db);
        $legacyPlan = $logical->planLegacyIdentityAdoption();
        $logical->adoptLegacyIdentityRelationships($legacyPlan['eligible']);
    }
    public function uninstall() {}    // handled by uninstall.php
    public function backup() {}
    public function restore($backup) {}

    /** FreePBX reads this declaration before applying its global permission reset. */
    public function chownFreepbx(): array
    {
        return self::permissionResetPaths('/var/lib/asterisk/ucmprovisioner', '/etc/asterisk/keys/ucmp_id_rsa');
    }

    /** Metadata-only: never reads credentials or changes the filesystem itself. */
    private static function permissionResetPaths(string $root, string $sshKey): array
    {
        clearstatcache();
        $files = [];
        $add = static function (string $path, string $type, int $mode, ?string $owner = null) use (&$files): void {
            for ($parent = $path; dirname($parent) !== $parent; $parent = dirname($parent)) {
                if (is_link($parent)) { throw new \RuntimeException('Linked UCM permission-reset path: ' . $path); }
            }
            if (file_exists($path) && ($type === 'dir' ? !is_dir($path) : !is_file($path))) {
                throw new \RuntimeException('Unexpected UCM permission-reset file type: ' . $path);
            }
            if ($owner === null && file_exists($path) && function_exists('posix_getpwuid')) {
                $account = posix_getpwuid(fileowner($path));
                $owner = $account['name'] ?? '';
                if (!in_array($owner, ['root', 'asterisk', 'ucmp-pbx'], true)) {
                    throw new \RuntimeException('Unexpected UCM permission-reset service owner: ' . $path);
                }
            }
            $files[] = ['type'=>$type, 'path'=>$path, 'perms'=>$mode, 'owner'=>$owner ?? 'asterisk', 'group'=>'asterisk'];
        };
        $add($root, 'dir', 0770, 'asterisk');
        if (is_dir($root)) {
            // Explicit entries avoid recursive defaults widening nested private stores
            // and let us reject links before FreePBX starts its permission pass.
            $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($paths as $item) {
                $path = $item->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
                $area = explode('/', $relative, 2)[0];
                $dir = $item->isDir();
                $mode = $dir ? 0700 : 0600;
                $owner = null;
                if (in_array($area, ['media', 'media-quarantine', 'media-transfers', 'directory', 'api-nonces', 'site-snapshots', 'asterisk-incoming'], true)) {
                    $mode = $dir ? 0770 : (in_array($area, ['media', 'media-quarantine', 'media-transfers'], true) ? 0660 : 0640);
                }
                // API and restricted SSH workers share the same mesh transaction.
                if (in_array($area,['publish-mesh','editor-incoming'],true)) { $mode = $dir ? 0770 : 0660; }
                if ($relative === 'configuration-packages') { $mode = 0770; }
                if ($area === 'asterisk-bundles') { $mode = $dir ? 0750 : 0640; $owner = 'root'; }
                if ($relative === 'secrets') { $mode = 0750; $owner = 'asterisk'; }
                if (in_array($relative, ['secrets/credentials.key', 'secrets/credential-migration.json'], true)) { $mode = 0640; }
                if ($relative === 'secrets/credential-migration.lock' || (!$dir && !str_contains($relative, '/') && str_ends_with($relative, '.lock'))) { $mode = 0660; }
                if (preg_match('~(?:^|/)\.retired-assets(?:/|$)~', $relative)) {
                    $mode = $dir ? 0700 : 0600;
                    // Restore uses rename: keep retained payload/catalog modes behind
                    // the private transaction directory, including quarantined libraries.
                    if (preg_match('~(?:^|/)\.retired-assets/[^/]+/(?:payload$|catalog(?:/|$))~', $relative)) {
                        $mode = $item->getPerms() & 0777;
                    }
                }
                if ($relative === 'secrets/directory' || str_starts_with($relative, 'secrets/directory/')) {
                    $mode = $dir ? 0700 : 0600; $owner = 'asterisk';
                }
                if (in_array($relative, ['web-phonebook.json', 'web-phonebook.lock'], true)) { $mode = 0640; }
                // Preserve private RPC work ownership captured before framework defaults
                // run: transferring a 0700 ucmp-pbx store to asterisk would break recovery.
                $type = in_array($relative, ['web-phonebook.json', 'web-phonebook.lock', 'secrets/credentials.key', 'secrets/credential-migration.json', 'secrets/credential-migration.lock'], true)
                    ? 'file' : ($dir ? 'dir' : 'file');
                if (in_array($relative, ['secrets', 'secrets/directory', 'media', 'media-quarantine', 'media-transfers', 'directory', 'api-nonces', 'site-snapshots', 'asterisk-incoming', 'asterisk-bundles', 'configuration-packages', 'core-recovery', 'publish-mesh'], true)) { $type = 'dir'; }
                $add($path, $type, $mode, $owner);
            }
        }
        foreach ([''=>0640, '.pub'=>0644, '.lock'=>0660] as $suffix=>$mode) {
            $add($sshKey . $suffix, 'file', $mode);
        }
        return $files;
    }

    /* ---------------------------------------------------------------- */
    /* Page routing                                                      */
    /* ---------------------------------------------------------------- */
    public function doConfigPageInit($page)
    {
        $this->ensureSchema();
        $this->ensureModelCatalog();
        $action = (string)($_POST['action'] ?? '');
        if ($action === '') {
            return;
        }
        try {
            $this->assertPageMutationRequest();
            switch ($action) {
                case 'savesite':    $this->saveSite($_REQUEST);        break;
                case 'delsite':     $this->deleteSite((int)($_REQUEST['id'] ?? 0)); break;
                case 'savetemplate':
                    $this->saveTemplate($_REQUEST);
                    $templateSiteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    unset($_REQUEST['view'], $_REQUEST['id']);
                    $_REQUEST['site_id'] = $templateSiteId;
                    $_REQUEST['site_filter'] = (string)$templateSiteId;
                    break;
                case 'deltemplate': $this->deleteTemplate((int)($_REQUEST['id'] ?? 0), $this->nullableInt($_REQUEST['site_id'] ?? null), $_REQUEST); break;
                case 'duptemplate': $this->duplicateTemplate((int)($_REQUEST['id'] ?? 0), $this->nullableInt($_REQUEST['site_id'] ?? null)); break;
                case 'savegroup':
                    $this->saveGroup($_REQUEST);
                    $groupSiteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    unset($_REQUEST['view'], $_REQUEST['id']);
                    $_REQUEST['site_id'] = $groupSiteId;
                    $_REQUEST['site_filter'] = (string)$groupSiteId;
                    break;
                case 'delgroup':    $this->deleteGroup((int)($_REQUEST['id'] ?? 0), $this->nullableInt($_REQUEST['site_id'] ?? null), $_REQUEST); break;
                case 'savephone':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    $saveResult = $this->savePhoneAssignment($_REQUEST);
                    $savedId = (int)($saveResult['id'] ?? 0);
                    if (!empty($_REQUEST['rebuild_after']) && $savedId) {
                        $affected = array_values(array_unique(array_filter(array_map('intval', (array)($saveResult['affected_ids'] ?? [])))));
                        if ($affected) {
                            $selection = implode(',', $affected);
                            $replaceManual = !empty($_REQUEST['replace_manual']);
                            $preview = $this->rebuild(
                                $selection,
                                false,
                                $siteId,
                                false,
                                false,
                                null,
                                null,
                                $replaceManual
                            );
                            $rr = !empty($preview['ok']) && !empty($preview['plan_hash'])
                                ? $this->rebuild(
                                    $selection,
                                    true,
                                    $siteId,
                                    false,
                                    true,
                                    (string)$preview['plan_hash'],
                                    null,
                                    $replaceManual
                                )
                                : $preview;
                            if (empty($rr['ok'])) {
                                $this->addError('Saved, but rebuild afterward failed: ' . ($rr['message'] ?? 'unknown error'));
                            }
                        }
                        foreach (array_unique(array_map('intval', (array)($saveResult['unassigned_ids'] ?? []))) as $unassignedId) {
                            $cleared = ($siteId > 0 && !$this->isSiteLocal($siteId))
                                ? $this->remoteQuery($siteId, 'clearPhoneConfigLocal', [$unassignedId])
                                : $this->clearPhoneConfigLocal($unassignedId);
                            if (empty($cleared['ok'])) {
                                $this->addError('Phone was unassigned, but its old TFTP config could not be removed: ' . ($cleared['message'] ?? 'unknown error'));
                            }
                        }
                    }
                    // Return to the inventory after Save or Save & Rebuild, but
                    // retain the site the operator was working on.
                    unset($_REQUEST['view'], $_REQUEST['id']);
                    $_REQUEST['site_id'] = $siteId;
                    $_REQUEST['site_filter'] = (string)$siteId;
                    break;
                case 'delphone':    $this->deletePhone((int)($_REQUEST['id'] ?? 0), $this->nullableInt($_REQUEST['site_id'] ?? null), (string)($_REQUEST['expected_binding']??'')); break;
                case 'savesettings':$this->saveSettings($_REQUEST);    break;
                case 'savedirectory':$this->saveDirectorySettings($_REQUEST); break;
                case 'savewebphonebook':
                    $this->saveWebDirectoryPolicy(
                        (array)($_POST['web_phonebook'] ?? []),
                        (string)($_POST['web_phonebook_revision'] ?? ''),
                        $_POST,
                        isset($_FILES['web_phonebook_favicon']) ? (array)$_FILES['web_phonebook_favicon'] : null
                    );
                    $this->logOperation($this->localSiteId(), 'web_phonebook_policy', 'info', 'Saved local web phonebook access and field policy');
                    break;
                case 'savedirectorysource':
                    $source = $this->saveDirectorySource((array)($_POST['source'] ?? []));
                    $_REQUEST['directory_source_id'] = (int)$source['id'];
                    unset($_REQUEST['directory_source_new']);
                    break;
                case 'savedirectorysourcesecret':
                    $directorySecret = (array)($_POST['directory_secret'] ?? []);
                    $directorySourceId = (int)($directorySecret['source_id'] ?? 0);
                    try {
                        $this->saveDirectorySourceSecret($directorySecret);
                    } finally {
                        unset($_POST['directory_secret'], $_REQUEST['directory_secret']);
                    }
                    $_REQUEST['directory_source_id'] = $directorySourceId;
                    break;
                case 'toggledirectorysource':
                    $this->setDirectorySourceEnabled(
                        (int)($_POST['source_id'] ?? 0),
                        !empty($_POST['enabled']),
                        (int)($_POST['expected_updated'] ?? -1)
                    );
                    break;
                case 'saveidentityoverride':$this->saveDirectoryIdentityOverride($_REQUEST); break;
                case 'revertidentityoverride':$this->revertDirectoryIdentityOverride($_REQUEST); break;
                case 'savephonebook': $_REQUEST['book_id'] = $this->saveDirectoryPhonebook($_REQUEST); unset($_REQUEST['book_new']); break;
                case 'delphonebook': $this->deleteDirectoryPhonebook((int)($_REQUEST['book_id'] ?? 0), $_REQUEST); unset($_REQUEST['book_id']); break;
                case 'dupphonebook': $_REQUEST['book_id'] = $this->duplicateDirectoryPhonebook((int)($_REQUEST['book_id'] ?? 0)); break;
                case 'exportconfiguration':
                    $archiveMode=(string)($_POST['archive_mode'] ?? 'configuration');
                    if ($archiveMode==='recovery' && empty($_POST['confirm_recovery_secrets'])) { throw new \InvalidArgumentException('Confirm restricted handling of the recovery package containing secrets'); }
                    $archive = (new \FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService($this->db, $this))->export(
                        (array)($_POST['archive_scopes'] ?? []),
                        $this->currentModuleVersion(), $archiveMode
                    );
                    $archivePath = (string)$archive['path'];
                    if (!is_file($archivePath) || headers_sent()) {
                        if ($archiveMode !== 'recovery') { @unlink($archivePath); }
                        throw new \RuntimeException('The configuration package was created, but the download response could not be started');
                    }
                    while (ob_get_level() > 0) { ob_end_clean(); }
                    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . basename((string)$archive['filename']) . '"');
                    header('Content-Length: ' . (string)filesize($archivePath));
                    header('Cache-Control: no-store, max-age=0');
                    header('X-Content-Type-Options: nosniff');
                    readfile($archivePath);
                    if ($archiveMode!=='recovery') { @unlink($archivePath); }
                    exit;
                case 'importconfiguration':
                    if (empty($_POST['confirm_configuration_import'])) {
                        throw new \InvalidArgumentException('Confirm that the selected configuration tabs may be merged into this controller');
                    }
                    $upload = (array)($_FILES['configuration_package'] ?? []);
                    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                        || empty($upload['tmp_name'])
                        || !is_uploaded_file((string)$upload['tmp_name'])) {
                        throw new \InvalidArgumentException('Choose a configuration ZIP package to import');
                    }
                    $importMode=(string)($_POST['archive_mode'] ?? 'configuration');
                    if ($importMode==='recovery' && empty($_POST['confirm_recovery_secrets'])) { throw new \InvalidArgumentException('Confirm restricted recovery import with the matching database/key pair'); }
                    $importResult = (new \FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService($this->db, $this))->import(
                        (string)$upload['tmp_name'],
                        (array)($_POST['archive_scopes'] ?? []), $importMode
                    );
                    $_REQUEST['configuration_archive_result'] = $importResult;
                    $_REQUEST['tab'] = 'settings';
                    $_REQUEST['view'] = 'form';
                    break;
                default: break;
            }
        } catch (\Throwable $e) {
            $this->addError($e->getMessage());
        }
        // Module-owned saves do not require FreePBX's global Apply Config.
        // The few operations that actually mutate Core endpoint data mark or
        // perform their reload at that narrower mutation boundary.
    }

    public function getActionBar($request)
    {
        if (($request['display'] ?? '') !== 'ucmprovisioner') {
            return [];
        }
        $view = $request['view'] ?? '';
        if ($view === '' ) {
            return [];
        }

        $tab = $request['tab'] ?? 'dashboard';
        $id = (int)($request['id'] ?? 0);

        $siteIdParam = !empty($request['site_id']) ? ('&site_id=' . (int)$request['site_id']) : '';
        if (empty($siteIdParam) && $id > 0 && $tab === 'phones') {
            $p = $this->getPhoneLocal($id);
            if ($p && !empty($p['site_id'])) {
                $siteIdParam = '&site_id=' . (int)$p['site_id'];
            }
        }

        // Map tab to its delete action prefix
        $delActionMap = [
            'sites'     => 'delsite',
            'templates' => 'deltemplate',
            'groups'    => 'delgroup',
            'phones'    => 'delphone'
        ];
        $action = $delActionMap[$tab] ?? 'delete';

        $confirmMap = [
            'sites'     => _('Are you sure you want to delete this site?'),
            'templates' => _('Are you sure you want to delete this template?'),
            'groups'    => _('Are you sure you want to delete this group?'),
            'phones'    => _('Are you sure you want to delete this phone?')
        ];
        $confirmMsg = $confirmMap[$tab] ?? _('Are you sure you want to delete this?');

        $deleteUrl = "config.php?display=ucmprovisioner&tab={$tab}&action={$action}&id={$id}{$siteIdParam}";
        $buttons = [
            'delete' => [
                'name'    => 'delete', 
                'id'      => 'ucm-delete', 
                'value'   => _('Delete'),
                'type'    => 'button',
                'class'   => 'btn btn-danger',
                'onclick' => htmlspecialchars("if(confirm('" . addslashes($confirmMsg) . "')) { window.ucmpPostAction(" .
                    json_encode($deleteUrl, JSON_HEX_APOS | JSON_HEX_QUOT) . "); }", ENT_QUOTES, 'UTF-8')
            ],
            'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')],
            'reset'  => ['name' => 'reset',  'id' => 'reset',  'value' => _('Reset')],
        ];
        if (empty($request['id'])) {
            unset($buttons['delete']);
        }
        return $buttons;
    }

    /** Session-bound token shared by page forms and authenticated AJAX calls. */
    public function pageCsrfToken(): string
    {
        $key = 'ucmprovisioner_page_csrf';
        $token = (string)($_SESSION[$key] ?? '');
        if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return $token;
        }
        // FreePBX closes the session lock before authenticated BMO AJAX handlers,
        // but leaves the page-created session data readable.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('The UCM Provisioner session token is unavailable; reload the page and try again');
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION[$key] = $token;
        return $token;
    }

    private function assertPageMutationRequest(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new \RuntimeException('UCM Provisioner changes require an authenticated POST request');
        }
        $supplied = (string)($_POST['ucmp_csrf'] ?? ($_SERVER['HTTP_X_UCM_PROVISIONER_CSRF'] ?? ''));
        $expected = $this->pageCsrfToken();
        if ($supplied === '' || !hash_equals($expected, $supplied)) {
            throw new \RuntimeException('The UCM Provisioner form token is missing or expired; reload the page and try again');
        }
    }

    private function assertAjaxRequest(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            throw new \RuntimeException('UCM Provisioner AJAX requests require POST');
        }
        $supplied = (string)($_POST['ucmp_csrf'] ?? ($_SERVER['HTTP_X_UCM_PROVISIONER_CSRF'] ?? ''));
        $expected = $this->pageCsrfToken();
        if ($supplied === '' || !hash_equals($expected, $supplied)) {
            throw new \RuntimeException('The UCM Provisioner AJAX token is missing or expired; reload the page and try again');
        }
    }

    /** Authenticate the dedicated read-only TFTP download endpoint with the page session. */
    public function assertTftpDownloadRequest(): void
    {
        if (!defined('FREEPBX_IS_AUTH') || !FREEPBX_IS_AUTH) {
            throw new \RuntimeException('An authenticated FreePBX session is required');
        }
        $this->assertPageMutationRequest();
    }

    /* ---------------------------------------------------------------- */
    /* AJAX (async ops: discovery, tftp browser, phone commands, rebuild)*/
    /* ---------------------------------------------------------------- */
    public function ajaxRequest($command, &$setting)
    {
        if ($command === 'operatorworkflow') { return true; }
        if ($command === 'operationlogs') { return true; }
        $allowed = ['discover', 'rebuild', 'preview', 'command', 'commandbatch', 'deletephones', 'tftp', 'tftparchitecture', 'mediaasset', 'mediareplication', 'firmwarereplication', 'configurationsync', 'replicationactivity', 'fail2ban', 'patchworkflow', 'phoneservices', 'meshsync', 'extensions', 'templatesettings', 'phonebooks', 'phonebookcopy', 'phonebookpreview', 'testsite', 'netscan', 'livestatus', 'backfillmacs', 'sshfingerprint', 'bootstrapsite', 'bootstrappbxtftp', 'bootstrappbx', 'bootstrapapi', 'listtrunks', 'remoteversion', 'remoteinstall', 'signmodule', 'adtest', 'adtestsource', 'adtree', 'adsync', 'adsyncsource', 'adresolve', 'adcreateaccount'];
        return in_array($command, $allowed, true);
    }

    public function ajaxHandler()
    {
        $command = is_string($_REQUEST['command'] ?? null) ? $_REQUEST['command'] : '';
        try {
            $this->assertAjaxRequest();
            if ($command === 'operationlogs') {
                // This read does not update session state. Leave existing
                // mutation request serialization unchanged.
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                $siteId = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($siteId === false) { throw new \InvalidArgumentException('Choose a saved site to read its operation log'); }
                return ['status'=>true, 'result'=>$this->siteOperationLogs($siteId)];
            }
            $this->ensureSchema();
            $this->ensureModelCatalog();
            $this->useSiteConnectionDraft($_REQUEST, (string)$command);
            switch ($command) {
                case 'operatorworkflow':
                    return ['status' => true, 'result' => $this->operatorWorkflowOp($_REQUEST)];
                 case 'discover':
                    return ['status' => true, 'result' => $this->discover((int)($_REQUEST['site_id'] ?? 0))];
                case 'rebuild':
                    // A site-wide build can legitimately exceed PHP's common
                    // 30-second web default. Keep it bounded while the shared
                    // request-local SFTP session avoids per-phone handshakes.
                    @set_time_limit(300);
                    $sel = $_REQUEST['ids'] ?? 'all';
                    $apply = (($_REQUEST['apply'] ?? '') === '1');
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    $restartAfter = (($_REQUEST['restart_after'] ?? '') === '1');
                    $replaceManual = (($_REQUEST['replace_manual'] ?? '') === '1');
                    $planHash = trim((string)($_REQUEST['plan_hash'] ?? ''));
                    $meshPlanHash = trim((string)($_REQUEST['mesh_plan_hash'] ?? ''));
                    return ['status' => true, 'result' => $this->rebuild(
                        $sel,
                        $apply,
                        $siteId,
                        $restartAfter,
                        false,
                        $planHash !== '' ? $planHash : null,
                        $meshPlanHash !== '' ? $meshPlanHash : null,
                        $replaceManual
                    )];
                case 'preview':
                    $id = (int)($_REQUEST['id'] ?? 0);
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    return ['status' => true, 'result' => $this->previewPhone($id, $siteId)];
                case 'command':
                    $id = (int)($_REQUEST['id'] ?? 0);
                    $cmd = (string)($_REQUEST['cmd'] ?? '');
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    return ['status' => true, 'result' => $this->sendPhoneCommand($id, $cmd, $siteId)];
                case 'commandbatch':
                    $ids = is_array($_REQUEST['ids'] ?? null)
                        ? $_REQUEST['ids']
                        : preg_split('/\s*,\s*/', (string)($_REQUEST['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                    $cmd = (string)($_REQUEST['cmd'] ?? '');
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    return ['status' => true, 'result' => $this->sendPhoneCommands((array)$ids, $cmd, $siteId)];
                case 'deletephones':
                    $ids = is_array($_REQUEST['ids'] ?? null)
                        ? $_REQUEST['ids']
                        : preg_split('/\s*,\s*/', (string)($_REQUEST['ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    $bindings=is_array($_REQUEST['expected_bindings']??null)?$_REQUEST['expected_bindings']:json_decode((string)($_REQUEST['expected_bindings']??''),true);
                    if (!is_array($bindings)) { throw new \InvalidArgumentException('Reload the phone inventory before deleting its bindings'); }
                    return ['status' => true, 'result' => $this->deletePhones((array)$ids, $siteId, $bindings)];
                case 'tftp':
                    $tftpOp = strtolower(trim((string)($_REQUEST['op'] ?? '')));
                    if (in_array($tftpOp,\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::OPERATIONS,true)) { @set_time_limit(600); }
                    if (in_array($tftpOp, ['sync_cisco_locale_bundle','sync_timezone_version','sync_firmware_bundle','sync_timezone_bundle','sync_wallpaper_media'], true)) {
                        @set_time_limit(600);
                        return ['status'=>true, 'result'=>$this->trackedReplication('managed-assets', $this->replicationRequestLabel($tftpOp, $_REQUEST), $_REQUEST, fn(): array => (array)$this->tftpOp($_REQUEST))];
                    }
                    return ['status' => true, 'result' => $this->tftpOp($_REQUEST)];
                case 'tftparchitecture':
                    @set_time_limit(0);
                    return ['status'=>true, 'result'=>$this->tftpArchitectureOp($_REQUEST)];
                case 'mediaasset':
                    $mediaOp = strtolower(trim((string)($_REQUEST['op'] ?? '')));
                    if (in_array($mediaOp, ['sync','special_catalog_sync'], true)) {
                        @set_time_limit(600);
                        return ['status'=>true, 'result'=>$this->trackedReplication('media', $this->replicationRequestLabel($mediaOp, $_REQUEST), $_REQUEST, fn(): array => (array)$this->mediaAssetOp($_REQUEST))];
                    }
                    return ['status' => true, 'result' => $this->mediaAssetOp($_REQUEST)];
                case 'mediareplication':
                    if (strtolower((string)($_REQUEST['op'] ?? 'list')) === 'run') {
                        @set_time_limit(600);
                        return ['status'=>true, 'result'=>$this->trackedReplication('media', $this->replicationRequestLabel('media-job', $_REQUEST), $_REQUEST, fn(): array => (array)$this->mediaReplicationOp($_REQUEST))];
                    }
                    return ['status' => true, 'result' => $this->mediaReplicationOp($_REQUEST)];
                case 'firmwarereplication':
                    if (in_array(strtolower((string)($_REQUEST['op'] ?? 'list')), ['run','copy'], true)) {
                        @set_time_limit(600);
                        return ['status'=>true, 'result'=>$this->trackedReplication('firmware', $this->replicationRequestLabel('firmware-' . strtolower((string)($_REQUEST['op'] ?? 'copy')), $_REQUEST), $_REQUEST, fn(): array => (array)$this->firmwareReplicationOp($_REQUEST))];
                    }
                    return ['status' => true, 'result' => $this->firmwareReplicationOp($_REQUEST)];
                case 'configurationsync':
                    if (strtolower((string)($_REQUEST['op'] ?? 'list')) === 'run') {
                        @set_time_limit(600);
                        return ['status'=>true, 'result'=>$this->trackedReplication('configuration', $this->replicationRequestLabel('configuration-job', $_REQUEST), $_REQUEST, fn(): array => (array)$this->configurationSyncOp($_REQUEST))];
                    }
                    return ['status' => true, 'result' => $this->configurationSyncOp($_REQUEST)];
                case 'replicationactivity':
                    return ['status'=>true, 'result'=>['ok'=>true, 'runs'=>(new \FreePBX\modules\Ucmprovisioner\ReplicationActivityService($this->db))->recent((int)($_REQUEST['limit'] ?? 20))]];
                case 'fail2ban':
                    return ['status' => true, 'result' => $this->fail2banOp($_REQUEST)];
                case 'patchworkflow':
                    if (in_array(strtolower((string)($_REQUEST['op'] ?? '')), ['build','activate','rollback'], true)) {
                        @set_time_limit(0);
                    }
                    return ['status' => true, 'result' => $this->patchWorkflowOp($_REQUEST)];
                case 'phoneservices':
                    return ['status' => true, 'result' => $this->phoneServiceOp($_REQUEST)];
                case 'meshsync':
                    $apply = (($_REQUEST['apply'] ?? '') === '1');
                    $planHash = trim((string)($_REQUEST['plan_hash'] ?? ''));
                    return ['status' => true, 'result' => $this->syncPublishMesh($apply, [], $planHash ?: null)];
                case 'extensions':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    return ['status' => true, 'result' => ['ok' => true, 'extensions' => $this->getAllSipExtensions($siteId)]];
                case 'templatesettings':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    $settings = (new \FreePBX\modules\Ucmprovisioner\TemplateEngine($this))->effectiveSettings($siteId);
                    $settings = array_filter($settings, static fn(string $key): bool => !preg_match('/(?:password|secret)/i', $key), ARRAY_FILTER_USE_KEY);
                    return ['status'=>true, 'result'=>['ok'=>true, 'settings'=>$settings]];
                case 'phonebooks':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    return ['status'=>true,'result'=>$this->getPublishedDirectoryPhonebooks($siteId)];
                case 'phonebookcopy':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    return ['status'=>true,'result'=>$this->copyPublishedDirectoryPhonebookPolicy($siteId, (string)($_REQUEST['book_key'] ?? ''))];
                case 'phonebookpreview':
                    $draft = $_REQUEST['phonebook'] ?? [];
                    if (is_string($draft)) {
                        $draft = json_decode($draft, true);
                    }
                    if (!is_array($draft)) { throw new \InvalidArgumentException('Phonebook preview payload must be valid JSON'); }
                    return ['status'=>true,'result'=>$this->previewDirectoryPhonebookDraft($draft)];
                case 'testsite':
                    return ['status' => true, 'result' => $this->testSite((int)($_REQUEST['id'] ?? 0))];
                case 'netscan':
                    return ['status' => true, 'result' => $this->networkScan((int)($_REQUEST['site_id'] ?? 0), (string)($_REQUEST['cidr'] ?? ''), (string)($_REQUEST['method'] ?? 'nmap'), (string)($_POST['admin_user'] ?? ''), (string)($_POST['admin_pass'] ?? ''), !empty($_POST['use_sudo']))];
                case 'livestatus':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    return ['status' => true, 'result' => $this->liveStatus($siteId)];
                case 'backfillmacs':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0) ?: $this->localSiteId();
                    return ['status' => true, 'result' => $this->backfillLegacyMacs($siteId, true)];
                case 'bootstrapsite':
                    $id = (int)($_REQUEST['id'] ?? 0);
                    $host = $_REQUEST['host'] ?? '';
                    $port = (int)($_REQUEST['port'] ?? 22);
                    $rootPath = $_REQUEST['root_path'] ?? '/opt/tftp-server/tftpboot';
                    $adminUser = $_REQUEST['admin_user'] ?? '';
                    $adminPass = $_REQUEST['admin_pass'] ?? '';
                    $useSudo = (($_REQUEST['use_sudo'] ?? '') === '1');
                    return $this->siteActionResponse($id, $command, $this->bootstrapDockerHost($id, $host, $port, $rootPath, $adminUser, $adminPass, $useSudo, (string)($_REQUEST['expected_host_key'] ?? '')));
                case 'bootstrappbxtftp':
                    @set_time_limit(900);
                    return $this->siteActionResponse((int)($_REQUEST['id'] ?? 0), $command, $this->bootstrapPbxTftp(
                        (int)($_REQUEST['id'] ?? 0),
                        (string)($_REQUEST['admin_user'] ?? ''),
                        (string)($_REQUEST['admin_pass'] ?? ''),
                        (($_REQUEST['use_sudo'] ?? '') === '1'),
                        (($_REQUEST['install_missing'] ?? '1') === '1'),
                        (string)($_REQUEST['expected_host_key'] ?? '')
                    ));
                case 'bootstrappbx':
                case 'remoteinstall':
                    @set_time_limit(900);
                    return $this->siteActionResponse((int)($_REQUEST['id'] ?? 0), $command, $this->prepareRemoteSite(
                        (int)($_REQUEST['id'] ?? 0),
                        $this->remoteOnboardingInput($_REQUEST),
                        false
                    ));
                case 'sshfingerprint':
                    return ['status'=>true,'result'=>$this->probeSiteHostKey(
                        (int)($_REQUEST['id'] ?? 0),
                        (string)($_REQUEST['channel'] ?? 'pbx')
                    )];
                case 'bootstrapapi':
                    return $this->siteActionResponse((int)($_REQUEST['id'] ?? 0), $command, $this->configureSiteApi(
                        (int)($_REQUEST['id'] ?? 0),
                        (($_REQUEST['enabled'] ?? '1') === '1'),
                        (string)($_REQUEST['scheme'] ?? 'http'),
                        (string)($_REQUEST['host'] ?? ''),
                        (int)($_REQUEST['port'] ?? 80),
                        (($_REQUEST['verify_tls'] ?? '1') === '1')
                    ));
                case 'listtrunks':
                    $siteId = (int)($_REQUEST['site_id'] ?? 0);
                    return ['status' => true, 'result' => $this->listPjsipTrunks($siteId)];
                case 'remoteversion':
                    return $this->siteActionResponse((int)($_REQUEST['id'] ?? 0), $command, $this->remoteModuleStatus(
                        (int)($_REQUEST['id'] ?? 0),
                        (string)($_REQUEST['admin_user'] ?? ''),
                        (string)($_REQUEST['admin_pass'] ?? ''),
                        (string)($_REQUEST['expected_host_key'] ?? ''),
                        (($_REQUEST['use_sudo'] ?? '') === '1')
                    ));
                case 'signmodule':
                    return $this->siteActionResponse((int)($_REQUEST['id'] ?? 0), $command, $this->signSiteModule(
                        (int)($_REQUEST['id'] ?? 0),
                        (string)($_REQUEST['admin_user'] ?? ''),
                        (string)($_REQUEST['admin_pass'] ?? ''),
                        (($_REQUEST['use_sudo'] ?? '') === '1'),
                        (string)($_REQUEST['expected_host_key'] ?? '')
                    ));
                case 'adtest':
                    return ['status' => true, 'result' => $this->testDirectoryConnection($_REQUEST)];
                case 'adtestsource':
                    return ['status' => true, 'result' => $this->testDirectorySource((int)($_REQUEST['source_id'] ?? 0))];
                case 'adtree':
                    return ['status' => true, 'result' => $this->browseDirectoryTree($_REQUEST)];
                case 'adsync':
                    return ['status' => true, 'result' => $this->syncDirectory(true)];
                case 'adsyncsource':
                    $directorySourceId = (int)($_REQUEST['source_id'] ?? 0);
                    if ($directorySourceId < 1) { throw new \InvalidArgumentException('Directory source not found'); }
                    return ['status' => true, 'result' => $this->syncDirectory(true, $directorySourceId)];
                case 'adresolve':
                    return ['status' => true, 'result' => $this->resolveDirectoryConflict($_REQUEST)];
                case 'adcreateaccount':
                    return ['status' => true, 'result' => $this->createDirectoryServiceAccount($_REQUEST)];
            }
        } catch (\Throwable $e) {
            $result = $this->operationFailureResult($e, (string)$command);
            $revision=$this->siteActionEditorRevision((int)($_REQUEST['id']??0));
            if ($revision!==null) { $result['site_editor_revision']=$revision; }
            // A failed read must not write logs or create a polling error loop.
            if ($command !== 'operationlogs') {
                $this->logOperation((int)($_REQUEST['id'] ?? ($_REQUEST['site_id'] ?? 0)) ?: null, $command ?: 'ajax', 'error', $result['message'], $result['detail']);
            }
            return ['status' => false, 'message' => $result['message'], 'result' => $result];
        } finally {
            $this->siteActionDrafts = [];
            $this->siteActionSavedBase = [];
            $this->siteConnections = [];
        }
        return ['status' => false, 'message' => 'unknown command', 'result' => ['ok' => false, 'message' => 'unknown command']];
    }

    /* ---------------------------------------------------------------- */
    /* Data: models                                                      */
    /* ---------------------------------------------------------------- */
    public function getModels(): array
    {
        return $this->db->query("SELECT * FROM ucmp_models ORDER BY vendor, model")->fetchAll(PDO::FETCH_ASSOC);
    }
    /** Re-seed any model-catalog rows added since this site was installed (idempotent, never overwrites). */
    public function ensureModelCatalog(): void
    {
        $cls = '\\FreePBX\\modules\\Ucmprovisioner\\ModelCatalog';
        if (!class_exists($cls)) {
            return;
        }
        $stored = (int) $this->getSetting('models_catalog_version', null, 0);
        if ($stored >= $cls::CATALOG_VERSION) {
            return;
        }
        $cls::seed($this->db);
        $this->setSetting('models_catalog_version', (string) $cls::CATALOG_VERSION);
    }
    /**
     * Apply versioned repairs that cannot be expressed safely as a fresh-table definition.
     *
     * Module Admin reconciles module.xml, while this method upgrades data already present on
     * an installed PBX. The integrity passes below preserve the newest useful values, remove
     * duplicate physical-phone rows, and restore the database constraints that prevent the
     * same corruption from recurring.
     */
    /** Preserve every row, then make same-site template names unambiguous. */
    private function ensureTemplateNameIntegrity(): void
    {
        $index = $this->db->prepare("SHOW INDEX FROM ucmp_templates WHERE Key_name = ?");
        $index->execute(['uniq_ucmp_templates_site_name']);
        if ($index->fetch()) {
            return;
        }

        $duplicates = $this->db->query(
            'SELECT site_id,name FROM ucmp_templates GROUP BY site_id,name HAVING COUNT(*) > 1'
        )->fetchAll(PDO::FETCH_ASSOC);
        $members = $this->db->prepare(
            'SELECT id FROM ucmp_templates WHERE site_id <=> ? AND name=? ORDER BY id'
        );
        $exists = $this->db->prepare(
            'SELECT id FROM ucmp_templates WHERE site_id <=> ? AND name=? AND id<>? LIMIT 1'
        );
        $rename = $this->db->prepare(
            'UPDATE ucmp_templates SET name=?, revision=GREATEST(1,revision)+1, updated=? WHERE id=?'
        );
        foreach ($duplicates as $duplicate) {
            $members->execute([$duplicate['site_id'], $duplicate['name']]);
            $ids = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
            array_shift($ids); // The oldest identity keeps the established name.
            foreach ($ids as $id) {
                $attempt = 1;
                do {
                    $marker = ' (duplicate #' . $id . ($attempt > 1 ? '-' . $attempt : '') . ')';
                    $candidate = $this->templateNameWithSuffix((string)$duplicate['name'], $marker);
                    $exists->execute([$duplicate['site_id'], $candidate, $id]);
                    $attempt++;
                } while ($exists->fetchColumn());
                $rename->execute([$candidate, time(), $id]);
            }
        }
        $this->db->exec(
            'ALTER TABLE ucmp_templates ADD UNIQUE INDEX uniq_ucmp_templates_site_name (site_id,name)'
        );
    }

    private function templateNameWithSuffix(string $name, string $suffix): string
    {
        $prefixLength = max(0, 64 - mb_strlen($suffix, 'UTF-8'));
        return rtrim(mb_substr(trim($name), 0, $prefixLength, 'UTF-8')) . $suffix;
    }

    private const SCHEMA_VERSION = 34;
    public function ensureSchema(bool $force = false): void
    {
        $stored = (int) $this->getSetting('schema_version', null, 0);
        // Installed schemas are repaired by Module Admin, not on every page or poll.
        if (!$force && $stored >= self::SCHEMA_VERSION) { return; }
        $this->ensurePhoneEndpointSchema();
        $this->ensurePhoneMacSuppressionSchema();
        if ($stored >= 32) {
            // Keep the complete upgrade/explicit repair pass, including uniqueness guards.
            try {
                \FreePBX\modules\Ucmprovisioner\LogicalExtensionService::ensureIdentitySchema($this->db);
                $this->rpcOperationJournal()->ensureSchema();
                \FreePBX\modules\Ucmprovisioner\OperatorWorkflowService::ensureSchema($this->db);
                $this->ensurePhoneIntegrity();
                $this->ensureTemplateNameIntegrity();
                $this->ensureDirectorySourceIntegrity();
                $this->ensureDirectoryIntegrity();
                \FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\BuildTransactionService::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\ManagedAssetScanJob::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\MediaReplicationService::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\FirmwareReplicationService::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
                \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::ensureSchema($this->db);
                if ($stored < self::SCHEMA_VERSION) {
                    $this->setSetting('schema_version', (string)self::SCHEMA_VERSION);
                }
            }
            catch (\Throwable $e) { throw new \RuntimeException('Data integrity repair failed: ' . $e->getMessage(), 0, $e); }
            return;
        }
        try {
            $cols = $this->db->query('SHOW COLUMNS FROM ucmp_phones')->fetchAll(PDO::FETCH_COLUMN);
            $add = function (string $name, string $ddl) use ($cols) {
                if (!in_array($name, $cols, true)) {
                    $this->db->exec("ALTER TABLE ucmp_phones ADD COLUMN $ddl");
                }
            };
            $add('extension_id', 'extension_id INT NULL AFTER site_id');
            $add('driver', "driver VARCHAR(8) NOT NULL DEFAULT 'inherit' AFTER prev_extension");
            $add('sip_port', 'sip_port INT NOT NULL DEFAULT 0 AFTER transport');
            $add('applied_driver', 'applied_driver VARCHAR(8) NULL AFTER last_applied');
            $add('applied_transport', 'applied_transport VARCHAR(8) NULL AFTER applied_driver');
            $add('applied_sip_port', 'applied_sip_port INT NOT NULL DEFAULT 0 AFTER applied_transport');
            $add('prev_extension', 'prev_extension VARCHAR(20) NULL AFTER extension');
            $add('last_command', 'last_command VARCHAR(24) NULL AFTER last_seen');
            $add('last_command_at', 'last_command_at INT DEFAULT 0 AFTER last_command');
            $add('last_applied', 'last_applied INT DEFAULT 0 AFTER last_command_at');
            $add('config_mode', "config_mode VARCHAR(16) NOT NULL DEFAULT 'managed' AFTER config_filename");
            $add('config_override_sha256', 'config_override_sha256 CHAR(64) NULL AFTER config_mode');
            $add('config_overridden_at', 'config_overridden_at INT NOT NULL DEFAULT 0 AFTER config_override_sha256');
            $add('exclude_bulk_rebuild', 'exclude_bulk_rebuild TINYINT(1) NOT NULL DEFAULT 0 AFTER config_overridden_at');

            // Old releases had no Inherit choice, so every phone was stamped with
            // the then-current site transport. Convert only that default-equivalent
            // value; genuine per-phone TCP/TLS deviations remain explicit.
            $this->db->exec(
                "UPDATE ucmp_phones p
                   LEFT JOIN ucmp_settings s ON s.site_id=p.site_id AND s.skey='transport'
                    SET p.transport='inherit'
                  WHERE LOWER(COALESCE(p.transport,''))=LOWER(COALESCE(NULLIF(s.sval,''),'udp'))"
            );
            // Preserve the actual FreePBX driver for already-managed non-Cisco
            // endpoints while new phones can inherit the modern PJSIP site default.
            $this->db->exec(
                "UPDATE ucmp_phones p
                   LEFT JOIN ucmp_models m ON m.id=p.model_id
                   JOIN devices d ON d.id=p.extension AND d.tech IN ('sip','pjsip')
                    SET p.driver=d.tech
                  WHERE LOWER(COALESCE(m.vendor,p.vendor,''))<>'cisco'"
            );
            $this->db->exec("ALTER TABLE ucmp_phones MODIFY COLUMN transport VARCHAR(8) NOT NULL DEFAULT 'inherit'");

            $this->ensurePhoneIntegrity();

            // A phone's SIP-account limit, physical appearances and programmable keys are
            // different capabilities.  Keeping them separate prevents the old line_keys value
            // from allowing invalid account blocks on key-heavy models.
            $modelCols = $this->db->query('SHOW COLUMNS FROM ucmp_models')->fetchAll(PDO::FETCH_COLUMN);
            $addModel = function (string $name, string $ddl) use ($modelCols): void {
                if (!in_array($name, $modelCols, true)) { $this->db->exec("ALTER TABLE ucmp_models ADD COLUMN $ddl"); }
            };
            $addModel('role', "role VARCHAR(24) NOT NULL DEFAULT 'desk' AFTER family");
            $addModel('series_key', 'series_key VARCHAR(64) NULL AFTER role');
            $addModel('sip_accounts', 'sip_accounts SMALLINT NOT NULL DEFAULT 1 AFTER role');
            $addModel('line_appearances', 'line_appearances SMALLINT NOT NULL DEFAULT 1 AFTER sip_accounts');
            $addModel('programmable_keys', 'programmable_keys SMALLINT NOT NULL DEFAULT 0 AFTER line_appearances');
            $addModel('programmable_key_namespace', "programmable_key_namespace VARCHAR(24) NOT NULL DEFAULT 'linekey' AFTER programmable_keys");
            $addModel('programmable_key_pages', 'programmable_key_pages SMALLINT NOT NULL DEFAULT 1 AFTER programmable_key_namespace');
            $addModel('handsets_max', 'handsets_max SMALLINT NOT NULL DEFAULT 0 AFTER programmable_key_pages');
            $addModel('simultaneous_calls', 'simultaneous_calls SMALLINT NOT NULL DEFAULT 0 AFTER handsets_max');
            $addModel('expansion_types', 'expansion_types TEXT NULL AFTER simultaneous_calls');
            $addModel('max_expansion_modules', 'max_expansion_modules SMALLINT NOT NULL DEFAULT 0 AFTER expansion_types');
            $addModel('keys_per_expansion', 'keys_per_expansion SMALLINT NOT NULL DEFAULT 0 AFTER max_expansion_modules');
            $addModel('config_profile', 'config_profile VARCHAR(64) NULL AFTER keys_per_expansion');
            $addModel('firmware_profile', 'firmware_profile VARCHAR(64) NULL AFTER config_profile');
            $addModel('directory_profile', 'directory_profile VARCHAR(64) NULL AFTER firmware_profile');
            $addModel('media_profile', 'media_profile VARCHAR(64) NULL AFTER directory_profile');
            $addModel('auth_profile', 'auth_profile VARCHAR(64) NULL AFTER media_profile');

            // Retire the old global ownership model without losing operator data. Rows that
            // used to be global become owned by this PBX's local site; every other PBX performs
            // the same migration against its own database.
            $localId = $this->localSiteId();
            $this->db->exec(\FreePBX\modules\Ucmprovisioner\LogicalExtensionService::extensionsTableSql());
            \FreePBX\modules\Ucmprovisioner\LogicalExtensionService::ensureIdentitySchema($this->db);
            $this->rpcOperationJournal()->ensureSchema();
            \FreePBX\modules\Ucmprovisioner\OperatorWorkflowService::ensureSchema($this->db);
            $this->db->exec(\FreePBX\modules\Ucmprovisioner\LogicalExtensionService::overridesTableSql());
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_directory_sources (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    source_key VARCHAR(64) NOT NULL,
                    domain VARCHAR(255) NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    endpoints_json LONGTEXT NULL,
                    tls_mode VARCHAR(16) NOT NULL DEFAULT 'ldaps',
                    secret_ref VARCHAR(255) NULL,
                    base_dn TEXT NULL,
                    filter TEXT NULL,
                    attribute_map_json LONGTEXT NULL,
                    site_rules_json LONGTEXT NULL,
                    schedule_minutes INT NOT NULL DEFAULT 60,
                    schedule_json LONGTEXT NULL,
                    last_accepted_revision VARCHAR(64) NULL,
                    last_attempt INT NOT NULL DEFAULT 0,
                    last_success INT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    created INT NOT NULL DEFAULT 0,
                    updated INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_ucmp_directory_source_key (source_key),
                    UNIQUE KEY uniq_ucmp_directory_domain (domain)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $directorySourceColumns = $this->db->query('SHOW COLUMNS FROM ucmp_directory_sources')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('schedule_json', $directorySourceColumns, true)) {
                $this->db->exec('ALTER TABLE ucmp_directory_sources ADD COLUMN schedule_json LONGTEXT NULL AFTER schedule_minutes');
            }
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_directory_sync_runs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    source_id INT NOT NULL,
                    started INT NOT NULL DEFAULT 0,
                    finished INT NOT NULL DEFAULT 0,
                    status VARCHAR(24) NOT NULL DEFAULT 'running',
                    candidate_revision VARCHAR(64) NULL,
                    accepted_revision VARCHAR(64) NULL,
                    object_count INT NOT NULL DEFAULT 0,
                    added INT NOT NULL DEFAULT 0,
                    changed INT NOT NULL DEFAULT 0,
                    deleted INT NOT NULL DEFAULT 0,
                    error TEXT NULL,
                    KEY idx_ucmp_directory_sync_source_started (source_id,started)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_site_snapshots (
                    site_id INT NOT NULL,
                    resource_type VARCHAR(128) NOT NULL,
                    payload_json LONGTEXT NULL,
                    payload_hash CHAR(64) NULL,
                    api_version VARCHAR(32) NULL,
                    fetched_at INT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    last_error_at INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_ucmp_site_snapshot (site_id,resource_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_site_jobs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    site_id INT NOT NULL,
                    job_type VARCHAR(48) NOT NULL,
                    object_key VARCHAR(160) NOT NULL,
                    desired_hash CHAR(64) NOT NULL,
                    payload_ref VARCHAR(255) NULL,
                    state VARCHAR(24) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    next_attempt INT NOT NULL DEFAULT 0,
                    created INT NOT NULL DEFAULT 0,
                    updated INT NOT NULL DEFAULT 0,
                    last_error TEXT NULL,
                    UNIQUE KEY uniq_ucmp_site_job_desired (site_id,job_type,object_key,desired_hash),
                    KEY idx_ucmp_site_job_ready (site_id,state,next_attempt)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $copy = $this->db->prepare(
                'INSERT INTO ucmp_settings (site_id, skey, sval)
                 SELECT ?, g.skey, g.sval FROM ucmp_settings g
                 WHERE g.site_id IS NULL AND NOT EXISTS
                   (SELECT 1 FROM ucmp_settings l WHERE l.site_id = ? AND l.skey = g.skey)'
            );
            $copy->execute([$localId, $localId]);
            $this->db->prepare('UPDATE ucmp_templates SET site_id = ? WHERE site_id IS NULL')->execute([$localId]);
            $this->db->prepare('UPDATE ucmp_groups SET site_id = ? WHERE site_id IS NULL')->execute([$localId]);
            $this->db->exec('DELETE FROM ucmp_settings WHERE site_id IS NULL');
            $seedSiteSetting = $this->db->prepare(
                'INSERT INTO ucmp_settings (site_id, skey, sval)
                 SELECT ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM ucmp_settings WHERE site_id = ? AND skey = ?)'
            );
            foreach (['cisco_xml_timezone' => 'GMT Standard/Daylight Time', 'cisco_legacy_timezone' => 'GMT', 'yealink_timezone' => '+0'] as $key => $value) {
                $seedSiteSetting->execute([$localId, $key, $value, $localId, $key]);
            }

            $siteCols = $this->db->query('SHOW COLUMNS FROM ucmp_sites')->fetchAll(PDO::FETCH_COLUMN);
            $addSite = function (string $name, string $ddl) use ($siteCols) {
                if (!in_array($name, $siteCols, true)) {
                    $this->db->exec("ALTER TABLE ucmp_sites ADD COLUMN $ddl");
                }
            };
            $addSite('site_key', 'site_key VARCHAR(64) NULL AFTER id');
            $addSite('pbx_sip_port', 'pbx_sip_port INT DEFAULT 5160 AFTER pbx_addr');
            $addSite('pbx_agent_user', "pbx_agent_user VARCHAR(64) DEFAULT 'ucmp-pbx' AFTER pbx_host_key");
            $addSite('pbx_agent_ready', 'pbx_agent_ready TINYINT(1) DEFAULT 0 AFTER pbx_agent_user');
            $addSite('pbx_bootstrapped_at', 'pbx_bootstrapped_at INT DEFAULT 0 AFTER pbx_agent_ready');
            $addSite('pbx_api_enabled', 'pbx_api_enabled TINYINT(1) DEFAULT 0 AFTER pbx_bootstrapped_at');
            $addSite('pbx_api_ready', 'pbx_api_ready TINYINT(1) DEFAULT 0 AFTER pbx_api_enabled');
            $addSite('pbx_api_scheme', "pbx_api_scheme VARCHAR(8) DEFAULT 'http' AFTER pbx_api_ready");
            $addSite('pbx_api_host', 'pbx_api_host VARCHAR(128) NULL AFTER pbx_api_scheme');
            $addSite('pbx_api_port', 'pbx_api_port INT DEFAULT 80 AFTER pbx_api_host');
            $addSite('pbx_api_verify_tls', 'pbx_api_verify_tls TINYINT(1) DEFAULT 1 AFTER pbx_api_port');
            $addSite('pbx_api_secret', 'pbx_api_secret TEXT NULL AFTER pbx_api_verify_tls');
            $addSite('pbx_api_last_ok', 'pbx_api_last_ok INT DEFAULT 0 AFTER pbx_api_secret');
            $addSite('tftp_container', 'tftp_container VARCHAR(64) NULL AFTER tftp_root_path');
            $addSite('tftp_service_host', 'tftp_service_host VARCHAR(128) NULL AFTER tftp_root_path');
            $addSite('tftp_bind_path', 'tftp_bind_path VARCHAR(255) NULL AFTER tftp_container');
            $addSite('docker_bootstrapped_at', 'docker_bootstrapped_at INT DEFAULT 0 AFTER tftp_bind_path');
            $addSite('tftp_mode', "tftp_mode VARCHAR(16) NOT NULL DEFAULT 'docker' AFTER docker_bootstrapped_at");
            $addSite('tftp_pending_mode', 'tftp_pending_mode VARCHAR(16) NULL AFTER tftp_mode');
            $addSite('pbx_tftp_service_host', 'pbx_tftp_service_host VARCHAR(128) NULL AFTER tftp_pending_mode');
            $addSite('pbx_tftp_root_path', "pbx_tftp_root_path VARCHAR(255) NOT NULL DEFAULT '/tftpboot' AFTER pbx_tftp_service_host");
            $addSite('pbx_tftp_bind_path', "pbx_tftp_bind_path VARCHAR(255) NOT NULL DEFAULT '/srv/tftp/tftpboot' AFTER pbx_tftp_root_path");
            $addSite('pbx_tftp_daemon', "pbx_tftp_daemon VARCHAR(32) NOT NULL DEFAULT 'tftpd-hpa' AFTER pbx_tftp_bind_path");
            $addSite('pbx_tftp_bootstrapped_at', 'pbx_tftp_bootstrapped_at INT NOT NULL DEFAULT 0 AFTER pbx_tftp_daemon');
            $addSite('trunk_endpoint', 'trunk_endpoint VARCHAR(128) NULL AFTER trunk_endpoint_prefix');
            $addSite('remote_trunk_endpoint', 'remote_trunk_endpoint VARCHAR(128) NULL AFTER trunk_endpoint');

            $templateCols = $this->db->query('SHOW COLUMNS FROM ucmp_templates')->fetchAll(PDO::FETCH_COLUMN);
            $addTemplate = function (string $name, string $ddl) use ($templateCols): void {
                if (!in_array($name, $templateCols, true)) {
                    $this->db->exec("ALTER TABLE ucmp_templates ADD COLUMN $ddl");
                }
            };
            $addTemplate('target_model', 'target_model VARCHAR(128) NULL AFTER target_family');
            $addTemplate('target_vendor', 'target_vendor VARCHAR(32) NULL AFTER site_id');
            $addTemplate('target_series', 'target_series VARCHAR(64) NULL AFTER target_family');
            $addTemplate('target_role', 'target_role VARCHAR(24) NULL AFTER target_series');
            $addTemplate('revision', 'revision INT NOT NULL DEFAULT 1 AFTER member_blf');
            $addTemplate('phonebook_key', 'phonebook_key VARCHAR(64) NULL AFTER target_model');
            $addTemplate('phonebook_filename', 'phonebook_filename VARCHAR(128) NULL AFTER phonebook_key');
            $addTemplate('phonebook_source_site', 'phonebook_source_site VARCHAR(64) NULL AFTER phonebook_filename');
            $addTemplate('phonebook_source_url', 'phonebook_source_url TEXT NULL AFTER phonebook_source_site');

            $groupCols = $this->db->query('SHOW COLUMNS FROM ucmp_groups')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('parent_group_id', $groupCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_groups ADD COLUMN parent_group_id INT NULL AFTER site_id');
            }
            if (!in_array('template_map', $groupCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_groups ADD COLUMN template_map TEXT NULL AFTER template_id');
            }
            if (!in_array('member_exceptions', $groupCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_groups ADD COLUMN member_exceptions TEXT NULL AFTER template_map');
            }
            if (!in_array('revision', $groupCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_groups ADD COLUMN revision INT NOT NULL DEFAULT 1 AFTER member_blf');
            }

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_default_template_rules (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    site_id INT NOT NULL,
                    template_id INT NOT NULL,
                    target_key VARCHAR(255) NOT NULL,
                    target_vendor VARCHAR(32) NULL,
                    target_family VARCHAR(16) NULL,
                    target_series VARCHAR(64) NULL,
                    target_model VARCHAR(128) NULL,
                    target_role VARCHAR(24) NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    updated INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_ucmp_default_template_target (site_id,target_key),
                    KEY idx_ucmp_default_template_site (site_id),
                    KEY idx_ucmp_default_template_template (template_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_operation_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    created INT NOT NULL DEFAULT 0,
                    site_id INT NULL,
                    level VARCHAR(12) NOT NULL DEFAULT 'info',
                    operation VARCHAR(48) NOT NULL,
                    message VARCHAR(255) NOT NULL,
                    detail TEXT NULL,
                    INDEX idx_ucmp_log_site_created (site_id, created)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_phone_services (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    site_id INT NOT NULL,
                    service_key VARCHAR(48) NOT NULL,
                    name VARCHAR(64) NOT NULL,
                    service_type VARCHAR(16) NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    locked TINYINT(1) NOT NULL DEFAULT 0,
                    menu_order SMALLINT NOT NULL DEFAULT 50,
                    settings_json LONGTEXT NULL,
                    updated INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_ucmp_phone_services_site_key (site_id,service_key),
                    KEY idx_ucmp_phone_services_site_order (site_id,menu_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $seedService = $this->db->prepare('INSERT IGNORE INTO ucmp_phone_services (site_id,service_key,name,service_type,enabled,locked,menu_order,settings_json,updated) VALUES (?,?,?,?,1,1,?,?,?)');
            $seedService->execute([$localId,'directory','Company Directory','directory',10,'{}',time()]);
            $seedService->execute([$localId,'phone-info','Phone Information','info',90,'{}',time()]);

            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_directory_entries (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    source_key VARCHAR(128) NOT NULL,
                    source_id INT NULL,
                    object_key VARCHAR(128) NULL,
                    source_revision VARCHAR(64) NULL,
                    last_seen_at INT NOT NULL DEFAULT 0,
                    display_name VARCHAR(255) NOT NULL,
                    label VARCHAR(128) NOT NULL,
                    extension VARCHAR(64) NOT NULL,
                    department VARCHAR(255) NULL,
                    company VARCHAR(255) NULL,
                    org_unit VARCHAR(255) NULL,
                    mail VARCHAR(255) NULL,
                    mobile VARCHAR(64) NULL,
                    pager VARCHAR(64) NULL,
                    attributes LONGTEXT NULL,
                    source_dn VARCHAR(768) NULL,
                    site_id INT NULL,
                    conflicted TINYINT(1) NOT NULL DEFAULT 0,
                    applied_hash VARCHAR(64) NULL,
                    updated INT NOT NULL DEFAULT 0,
                    KEY idx_ucmp_directory_source (source_key),
                    UNIQUE KEY idx_ucmp_directory_object (source_id,object_key),
                    INDEX idx_ucmp_directory_ext (extension),
                    INDEX idx_ucmp_directory_site (site_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $directoryCols = $this->db->query('SHOW COLUMNS FROM ucmp_directory_entries')->fetchAll(PDO::FETCH_COLUMN);
            $addDirectory = function (string $name, string $ddl) use ($directoryCols): void {
                if (!in_array($name, $directoryCols, true)) {
                    $this->db->exec("ALTER TABLE ucmp_directory_entries ADD COLUMN $ddl");
                }
            };
            $addDirectory('source_id', 'source_id INT NULL AFTER source_key');
            $addDirectory('object_key', 'object_key VARCHAR(128) NULL AFTER source_id');
            $addDirectory('source_revision', 'source_revision VARCHAR(64) NULL AFTER object_key');
            $addDirectory('last_seen_at', 'last_seen_at INT NOT NULL DEFAULT 0 AFTER source_revision');
            $addDirectory('pager', 'pager VARCHAR(64) NULL AFTER mobile');
            if (!in_array('attributes', $directoryCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_directory_entries ADD COLUMN attributes LONGTEXT NULL AFTER pager');
            }
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_directory_conflicts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    source_id INT NULL,
                    conflict_key VARCHAR(64) NOT NULL,
                    conflict_type VARCHAR(32) NOT NULL,
                    conflict_value VARCHAR(64) NOT NULL,
                    candidates TEXT NOT NULL,
                    resolution_key VARCHAR(128) NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'open',
                    notified INT NOT NULL DEFAULT 0,
                    updated INT NOT NULL DEFAULT 0,
                    UNIQUE KEY idx_ucmp_directory_conflict (conflict_key),
                    KEY idx_ucmp_directory_conflict_source (source_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $directoryConflictCols = $this->db->query('SHOW COLUMNS FROM ucmp_directory_conflicts')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('source_id', $directoryConflictCols, true)) {
                $this->db->exec('ALTER TABLE ucmp_directory_conflicts ADD COLUMN source_id INT NULL AFTER id');
            }
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_phonebooks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    book_key VARCHAR(64) NOT NULL,
                    name VARCHAR(64) NOT NULL,
                    filename VARCHAR(128) NOT NULL,
                    title VARCHAR(64) NOT NULL,
                    include_mode VARCHAR(16) NOT NULL DEFAULT 'all',
                    rules TEXT NULL,
                    exclusions TEXT NULL,
                    manual_entries TEXT NULL,
                    group_by VARCHAR(24) NOT NULL DEFAULT 'department',
                    hierarchy TEXT NULL,
                    publish_sites TEXT NULL,
                    name_format VARCHAR(24) NOT NULL DEFAULT 'display_label',
                    sort_by VARCHAR(24) NOT NULL DEFAULT 'display_name',
                    include_mobile TINYINT(1) NOT NULL DEFAULT 1,
                    cisco_page_size INT NOT NULL DEFAULT 32,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    updated INT NOT NULL DEFAULT 0,
                    UNIQUE KEY idx_ucmp_phonebooks_key (book_key),
                    UNIQUE KEY idx_ucmp_phonebooks_filename (filename)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS ucmp_firmware_bundles (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    site_id INT NOT NULL,
                    vendor VARCHAR(32) NOT NULL,
                    profile VARCHAR(64) NOT NULL,
                    load_name VARCHAR(128) NOT NULL,
                    files_json LONGTEXT NULL,
                    missing_json LONGTEXT NULL,
                    status VARCHAR(24) NOT NULL DEFAULT 'incomplete',
                    scanned_at INT NOT NULL DEFAULT 0,
                    UNIQUE KEY idx_ucmp_firmware_identity (site_id, profile, load_name),
                    INDEX idx_ucmp_firmware_site_profile (site_id, profile)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            // FreePBX Module Admin can create these JSON-bearing fields as TEXT;
            // widen them in place so large policy documents are not truncated.
            // Real AD memberOf/custom attributes and compiled firmware manifests can
            // exceed 64 KiB, so widen them explicitly on both fresh and upgraded PBXs.
            $this->db->exec('ALTER TABLE ucmp_directory_entries MODIFY COLUMN attributes LONGTEXT NULL');
            $phonebookCols = $this->db->query('SHOW COLUMNS FROM ucmp_phonebooks')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('hierarchy', $phonebookCols, true)) { $this->db->exec('ALTER TABLE ucmp_phonebooks ADD COLUMN hierarchy LONGTEXT NULL AFTER group_by'); }
            if (!in_array('publish_sites', $phonebookCols, true)) { $this->db->exec('ALTER TABLE ucmp_phonebooks ADD COLUMN publish_sites LONGTEXT NULL AFTER hierarchy'); }
            foreach (['rules','exclusions','manual_entries','hierarchy','publish_sites'] as $column) {
                $this->db->exec("ALTER TABLE ucmp_phonebooks MODIFY COLUMN {$column} LONGTEXT NULL");
            }
            $this->db->exec('ALTER TABLE ucmp_firmware_bundles MODIFY COLUMN files_json LONGTEXT NULL, MODIFY COLUMN missing_json LONGTEXT NULL');
            $this->db->exec("UPDATE ucmp_phonebooks SET hierarchy='[{\"field\":\"site_name\",\"fallback\":\"Unmapped site\"},{\"field\":\"ou:1\",\"fallback\":\"Other\"}]' WHERE (hierarchy IS NULL OR hierarchy='') AND is_default=1");
            $this->db->exec("UPDATE ucmp_phonebooks SET hierarchy=CONCAT('[{\"field\":\"',IF(group_by='none','none',group_by),'\",\"fallback\":\"Other\"}]') WHERE hierarchy IS NULL OR hierarchy='' ");
            $this->db->exec("UPDATE ucmp_phonebooks SET publish_sites='[\"*\"]' WHERE publish_sites IS NULL OR publish_sites='' ");

            // Preserve the existing single-domain configuration as one explicit source.
            // The encrypted legacy password remains where it is until the privileged
            // secret-store migration; only a non-secret reference is recorded here.
            $baseDn = trim((string)$this->getSetting('ad_base_dn', $localId, ''));
            preg_match_all('/(?:^|,)DC=([^,]+)/i', $baseDn, $domainParts);
            $domain = !empty($domainParts[1])
                ? implode('.', array_map(static fn($part): string => trim((string)$part), $domainParts[1]))
                : 'legacy-directory';
            $adHost = trim((string)$this->getSetting('ad_host', $localId, ''));
            $adPort = (int)$this->getSetting('ad_port', $localId, 636);
            $adSecurity = strtolower(trim((string)$this->getSetting('ad_security', $localId, 'ldaps')));
            $adSecurity = in_array($adSecurity, ['ldaps','starttls','ldap'], true) ? $adSecurity : 'ldaps';
            $endpointJson = $adHost !== ''
                ? json_encode([['host'=>$adHost,'port'=>$adPort]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : '[]';
            $attributeMap = [
                'display_name'=>(string)$this->getSetting('ad_attr_display', $localId, 'displayName'),
                'extension'=>(string)$this->getSetting('ad_attr_extension', $localId, 'telephoneNumber'),
                'label'=>(string)$this->getSetting('ad_attr_label', $localId, 'displayName'),
                'department'=>(string)$this->getSetting('ad_attr_department', $localId, 'department'),
                'company'=>(string)$this->getSetting('ad_attr_company', $localId, 'company'),
                'mail'=>(string)$this->getSetting('ad_attr_mail', $localId, 'mail'),
                'mobile'=>(string)$this->getSetting('ad_attr_mobile', $localId, 'mobile'),
                'pager'=>(string)$this->getSetting('ad_attr_pager', $localId, 'pager'),
            ];
            $lastDirectorySync = (int)$this->getSetting('ad_last_sync', $localId, 0);
            $sourceRevision = $lastDirectorySync > 0 ? ('legacy-' . $lastDirectorySync) : null;
            $legacySourceId = (int)$this->db->query(
                "SELECT id FROM ucmp_directory_sources WHERE source_key='legacy-default' LIMIT 1"
            )->fetchColumn();
            if ($legacySourceId < 1) {
                $seedDirectorySource = $this->db->prepare(
                    'INSERT INTO ucmp_directory_sources
                    (source_key,domain,enabled,endpoints_json,tls_mode,secret_ref,base_dn,filter,
                     attribute_map_json,site_rules_json,schedule_minutes,last_accepted_revision,
                     last_attempt,last_success,last_error,created,updated)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $seedDirectorySource->execute([
                    'legacy-default',
                    $domain,
                    (int)$this->getSetting('ad_enabled', $localId, 0) ? 1 : 0,
                    $endpointJson,
                    $adSecurity,
                    'legacy-setting:ad_bind_password',
                    $baseDn !== '' ? $baseDn : null,
                    (string)$this->getSetting('ad_user_filter', $localId, '(&(objectCategory=person)(objectClass=user))'),
                    json_encode($attributeMap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    (string)$this->getSetting('ad_site_rules', $localId, '[]'),
                    max(0, (int)$this->getSetting('ad_sync_interval', $localId, 60)),
                    $sourceRevision,
                    $lastDirectorySync,
                    $lastDirectorySync,
                    null,
                    time(),
                    time(),
                ]);
                $legacySourceId = (int)$this->db->lastInsertId();
            }
            if ($legacySourceId > 0) {
                $bindLegacyDirectoryRows = $this->db->prepare(
                    'UPDATE ucmp_directory_entries
                        SET source_id=COALESCE(source_id,?),
                            object_key=COALESCE(NULLIF(object_key,\'\'),source_key),
                            source_revision=COALESCE(source_revision,?),
                            last_seen_at=CASE WHEN last_seen_at>0 THEN last_seen_at ELSE updated END
                      WHERE source_id IS NULL OR object_key IS NULL OR object_key=\'\' OR last_seen_at=0'
                );
                $bindLegacyDirectoryRows->execute([$legacySourceId, $sourceRevision]);
                $this->db->prepare(
                    'UPDATE ucmp_directory_conflicts SET source_id=? WHERE source_id IS NULL'
                )->execute([$legacySourceId]);
            }

            $this->ensureDirectorySourceIntegrity();
            $this->ensureDirectoryIntegrity();
            \FreePBX\modules\Ucmprovisioner\ManagedAssetRegistry::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\BuildTransactionService::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\CiscoTimezoneBundleManager::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\ManagedAssetScanJob::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\MediaReplicationService::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\FirmwareReplicationService::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
            \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::ensureSchema($this->db);
            $ensureIndex = function (string $table, string $name, string $definition): void {
                $check = $this->db->prepare("SHOW INDEX FROM {$table} WHERE Key_name = ?");
                $check->execute([$name]);
                if (!$check->fetch()) {
                    $this->db->exec("ALTER TABLE {$table} ADD {$definition}");
                }
            };
            $ensureIndex('ucmp_phones', 'idx_ucmp_phones_site_ext', 'INDEX idx_ucmp_phones_site_ext (site_id, extension)');
            $ensureIndex('ucmp_phones', 'idx_ucmp_phones_extension_id', 'INDEX idx_ucmp_phones_extension_id (extension_id)');
            $ensureIndex('ucmp_templates', 'idx_ucmp_templates_site', 'INDEX idx_ucmp_templates_site (site_id)');
            $this->ensureTemplateNameIntegrity();
            $ensureIndex('ucmp_default_template_rules', 'idx_ucmp_default_template_site', 'INDEX idx_ucmp_default_template_site (site_id)');
            $ensureIndex('ucmp_default_template_rules', 'idx_ucmp_default_template_template', 'INDEX idx_ucmp_default_template_template (template_id)');
            $ensureIndex('ucmp_groups', 'idx_ucmp_groups_site', 'INDEX idx_ucmp_groups_site (site_id)');
            $ensureIndex('ucmp_groups', 'idx_ucmp_groups_parent', 'INDEX idx_ucmp_groups_parent (parent_group_id)');
            $ensureIndex('ucmp_directory_entries', 'idx_ucmp_directory_object', 'UNIQUE INDEX idx_ucmp_directory_object (source_id,object_key)');
            $ensureIndex('ucmp_directory_conflicts', 'idx_ucmp_directory_conflict_source', 'INDEX idx_ucmp_directory_conflict_source (source_id)');
            $this->db->exec(
                'DELETE older FROM ucmp_settings older INNER JOIN ucmp_settings newer
                   ON newer.site_id <=> older.site_id AND newer.skey = older.skey AND newer.id > older.id'
            );
            $settingsIndex = $this->db->query("SHOW INDEX FROM ucmp_settings WHERE Key_name='idx_ucmp_settings_site_key'")->fetch(PDO::FETCH_ASSOC);
            if ($settingsIndex && !empty($settingsIndex['Non_unique'])) {
                $this->db->exec('ALTER TABLE ucmp_settings DROP INDEX idx_ucmp_settings_site_key');
            }
            $ensureIndex('ucmp_settings', 'idx_ucmp_settings_site_key', 'UNIQUE INDEX idx_ucmp_settings_site_key (site_id, skey)');
            $ensureIndex('ucmp_operation_log', 'idx_ucmp_log_site_created', 'INDEX idx_ucmp_log_site_created (site_id, created)');
            $ensureIndex('ucmp_phonebooks', 'idx_ucmp_phonebooks_key', 'UNIQUE INDEX idx_ucmp_phonebooks_key (book_key)');
            $ensureIndex('ucmp_phonebooks', 'idx_ucmp_phonebooks_filename', 'UNIQUE INDEX idx_ucmp_phonebooks_filename (filename)');
            (new \FreePBX\modules\Ucmprovisioner\LogicalExtensionService($this->db))->backfillLegacyPhones();
            $catalog = $this->getModels();
            foreach ($this->db->query("SELECT id,name,target_family,setting_overrides FROM ucmp_templates WHERE target_model IS NULL OR target_model='' ")->fetchAll(PDO::FETCH_ASSOC) as $templateRow) {
                $legacy = json_decode((string)($templateRow['setting_overrides'] ?? ''), true) ?: [];
                $modelId = (int)($legacy['_design_model_id'] ?? 0);
                $modelRow = $modelId > 0 ? $this->getModel($modelId) : null;
                if (!$modelRow) {
                    $matches = array_values(array_filter($catalog, static function ($model) use ($templateRow): bool {
                        return (empty($templateRow['target_family']) || (string)$model['family'] === (string)$templateRow['target_family'])
                            && preg_match('/(?<![A-Za-z0-9])' . preg_quote((string)$model['model'],'/') . '(?![A-Za-z0-9])/i', (string)$templateRow['name']);
                    }));
                    if (count($matches) === 1) { $modelRow = $matches[0]; }
                }
                if ($modelRow) {
                    $this->db->prepare('UPDATE ucmp_templates SET target_model=? WHERE id=?')
                        ->execute([(string)$modelRow['vendor'] . '|' . (string)$modelRow['model'], (int)$templateRow['id']]);
                }
            }
            // Early multi-source releases preserved an intentionally blank legacy mobile
            // mapping. That made substring search look broken because LDAP never fetched the
            // value. Upgrade only the untouched legacy-default projection; a nonblank site
            // setting or source mapping remains operator-owned.
            if ($legacySourceId > 0
                && trim((string)$this->getSetting('ad_attr_mobile', $localId, '')) === '') {
                $legacyMapStatement = $this->db->prepare(
                    'SELECT attribute_map_json FROM ucmp_directory_sources WHERE id=? AND source_key=? LIMIT 1'
                );
                $legacyMapStatement->execute([$legacySourceId, 'legacy-default']);
                $legacyMapJson = $legacyMapStatement->fetchColumn();
                $legacyMap = is_string($legacyMapJson) ? json_decode($legacyMapJson, true) : null;
                if (is_array($legacyMap) && trim((string)($legacyMap['mobile'] ?? '')) === '') {
                    $legacyMap['mobile'] = 'mobile';
                    $this->db->prepare(
                        'UPDATE ucmp_directory_sources SET attribute_map_json=?,updated=? WHERE id=? AND source_key=?'
                    )->execute([
                        json_encode($legacyMap, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        time(),
                        $legacySourceId,
                        'legacy-default',
                    ]);
                    $this->setSetting('ad_attr_mobile', 'mobile', $localId);
                }
            }

            if ((int)$this->db->query('SELECT COUNT(*) FROM ucmp_phonebooks')->fetchColumn() === 0) {
                $title = (string)$this->getSetting('directory_title', $localId, 'Company Directory');
                $groupBy = (string)$this->getSetting('directory_group_by', $localId, 'department');
                $nameFormat = (string)$this->getSetting('directory_name_format', $localId, 'display_label');
                $sortBy = (string)$this->getSetting('directory_sort_by', $localId, 'display_name');
                $includeMobile = (int)$this->getSetting('directory_include_mobile', $localId, 1);
                $pageSize = (int)$this->getSetting('directory_cisco_page_size', $localId, 32);
                $this->db->prepare(
                    'INSERT INTO ucmp_phonebooks (book_key,name,filename,title,include_mode,rules,exclusions,manual_entries,group_by,hierarchy,publish_sites,name_format,sort_by,include_mobile,cisco_page_size,is_default,updated)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute(['company','Company Directory','company-directory.xml',$title ?: 'Company Directory','all','[]','[]','[]',$groupBy ?: 'department','[{"field":"site_name","fallback":"Unmapped site"},{"field":"ou:1","fallback":"Other"}]','["*"]',$nameFormat ?: 'display_label',$sortBy ?: 'display_name',$includeMobile ? 1 : 0,in_array($pageSize,[16,24,32],true)?$pageSize:32,1,time()]);
            }
            // Identity v2 also owns the default FreePBX device caller-ID. Force one
            // controlled re-check after upgrading instead of leaving "device <ext>".
            $this->db->exec('UPDATE ucmp_directory_entries SET applied_hash = NULL');
            $identityBackfill = (new \FreePBX\modules\Ucmprovisioner\DirectoryService($this))
                ->backfillAcceptedIdentityBindings();
            if (!empty($identityBackfill['failed'])) {
                $this->addError(
                    'Identity binding backfill skipped ' . (int)$identityBackfill['failed']
                    . ' accepted row(s): ' . implode('; ', (array)$identityBackfill['errors'])
                );
            }

            foreach ($this->db->query('SELECT id, name, site_key FROM ucmp_sites')->fetchAll(PDO::FETCH_ASSOC) as $site) {
                if (!empty($site['site_key'])) {
                    continue;
                }
                $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', (string)$site['name']), '-')) ?: 'site';
                $this->db->prepare('UPDATE ucmp_sites SET site_key = ? WHERE id = ?')
                    ->execute([$slug . '-' . bin2hex(random_bytes(4)), $site['id']]);
            }
            $siteIdx = $this->db->query("SHOW INDEX FROM ucmp_sites WHERE Key_name = 'idx_ucmp_sites_key'")->fetchAll();
            if (!$siteIdx) {
                $this->db->exec('ALTER TABLE ucmp_sites ADD UNIQUE INDEX idx_ucmp_sites_key (site_key)');
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Schema migration to v' . self::SCHEMA_VERSION . ' failed: ' . $e->getMessage()
                . ' — resolve the reported migration error before retrying the upgrade.', 0, $e);
        }
        $this->setSetting('schema_version', (string) self::SCHEMA_VERSION);
    }

    /** Keep endpoint tuple columns available even after a partially reconciled module upgrade. */
    private function ensurePhoneEndpointSchema(): void
    {
        $columns = $this->db->query('SHOW COLUMNS FROM ucmp_phones')->fetchAll(PDO::FETCH_COLUMN);
        $definitions = [
            'driver'=>"driver VARCHAR(8) NOT NULL DEFAULT 'inherit' AFTER prev_extension",
            'sip_port'=>'sip_port INT NOT NULL DEFAULT 0 AFTER transport',
            'applied_driver'=>'applied_driver VARCHAR(8) NULL AFTER last_applied',
            'applied_transport'=>'applied_transport VARCHAR(8) NULL AFTER applied_driver',
            'applied_sip_port'=>'applied_sip_port INT NOT NULL DEFAULT 0 AFTER applied_transport',
        ];
        foreach ($definitions as $name=>$ddl) {
            if (!in_array($name, $columns, true)) {
                $this->db->exec("ALTER TABLE ucmp_phones ADD COLUMN $ddl");
            }
        }
    }

    /** Persist only an intentionally removed physical MAC; FreePBX extensions remain untouched. */
    private function ensurePhoneMacSuppressionSchema(): void
    {
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ucmp_phone_mac_suppressions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                site_id INT UNSIGNED NOT NULL,
                mac CHAR(12) NOT NULL,
                extension VARCHAR(20) NULL,
                created BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_ucmp_phone_mac_suppression (site_id,mac)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** Keep one physical-phone row per site while preserving different sites' independent state. */
    private function ensurePhoneIntegrity(): void
    {
        $localId = $this->localSiteId();
        $groups = $this->db->prepare(
            'SELECT COALESCE(site_id,?) effective_site,mac,COUNT(*) copies
               FROM ucmp_phones WHERE mac IS NOT NULL AND mac<>\'\'
              GROUP BY COALESCE(site_id,?),mac HAVING COUNT(*)>1'
        );
        $groups->execute([$localId,$localId]);
        $mergeFields = [
            'group_id','vendor','model_id','family','ip','extension','prev_extension',
            'driver','transport','sip_port','label','overrides','lines_json','status','last_seen',
            'last_command','last_command_at','last_applied','applied_driver','applied_transport','applied_sip_port','config_filename',
            'config_mode','config_override_sha256','config_overridden_at',
            'exclude_bulk_rebuild','updated',
        ];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $group) {
            $siteId = (int)$group['effective_site'];
            $ownsTransaction = !$this->db->inTransaction();
            if ($ownsTransaction) {
                if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin duplicate-phone repair'); }
            } else { $this->db->exec('SAVEPOINT ucmp_phone_integrity'); }
            try {
                $rowLock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                $rows = $this->db->prepare('SELECT * FROM ucmp_phones WHERE mac=? AND (site_id=? OR (site_id IS NULL AND ?=?)) ORDER BY updated DESC,id DESC' . $rowLock);
                $rows->execute([(string)$group['mac'],$siteId,$siteId,$localId]);
                $duplicates = $rows->fetchAll(PDO::FETCH_ASSOC);
                if (count($duplicates) >= 2) {
                    $keeper = array_shift($duplicates);
                    foreach ($duplicates as $duplicate) {
                        foreach ($mergeFields as $field) {
                            $current = $keeper[$field] ?? null; $candidate = $duplicate[$field] ?? null;
                            if (($current === null || $current === '' || $current === 0 || $current === '0') && $candidate !== null && $candidate !== '' && $candidate !== 0 && $candidate !== '0') {
                                $keeper[$field] = $candidate;
                            }
                        }
                        if (($duplicate['config_mode'] ?? '') === 'manual') {
                            foreach (['config_mode','config_filename','config_override_sha256','config_overridden_at'] as $field) {
                                $keeper[$field] = $duplicate[$field] ?? $keeper[$field] ?? null;
                            }
                        }
                        if (!empty($duplicate['exclude_bulk_rebuild'])) {
                            $keeper['exclude_bulk_rebuild'] = 1;
                        }
                    }
                    $ids = array_column($duplicates, 'id');
                    $marks = implode(',', array_fill(0,count($ids),'?'));
                    $this->db->prepare("DELETE FROM ucmp_phones WHERE id IN ($marks)")->execute($ids);
                    $values = [$siteId];
                    foreach ($mergeFields as $field) { $values[] = $keeper[$field] ?? null; }
                    $values[] = (int)$keeper['id'];
                    $sets = implode(',', array_map(static fn($field) => "$field=?", $mergeFields));
                    $this->db->prepare("UPDATE ucmp_phones SET site_id=?,$sets WHERE id=?")->execute($values);
                }
                if ($ownsTransaction) {
                    if (!$this->db->commit()) { throw new \RuntimeException('Duplicate-phone repair commit is unconfirmed'); }
                } else { $this->db->exec('RELEASE SAVEPOINT ucmp_phone_integrity'); }
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    if ($ownsTransaction) { $this->db->rollBack(); }
                    else { $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_phone_integrity'); $this->db->exec('RELEASE SAVEPOINT ucmp_phone_integrity'); }
                }
                throw $error;
            }
        }
        $this->db->prepare('UPDATE ucmp_phones SET site_id=? WHERE site_id IS NULL')->execute([$localId]);
        $indexRows = $this->db->query("SHOW INDEX FROM ucmp_phones WHERE Key_name='idx_ucmp_phones_mac'")->fetchAll(PDO::FETCH_ASSOC);
        usort($indexRows, static fn($a,$b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $columns = array_map(static fn($row) => strtolower((string)$row['Column_name']), $indexRows);
        $nonUnique = $indexRows ? !empty($indexRows[0]['Non_unique']) : true;
        if ($indexRows && ($columns !== ['site_id','mac'] || $nonUnique)) {
            $this->db->exec('ALTER TABLE ucmp_phones DROP INDEX idx_ucmp_phones_mac');
            $indexRows = [];
        }
        if (!$indexRows) {
            try {
                $this->db->exec('ALTER TABLE ucmp_phones ADD UNIQUE INDEX idx_ucmp_phones_mac (site_id,mac)');
            } catch (\PDOException $e) {
                // Module Admin and a web request can reach this repair at the same time during
                // an upgrade. A concurrent successful ADD is harmless, but only accept MySQL's
                // duplicate-index response after verifying the final index definition.
                if ((int)($e->errorInfo[1] ?? 0) !== 1061) {
                    throw $e;
                }
                $concurrent = $this->db->query(
                    "SHOW INDEX FROM ucmp_phones WHERE Key_name='idx_ucmp_phones_mac'"
                )->fetchAll(PDO::FETCH_ASSOC);
                usort($concurrent, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
                $concurrentColumns = array_map(
                    static fn($row) => strtolower((string)$row['Column_name']),
                    $concurrent
                );
                if (!$concurrent || $concurrentColumns !== ['site_id','mac'] || !empty($concurrent[0]['Non_unique'])) {
                    throw $e;
                }
            }
        }
    }

    /** Enforce the one-authority-per-canonical-domain invariant without merging authorities. */
    private function ensureDirectorySourceIntegrity(): void
    {
        $columns = $this->db->query('SHOW COLUMNS FROM ucmp_directory_sources')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('schedule_json', $columns, true)) {
            $this->db->exec('ALTER TABLE ucmp_directory_sources ADD COLUMN schedule_json LONGTEXT NULL AFTER schedule_minutes');
        }
        $rows = $this->db->query(
            'SELECT id,domain FROM ucmp_directory_sources ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $canonicalById = [];
        $ownerByDomain = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $canonical = strtolower(rtrim(trim((string)$row['domain']), '.'));
            if ($canonical === '') {
                throw new \RuntimeException("Directory source {$id} has an empty AD domain");
            }
            if (isset($ownerByDomain[$canonical])) {
                throw new \RuntimeException(sprintf(
                    'Directory sources %d and %d claim the same canonical AD domain %s; resolve them before upgrading',
                    $ownerByDomain[$canonical],
                    $id,
                    $canonical
                ));
            }
            $ownerByDomain[$canonical] = $id;
            $canonicalById[$id] = $canonical;
        }
        $update = $this->db->prepare('UPDATE ucmp_directory_sources SET domain=? WHERE id=?');
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!hash_equals((string)$row['domain'], $canonicalById[$id])) {
                $update->execute([$canonicalById[$id], $id]);
            }
        }

        $check = $this->db->prepare(
            "SHOW INDEX FROM ucmp_directory_sources WHERE Key_name='uniq_ucmp_directory_domain'"
        );
        $check->execute();
        $index = $check->fetchAll(PDO::FETCH_ASSOC) ?: [];
        usort($index, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $columns = array_map(static fn($row) => strtolower((string)$row['Column_name']), $index);
        if (!$index || $columns !== ['domain'] || !empty($index[0]['Non_unique'])) {
            if ($index) {
                $this->db->exec(
                    'ALTER TABLE ucmp_directory_sources DROP INDEX uniq_ucmp_directory_domain'
                );
            }
            $this->db->exec(
                'ALTER TABLE ucmp_directory_sources
                 ADD UNIQUE INDEX uniq_ucmp_directory_domain (domain)'
            );
        }
    }

    /** Repair the small set of constraints whose absence causes user-visible data growth. */
    private function ensureDirectoryIntegrity(): void
    {
        $index = function (string $table, string $name): array {
            $st = $this->db->prepare("SHOW INDEX FROM {$table} WHERE Key_name = ?");
            $st->execute([$name]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };
        $sourceIndex = $index('ucmp_directory_entries', 'idx_ucmp_directory_source');
        usort($sourceIndex, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $sourceColumns = array_map(static fn($row) => strtolower((string)$row['Column_name']), $sourceIndex);
        if (!$sourceIndex || $sourceColumns !== ['source_key'] || empty($sourceIndex[0]['Non_unique'])) {
            if ($sourceIndex) {
                $this->db->exec('ALTER TABLE ucmp_directory_entries DROP INDEX idx_ucmp_directory_source');
            }
            $this->db->exec('ALTER TABLE ucmp_directory_entries ADD INDEX idx_ucmp_directory_source (source_key)');
        }

        $objectIndex = $index('ucmp_directory_entries', 'idx_ucmp_directory_object');
        usort($objectIndex, static fn($a, $b) => (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']);
        $objectColumns = array_map(static fn($row) => strtolower((string)$row['Column_name']), $objectIndex);
        if (!$objectIndex || $objectColumns !== ['source_id','object_key'] || !empty($objectIndex[0]['Non_unique'])) {
            $this->db->exec(
                'DELETE older FROM ucmp_directory_entries older
                 INNER JOIN ucmp_directory_entries newer
                   ON newer.source_id = older.source_id
                  AND newer.object_key = older.object_key
                  AND (
                        newer.updated > older.updated
                        OR (newer.updated = older.updated AND newer.id > older.id)
                      )
                 WHERE older.source_id IS NOT NULL
                   AND older.object_key IS NOT NULL
                   AND older.object_key <> \'\''
            );
            if ($objectIndex) {
                $this->db->exec('ALTER TABLE ucmp_directory_entries DROP INDEX idx_ucmp_directory_object');
            }
            $this->db->exec(
                'ALTER TABLE ucmp_directory_entries
                 ADD UNIQUE INDEX idx_ucmp_directory_object (source_id,object_key)'
            );
        }
        if (!$index('ucmp_directory_entries', 'idx_ucmp_directory_ext')) {
            $this->db->exec('ALTER TABLE ucmp_directory_entries ADD INDEX idx_ucmp_directory_ext (extension)');
        }
        if (!$index('ucmp_directory_entries', 'idx_ucmp_directory_site')) {
            $this->db->exec('ALTER TABLE ucmp_directory_entries ADD INDEX idx_ucmp_directory_site (site_id)');
        }
        $conflictIndex = $index('ucmp_directory_conflicts', 'idx_ucmp_directory_conflict');
        if (!$conflictIndex || !empty($conflictIndex[0]['Non_unique'])) {
            foreach ($this->db->query('SELECT conflict_key FROM ucmp_directory_conflicts GROUP BY conflict_key HAVING COUNT(*) > 1')->fetchAll(PDO::FETCH_COLUMN) as $key) {
                $pick = $this->db->prepare(
                    'SELECT id FROM ucmp_directory_conflicts WHERE conflict_key=?
                     ORDER BY (resolution_key IS NOT NULL) DESC,updated DESC,id DESC LIMIT 1'
                );
                $pick->execute([$key]);
                $keep = (int)$pick->fetchColumn();
                $this->db->prepare('DELETE FROM ucmp_directory_conflicts WHERE conflict_key=? AND id<>?')->execute([$key,$keep]);
            }
            if ($conflictIndex) { $this->db->exec('ALTER TABLE ucmp_directory_conflicts DROP INDEX idx_ucmp_directory_conflict'); }
            $this->db->exec('ALTER TABLE ucmp_directory_conflicts ADD UNIQUE INDEX idx_ucmp_directory_conflict (conflict_key)');
        }
    }
    public function getModel(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ucmp_models WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ---------------------------------------------------------------- */
    /* Data: sites                                                       */
    /* ---------------------------------------------------------------- */
    public function getSites(): array
    {
        $rows = $this->db->query("SELECT * FROM ucmp_sites ORDER BY is_local DESC, name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            unset($r['pbx_ssh_secret'], $r['docker_ssh_secret'], $r['pbx_api_secret']); // never expose secrets to views
        }
        return $rows;
    }
    public function getSite(int $id, bool $withSecrets = false): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ucmp_sites WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row && $withSecrets) {
            $row['pbx_ssh_secret'] = $this->dec($row['pbx_ssh_secret'] ?? '');
            $row['docker_ssh_secret'] = $this->dec($row['docker_ssh_secret'] ?? '');
            $row['pbx_api_secret'] = $this->dec($row['pbx_api_secret'] ?? '');
        }
        return $row ? array_replace($row, $this->siteActionDrafts[$id] ?? []) : null;
    }

    /** Normalize the one main PBX address and optional service-specific overrides. */
    private function siteConnectionFields(array $site, array $input): array
    {
        foreach ($input as $value) {
            if ($value !== null && !is_scalar($value)) { throw new \InvalidArgumentException('Connection fields must be scalar values'); }
        }
        $fields = [];
        foreach (['pbx_host','pbx_addr','pbx_api_host','docker_host','tftp_service_host','pbx_tftp_service_host'] as $name) {
            if (!array_key_exists($name, $input)) { continue; }
            $value = trim((string)$input[$name]);
            if ($value !== '' && (strlen($value) > 128 || (!filter_var($value, FILTER_VALIDATE_IP)
                && !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/D', $value)))) {
                $label = ['tftp_service_host'=>'Phone-facing TFTP address', 'pbx_tftp_service_host'=>'PBX-hosted TFTP address'][$name] ?? 'Connection host';
                throw new \InvalidArgumentException($label . ' must be a bare IP address or DNS name');
            }
            $fields[$name] = $value;
        }
        $main = trim((string)($fields['pbx_host'] ?? $site['pbx_host'] ?? ''))
            ?: trim((string)($fields['pbx_addr'] ?? $site['pbx_addr'] ?? ''));
        if (array_key_exists('pbx_host', $fields)) { $fields['pbx_host'] = $main; }
        foreach (['pbx_addr','pbx_api_host'] as $name) {
            if (array_key_exists($name, $fields) && $fields[$name] === '') { $fields[$name] = $main; }
        }
        // A displayed default remains inherited when its underlying host changes later.
        $candidate = array_replace($site, $fields);
        foreach (['tftp_service_host'=>'docker', 'pbx_tftp_service_host'=>'pbx'] as $name => $mode) {
            if (!array_key_exists($name, $fields)) { continue; }
            $withoutOverride = array_replace($candidate, [$name=>'']);
            if ($fields[$name] === \FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($withoutOverride, $mode)) {
                $fields[$name] = '';
            }
        }
        foreach (['pbx_ssh_port','pbx_api_port','docker_ssh_port'] as $name) {
            if (!array_key_exists($name, $input)) { continue; }
            $port = trim((string)$input[$name]);
            if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
                throw new \InvalidArgumentException('Connection ports must be integers from 1 to 65535');
            }
            $fields[$name] = (int)$port;
        }
        if (array_key_exists('pbx_api_scheme', $input)) {
            $scheme = strtolower(trim((string)$input['pbx_api_scheme']));
            if (!in_array($scheme, ['http','https'], true)) { throw new \InvalidArgumentException('API scheme must be HTTP or HTTPS'); }
            $fields['pbx_api_scheme'] = $scheme;
        }
        if (array_key_exists('pbx_api_verify_tls', $input)) {
            $fields['pbx_api_verify_tls'] = !empty($input['pbx_api_verify_tls']) ? 1 : 0;
        }
        if (array_key_exists('tftp_container', $input)) {
            $container = trim((string)$input['tftp_container']);
            if ($container !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $container)) {
                throw new \InvalidArgumentException('TFTP container name contains invalid characters');
            }
            $fields['tftp_container'] = $container;
        }
        return $fields;
    }

    /** Reads use a draft; explicit install/enrollment actions apply only connection fields. */
    private function useSiteConnectionDraft(array $request, string $command): void
    {
        if (!isset($request['site_draft']) || !in_array($command, ['sshfingerprint','remoteversion','remoteinstall','bootstrappbx','signmodule','bootstrapsite','bootstrappbxtftp','bootstrapapi'], true)) { return; }
        $input = is_array($request['site_draft']) ? $request['site_draft'] : json_decode((string)$request['site_draft'], true);
        if (!is_array($input)) { throw new \InvalidArgumentException('Invalid Sites connection draft'); }
        $id = (int)($request['id'] ?? 0);
        $site = $this->getSite($id);
        if (!$site) { throw new \InvalidArgumentException('Save the new site once before using connection actions'); }
        $expected=(string)($request['expected_revision']??'');
        if ($expected!==''&&!hash_equals(self::siteEditorRevision($site),$expected)) { throw new \DomainException('Site changed since this form was opened; reload before applying connection settings'); }
        $docker = $command === 'bootstrapsite' || ($command === 'sshfingerprint' && ($request['channel'] ?? '') === 'docker');
        $allowed = $docker ? ['docker_host','docker_ssh_port','tftp_container','tftp_service_host']
            : ['pbx_host','pbx_addr','pbx_ssh_port','pbx_api_scheme','pbx_api_host','pbx_api_port','pbx_api_verify_tls'];
        if ($command === 'bootstrappbxtftp') { $allowed[] = 'pbx_tftp_service_host'; }
        $fields = $this->siteConnectionFields($site, array_intersect_key($input, array_flip($allowed)));
        $candidate = array_replace($site, $fields);
        $hostField = $docker ? 'docker_host' : 'pbx_host';
        $portField = $docker ? 'docker_ssh_port' : 'pbx_ssh_port';
        $oldHost = trim((string)($site[$hostField] ?? '')) ?: ($docker ? '' : trim((string)($site['pbx_addr'] ?? '')));
        $targetChanged = $oldHost !== trim((string)($candidate[$hostField] ?? '')) || (int)($site[$portField] ?? 22) !== (int)($candidate[$portField] ?? 22);
        if ($targetChanged) {
            $fields[$docker ? 'docker_host_key' : 'pbx_host_key'] = null;
            $fields[$docker ? 'docker_bootstrapped_at' : 'pbx_bootstrapped_at'] = 0;
            if (!$docker) {
                $fields['pbx_agent_ready'] = 0;
                $fields['pbx_tftp_bootstrapped_at'] = 0;
                $fields['pbx_api_ready'] = 0;
                $fields['pbx_api_last_ok'] = 0;
            }
        }
        foreach (['pbx_api_scheme','pbx_api_host','pbx_api_port','pbx_api_verify_tls'] as $field) {
            if (array_key_exists($field, $fields) && (string)$fields[$field] !== (string)($site[$field] ?? '')) {
                $fields['pbx_api_ready'] = 0;
                $fields['pbx_api_last_ok'] = 0;
            }
        }
        if (in_array($command, ['sshfingerprint','remoteversion'], true)) {
            $this->siteActionDrafts[$id] = $fields;
        } else {
            if ($command !== 'bootstrapapi' && (trim((string)($request['admin_user'] ?? '')) === '' || (string)($request['admin_pass'] ?? '') === '')) {
                throw new \InvalidArgumentException('Temporary administrator username and password are required before applying connection settings');
            }
            $changes = array_filter($fields, static fn($value, $field): bool => $value === null
                ? ($site[$field] ?? null) !== null : (string)$value !== (string)($site[$field] ?? ''), ARRAY_FILTER_USE_BOTH);
            if ($changes !== []) {
                // Bound the update to the exact connection snapshot read before this action.
                $where = ['id = ?']; $before = [$id];
                foreach (array_unique(array_merge($allowed, array_keys($changes))) as $field) {
                    if (!array_key_exists($field, $site) || $site[$field] === null) { $where[] = "`$field` IS NULL"; }
                    else { $where[] = "`$field` = ?"; $before[] = $site[$field]; }
                }
                $set = implode(', ', array_map(static fn($field): string => "`$field` = ?", array_keys($changes)));
                $statement = $this->db->prepare('UPDATE ucmp_sites SET ' . $set . ' WHERE ' . implode(' AND ', $where));
                $statement->execute(array_merge(array_values($changes), $before));
                if ($statement->rowCount() !== 1) { throw new \RuntimeException('Site connection fields changed concurrently; reload the site before retrying'); }
            }
            if ($expected!=='') { $this->siteActionSavedBase[$id]=array_replace($site,$changes); }
        }
        $this->resetSiteConnection($id);
    }

    public static function siteEditorRevision(array $site): string
    {
        unset($site['pbx_api_last_ok']); // A health probe does not edit the site.
        ksort($site,SORT_STRING);
        foreach ($site as &$value) { $value=$value===null?null:(string)$value; }
        unset($value);
        return hash('sha256',json_encode($site,JSON_THROW_ON_ERROR));
    }

    public function saveSite(array $d): int
    {
        $name = trim($d['name'] ?? '');
        if ($name === '') {
            throw new \Exception(_('Site name cannot be empty.'));
        }
        $id = (int)($d['id'] ?? 0);
        $existing = $id ? $this->getSite($id) : null;
        if ($id && (!$existing || !hash_equals(self::siteEditorRevision($existing),(string)($d['expected_revision']??'')))) {
            throw new \DomainException('Site changed or its editor revision is missing; reload it before saving');
        }
        $d = array_replace($d, $this->siteConnectionFields($existing ?? [], array_intersect_key($d, array_flip(['pbx_host','pbx_addr','pbx_api_host']))));
        
        $is_local = isset($d['is_local']) ? (int)!empty($d['is_local']) : ($existing ? (int)$existing['is_local'] : 0);
        $enabled = isset($d['enabled']) ? (int)!empty($d['enabled']) : ($existing ? (int)$existing['enabled'] : 1);
        $docker_sudo = 0;
        $apiScheme = strtolower(trim((string)($d['pbx_api_scheme'] ?? ($existing['pbx_api_scheme'] ?? 'http'))));
        if (!in_array($apiScheme, ['http','https'], true)) { throw new \InvalidArgumentException('API scheme must be HTTP or HTTPS'); }
        $apiPort = (int)($d['pbx_api_port'] ?? ($existing['pbx_api_port'] ?? ($apiScheme === 'https' ? 443 : 80)));
        if ($apiPort < 1 || $apiPort > 65535) { throw new \InvalidArgumentException('API port must be between 1 and 65535'); }
        $siteKey = trim((string)($d['site_key'] ?? ($existing['site_key'] ?? '')));
        if ($siteKey === '') {
            $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-')) ?: 'site';
            $siteKey = $slug . '-' . bin2hex(random_bytes(4));
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $siteKey)) {
            throw new \InvalidArgumentException('Site key must contain only letters, numbers, dot, underscore and hyphen');
        }
        $pbxSshPort = (int)($d['pbx_ssh_port'] ?? ($existing['pbx_ssh_port'] ?? 22));
        $dockerSshPort = (int)($d['docker_ssh_port'] ?? ($existing['docker_ssh_port'] ?? 22));
        if ($pbxSshPort < 1 || $pbxSshPort > 65535 || $dockerSshPort < 1 || $dockerSshPort > 65535) {
            throw new \InvalidArgumentException('SSH ports must be between 1 and 65535');
        }
        $requestedTftpMode = strtolower(trim((string)($d['tftp_mode']
            ?? ($existing['tftp_pending_mode'] ?? $existing['tftp_mode'] ?? 'pbx'))));
        if (!in_array($requestedTftpMode, ['docker', 'pbx'], true)) {
            throw new \InvalidArgumentException('Choose PBX-hosted or remote Docker-hosted TFTP');
        }
        $tftpContainer = trim((string)($d['tftp_container'] ?? ($existing['tftp_container'] ?? '')));
        if ($tftpContainer !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $tftpContainer)) {
            throw new \InvalidArgumentException('TFTP container name contains invalid characters');
        }
        // Hidden provider fields may contain stale fallbacks; preserve their saved addresses.
        $tftpServiceHost = $requestedTftpMode === 'docker'
            ? trim((string)($d['tftp_service_host'] ?? ($existing['tftp_service_host'] ?? '')), " []\t\r\n")
            : (string)($existing['tftp_service_host'] ?? '');
        $pbxTftpServiceHost = $requestedTftpMode === 'pbx'
            ? trim((string)($d['pbx_tftp_service_host'] ?? ($existing['pbx_tftp_service_host'] ?? '')), " []\t\r\n")
            : (string)($existing['pbx_tftp_service_host'] ?? '');
        $serviceField = $requestedTftpMode === 'pbx' ? 'pbx_tftp_service_host' : 'tftp_service_host';
        $serviceValue = $requestedTftpMode === 'pbx' ? $pbxTftpServiceHost : $tftpServiceHost;
        $normalizedService = $this->siteConnectionFields(array_replace($existing ?? [], $d), [$serviceField=>$serviceValue]);
        if ($requestedTftpMode === 'pbx') { $pbxTftpServiceHost = $normalizedService[$serviceField]; }
        else { $tftpServiceHost = $normalizedService[$serviceField]; }
        $activeTftpMode = $existing
            ? (in_array((string)($existing['tftp_mode'] ?? ''), ['docker', 'pbx'], true) ? (string)$existing['tftp_mode'] : 'docker')
            : $requestedTftpMode;
        $pendingTftpMode = $existing && $requestedTftpMode !== $activeTftpMode ? $requestedTftpMode : null;
        
        $vals = [
            'site_key'              => $siteKey,
            'name'                  => $name,
            'is_local'              => $is_local,
            'enabled'               => $enabled,
            'pbx_addr'              => trim((string)($d['pbx_addr'] ?? $existing['pbx_addr'] ?? $d['pbx_host'] ?? '')) ?: null,
            'pbx_sip_port'          => max(1, min(65535, (int)($d['pbx_sip_port'] ?? 5160))),
            'pbx_host'              => trim((string)($d['pbx_host'] ?? $existing['pbx_host'] ?? $d['pbx_addr'] ?? '')) ?: null,
            'pbx_ssh_port'          => $pbxSshPort,
            // Runtime identities and pins are server-owned enrollment state.
            // Read-only/hidden browser fields are never trust anchors.
            'pbx_ssh_user'          => $existing['pbx_ssh_user'] ?? null,
            'pbx_ssh_secret'        => null,
            'pbx_host_key'          => $existing['pbx_host_key'] ?? null,
            'pbx_agent_user'        => 'ucmp-pbx',
            'pbx_agent_ready'       => (int)($existing['pbx_agent_ready'] ?? 0),
            'pbx_bootstrapped_at'   => (int)($existing['pbx_bootstrapped_at'] ?? 0),
            'pbx_api_enabled'       => isset($d['pbx_api_enabled']) ? (int)!empty($d['pbx_api_enabled']) : (int)($existing['pbx_api_enabled'] ?? 0),
            'pbx_api_ready'         => (int)($existing['pbx_api_ready'] ?? 0),
            'pbx_api_scheme'        => $apiScheme,
            'pbx_api_host'          => trim((string)($d['pbx_api_host'] ?? ($existing['pbx_api_host'] ?? ''))) ?: null,
            'pbx_api_port'          => $apiPort,
            'pbx_api_verify_tls'    => isset($d['pbx_api_verify_tls']) ? (int)!empty($d['pbx_api_verify_tls']) : (int)($existing['pbx_api_verify_tls'] ?? 1),
            'pbx_api_last_ok'       => (int)($existing['pbx_api_last_ok'] ?? 0),
            'docker_host'           => !empty($d['docker_host']) ? trim($d['docker_host']) : null,
            'docker_ssh_port'       => $dockerSshPort,
            'docker_ssh_user'       => 'ucmp-tftp',
            'docker_ssh_secret'     => null,
            'docker_host_key'       => $existing['docker_host_key'] ?? null,
            'docker_sudo'           => $docker_sudo,
            'tftp_root_path'        => '/tftpboot',
            'tftp_service_host'     => $tftpServiceHost !== '' ? $tftpServiceHost : null,
            'tftp_container'        => $tftpContainer !== '' ? $tftpContainer : null,
            'tftp_bind_path'        => '/opt/tftp-server/tftpboot',
            'docker_bootstrapped_at'=> (int)($existing['docker_bootstrapped_at'] ?? 0),
            // Existing sites stage architecture changes. The old backend remains active until
            // the explicit copy-and-verify cutover succeeds.
            'tftp_mode'             => $activeTftpMode,
            'tftp_pending_mode'     => $pendingTftpMode,
            'pbx_tftp_service_host' => $pbxTftpServiceHost !== '' ? $pbxTftpServiceHost : null,
            'pbx_tftp_root_path'    => '/tftpboot',
            'pbx_tftp_bind_path'    => '/srv/tftp/tftpboot',
            'pbx_tftp_daemon'       => 'tftpd-hpa',
            'pbx_tftp_bootstrapped_at'=> (int)($existing['pbx_tftp_bootstrapped_at'] ?? 0),
            'voice_subnets'         => !empty($d['voice_subnets']) ? trim($d['voice_subnets']) : null,
            'trunk_endpoint_prefix' => !empty($d['trunk_endpoint_prefix']) ? trim($d['trunk_endpoint_prefix']) : null,
            'trunk_endpoint'        => !empty($d['trunk_endpoint']) ? trim($d['trunk_endpoint']) : null,
            'remote_trunk_endpoint' => !empty($d['remote_trunk_endpoint']) ? trim($d['remote_trunk_endpoint']) : null,
        ];

        // A host or port change is a new SSH identity. Clear the old pin and
        // readiness atomically so the UI cannot advertise a stale enrollment.
        if ($existing) {
            $oldPbxHost = trim((string)($existing['pbx_host'] ?: ($existing['pbx_addr'] ?? '')));
            $newPbxHost = trim((string)($vals['pbx_host'] ?: ($vals['pbx_addr'] ?? '')));
            $oldPbxPort = (int)($existing['pbx_ssh_port'] ?? 22);
            if ($oldPbxHost !== $newPbxHost || $oldPbxPort !== (int)$vals['pbx_ssh_port']) {
                $vals['pbx_host_key'] = null;
                $vals['pbx_agent_ready'] = 0;
                $vals['pbx_bootstrapped_at'] = 0;
                $vals['pbx_tftp_bootstrapped_at'] = 0;
                $vals['pbx_api_ready'] = 0;
                $vals['pbx_api_last_ok'] = 0;
            }
            if (trim((string)($existing['pbx_api_host'] ?? '')) !== trim((string)($vals['pbx_api_host'] ?? ''))
                || (string)($existing['pbx_api_scheme'] ?? 'http') !== (string)$vals['pbx_api_scheme']
                || (int)($existing['pbx_api_port'] ?? 80) !== (int)$vals['pbx_api_port']
                || (int)($existing['pbx_api_verify_tls'] ?? 1) !== (int)$vals['pbx_api_verify_tls']) {
                $vals['pbx_api_ready'] = 0;
                $vals['pbx_api_last_ok'] = 0;
            }
            $oldDockerHost = trim((string)($existing['docker_host'] ?? ''));
            $oldDockerPort = (int)($existing['docker_ssh_port'] ?? 22);
            if ($oldDockerHost !== trim((string)$vals['docker_host']) || $oldDockerPort !== (int)$vals['docker_ssh_port']) {
                $vals['docker_host_key'] = null;
                $vals['docker_bootstrapped_at'] = 0;
            }
        }
        if (empty($vals['pbx_host_key'])) {
            $vals['pbx_agent_ready'] = 0;
        }
        
        $savedId = 0;
        if ($id > 0) {
            $changes=[];
            foreach ($vals as $field=>$value) {
                if (($existing[$field]??null)===$value || (($existing[$field]??null)!==null&&$value!==null&&(string)$existing[$field]===(string)$value)) { continue; }
                $changes[$field]=$value;
            }
            if ($changes) {
                $where=['id=?'];$before=[$id];
                foreach ($existing as $field=>$value) {
                    if ($field==='id'||$field==='pbx_api_last_ok') { continue; }
                    if (!preg_match('/^[a-z0-9_]+$/D',(string)$field)) { throw new \RuntimeException('Invalid saved site field'); }
                    $where[]=$value===null?"`$field` IS NULL":"`$field`=?";
                    if ($value!==null) { $before[]=$value; }
                }
                $set=implode(',',array_map(static fn(string $field):string=>"`$field`=?",array_keys($changes)));
                $st=$this->db->prepare('UPDATE ucmp_sites SET '.$set.' WHERE '.implode(' AND ',$where));
                $st->execute(array_merge(array_values($changes),$before));
                if ($st->rowCount()!==1) { throw new \DomainException('Site changed while saving; reload it before retrying'); }
            }
            $savedId = $id;
        } else {
            $cols = implode(',', array_map(fn($f) => "`$f`", array_keys($vals)));
            $ph = implode(',', array_map(fn($f) => ":$f", array_keys($vals)));
            $st = $this->db->prepare("INSERT INTO ucmp_sites ($cols, pbx_api_secret) VALUES ($ph, NULL)");
            $st->execute($vals);
            $savedId = (int)$this->db->lastInsertId();
        }

        // A request may have opened this site's SSH/SFTP connection before an
        // enrollment or credential edit. Do not reuse that stale connection.
        unset($this->siteConnections[$savedId]);
        return $savedId;
    }
    public function deleteSite(int $id): void
    {
        $this->db->prepare("DELETE FROM ucmp_sites WHERE id = ? AND is_local = 0")->execute([$id]);
        unset($this->siteConnections[$id]);
    }

    /* ---------------------------------------------------------------- */
    /* Data: templates / groups / phones (generic JSON-aware CRUD)       */
    /* ---------------------------------------------------------------- */
    public function isSiteLocal(int $siteId): bool
    {
        if ($siteId === $this->localSiteId() || $siteId === 0) {
            return true;
        }
        $site = $this->getSite($siteId);
        return !empty($site['is_local']);
    }

    public function remoteQuery(int $siteId, string $method, array $args = [])
    {
        $resourceKey = $this->snapshotResourceKey($method, $args);
        if ($resourceKey !== null) {
            $site = $this->getSite($siteId) ?: [];
            $cache = $this->siteSnapshotCache();
            $envelope = $cache->readThrough(
                \FreePBX\modules\Ucmprovisioner\SiteSnapshotCache::INTENT_READ,
                (string)$siteId,
                $resourceKey,
                300,
                fn() => $this->remoteLiveQuery($siteId, $method, $args),
                fn(array $data): array => $this->sanitizeSnapshotData($method, $data)
            );
            $meta = $envelope['meta'] + [
                'site_id'=>$siteId,
                'site_name'=>(string)($site['name'] ?? ('Site #' . $siteId)),
                'method'=>$method,
            ];
            $this->siteReadStates[$siteId . ':' . $resourceKey] = $meta;
            return is_array($envelope['data'])
                ? $envelope['data']
                : $this->emptySnapshotResult($method, $meta);
        }
        try {
            return $this->remoteLiveQuery($siteId, $method, $args);
        } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
            // These actions render completed per-stage/per-phone failures just as local calls do.
            // Keep unresolved receipts exceptional so no consumer can replay a mutation.
            if (in_array($method, ['rebuild','sendPhoneCommands'], true)
                && ($error->receipt['state'] ?? '') === 'completed'
                && is_array($error->receipt['result'] ?? null)) {
                return $error->receipt['result'];
            }
            throw $error;
        }
    }

    /** Mutations and uncached reads always use a live authenticated channel. */
    private function remoteLiveQuery(int $siteId, string $method, array $args = [])
    {
        return \FreePBX\modules\Ucmprovisioner\StorageBusyRetry::run(
            fn() => $this->remoteLiveQueryOnce($siteId, $method, $args), $method, (array)($args[0] ?? []),
            fn() => $this->replicationProgress($siteId, 'Waiting for destination storage', 'Another writer is active; this operation has not started'));
    }

    private function remoteLiveQueryOnce(int $siteId, string $method, array $args = [])
    {
        $site = $this->getSite($siteId) ?: [];
        $readOnly = $this->rpcIsReadOnly($method, $args);
        $request = ['rpc_version'=>2, 'method'=>$method, 'args'=>array_values($args)];
        if (!$readOnly) { $request['operation_id'] = bin2hex(random_bytes(16)); }
        $apiFailure = null;
        if (!empty($site['pbx_api_enabled']) && !empty($site['pbx_api_ready'])) {
            try {
                $result = $this->siteConnection($siteId)->execApi($request);
            } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $e) {
                throw $e; // Authenticated operational failure: never re-execute it over SSH.
            } catch (\Throwable $e) {
                $apiFailure = $e;
                if (!$readOnly) {
                    try {
                        // Query only the original operation receipt. Even a legacy/unknown
                        // destination never receives the mutation a second time.
                        return $this->remoteQueryViaSsh($siteId, 'rpcOperationStatusLocal', [$request['operation_id']]);
                    } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $statusFailure) {
                        if ((string)($statusFailure->receipt['operation_id'] ?? '') === $request['operation_id']) { throw $statusFailure; }
                        throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                            'Remote execution is unresolved and the destination cannot reconcile operation ' . $request['operation_id'] . '. Do not replay it.',
                            ['state'=>'unresolved', 'operation_id'=>$request['operation_id']]
                        );
                    } catch (\Throwable $statusFailure) {
                        throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                            'Remote execution is unresolved. Reconcile operation ' . $request['operation_id'] . ' before retrying.',
                            ['state'=>'unresolved', 'operation_id'=>$request['operation_id']]
                        );
                    }
                }
            }
            if ($apiFailure === null) {
                // Local health bookkeeping must not turn a completed mutation into a replay.
                try { $this->db->prepare('UPDATE ucmp_sites SET pbx_api_last_ok=? WHERE id=?')->execute([time(),$siteId]); }
                catch (\Throwable $ignored) {}
                return $result;
            }
        }
        try {
            $result = $this->remoteQueryViaSsh($siteId, $method, $args, $request);
            if ($apiFailure !== null) {
                $this->logOperation(
                    $siteId,
                    'api-fallback',
                    'warning',
                    'Federation API unavailable; SSH recovery succeeded',
                    $apiFailure->getMessage()
                );
            }
            return $result;
        } catch (\Throwable $sshFailure) {
            if (!$readOnly && !$sshFailure instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                    'Remote execution is unresolved. Reconcile operation ' . $request['operation_id'] . ' before retrying.',
                    ['state'=>'unresolved', 'operation_id'=>$request['operation_id']]
                );
            }
            if ($apiFailure === null) {
                throw $sshFailure;
            }
            throw new \RuntimeException(
                'Both federation channels failed. API: ' . $apiFailure->getMessage()
                . '; SSH: ' . $sshFailure->getMessage(),
                0,
                $sshFailure
            );
        }
    }

    private function rpcIsReadOnly(string $method, array $args): bool
    {
        if ($method === 'tftpTransferLocal') {
            return in_array(($args[0]['op'] ?? ''), ['capabilities','manifest','download','status'], true);
        }
        if ($method === 'operatorWorkflowLocal') {
            return \FreePBX\modules\Ucmprovisioner\OperatorWorkflowService::isReadOnly(is_array($args[0] ?? null) ? $args[0] : []);
        }
        if ($method === 'tftpOp' && in_array(($args[0]['op'] ?? ''), ['asset_retirement_preview','lock_status'], true)) { return true; }
        return $this->snapshotResourceKey($method, $args) !== null
            || in_array($method, ['rpcOperationStatusLocal','getBoundDeviceLocal','configurationSyncCapabilitiesLocal','configurationSyncStatesLocal','networkScanLocal','networkScanRequestLocal'], true);
    }

    private function rpcOperationJournal(): \FreePBX\modules\Ucmprovisioner\RpcOperationJournal
    {
        return new \FreePBX\modules\Ucmprovisioner\RpcOperationJournal($this->db, fn(string $s): string=>$this->enc($s), fn(string $s): string=>$this->dec($s));
    }

    public function rpcOperationStatusLocal(string $operationId): array
    {
        return $this->rpcOperationJournal()->status($operationId);
    }

    /** @return array<int,array<string,mixed>> */
    public function getSiteReadStates(): array
    {
        return array_values($this->siteReadStates);
    }

    private function siteSnapshotCache(): \FreePBX\modules\Ucmprovisioner\SiteSnapshotCache
    {
        if (!$this->siteSnapshotCache) {
            $this->siteSnapshotCache = new \FreePBX\modules\Ucmprovisioner\SiteSnapshotCache($this->db);
        }
        return $this->siteSnapshotCache;
    }

    /**
     * Only deterministic, read-only RPCs are eligible. Every operation not
     * listed here remains live-only and therefore cannot be replayed from cache.
     */
    private function snapshotResourceKey(string $method, array $args): ?string
    {
        $simpleReads = [
            'getTemplatesLocal','getTemplateLocal','getGroupsLocal','getGroupLocal',
            'getPhonesLocal','getPhoneLocal','getAllSipExtensionsLocal',
            'getSiteSettingsLocal','getDefaultTemplateRulesLocal',
            'getPublishedDirectoryPhonebooksLocal','listPjsipTrunks',
            'moduleStatus','getPatchInventoryLocal',
        ];
        $safe = in_array($method, $simpleReads, true);
        if ($method === 'tftpOp') {
            $safe = in_array(
                (string)($args[0]['op'] ?? ''),
                ['firmware_inventory', 'managed_asset_inventory', 'timezone_inventory', 'timezone_versions', 'cisco_locale_inventory'],
                true
            );
        } elseif ($method === 'mediaAssetOpLocal') {
            $safe = in_array(
                (string)($args[0]['op'] ?? ''),
                ['list', 'inventory', 'special_catalog_list'],
                true
            );
        } elseif ($method === 'fail2banOpLocal') {
            $safe = in_array((string)($args[0]['op'] ?? ''), ['overview','detail'], true);
        } elseif ($method === 'phoneServiceOpLocal') {
            $safe = (string)($args[0]['op'] ?? '') === 'list';
        }
        if (!$safe) {
            return null;
        }
        $stable = $this->stableSnapshotValue(array_values($args));
        $digest = hash('sha256', json_encode($stable, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return substr($method, 0, 80) . ':' . substr($digest, 0, 32);
    }

    private function stableSnapshotValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            $item = $this->stableSnapshotValue($item);
        }
        unset($item);
        return $value;
    }

    /** Remove values that are useful live but must never become durable snapshots. */
    private function sanitizeSnapshotData(string $method, array $data): array
    {
        $data = $this->projectSnapshotValue($data);
        if ($method === 'getSiteSettingsLocal') {
            foreach (array_keys($data) as $key) {
                $value = $data[$key];
                if (preg_match('/(?:password|passwd|secret|token|credential|private[_-]?key|authorization|cookie|api[_-]?key)/i', (string)$key)
                    || (is_string($value) && preg_match('/[?&](?:token|password|secret|key)=/i', $value))
                ) {
                    unset($data[$key]);
                }
            }
            return $data;
        }
        if (in_array($method, ['getTemplatesLocal','getTemplateLocal'], true)) {
            $single = $method === 'getTemplateLocal';
            $rows = $single ? [$data] : $data;
            foreach ($rows as &$row) {
                if (!is_array($row)) { continue; }
                $row['phonebook_source_url'] = null;
                $settings = json_decode((string)($row['setting_overrides'] ?? ''), true);
                if (is_array($settings)) {
                    foreach (array_keys($settings) as $key) {
                        if (preg_match('/(?:password|passwd|secret|token|credential|private[_-]?key|authorization|cookie|api[_-]?key)/i', (string)$key)) {
                            unset($settings[$key]);
                        }
                    }
                    $row['setting_overrides'] = json_encode(
                        $settings,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    );
                }
            }
            unset($row);
            return $single ? ($rows[0] ?? []) : $rows;
        }
        if ($method === 'phoneServiceOpLocal') {
            // Example URLs contain the site-local read bearer. The offline
            // catalog remains useful without persisting that credential.
            unset($data['urls']);
            return $data;
        }
        return $data;
    }

    /** Decode structured JSON before projecting it; serialized overrides are not opaque safe text. */
    private function projectSnapshotValue(array $data): array
    {
        foreach ($data as $key=>$value) {
            if (!is_int($key) && preg_match('/(?:password|passwd|secret|token|credential|private[_-]?key|crypt[_-]?key|authorization|cookie|api[_-]?key)/i', (string)$key)) {
                unset($data[$key]);
                continue;
            }
            if (is_string($value) && preg_match('#https?://[^\\s]*#i', $value)) {
                $url = parse_url($value);
                if (is_array($url) && (isset($url['user']) || isset($url['pass'])
                    || preg_match('/(?:^|&)(?:token|password|passwd|secret|key|api[_-]?key|authorization)=/i', rawurldecode((string)($url['query'] ?? ''))))) {
                    unset($data[$key]);
                    continue;
                }
            }
            if (is_array($value)) { $data[$key] = $this->projectSnapshotValue($value); }
            elseif (is_string($value) && in_array((string)$key, ['overrides','setting_overrides','lines_json','settings_json','scope_json'], true)) {
                $decoded = json_decode($value, true);
                $data[$key] = is_array($decoded)
                    ? json_encode($this->projectSnapshotValue($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    : null;
            }
        }
        return $data;
    }

    private function emptySnapshotResult(string $method, array $meta): array
    {
        if (in_array($method, [
            'getTemplatesLocal','getTemplateLocal','getGroupsLocal','getGroupLocal',
            'getPhonesLocal','getPhoneLocal','getAllSipExtensionsLocal',
            'getSiteSettingsLocal','getDefaultTemplateRulesLocal',
        ], true)) {
            return [];
        }
        return [
            'ok'=>false,
            'offline'=>true,
            'message'=>'The site is unreachable and no last-known-good snapshot exists for this view.',
            '_site_read_state'=>$meta,
        ];
    }

    private function remoteQueryViaSsh(int $siteId, string $method, array $args = [], array $metadata = [])
    {
        $site = $this->getSite($siteId) ?: [];
        if (empty($site['pbx_agent_ready'])) {
            throw new \RuntimeException("Site #{$siteId} PBX agent is not enrolled; bootstrap ucmp-pbx first");
        }
        $conn = $this->siteConnection($siteId);
        $request = ['rpc_version'=>2, 'method'=>$method, 'args'=>array_values($args)];
        if (!$this->rpcIsReadOnly($method, $args)) { $request['operation_id'] = (string)($metadata['operation_id'] ?? bin2hex(random_bytes(16))); }
        $payload = base64_encode((string)json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $res = $conn->execAgent($payload);
        $decoded = json_decode($res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Remote SSH query to site #{$siteId} returned invalid JSON");
        }
        return \FreePBX\modules\Ucmprovisioner\RpcResponse::unwrap($decoded);
    }

    private function assertRemoteLocalRow(string $table, int $id, string $label, int $localId): void
    {
        $tables = [
            'ucmp_templates'=>'ucmp_templates',
            'ucmp_groups'=>'ucmp_groups',
            'ucmp_phones'=>'ucmp_phones',
        ];
        if ($id <= 0 || !isset($tables[$table])) {
            throw new \InvalidArgumentException("Choose a valid local $label");
        }
        $stmt = $this->db->prepare("SELECT site_id FROM {$tables[$table]} WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $siteId = $stmt->fetchColumn();
        if ($siteId === false) {
            throw new \InvalidArgumentException(ucfirst($label) . ' was not found');
        }
        $effectiveSiteId = (int)$siteId ?: $localId;
        if ($effectiveSiteId !== $localId) {
            throw new \RuntimeException(ucfirst($label) . ' does not belong to this PBX');
        }
    }

    /**
     * Entry point used by the forced-command SSH agent. Every callable operation is explicit,
     * and all incoming central-site IDs are translated to this PBX's own local row ID.
     */
    public function remoteDispatch(string $payload): string
    {
        try {
            $request = json_decode((string)base64_decode($payload, true), true, 32, JSON_THROW_ON_ERROR);
            $operationRequest = $request;
            $method = (string)($request['method'] ?? '');
            $args = is_array($request['args'] ?? null) ? array_values($request['args']) : [];
            $allowed = [
                'operatorWorkflowLocal',
                'rpcOperationStatusLocal', 'tftpTransferLocal', 'wallpaperEditorInputLocal',
                'getTemplatesLocal', 'getTemplateLocal', 'saveTemplateLocal', 'deleteTemplateLocal', 'duplicateTemplateLocal',
                'getGroupsLocal', 'getGroupLocal', 'saveGroupLocal', 'deleteGroupLocal',
                'getPhonesLocal', 'getPhoneLocal', 'savePhoneLocal', 'savePhoneAssignmentLocal', 'savePhoneAssignmentByIdentityLocal', 'clearPhoneConfigLocal', 'deletePhoneLocal', 'deletePhonesLocal',
                'phoneConfigFilesLocal', 'recordPhoneConfigOverrideLocal',
                'getAllSipExtensionsLocal', 'getBoundDeviceLocal', 'importDirectorySnapshotLocal',
                'stageDirectorySnapshotChunkLocal', 'commitDirectorySnapshotLocal', 'abortDirectorySnapshotLocal',
                'refreshDirectorySourceTokensLocal',
                'getSiteSettingsLocal', 'getDefaultTemplateRulesLocal', 'saveSettingsLocal', 'moduleStatus',
                'getPatchInventoryLocal', 'stageAsteriskBundleChunkLocal',
                'commitAsteriskBundleLocal', 'abortAsteriskBundleLocal',
                'discover', 'rebuild', 'previewPhone', 'sendPhoneCommand', 'sendPhoneCommands', 'tftpOp',
                'liveStatus', 'backfillLegacyMacs', 'listPjsipTrunks',
                'previewExplicitPublishPeer', 'prepareExplicitPublishPeer',
                'commitPublishMeshTransaction', 'rollbackPublishMeshTransaction',
                'adoptDockerEnrollment', 'adoptPbxTftpEnrollment', 'inspectPbxTftpLocal', 'activateTftpArchitectureLocal', 'mediaAssetOpLocal',
                'captureTimezoneVersionLocal', 'quarantineTimezoneVersionLocal',
                'activateTimezoneVersionLocal', 'adoptTimezoneVersionLocal',
                'resetManagedTftpStorageLocal', 'registerManagedTftpCopiesLocal',
                'replaceWallpaperReferencesLocal',
                'getProvisionerPublicKey', 'getPublishedDirectoryPhonebooksLocal', 'copyPublishedDirectoryPhonebookPolicyLocal',
                'configureFederationApiLocal', 'fail2banOpLocal', 'phoneServiceOpLocal', 'networkScanLocal', 'networkScanRequestLocal',
                'configurationSyncCapabilitiesLocal', 'configurationSyncSnapshotLocal', 'configurationSyncApplyLocal',
                'configurationSyncPrepareAssetLocal', 'configurationSyncCommitAssetLocal',
                'configurationSyncResumeLocal', 'configurationSyncReleaseLocal', 'configurationSyncStatesLocal', 'configurationSyncLegacyOwnershipLocal'
            ];
            if (!in_array($method, $allowed, true) || !method_exists($this, $method)) {
                throw new \RuntimeException('Remote operation is not allowed');
            }
            if ($method === 'tftpTransferLocal' && (int)($request['rpc_version'] ?? 0) < 2) {
                throw new \RuntimeException('TFTP transfers require receipted RPC version 2');
            }

            $localId = $this->localSiteId();
            $rowMethods = [
                'getTemplateLocal'=>['ucmp_templates','template'],
                'deleteTemplateLocal'=>['ucmp_templates','template'],
                'duplicateTemplateLocal'=>['ucmp_templates','template'],
                'getGroupLocal'=>['ucmp_groups','group'],
                'deleteGroupLocal'=>['ucmp_groups','group'],
                'getPhoneLocal'=>['ucmp_phones','phone'],
                'clearPhoneConfigLocal'=>['ucmp_phones','phone'],
                'deletePhoneLocal'=>['ucmp_phones','phone'],
                'previewPhone'=>['ucmp_phones','phone'],
                'sendPhoneCommand'=>['ucmp_phones','phone'],
            ];
            if (isset($rowMethods[$method])) {
                $this->assertRemoteLocalRow(
                    $rowMethods[$method][0],
                    (int)($args[0] ?? 0),
                    $rowMethods[$method][1],
                    $localId
                );
            }
            if (in_array($method, ['deleteTemplateLocal', 'deleteGroupLocal'], true)) {
                $input = is_array($args[1] ?? null) ? $args[1] : [];
                $args = [(int)($args[0] ?? 0), [
                    'sync_edit_confirmed'=>!empty($input['sync_edit_confirmed']) ? 1 : 0,
                    'sync_edit_reason'=>substr(trim((string)($input['sync_edit_reason'] ?? '')), 0, 2000),
                ]];
            }
            if ($method === 'recordPhoneConfigOverrideLocal') {
                $this->assertRemoteLocalRow(
                    'ucmp_phones',
                    (int)($args[1] ?? 0),
                    'phone',
                    $localId
                );
                $args[0] = $localId;
            } elseif ($method === 'phoneConfigFilesLocal') {
                $args = [$localId];
            }
            if (in_array($method, ['saveTemplateLocal', 'saveGroupLocal', 'savePhoneLocal', 'savePhoneAssignmentLocal', 'savePhoneAssignmentByIdentityLocal'], true)) {
                $existingId = (int)(is_array($args[0] ?? null) ? ($args[0]['id'] ?? 0) : 0);
                if ($existingId > 0) {
                    $saveRows = [
                        'saveTemplateLocal'=>['ucmp_templates','template'],
                        'saveGroupLocal'=>['ucmp_groups','group'],
                        'savePhoneLocal'=>['ucmp_phones','phone'],
                        'savePhoneAssignmentLocal'=>['ucmp_phones','phone'],
                        'savePhoneAssignmentByIdentityLocal'=>['ucmp_phones','phone'],
                    ];
                    $this->assertRemoteLocalRow($saveRows[$method][0], $existingId, $saveRows[$method][1], $localId);
                }
            }
            if (in_array($method, ['getTemplatesLocal', 'getGroupsLocal', 'getDefaultTemplateRulesLocal'], true)) {
                $args[0] = $localId;
            } elseif ($method === 'getSiteSettingsLocal') {
                $args = [$localId];
            } elseif ($method === 'saveSettingsLocal') {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif ($method === 'getPhonesLocal') {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif (in_array($method, ['getAllSipExtensionsLocal', 'getBoundDeviceLocal'], true)) {
                // These operate only on the PBX receiving the forced-command request.
            } elseif ($method === 'operatorWorkflowLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
                $args[0]['site_id'] = $localId;
                // Transport authenticates an administrator, not a claimed site key.
                $args[0]['_remote_request'] = true;
            } elseif ($method === 'importDirectorySnapshotLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : [], (string)($args[1] ?? '')];
            } elseif ($method === 'wallpaperEditorInputLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
            } elseif ($method === 'stageDirectorySnapshotChunkLocal') {
                $args = [(string)($args[0] ?? ''), (int)($args[1] ?? -1), (int)($args[2] ?? 0), (string)($args[3] ?? '')];
            } elseif ($method === 'commitDirectorySnapshotLocal') {
                $args = [(string)($args[0] ?? ''), (int)($args[1] ?? 0), (string)($args[2] ?? '')];
            } elseif ($method === 'abortDirectorySnapshotLocal') {
                $args = [(string)($args[0] ?? '')];
            } elseif ($method === 'refreshDirectorySourceTokensLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
            } elseif ($method === 'stageAsteriskBundleChunkLocal') {
                $args = [
                    (string)($args[0] ?? ''), (int)($args[1] ?? -1),
                    (int)($args[2] ?? 0), (string)($args[3] ?? ''),
                ];
            } elseif ($method === 'commitAsteriskBundleLocal') {
                $args = [
                    (string)($args[0] ?? ''), (int)($args[1] ?? 0),
                    (string)($args[2] ?? ''), (string)($args[3] ?? ''),
                    (int)($args[4] ?? 0),
                ];
            } elseif ($method === 'abortAsteriskBundleLocal') {
                $args = [(string)($args[0] ?? '')];
            } elseif (in_array($method, ['saveTemplateLocal', 'saveGroupLocal', 'savePhoneLocal', 'savePhoneAssignmentLocal', 'savePhoneAssignmentByIdentityLocal'], true)) {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif ($method === 'discover') {
                $args = [$localId];
            } elseif ($method === 'rebuild') {
                $args = [
                    (string)($args[0] ?? 'all'),
                    !empty($args[1]),
                    $localId,
                    !empty($args[3]),
                    false,
                    trim((string)($args[5] ?? '')) ?: null,
                    null,
                    !empty($args[7]),
                ];
            } elseif ($method === 'sendPhoneCommands') {
                $args = [is_array($args[0] ?? null) ? $args[0] : [], (string)($args[1] ?? ''), $localId];
            } elseif ($method === 'tftpOp') {
                $request = is_array($args[0] ?? null) ? $args[0] : [];
                $operation = (string)($request['op'] ?? '');
                if (!in_array($operation, [
                    'firmware_inventory', 'firmware_rescan',
                    'managed_asset_inventory', 'managed_asset_rescan', 'managed_asset_scan_reset',
                    'asset_retirement_preview', 'asset_reassign', 'asset_retire', 'asset_restore',
                    'lock_status',
                    'timezone_inventory', 'timezone_versions', 'cisco_locale_inventory',
                ], true)) {
                    throw new \RuntimeException('Remote TFTP RPC is limited to bounded inventory operations');
                }
                $args = [[
                    'op'=>$operation,
                    'site_id'=>$localId,
                    'bundle_id'=>max(0, (int)($request['bundle_id'] ?? 0)),
                    'scan_token'=>substr(strtolower(trim((string)($request['scan_token'] ?? ''))), 0, 32),
                    'profile'=>substr(trim((string)($request['profile'] ?? '')), 0, 80),
                    'user_locale'=>substr(trim((string)($request['user_locale'] ?? '')), 0, 64),
                    'network_locale'=>substr(trim((string)($request['network_locale'] ?? '')), 0, 64),
                    'model_id'=>max(0, (int)($request['model_id'] ?? 0)),
                    'kind'=>substr(trim((string)($request['kind'] ?? '')), 0, 16),
                    'state'=>substr(trim((string)($request['state'] ?? '')), 0, 16),
                    'bundle_key'=>substr(trim((string)($request['bundle_key'] ?? '')), 0, 128),
                    'limit'=>max(1, min(500, (int)($request['limit'] ?? 200))),
                    'offset'=>max(0, (int)($request['offset'] ?? 0)),
                    'asset_id'=>max(0, (int)($request['asset_id'] ?? 0)),
                    'replacement_asset_id'=>max(0, (int)($request['replacement_asset_id'] ?? 0)),
                    'expected_revision'=>substr(strtolower(trim((string)($request['expected_revision'] ?? ''))), 0, 64),
                    'recovery_id'=>substr(trim((string)($request['recovery_id'] ?? '')), 0, 96),
                ]];
            } elseif (in_array($method, ['captureTimezoneVersionLocal', 'quarantineTimezoneVersionLocal'], true)) {
                $args = [];
            } elseif ($method === 'activateTimezoneVersionLocal') {
                $args = [max(0, (int)($args[0] ?? 0))];
            } elseif ($method === 'adoptTimezoneVersionLocal') {
                $args = [substr(strtolower(trim((string)($args[0] ?? ''))), 0, 64)];
            } elseif ($method === 'resetManagedTftpStorageLocal') {
                $args = [!empty($args[0])];
            } elseif ($method === 'tftpTransferLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
            } elseif ($method === 'registerManagedTftpCopiesLocal') {
                $rows = is_array($args[0] ?? null) ? $args[0] : [];
                if (!$rows || count($rows) > 500) {
                    throw new \RuntimeException('Managed-copy registration requires between one and five hundred files');
                }
                $clean = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) { throw new \RuntimeException('Managed-copy registration contains an invalid file'); }
                    $path = trim(str_replace('\\', '/', (string)($row['path'] ?? '')), '/');
                    $sha = strtolower(trim((string)($row['sha256'] ?? '')));
                    $size = max(0, (int)($row['size'] ?? 0));
                    if ($path === '' || strlen($path) > 512
                        || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path) === 1
                        || preg_match('/^[a-f0-9]{64}$/D', $sha) !== 1
                        || $size > 1073741824) {
                        throw new \RuntimeException('Managed-copy registration contains unsafe file metadata');
                    }
                    $clean[] = ['path'=>$path,'sha256'=>$sha,'size'=>$size];
                }
                $args = [$clean];
            } elseif ($method === 'mediaAssetOpLocal') {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif ($method === 'fail2banOpLocal') {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif ($method === 'phoneServiceOpLocal') {
                $args[0] = is_array($args[0] ?? null) ? $args[0] : [];
                $args[0]['site_id'] = $localId;
            } elseif ($method === 'liveStatus') {
                $args = [$localId];
            } elseif ($method === 'backfillLegacyMacs') {
                $args = [$localId, !empty($args[1])];
            } elseif ($method === 'listPjsipTrunks') {
                $args = [0];
            } elseif ($method === 'previewExplicitPublishPeer') {
                $args = [is_array($args[0] ?? null) ? array_slice($args[0], 0, 8, true) : []];
            } elseif ($method === 'prepareExplicitPublishPeer') {
                $args = [
                    is_array($args[0] ?? null) ? array_slice($args[0], 0, 8, true) : [],
                    substr((string)($args[1] ?? ''), 0, 64),
                    substr((string)($args[2] ?? ''), 0, 96),
                ];
            } elseif (in_array($method, ['commitPublishMeshTransaction', 'rollbackPublishMeshTransaction'], true)) {
                $args = [substr((string)($args[0] ?? ''), 0, 96)];
            } elseif ($method === 'adoptDockerEnrollment') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
            } elseif ($method === 'getPublishedDirectoryPhonebooksLocal') {
                $args = [];
            } elseif ($method === 'copyPublishedDirectoryPhonebookPolicyLocal') {
                $args = [substr(strtolower(trim((string)($args[0] ?? ''))), 0, 64)];
            } elseif ($method === 'configureFederationApiLocal') {
                $args = [(string)($args[0] ?? ''), !empty($args[1])];
            } elseif ($method === 'configurationSyncSnapshotLocal') {
                $args = [
                    is_array($args[0] ?? null) ? $args[0] : [],
                    is_array($args[1] ?? null) ? $args[1] : [],
                ];
            } elseif ($method === 'configurationSyncApplyLocal') {
                $args = [
                    is_array($args[0] ?? null) ? $args[0] : [],
                    is_array($args[1] ?? null) ? $args[1] : [],
                ];
            } elseif ($method === 'configurationSyncResumeLocal') {
                $args = [
                    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeJobKey((string)($args[0] ?? '')),
                    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectType((string)($args[1] ?? '')),
                    \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectKey((string)($args[2] ?? '')),
                ];
            } elseif ($method === 'configurationSyncReleaseLocal') {
                $args = [\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeJobKey((string)($args[0] ?? ''))];
            } elseif ($method === 'configurationSyncLegacyOwnershipLocal') {
                $args = [is_array($args[0] ?? null) ? $args[0] : []];
            } elseif ($method === 'configurationSyncStatesLocal') {
                $args = [\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectType((string)($args[0] ?? ''))];
            }
            if ($method === 'rpcOperationStatusLocal') {
                $result = $this->rpcOperationStatusLocal((string)($args[0] ?? ''));
            } elseif ((int)($operationRequest['rpc_version'] ?? 0) >= 2) {
                $invoke = fn()=>call_user_func_array([$this, $method], $args);
                $result = $this->rpcIsReadOnly($method, $args)
                    ? \FreePBX\modules\Ucmprovisioner\RpcResponse::completed($invoke())
                    : $this->rpcOperationJournal()->execute((string)($operationRequest['operation_id'] ?? ''),
                        ['method'=>$method, 'args'=>$operationRequest['args'] ?? []], $invoke);
            } else {
                $result = call_user_func_array([$this, $method], $args);
            }
            return (string)json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return (string)json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }
    }

    /* Federated wrappers */
    public function getTemplates(int $siteId = 0, bool $includeGlobal = false): array
    {
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getTemplatesLocal', [0, $includeGlobal]);
        }
        return $this->getTemplatesLocal($siteId, $includeGlobal);
    }

    public function getTemplate(int $id, ?int $siteId = null): ?array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getTemplateLocal', [$id]);
        }
        return $this->getTemplateLocal($id);
    }

    /** Destructive reference checks must not use a stale display snapshot. */
    public function getTemplatesForReferenceCheck(int $siteId): array
    {
        $rows = $this->isSiteLocal($siteId) ? $this->getTemplatesLocal($siteId, true)
            : $this->remoteLiveQuery($siteId,'getTemplatesLocal',[0,true]);
        if (!is_array($rows) || !array_is_list($rows)) { throw new \RuntimeException('Live template references could not be verified'); }
        return $rows;
    }

    public function getDefaultTemplateRules(int $siteId = 0): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getDefaultTemplateRulesLocal', [0]);
        }
        return $this->getDefaultTemplateRulesLocal($siteId);
    }

    public function saveTemplate(array $d): int
    {
        foreach (['ucm_image_upload','ucm_ringtone_upload'] as $uploadField) {
            if (!empty($_FILES[$uploadField]['tmp_name'])
                || (isset($_FILES[$uploadField]) && (int)($_FILES[$uploadField]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
                throw new \InvalidArgumentException('Upload wallpaper and ringtones in Media, then select the managed asset in the template');
            }
        }

        $siteId = (int) ($d['site_id'] ?? 0);
        $phonebookKey = strtolower(trim((string)($d['phonebook_key'] ?? '')));
        $specialPhonebook = in_array($phonebookKey, [
            \FreePBX\modules\Ucmprovisioner\TemplateEngine::PHONEBOOK_SITE_DEFAULT,
            \FreePBX\modules\Ucmprovisioner\TemplateEngine::PHONEBOOK_NONE,
        ], true);
        if ($specialPhonebook) {
            $d['phonebook_key'] = $phonebookKey;
            $d['phonebook_filename'] = '';
            $d['phonebook_source_site'] = '';
            $d['phonebook_source_url'] = '';
        }
        $sourceKey = $specialPhonebook ? '' : trim((string)($d['phonebook_source_site'] ?? ''));
        if (!$specialPhonebook && $sourceKey !== '') {
            $source = null;
            foreach ($this->getSites() as $candidate) {
                if (hash_equals((string)($candidate['site_key'] ?? ''), $sourceKey)) { $source = $candidate; break; }
            }
            if (!$source) { throw new \InvalidArgumentException('Choose a valid phonebook source site'); }
            $d['phonebook_source_site'] = $sourceKey;
            $d['phonebook_source_url'] = $this->directorySourceUrl((int)$source['id']);
        } elseif (!$specialPhonebook) {
            $d['phonebook_source_site'] = '';
            $d['phonebook_source_url'] = '';
        }
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'saveTemplateLocal', [$d]);
        }
        return $this->saveTemplateLocal($d);
    }

    public function deleteTemplate(int $id, ?int $siteId = null, array $input = []): void
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            $this->remoteQuery($siteId, 'deleteTemplateLocal', [$id,$input]);
            return;
        }
        $this->deleteTemplateLocal($id, $input);
    }

    public function duplicateTemplate(int $id, ?int $siteId = null): int
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'duplicateTemplateLocal', [$id]);
        }
        return $this->duplicateTemplateLocal($id);
    }

    public function getGroups(int $siteId = 0): array
    {
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getGroupsLocal', [0]);
        }
        return $this->getGroupsLocal($siteId);
    }

    public function getGroup(int $id, ?int $siteId = null): ?array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getGroupLocal', [$id]);
        }
        return $this->getGroupLocal($id);
    }

    public function saveGroup(array $d): int
    {
        $siteId = (int) ($d['site_id'] ?? 0);
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'saveGroupLocal', [$d]);
        }
        return $this->saveGroupLocal($d);
    }

    public function deleteGroup(int $id, ?int $siteId = null, array $input = []): void
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            $this->remoteQuery($siteId, 'deleteGroupLocal', [$id,$input]);
            return;
        }
        $this->deleteGroupLocal($id,$input);
    }

    public function getPhones(array $filters = []): array
    {
        $siteId = (int) ($filters['site_id'] ?? 0);
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            $rows = (array)$this->remoteQuery($siteId, 'getPhonesLocal', [$filters]);
            foreach ($rows as &$row) {
                if (is_array($row)) { $row['site_id'] = $siteId; }
            }
            unset($row);
            return $rows;
        }
        return $this->getPhonesLocal($filters);
    }

    public function getPhone(int $id, ?int $siteId = null): ?array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            $row = $this->remoteQuery($siteId, 'getPhoneLocal', [$id]);
            if (is_array($row)) { $row['site_id'] = $siteId; }
            return is_array($row) ? $row : null;
        }
        return $this->getPhoneLocal($id);
    }

    public function savePhone(array $d): int
    {
        return (int)($this->savePhoneAssignment($d)['id'] ?? 0);
    }

    public function savePhoneAssignment(array $d): array
    {
        $siteId = (int) ($d['site_id'] ?? 0);
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            // Numeric ucmp_models IDs belong to one PBX database and are not a
            // federated identity.  A new endpoint intentionally fails closed
            // against an older remote module instead of silently selecting the
            // unrelated row that happens to share the controller's integer ID.
            if (trim((string)($d['model_key'] ?? '')) !== '') {
                return $this->remoteQuery($siteId, 'savePhoneAssignmentByIdentityLocal', [$d]);
            }
            return $this->remoteQuery($siteId, 'savePhoneAssignmentLocal', [$d]);
        }
        return $this->savePhoneAssignmentLocal($d);
    }

    public static function phoneBindingRevision(array $phone): string
    {
        $fields=[];
        foreach (['id','mac','extension_id','model_id','family','config_filename','updated'] as $key) { $fields[$key]=(string)($phone[$key]??''); }
        $fields['mac']=strtoupper(preg_replace('/[^0-9A-Fa-f]/','',$fields['mac']));
        return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
    }

    public function deletePhone(int $id, ?int $siteId = null, ?string $expectedBinding = null): array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'deletePhoneLocal', [$id,$expectedBinding]);
        }
        return $this->deletePhoneLocal($id,$expectedBinding);
    }

    /** Delete one bounded selection on exactly one PBX/site. */
    public function deletePhones(array $phoneIds, ?int $siteId = null, ?array $expectedBindings = null): array
    {
        $siteId = ($siteId !== null && $siteId > 0) ? $siteId : 0;
        $phoneIds = array_values(array_unique(array_filter(array_map('intval', $phoneIds), static fn(int $id): bool => $id > 0)));
        if ($siteId < 1) { return ['ok'=>false, 'message'=>'Choose one specific site before deleting phones']; }
        if (!$phoneIds) { return ['ok'=>false, 'message'=>'Select at least one phone']; }
        if (count($phoneIds) > 200) { return ['ok'=>false, 'message'=>'A delete batch is limited to 200 phones']; }
        if (!$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'deletePhonesLocal', [$phoneIds,$expectedBindings]);
        }
        return $this->deletePhonesLocal($phoneIds,$expectedBindings);
    }

    /* Local CRUD implementation methods */
    public function getTemplatesLocal(int $siteId = 0, bool $includeGlobal = false): array
    {
        if ($siteId > 0) {
            $sql = $includeGlobal 
                ? "SELECT * FROM ucmp_templates WHERE site_id = ? OR site_id IS NULL ORDER BY name"
                : "SELECT * FROM ucmp_templates WHERE site_id = ? ORDER BY name";
            $s = $this->db->prepare($sql);
            $s->execute([$siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->db->query("SELECT * FROM ucmp_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getTemplateLocal(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ucmp_templates WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getDefaultTemplateRulesLocal(int $siteId = 0): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        $stmt = $this->db->prepare(
            'SELECT r.*,t.name AS template_name
               FROM ucmp_default_template_rules r
               LEFT JOIN ucmp_templates t ON t.id=r.template_id
              WHERE r.site_id=?
              ORDER BY r.target_family,r.target_series,r.target_model,r.target_role,r.id'
        );
        $stmt->execute([$siteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    public function saveTemplateLocal(array $d): int
    {
        return $this->withConfigurationSyncLock(fn():int=>$this->saveTemplateUnderLock($d));
    }

    private function saveTemplateUnderLock(array $d): int
    {
        $templateId = (int)($d['id'] ?? 0);
        $siteId = (int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        $templateName = trim((string)($d['name'] ?? ''));
        if ($templateName === '' || mb_strlen($templateName, 'UTF-8') > 64) {
            throw new \InvalidArgumentException('Template name is required and must be at most 64 characters');
        }
        $syncEditItems = [];
        $existingTemplate = null;
        $existingRevision = 0;
        if ($templateId > 0) {
            $existingTemplate = $this->getTemplateLocal($templateId);
            if (!$existingTemplate) {
                throw new \InvalidArgumentException('The provisioning template was not found');
            }
            $existingSiteId = (int)($existingTemplate['site_id'] ?? 0) ?: $this->localSiteId();
            if ($existingSiteId !== $siteId) {
                throw new \InvalidArgumentException('A provisioning template cannot be moved between sites; duplicate it on the destination site instead');
            }
            $existingRevision = max(1, (int)($existingTemplate['revision'] ?? 1));
            if (!array_key_exists('revision', $d) || (int)$d['revision'] !== $existingRevision) {
                throw new \RuntimeException('This template was changed by another administrator. Reload it before saving your changes.');
            }
            $syncEditItems = $this->configurationSyncEditGuard('template', $templateId, '', $d);
        }
        $sameName = $this->db->prepare(
            'SELECT id FROM ucmp_templates WHERE site_id=? AND name=? AND id<>? LIMIT 1'
        );
        $sameName->execute([$siteId, $templateName, $templateId]);
        if ($sameName->fetchColumn()) {
            throw new \InvalidArgumentException('A template named "' . $templateName . '" already exists on this site');
        }
        $targetFamily = trim((string)($d['target_family'] ?? ''));
        if (!in_array($targetFamily, ['cisco_xml','cisco_cnf','yealink','grandstream'], true)) {
            throw new \InvalidArgumentException('Choose a supported provisioning family');
        }
        $familyVendors = [
            'cisco_xml'=>'Cisco',
            'cisco_cnf'=>'Cisco',
            'yealink'=>'Yealink',
            'grandstream'=>'Grandstream',
        ];
        $targetVendor = trim((string)($d['target_vendor'] ?? '')) ?: $familyVendors[$targetFamily];
        if (strcasecmp($targetVendor, $familyVendors[$targetFamily]) !== 0) {
            throw new \InvalidArgumentException('The template vendor does not match its provisioning family');
        }
        $targetVendor = $familyVendors[$targetFamily];
        $targetSeries = strtolower(trim((string)($d['target_series'] ?? '')));
        $targetRole = strtolower(trim((string)($d['target_role'] ?? '')));
        $targetModel = trim((string)($d['target_model'] ?? ''));
        if ($targetModel !== '' && !preg_match('/^[^|]{1,63}\|[^|]{1,63}$/', $targetModel)) {
            throw new \InvalidArgumentException('Choose a valid model from the catalog');
        }
        $catalogModel = function (string $key): ?array {
            if ($key === '' || !str_contains($key, '|')) { return null; }
            [$vendor, $model] = explode('|', $key, 2);
            $stmt = $this->db->prepare('SELECT * FROM ucmp_models WHERE LOWER(vendor)=LOWER(?) AND LOWER(model)=LOWER(?) LIMIT 1');
            $stmt->execute([$vendor, $model]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $targetModelRow = $targetModel !== '' ? $catalogModel($targetModel) : null;
        if ($targetModel !== '' && (!$targetModelRow || (string)$targetModelRow['family'] !== $targetFamily)) {
            throw new \InvalidArgumentException('The selected model is not in this provisioning family');
        }
        if ($targetModelRow && in_array((string)($targetModelRow['role'] ?? 'desk'), ['dect_handset','dect_radio','dect_manager'], true)) {
            throw new \InvalidArgumentException('Target a provisioned DECT base/system template; handsets and radio components inherit configuration through that system');
        }
        $familyModels = array_values(array_filter(
            $this->getModels(),
            static fn(array $model): bool => (string)$model['family'] === $targetFamily
                && !in_array((string)($model['role'] ?? 'desk'), ['dect_handset','dect_radio','dect_manager','unsupported'], true)
        ));
        $validSeries = [];
        $validRoles = [];
        foreach ($familyModels as $candidate) {
            foreach (\FreePBX\modules\Ucmprovisioner\ModelCatalog::templateScopeKeys(
                (string)$candidate['vendor'],
                (string)$candidate['model'],
                (string)$candidate['family'],
                (string)($candidate['series_key'] ?? ''),
                (string)($candidate['firmware_profile'] ?? '')
            ) as $scopeKey) {
                $validSeries[strtolower($scopeKey)] = true;
            }
            $validRoles[strtolower((string)($candidate['role'] ?? 'desk'))] = true;
        }
        if ($targetSeries !== '' && !isset($validSeries[$targetSeries])) {
            throw new \InvalidArgumentException('Choose a valid series for this provisioning family');
        }
        if ($targetRole !== '' && !isset($validRoles[$targetRole])) {
            throw new \InvalidArgumentException('Choose a provisioned device role for this family');
        }
        if ($targetModelRow) {
            $targetModel = (string)$targetModelRow['vendor'] . '|' . (string)$targetModelRow['model'];
            if ($targetSeries !== '' && !\FreePBX\modules\Ucmprovisioner\ModelCatalog::templateScopeMatches(
                $targetSeries,
                $targetModelRow
            )) {
                throw new \InvalidArgumentException('The exact model does not belong to the selected series');
            }
            if ($targetRole !== '' && $targetRole !== strtolower((string)($targetModelRow['role'] ?? 'desk'))) {
                throw new \InvalidArgumentException('The exact model does not have the selected device role');
            }
        }
        $phonebookFilename = trim((string)($d['phonebook_filename'] ?? ''));
        if ($phonebookFilename !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $phonebookFilename)) {
            throw new \InvalidArgumentException('Phonebook filename may contain only letters, numbers, dot, underscore and hyphen');
        }
        $sourceSite = trim((string)($d['phonebook_source_site'] ?? ''));
        $sourceUrl = trim((string)($d['phonebook_source_url'] ?? ''));
        if ($sourceSite !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $sourceSite)) { throw new \InvalidArgumentException('Invalid phonebook source site'); }
        if ($sourceUrl !== '' && (!filter_var($sourceUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $sourceUrl))) { throw new \InvalidArgumentException('Invalid phonebook source URL'); }

        $settingsInput = $d['setting_overrides'] ?? null;
        if (is_array($settingsInput)) {
            if ($settingsInput !== [] && array_is_list($settingsInput)) {
                throw new \InvalidArgumentException('Template setting overrides must be a JSON object');
            }
            $settings = $settingsInput;
        } else {
            $settingsJson = trim((string)$settingsInput);
            if ($settingsJson === '') {
                $settings = [];
            } else {
                if (!str_starts_with($settingsJson, '{')) {
                    throw new \InvalidArgumentException('Template setting overrides must be a JSON object');
                }
                try {
                    $settings = json_decode($settingsJson, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \InvalidArgumentException('Template setting overrides contain invalid JSON', 0, $e);
                }
                if (!is_array($settings)) {
                    throw new \InvalidArgumentException('Template setting overrides must be a JSON object');
                }
            }
        }
        $designModel = trim((string)($settings['_design_model'] ?? ''));
        if ($designModel !== '' && !preg_match('/^[^|]{1,63}\|[^|]{1,63}$/', $designModel)) {
            throw new \InvalidArgumentException('The template preview model is invalid');
        }
        $designModelRow = $designModel !== '' ? $catalogModel($designModel) : null;
        if ($designModel !== '' && (!$designModelRow || (string)$designModelRow['family'] !== $targetFamily)) {
            throw new \InvalidArgumentException('The template preview model is not in this provisioning family');
        }
        if ($designModelRow && in_array((string)($designModelRow['role'] ?? 'desk'), ['dect_handset','dect_radio','dect_manager'], true)) {
            throw new \InvalidArgumentException('Use a DECT base/system as the template preview model; handset policy is distributed by that system');
        }
        if ($designModelRow) {
            $designModel = (string)$designModelRow['vendor'] . '|' . (string)$designModelRow['model'];
            $settings['_design_model'] = $designModel;
        }
        $profileModelRow = $targetModelRow ?: $designModelRow;
        $secretKeys = ['cisco_phone_password','cisco_ssh_password','cisco_admin_password','phone_password','admin_password','user_password'];
        $existingSettings = $existingTemplate
            ? (json_decode((string)($existingTemplate['setting_overrides'] ?? '{}'), true) ?: [])
            : [];
        foreach ($secretKeys as $secretKey) {
            if ((!array_key_exists($secretKey, $settings) || (string)$settings[$secretKey] === '') && array_key_exists($secretKey, $existingSettings)) {
                $settings[$secretKey] = $existingSettings[$secretKey];
            }
        }
        foreach (['phone_label_pattern','line_label_pattern','blf_label_pattern','directory_label_pattern'] as $patternKey) {
            if (!array_key_exists($patternKey, $settings)) { continue; }
            $pattern = (string)$settings[$patternKey];
            if ($pattern === '') {
                unset($settings[$patternKey]);
                continue;
            }
            $settings[$patternKey] = \FreePBX\modules\Ucmprovisioner\IdentityLabelFormatter::validatePattern($pattern);
        }
        $timezoneByProfile = $settings['cisco_timezone_by_profile'] ?? [];
        if (!is_array($timezoneByProfile)) {
            throw new \InvalidArgumentException('cisco_timezone_by_profile must be a JSON object');
        }
        $timezoneBundleKey = strtolower(trim((string)($settings['cisco_timezone_bundle_key'] ?? '')));
        if ($timezoneBundleKey !== '' && !preg_match('/^[a-f0-9]{64}$/D', $timezoneBundleKey)) {
            throw new \InvalidArgumentException('Legacy Cisco timezone version pin is invalid');
        }
        $timezoneInventory = null;
        if ($timezoneBundleKey !== '' && !$timezoneByProfile && $profileModelRow) {
            $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::ciscoTimezoneProfile(
                (string)$profileModelRow['vendor'],
                (string)$profileModelRow['model'],
                (string)$profileModelRow['family']
            );
            if ($profile !== null) {
                $timezoneInventory = $this->configurationSyncApplying
                    ? $this->configurationSyncInventory('timezone_versions')
                    : (array)$this->tftpOp(['op'=>'timezone_versions','site_id'=>$siteId ?: $this->localSiteId()]);
                foreach ((array)($timezoneInventory['assets'] ?? []) as $asset) {
                    if ((string)($asset['profile'] ?? '') === $profile
                        && hash_equals($timezoneBundleKey, (string)($asset['source_bundle_key'] ?? ''))) {
                        $timezoneByProfile[$profile] = (string)$asset['asset_key'];
                        unset($settings['cisco_timezone_bundle_key']);
                        break;
                    }
                }
            }
        }
        if ($timezoneByProfile) {
            if ($targetFamily !== 'cisco_xml' || count($timezoneByProfile) > count(\FreePBX\modules\Ucmprovisioner\ModelCatalog::CISCO_TIMEZONE_PROFILES)) {
                throw new \InvalidArgumentException('Cisco timezone assets apply only to Cisco XML runtime profiles');
            }
            $timezoneInventory ??= $this->configurationSyncApplying
                ? $this->configurationSyncInventory('timezone_versions')
                : (array)$this->tftpOp(['op'=>'timezone_versions','site_id'=>$siteId ?: $this->localSiteId()]);
            if (empty($timezoneInventory['ok'])) {
                throw new \RuntimeException((string)($timezoneInventory['message'] ?? 'Cisco timezone inventory is unavailable'));
            }
            $availableTimezone = [];
            foreach ((array)($timezoneInventory['assets'] ?? []) as $asset) {
                if (!empty($asset['selectable'])) {
                    $availableTimezone[(string)($asset['profile'] ?? '')][(string)($asset['asset_key'] ?? '')] = true;
                }
            }
            $cleanTimezone = [];
            foreach ($timezoneByProfile as $profile=>$assetKey) {
                $profile = trim((string)$profile);
                $assetKey = strtolower(trim((string)$assetKey));
                if (!isset(\FreePBX\modules\Ucmprovisioner\ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile])
                    || preg_match('/^[a-f0-9]{64}$/D', $assetKey) !== 1) {
                    throw new \InvalidArgumentException('Cisco timezone selection has an invalid runtime profile or asset identity');
                }
                if (empty($availableTimezone[$profile][$assetKey])) {
                    throw new \RuntimeException("Cisco timezone asset $profile / $assetKey is not retained and phone-accessible on this PBX");
                }
                $cleanTimezone[$profile] = $assetKey;
            }
            $settings['cisco_timezone_by_profile'] = $cleanTimezone;
            unset($settings['cisco_timezone_bundle_key']);
        } else {
            unset($settings['cisco_timezone_by_profile']);
            if ($timezoneBundleKey !== '') { $settings['cisco_timezone_bundle_key'] = $timezoneBundleKey; }
            else { unset($settings['cisco_timezone_bundle_key']); }
        }
        foreach ([
            'cisco_display_schedule_mode'=>'cisco_days_display_not_active',
            'cisco_backlight_schedule_mode'=>'cisco_days_backlight_not_active',
        ] as $modeKey=>$daysKey) {
            $mode = trim((string)($settings[$modeKey] ?? ''));
            if ($mode !== '' && !in_array($mode, ['daily','custom','off'], true)) {
                throw new \InvalidArgumentException("$modeKey must be inherit, active every day, custom, or always off");
            }
            $rawDays = trim((string)($settings[$daysKey] ?? ''));
            $days = $rawDays === '' ? [] : array_values(array_unique(array_filter(
                array_map('trim', explode(',', $rawDays)),
                static fn(string $day): bool => $day !== ''
            )));
            foreach ($days as $day) {
                if (!preg_match('/^[1-7]$/D', $day)) {
                    throw new \InvalidArgumentException("$daysKey contains an invalid Cisco weekday; use 1 through 7");
                }
            }
            sort($days, SORT_NUMERIC);
            if ($mode === '') {
                unset($settings[$modeKey]);
                if ($rawDays !== '') { $settings[$daysKey] = implode(',', $days); }
                continue;
            }
            $settings[$modeKey] = $mode;
            $settings[$daysKey] = $mode === 'off'
                ? '1,2,3,4,5,6,7'
                : ($mode === 'custom' ? implode(',', $days) : '');
        }
        foreach (['cisco_userLocale','cisco_networkLocale'] as $localeKey) {
            if (!array_key_exists($localeKey, $settings)) { continue; }
            $localeValue = trim((string)$settings[$localeKey]);
            if ($localeValue === '') { unset($settings[$localeKey]); continue; }
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $localeValue) !== 1) {
                throw new \InvalidArgumentException("$localeKey may contain only letters, numbers, underscore and hyphen");
            }
            $settings[$localeKey] = $localeValue;
        }
        if (array_filter(
            array_keys($settings),
            static fn(string $key): bool => str_starts_with($key, 'vlan_')
        )) {
            \FreePBX\modules\Ucmprovisioner\VlanPolicy::normalize($settings);
        }
        $firmware = trim(str_replace('\\', '/', (string)($settings['firmware'] ?? '')));
        $inactiveFirmware = trim(str_replace('\\', '/', (string)($settings['inactive_firmware'] ?? '')));
        $kemFirmware = trim(str_replace('\\', '/', (string)($settings['cisco_kem_firmware'] ?? '')));
        foreach (['firmware_by_profile','inactive_firmware_by_profile','cisco_timezone_by_profile','cisco_wallpaper_by_profile','hosted_image_by_profile'] as $mapKey) {
            if (array_key_exists($mapKey, $settings) && !is_array($settings[$mapKey])) {
                throw new \InvalidArgumentException("$mapKey must be a JSON object");
            }
        }
        $firmwareByProfile = is_array($settings['firmware_by_profile'] ?? null) ? $settings['firmware_by_profile'] : [];
        $inactiveByProfile = is_array($settings['inactive_firmware_by_profile'] ?? null) ? $settings['inactive_firmware_by_profile'] : [];
        if ($firmware !== '' && empty($settings['firmware_profile']) && $profileModelRow) {
            $settings['firmware_profile'] = \FreePBX\modules\Ucmprovisioner\ModelCatalog::firmwareProfile((string)$profileModelRow['vendor'], (string)$profileModelRow['model']);
        }
        // Upgrade exact-model templates saved by earlier releases into the portable
        // profile map. A 7940 and 7960 now share cisco:p0s3, while incompatible
        // 88xx audio/video loads remain separate entries.
        if ($firmware !== '' && !empty($settings['firmware_profile'])) {
            $firmwareByProfile[(string)$settings['firmware_profile']] = $firmware;
        }
        if ($inactiveFirmware !== '' && !empty($settings['firmware_profile'])) {
            $inactiveByProfile[(string)$settings['firmware_profile']] = $inactiveFirmware;
        }
        if (($inactiveByProfile || $kemFirmware !== '') && $targetFamily !== 'cisco_xml') {
            throw new \InvalidArgumentException('Inactive and expansion-module loads apply only to Cisco XML phones');
        }
        $allCatalogModels = $this->getModels();
        $catalogFamilies = [];
        foreach ($allCatalogModels as $candidate) {
            $catalogFamilies[strtoupper((string)$candidate['vendor']) . '|' . strtoupper((string)$candidate['model'])] = (string)$candidate['family'];
        }
        if ($firmwareByProfile || $inactiveByProfile || $kemFirmware !== '') {
            $selectionMaps = ['firmware_by_profile'=>$firmwareByProfile, 'inactive_firmware_by_profile'=>$inactiveByProfile];
            foreach ($selectionMaps as $field=>$selections) {
                if (count($selections) > 200) { throw new \InvalidArgumentException("$field contains too many entries"); }
                $clean = [];
                foreach ($selections as $profile=>$load) {
                    $profile = trim((string)$profile);
                    $load = trim(str_replace('\\', '/', (string)$load));
                    if ($load === '') { continue; }
                    if (!preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/i', $profile)) {
                        throw new \InvalidArgumentException('A firmware selection has an invalid compatibility profile');
                    }
                    $compatible = \FreePBX\modules\Ucmprovisioner\ModelCatalog::modelsForFirmwareProfile($profile);
                    if (!$compatible) { throw new \InvalidArgumentException("Unknown firmware profile $profile"); }
                    $familyCompatible = false;
                    foreach ($compatible as $candidateModel) {
                        $key = strtoupper((string)$candidateModel['vendor']) . '|' . strtoupper((string)$candidateModel['model']);
                        if (($catalogFamilies[$key] ?? '') === $targetFamily) { $familyCompatible = true; break; }
                    }
                    if (!$familyCompatible) { throw new \InvalidArgumentException("Firmware profile $profile does not belong to $targetFamily templates"); }
                    if (strlen($load) > 255 || str_contains($load, '..') || str_starts_with($load, '/') || !preg_match('#^[A-Za-z0-9._/-]+$#', $load)) {
                        throw new \InvalidArgumentException("The selected firmware package for $profile has an invalid path");
                    }
                    $clean[$profile] = $load;
                }
                if ($clean) { $settings[$field] = $clean; } else { unset($settings[$field]); }
            }
            if ($kemFirmware !== '') {
                if ($targetModel === '') { throw new \InvalidArgumentException('Restrict the template to an exact KEM-capable phone model before assigning module firmware'); }
                $modelRow = $targetModelRow;
                if (!$modelRow) { throw new \InvalidArgumentException('The selected model is not in the supported catalog'); }
                $type = strtoupper(trim((string)($settings['cisco_kem_type'] ?? '')));
                $allowed = json_decode((string)($modelRow['expansion_types'] ?? '[]'), true) ?: [];
                if (!in_array($type, $allowed, true) || (int)($settings['cisco_kem_count'] ?? 0) < 1) {
                    throw new \InvalidArgumentException("$type is not an enabled expansion module for the selected phone model");
                }
                if (strlen($kemFirmware) > 255 || str_contains($kemFirmware, '..') || str_starts_with($kemFirmware, '/') || !preg_match('#^[A-Za-z0-9._/-]+$#', $kemFirmware)) {
                    throw new \InvalidArgumentException('The selected expansion-module firmware has an invalid path');
                }
                $settings['cisco_kem_firmware'] = $kemFirmware;
            } else {
                unset($settings['cisco_kem_firmware']);
            }
        }

        $wallpaperMap = is_array($settings['cisco_wallpaper_by_profile'] ?? null) ? $settings['cisco_wallpaper_by_profile'] : [];
        $directWallpaper = trim((string)($settings['cisco_default_wallpaper'] ?? ''));
        if ($directWallpaper !== '' && $profileModelRow) {
            $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfileKey((string)$profileModelRow['vendor'], (string)$profileModelRow['model']);
            if ($profile !== null) { $wallpaperMap[$profile] = $directWallpaper; }
        }
        if ($wallpaperMap && $targetFamily !== 'cisco_xml') {
            throw new \InvalidArgumentException('Cisco wallpaper catalogs apply only to Cisco XML phones');
        }
        $validWallpaperProfiles = [];
        foreach (array_keys(\FreePBX\modules\Ucmprovisioner\ModelCatalog::BACKGROUND_PROFILES) as $modelName) {
            $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfileKey('Cisco', (string)$modelName);
            if ($profile !== null) { $validWallpaperProfiles[$profile] = true; }
        }
        $cleanWallpaperMap = [];
        if (count($wallpaperMap) > 200) { throw new \InvalidArgumentException('cisco_wallpaper_by_profile contains too many entries'); }
        foreach ($wallpaperMap as $profile=>$filename) {
            $filename = trim((string)$filename);
            if ($filename === '') { continue; }
            if (!isset($validWallpaperProfiles[(string)$profile]) || !preg_match('/^[A-Za-z0-9_.-]{1,128}\.png$/i', $filename)) {
                throw new \InvalidArgumentException('A template wallpaper must use a supported Cisco screen profile and PNG catalog filename');
            }
            $cleanWallpaperMap[(string)$profile] = $filename;
        }
        if ($cleanWallpaperMap) { $settings['cisco_wallpaper_by_profile'] = $cleanWallpaperMap; }
        else { unset($settings['cisco_wallpaper_by_profile']); }

        $hostedMap = is_array($settings['hosted_image_by_profile'] ?? null) ? $settings['hosted_image_by_profile'] : [];
        $directHosted = trim((string)($settings['hosted_image_url'] ?? ($settings['legacy_logo_url'] ?? '')));
        if ($directHosted !== '' && $profileModelRow) {
            $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaProfileKey((string)$profileModelRow['vendor'], (string)$profileModelRow['model']);
            if ($profile !== null) { $hostedMap[$profile] = $directHosted; }
        }
        $validHostedProfiles = [];
        foreach ($allCatalogModels as $candidate) {
            if ((string)$candidate['family'] !== $targetFamily) { continue; }
            $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaProfileKey((string)$candidate['vendor'], (string)$candidate['model']);
            if ($profile !== null) { $validHostedProfiles[$profile] = true; }
        }
        $cleanHostedMap = [];
        if (count($hostedMap) > 200) { throw new \InvalidArgumentException('hosted_image_by_profile contains too many entries'); }
        foreach ($hostedMap as $profile=>$url) {
            $url = trim((string)$url);
            if ($url === '') { continue; }
            if (!isset($validHostedProfiles[(string)$profile]) || strlen($url) > 1024 || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                throw new \InvalidArgumentException('A template hosted image must use a supported screen profile and HTTP or HTTPS URL');
            }
            $cleanHostedMap[(string)$profile] = $url;
        }
        if ($cleanHostedMap) { $settings['hosted_image_by_profile'] = $cleanHostedMap; }
        else { unset($settings['hosted_image_by_profile']); }

        unset($settings['firmware'], $settings['firmware_profile'], $settings['inactive_firmware'], $settings['cisco_default_wallpaper'], $settings['hosted_image_url'], $settings['legacy_logo_url']);
        $d['setting_overrides'] = $settings ?: null;
        $row = [
            'name'               => $templateName,
            'site_id'            => $siteId,
            'target_vendor'      => $targetVendor,
            'target_family'      => $targetFamily,
            'target_series'      => $targetSeries ?: null,
            'target_role'        => $targetRole ?: null,
            'target_model'       => $targetModel ?: null,
            'phonebook_key'      => preg_replace('/[^a-z0-9_-]/', '', strtolower(trim((string)($d['phonebook_key'] ?? '')))) ?: null,
            'phonebook_filename' => $phonebookFilename ?: null,
            'phonebook_source_site' => $sourceSite ?: null,
            'phonebook_source_url' => $sourceUrl ?: null,
            'buttons'            => $this->jsonField($d['buttons'] ?? null),
            'capacity_overrides' => $this->jsonField($d['capacity_overrides'] ?? null),
            'softkeys'           => $this->softkeyField($d['softkeys'] ?? null),
            'feature_policy'     => $this->jsonField($d['feature_policy'] ?? null),
            'setting_overrides'  => $this->jsonField($d['setting_overrides'] ?? null),
            'member_blf'         => !empty($d['member_blf']) ? 1 : 0,
            'revision'           => max(1, $existingRevision + 1),
            'updated'            => time(),
        ];
        $started = !$this->db->inTransaction();
        if ($started) {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin template save'); }
        } else { $this->db->exec('SAVEPOINT ucmp_template_save'); }
        try {
            $savedId = $this->upsert('ucmp_templates', $row, $templateId);
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems, $d); }
            if ($started) {
                if (!$this->db->commit()) { throw new \RuntimeException('Template save commit is unconfirmed; reload before retrying'); }
            } else { $this->db->exec('RELEASE SAVEPOINT ucmp_template_save'); }
            return $savedId;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                if ($started) { $this->db->rollBack(); }
                else { $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_template_save'); $this->db->exec('RELEASE SAVEPOINT ucmp_template_save'); }
            }
            throw $error;
        }
    }
    public function deleteTemplateLocal(int $id, array $input = []): void
    {
        $this->withConfigurationSyncLock(function()use($id,$input):void{$this->deleteTemplateUnderLock($id,$input);});
    }

    private function deleteTemplateUnderLock(int $id, array $input): void
    {
        $syncEditItems = $this->configurationSyncEditGuard('template', $id, '', $input);
        $groups = $this->db->prepare(
            'SELECT id,name,template_id,template_map,member_exceptions FROM ucmp_groups
              WHERE template_id=? OR template_map LIKE ? OR member_exceptions LIKE ?'
        );
        $templateNeedle = '%"template_id"%' . (string)$id . '%';
        $groups->execute([$id, $templateNeedle, $templateNeedle]);
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $groupRow) {
            $assigned = (int)($groupRow['template_id'] ?? 0) === $id;
            foreach (json_decode((string)($groupRow['template_map'] ?? '[]'), true) ?: [] as $entry) {
                if ((int)($entry['template_id'] ?? 0) === $id) { $assigned = true; break; }
            }
            foreach (json_decode((string)($groupRow['member_exceptions'] ?? '[]'), true) ?: [] as $entry) {
                if ((int)($entry['template_id'] ?? 0) === $id) { $assigned = true; break; }
            }
            if ($assigned) {
                throw new \RuntimeException(
                    'Template is still assigned to provisioning group ' . (string)$groupRow['name']
                    . '; remove the assignment before deleting it'
                );
            }
        }
        $rule = $this->db->prepare('SELECT id FROM ucmp_default_template_rules WHERE template_id=? LIMIT 1');
        $rule->execute([$id]);
        if ($rule->fetchColumn()) {
            throw new \RuntimeException('Template is still selected by a default-template rule; remove that rule before deleting it');
        }
        $started = !$this->db->inTransaction();
        if ($started) {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin template deletion'); }
        } else { $this->db->exec('SAVEPOINT ucmp_template_delete'); }
        try {
            $this->db->prepare("DELETE FROM ucmp_templates WHERE id = ?")->execute([$id]);
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems, $input + ['sync_edit_reason'=>'Deleted in Template Manager']); }
            if ($started) {
                if (!$this->db->commit()) { throw new \RuntimeException('Template deletion commit is unconfirmed; reload before retrying'); }
            } else { $this->db->exec('RELEASE SAVEPOINT ucmp_template_delete'); }
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                if ($started) { $this->db->rollBack(); }
                else { $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_template_delete'); $this->db->exec('RELEASE SAVEPOINT ucmp_template_delete'); }
            }
            throw $error;
        }
    }
    /** Clone a template (buttons/softkeys/feature policy/settings) as a starting point for a new one. */
    public function duplicateTemplateLocal(int $id): int
    {
        $src = $this->getTemplate($id);
        if (!$src) {
            throw new \Exception("Unknown template #$id");
        }
        unset($src['id']);
        $baseName = trim((string)($src['name'] ?? 'Template')) ?: 'Template';
        $siteId = (int)($src['site_id'] ?? 0) ?: $this->localSiteId();
        $copyNumber = 1;
        $sameName = $this->db->prepare('SELECT id FROM ucmp_templates WHERE site_id=? AND name=? LIMIT 1');
        do {
            $suffix = $copyNumber === 1 ? ' (copy)' : ' (copy ' . $copyNumber . ')';
            $src['name'] = $this->templateNameWithSuffix($baseName, $suffix);
            $sameName->execute([$siteId, $src['name']]);
            $copyNumber++;
        } while ($sameName->fetchColumn());
        $src['revision'] = 1;
        $src['updated'] = time();
        return $this->upsert('ucmp_templates', $src, 0);
    }

    public function getGroupsLocal(int $siteId = 0): array
    {
        if ($siteId > 0) {
            $s = $this->db->prepare("SELECT * FROM ucmp_groups WHERE site_id = ? ORDER BY name");
            $s->execute([$siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->db->query("SELECT * FROM ucmp_groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getGroupLocal(int $id): ?array
    {
        $s = $this->db->prepare("SELECT * FROM ucmp_groups WHERE id = ?");
        $s->execute([$id]);
        $group = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$group) {
            return null;
        }
        $members = $this->db->prepare(
            'SELECT extension
               FROM ucmp_extensions
              WHERE group_id=? AND site_id=?
              ORDER BY extension'
        );
        $members->execute([$id, (int)($group['site_id'] ?: $this->localSiteId())]);
        $group['member_extensions'] = array_map(
            'strval',
            $members->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
        return $group;
    }
    public function saveGroupLocal(array $d): int
    {
        return $this->withConfigurationSyncLock(fn():int=>$this->saveGroupUnderLock($d));
    }

    private function saveGroupUnderLock(array $d): int
    {
        $siteId = (int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        $existingGroupId = (int)($d['id'] ?? 0);
        $existingRevision = 0;
        $existingGroup = null;
        $syncEditItems = [];
        if ($existingGroupId > 0) {
            $existingGroup = $this->getGroupLocal($existingGroupId);
            if (!$existingGroup) {
                throw new \InvalidArgumentException('The provisioning group was not found');
            }
            if ((int)($existingGroup['site_id'] ?: $this->localSiteId()) !== $siteId) {
                throw new \InvalidArgumentException('A provisioning group cannot be moved between sites; create it on the destination site instead');
            }
            $existingRevision = max(1, (int)($existingGroup['revision'] ?? 1));
            if (!array_key_exists('revision', $d) || (int)$d['revision'] !== $existingRevision) {
                throw new \RuntimeException('This provisioning group was changed by another administrator. Reload it before saving your changes.');
            }
        }
        $parentGroupId = $this->nullableInt($d['parent_group_id'] ?? null);
        if ($parentGroupId !== null) {
            if ($parentGroupId === (int)($d['id'] ?? 0)) {
                throw new \InvalidArgumentException('A provisioning group cannot inherit from itself');
            }
            $parent = $this->getGroupLocal($parentGroupId);
            if (!$parent || (int)($parent['site_id'] ?: $this->localSiteId()) !== $siteId) {
                throw new \InvalidArgumentException('The parent provisioning group must exist on the same site');
            }
        }
        $templateId = $this->nullableInt($d['template_id'] ?? null);
        $templateMap = $d['template_map'] ?? [];
        if (is_string($templateMap)) {
            if (trim($templateMap) === '') {
                $templateMap = [];
            } else {
                $decodedTemplateMap = json_decode($templateMap, true);
                if (!is_array($decodedTemplateMap)) {
                    throw new \InvalidArgumentException('The ordered template layers are not valid JSON');
                }
                $templateMap = $decodedTemplateMap;
            }
        }
        if (!is_array($templateMap)) {
            throw new \InvalidArgumentException('The ordered template layers must be a list');
        }

        $memberExceptions = array_key_exists('member_exceptions', $d)
            ? $d['member_exceptions']
            : ($existingGroup['member_exceptions'] ?? []);
        if (is_string($memberExceptions)) {
            if (trim($memberExceptions) === '') {
                $memberExceptions = [];
            } else {
                $decodedExceptions = json_decode($memberExceptions, true);
                if (!is_array($decodedExceptions)) {
                    throw new \InvalidArgumentException('The member exceptions are not valid JSON');
                }
                $memberExceptions = $decodedExceptions;
            }
        }
        if (!is_array($memberExceptions)) {
            throw new \InvalidArgumentException('Member exceptions must be a list');
        }
        $normalizedExceptions = [];
        $exceptionExtensions = [];
        $exceptionTemplateIds = [];
        foreach ($memberExceptions as $exception) {
            if (!is_array($exception) || (int)($exception['template_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Every member exception must select a valid final template');
            }
            $candidateTemplateId = (int)$exception['template_id'];
            $members = is_array($exception['extensions'] ?? null) ? $exception['extensions'] : [];
            $members = array_values(array_unique(array_filter(array_map(
                static fn($extension): string => trim((string)$extension),
                $members
            ), static fn(string $extension): bool => $extension !== '')));
            if (!$members) { continue; }
            sort($members, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($members as $extension) {
                if (isset($exceptionExtensions[$extension])) {
                    throw new \InvalidArgumentException("Extension $extension can have only one final member-exception template");
                }
                $exceptionExtensions[$extension] = $candidateTemplateId;
            }
            $exceptionTemplateIds[] = $candidateTemplateId;
            $normalizedExceptions[] = [
                'template_id'=>$candidateTemplateId,
                'extensions'=>$members,
            ];
        }
        $memberExceptions = $normalizedExceptions;
        $templateIds = $templateId ? [$templateId] : [];
        foreach ($templateMap as $entry) {
            if (!is_array($entry) || (int)($entry['template_id'] ?? 0) <= 0) {
                throw new \InvalidArgumentException('Every template layer must select a valid template');
            }
            $templateIds[] = (int)$entry['template_id'];
        }
        $templateIds = array_merge($templateIds, $exceptionTemplateIds);
        foreach (array_values(array_unique($templateIds)) as $candidateTemplateId) {
            $template = $this->getTemplateLocal($candidateTemplateId);
            if (!$template) {
                throw new \InvalidArgumentException('Every template layer must belong to the same site as its provisioning group');
            }
            $templateSiteId = (int)($template['site_id'] ?? 0) ?: $this->localSiteId();
            if ($templateSiteId !== $siteId) {
                throw new \InvalidArgumentException('Every template layer must belong to the same site as its provisioning group');
            }
        }
        $groupName = preg_replace('/\s+/u', ' ', trim((string)($d['name'] ?? 'Group')));
        $groupName = is_string($groupName) ? $groupName : trim((string)($d['name'] ?? 'Group'));
        if ($groupName === '') {
            throw new \InvalidArgumentException('A provisioning group name is required');
        }
        $siblingStmt = $this->db->prepare(
            'SELECT id,name FROM ucmp_groups WHERE site_id=? AND '
            . ($parentGroupId === null ? 'parent_group_id IS NULL' : 'parent_group_id=?')
        );
        $siblingStmt->execute($parentGroupId === null ? [$siteId] : [$siteId, $parentGroupId]);
        $normalizedName = $this->normalizeConfigurationGroupName($groupName);
        foreach ($siblingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sibling) {
            if ((int)($sibling['id'] ?? 0) === $existingGroupId) { continue; }
            if ($this->normalizeConfigurationGroupName((string)($sibling['name'] ?? '')) === $normalizedName) {
                throw new \InvalidArgumentException('A sibling provisioning group with this name already exists on this site');
            }
        }
        $row = [
            'name'          => $groupName,
            'site_id'       => $siteId,
            'parent_group_id' => $parentGroupId,
            'template_id'   => $templateId,
            'template_map'  => $this->jsonField($templateMap),
            'member_exceptions' => $this->jsonField($memberExceptions),
            'member_blf'    => !empty($d['member_blf']) ? 1 : 0,
            'revision'      => max(1, $existingRevision + 1),
            'extra_targets' => $this->jsonField($d['extra_targets'] ?? null),
            'updated'       => time(),
        ];
        $legacySyncRevision = '';
        if ($existingGroup && !$this->configurationSyncApplying) {
            if ($this->configurationSyncGroupPolicyRevision($existingGroup) !== $this->configurationSyncGroupPolicyRevision($row)) {
                $syncEditItems = $this->configurationSyncEditGuard('group',$existingGroupId,'',$d);
            } else {
                // Membership and per-member exceptions belong to this PBX. Upgrade
                // a matching pre-rc21 receipt before changing only those local fields.
                \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
                $legacySyncRevision = $this->configurationSyncGroupRevision($existingGroup);
            }
        }
        $manageMembers = array_key_exists('members_submitted', $d);
        $memberExtensions = $d['member_extensions'] ?? [];
        if (is_string($memberExtensions)) {
            $decoded = json_decode($memberExtensions, true);
            $memberExtensions = is_array($decoded) ? $decoded : [$memberExtensions];
        }
        $memberExtensions = array_values(array_unique(array_filter(array_map(
            static fn($extension): string => trim((string)$extension),
            is_array($memberExtensions) ? $memberExtensions : []
        ), static fn(string $extension): bool => $extension !== '')));

        if ($manageMembers) {
            $validExtensions = [];
            foreach ($this->getAllSipExtensionsLocal() as $extension) {
                $value = trim((string)($extension['id'] ?? ''));
                if ($value !== '') { $validExtensions[$value] = true; }
            }
            $invalid = array_values(array_filter($memberExtensions, static fn(string $extension): bool => !isset($validExtensions[$extension])));
            if ($invalid) {
                throw new \InvalidArgumentException('Unknown PBX extension(s): ' . implode(', ', array_slice($invalid, 0, 10)));
            }
        }
        $exceptionMemberSet = array_fill_keys(
            $manageMembers
                ? $memberExtensions
                : array_map('strval', (array)($existingGroup['member_extensions'] ?? [])),
            true
        );
        $detachedExceptions = array_values(array_filter(
            array_keys($exceptionExtensions),
            static fn(string $extension): bool => !isset($exceptionMemberSet[$extension])
        ));
        if ($detachedExceptions) {
            throw new \InvalidArgumentException(
                'Member exceptions can target only extensions assigned to this group: '
                . implode(', ', array_slice($detachedExceptions, 0, 10))
            );
        }

        $transactionStarted = !$this->db->inTransaction();
        if ($transactionStarted) {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin group save'); }
        } else { $this->db->exec('SAVEPOINT ucmp_group_save'); }
        try {
            if ($legacySyncRevision !== '') {
                $this->db->prepare("UPDATE ucmp_configuration_sync_items SET destination_revision=? WHERE object_type='group' AND destination_id=? AND target_site_id=? AND destination_revision=?")
                    ->execute([$this->configurationSyncGroupPolicyRevision($existingGroup),$existingGroupId,$siteId,$legacySyncRevision]);
            }
            $groupId = $this->upsert('ucmp_groups', $row, $existingGroupId);
            (new \FreePBX\modules\Ucmprovisioner\TemplateStackResolver())->resolve(
                $groupId,
                $this->getGroupsLocal($siteId),
                [],
                [],
                []
            );
            if ($manageMembers) {
                $validExtensions = [];
                foreach ($this->getAllSipExtensionsLocal() as $extension) {
                    $value = trim((string)($extension['id'] ?? ''));
                    if ($value !== '') { $validExtensions[] = $value; }
                }
                (new \FreePBX\modules\Ucmprovisioner\LogicalExtensionService($this->db))
                    ->synchronizeGroupMembers(
                        $siteId,
                        $groupId,
                        $memberExtensions,
                        $validExtensions
                    );
            }
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems,$d + ['sync_edit_reason'=>'Edited in Groups']); }
            if ($transactionStarted) {
                if (!$this->db->commit()) { throw new \RuntimeException('Group save commit is unconfirmed; reload before retrying'); }
            } else { $this->db->exec('RELEASE SAVEPOINT ucmp_group_save'); }
            return $groupId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                if ($transactionStarted) { $this->db->rollBack(); }
                else { $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_group_save'); $this->db->exec('RELEASE SAVEPOINT ucmp_group_save'); }
            }
            throw $e;
        }
    }

    private function normalizeConfigurationGroupName(string $name): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        $name = is_string($name) ? $name : trim($name);
        return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }
    public function deleteGroupLocal(int $id, array $input = []): void
    {
        $this->withConfigurationSyncLock(function()use($id,$input):void{$this->deleteGroupUnderLock($id,$input);});
    }

    private function deleteGroupUnderLock(int $id, array $input): void
    {
        $children = $this->db->prepare('SELECT COUNT(*) FROM ucmp_groups WHERE parent_group_id=?');
        $children->execute([$id]);
        if ((int)$children->fetchColumn() > 0) {
            throw new \RuntimeException('Remove or reassign child provisioning groups before deleting this parent');
        }
        $group = $this->getGroupLocal($id);
        if (!$group) {
            return;
        }
        $siteId = (int)($group['site_id'] ?: $this->localSiteId());
        $members = $this->db->prepare(
            'SELECT
                (SELECT COUNT(*) FROM ucmp_extensions WHERE group_id=? AND site_id=?)
              + (SELECT COUNT(*) FROM ucmp_phones WHERE group_id=? AND extension_id IS NULL AND (site_id=? OR site_id IS NULL))'
        );
        $members->execute([$id, $siteId, $id, $siteId]);
        if ((int)$members->fetchColumn() > 0) {
            throw new \RuntimeException('Move or detach this group\'s logical extensions before deleting it');
        }
        $syncEditItems = $this->configurationSyncEditGuard('group',$id,'',$input);
        $started = !$this->db->inTransaction();
        if ($started) {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin group deletion'); }
        } else { $this->db->exec('SAVEPOINT ucmp_group_delete'); }
        try {
            $this->db->prepare(
                'UPDATE ucmp_extensions
                    SET group_id=NULL,revision=revision+1,updated=?
                  WHERE group_id=? AND site_id=?'
            )->execute([time(), $id, $siteId]);
            $this->db->prepare(
                'UPDATE ucmp_phones
                    SET group_id=NULL,updated=' . LogicalExtensionService::phoneUpdateExpression($this->db) . '
                  WHERE group_id=? AND (site_id=? OR site_id IS NULL)'
            )->execute([time(), $id, $siteId]);
            $this->db->prepare("DELETE FROM ucmp_groups WHERE id = ?")->execute([$id]);
            if ($syncEditItems) {
                $this->pauseConfigurationSyncItems(
                    $syncEditItems,
                    $input + ['sync_edit_reason'=>'Deleted in Groups']
                );
            }
            if ($started) {
                if (!$this->db->commit()) { throw new \RuntimeException('Group deletion commit is unconfirmed; reload before retrying'); }
            } else { $this->db->exec('RELEASE SAVEPOINT ucmp_group_delete'); }
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                if ($started) { $this->db->rollBack(); }
                else { $this->db->exec('ROLLBACK TO SAVEPOINT ucmp_group_delete'); $this->db->exec('RELEASE SAVEPOINT ucmp_group_delete'); }
            }
            throw $e;
        }
    }

    public function getPhonesLocal(array $filters = []): array
    {
        $sql = "SELECT p.*, CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END AS group_id,
                       m.vendor AS m_vendor, m.model AS m_model, m.family AS m_family,
                       m.series_key AS m_series_key, m.role AS m_role, m.line_keys,
                       d.tech AS extension_driver
                FROM ucmp_phones p
                LEFT JOIN ucmp_extensions x ON x.id=p.extension_id
                LEFT JOIN ucmp_models m ON m.id = p.model_id
                LEFT JOIN devices d ON d.id = p.extension";
        $where = [];
        $args = [];
        if (!empty($filters['site_id'])) {
            if ((int)$filters['site_id'] === $this->localSiteId()) {
                $where[] = "(p.site_id = ? OR p.site_id IS NULL)";
            } else {
                $where[] = "p.site_id = ?";
            }
            $args[] = (int)$filters['site_id'];
        }
        if (!empty($filters['status'])) { $where[] = "p.status = ?"; $args[] = $filters['status']; }
        if (!empty($filters['has_mac'])) { $where[] = "(p.mac IS NOT NULL AND p.mac != '')"; }
        if ($where) { $sql .= " WHERE " . implode(' AND ', $where); }
        $sql .= " ORDER BY p.mac IS NULL, p.mac, p.extension";
        $st = $this->db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public function getPhoneLocal(int $id): ?array
    {
        $s = $this->db->prepare(
            "SELECT p.*,COALESCE(NULLIF(p.family,''),m.family) AS effective_family,
                    m.vendor AS m_vendor,m.model AS m_model,m.family AS m_family,
                    m.series_key AS m_series_key,m.role AS m_role,
                    m.sip_accounts AS m_sip_accounts,m.programmable_keys AS m_programmable_keys,
                    m.handsets_max AS m_handsets_max,
                    CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END AS group_id,
                    d.tech AS extension_driver
               FROM ucmp_phones p
               LEFT JOIN ucmp_extensions x ON x.id=p.extension_id
               LEFT JOIN ucmp_models m ON m.id=p.model_id
               LEFT JOIN devices d ON d.id=p.extension
              WHERE p.id = ?"
        );
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Manual phone configs remain identifiable after a later partial build
     * advances the active-generation pointer past their original manifest.
     *
     * @return array<string,array<string,mixed>> exact root filename => phone state
     */
    public function phoneConfigFiles(int $siteId): array
    {
        $siteId = $siteId > 0 ? $siteId : $this->localSiteId();
        return $this->isSiteLocal($siteId)
            ? $this->phoneConfigFilesLocal($siteId)
            : (array)$this->remoteQuery($siteId, 'phoneConfigFilesLocal', [0]);
    }

    public function recordPhoneConfigOverride(
        int $siteId,
        int $phoneId,
        string $filename,
        string $sha256
    ): array {
        $siteId = $siteId > 0 ? $siteId : $this->localSiteId();
        return $this->isSiteLocal($siteId)
            ? $this->recordPhoneConfigOverrideLocal($siteId, $phoneId, $filename, $sha256)
            : (array)$this->remoteQuery(
                $siteId,
                'recordPhoneConfigOverrideLocal',
                [0, $phoneId, $filename, $sha256]
            );
    }

    public function phoneConfigOverridesLocal(int $siteId): array
    {
        return array_filter(
            $this->phoneConfigFilesLocal($siteId),
            static fn(array $row): bool => (string)($row['config_mode'] ?? 'managed') === 'manual'
        );
    }

    /**
     * @return array<string,array<string,mixed>> exact root filename => phone state
     */
    public function phoneConfigFilesLocal(int $siteId): array
    {
        $siteId = $siteId > 0 ? $siteId : $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            throw new \InvalidArgumentException('Phone config override state belongs to the owning site');
        }
        $stmt = $this->db->prepare(
            "SELECT id,extension,config_filename,config_mode,
                    config_override_sha256,config_overridden_at
               FROM ucmp_phones
              WHERE (site_id=? OR site_id IS NULL)
                AND config_filename IS NOT NULL
                AND config_filename<>''"
        );
        $stmt->execute([$siteId]);
        $byFilename = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $filename = (string)$row['config_filename'];
            try {
                \FreePBX\modules\Ucmprovisioner\BuildPlanner::assertFilename($filename);
            } catch (\Throwable $e) {
                continue;
            }
            $byFilename[$filename] = $row;
        }
        return $byFilename;
    }

    /**
     * Record an explicit TFTP-browser override only when the manifest's phone
     * id and filename still match that physical endpoint's generated basename.
     */
    public function recordPhoneConfigOverrideLocal(
        int $siteId,
        int $phoneId,
        string $filename,
        string $sha256
    ): array {
        $siteId = $siteId > 0 ? $siteId : $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            throw new \InvalidArgumentException('Phone config overrides must be recorded by the owning site');
        }
        \FreePBX\modules\Ucmprovisioner\BuildPlanner::assertFilename($filename);
        $sha256 = strtolower(trim($sha256));
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $sha256)) {
            throw new \InvalidArgumentException('A phone config override requires its exact SHA-256');
        }
        $stmt = $this->db->prepare(
            "SELECT p.id,p.extension,p.mac,
                    COALESCE(NULLIF(p.family,''),m.family) AS effective_family
               FROM ucmp_phones p
               LEFT JOIN ucmp_models m ON m.id=p.model_id
              WHERE p.id=? AND (p.site_id=? OR p.site_id IS NULL)
              LIMIT 1"
        );
        $stmt->execute([$phoneId, $siteId]);
        $phone = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$phone) {
            throw new \RuntimeException('The managed phone behind this config no longer exists');
        }
        $expected = $this->configFilenameFor(
            (string)($phone['mac'] ?? ''),
            (string)($phone['effective_family'] ?? '')
        );
        if ($expected === null || !hash_equals($expected, $filename)) {
            throw new \RuntimeException('The selected file is not this phone\'s generated root config');
        }
        $now = time();
        $update = $this->db->prepare(
            "UPDATE ucmp_phones
                SET config_filename=?,config_mode='manual',
                    config_override_sha256=?,config_overridden_at=?
              WHERE id=? AND (site_id=? OR site_id IS NULL)"
        );
        $update->execute([$filename, $sha256, $now, $phoneId, $siteId]);
        return [
            'id'=>$phoneId,
            'extension'=>(string)($phone['extension'] ?? ''),
            'config_filename'=>$filename,
            'config_mode'=>'manual',
            'config_override_sha256'=>$sha256,
            'config_overridden_at'=>$now,
        ];
    }

    public function savePhoneLocal(array $d): int
    {
        return $this->withConfigurationSyncLock(fn():int=>$this->savePhoneUnderLock($d));
    }

    private function savePhoneUnderLock(array $d): int
    {
        return (int)($this->savePhoneAssignmentLocal($d)['id'] ?? 0);
    }

    /** Federated save that requires a vendor/model identity understood by the owning PBX. */
    public function savePhoneAssignmentByIdentityLocal(array $d): array
    {
        if (trim((string)($d['model_key'] ?? '')) === '') {
            throw new \InvalidArgumentException('A federated phone save requires a stable vendor/model identity');
        }
        return $this->savePhoneAssignmentLocal($d);
    }

    /** Atomically assign an extension to a physical phone; MAC identities never move. */
    public function savePhoneAssignmentLocal(array $d): array
    {
        $siteId=(int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) { throw new \InvalidArgumentException('Phone must be saved by its owning site'); }
        return $this->withConfigurationSyncLock(fn():array=>$this->withPhoneAssignmentLock($siteId,fn():array=>$this->savePhoneAssignmentLocked($d)));
    }

    private function withPhoneAssignmentLock(int $siteId, callable $operation): array
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') { return $operation(); }
        $name='ucmp-phone-assignment-'.$siteId;
        $lock=$this->db->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$name]);
        if ((int)$lock->fetchColumn()!==1) { throw new \RuntimeException('Another phone assignment is running; retry from the current owner inventory'); }
        $result=null;$failure=null;$releaseFailure=null;
        try { $result=$operation(); } catch (\Throwable $error) { $failure=$error; }
        try {
            $release=$this->db->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$name]);
            if ((int)$release->fetchColumn()!==1) { throw new \RuntimeException('Phone assignment lock release was not confirmed'); }
        } catch (\Throwable $error) { $releaseFailure=$error; }
        if ($failure!==null) { throw $failure; }
        if ($releaseFailure!==null) {
            $result['operation_ok']=!empty($result['ok']);$result['ok']=false;$result['recovery_required']=true;
            $result['lock_release_error']=$releaseFailure->getMessage();
            $result['message']=trim((string)($result['message']??'').' Assignment result retained; lock release needs reconciliation before retrying.');
        }
        return $result;
    }

    private function savePhoneAssignmentLocked(array $d): array
    {
        $siteId = (int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) { throw new \InvalidArgumentException('Phone must be saved by its owning site'); }
        $formId = (int)($d['id'] ?? 0);
        $ext = trim((string)($d['extension'] ?? ''));
        $mac = $this->normMac((string)($d['mac'] ?? ''));
        $driverMode = in_array(strtolower((string)($d['driver'] ?? 'inherit')), ['inherit','sip','pjsip'], true)
            ? strtolower((string)($d['driver'] ?? 'inherit')) : 'inherit';
        $transport = in_array(strtolower((string)($d['transport'] ?? 'inherit')), ['inherit','udp','tcp','tls'], true)
            ? strtolower((string)($d['transport'] ?? 'inherit')) : 'inherit';
        $sipPort = (int)($d['sip_port'] ?? 0);
        if ($sipPort < 0 || $sipPort > 65535) { throw new \InvalidArgumentException('Phone SIP port must be blank/inherited or between 1 and 65535'); }
        $secret = trim((string)($d['secret'] ?? ''));
        $collisionAction = in_array((string)($d['collision_action'] ?? 'swap'), ['swap', 'move'], true) ? (string)$d['collision_action'] : 'swap';
        $syncAssignmentLabel = !empty($d['sync_assignment_label']);
        if ($mac !== '' && strlen($mac) !== 12) { throw new \InvalidArgumentException('MAC address must contain exactly 12 hexadecimal characters'); }

        $knownExtensions = [];
        $knownExtensionRows = [];
        foreach ($this->getAllSipExtensionsLocal() as $candidate) {
            $candidateExtension = trim((string)($candidate['id'] ?? ''));
            if ($candidateExtension !== '') {
                $knownExtensions[$candidateExtension] = true;
                $knownExtensionRows[$candidateExtension] = $candidate;
            }
        }
        if ($ext !== '' && !isset($knownExtensions[$ext])) {
            throw new \InvalidArgumentException("Extension $ext does not exist on this PBX");
        }

        $findById = $this->db->prepare("SELECT p.*,CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END group_id,COALESCE(NULLIF(p.family,''),m.family) effective_family FROM ucmp_phones p LEFT JOIN ucmp_extensions x ON x.id=p.extension_id LEFT JOIN ucmp_models m ON m.id=p.model_id WHERE p.id=? AND (p.site_id=? OR p.site_id IS NULL) LIMIT 1");
        $formRow = null;
        if ($formId > 0) {
            $findById->execute([$formId, $siteId]);
            $formRow = $findById->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$formRow) { throw new \InvalidArgumentException('The selected phone does not belong to this site'); }
        }

        $macOwner = null;
        if ($mac !== '') {
            $stmt = $this->db->prepare("SELECT p.*,CASE WHEN x.id IS NOT NULL THEN x.group_id ELSE p.group_id END group_id,COALESCE(NULLIF(p.family,''),m.family) effective_family FROM ucmp_phones p LEFT JOIN ucmp_extensions x ON x.id=p.extension_id LEFT JOIN ucmp_models m ON m.id=p.model_id WHERE p.mac=? AND (p.site_id=? OR p.site_id IS NULL) ORDER BY p.id LIMIT 1");
            $stmt->execute([$mac, $siteId]);
            $macOwner = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $target = $macOwner ?: $formRow;
        $targetId = (int)($target['id'] ?? 0);
        $redirected = $targetId > 0 && $formId > 0 && $targetId !== $formId;

        $modelKey = trim((string)($d['model_key'] ?? ''));
        $modelId = $redirected ? $this->nullableInt($target['model_id'] ?? null) : null;
        $modelFamily = '';
        $modelRow = null;
        if (!$redirected && $modelKey !== '') {
            [$modelVendor, $modelName] = \FreePBX\modules\Ucmprovisioner\PhoneModelIdentity::parse($modelKey);
            $modelRow = \FreePBX\modules\Ucmprovisioner\PhoneModelIdentity::resolve($this->db, $modelKey);
            if (!$modelRow) {
                throw new \InvalidArgumentException(
                    "$modelVendor $modelName is not in the supported catalog on the target PBX"
                );
            }
            $modelId = (int)$modelRow['id'];
        } elseif (!$redirected) {
            // Backward-compatible local/API callers may still submit the local
            // numeric ID.  The web editor always submits model_key as well.
            $modelId = $this->nullableInt($d['model_id'] ?? ($target['model_id'] ?? null));
        }
        if ($modelId) {
            if (!$modelRow) {
                $modelStmt = $this->db->prepare('SELECT * FROM ucmp_models WHERE id=? LIMIT 1');
                $modelStmt->execute([$modelId]);
                $modelRow = $modelStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$modelRow) { throw new \InvalidArgumentException('The selected model is not in the supported catalog'); }
            $modelRole = strtolower((string)($modelRow['role'] ?? 'desk'));
            if (in_array($modelRole, ['dect_handset','dect_radio','dect_manager'], true)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s %s is a %s component. Assign SIP accounts to its DECT base/system and record the handset there instead of creating a standalone phone.',
                    (string)($modelRow['vendor'] ?? ''),
                    (string)($modelRow['model'] ?? ''),
                    str_replace('_', ' ', $modelRole)
                ));
            }
            $modelFamily = (string)($modelRow['family'] ?? '');
        }
        $familyForLimit = $modelFamily ?: (string)($target['effective_family'] ?? '');
        $endpointPhone = [
            'vendor'=>$redirected ? ($target['vendor'] ?? '') : ($d['vendor'] ?? ($target['vendor'] ?? '')),
            'family'=>$familyForLimit,
            'driver'=>$redirected ? ($target['driver'] ?? 'inherit') : $driverMode,
            'transport'=>$redirected ? ($target['transport'] ?? 'inherit') : $transport,
            'sip_port'=>$redirected ? (int)($target['sip_port'] ?? 0) : $sipPort,
        ];
        $endpoint = \FreePBX\modules\Ucmprovisioner\EndpointProfile::resolve(
            $endpointPhone,
            $modelRow ?: ['vendor'=>$endpointPhone['vendor'],'family'=>$endpointPhone['family']],
            $this->getSiteSettingsLocal($siteId)
        );
        if ($ext !== '') {
            $actualDriver = strtolower((string)($knownExtensionRows[$ext]['driver'] ?? ''));
            if ($actualDriver !== '' && $actualDriver !== $endpoint['driver']) {
                throw new \InvalidArgumentException(sprintf(
                    'Extension %s is %s in FreePBX, while this phone resolves to %s. Change the extension driver in FreePBX or select a matching Driver override.',
                    $ext,
                    \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($actualDriver),
                    \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($endpoint['driver'])
                ));
            }
        }
        $normalizedLines = $redirected
            ? null
            : \FreePBX\modules\Ucmprovisioner\PhoneAccounts::normalize(
                $d['lines_json'] ?? ($target['lines_json'] ?? null),
                $modelRow,
                $knownExtensions,
                $ext
            );
        foreach ((array)$normalizedLines as $line) {
            $lineExtension = trim((string)($line['extension'] ?? ''));
            if ($lineExtension === '') { continue; }
            $lineDriver = strtolower((string)($knownExtensionRows[$lineExtension]['driver'] ?? ''));
            if ($lineDriver !== '' && $lineDriver !== $endpoint['driver']) {
                throw new \InvalidArgumentException(sprintf(
                    'Additional account %s is %s in FreePBX; every account on this phone must use its resolved %s driver.',
                    $lineExtension,
                    \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($lineDriver),
                    \FreePBX\modules\Ucmprovisioner\EndpointProfile::driverLabel($endpoint['driver'])
                ));
            }
        }
        $normalizedOverrides = null;
        if (!$redirected) {
            $handsetCatalog = [];
            foreach ($this->db->query("SELECT vendor,model FROM ucmp_models WHERE role='dect_handset'")->fetchAll(PDO::FETCH_ASSOC) as $handsetModel) {
                $handsetCatalog[strtoupper((string)$handsetModel['vendor']) . '|' . strtoupper((string)$handsetModel['model'])] = true;
            }
            $activeAccounts = $ext !== '' ? [1] : [];
            foreach ((array)$normalizedLines as $line) { $activeAccounts[] = (int)($line['account'] ?? 0); }
            $normalizedOverrides = \FreePBX\modules\Ucmprovisioner\PhoneAccounts::normalizeDectOverrides(
                $d['overrides'] ?? ($target['overrides'] ?? null),
                $modelRow,
                $handsetCatalog,
                $activeAccounts
            );
        }

        $base = $target ?: [];
        $row = [
            'site_id' => $siteId,
            'group_id' => $redirected ? ($base['group_id'] ?? null) : $this->nullableInt($d['group_id'] ?? ($base['group_id'] ?? null)),
            'mac' => $redirected ? ($base['mac'] ?? null) : ($mac ?: null),
            'vendor' => $redirected ? ($base['vendor'] ?? null) : ($d['vendor'] ?? ($base['vendor'] ?? null)),
            'model_id' => $modelId,
            'family' => $redirected ? ($base['family'] ?? null) : ($modelFamily ?: ($base['family'] ?? null)),
            'ip' => $redirected ? ($base['ip'] ?? null) : ($d['ip'] ?? ($base['ip'] ?? null)),
            'extension' => null,
            'driver' => $redirected ? ($base['driver'] ?? 'inherit') : ($endpoint['is_cisco'] ? 'inherit' : $driverMode),
            'transport' => $redirected ? ($base['transport'] ?? 'inherit') : ($endpoint['is_cisco_cnf'] ? 'inherit' : $transport),
            'sip_port' => $redirected ? (int)($base['sip_port'] ?? 0) : $sipPort,
            'label' => $redirected ? ($base['label'] ?? null) : ($d['label'] ?? ($base['label'] ?? null)),
            'overrides' => $redirected ? ($base['overrides'] ?? null) : ($normalizedOverrides ? json_encode($normalizedOverrides, JSON_UNESCAPED_SLASHES) : null),
            'lines_json' => $redirected ? ($base['lines_json'] ?? null) : ($normalizedLines ? json_encode($normalizedLines, JSON_UNESCAPED_SLASHES) : null),
            'exclude_bulk_rebuild' => $redirected
                ? (int)($base['exclude_bulk_rebuild'] ?? 0)
                : (!empty($d['exclude_bulk_rebuild']) ? 1 : 0),
            'status' => $base['status'] ?? 'unassigned',
            'prev_extension' => $base['prev_extension'] ?? null,
            'updated' => LogicalExtensionService::nextPhoneUpdate($base),
        ];
        $targetPreviousExtension = trim((string)($target['extension'] ?? ''));
        $targetLabelSynced = false;
        if ($syncAssignmentLabel && $ext !== '' && $targetPreviousExtension !== $ext) {
            $fallback = \FreePBX\modules\Ucmprovisioner\PhoneAssignment::fallbackLabel(
                $knownExtensionRows[$ext] ?? ['id'=>$ext]
            );
            $row['label'] = $fallback['label'];
            $targetLabelSynced = true;
        }

        $begun = false;
        $coreResult = null;
        $commitAttempted = false;
        try {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Could not begin the phone assignment transaction'); }
            $begun = true;
            if (array_key_exists('expected_identity_revisions',$d)) {
                $expected=(array)$d['expected_identity_revisions'];
                if (!$expected || count($expected)>2) { throw new \InvalidArgumentException('Reviewed replacement requires one or two logical-extension revisions'); }
                ksort($expected,SORT_STRING);
                $identityLock=$this->db->prepare('SELECT revision FROM ucmp_extensions WHERE site_id=? AND extension=?'
                    .($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
                foreach ($expected as $number=>$revision) {
                    if (!preg_match('/^[0-9*#+]{1,20}$/D',(string)$number) || (int)$revision<0) { throw new \InvalidArgumentException('Reviewed logical identity is invalid'); }
                    $identityLock->execute([$siteId,(string)$number]);$actual=$identityLock->fetchColumn();
                    if (($actual===false?0:(int)$actual)!==(int)$revision) { throw new \DomainException('The reviewed person or extension policy changed; preview replacement again'); }
                }
            }
            if (array_key_exists('expected_target_hash',$d)) {
                if ($targetId<1 || $formId!==$targetId || $mac===''
                    || !preg_match('/^[a-f0-9]{64}$/D',(string)$d['expected_target_hash'])
                    || !array_key_exists('expected_other_id',$d) || !array_key_exists('expected_other_hash',$d)) {
                    throw new \InvalidArgumentException('Reviewed replacement requires explicit existing physical target and collision expectations');
                }
                $lockRows=$this->db->prepare('SELECT id FROM ucmp_phones WHERE (site_id=? OR site_id IS NULL) AND (id=? OR extension=? OR mac=?) ORDER BY id'
                    .($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
                $lockRows->execute([$siteId,$targetId,$ext,$mac]);$lockRows->fetchAll(PDO::FETCH_COLUMN);
                $currentTarget=$this->getPhoneLocal($targetId);
                if (!$currentTarget || !hash_equals((string)$d['expected_target_hash'],\FreePBX\modules\Ucmprovisioner\IdentityDeliveryService::hash($currentTarget))) {
                    throw new \DomainException('The reviewed target phone changed; preview replacement again');
                }
                $collision=$this->db->prepare("SELECT id FROM ucmp_phones WHERE extension=? AND id<>? AND (site_id=? OR site_id IS NULL) ORDER BY (mac IS NOT NULL AND mac<>'') DESC,id");
                $collision->execute([$ext,$targetId,$siteId]);$collisionIds=$ext!==''?$collision->fetchAll(PDO::FETCH_COLUMN):[];
                $actualOtherId=(int)($collisionIds[0] ?? 0);
                if (count($collisionIds)>1 || $actualOtherId!==(int)$d['expected_other_id']) {
                    throw new \DomainException('The reviewed extension collision appeared, disappeared or changed; preview again');
                }
                $currentOther=$actualOtherId>0?$this->getPhoneLocal($actualOtherId):null;
                $actualOtherHash=$currentOther?\FreePBX\modules\Ucmprovisioner\IdentityDeliveryService::hash($currentOther):'';
                if (!hash_equals((string)$d['expected_other_hash'],$actualOtherHash)) {
                    throw new \DomainException('The reviewed displaced phone changed; preview replacement again');
                }
            }
            if ($mac !== '') {
                // An explicit add/edit is the operator's decision to manage this
                // physical phone again; clear any prior Delete Phone tombstone.
                $this->db->prepare(
                    'DELETE FROM ucmp_phone_mac_suppressions WHERE site_id=? AND mac=?'
                )->execute([$siteId, $mac]);
            }
            if ($targetId === 0) {
                $targetId = $this->upsert('ucmp_phones', $row, 0);
                $findById->execute([$targetId, $siteId]);
                $target = $findById->fetch(PDO::FETCH_ASSOC) ?: $row + ['id'=>$targetId];
            }

            $other = null;
            if ($ext !== '') {
                $stmt = $this->db->prepare("SELECT * FROM ucmp_phones WHERE extension=? AND id<>? AND (site_id=? OR site_id IS NULL) ORDER BY (mac IS NOT NULL AND mac<>'') DESC,id LIMIT 1");
                $stmt->execute([$ext, $targetId, $siteId]);
                $other = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($other && empty($target['mac']) && empty($target['model_id'])) {
                throw new \InvalidArgumentException('Select a physical phone or model before taking an extension from another phone');
            }

            $plan = \FreePBX\modules\Ucmprovisioner\PhoneAssignment::plan((array)$target, $other, $ext, $collisionAction);
            $logicalExtensions = new \FreePBX\modules\Ucmprovisioner\LogicalExtensionService($this->db);
            if ((string)($target['extension'] ?? '') !== $ext) {
                $this->db->prepare('UPDATE ucmp_phones SET extension=NULL WHERE id=?')->execute([$targetId]);
            }
            $affected = [];
            $unassigned = [];
            $otherLabelSynced = false;
            if ($other) {
                if ($syncAssignmentLabel && $plan['other_extension'] !== null) {
                    $fallback = \FreePBX\modules\Ucmprovisioner\PhoneAssignment::fallbackLabel(
                        $knownExtensionRows[(string)$plan['other_extension']] ?? ['id'=>(string)$plan['other_extension']]
                    );
                    $this->db->prepare('UPDATE ucmp_phones SET extension=?,prev_extension=?,status=?,label=?,updated=? WHERE id=?')->execute([
                        $plan['other_extension'], $plan['other_prev_extension'], $plan['other_status'],
                        $fallback['label'], LogicalExtensionService::nextPhoneUpdate($other), (int)$other['id']
                    ]);
                    $otherLabelSynced = true;
                } else {
                    $this->db->prepare('UPDATE ucmp_phones SET extension=?,prev_extension=?,status=?,updated=? WHERE id=?')->execute([
                        $plan['other_extension'], $plan['other_prev_extension'], $plan['other_status'], LogicalExtensionService::nextPhoneUpdate($other), (int)$other['id']
                    ]);
                }
                $logicalExtensions->linkPhone(
                    (int)$other['id'],
                    $siteId,
                    $plan['other_extension'] !== null ? (string)$plan['other_extension'] : null
                );
                if (!empty($other['mac']) && $plan['other_extension']) { $affected[] = (int)$other['id']; }
                if (!empty($other['mac']) && !$plan['other_extension']) { $unassigned[] = (int)$other['id']; }
            }
            $row['extension'] = $plan['target_extension'];
            $row['prev_extension'] = $plan['target_prev_extension'];
            $row['status'] = $plan['target_status'];
            $this->upsert('ucmp_phones', $row, $targetId);
            $logicalExtensions->linkPhone(
                $targetId,
                $siteId,
                $plan['target_extension'] !== null ? (string)$plan['target_extension'] : null,
                !empty($d['group_changed']),
                $this->nullableInt($d['group_id'] ?? null)
            );
            // Keep the external FreePBX Core mutation at the end of the module
            // transaction. All assignment, ownership and logical-link checks
            // have succeeded before Core performs its delete/re-add edit.
            if ($ext !== '' && ($secret !== '' || !empty($d['apply_transport']))) {
                $eng = $this->engine('ExtensionBinder');
                if (!$eng) { throw new \Exception('ExtensionBinder engine not available'); }
                $maxSecret = $familyForLimit === 'cisco_cnf' ? 16 : ($familyForLimit === 'cisco_xml' ? 30 : 256);
                $limitLabel = $familyForLimit === 'cisco_cnf' ? 'Cisco 7940/7960 SIP CNF firmware' : ($familyForLimit === 'cisco_xml' ? 'Cisco XML SIP firmware' : 'selected phone');
                $res = $eng->updateSecretTransport(
                    $ext,
                    $secret !== '' ? $secret : null,
                    (string)$endpoint['transport'],
                    $maxSecret,
                    $limitLabel,
                    (string)$endpoint['driver'],
                    (int)$endpoint['port']
                );
                $coreResult = $res;
                if (empty($res['ok'])) { throw new \Exception($res['message']); }
            }
            if (!empty($row['mac']) && $row['extension']) { $affected[] = $targetId; }
            if (!empty($row['mac']) && !$row['extension']) { $unassigned[] = $targetId; }
            $commitAttempted = true;
            if (!$this->db->commit()) { throw new \RuntimeException('Phone assignment database commit was not confirmed'); }
            $begun = false;

            $affected = array_values(array_unique(array_map('intval', $affected)));
            $message = $other
                ? ($collisionAction === 'swap' && !empty($plan['other_extension'])
                    ? "Extensions {$target['extension']} and $ext were swapped between the two phones"
                    : "Extension $ext was moved; the other phone is now unassigned")
                : ($ext !== '' ? "Extension $ext assigned to the selected phone" : 'Phone left unassigned');
            if ($targetLabelSynced || $otherLabelSynced) {
                $message .= '; base fallback labels followed the assigned extensions';
            }
            return [
                'ok'=>true,
                'id'=>$targetId,
                'affected_ids'=>$affected,
                'unassigned_ids'=>array_values(array_unique($unassigned)),
                'label_sync'=>['target'=>$targetLabelSynced,'other'=>$otherLabelSynced],
                'message'=>$message,
            ];
        } catch (\Throwable $e) {
            if ($begun) {
                try { $this->db->rollBack(); }
                catch (\Throwable $ignored) {
                    // Preserve the operation failure; the database connection owns
                    // recovery if its rollback command also became unavailable.
                }
            }
            if ($commitAttempted) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Phone assignment commit is unconfirmed; reconcile the saved binding before retrying: '.$e->getMessage(),
                    ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'id'=>$targetId,
                     'affected_ids'=>array_values(array_unique($affected)),'core'=>$coreResult]);
            }
            if ($coreResult !== null && (!empty($coreResult['ok']) || ($coreResult['status'] ?? '') === 'recovery_required')) {
                throw new \RuntimeException('Phone assignment failed after a Core edit; reconcile module and Core state before retrying. Retained Core recovery: '
                    . (string)($coreResult['recovery_id'] ?? 'unavailable') . '. ' . $e->getMessage(), 0, $e);
            }
            throw $e;
        }
    }

    /** Read the live FreePBX SIP/PJSIP device on the PBX that owns the selected site. */
    public function getBoundDevice(string $ext, ?int $siteId = null): ?array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            try {
                $result = $this->remoteQuery($siteId, 'getBoundDeviceLocal', [$ext]);
                // Core returns the device row itself, without an `ok` envelope.
                return is_array($result) && (!array_key_exists('ok', $result) || !empty($result['ok'])) ? $result : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        return $this->getBoundDeviceLocal($ext);
    }
    public function getBoundDeviceLocal(string $ext): ?array
    {
        $eng = $this->engine('ExtensionBinder');
        return $eng ? $eng->getDevice($ext) : null;
    }

    /** Known MACs across the fleet, with context, for the phone edit form's MAC datalist. */
    public function getKnownMacs(?int $siteId = null): array
    {
        $out = [];
        $siteId = $siteId ?: $this->localSiteId();
        foreach ($this->getPhones(['site_id' => $siteId]) as $p) {
            if (empty($p['mac'])) {
                continue;
            }
            $model = trim(($p['m_vendor'] ?? '') . ' ' . ($p['m_model'] ?? ''));
            if (!empty($p['extension'])) {
                $ctx = $p['extension'] . ' — ' . ($p['label'] ?: $model ?: 'bound');
            } else {
                $ctx = 'UNASSIGNED' . ($p['label'] ? ' — ' . $p['label'] : '') . ($model ? " ($model)" : '');
            }
            $out[] = ['mac' => $p['mac'], 'context' => trim($ctx)];
        }
        return $out;
    }

    /**
     * SIP/PJSIP extensions available to bind a phone to — for the "bind"/"reassign" dropdown.
     *
     * NOTE: this is NOT "no ucmp_phones row exists". Discovery creates a bare placeholder row
     * for every SIP/PJSIP extension (even offline/never-configured ones) so it can track live
     * registration data. "Available" means no REAL phone is configured on it yet: no MAC and no
     * model chosen. Without this distinction, every extension in FreePBX would look "already
     * bound" after the first Discover and nothing would ever appear as reassignable.
     */
    /** All SIP/PJSIP extensions with their FreePBX description and driver. */
    public function getAllSipExtensions(?int $siteId = null): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            try {
                $result = $this->remoteQuery($siteId, 'getAllSipExtensionsLocal', []);
                if (is_array($result) && !isset($result['ok'])) {
                    return $result;
                }
            } catch (\Throwable $e) {
                // Older remote module: its discovered placeholders are still a useful catalog.
            }
            $out = [];
            foreach ($this->getPhones(['site_id' => $siteId]) as $p) {
                if ((string)($p['extension'] ?? '') !== '') {
                    $out[] = [
                        'id'=>(string)$p['extension'],
                        'description'=>(string)($p['label'] ?? ''),
                        'driver'=>(string)($p['extension_driver'] ?? $p['driver'] ?? ''),
                    ];
                }
            }
            return $out;
        }
        return $this->getAllSipExtensionsLocal();
    }
    public function getAllSipExtensionsLocal(): array
    {
        $eng = $this->engine('ExtensionBinder');
        $rows = $eng ? $eng->listEndpointExtensions() : [];
        $groups = [];
        $identityPreviews = [];
        $siteId = $this->localSiteId();
        $stmt = $this->db->prepare(
            'SELECT *
               FROM ucmp_extensions
              WHERE site_id=?'
        );
        $stmt->execute([$siteId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $logical) {
            $groups[(string)$logical['extension']] = [
                'extension_id'=>(int)$logical['id'],
                'group_id'=>$this->nullableInt($logical['group_id'] ?? null),
                'binding'=>\FreePBX\modules\Ucmprovisioner\LogicalExtensionService::identityBinding($logical),
                'revision'=>(int)($logical['revision'] ?? 1),
                'apply_status'=>(string)($logical['apply_status'] ?? 'unknown'),
            ];
        }
        $directory = new \FreePBX\modules\Ucmprovisioner\DirectoryService($this);
        foreach ($rows as &$row) {
            $extension = (string)($row['id'] ?? '');
            $logicalRow = $groups[$extension] ?? [];
            $row['extension_id'] = $logicalRow['extension_id'] ?? null;
            $row['group_id'] = $logicalRow['group_id'] ?? null;
            $row['identity_binding'] = $logicalRow['binding'] ?? null;
            $row['identity_revision'] = $logicalRow['revision'] ?? null;
            $row['identity_apply_status'] = $logicalRow['apply_status'] ?? 'unbound';
            $row['observed_freepbx_name'] = (string)($row['description'] ?? '');
            // The stable binding resolver also covers linked additional and manual
            // identities, and refuses a source row from an unaccepted revision.
            try {
                $bound = $directory->resolveExtensionIdentity($siteId, $extension);
                if ($bound !== null) {
                    $row['identity_resolved'] = !empty($bound['resolved']);
                    $row['identity_binding'] = $bound['binding'] ?? $row['identity_binding'];
                    $row['identity_accepted_at'] = (int)($bound['accepted_at'] ?? 0);
                    $row['identity_warnings'] = $bound['warnings'] ?? [];
                    if (!empty($bound['resolved'])) {
                        $canonical = array_intersect_key($bound['canonical'], array_flip(['display_name','given_name','initial','surname','label','extension','department']));
                        $canonical['site'] = (string)(($this->getSite($siteId) ?: [])['name'] ?? '');
                        $finalOverrides = [];
                        foreach (\FreePBX\modules\Ucmprovisioner\LogicalExtensionService::FINAL_FIELDS as $field) {
                            if (($bound['trace']['outputs'][$field]['source'] ?? '') === 'override') {
                                $finalOverrides[$field] = (string)$bound['outputs'][$field];
                            }
                        }
                        $identityPreviews[$extension] = ['identity'=>$canonical,
                            'identity_source'=>($bound['binding']['mode'] ?? '') === 'manual_shared' ? 'manual' : 'ad',
                            'final_overrides'=>$finalOverrides, 'effective_label'=>$bound['outputs']['phone_label']];
                    }
                }
            } catch (\Throwable $e) {
                $row['identity_resolved'] = false;
                $row['identity_warnings'] = ['Identity data unavailable; observed FreePBX name retained'];
            }
            if (isset($identityPreviews[$extension])) {
                $row += $identityPreviews[$extension];
            } else {
                // Template label patterns such as {extension} are valid even
                // before an extension is linked to AD. Expose the same
                // FreePBX/legacy fallback used by the build engine so the
                // preview selector never hides a provisionable extension.
                $description = trim((string)($row['description'] ?? ''));
                $fallbackLabel = $description !== '' ? $description : $extension;
                $fallback = [
                    'display_name'=>$fallbackLabel,
                    'given_name'=>'', 'initial'=>'', 'surname'=>'',
                    'label'=>$fallbackLabel, 'extension'=>$extension,
                    'department'=>'', 'company'=>'', 'org_unit'=>'',
                    'domain'=>'', 'mail'=>'', 'mobile'=>'', 'pager'=>'',
                ];
                $overrides = [];
                if (!empty($logicalRow['extension_id'])) {
                    try {
                        $overrides = (new \FreePBX\modules\Ucmprovisioner\LogicalExtensionService($this->db))
                            ->overridesForExtension((int)$logicalRow['extension_id']);
                    } catch (\Throwable $ignored) {
                        // Identity selection must still list the FreePBX endpoint on
                        // an older/partially upgraded remote site; generation performs
                        // its own authoritative override lookup before writing files.
                    }
                }
                $resolved = \FreePBX\modules\Ucmprovisioner\LogicalExtensionService::resolveProvisioningIdentity(
                    $fallback,
                    $description !== '' ? 'freepbx' : 'legacy',
                    $overrides,
                    static fn(array $canonical): array =>
                        \FreePBX\modules\Ucmprovisioner\IdentityLabelFormatter::outputs($canonical)
                );
                $canonical = array_intersect_key(
                    $resolved['canonical'],
                    array_flip(['display_name','given_name','initial','surname','label','extension','department'])
                );
                $site = $this->getSite($siteId) ?: [];
                $canonical['site'] = (string)($site['name'] ?? '');
                $finalOverrides = [];
                foreach (\FreePBX\modules\Ucmprovisioner\LogicalExtensionService::FINAL_FIELDS as $field) {
                    if (($resolved['trace']['outputs'][$field]['source'] ?? '') === 'override') {
                        $finalOverrides[$field] = (string)$resolved['outputs'][$field];
                    }
                }
                $row['identity'] = $canonical;
                $row['identity_source'] = (string)$resolved['base_source'];
                $row['final_overrides'] = $finalOverrides;
            }
        }
        unset($row);
        return $rows;
    }
    public function getUnboundExtensions(?int $siteId = null): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        $configured = [];
        foreach ($this->getPhones(['site_id' => $siteId]) as $p) {
            if (!empty($p['mac']) || !empty($p['model_id'])) {
                $configured[] = $p['extension'];
            }
        }
        $out = [];
        foreach ($this->getAllSipExtensions($siteId) as $e) {
            if (!in_array($e['id'], $configured, true)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Apply AD-owned display names and phone labels on the PBX executing this method. */
    public function applyDirectoryIdentitiesLocal(array $identities): array
    {
        return (new \FreePBX\modules\Ucmprovisioner\IdentityDeliveryService($this))->applyLocal($identities);
    }

    public function applyDirectoryIdentities(int $siteId, array $identities): array
    {
        $owner = $this->getSite($siteId);
        if (!$owner || empty($owner['enabled'])) { throw new \DomainException('The identity owner site is unavailable or disabled'); }
        $review = \FreePBX\modules\Ucmprovisioner\IdentityDeliveryService::planSites($this,$identities);
        $results = []; $sites = []; $warnings = [];
        foreach ($identities as $identity) {
            $key=$identity['identity_site_key'].':'.$identity['extension'];
            if (isset($review['skipped'][$key])) { $results[$identity['extension']]=$review['skipped'][$key]; }
        }
        foreach ($review['destinations'] as $target) {
            $targetId = $target['site_id'];
            if (!$review['packets']) { $sites[$targetId]=['ok'=>true,'skipped'=>true,'message'=>'No eligible identity targets; no delivery performed'];continue; }
            try {
                // Only the new capability accepts foreign-owner BLF projections.
                // Legacy applyDirectoryIdentitiesLocal must never receive those.
                $delivery = $this->operatorWorkflowAtSite($targetId, ['op'=>'delivery_execute','plan_hash'=>$target['plan_hash']]+$target['input']);
                if (empty($delivery['ok']) || (int)($delivery['identity_delivery_schema'] ?? 0) !== 1) {
                    throw new \RuntimeException((string)($delivery['message'] ?? 'Identity delivery remains unresolved'));
                }
                $sites[$targetId] = array_intersect_key($delivery, array_flip(['ok','plan_hash','transaction_id','phone_targets','excluded','unresolved','stages','message']));
                if ($targetId === $siteId) { $results += (array)($delivery['results'] ?? []); }
            } catch (\Throwable $error) {
                $warnings[] = 'Site #' . $targetId . ': ' . $error->getMessage();
                $sites[$targetId] = ['ok'=>false,'message'=>$error->getMessage()];
            }
        }
        foreach ($identities as $identity) {
            $extension = (string)$identity['extension'];
            $result = $results[$extension] ?? ['ok'=>false,'message'=>'No owner result returned'];
            if ($warnings && empty($result['skipped'])) { $result['ok']=false; $result['message']=implode('; ', $warnings); }
            $results[$extension] = $result;
        }
        return ['ok'=>!$warnings && !array_filter($results, static fn(array $r): bool => empty($r['ok']) && empty($r['skipped'])),
            'identity_delivery_schema'=>1,'results'=>$results,'sites'=>$sites,'warnings'=>$warnings,'skipped'=>$review['skipped'],
            'message'=>$warnings ? 'Identity delivery is incomplete across the fixed enabled-site set' : 'Eligible identities reconciled; '.count($review['skipped']).' extension(s) skipped'];
    }
    /** Remove a now-unassigned phone's old per-MAC TFTP config without deleting inventory. */
    public function clearPhoneConfigLocal(int $id): array
    {
        $stmt = $this->db->prepare("SELECT p.mac,p.config_filename,COALESCE(NULLIF(p.family,''),m.family) family,p.site_id FROM ucmp_phones p LEFT JOIN ucmp_models m ON m.id=p.model_id WHERE p.id=? LIMIT 1");
        $stmt->execute([$id]);
        $phone = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$phone) { return ['ok'=>false,'message'=>'Phone not found']; }
        $filenames = $this->configFilenamesForPhone((string)$phone['mac'], (string)$phone['family'], (string)($phone['config_filename'] ?? ''));
        if (!$filenames) { return ['ok'=>false,'message'=>'The phone model/family is unknown, so its TFTP filename cannot be determined safely']; }
        $store = $this->engine('TftpStore');
        if (!$store) { return ['ok'=>false,'message'=>'TFTP store is unavailable']; }
        foreach ($filenames as $filename) {
            $result = $store->deletePhoneConfig((int)($phone['site_id'] ?: $this->localSiteId()), $filename);
            if (empty($result['ok'])) { return $result; }
        }
        return ['ok'=>true,'filenames'=>$filenames,'message'=>'Phone config files removed'];
    }

    /** Delete a phone binding only after its generated per-MAC config is gone. */
    public function deletePhoneLocal(int $id, ?string $expectedBinding = null): array
    {
        $siteId=$this->localSiteId();
        $store=$this->engine('TftpStore');
        if (!$store) { return ['ok'=>false,'message'=>'TFTP store is unavailable; the phone binding was preserved','tftp'=>null]; }
        return $this->withConfigurationSyncLock(fn():array=>$this->withPhoneAssignmentLock($siteId,fn():array=>$store->withSiteLock($siteId,function() use($id,$siteId,$expectedBinding):array {
            $commitAttempted=false;$result=null;
            try {
                if (!$this->db->beginTransaction()) { throw new \RuntimeException('Could not begin phone deletion transaction'); }
                $row=$this->db->prepare('SELECT * FROM ucmp_phones WHERE id=? AND (site_id=? OR site_id IS NULL)'
                    .($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE'));
                $row->execute([$id,$siteId]);
                $phone=$row->fetch(PDO::FETCH_ASSOC);
                if (!$phone) { $this->db->rollBack();return ['ok'=>false,'message'=>'Phone does not belong to the local site','tftp'=>null]; }
                if ($expectedBinding!==null&&!hash_equals(self::phoneBindingRevision($phone),$expectedBinding)) {
                    $this->db->rollBack();return ['ok'=>false,'conflict'=>true,'message'=>'Phone binding changed since the page was loaded; reload before deleting','tftp'=>null];
                }
                $result=$this->deletePhoneBindingLocked($id);
                if (empty($result['ok'])) { $this->db->rollBack();return $result; }
                $commitAttempted=true;
                if (!$this->db->commit()) { throw new \RuntimeException('Phone deletion commit was not confirmed'); }
                return $result;
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                return ['ok'=>false,'status'=>$commitAttempted?'unknown':'partial','recovery_required'=>$result!==null,
                    'phone_id'=>$id,'tftp'=>$result['tftp']??null,'binding_retired'=>$commitAttempted?null:false,
                    'message'=>'Phone deletion needs reconciliation: '.$error->getMessage()];
            }
        })));
    }

    private function deletePhoneBindingLocked(int $id): array
    {
        $phone = $this->getPhoneLocal($id);
        if (!$phone) { return ['ok'=>false, 'message'=>'Phone not found', 'tftp'=>null]; }
        $siteId = (int)($phone['site_id'] ?: $this->localSiteId());
        $mac = $this->normMac((string)($phone['mac'] ?? ''));
        $tftpResult = null;
        if ($mac !== '') {
            $family = (string)($phone['effective_family'] ?? $phone['family'] ?? '');
            $filenames = $this->configFilenamesForPhone(
                $mac,
                $family,
                (string)($phone['config_filename'] ?? '')
            );
            $store = $this->engine('TftpStore');
            if (!$store) {
                return ['ok'=>false, 'message'=>'TFTP store is unavailable; the phone binding was preserved', 'tftp'=>null];
            }
            $tftpResults = [];
            foreach ($filenames as $filename) {
                $tftpResult = $store->deletePhoneConfig($siteId, $filename);
                $tftpResults[] = $tftpResult;
                if (empty($tftpResult['ok'])) {
                    return [
                        'ok'=>false,
                        'status'=>'partial','recovery_required'=>true,'phone_id'=>$id,
                        'message'=>'Phone config cleanup failed; the phone binding was preserved: '
                            . (string)($tftpResult['message'] ?? 'unknown TFTP error'),
                        'tftp'=>$tftpResults,
                    ];
                }
            }
            $tftpResult = $tftpResults;
        }
        try {
            if ($mac !== '') {
                $this->db->prepare(
                    'DELETE FROM ucmp_phone_mac_suppressions WHERE site_id=? AND mac=?'
                )->execute([$siteId, $mac]);
                $this->db->prepare(
                    'INSERT INTO ucmp_phone_mac_suppressions (site_id,mac,extension,created) VALUES (?,?,?,?)'
                )->execute([$siteId, $mac, (string)($phone['extension'] ?? ''), time()]);
            }
            $this->db->prepare('DELETE FROM ucmp_phone_builds WHERE phone_id=?')->execute([$id]);
            // ucmp_phones also carries the extension-only rows displayed by
            // "Show unbound extensions". Retire the physical binding in place
            // so the FreePBX extension is immediately available for another
            // phone without waiting for a discovery pass. Its logical
            // extension_id remains linked, preserving extension-owned group
            // policy while every device-owned value is removed.
            $this->db->prepare(
                "UPDATE ucmp_phones
                    SET group_id=NULL,
                        mac=NULL,vendor=NULL,model_id=NULL,family=NULL,ip=NULL,
                        prev_extension=NULL,driver='inherit',transport='inherit',sip_port=0,
                        label=NULL,overrides=NULL,lines_json=NULL,status='discovered',
                        last_seen=0,last_command=NULL,last_command_at=0,last_applied=0,
                        applied_driver=NULL,applied_transport=NULL,applied_sip_port=0,
                        config_filename=NULL,config_mode='managed',
                        config_override_sha256=NULL,config_overridden_at=0,
                        exclude_bulk_rebuild=0,updated=?
                  WHERE id=?"
            )->execute([time(), $id]);
        } catch (\Throwable $e) {
            return [
                'ok'=>false,
                'status'=>'partial','recovery_required'=>true,'phone_id'=>$id,
                'message'=>'TFTP cleanup completed, but the phone binding could not be retired: ' . $e->getMessage(),
                'tftp'=>$tftpResult,
            ];
        }
        return [
            'ok'=>true,
            'message'=>'Physical phone binding deleted; its FreePBX extension is now unprovisioned.',
            'tftp'=>$tftpResult,
        ];
    }

    /** Validate the complete selection before deleting any local phone binding. */
    public function deletePhonesLocal(array $phoneIds, ?array $expectedBindings = null): array
    {
        $phoneIds = array_values(array_unique(array_filter(array_map('intval', $phoneIds), static fn(int $id): bool => $id > 0)));
        if (!$phoneIds) { return ['ok'=>false, 'message'=>'Select at least one phone', 'deleted'=>[]]; }
        if (count($phoneIds) > 200) { return ['ok'=>false, 'message'=>'A delete batch is limited to 200 phones', 'deleted'=>[]]; }

        $siteId = $this->localSiteId();
        foreach ($phoneIds as $phoneId) {
            $phone = $this->getPhoneLocal($phoneId);
            if (!$phone || (int)($phone['site_id'] ?: $siteId) !== $siteId) {
                return ['ok'=>false, 'message'=>"Phone #$phoneId does not belong to the selected site", 'deleted'=>[]];
            }
        }

        $deleted = [];
        $failed = [];
        foreach ($phoneIds as $phoneId) {
            try {
                $result = $this->deletePhoneLocal($phoneId,$expectedBindings===null?null:(string)($expectedBindings[$phoneId]??''));
                if (!empty($result['ok'])) {
                    $deleted[] = $phoneId;
                } else {
                    $failed[] = ['id'=>$phoneId, 'message'=>(string)($result['message'] ?? 'Delete failed'),'result'=>$result];
                }
            } catch (\Throwable $e) {
                $failed[] = ['id'=>$phoneId, 'message'=>$e->getMessage()];
            }
        }

        $message = 'Deleted ' . count($deleted) . ' physical phone(s). Their FreePBX extensions remain available as unprovisioned.';
        if ($failed) { $message .= ' ' . count($failed) . ' deletion(s) need attention; inspect their cleanup and binding results before retrying.'; }
        return ['ok'=>!$failed, 'message'=>$message, 'deleted'=>$deleted, 'failed'=>$failed, 'warnings'=>[]];
    }

    private function configFilenameFor(string $mac, string $family): ?string
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        if (strlen($mac) !== 12) {
            return null;
        }
        switch ($family) {
            case 'cisco_xml': return "SEP{$mac}.cnf.xml";
            case 'cisco_cnf': return "SIP{$mac}.cnf";
            case 'yealink':   return strtolower($mac) . '.cfg';
            case 'grandstream': return 'cfg' . strtolower($mac) . '.xml';
            default: return null;
        }
    }

    /** Every supported per-phone filename derived from this physical MAC. */
    private function configFilenamesForPhone(string $mac, string $family, string $recorded): array
    {
        $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
        if (strlen($mac) !== 12) { return []; }
        return [
            "SEP{$mac}.cnf.xml",
            "SIP{$mac}.cnf",
            strtolower($mac) . '.cfg',
            'cfg' . strtolower($mac) . '.xml',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Data: settings (complete, site-owned sets; no inheritance)        */
    /* ---------------------------------------------------------------- */
    public function getSetting(string $key, ?int $siteId = null, $default = null)
    {
        $siteId = $siteId ?: $this->localSiteId();
        $s = $this->db->prepare("SELECT sval FROM ucmp_settings WHERE site_id = ? AND skey = ?");
        $s->execute([$siteId, $key]);
        $v = $s->fetchColumn();
        return $v !== false ? $v : $default;
    }
    public function getGlobalSettings(): array
    {
        return $this->getSiteSettingsLocal($this->localSiteId());
    }
    /** Raw stored settings for one scope only (no fallback to global) — for editing forms. */
    public function getSiteSettings(?int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            $result = $this->remoteQuery($siteId, 'getSiteSettingsLocal', [0]);
            if (is_array($result) && array_key_exists('ok', $result) && empty($result['ok'])) {
                throw new \RuntimeException((string)($result['message'] ?? 'Remote settings query failed'));
            }
            return is_array($result) ? $result : [];
        }
        return $this->getSiteSettingsLocal($siteId);
    }
    public function getSiteSettingsLocal(int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        $s = $this->db->prepare("SELECT skey, sval FROM ucmp_settings WHERE site_id = ?");
        $s->execute([$siteId]);
        $settings = $s->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        unset($settings['crypt_key'],$settings['identity_delivery_cache'],$settings['identity_delivery_releases']);
        return $settings;
    }
    public function setSetting(string $key, $val, ?int $siteId = null): void
    {
        $siteId = $siteId ?: $this->localSiteId();
        $sel = $this->db->prepare("SELECT id FROM ucmp_settings WHERE site_id = ? AND skey = ?");
        if (!$sel->execute([$siteId, $key])) { throw new \RuntimeException('Could not read setting before save'); }
        $id = $sel->fetchColumn();
        if ($id) {
            $saved = $this->db->prepare("UPDATE ucmp_settings SET sval = ? WHERE id = ?")->execute([$val, $id]);
        } else {
            $saved = $this->db->prepare("INSERT INTO ucmp_settings (site_id, skey, sval) VALUES (?, ?, ?)")->execute([$siteId, $key, $val]);
        }
        if (!$saved) { throw new \RuntimeException('Setting save was not confirmed'); }
    }

    /**
     * Move exact managed-wallpaper values without rewriting unrelated template
     * text. Remote sites perform the same transaction in their own PBX DB.
     *
     * @param array<int,array{old:string,new:string}> $replacements
     */
    public function replaceWallpaperReferences(int $siteId, array $replacements): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return (array)$this->remoteQuery($siteId, 'replaceWallpaperReferencesLocal', [$replacements]);
        }
        return $this->replaceWallpaperReferencesLocal($replacements);
    }

    /**
     * @param array<int,array{old:string,new:string}> $replacements
     */
    public function replaceWallpaperReferencesLocal(array $replacements): array
    {
        if (!$replacements || count($replacements) > 100) {
            throw new \InvalidArgumentException('Wallpaper rename requires between one and one hundred exact references');
        }
        $exact = [];
        $urlPaths = [];
        foreach ($replacements as $replacement) {
            $old = trim((string)($replacement['old'] ?? ''));
            $new = trim((string)($replacement['new'] ?? ''));
            if ($old === '' || $new === '') {
                throw new \InvalidArgumentException('Wallpaper rename contains an empty reference');
            }
            $oldIsUrl = filter_var($old, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $old);
            $newIsUrl = filter_var($new, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $new);
            $oldIsFile = preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $old) === 1;
            $newIsFile = preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $new) === 1;
            if ((!$oldIsUrl || !$newIsUrl) && (!$oldIsFile || !$newIsFile)) {
                throw new \InvalidArgumentException('Wallpaper rename accepts only matching managed filenames or HTTP(S) URLs');
            }
            $exact[$old] = $new;
            if ($oldIsUrl && $newIsUrl) {
                $urlPaths[(string)preg_replace('/[?#].*$/', '', $old)] = $new;
            }
        }
        $replace = static function ($value) use (&$replace, $exact, $urlPaths) {
            if (is_array($value)) {
                foreach ($value as $key=>$item) { $value[$key] = $replace($item); }
                return $value;
            }
            if (!is_string($value)) { return $value; }
            if (isset($exact[$value])) { return $exact[$value]; }
            $base = (string)preg_replace('/[?#].*$/', '', $value);
            return $urlPaths[$base] ?? $value;
        };

        $siteId = $this->localSiteId();
        $settingsChanged = 0;
        $templatesChanged = 0;
        if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin wallpaper reference update'); }
        try {
            $rowLock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $settings = $this->db->prepare(
                "SELECT id,sval FROM ucmp_settings
                  WHERE site_id=? AND skey IN ('cisco_wallpaper_defaults','hosted_image_defaults')" . $rowLock
            );
            $settings->execute([$siteId]);
            $updateSetting = $this->db->prepare('UPDATE ucmp_settings SET sval=? WHERE id=? AND sval=?');
            foreach ($settings->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $raw = (string)$row['sval'];
                $decoded = json_decode(trim($raw) !== '' ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) { throw new \RuntimeException('Wallpaper setting map is invalid'); }
                $updated = $replace($decoded);
                if ($updated === $decoded) { continue; }
                $updateSetting->execute([
                    json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    (int)$row['id'],
                    $raw,
                ]);
                if ($updateSetting->rowCount() !== 1) { throw new \RuntimeException('Wallpaper setting changed during reference update'); }
                $settingsChanged++;
            }

            $templates = $this->db->prepare(
                'SELECT id,setting_overrides,revision FROM ucmp_templates WHERE site_id=?' . $rowLock
            );
            $templates->execute([$siteId]);
            $updateTemplate = $this->db->prepare(
                'UPDATE ucmp_templates
                    SET setting_overrides=?,revision=revision+1,updated=?
                  WHERE id=? AND revision=?'
            );
            foreach ($templates->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $raw = (string)$row['setting_overrides'];
                $decoded = json_decode(trim($raw) !== '' ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) { throw new \RuntimeException('Template settings are invalid; wallpaper references were not changed'); }
                $updated = $decoded;
                foreach (['cisco_default_wallpaper','cisco_wallpaper_by_profile','hosted_image_url','hosted_image_by_profile','legacy_logo_url'] as $key) {
                    if (array_key_exists($key,$updated)) { $updated[$key] = $replace($updated[$key]); }
                }
                if ($updated === $decoded) { continue; }
                $updateTemplate->execute([
                    json_encode($updated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    time(),
                    (int)$row['id'],
                    (int)$row['revision'],
                ]);
                if ($updateTemplate->rowCount() !== 1) { throw new \RuntimeException('Template changed during wallpaper reference update'); }
                $templatesChanged++;
            }
            if (!$this->db->commit()) { throw new \RuntimeException('Wallpaper reference commit was not confirmed'); }
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
        return [
            'ok'=>true,
            'settings_changed'=>$settingsChanged,
            'templates_changed'=>$templatesChanged,
            'message'=>"Updated $settingsChanged site setting map(s) and $templatesChanged template(s)",
        ];
    }

    public function saveSettings(array $d): void
    {
        $siteId = (int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            $this->remoteQuery($siteId, 'saveSettingsLocal', [$d]);
            return;
        }
        $this->saveSettingsLocal($d);
    }

    /**
     * Validate the complete replacement set before any settings row changes.
     * Exact model > series+role > series > family+role > family is enforced by
     * DefaultTemplateResolver and every active rule must match at least one
     * currently provisionable catalog model.
     */
    private function normalizeDefaultTemplateRules(int $siteId, $input): array
    {
        if (is_string($input)) {
            try {
                $input = json_decode($input, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Default-template rules must be valid JSON');
            }
        }
        if (!is_array($input) || count($input) > 200) {
            throw new \InvalidArgumentException('Default-template rules must be a bounded array');
        }
        $templates = $this->getTemplatesLocal($siteId, false);
        $templatesById = [];
        foreach ($templates as $template) {
            $templatesById[(int)$template['id']] = $template;
        }
        $models = array_values(array_filter(
            $this->getModels(),
            static fn(array $model): bool =>
                \FreePBX\modules\Ucmprovisioner\ModelCatalog::unsupportedReason(
                    (string)$model['vendor'],
                    (string)$model['model']
                ) === null
                && !in_array((string)($model['role'] ?? 'desk'), ['dect_handset','dect_radio','dect_manager','unsupported'], true)
        ));
        $supportedFamilies = ['cisco_xml','cisco_cnf','yealink','grandstream'];
        $normal = [];
        $keys = [];
        foreach (array_values($input) as $index=>$rule) {
            if (!is_array($rule)) {
                throw new \InvalidArgumentException('Every default-template rule must be an object');
            }
            $templateId = (int)($rule['template_id'] ?? 0);
            if (!isset($templatesById[$templateId])) {
                throw new \InvalidArgumentException('A default-template rule selects a missing or cross-site template');
            }
            $vendor = trim((string)($rule['target_vendor'] ?? ''));
            $family = strtolower(trim((string)($rule['target_family'] ?? '')));
            $series = strtolower(trim((string)($rule['target_series'] ?? '')));
            $modelKey = trim((string)($rule['target_model'] ?? ''));
            $role = strtolower(trim((string)($rule['target_role'] ?? '')));
            if ($family !== '' && !in_array($family, $supportedFamilies, true)) {
                throw new \InvalidArgumentException('A default-template rule has an unsupported family');
            }
            if ($modelKey !== '' && !preg_match('/^[^|]{1,63}\|[^|]{1,63}$/', $modelKey)) {
                throw new \InvalidArgumentException('A default-template rule has an invalid exact model');
            }
            if ($family === '' && $series === '' && $modelKey === '') {
                throw new \InvalidArgumentException('Each default-template rule must target a family, series, or exact model');
            }

            $matchingModels = $models;
            if ($modelKey !== '') {
                $matchingModels = array_values(array_filter($matchingModels, static function (array $model) use ($modelKey): bool {
                    return strcasecmp((string)$model['vendor'] . '|' . (string)$model['model'], $modelKey) === 0;
                }));
            }
            if ($series !== '') {
                $matchingModels = array_values(array_filter($matchingModels, static function (array $model) use ($series): bool {
                    return \FreePBX\modules\Ucmprovisioner\ModelCatalog::templateScopeMatches($series, $model);
                }));
            }
            if ($family !== '') {
                $matchingModels = array_values(array_filter(
                    $matchingModels,
                    static fn(array $model): bool => strcasecmp((string)$model['family'], $family) === 0
                ));
            }
            if ($vendor !== '') {
                $matchingModels = array_values(array_filter(
                    $matchingModels,
                    static fn(array $model): bool => strcasecmp((string)$model['vendor'], $vendor) === 0
                ));
            }
            if ($role !== '') {
                $matchingModels = array_values(array_filter(
                    $matchingModels,
                    static fn(array $model): bool => strcasecmp((string)($model['role'] ?? 'desk'), $role) === 0
                ));
            }
            if (!$matchingModels) {
                throw new \InvalidArgumentException('A default-template rule does not match a provisionable catalog model');
            }
            $first = $matchingModels[0];
            if ($vendor === '') { $vendor = (string)$first['vendor']; }
            if ($family === '') { $family = (string)$first['family']; }
            if ($series !== '') { $series = strtolower($series); }
            if ($modelKey !== '') {
                $modelKey = (string)$first['vendor'] . '|' . (string)$first['model'];
            }
            $targetKey = hash('sha256', strtolower(implode('|', [$vendor,$family,$series,$modelKey,$role])));
            if (isset($keys[$targetKey])) {
                throw new \InvalidArgumentException('Two default-template rules have the same target');
            }
            $keys[$targetKey] = true;
            $normal[] = [
                'id'=>$index + 1,
                'site_id'=>$siteId,
                'template_id'=>$templateId,
                'target_key'=>$targetKey,
                'target_vendor'=>$vendor ?: null,
                'target_family'=>$family ?: null,
                'target_series'=>$series ?: null,
                'target_model'=>$modelKey ?: null,
                'target_role'=>$role ?: null,
                'enabled'=>array_key_exists('enabled', $rule) ? (int)!empty($rule['enabled']) : 1,
                'updated'=>time(),
            ];
        }

        $resolver = new \FreePBX\modules\Ucmprovisioner\DefaultTemplateResolver();
        foreach ($normal as $rule) {
            if (empty($rule['enabled'])) { continue; }
            $matched = false;
            foreach ($models as $model) {
                $device = [
                    'vendor'=>(string)$model['vendor'],
                    'family'=>(string)$model['family'],
                    'series_key'=>(string)($model['series_key'] ?? '') ?: \FreePBX\modules\Ucmprovisioner\ModelCatalog::seriesKey(
                        (string)$model['vendor'],
                        (string)$model['model'],
                        (string)$model['family']
                    ),
                    'model'=>(string)$model['model'],
                    'model_id'=>(int)$model['id'],
                    'role'=>(string)($model['role'] ?? 'desk'),
                ];
                if ($resolver->resolve([$rule], $templates, $device)['template_id'] !== null) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                throw new \InvalidArgumentException(
                    'A default-template rule matches devices, but its selected template applicability excludes all of them'
                );
            }
        }
        foreach ($models as $model) {
            $resolver->resolve($normal, $templates, [
                'vendor'=>(string)$model['vendor'],
                'family'=>(string)$model['family'],
                'series_key'=>(string)($model['series_key'] ?? '') ?: \FreePBX\modules\Ucmprovisioner\ModelCatalog::seriesKey(
                    (string)$model['vendor'],
                    (string)$model['model'],
                    (string)$model['family']
                ),
                'model'=>(string)$model['model'],
                'model_id'=>(int)$model['id'],
                'role'=>(string)($model['role'] ?? 'desk'),
            ]);
        }
        return $normal;
    }

    public function saveSettingsLocal(array $d): void
    {
        $this->withConfigurationSyncLock(function()use($d):void{$this->saveSettingsWithScheduleLock($d);});
    }

    private function saveSettingsWithScheduleLock(array $d): void
    {
        $locks = [];
        try {
            foreach (['blf_mesh'=>'mesh-scheduler','phone_rebuild'=>'phone-rebuild-scheduler'] as $prefix=>$file) {
                $keys = [$prefix . '_schedule_enabled',$prefix . '_interval_minutes',$prefix . '_schedule_json',$prefix . '_reboot'];
                if (!array_intersect($keys, array_keys((array)($d['settings'] ?? [])))) { continue; }
                if (isset($this->configurationSyncScheduleLocks[$file])) { continue; }
                $lock = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock('/var/lib/asterisk/ucmprovisioner/' . $file . '.lock');
                if ($lock === false) { throw new \RuntimeException('The schedule is running; settings were not changed. Retry after it finishes.'); }
                $locks[] = $lock;
            }
            $this->saveSettingsLocalLocked($d);
        } finally {
            foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private function saveSettingsLocalLocked(array $d): void
    {
        $siteId = (int)($d['site_id'] ?? 0) ?: $this->localSiteId();
        $replaceDefaultRules = array_key_exists('default_template_rules', $d);
        $defaultRules = $replaceDefaultRules
            ? $this->normalizeDefaultTemplateRules($siteId, $d['default_template_rules'])
            : [];
        $allowedKeys = array_merge(...array_values(\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS));
        $choices = [
            'transport' => ['udp','tcp','tls'],
            'cisco_transport' => ['udp','tcp','tls'],
            'cisco_cnf_transport' => ['udp'],
            'non_cisco_driver' => ['sip','pjsip'],
            'non_cisco_transport' => ['udp','tcp','tls'],
            'date_template' => ['D/M/Y','M/D/Y','Y/M/D','D-M-Y','Y-M-D'],
            'proxy' => ['USECALLMANAGER',''],
            'device_security' => ['1','2','3'],
            'cisco_xml_timezone' => ['GMT Standard/Daylight Time','W. Europe Standard/Daylight Time','E. Europe Standard/Daylight Time','Egypt Standard/Daylight Time','South Africa Standard Time','Saudi Arabia Standard Time','Iran Standard/Daylight Time','India Standard Time','China Standard/Daylight Time','Tokyo Standard Time','AUS Eastern Standard/Daylight Time','US Eastern Standard/Daylight Time','Central Standard/Daylight Time','Mountain Standard/Daylight Time','Pacific Standard/Daylight Time'],
            'cisco_legacy_timezone' => ['GMT','CET','EET','EST','CST','MST','PST','IT'],
            'cisco_legacy_dst_auto_adjust' => ['0','1'],
            'background_access_disabled' => ['0','1'],
            'phone_personalization' => ['0','1'],
            'blf_mesh_schedule_enabled' => ['0','1'],
            'phone_rebuild_schedule_enabled' => ['0','1'],
            'phone_rebuild_reboot' => ['0','1'],
            'vlan_mode' => ['inherit','discovery','manual','off'],
            'vlan_pc_port' => ['inherit','untagged','tagged','disabled'],
            'yealink_timezone' => ['-12','-11','-10','-9:30','-9','-8','-7','-6','-5','-4','-3:30','-3','-2','-1','+0','+1','+2','+3','+3:30','+4','+4:30','+5','+5:30','+5:45','+6','+6:30','+7','+8','+9','+9:30','+10','+11','+12','+13','+14'],
        ];
        $updates = [];
        foreach (($d['settings'] ?? []) as $k => $v) {
            $k = (string)$k;
            if (!in_array($k, $allowedKeys, true)) { continue; }
            if ($v === null && $this->configurationSyncApplying) { $updates[$k] = null; continue; }
            if ($k === 'blf_mesh_interval_minutes' && isset($d['settings']['blf_mesh_schedule_json'])) { continue; }
            if ($k === 'phone_rebuild_interval_minutes' && isset($d['settings']['phone_rebuild_schedule_json'])) { continue; }
            $v = (string) $v;
            if (isset($choices[$k]) && !in_array($v, $choices[$k], true)) {
                throw new \InvalidArgumentException("Invalid value for $k");
            }
            if (in_array($k, ['sip_port','cisco_sip_port','cisco_cnf_sip_port','non_cisco_sip_port'], true)
                && ((int)$v < 1 || (int)$v > 65535)) {
                throw new \InvalidArgumentException("$k must be between 1 and 65535");
            }
            if ($k === 'blf_mesh_interval_minutes' && ((int)$v < 15 || (int)$v > 10080)) { throw new \InvalidArgumentException('BLF mesh interval must be between 15 minutes and 7 days'); }
            if ($k === 'phone_rebuild_interval_minutes' && ((int)$v < 15 || (int)$v > 10080)) { throw new \InvalidArgumentException('Phone rebuild interval must be between 15 minutes and 7 days'); }
            if ($k === 'blf_mesh_schedule_json') {
                $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
                    $v,
                    max(15, (int)(($d['settings']['blf_mesh_interval_minutes'] ?? 60))),
                    15
                );
                $v = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($schedule);
                $updates['blf_mesh_interval_minutes'] = (string)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($schedule);
            }
            if ($k === 'phone_rebuild_schedule_json') {
                $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
                    $v,
                    max(15, (int)(($d['settings']['phone_rebuild_interval_minutes'] ?? 1440))),
                    15
                );
                $v = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::encode($schedule);
                $updates['phone_rebuild_interval_minutes'] = (string)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::compatibilityMinutes($schedule);
            }
            if ($k === 'cisco_xml_olson_timezone' && $v !== '' && !preg_match('#^[A-Za-z][A-Za-z0-9_+.-]*/[A-Za-z0-9_+./-]+$#', $v)) { throw new \InvalidArgumentException('Cisco Olson time zone must be an IANA area/location such as Asia/Tehran'); }
            if ($k === 'default_phonebook_key' && $v !== '') {
                if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $v)) { throw new \InvalidArgumentException('Invalid default phonebook key'); }
                $catalog = $this->getPublishedDirectoryPhonebooksLocal();
                $available = array_fill_keys(array_map(static fn(array $book): string => (string)($book['book_key'] ?? ''), (array)($catalog['phonebooks'] ?? [])), true);
                if (!isset($available[$v])) { throw new \RuntimeException('The selected default phonebook is not present in this PBX published cache'); }
            }
            if (in_array($k, ['dial_template_file','softkey_file','featurepolicy_file'], true) && $v !== '' && !preg_match('/^[A-Za-z0-9_.-]+$/', $v)) { throw new \InvalidArgumentException("Invalid filename for $k"); }
            if ($k === 'services_url' && $v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v))) { throw new \InvalidArgumentException("$k must be an HTTP or HTTPS URL"); }
            if ($k === 'legacy_logo_url' && $v !== '') {
                $parts = parse_url($v);
                if (filter_var($v, FILTER_VALIDATE_URL) === false
                    || !is_array($parts)
                    || strtolower((string)($parts['scheme'] ?? '')) !== 'http'
                    || trim((string)($parts['host'] ?? '')) === ''
                    || isset($parts['user'])
                    || isset($parts['pass'])
                    || isset($parts['query'])
                    || isset($parts['fragment'])
                    || !preg_match('/\.bmp$/iD', (string)($parts['path'] ?? ''))
                ) {
                    throw new \InvalidArgumentException('legacy_logo_url must be a direct plain-http BMP URL without credentials, a query string, or a fragment');
                }
            }
            if (in_array($k, ['media_base_url','phone_services_base_url'], true) && $v !== '') {
                $v = \FreePBX\modules\Ucmprovisioner\MediaUrl::normalizeBase($v);
            }
            if ($k === 'vlan_discovery' && $v !== '') {
                $protocols = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower($v)))));
                foreach ($protocols as $protocol) {
                    if (!in_array($protocol, \FreePBX\modules\Ucmprovisioner\VlanPolicy::DISCOVERY, true)) {
                        throw new \InvalidArgumentException("Unsupported VLAN discovery protocol $protocol");
                    }
                }
                $v = implode(',', $protocols);
            }
            if (in_array($k, ['vlan_voice_vid','vlan_pc_vid'], true) && $v !== '' && ((int)$v < 1 || (int)$v > 4094)) { throw new \InvalidArgumentException("$k must be between 1 and 4094"); }
            if (in_array($k, ['vlan_voice_pcp','vlan_pc_pcp'], true) && $v !== '' && ((int)$v < 0 || (int)$v > 7)) { throw new \InvalidArgumentException("$k must be between 0 and 7"); }
            if (in_array($k, ['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults'], true) && $v !== '') {
                $map = json_decode($v, true);
                if (!is_array($map) || count($map) > 200) { throw new \InvalidArgumentException("$k must be a bounded JSON object"); }
                foreach ($map as $mapKey=>$mapValue) {
                    if (!is_string($mapKey) || !is_scalar($mapValue) || strlen((string)$mapValue) > 1024) { throw new \InvalidArgumentException("$k contains an invalid entry"); }
                    if ($k === 'hosted_image_defaults' && (string)$mapValue !== '' && (!filter_var((string)$mapValue, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', (string)$mapValue))) { throw new \InvalidArgumentException('Hosted image defaults must be HTTP or HTTPS URLs'); }
                }
                if ($k === 'firmware_defaults') {
                    foreach ($map as $profile=>$load) {
                        $load = trim(str_replace('\\', '/', (string)$load));
                        if ($load === '') { continue; }
                        $models = \FreePBX\modules\Ucmprovisioner\ModelCatalog::modelsForFirmwareProfile((string)$profile);
                        if (!$models) { throw new \InvalidArgumentException("Unknown firmware profile $profile"); }
                        if (strlen($load) > 255 || str_contains($load, '..') || str_starts_with($load, '/') || !preg_match('#^[A-Za-z0-9._/-]+$#', $load)) {
                            throw new \InvalidArgumentException("The selected firmware package for $profile has an invalid path");
                        }
                        $map[$profile] = $load;
                    }
                } elseif ($k === 'cisco_timezone_defaults') {
                    $inventory = $this->configurationSyncApplying
                        ? $this->configurationSyncInventory('timezone_versions')
                        : (array)$this->tftpOp(['op'=>'timezone_versions','site_id'=>$siteId]);
                    if (empty($inventory['ok'])) {
                        throw new \RuntimeException((string)($inventory['message'] ?? 'Cisco timezone inventory is unavailable'));
                    }
                    $available = [];
                    foreach ((array)($inventory['assets'] ?? []) as $asset) {
                        if (empty($asset['selectable'])) { continue; }
                        $available[(string)($asset['profile'] ?? '')][(string)($asset['asset_key'] ?? '')] = true;
                    }
                    foreach ($map as $profile=>$assetKey) {
                        $profile = (string)$profile;
                        $assetKey = strtolower(trim((string)$assetKey));
                        if (!isset(\FreePBX\modules\Ucmprovisioner\ModelCatalog::CISCO_TIMEZONE_PROFILES[$profile])
                            || preg_match('/^[a-f0-9]{64}$/D', $assetKey) !== 1) {
                            throw new \InvalidArgumentException('Cisco timezone defaults must use a documented runtime profile and managed asset');
                        }
                        if (empty($available[$profile][$assetKey])) {
                            throw new \RuntimeException("The selected Cisco timezone asset for $profile is not retained and phone-accessible on this PBX");
                        }
                        $map[$profile] = $assetKey;
                    }
                } elseif ($k === 'cisco_wallpaper_defaults') {
                    $validProfiles = [];
                    foreach (array_keys(\FreePBX\modules\Ucmprovisioner\ModelCatalog::BACKGROUND_PROFILES) as $modelName) {
                        $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::backgroundProfileKey('Cisco', (string)$modelName);
                        if ($profile !== null) { $validProfiles[$profile] = true; }
                    }
                    foreach ($map as $profile=>$filename) {
                        if (trim((string)$filename) === '') { continue; }
                        if (!isset($validProfiles[(string)$profile]) || !preg_match('/^[A-Za-z0-9_.-]+\.png$/i', (string)$filename)) {
                            throw new \InvalidArgumentException('Cisco wallpaper defaults must use a supported screen profile and PNG catalog filename');
                        }
                    }
                } elseif ($k === 'hosted_image_defaults') {
                    // Older settings stored a family name instead of a concrete
                    // hosted-media profile. Accept only the three known family keys.
                    $validHostedProfiles = array_fill_keys(['cisco_cnf','yealink','grandstream'], true);
                    foreach ($this->getModels() as $catalogModel) {
                        $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaProfileKey((string)$catalogModel['vendor'], (string)$catalogModel['model']);
                        if ($profile !== null) { $validHostedProfiles[$profile] = true; }
                    }
                    foreach (array_keys($map) as $family) {
                        if (!isset($validHostedProfiles[(string)$family])) { throw new \InvalidArgumentException('Unknown PBX-hosted screen profile'); }
                    }
                }
                $filteredMap = array_filter($map, static fn($value): bool => trim((string)$value) !== '');
                $v = json_encode($filteredMap ?: new \stdClass(), JSON_UNESCAPED_SLASHES);
            }
            if ($v === '') {
                // Blank removes the optional site value; TemplateEngine then uses its safe
                // product default. Sites never inherit values from another site.
                $updates[(string)$k] = null;
                continue;
            }
            $updates[(string)$k] = $v;
        }
        $beforeSettings = $this->getSiteSettingsLocal($siteId);
        $candidate = $beforeSettings;
        foreach ($updates as $key=>$value) {
            if ($value === null) { unset($candidate[$key]); }
            else { $candidate[$key] = $value; }
        }
        \FreePBX\modules\Ucmprovisioner\VlanPolicy::normalize($candidate);
        $syncEditItems = [];
        foreach (['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults'] as $syncKey) {
            if (array_key_exists($syncKey, $updates)
                && (string)($beforeSettings[$syncKey] ?? '') !== (string)($candidate[$syncKey] ?? '')) {
                $beforeMap = json_decode((string)($beforeSettings[$syncKey] ?? '{}'), true) ?: [];
                $afterMap = json_decode((string)($candidate[$syncKey] ?? '{}'), true) ?: [];
                foreach (array_unique(array_merge(array_keys($beforeMap),array_keys($afterMap))) as $profile) {
                    $beforePresent = array_key_exists($profile,$beforeMap);
                    $afterPresent = array_key_exists($profile,$afterMap);
                    if ($beforePresent !== $afterPresent || (string)($beforeMap[$profile] ?? '') !== (string)($afterMap[$profile] ?? '')) {
                        $syncEditItems = array_merge(
                            $syncEditItems,
                            $this->configurationSyncEditGuard('site_defaults',0,$syncKey . ':' . $profile,$d),
                            $this->configurationSyncEditGuard('settings_assets',0,$syncKey . ':' . $profile,$d)
                        );
                    }
                }
            }
        }
        $settingScopes = [];
        foreach (\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS as $scope=>$keys) {
            foreach ($keys as $key) { $settingScopes[$key] = $scope; }
        }
        foreach ($settingScopes as $key=>$scope) {
            if (array_key_exists($key,$updates) && (string)($beforeSettings[$key] ?? '') !== (string)($candidate[$key] ?? '')) {
                $syncEditItems = array_merge($syncEditItems, $this->configurationSyncEditGuard($scope,0,'setting:' . $key,$d));
            }
        }
        if ($replaceDefaultRules
            && $this->configurationSyncDigest($this->configurationSyncRuleDigestData($this->getDefaultTemplateRulesLocal($siteId)))
                !== $this->configurationSyncDigest($this->configurationSyncRuleDigestData($defaultRules))) {
            $beforeRules = [];
            foreach ($this->getDefaultTemplateRulesLocal($siteId) as $rule) { $beforeRules[(string)($rule['target_key'] ?? '')] = $rule; }
            $afterRules = [];
            foreach ($defaultRules as $rule) { $afterRules[(string)($rule['target_key'] ?? '')] = $rule; }
            foreach (array_unique(array_merge(array_keys($beforeRules),array_keys($afterRules))) as $targetKey) {
                $beforeRule = $beforeRules[$targetKey] ?? null;
                $afterRule = $afterRules[$targetKey] ?? null;
                if ($this->configurationSyncDigest($beforeRule ? $this->configurationSyncRuleDigestData([$beforeRule]) : [])
                    !== $this->configurationSyncDigest($afterRule ? $this->configurationSyncRuleDigestData([$afterRule]) : [])) {
                    $syncEditItems = array_merge(
                        $syncEditItems,
                        $this->configurationSyncEditGuard('default_templates',0,'rule:' . $targetKey,$d),
                        $this->configurationSyncEditGuard('settings_default_template_selectors',0,'rule:' . $targetKey,$d)
                    );
                }
            }
        }
        $settingsTransaction = !$this->db->inTransaction();
        if (!$settingsTransaction && !$this->configurationSyncApplying) { throw new \RuntimeException('Settings require their own transaction outside a configuration import'); }
        if ($settingsTransaction && !$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin settings save'); }
        $commitAttempted = false;
        try {
            foreach ($updates as $key=>$value) {
                if ($value === null) {
                    $this->db->prepare("DELETE FROM ucmp_settings WHERE site_id = ? AND skey = ?")->execute([$siteId, $key]);
                } else {
                    $this->setSetting($key, $value, $siteId);
                }
            }
            if (array_key_exists('blf_mesh_schedule_enabled', $updates) || array_key_exists('blf_mesh_schedule_json', $updates)) {
                $enabled = (string)($candidate['blf_mesh_schedule_enabled'] ?? '0') === '1';
                $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
                    $candidate['blf_mesh_schedule_json'] ?? [
                        'mode'=>'interval',
                        'interval_minutes'=>max(15, (int)($candidate['blf_mesh_interval_minutes'] ?? 60)),
                    ],
                    max(15, (int)($candidate['blf_mesh_interval_minutes'] ?? 60)),
                    15
                );
                $this->setSetting('blf_mesh_next_run', $enabled ? (string)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, time()) : '0', $siteId);
            }
            if (array_key_exists('phone_rebuild_schedule_enabled', $updates) || array_key_exists('phone_rebuild_schedule_json', $updates)) {
                $enabled = (string)($candidate['phone_rebuild_schedule_enabled'] ?? '0') === '1';
                $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
                    $candidate['phone_rebuild_schedule_json'] ?? [
                        'mode'=>'daily',
                        'interval_minutes'=>max(15, (int)($candidate['phone_rebuild_interval_minutes'] ?? 1440)),
                        'time'=>'03:00',
                    ],
                    max(15, (int)($candidate['phone_rebuild_interval_minutes'] ?? 1440)),
                    15
                );
                $this->setSetting('phone_rebuild_next_run', $enabled && $schedule['mode'] !== 'manual' ? (string)\FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, time()) : '0', $siteId);
            }
            if ($replaceDefaultRules) {
                $this->db->prepare('DELETE FROM ucmp_default_template_rules WHERE site_id=?')->execute([$siteId]);
                $insertRule = $this->db->prepare(
                    'INSERT INTO ucmp_default_template_rules
                        (site_id,template_id,target_key,target_vendor,target_family,target_series,target_model,target_role,enabled,updated)
                     VALUES (?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($defaultRules as $rule) {
                    $insertRule->execute([
                        $rule['site_id'],$rule['template_id'],$rule['target_key'],$rule['target_vendor'],
                        $rule['target_family'],$rule['target_series'],$rule['target_model'],
                        $rule['target_role'],$rule['enabled'],$rule['updated'],
                    ]);
                }
            }
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems, $d); }
            $commitAttempted = $settingsTransaction;
            if ($settingsTransaction && !$this->db->commit()) { throw new \RuntimeException('Settings commit was not confirmed'); }
        } catch (\Throwable $e) {
            if ($commitAttempted) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                    'Settings commit was not confirmed; inspect the saved state before retrying.',
                    ['ok'=>false,'status'=>'unknown','settings_saved'=>null,'saved_keys'=>array_keys($updates),
                        'default_rules_saved'=>null,'failed_stage'=>'settings_commit']
                );
            }
            if ($settingsTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        if (array_key_exists('default_phonebook_key', $updates)) {
            if (!$settingsTransaction) {
                $this->configurationSyncAfterCommit['default_phonebook'] = fn() => $this->publishSettingsPhonebookDefault($updates, $replaceDefaultRules);
                return;
            }
            $this->publishSettingsPhonebookDefault($updates, $replaceDefaultRules);
        }
    }

    private function publishSettingsPhonebookDefault(array $updates, bool $replaceDefaultRules): void
    {
            $publication = ['ok'=>false,'status'=>'unknown'];
            try {
                $engine = $this->engine('DirectoryService');
                if (!$engine) { throw new \RuntimeException('Directory service is unavailable'); }
                $publication = $engine->setPublishedDefaultPhonebook((string)($updates['default_phonebook_key'] ?? ''));
                if (empty($publication['ok'])) { throw new \RuntimeException((string)($publication['message'] ?? 'Phonebook publication was not confirmed')); }
                if (!empty($publication['warning'])) { throw new \RuntimeException((string)$publication['warning']); }
            } catch (\Throwable $error) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                    'Settings were saved; the published phonebook needs attention: ' . $error->getMessage(),
                    ['ok'=>false,'status'=>'partial','settings_saved'=>true,'saved_keys'=>array_keys($updates),
                        'default_rules_saved'=>$replaceDefaultRules,'phonebook_publication'=>$publication]
                );
            }
    }

    /* ---------------------------------------------------------------- */
    /* AD/LDAP identity and company directory                            */
    /* ---------------------------------------------------------------- */
    private function directorySourceService(): \FreePBX\modules\Ucmprovisioner\DirectorySourceService
    {
        $siteKeys = [];
        foreach ($this->getSites() as $site) {
            $key = trim((string)($site['site_key'] ?? ''));
            if ($key !== '') { $siteKeys[] = $key; }
        }
        return new \FreePBX\modules\Ucmprovisioner\DirectorySourceService($this->db, $siteKeys);
    }

    /** @return array<int,array<string,mixed>> */
    public function getDirectorySources(): array
    {
        $sources = $this->directorySourceService()->all();
        $eng = $this->engine('DirectoryService');
        if (!$eng) { return $sources; }
        foreach ($sources as &$source) {
            $source = array_replace($source, $eng->sourceRuntimeStatus($source));
        }
        unset($source);
        return $sources;
    }

    /** @return array<string,mixed>|null */
    public function getDirectorySource(int $id): ?array
    {
        $source = $this->directorySourceService()->get($id);
        $eng = $this->engine('DirectoryService');
        return $source && $eng
            ? array_replace($source, $eng->sourceRuntimeStatus($source))
            : $source;
    }

    /** @return array<string,mixed> */
    public function saveDirectorySource(array $input): array
    {
        $values = is_array($input['source'] ?? null) ? $input['source'] : $input;
        $this->assertDirectorySourcePayloadSafe($values);
        $id = (int)($values['id'] ?? 0);
        $existing = $id > 0 ? $this->directorySourceService()->get($id) : null;
        if ($this->isLegacyDirectoryCompatibilitySource($existing)) {
            throw new \InvalidArgumentException(
                'Upgrade legacy-default in place or manage it through the compatibility settings'
            );
        }
        if (!empty($values['enabled'])) {
            if (!$existing) {
                throw new \InvalidArgumentException(
                    'Save the source disabled, provision its external credential, then enable it'
                );
            }
            $eng = $this->engine('DirectoryService');
            if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
            $runtime = $eng->sourceRuntimeStatus($existing);
            if (empty($runtime['secret_configured'])) {
                throw new \InvalidArgumentException(
                    'Provision a valid external credential object before enabling this source'
                );
            }
        }
        $allowed = array_fill_keys([
            'id', 'expected_updated', 'source_key', 'domain', 'enabled', 'endpoints',
            'tls_mode', 'secret_ref', 'base_dn', 'filter', 'attribute_map',
            'site_rules', 'schedule_minutes', 'schedule_json', 'schedule_mode',
            'schedule_time', 'schedule_weekdays',
        ], true);
        $saved = $this->directorySourceService()->save(array_intersect_key($values, $allowed));
        return $this->getDirectorySource((int)$saved['id']) ?? $saved;
    }

    /** @return array<string,mixed> */
    public function setDirectorySourceEnabled(int $id, bool $enabled, int $expectedUpdated): array
    {
        $existing = $this->directorySourceService()->get($id);
        if ($this->isLegacyDirectoryCompatibilitySource($existing)) {
            throw new \InvalidArgumentException(
                'Upgrade legacy-default in place or manage it through the compatibility settings'
            );
        }
        if ($enabled) {
            $eng = $this->engine('DirectoryService');
            $runtime = $eng ? $eng->sourceRuntimeStatus($existing) : [];
            if (empty($runtime['secret_configured'])) {
                throw new \InvalidArgumentException(
                    'Provision a valid external credential object before enabling this source'
                );
            }
        }
        $saved = $this->directorySourceService()->setEnabled($id, $enabled, $expectedUpdated);
        return $this->getDirectorySource((int)$saved['id']) ?? $saved;
    }

    /** @return array<string,mixed> */
    public function saveDirectorySourceSecret(array $input): array
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        return $eng->saveSourceSecret((int)($input['source_id'] ?? 0), $input);
    }

    private function isLegacyDirectoryCompatibilitySource(?array $source): bool
    {
        return is_array($source)
            && hash_equals('legacy-default', trim((string)($source['source_key'] ?? '')))
            && hash_equals(
                'legacy-setting:ad_bind_password',
                trim((string)($source['secret_ref'] ?? ''))
            );
    }

    /** @return array<string,mixed> */
    public function testDirectorySource(int $id): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng
            ? $eng->testSource($id)
            : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }

    private function assertDirectorySourcePayloadSafe(array $values, bool $topLevel = true): void
    {
        foreach ($values as $key => $value) {
            $name = strtolower((string)$key);
            if (
                !($topLevel && $name === 'secret_ref')
                && preg_match('/password|credential|secret/', $name)
            ) {
                throw new \InvalidArgumentException(
                    'Directory-source credentials must be supplied only as an opaque secret_ref'
                );
            }
            if (is_array($value)) {
                $this->assertDirectorySourcePayloadSafe($value, false);
            }
        }
    }

    public function getDirectorySettings(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->settings(false) : [];
    }
    public function saveDirectorySettings(array $data): void
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        $eng->save($data);
    }
    public function testDirectoryConnection(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->test($data) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }
    public function browseDirectoryTree(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->tree($data) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }
    public function syncDirectory(bool $manual = false, int $sourceId = 0): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->sync($manual, $sourceId) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }
    public function getDirectoryEntries(bool $includeConflicted = true, int $limit = 5000): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->entries($includeConflicted, $limit) : [];
    }
    public function getDirectoryConflicts(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->conflicts() : [];
    }
    public function getDirectoryStats(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->stats() : ['entries'=>0,'conflicts'=>0,'unmapped'=>0,'last_sync'=>0];
    }
    public function getDirectoryPhonebooks(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->phonebooks() : [];
    }
    public function getDirectoryPhonebook(int $id): ?array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->phonebook($id) : null;
    }
    public function getDirectoryPhonebookPreview(int $id): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->previewPhonebook($id) : ['book'=>null,'entries'=>[],'groups'=>[]];
    }
    public function previewDirectoryPhonebookDraft(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { return ['ok'=>false,'message'=>'Directory service is unavailable']; }
        $preview = $eng->previewDraft($data);
        $values = is_array($data['phonebook'] ?? null) ? $data['phonebook'] : $data;
        $preview['review'] = (new \FreePBX\modules\Ucmprovisioner\WebPhonebook())->reviewPublication($preview, $values);
        return $preview;
    }
    public function saveDirectoryPhonebook(array $data): int
    {
        return $this->withConfigurationSyncLock(fn():int=>$this->saveDirectoryPhonebookUnderLock($data));
    }

    private function saveDirectoryPhonebookUnderLock(array $data): int
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        $values = is_array($data['phonebook'] ?? null) ? $data['phonebook'] : $data;
        $id = (int)($values['id'] ?? $data['book_id'] ?? 0);
        if ($id > 0 && !array_key_exists('expected_updated',$values)) { throw new \RuntimeException('Reload the phonebook editor before saving; its revision is missing'); }
        $preview = $this->previewDirectoryPhonebookDraft($data);
        \FreePBX\modules\Ucmprovisioner\WebPhonebook::validatePublicationReview(
            $preview['review'], (string)($data['review_revision'] ?? ''),
            !empty($data['review_approved'])
        );
        $syncEditItems = $id > 0 ? $this->configurationSyncEditGuard('phonebook', $id, '', $values + $data) : [];
        $result = $this->configurationSyncTransaction(function () use ($eng,$data,$values,$syncEditItems): array {
            $savedId = $eng->savePhonebook($data,null,false);
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems, $values + $data); }
            $this->deferConfigurationSyncPhonebookPublication();
            return ['id'=>$savedId];
        });
        $this->reportPhonebookPublicationWarnings($eng);
        return (int)$result['id'];
    }
    public function deleteDirectoryPhonebook(int $id, array $input = []): void
    {
        $this->withConfigurationSyncLock(function()use($id,$input):void{$this->deleteDirectoryPhonebookUnderLock($id,$input);});
    }

    private function deleteDirectoryPhonebookUnderLock(int $id, array $input): void
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        $values = is_array($input['phonebook'] ?? null) ? $input['phonebook'] : $input;
        if (!array_key_exists('expected_updated',$values)) { throw new \RuntimeException('Reload the phonebook editor before deleting; its revision is missing'); }
        $syncEditItems = $this->configurationSyncEditGuard('phonebook', $id, '', $input);
        $this->configurationSyncTransaction(function () use ($eng,$id,$values,$input,$syncEditItems): array {
            $eng->deletePhonebook($id,false,(int)$values['expected_updated']);
            if ($syncEditItems) { $this->pauseConfigurationSyncItems($syncEditItems, $input + ['sync_edit_reason'=>'Deleted in Phonebook Designer']); }
            $this->deferConfigurationSyncPhonebookPublication();
            return [];
        });
        $this->reportPhonebookPublicationWarnings($eng);
    }
    public function duplicateDirectoryPhonebook(int $id): int
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        $savedId = $eng->duplicatePhonebook($id);
        $this->reportPhonebookPublicationWarnings($eng);
        return $savedId;
    }
    private function reportPhonebookPublicationWarnings($directory): void
    {
        foreach ((array)($directory->lastPublicationResult()['warnings'] ?? []) as $warning) {
            $this->addError(htmlspecialchars('Phonebook change saved; ' . $warning, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
    }
    public function resolveDirectoryConflict(array $data): array
    {
        $mode = strtolower(trim((string)($data['mode'] ?? 'local')));
        if (!in_array($mode, ['local','clear','reconcile'], true)) {
            throw new \InvalidArgumentException(
                'This module never writes conflict corrections to AD; make the correction in AD and synchronize again'
            );
        }
        $data['mode'] = $mode;
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->resolve($data) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }
    public function getDirectoryIdentityOverrides(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->identityOverrides() : [];
    }
    public function saveDirectoryIdentityOverride(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        return $eng->saveIdentityOverride($data);
    }
    public function revertDirectoryIdentityOverride(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        if (!$eng) { throw new \RuntimeException('Directory service is unavailable'); }
        return $eng->revertIdentityOverride($data);
    }
    public function createDirectoryServiceAccount(array $data): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->createServiceAccount($data) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }
    public function importDirectorySnapshot(int $siteId, array $entries, string $token): array
    {
        if (!$this->isSiteLocal($siteId)) {
            $json = (string)json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $compressed = gzencode($json, 6);
            if ($compressed === false) { throw new \RuntimeException('Could not compress the directory snapshot'); }
            // 192 KiB remains comfortably below the SSH agent's 1 MiB envelope after
            // JSON/base64 wrapping and also avoids web-server request-size surprises.
            $chunks = str_split(base64_encode($compressed), 196608);
            if (!$chunks || count($chunks) > 256) { throw new \RuntimeException('Directory snapshot is too large to federate safely'); }
            $transferId = bin2hex(random_bytes(16));
            try {
                foreach ($chunks as $index => $chunk) {
                    $staged = $this->remoteQuery($siteId, 'stageDirectorySnapshotChunkLocal', [$transferId, $index, count($chunks), $chunk]);
                    if (empty($staged['ok'])) { throw new \RuntimeException((string)($staged['message'] ?? 'Remote PBX rejected a directory chunk')); }
                }
                return $this->remoteQuery($siteId, 'commitDirectorySnapshotLocal', [$transferId, count($chunks), $token]);
            } catch (\Throwable $e) {
                try { $this->remoteQuery($siteId, 'abortDirectorySnapshotLocal', [$transferId]); }
                catch (\Throwable $ignored) {
                    // The destination expires abandoned chunks; retain the transfer
                    // failure that caused this best-effort cleanup attempt.
                }
                throw $e;
            }
        }
        return $this->importDirectorySnapshotLocal($entries, $token);
    }
    public function importDirectorySnapshotLocal(array $entries, string $token): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->publishSnapshotLocal($entries, $token) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }

    public function stageDirectorySnapshotChunkLocal(string $transferId, int $index, int $total, string $chunk): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId) || $index < 0 || $total < 1 || $total > 256 || $index >= $total
            || $chunk === '' || strlen($chunk) > 196608 || !preg_match('/^[A-Za-z0-9+\/=]+$/D', $chunk)) {
            throw new \InvalidArgumentException('Invalid directory snapshot chunk');
        }
        $dir = $this->directoryTransferDir();
        if ($index === 0) {
            foreach ((array)glob($dir . '/*.part') as $old) {
                if (is_file($old) && (int)@filemtime($old) < time() - 3600) { @unlink($old); }
            }
        }
        $path = $dir . '/' . $transferId . '.' . $index . '.part';
        if (@file_put_contents($path, $chunk, LOCK_EX) !== strlen($chunk)) {
            throw new \RuntimeException('Could not stage a directory snapshot chunk');
        }
        @chgrp($path, 'asterisk'); @chmod($path, 0640);
        return ['ok'=>true, 'index'=>$index, 'total'=>$total];
    }

    public function commitDirectorySnapshotLocal(string $transferId, int $total, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId) || $total < 1 || $total > 256) {
            throw new \InvalidArgumentException('Invalid directory snapshot transfer');
        }
        $dir = $this->directoryTransferDir();
        $encoded = '';
        try {
            for ($index = 0; $index < $total; $index++) {
                $path = $dir . '/' . $transferId . '.' . $index . '.part';
                if (!is_readable($path)) { throw new \RuntimeException("Directory snapshot chunk {$index} is missing"); }
                $encoded .= (string)file_get_contents($path);
                if (strlen($encoded) > 50331648) { throw new \RuntimeException('Directory snapshot exceeds the safe decode limit'); }
            }
            $compressed = base64_decode($encoded, true);
            $json = is_string($compressed) ? gzdecode($compressed) : false;
            if (!is_string($json) || strlen($json) > 67108864) { throw new \RuntimeException('Directory snapshot compression is invalid'); }
            $entries = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($entries) || !array_is_list($entries)) { throw new \RuntimeException('Directory snapshot payload is invalid'); }
            return $this->importDirectorySnapshotLocal($entries, $token);
        } finally {
            $this->abortDirectorySnapshotLocal($transferId);
        }
    }

    public function abortDirectorySnapshotLocal(string $transferId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId)) { throw new \InvalidArgumentException('Invalid directory snapshot transfer'); }
        $dir = '/var/lib/asterisk/ucmprovisioner/directory/incoming';
        foreach ((array)glob($dir . '/' . $transferId . '.*.part') as $path) { if (is_file($path)) { @unlink($path); } }
        return ['ok'=>true];
    }

    private function directoryTransferDir(): string
    {
        $dir = '/var/lib/asterisk/ucmprovisioner/directory/incoming';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the directory transfer cache');
        }
        @chgrp(dirname($dir), 'asterisk'); @chmod(dirname($dir), 02770);
        @chgrp($dir, 'asterisk'); @chmod($dir, 02770);
        return $dir;
    }

    /** Catalog actually cached on a PBX, used by the template source selector. */
    public function getPublishedDirectoryPhonebooks(int $siteId = 0): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) { return $this->remoteQuery($siteId, 'getPublishedDirectoryPhonebooksLocal'); }
        return $this->getPublishedDirectoryPhonebooksLocal();
    }

    public function getPublishedDirectoryPhonebooksLocal(): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->publishedPhonebooks() : ['ok'=>false,'phonebooks'=>[],'message'=>'Directory service is unavailable'];
    }

    public function copyPublishedDirectoryPhonebookPolicy(int $siteId, string $bookKey): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) { return $this->remoteQuery($siteId, 'copyPublishedDirectoryPhonebookPolicyLocal', [$bookKey]); }
        return $this->copyPublishedDirectoryPhonebookPolicyLocal($bookKey);
    }

    public function copyPublishedDirectoryPhonebookPolicyLocal(string $bookKey): array
    {
        $eng = $this->engine('DirectoryService');
        return $eng ? $eng->copyPublishedPhonebookPolicy($bookKey) : ['ok'=>false,'message'=>'Directory service is unavailable'];
    }

    /** One credential per serving PBX: compromise of one cache URL cannot read every site. */
    public function directoryTokenForSite($site): string
    {
        if (!is_array($site)) { $site = $this->getSite((int)$site) ?: []; }
        $siteKey = trim((string)($site['site_key'] ?? ''));
        if ($siteKey === '') { throw new \RuntimeException('Directory publication site has no stable site key'); }
        $localId = $this->localSiteId();
        $master = (string)$this->getSetting('directory_master_token', $localId, '');
        if (!preg_match('/^[A-Fa-f0-9]{48,128}$/D', $master)) {
            $legacy = (string)$this->getSetting('directory_token', $localId, '');
            $master = preg_match('/^[A-Fa-f0-9]{48,128}$/D', $legacy) ? $legacy : bin2hex(random_bytes(32));
            $sql = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (?,?,?) ON CONFLICT(site_id,skey) DO NOTHING'
                : 'INSERT INTO ucmp_settings (site_id,skey,sval) VALUES (?,?,?) ON DUPLICATE KEY UPDATE skey=VALUES(skey)';
            $this->db->prepare($sql)->execute([$localId,'directory_master_token',$master]);
            $master = (string)$this->getSetting('directory_master_token',$localId,'');
            if (!preg_match('/^[A-Fa-f0-9]{48,128}$/D',$master)) { throw new \RuntimeException('Saved directory token identity is invalid; repair it explicitly before publication'); }
        }
        $token = substr(hash_hmac('sha256', 'ucmp-directory-source\0' . $siteKey, $master), 0, 16);
        $this->setSetting('directory_source_token_' . substr(hash('sha256', $siteKey), 0, 24), $token, $localId);
        return $token;
    }

    public function directorySourceUrl(int $siteId): string
    {
        $site = $this->getSite($siteId) ?: [];
        return $this->directoryBaseUrl($siteId) . '?token=' . rawurlencode($this->directoryTokenForSite($site));
    }

    public function refreshDirectorySourceTokens(int $siteId, array $sources): array
    {
        if (!$this->isSiteLocal($siteId)) { return $this->remoteQuery($siteId, 'refreshDirectorySourceTokensLocal', [$sources]); }
        return $this->refreshDirectorySourceTokensLocal($sources);
    }

    public function refreshDirectorySourceTokensLocal(array $sources): array
    {
        $safe = [];
        foreach (array_slice($sources, 0, 100, true) as $siteKey => $url) {
            $siteKey = trim((string)$siteKey); $url = trim((string)$url);
            $parts = parse_url($url); $query = [];
            if (is_array($parts)) { parse_str((string)($parts['query'] ?? ''), $query); }
            if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $siteKey)
                || !is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true)
                || empty($parts['host']) || basename((string)($parts['path'] ?? '')) !== 'ucmprovisioner-directory.php'
                || !preg_match('/^[A-Fa-f0-9]{8,128}$/D', (string)($query['token'] ?? ''))) { continue; }
            $safe[$siteKey] = $url;
        }
        if (!$safe) { return ['ok'=>true, 'updated'=>0, 'message'=>'No directory source URLs required refresh']; }
        $rows = $this->db->query("SELECT id,phonebook_source_site,phonebook_source_url FROM ucmp_templates WHERE phonebook_source_site IS NOT NULL AND phonebook_source_site<>''")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $update = $this->db->prepare('UPDATE ucmp_templates SET phonebook_source_url=?,updated=? WHERE id=?');
        $changed = 0;
        foreach ($rows as $row) {
            $url = $safe[(string)$row['phonebook_source_site']] ?? null;
            if ($url !== null && !hash_equals($url, (string)($row['phonebook_source_url'] ?? ''))) {
                $update->execute([$url, time(), (int)$row['id']]); $changed++;
            }
        }
        return ['ok'=>true, 'updated'=>$changed, 'message'=>$changed . ' template directory source URL(s) refreshed'];
    }

    /** Phone-facing directory endpoint. Kept separate from API TLS for legacy phone compatibility. */
    public function directoryBaseUrl(int $siteId): string
    {
        $site = $this->getSite($siteId) ?: [];
        $host = trim((string)($site['pbx_addr'] ?? $site['pbx_host'] ?? ''));
        if ($host === '') { throw new \RuntimeException('The phonebook source site has no web address'); }
        $authority = str_contains($host, ':') && $host[0] !== '[' ? '[' . $host . ']' : $host;
        return 'http://' . $authority . '/ucmprovisioner-directory.php';
    }

    /* ---------------------------------------------------------------- */
    /* Dashboard stats                                                   */
    /* ---------------------------------------------------------------- */
    public function getStats(): array
    {
        $q = fn($sql) => (int)$this->db->query($sql)->fetchColumn();
        return [
            'sites'     => $q("SELECT COUNT(*) FROM ucmp_sites"),
            'phones'    => $q("SELECT COUNT(*) FROM ucmp_phones"),
            'bound'     => $q("SELECT COUNT(*) FROM ucmp_phones WHERE status='bound' OR status='provisioned'"),
            'discovered'=> $q("SELECT COUNT(*) FROM ucmp_phones WHERE status='discovered'"),
            'unassigned'=> $q("SELECT COUNT(*) FROM ucmp_phones WHERE status='unassigned'"),
            'templates' => $q("SELECT COUNT(*) FROM ucmp_templates"),
            'groups'    => $q("SELECT COUNT(*) FROM ucmp_groups"),
            'directory' => $q("SELECT COUNT(*) FROM ucmp_directory_entries WHERE conflicted=0"),
            'directory_conflicts' => $q("SELECT COUNT(*) FROM ucmp_directory_conflicts WHERE status='open'"),
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Service façades. Availability guards keep partially upgraded     */
    /* remote sites readable until their matching service file exists.  */
    /* ---------------------------------------------------------------- */
    public function discover(int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            $result = $this->remoteQuery($siteId, 'discover', [$siteId]);
        } else {
            $eng = $this->engine('Discovery');
            if (!$eng) { return ['ok' => false, 'message' => 'Discovery engine not yet available']; }
            $result = $eng->run($siteId);
        }
        // Registrations from several supported families expose no MAC. Once the
        // extension catalog is current, recover physical identities from existing
        // per-MAC TFTP configs whenever the extension-to-MAC match is unambiguous.
        if (!empty($result['ok'])) {
            $recovery = $this->backfillLegacyMacs($siteId);
            $result['tftp_identity_recovery'] = $recovery;
            if (!empty($recovery['ok']) && !empty($recovery['filled'])) {
                $result['message'] = rtrim((string)($result['message'] ?? ''), '.') . '; ' . ($recovery['message'] ?? 'TFTP identities recovered') . '.';
            } elseif (empty($recovery['ok'])) {
                $result['message'] = rtrim((string)($result['message'] ?? ''), '.') . '; TFTP identity recovery could not run: ' . ($recovery['message'] ?? 'unknown error') . '.';
            }
        }
        return $result;
    }
    public function rebuild(
        $selection,
        bool $apply = false,
        ?int $siteId = null,
        bool $restartAfter = false,
        bool $allowMesh = true,
        ?string $expectedPlanHash = null,
        ?string $expectedMeshPlanHash = null,
        bool $replaceManual = false
    ): array
    {
        $siteId = ($siteId !== null && $siteId > 0) ? $siteId : $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            $result = $this->remoteQuery(
                $siteId,
                'rebuild',
                [$selection, $apply, null, $restartAfter, false, $expectedPlanHash, null, $replaceManual]
            );
        } else {
            $eng = $this->engine('Provisioner');
            if (!$eng) { return ['ok' => false, 'message' => 'Provisioner engine not yet available']; }
            $result = $eng->rebuild(
                (string)$selection,
                $apply,
                $siteId,
                $restartAfter,
                $expectedPlanHash,
                $replaceManual
            );
        }
        $crossSite = (array)($result['glue']['cross_site_targets'] ?? []);
        if ($crossSite) {
            $result['mesh_deferred'] = true;
            $result['message'] = trim(
                (string)($result['message'] ?? '')
                . ' Cross-site BLF mesh is independent; use Sync BLF Mesh or its schedule when federation changes.'
            );
        }
        return $result;
    }
    public function previewPhone(int $id, ?int $siteId = null): array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'previewPhone', [$id]);
        }
        $eng = $this->engine('Provisioner');
        if (!$eng) { return ['ok' => false, 'message' => 'Provisioner engine not yet available']; }
        return $eng->previewPhone($id);
    }
    public function sendPhoneCommand(int $phoneId, string $cmd, ?int $siteId = null): array
    {
        if ($siteId !== null && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'sendPhoneCommand', [$phoneId, $cmd]);
        }
        $eng = $this->engine('PhoneCommand');
        if (!$eng) { return ['ok' => false, 'message' => 'PhoneCommand engine not yet available']; }
        return $eng->send($phoneId, $cmd);
    }

    /** Send one bounded command batch on exactly one PBX/site. */
    public function sendPhoneCommands(array $phoneIds, string $cmd, ?int $siteId = null): array
    {
        $siteId = ($siteId !== null && $siteId > 0) ? $siteId : $this->localSiteId();
        $phoneIds = array_values(array_unique(array_filter(array_map('intval', $phoneIds), static fn(int $id): bool => $id > 0)));
        if (!$phoneIds) { return ['ok'=>false, 'message'=>'Select at least one phone', 'results'=>[]]; }
        if (count($phoneIds) > 200) { return ['ok'=>false, 'message'=>'A command batch is limited to 200 phones', 'results'=>[]]; }
        if (!in_array($cmd, ['restart','reboot'], true)) { return ['ok'=>false, 'message'=>'Only restart and reboot are allowed in a batch', 'results'=>[]]; }
        if (!$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'sendPhoneCommands', [$phoneIds, $cmd]);
        }
        foreach ($phoneIds as $phoneId) {
            $phone = $this->getPhone($phoneId);
            if (!$phone || (int)($phone['site_id'] ?: $siteId) !== $siteId) {
                return ['ok'=>false, 'message'=>"Phone #$phoneId does not belong to the selected site", 'results'=>[]];
            }
        }
        $eng = $this->engine('PhoneCommand');
        if (!$eng) { return ['ok'=>false, 'message'=>'PhoneCommand engine not yet available', 'results'=>[]]; }
        return $eng->sendBatch($phoneIds, $cmd);
    }

    /** Route one Fail2ban request to exactly the PBX selected in the UI. */
    public function fail2banOp(array $request): array
    {
        $siteId = (int)($request['site_id'] ?? 0) ?: $this->localSiteId();
        $request['site_id'] = $siteId;
        if (!$this->isSiteLocal($siteId)) {
            $result = $this->remoteQuery($siteId, 'fail2banOpLocal', [$request]);
            if ($this->isFail2banMutation((string)($request['op'] ?? ''))) {
                $this->logOperation($siteId, 'fail2ban-' . (string)$request['op'], !empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Remote Fail2ban operation completed'));
            }
            return $result;
        }
        return $this->fail2banOpLocal($request);
    }

    /** Execute only on the receiving PBX; exposed through the constrained RPC allowlist. */
    public function fail2banOpLocal(array $request): array
    {
        $request['site_id'] = $this->localSiteId();
        $engine = $this->engine('Fail2banService');
        if (!$engine) { return ['ok'=>false, 'message'=>'Fail2ban service engine is unavailable']; }
        try {
            $result = $engine->handle($request);
            if ($this->isFail2banMutation((string)($request['op'] ?? ''))) {
                $this->logOperation($this->localSiteId(), 'fail2ban-' . (string)$request['op'], !empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Fail2ban operation completed'));
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->isFail2banMutation((string)($request['op'] ?? ''))) {
                $this->logOperation($this->localSiteId(), 'fail2ban-' . (string)$request['op'], 'error', $e->getMessage());
            }
            throw $e;
        }
    }

    private function isFail2banMutation(string $op): bool
    {
        return $op === 'unban';
    }

    /** Route phone-service CRUD/preview to exactly the selected PBX. */
    public function phoneServiceOp(array $request): array
    {
        $siteId = (int)($request['site_id'] ?? 0) ?: $this->localSiteId();
        $request['site_id'] = $siteId;
        if (!$this->isSiteLocal($siteId)) { return $this->remoteQuery($siteId, 'phoneServiceOpLocal', [$request]); }
        return $this->phoneServiceOpLocal($request);
    }

    /** Execute on the receiving PBX only; remoteDispatch replaces the incoming site id. */
    public function phoneServiceOpLocal(array $request): array
    {
        $request['site_id'] = $this->localSiteId();
        $engine = $this->engine('PhoneServiceService');
        if (!$engine) { return ['ok'=>false,'message'=>'Phone-service engine is unavailable']; }
        $result = $engine->handle($request);
        if (in_array((string)($request['op'] ?? ''), ['save','delete','reorder'], true)) {
            $this->logOperation($this->localSiteId(), 'phone-services-' . (string)$request['op'], !empty($result['ok'])?'success':'error', (string)($result['message'] ?? 'Phone-service catalog changed'));
        }
        return $result;
    }

    /** Controller identity operations stay here; physical operations route to their owner. */
    public function operatorWorkflowOp(array $request): array
    {
        return (new \FreePBX\modules\Ucmprovisioner\OperatorWorkflowService($this))->handle($request);
    }

    public function operatorWorkflowAtSite(int $siteId, array $request): array
    {
        if ($siteId <= 0 || !$this->getSite($siteId)) { throw new \InvalidArgumentException('A valid explicit owner site is required'); }
        $request['site_id'] = $siteId;
        $delivery=in_array((string)($request['op'] ?? ''),['delivery_plan','delivery_execute'],true);
        if ($delivery) { $request['receiver_site_key']=(string)$this->getSite($siteId)['site_key']; }
        $result = $this->isSiteLocal($siteId)
            ? $this->operatorWorkflowLocal($request)
            : $this->remoteLiveQuery($siteId, 'operatorWorkflowLocal', [$request]);
        if ($delivery && $request['op']==='delivery_plan' && !empty($result['ok'])
            && ($result['receiver_site_key'] ?? '')!==$request['receiver_site_key']) {
            throw new \RuntimeException('Update the destination module: it did not acknowledge its identity site mapping');
        }
        return $result;
    }

    public function operatorWorkflowLocal(array $request): array
    {
        $request['site_id'] = $this->localSiteId();
        return (new \FreePBX\modules\Ucmprovisioner\OperatorWorkflowService($this))->handleLocal($request);
    }

    /** Keep one reviewed all-fleet plan while transferring bounded RPC chunks. */
    public function prepareIdentityDeliveryInput(int $siteId, array $packets): array
    {
        if (!$packets || count($packets)>5000) { throw new \InvalidArgumentException('Identity input must contain one to 5000 reviewed targets'); }
        $packets=array_map(static fn(array $packet):array=>\FreePBX\modules\Ucmprovisioner\IdentityDeliveryService::normalize($packet)+['force'=>!empty($packet['force'])],$packets);
        if (strlen(json_encode($packets,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>8388608) { throw new \LengthException('Identity input exceeds the 8 MiB staging bound'); }
        if (count($packets)<=128) { return ['identities'=>$packets]; }
        $origin=(string)($this->getSite($this->localSiteId())['site_key'] ?? '');
        foreach ($packets as $packet) { if (($packet['origin_site_key'] ?? '')!==$origin) { throw new \DomainException('Mixed identity origins cannot be staged'); } }
        $started=$this->operatorWorkflowAtSite($siteId,['op'=>'delivery_stage_begin','origin_site_key'=>$origin,
            'total_count'=>count($packets),'content_hash'=>\FreePBX\modules\Ucmprovisioner\IdentityDeliveryService::hash($packets,true)]);
        $id=(string)($started['input_id'] ?? '');
        if (empty($started['ok']) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { throw new \RuntimeException('Destination did not acknowledge staged identity input'); }
        $received=0;
        foreach (array_chunk($packets,128) as $index=>$chunk) {
            $part=$this->operatorWorkflowAtSite($siteId,['op'=>'delivery_stage_chunk','input_id'=>$id,'index'=>$index,'identities'=>$chunk]);
            $received+=count($chunk);
            if (empty($part['ok']) || (int)($part['received_count'] ?? -1)!==$received) { throw new \RuntimeException('Staged identity chunk is unverified; input '.$id.' was not executed'); }
        }
        if (empty($part['ready'])) { throw new \RuntimeException('Staged identity content hash was not verified; input '.$id.' was not executed'); }
        return ['input_id'=>$id];
    }

    /** Stable site-local bearer for read-only phone service URLs. */
    public function phoneServicesToken(bool $create=true): string
    {
        $siteId = $this->localSiteId();
        $token = (string)$this->getSetting('phone_services_token', $siteId, '');
        if (!preg_match('/^[A-Fa-f0-9]{32,128}$/D', $token) && $create) {
            $token = bin2hex(random_bytes(24));
            $this->setSetting('phone_services_token', $token, $siteId);
        }
        return $token;
    }
    public function tftpOp(array $req): array
    {
        $siteId = (int)($req['site_id'] ?? 0) ?: $this->localSiteId();
        $op = (string)($req['op'] ?? 'list');
        if (!$this->isSiteLocal($siteId) && in_array($op,\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::OPERATIONS,true)) {
            $json=json_encode($req,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if (strlen($json)>\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::LIMIT) { throw new \InvalidArgumentException('Wallpaper editor input exceeds 48 MiB'); }
            $identity=['transfer_id'=>bin2hex(random_bytes(16)),'sha256'=>hash('sha256',$json),'size'=>strlen($json)];
            for ($offset=0,$index=0;$offset<strlen($json);$offset+=\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::CHUNK,$index++) {
                $result=$this->remoteLiveQuery($siteId,'wallpaperEditorInputLocal',[$identity+['op'=>'chunk','index'=>$index,
                    'content'=>base64_encode(substr($json,$offset,\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::CHUNK))]]);
                if (empty($result['ok'])) { return $result+['failed_stage'=>'editor_input','transfer_id'=>$identity['transfer_id']]; }
            }
            return $this->remoteLiveQuery($siteId,'wallpaperEditorInputLocal',[$identity+['op'=>'apply']]);
        }
        // Inventory belongs to the PBX that owns the TFTP volume. Routing these
        // bounded operations through that PBX avoids a controller-side cache
        // disagreeing with the remote site's rebuild engine.
        if (!$this->isSiteLocal($siteId) && in_array($op, [
            'firmware_inventory', 'firmware_rescan',
            'managed_asset_inventory', 'managed_asset_rescan', 'managed_asset_scan_reset',
            'asset_retirement_preview', 'asset_reassign', 'asset_retire', 'asset_restore',
            'lock_status',
            'timezone_inventory', 'timezone_versions', 'cisco_locale_inventory',
        ], true)) {
            $req['site_id'] = $siteId;
            // These inventories are validation boundaries, not ordinary page
            // snapshots. A cached pre-replication result can otherwise report
            // copied firmware/media as absent (or deleted files as present).
            return $this->remoteLiveQuery($siteId, 'tftpOp', [$req]);
        }
        if (!$this->isSiteLocal($siteId) && $op === 'timezone_capture') {
            return $this->captureTimezoneVersion($siteId);
        }
        if (!$this->isSiteLocal($siteId) && $op === 'timezone_quarantine') {
            return $this->quarantineTimezoneVersion($siteId);
        }
        if (!$this->isSiteLocal($siteId) && $op === 'timezone_activate') {
            return $this->activateTimezoneVersion($siteId, (int)($req['bundle_id'] ?? 0));
        }
        if (!$this->isSiteLocal($siteId) && $op === 'timezone_adopt') {
            return $this->adoptTimezoneVersion($siteId, (string)($req['bundle_key'] ?? ''));
        }
        $eng = $this->engine('TftpStore');
        if (!$eng) { return ['ok' => false, 'message' => 'TftpStore engine not yet available']; }
        if (in_array($op,\FreePBX\modules\Ucmprovisioner\WallpaperEditorInput::OPERATIONS,true)) {
            return $this->withConfigurationSyncLock(fn():array=>$eng->handle($req));
        }
        return $eng->handle($req);
    }

    public function wallpaperEditorInputLocal(array $request): array
    {
        return (new \FreePBX\modules\Ucmprovisioner\WallpaperEditorInput('/var/lib/asterisk/ucmprovisioner/editor-incoming'))
            ->handle($request,$this->localSiteId(),fn(array $payload):array=>$this->tftpOp($payload));
    }

    /** Inspect, preview or apply a site-level TFTP architecture change. */
    public function tftpArchitectureOp(array $request): array
    {
        $siteId = (int)($request['site_id'] ?? $request['id'] ?? 0) ?: $this->localSiteId();
        $op = strtolower(trim((string)($request['op'] ?? 'inspect')));
        if ($op === 'inspect') {
            return $this->inspectPbxTftp($siteId);
        }
        $engine = $this->engine('TftpArchitectureService');
        if (!$engine) { throw new \RuntimeException('TFTP architecture service is unavailable'); }
        if ($op === 'cutover_preview') {
            return $engine->preview($siteId);
        }
        if ($op === 'cutover_apply') {
            return $engine->apply($siteId, trim((string)($request['plan_hash'] ?? '')));
        }
        throw new \InvalidArgumentException('Unknown TFTP architecture operation');
    }

    /** CLI maintenance boundary: quarantine all managed TFTP content except MAC configs. */
    public function resetManagedTftpStorage(int $siteId, bool $apply = false): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->resetManagedTftpStorageLocal($apply)
            : $this->remoteQuery($siteId, 'resetManagedTftpStorageLocal', [$apply]);
    }

    public function resetManagedTftpStorageLocal(bool $apply = false): array
    {
        $engine = $this->engine('TftpStore');
        return $engine
            ? $engine->handle([
                'op'=>'reset_except_phone_configs','site_id'=>$this->localSiteId(),
                'apply'=>$apply ? 1 : 0,'confirm'=>$apply ? 'PRESERVE_PHONE_CONFIGS' : '',
            ])
            : ['ok'=>false,'message'=>'TftpStore engine not yet available'];
    }

    /** Select once, before any transfer mutation. An authenticated rejection never triggers downgrade. */
    public function replicationTransport(int $siteId): string
    {
        if ($this->isSiteLocal($siteId)) { return 'local'; }
        $site = $this->getSite($siteId) ?: [];
        $transport = 'sftp';
        if (!empty($site['pbx_api_enabled']) && !empty($site['pbx_api_ready'])) {
            try {
                $caps = $this->siteConnection($siteId)->execApi([
                    'rpc_version'=>2, 'method'=>'tftpTransferLocal', 'args'=>[['op'=>'capabilities']],
                ]);
                $transport = 'api';
            } catch (\FreePBX\modules\Ucmprovisioner\ApiUnavailableException $unavailable) {
                // Only a read-only preflight may select the backup channel.
            }
        }
        if ($transport === 'sftp') {
            $caps = $this->remoteQueryViaSsh($siteId, 'tftpTransferLocal', [['op'=>'capabilities']]);
            $this->siteConnection($siteId)->dockerSftp();
        }
        if (empty($caps['ok']) || (int)($caps['protocol'] ?? 0) !== 1) {
            throw new \RuntimeException('Replication peer needs the same transfer protocol; upgrade the peer before synchronization');
        }
        $this->replicationCapabilities[$siteId] = $caps;
        return $transport;
    }

    public function replicationTransferCapabilities(int $siteId): array
    {
        return $this->isSiteLocal($siteId) ? $this->tftpTransferLocal(['op'=>'capabilities']) : ($this->replicationCapabilities[$siteId] ?? []);
    }

    /** Fixed-channel RPC. A lost mutation response is reconciled by receipt, never dispatched twice. */
    public function replicationTransfer(int $siteId, array $request, string $transport): array
    {
        return \FreePBX\modules\Ucmprovisioner\StorageBusyRetry::run(
            fn() => $this->replicationTransferOnce($siteId, $request, $transport), 'tftpTransferLocal', $request,
            fn() => $this->replicationProgress($siteId, 'Waiting for destination storage', 'Another writer is active; this operation has not started'));
    }

    private function replicationTransferOnce(int $siteId, array $request, string $transport): array
    {
        if ($this->isSiteLocal($siteId)) { return $this->tftpTransferLocal($request); }
        if (!in_array($transport, ['api','sftp'], true)) { throw new \InvalidArgumentException('Invalid replication transport'); }
        $args = [$request];
        $readOnly = $this->rpcIsReadOnly('tftpTransferLocal', $args);
        $operationId = $readOnly ? '' : bin2hex(random_bytes(16));
        try {
            if ($transport === 'sftp') {
                return (array)$this->remoteQueryViaSsh($siteId, 'tftpTransferLocal', $args, ['operation_id'=>$operationId]);
            }
            $rpc = ['rpc_version'=>2, 'method'=>'tftpTransferLocal', 'args'=>$args];
            if (!$readOnly) { $rpc['operation_id'] = $operationId; }
            return (array)$this->siteConnection($siteId)->execApi($rpc);
        } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $failure) {
            throw $failure;
        } catch (\Throwable $failure) {
            if ($readOnly) { throw $failure; }
            try {
                return (array)$this->remoteQueryViaSsh($siteId, 'rpcOperationStatusLocal', [$operationId]);
            } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $confirmed) {
                // A reconciled completed failure still contains the destination's
                // authoritative outcome; do not replace it with an unknown receipt.
                throw $confirmed;
            } catch (\Throwable $statusFailure) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                    'Transfer outcome is unresolved; inspect its receipt before retrying',
                    ['operation_id'=>$operationId, 'state'=>'unresolved', 'transfer_id'=>$request['transfer_id'] ?? '']
                );
            }
        }
    }

    public function tftpTransferLocal(array $request): array
    {
        $engine = $this->engine('TftpStore');
        if (!$engine) { throw new \RuntimeException('TFTP transfer engine is unavailable'); }
        return $engine->transfer($this->localSiteId(), $request);
    }

    /** Binary endpoint only: resource arguments cannot be supplied through JSON RPC. */
    public function receiveTftpFileLocal(array $request, $stream): array
    {
        $operationId = (string)($request['operation_id'] ?? '');
        $transfer = ['op'=>'upload_file','transfer_id'=>(string)($request['transfer_id'] ?? ''),'path'=>(string)($request['file']['path'] ?? '')];
        if (isset($request['offset']) || isset($request['length'])) { $transfer += array_intersect_key($request, array_flip(['offset','length'])); }
        return $this->rpcOperationJournal()->execute($operationId, $request,
            fn(): array => $this->tftpTransferLocal($transfer + ['stream'=>$stream]));
    }

    public function openTftpSourceFileLocal(array $file)
    {
        return $this->engine('TftpStore')->transferSourceFile($this->localSiteId(), $file);
    }

    /** A source snapshot is reused across destinations and automatically removed on close. */
    public function replicationSourceFile(int $siteId, array $file, string $transport)
    {
        if ($this->isSiteLocal($siteId)) { return $this->openTftpSourceFileLocal($file); }
        if ($transport === 'sftp') { return $this->engine('TftpStore')->transferSourceFile($siteId, $file); }
        if (empty($this->replicationTransferCapabilities($siteId)['file_stream'])) { throw new \RuntimeException('Update the source PBX for binary API file transfers'); }
        $temporary = tmpfile();
        if ($temporary === false) { throw new \RuntimeException('Cannot allocate a private source snapshot'); }
        try {
            $this->siteConnection($siteId)->execApiFile(['method'=>'tftp_file_download','file'=>$file], $temporary, false);
            return $temporary;
        } catch (\Throwable $error) { fclose($temporary); throw $error; }
    }

    public function replicationUploadFile(int $siteId, string $transferId, array $file, $stream, ?callable $progress = null, ?array $range = null): array
    {
        if ($range !== null) {
            // Only receipted, idempotent private ranges can retry; commit and legacy whole-file writes retain their existing reconciliation rule.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $operationId = bin2hex(random_bytes(16));
                $request = ['method'=>'tftp_file_upload','operation_id'=>$operationId,'transfer_id'=>$transferId,'file'=>$file]
                    + array_intersect_key($range, array_flip(['offset','length']));
                try { return $this->siteConnection($siteId)->execApiFile($request, $stream, true, $progress); }
                catch (\Throwable $error) {
                    if ($error instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException) {
                        $receipt = $error->receipt;
                        $status = (string)($receipt['result']['status'] ?? '');
                        if (!in_array($receipt['state'] ?? '', ['unknown','unresolved','running'], true) && $status !== 'staging_incomplete'
                            && !\FreePBX\modules\Ucmprovisioner\StorageBusyRetry::beforeMutation($error)) { throw $error; }
                    }
                    if ($attempt === 2) {
                        throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Transfer interrupted; rerun the same selection to resume confirmed ranges',
                            ['state'=>'completed','operation_id'=>$operationId,'result'=>['ok'=>false,'status'=>'staging_incomplete','resumable'=>true,
                                'publication_completed'=>false,'recovery_id'=>$transferId,'message'=>'Transfer interrupted after three attempts; confirmed ranges retained for resume']]);
                    }
                    $this->replicationProgress($siteId, 'Reconnecting transfer', 'Retry ' . ($attempt + 1) . '/2 for ' . $file['path'] . '; confirmed ranges retained');
                    usleep(($attempt + 1) * 1000000);
                }
            }
        }
        $operationId = bin2hex(random_bytes(16));
        $request = ['method'=>'tftp_file_upload','operation_id'=>$operationId,'transfer_id'=>$transferId,'file'=>$file];
        try { return $this->siteConnection($siteId)->execApiFile($request, $stream, true, $progress); }
        catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $confirmed) { throw $confirmed; }
        catch (\Throwable $error) {
            try { return (array)$this->remoteQueryViaSsh($siteId, 'rpcOperationStatusLocal', [$operationId]); }
            catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $confirmed) { throw $confirmed; }
            catch (\Throwable $statusError) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Binary upload completion is unconfirmed; inspect its receipt before retrying',
                    ['state'=>'unresolved','operation_id'=>$operationId,'transfer_id'=>$transferId]);
            }
        }
    }

    public function replicateTftpPaths(int $sourceSiteId, array $targets, array $paths, bool $overwrite): array
    {
        return (new \FreePBX\modules\Ucmprovisioner\TftpSyncService($this))->copy($sourceSiteId, $targets, $paths, $overwrite);
    }

    /** Register exact files on the PBX that owns the destination inventory. */
    public function registerManagedTftpCopies(int $siteId, array $files): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->registerManagedTftpCopiesLocal($files)
            : $this->remoteLiveQuery($siteId, 'registerManagedTftpCopiesLocal', [$files]);
    }

    public function registerManagedTftpCopiesLocal(array $files): array
    {
        $engine = $this->engine('TftpStore');
        return $engine
            ? $engine->handle([
                'op'=>'register_copied_assets',
                'site_id'=>$this->localSiteId(),
                'assets'=>$files,
            ])
            : ['ok'=>false,'message'=>'TftpStore engine not yet available'];
    }

    public function captureTimezoneVersion(int $siteId): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->captureTimezoneVersionLocal()
            : $this->remoteQuery($siteId, 'captureTimezoneVersionLocal');
    }

    public function captureTimezoneVersionLocal(): array
    {
        $eng = $this->engine('TftpStore');
        return $eng
            ? $eng->handle(['op'=>'timezone_capture','site_id'=>$this->localSiteId()])
            : ['ok'=>false, 'message'=>'TftpStore engine not yet available'];
    }

    public function quarantineTimezoneVersion(int $siteId): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->quarantineTimezoneVersionLocal()
            : $this->remoteQuery($siteId, 'quarantineTimezoneVersionLocal');
    }

    public function quarantineTimezoneVersionLocal(): array
    {
        $eng = $this->engine('TftpStore');
        return $eng
            ? $eng->handle(['op'=>'timezone_quarantine','site_id'=>$this->localSiteId()])
            : ['ok'=>false, 'message'=>'TftpStore engine not yet available'];
    }

    public function activateTimezoneVersion(int $siteId, int $bundleId): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->activateTimezoneVersionLocal($bundleId)
            : $this->remoteQuery($siteId, 'activateTimezoneVersionLocal', [$bundleId]);
    }

    public function activateTimezoneVersionLocal(int $bundleId): array
    {
        $eng = $this->engine('TftpStore');
        return $eng
            ? $eng->handle([
                'op'=>'timezone_activate',
                'site_id'=>$this->localSiteId(),
                'bundle_id'=>$bundleId,
            ])
            : ['ok'=>false, 'message'=>'TftpStore engine not yet available'];
    }

    public function adoptTimezoneVersion(int $siteId, string $bundleKey): array
    {
        return $this->isSiteLocal($siteId)
            ? $this->adoptTimezoneVersionLocal($bundleKey)
            : $this->remoteQuery($siteId, 'adoptTimezoneVersionLocal', [$bundleKey]);
    }

    public function adoptTimezoneVersionLocal(string $bundleKey): array
    {
        $eng = $this->engine('TftpStore');
        return $eng
            ? $eng->handle([
                'op'=>'timezone_adopt',
                'site_id'=>$this->localSiteId(),
                'bundle_key'=>$bundleKey,
            ])
            : ['ok'=>false, 'message'=>'TftpStore engine not yet available'];
    }

    /** Manage unauthenticated phone-facing HTTP media on the selected PBX. */
    public function mediaAssetOp(array $req): array
    {
        $siteId = (int)($req['site_id'] ?? 0) ?: $this->localSiteId();
        if ((string)($req['op'] ?? '') === 'sync') { return $this->syncMediaAssets($siteId, $req); }
        if ((string)($req['op'] ?? '') === 'special_catalog_sync') { return $this->syncSpecialMediaCatalogs($siteId, $req); }
        if (!$this->isSiteLocal($siteId)) { return $this->remoteQuery($siteId, 'mediaAssetOpLocal', [$req]); }
        $req['site_id'] = $siteId;
        return $this->mediaAssetOpLocal($req);
    }

    private function syncMediaAssets(int $sourceSiteId, array $req): array
    {
        $targets = array_values(array_unique(array_filter(array_map('intval', (array)($req['target_site_ids'] ?? [])), static fn(int $id): bool => $id > 0 && $id !== $sourceSiteId)));
        if (!$targets || count($targets) > 20) { return ['ok'=>false,'message'=>'Choose between one and twenty different target sites','sites'=>[]]; }
        $family = (string)($req['family'] ?? ''); $model = (string)($req['model'] ?? 'all'); $overwrite = !empty($req['overwrite']);
        $selectedNames = null;
        if (array_key_exists('names', $req)) {
            $rawNames = $req['names'];
            if (is_string($rawNames)) { $rawNames = json_decode($rawNames, true); }
            if (!is_array($rawNames) || !$rawNames || count($rawNames) > 200) {
                return ['ok'=>false,'message'=>'Choose between one and two hundred media files','sites'=>[]];
            }
            $selectedNames = [];
            foreach ($rawNames as $rawName) {
                if (!is_scalar($rawName)) { return ['ok'=>false,'message'=>'A selected media filename is invalid','sites'=>[]]; }
                $name = (string)$rawName;
                if ($name === '' || basename($name) !== $name || preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
                    return ['ok'=>false,'message'=>'A selected media filename is invalid','sites'=>[]];
                }
                $selectedNames[$name] = true;
            }
        }
        $onSite = function(int $siteId, array $request) {
            $request['site_id'] = $siteId;
            return $this->isSiteLocal($siteId) ? $this->mediaAssetOpLocal($request) : $this->remoteQuery($siteId, 'mediaAssetOpLocal', [$request]);
        };
        try {
            $source = $onSite($sourceSiteId, ['op'=>'list','family'=>$family,'model'=>$model]);
            if (empty($source['ok']) || empty($source['items'])) { throw new \RuntimeException((string)($source['message'] ?? 'The source media library is empty')); }
            if ($selectedNames !== null) {
                $sourceByName = [];
                foreach ((array)$source['items'] as $item) { $sourceByName[(string)$item['name']] = $item; }
                $unknown = array_values(array_diff(array_keys($selectedNames), array_keys($sourceByName)));
                if ($unknown) { throw new \RuntimeException('Unknown source media file(s): ' . implode(', ', $unknown)); }
                $source['items'] = array_values(array_intersect_key($sourceByName, $selectedNames));
            }
            $results = [];
            $sourcePins = [];
            foreach ($targets as $targetId) {
                $copied = 0; $skipped = 0; $errors = [];
                $outcome = []; $mutationPending = false; $transferId = '';
                try {
                    $this->replicationProgress($targetId, 'Preparing hosted media', $family . '/' . $model);
                    $existing = $onSite($targetId, ['op'=>'list','family'=>$family,'model'=>$model]);
                    if (empty($existing['ok'])) { throw new \RuntimeException((string)($existing['message'] ?? 'Cannot list the destination media library')); }
                    $chunkBytes = min(524288, max(1,(int)($source['transfer_chunk_bytes'] ?? 98304)), max(1,(int)($existing['transfer_chunk_bytes'] ?? 98304)));
                    $names = array_fill_keys(array_map(static fn(array $item): string => (string)$item['name'], (array)($existing['items'] ?? [])), true);
                    foreach ($source['items'] as $item) {
                        $name = (string)$item['name'];
                        if (!$overwrite && isset($names[$name])) { $skipped++; continue; }
                        // Keep every encrypted API/forced-command envelope small.
                        // Whole-file base64 payloads exceed the federation channel
                        // long before they reach the media service.
                        $offset = 0;
                        $transferId = bin2hex(random_bytes(16));
                        do {
                            $download = $onSite($sourceSiteId, ['op'=>'download_chunk','family'=>$family,'model'=>$model,'name'=>$name,'offset'=>$offset,'chunk_bytes'=>$chunkBytes]+($sourcePins[$name] ?? []));
                            if (empty($download['ok'])) { throw new \RuntimeException((string)($download['message'] ?? "Could not read $name")); }
                            $pin=['expected_sha256'=>(string)($download['sha256'] ?? ''),'expected_size'=>(int)($download['size'] ?? -1)];
                            if (!preg_match('/^[a-f0-9]{64}$/D',$pin['expected_sha256']) || $pin['expected_size']<0 || $pin['expected_size']>8388608
                                || (isset($sourcePins[$name]) && $sourcePins[$name]!==$pin)) { throw new \RuntimeException('Source media content identity is missing or changed'); }
                            $sourcePins[$name]=$pin;
                            $bytes = (int)($download['bytes'] ?? 0);
                            $chunk=base64_decode((string)($download['content'] ?? ''),true);
                            if ($chunk===false || strlen($chunk)!==$bytes || $bytes<0 || $bytes>$chunkBytes || $offset+$bytes>$pin['expected_size']
                                || (!empty($download['eof']) && $offset+$bytes!==$pin['expected_size'])) { throw new \RuntimeException('Source media chunk bounds are invalid'); }
                            $mutationPending = true;
                            $upload = $onSite($targetId, [
                                'op'=>'upload_chunk','family'=>$family,'model'=>$model,'name'=>$name,
                                'offset'=>$offset,'content'=>(string)($download['content'] ?? ''),'final'=>!empty($download['eof']),
                                'transfer_id'=>$transferId,'overwrite'=>$overwrite,
                            ]+$pin);
                            if (empty($upload['ok'])) {
                                $outcome = $upload;
                                $mutationPending = false;
                                throw new \RuntimeException((string)($upload['message'] ?? "Could not write $name"));
                            }
                            if (($upload['transfer_id'] ?? '')!==$transferId || (int)($upload['bytes'] ?? -1)!==$bytes
                                || (!empty($download['eof']) && (empty($upload['complete']) || ($upload['sha256'] ?? '')!==$pin['expected_sha256'] || (int)($upload['size'] ?? -1)!==$pin['expected_size']))) {
                                throw new \RuntimeException('Destination media receipt does not verify the reviewed transfer');
                            }
                            $mutationPending = false;
                            $offset += $bytes;
                            $this->replicationProgress($targetId, 'Transferring hosted media: ' . $name, $family . '/' . $model . ': ' . $offset . ' / ' . $pin['expected_size'] . ' bytes');
                            if ($bytes === 0 && empty($download['eof'])) { throw new \RuntimeException("Media transfer stalled for $name"); }
                        } while (empty($download['eof']));
                        $copied++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                    if (!$outcome) {
                        $failure = \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::failedOperation($e, [$targetId], 'Hosted media', !$mutationPending);
                        $outcome = $failure['sites'][$targetId] ?? $failure;
                    }
                    if ($mutationPending) { $outcome['transfer_id'] = $transferId; }
                }
                $results[(string)$targetId] = ['ok'=>!$errors,'copied'=>$copied,'skipped'=>$skipped,'message'=>$errors?implode('; ',$errors):"Copied $copied file(s); skipped $skipped existing override(s)"] + $outcome;
                $this->replicationProgress($targetId, $errors ? 'Hosted media stopped' : 'Hosted media library completed', $results[(string)$targetId]['message'], $results[(string)$targetId]);
            }
            $ok = !array_filter($results, static fn(array $row): bool => empty($row['ok']));
            return ['ok'=>$ok,'sites'=>$results,'message'=>$ok?'PBX-hosted media synchronized to '.count($results).' site(s)':'One or more target media libraries failed'];
        } catch (\Throwable $e) {
            return \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::failedOperation($e, $targets, 'Hosted media source', true);
        }
    }

    /** Replicate retained legacy/special catalog state and its published BMP together. */
    private function syncSpecialMediaCatalogs(int $sourceSiteId, array $req): array
    {
        $targets = array_values(array_unique(array_filter(
            array_map('intval', (array)($req['target_site_ids'] ?? [])),
            static fn(int $id): bool => $id > 0 && $id !== $sourceSiteId
        )));
        if (!$targets || count($targets) > 20) {
            return ['ok'=>false,'message'=>'Choose between one and twenty different target sites','sites'=>[]];
        }
        $onSite = function (int $siteId, array $request): array {
            $request += ['family'=>'cisco_cnf', 'model'=>'all'];
            $request['site_id'] = $siteId;
            return $this->isSiteLocal($siteId)
                ? $this->mediaAssetOpLocal($request)
                : (array)$this->remoteQuery($siteId, 'mediaAssetOpLocal', [$request]);
        };
        try {
            $listed = $onSite($sourceSiteId, ['op'=>'special_catalog_list']);
            if (empty($listed['ok'])) { throw new \RuntimeException((string)($listed['message'] ?? 'Cannot list special catalogs')); }
            if ((int)($listed['transfer_protocol'] ?? 0) !== 2) { throw new \RuntimeException('Upgrade the source PBX before synchronizing special catalogs'); }
            $available = [];
            foreach ((array)($listed['catalogs'] ?? []) as $catalog) {
                $available[(string)$catalog['catalog_id']] = $catalog;
            }
            $selectionProvided = array_key_exists('set_ids', $req);
            $selected = array_keys(array_filter(
                $available,
                static fn(array $catalog): bool => !empty($catalog['complete'])
            ));
            if ($selectionProvided) {
                $raw = $req['set_ids'];
                if (is_string($raw)) { $raw = json_decode($raw, true); }
                if (!is_array($raw) || !$raw || count($raw) > 50) {
                    throw new \InvalidArgumentException('Choose between one and fifty special catalogs');
                }
                $selected = [];
                foreach ($raw as $id) {
                    if (!is_scalar($id)) { throw new \InvalidArgumentException('A selected special catalog ID is invalid'); }
                    $selected[] = self::specialCatalogId((string)$id);
                }
                $selected = array_values(array_unique($selected));
            }
            $unknown = array_values(array_diff($selected, array_keys($available)));
            if ($unknown) { throw new \InvalidArgumentException('Unknown special catalog ID(s): ' . implode(', ', $unknown)); }
            $incomplete = $selectionProvided ? array_values(array_filter(
                $selected,
                static fn(string $id): bool => empty($available[$id]['complete'])
            )) : [];
            if ($incomplete) { throw new \RuntimeException('Incomplete special catalog(s) cannot be synchronized: ' . implode(', ', $incomplete)); }
            if (!$selected) { throw new \RuntimeException('The source special catalog library is empty'); }
            $manifests = [];
            foreach ($selected as $catalogId) {
                $catalog = $onSite($sourceSiteId, ['op'=>'special_catalog_get','catalog_id'=>$catalogId,'include_source'=>0]);
                if (empty($catalog['ok']) || !is_array($catalog['manifest'] ?? null)) {
                    throw new \RuntimeException((string)($catalog['message'] ?? "Cannot read special catalog $catalogId"));
                }
                $manifests[$catalogId] = self::specialCatalogManifest($catalog['manifest'], $catalogId);
            }

            $results = [];
            foreach ($targets as $targetId) {
                $copied = 0; $skipped = 0; $errors = [];
                $outcome = []; $mutationPending = false; $transferId = '';
                try {
                    $targetList = $onSite($targetId, ['op'=>'special_catalog_list']);
                    if (empty($targetList['ok'])) { throw new \RuntimeException((string)($targetList['message'] ?? 'Cannot list target special catalogs')); }
                    if ((int)($targetList['transfer_protocol'] ?? 0) !== 2) { throw new \RuntimeException('Upgrade the destination PBX before synchronizing special catalogs'); }
                    $targetIds = array_column((array)($targetList['catalogs'] ?? []), null, 'catalog_id');
                    foreach ($selected as $catalogId) {
                        if (empty($req['overwrite']) && isset($targetIds[$catalogId])) { $skipped++; continue; }
                        $manifest = $manifests[$catalogId];
                        $transferId = bin2hex(random_bytes(16));
                        $destinationRevision = $targetIds[$catalogId]['manifest_sha256'] ?? 'absent';
                        foreach (['source','published'] as $role) {
                            $offset = 0; $pin = [];
                            do {
                                $chunk = $onSite($sourceSiteId, [
                                    'op'=>'special_catalog_download_chunk', 'catalog_id'=>$catalogId,
                                    'role'=>$role, 'offset'=>$offset,
                                    'manifest_sha256'=>$manifest[$role . '_sha256'],
                                ] + $pin);
                                if (empty($chunk['ok'])) { throw new \RuntimeException((string)($chunk['message'] ?? "Cannot read $catalogId $role")); }
                                $bytes = (int)($chunk['bytes'] ?? -1);
                                $data = base64_decode((string)($chunk['content'] ?? ''), true);
                                $identity = ['expected_sha256'=>(string)($chunk['sha256'] ?? ''),'expected_size'=>(int)($chunk['size'] ?? -1)];
                                if ($identity['expected_sha256'] !== $manifest[$role . '_sha256'] || $identity['expected_size'] < 1 || $identity['expected_size'] > 8388608
                                    || ($pin && $pin !== $identity) || $data === false || strlen($data) !== $bytes || $bytes < 0 || $bytes > 98304
                                    || $offset + $bytes > $identity['expected_size'] || (!empty($chunk['eof']) && $offset + $bytes !== $identity['expected_size'])) {
                                    throw new \RuntimeException('Special catalog source content or chunk bounds changed');
                                }
                                $pin = $identity;
                                $mutationPending = true;
                                $written = $onSite($targetId, [
                                    'op'=>'special_catalog_upload_chunk', 'catalog_id'=>$catalogId,
                                    'role'=>$role, 'offset'=>$offset,
                                    'transfer_id'=>$transferId,
                                    'content'=>(string)($chunk['content'] ?? ''),
                                    'final'=>!empty($chunk['eof']),
                                ] + $pin);
                                if (empty($written['ok'])) { $outcome = $written; $mutationPending = false; throw new \RuntimeException((string)($written['message'] ?? "Cannot write $catalogId $role")); }
                                if (($written['transfer_id'] ?? '') !== $transferId || (int)($written['bytes'] ?? -1) !== $bytes
                                    || (!empty($chunk['eof']) && (empty($written['complete']) || ($written['sha256'] ?? '') !== $pin['expected_sha256'] || (int)($written['size'] ?? -1) !== $pin['expected_size']))) {
                                    throw new \RuntimeException('Special catalog destination receipt is unverified');
                                }
                                $mutationPending = false;
                                if ($bytes === 0 && empty($chunk['eof'])) { throw new \RuntimeException("Special catalog transfer stalled for $catalogId $role"); }
                                $offset += $bytes;
                            } while (empty($chunk['eof']));
                        }
                        $mutationPending = true;
                        $activated = $onSite($targetId, [
                            'op'=>'special_catalog_activate', 'catalog_id'=>$catalogId,
                            'manifest'=>$manifest, 'overwrite'=>!empty($req['overwrite']),
                            'transfer_id'=>$transferId, 'expected_destination'=>$destinationRevision,
                        ]);
                        $mutationPending = false;
                        if (empty($activated['ok'])) { $outcome = $activated; throw new \RuntimeException((string)($activated['message'] ?? "Cannot activate special catalog $catalogId")); }
                        !empty($activated['skipped']) ? $skipped++ : $copied++;
                    }
                } catch (\Throwable $error) {
                    $errors[] = $error->getMessage();
                    if (!$outcome) {
                        $failure = \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::failedOperation($error, [$targetId], 'Special catalogs', !$mutationPending);
                        $outcome = $failure['sites'][$targetId] ?? $failure;
                    }
                    if ($mutationPending) { $outcome['transfer_id'] = $transferId; }
                }
                $results[(string)$targetId] = [
                    'ok'=>!$errors, 'copied'=>$copied, 'skipped'=>$skipped,
                    'message'=>$errors ? implode('; ', $errors) : "Copied $copied catalog(s); skipped $skipped existing catalog(s)",
                ] + $outcome;
            }
            $ok = !array_filter($results, static fn(array $row): bool => empty($row['ok']));
            return [
                'ok'=>$ok, 'set_ids'=>$selected, 'sites'=>$results,
                'message'=>$ok
                    ? 'Special catalogs synchronized to ' . count($results) . ' site(s)'
                    : 'One or more target special catalog libraries failed',
            ];
        } catch (\Throwable $error) {
            return \FreePBX\modules\Ucmprovisioner\ReplicationActivityService::failedOperation($error, $targets, 'Special catalog source', true);
        }
    }

    public function mediaAssetOpLocal(array $req): array
    {
        $siteId = (int)($req['site_id'] ?? 0) ?: $this->localSiteId();
        if ($siteId !== $this->localSiteId()) { throw new \InvalidArgumentException('HTTP media must execute on its owner PBX'); }
        $op = (string)($req['op'] ?? 'list');
        if ($op === 'reset_media_library' && !empty($req['apply']) && ($req['confirm'] ?? '') !== 'RESET_MEDIA_LIBRARY') {
            throw new \InvalidArgumentException('Media reset confirmation is invalid');
        }
        $readOnly = in_array($op, ['list','inventory','download','download_chunk','special_catalog_list','special_catalog_get','special_catalog_download_chunk','retirement_preview'], true)
            || ($op === 'reset_media_library' && empty($req['apply']));
        if ($readOnly) { return $this->mediaAssetOpLocalUnlocked($req,true); }
        return (new \FreePBX\modules\Ucmprovisioner\TftpStore($this))->withSiteLock($siteId, fn() => $this->mediaAssetOpLocalUnlocked($req,false));
    }

    private function mediaAssetOpLocalUnlocked(array $req, bool $readOnly): array
    {
        $siteId = (int)($req['site_id'] ?? 0) ?: $this->localSiteId();
        $op = (string)($req['op'] ?? 'list');
        $root = '/var/lib/asterisk/ucmprovisioner/media';
        $site = $this->getSite($siteId) ?: [];
        $publicBase = trim((string)$this->getSetting('media_base_url', $siteId, ''));
        if ($publicBase === '') {
            $publicBase = 'http://' . (string)($site['pbx_addr'] ?? '');
        }
        if ($op === 'inventory') {
            return $this->hostedMediaInventoryLocal($siteId, $root, $publicBase);
        }
        if ($op === 'reset_media_library') {
            $apply = !empty($req['apply']);
            if ($apply && !hash_equals('RESET_MEDIA_LIBRARY', (string)($req['confirm'] ?? ''))) {
                throw new \InvalidArgumentException('Media reset confirmation is invalid');
            }
            if (is_link($root)) { throw new \RuntimeException('Refusing to reset a symbolic-link media root'); }
            $inventory = is_dir($root)
                ? $this->hostedMediaInventoryLocal($siteId, $root, $publicBase)
                : ['ok'=>true,'items'=>[]];
            if (!$apply) {
                return [
                    'ok'=>true,'site_id'=>$siteId,'mode'=>'dry-run','items'=>(array)($inventory['items'] ?? []),
                    'message'=>count((array)($inventory['items'] ?? [])) . ' HTTP media item(s) would be quarantined',
                ];
            }
            $parent = dirname($root);
            $quarantineRoot = $parent . '/media-quarantine';
            if (is_link($quarantineRoot)
                || (!is_dir($quarantineRoot) && !mkdir($quarantineRoot, 0770, true))) {
                throw new \RuntimeException('Could not create the media quarantine directory');
            }
            $target = $quarantineRoot . '/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
            if (is_dir($root) && !rename($root, $target)) {
                throw new \RuntimeException('Could not move the media library into recoverable quarantine');
            }
            if (!mkdir($root, 0770, true) && !is_dir($root)) {
                if (is_dir($target)) { @rename($target, $root); }
                throw new \RuntimeException('Could not recreate the empty media library');
            }
            foreach ([$quarantineRoot, $target, $root] as $mediaDirectory) {
                if (is_dir($mediaDirectory)) { \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::apply($mediaDirectory, 0770); }
            }
            return [
                'ok'=>true,'site_id'=>$siteId,'mode'=>'apply','quarantine'=>$target,
                'moved_count'=>count((array)($inventory['items'] ?? [])),
                'message'=>'HTTP media library moved to recoverable quarantine and recreated empty',
            ];
        }
        $family = preg_replace('/[^a-z0-9_-]/i', '', (string)($req['family'] ?? ''));
        $model = preg_replace('/[^a-z0-9_.-]/i', '', (string)($req['model'] ?? 'all')) ?: 'all';
        if (!in_array($family, ['cisco_cnf','cisco_xml','yealink','grandstream'], true)) { throw new \InvalidArgumentException('Choose a supported phone family'); }
        // Validate the route before creating or changing any library directory.
        $base = \FreePBX\modules\Ucmprovisioner\MediaUrl::endpoint($publicBase, $family, $model);
        $dir = $root . '/' . $family . '/' . $model;
        if (!$readOnly) {
            foreach ([$root, dirname($dir), $dir] as $mediaDirectory) {
                \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::directory($mediaDirectory);
            }
        }
        if ($op === 'restore_retired') {
            return $this->hostedRetirementOperation($siteId,$dir,'',$req);
        }
        if ($op === 'retirement_preview') {
            return match ((string)($req['retire_op'] ?? '')) {
                'special_catalog_delete'=>$family==='cisco_cnf' && $model==='all'
                    ? $this->specialCatalogDelete($dir,(string)($req['catalog_id']??''),$siteId,$req)
                    : throw new \InvalidArgumentException('Special catalog retirement requires its exact library'),
                'special_asset_delete'=>$family==='cisco_cnf' && $model==='all'
                    ? $this->specialAssetDelete($dir,(string)($req['name']??''),$siteId,$req)
                    : throw new \InvalidArgumentException('Special asset retirement requires its exact library'),
                'delete'=>$this->hostedRetirementOperation($siteId,$dir,(string)($req['name']??''),$req),
                default=>throw new \InvalidArgumentException('Choose an exact HTTP retirement operation'),
            };
        }
        if (str_starts_with($op, 'special_catalog_') || str_starts_with($op, 'special_asset_')) {
            if ($family !== 'cisco_cnf' || $model !== 'all') {
                throw new \InvalidArgumentException('Special background catalogs currently use the cisco_cnf/all media library');
            }
            $syncItems = [];
            if (in_array($op, ['special_catalog_save','special_catalog_rename','special_catalog_delete'], true)) {
                $catalogId = (string)($req['catalog_id'] ?? '');
                if ($catalogId !== '') {
                    $guard = $this->configurationSyncCatalogEditGuard('special_catalog','special:' . $catalogId,$req);
                    if (empty($guard['ok'])) { return $guard; }
                    $syncItems = (array)($guard['items'] ?? []);
                }
            }
            $result = match ($op) {
                'special_catalog_list' => $this->specialCatalogLibrary($siteId, $root, $dir, $publicBase),
                'special_catalog_get' => $this->specialCatalogGet(
                    $dir,
                    $publicBase,
                    (string)($req['catalog_id'] ?? ''),
                    !array_key_exists('include_source', $req) || !empty($req['include_source'])
                ),
                'special_catalog_save' => $this->specialCatalogSave($dir, $publicBase, $req),
                'special_catalog_rename' => $this->specialCatalogRename($dir, $publicBase, $req),
                'special_catalog_delete' => $this->specialCatalogDelete($dir, (string)($req['catalog_id'] ?? ''), $siteId, $req),
                'special_catalog_download_chunk' => $this->specialCatalogDownloadChunk($dir, $req),
                'special_catalog_upload_chunk' => $this->specialCatalogUploadChunk($dir, $req),
                'special_catalog_activate' => $this->specialCatalogActivate($dir, $publicBase, $req),
                'special_asset_adopt' => $this->specialAssetAdopt($dir, $publicBase, $req),
                'special_asset_delete' => $this->specialAssetDelete($dir, (string)($req['name'] ?? ''), $siteId, $req),
                default => throw new \InvalidArgumentException('Unknown special catalog operation'),
            };
            if (!empty($result['ok']) && empty($result['retirement_preview']) && $syncItems) {
                $reason = $req;
                $reason['sync_edit_reason'] = (string)($reason['sync_edit_reason'] ?? '')
                    ?: ($op === 'special_catalog_delete' ? 'Deleted in Media Manager' : ($op === 'special_catalog_rename' ? 'Renamed in Media Manager' : 'Edited in Media Manager'));
                $this->configurationSyncCatalogEditPause($syncItems, $reason);
                $result['sync_paused'] = true;
                $result['sync_message'] = 'Automatic synchronization for this catalog was paused after the confirmed destination edit';
            }
            return $result;
        }
        if ($op === 'list') {
            $catalogs = $family === 'cisco_cnf' && $model === 'all'
                ? $this->specialCatalogList($dir, $publicBase)
                : ['catalogs'=>[]];
            $scan = (new \FreePBX\modules\Ucmprovisioner\HostedMediaInventory($root, $publicBase))->scan(
                $family,
                $model,
                self::hostedMediaOwners((array)($catalogs['catalogs'] ?? [])),
                $this->mediaAssetUsageIndex($siteId)
            );
            return [
                'ok'=>true,
                'items'=>$scan['items'],
                'transfer_chunk_bytes'=>524288,
                'base_url'=>$base,
                'issues'=>$scan['issues'],
                'truncated'=>$scan['truncated'],
            ];
        }
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename((string)($req['name'] ?? '')));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $family === 'cisco_cnf' ? ['bmp'] : ['png','jpg','jpeg','bmp','gif','wav','mp3','ogg'];
        if ($name === '' || !in_array($ext, $allowed, true)) { throw new \InvalidArgumentException('Unsupported media filename or format for this phone family'); }
        $file = $dir . '/' . $name;
        if ($op === 'download_chunk') {
            return \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::download($file,$req);
        }
        if ($op === 'upload_chunk') {
            return \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::upload($file,dirname($root).'/media-transfers',
                $siteId.':'.$family.':'.$model.':'.$name,$req,
                $family==='cisco_cnf'?static fn(string $bytes)=>\FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($bytes):null);
        }
        if ($op === 'download') {
            return ['ok'=>true,'name'=>$name,'content'=>base64_encode(\FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::read($file))];
        }
        if ($op === 'delete') { return $this->hostedRetirementOperation($siteId,$dir,$name,$req); }
        if ($op !== 'upload') { throw new \InvalidArgumentException('Unknown media operation'); }
        $data = base64_decode((string)($req['content'] ?? ''), true);
        if ($data === false || $data === '' || strlen($data) > 8 * 1024 * 1024) { throw new \InvalidArgumentException('Media upload is empty, invalid, or larger than 8 MiB'); }
        // The browser editor exports PNG. Convert that render into the legacy
        // phone's required 90x56, 256-colour Windows BMP before publishing it.
        if ($family === 'cisco_cnf' && substr($data, 0, 8) === "\x89PNG\r\n\x1a\n") {
            if (!function_exists('imagecreatefromstring') || !function_exists('imagebmp')) { throw new \RuntimeException('PHP GD with BMP support is required for legacy Cisco logo conversion'); }
            $src = @imagecreatefromstring($data);
            if (!$src) { throw new \InvalidArgumentException('The rendered logo image is invalid'); }
            $dst = imagecreatetruecolor(90, 56);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, 90, 56, imagesx($src), imagesy($src));
            imagefilter($dst, IMG_FILTER_GRAYSCALE);
            // The BMP container accepts a palette, but the 7940/7960 LCD has
            // only four grayscale levels. Dither to the phone, not the file.
            imagetruecolortopalette($dst, true, 4);
            ob_start(); imagebmp($dst, null, false); $data = (string)ob_get_clean(); imagedestroy($src); imagedestroy($dst);
        }
        if ($family === 'cisco_cnf') {
            \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($data);
        }
        // Managed wallpaper names already include catalog ownership and full
        // content identity. Keep the manifest's name through HTTP publication.
        if (preg_match('/^w[a-f0-9]{12}\.(?:png|jpg)$/D', $name) !== 1) {
            $name = \FreePBX\modules\Ucmprovisioner\AssetFilename::content(
                in_array($ext, ['wav','mp3','ogg'], true) ? 'r' : 'h', $data, $ext === 'jpeg' ? 'jpg' : $ext
            );
        }
        $file = $dir . '/' . $name;
        \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::write($file, $data);
        clearstatcache(true, $file);
        $size = (int)filesize($file);
        $mtime = (int)filemtime($file);
        return [
            'ok'=>true,
            'name'=>$name,
            'url'=>\FreePBX\modules\Ucmprovisioner\MediaUrl::asset($publicBase, $family, $model, $name, $mtime . '-' . $size),
            'preview_url'=>\FreePBX\modules\Ucmprovisioner\MediaUrl::preview($publicBase, $family, $model, $name, $mtime . '-' . $size),
            'message'=>'Media uploaded to the selected PBX',
        ];
    }

    /** @param array<int,array<string,mixed>> $catalogs @param array<int,array<string,mixed>> $wallpaperSets @return array<string,array<string,mixed>> */
    private static function hostedMediaOwners(array $catalogs, array $wallpaperSets = []): array
    {
        $owners = [];
        foreach ($catalogs as $catalog) {
            $filename = basename((string)($catalog['published_name'] ?? ''));
            if ($filename === '') { continue; }
            $owners['cisco_cnf/all/' . strtolower($filename)] = [
                'catalog_id'=>(string)($catalog['catalog_id'] ?? ''),
                'catalog_name'=>(string)($catalog['name'] ?? ''),
                'revision'=>(string)($catalog['revision'] ?? ''),
                'state'=>!empty($catalog['complete']) ? 'current' : 'unavailable',
                'sha256'=>(string)($catalog['published_sha256'] ?? ''),
            ];
        }
        foreach ($wallpaperSets as $catalog) {
            $catalogId = (string)($catalog['id'] ?? '');
            $catalogName = (string)($catalog['name'] ?? $catalogId);
            $catalogRevision = (string)($catalog['updated'] ?? '');
            $claim = static function (array $variant, string $catalogId, string $catalogName, string $catalogRevision, bool $alias = false) use (&$owners): void {
                // Disabled profiles retain owned URLs for existing phones.
                $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfileByKey((string)($variant['profile'] ?? ''));
                if (!is_array($profile) || ($profile['delivery'] ?? '') !== 'hosted_http') { return; }
                $filename = basename((string)($variant['full'] ?? ''));
                if ($filename === '') { return; }
                foreach ((array)($variant['models'] ?? []) as $model) {
                    $model = strtolower(trim((string)$model));
                    if ($model === '') { continue; }
                    $key = (string)$profile['family'] . '/' . $model . '/' . strtolower($filename);
                    // A retained alias must never demote the current output when
                    // an unchanged image is saved again under the same name.
                    if ($alias && isset($owners[$key])) { continue; }
                    $owners[$key] = [
                        'catalog_id'=>$catalogId,
                        'catalog_name'=>$catalogName,
                        'revision'=>$catalogRevision,
                        'state'=>$alias ? 'compatibility' : (empty($variant['enabled']) ? 'disabled' : (!empty($variant['current']) ? 'current' : 'unavailable')),
                        'sha256'=>(string)($variant['full_sha256'] ?? ''),
                    ];
                }
            };
            foreach ((array)($catalog['variants'] ?? []) as $variant) {
                $claim((array)$variant, $catalogId, $catalogName, $catalogRevision);
            }
            // Renaming/replacing a catalog deliberately retains its old hosted
            // filenames as compatibility aliases. They are still served by the
            // catalog and must remain verified/owned in the unified inventory.
            foreach ((array)($catalog['aliases'] ?? []) as $alias) {
                $alias = (array)$alias;
                $alias['enabled'] = true;
                $claim($alias, $catalogId, $catalogName, $catalogRevision, true);
                $profile = \FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfileByKey((string)($alias['profile'] ?? ''));
                if (!is_array($profile) || ($profile['delivery'] ?? '') !== 'hosted_http') { continue; }
                $thumb = basename((string)($alias['thumbnail'] ?? ''));
                if ($thumb === '') { continue; }
                foreach ((array)($alias['models'] ?? []) as $model) {
                    $model = strtolower(trim((string)$model));
                    if ($model === '') { continue; }
                    $owners[(string)$profile['family'] . '/' . $model . '/' . strtolower($thumb)] = [
                        'catalog_id'=>$catalogId,
                        'catalog_name'=>$catalogName,
                        'revision'=>$catalogRevision,
                        'state'=>'compatibility',
                        'sha256'=>(string)($alias['thumbnail_sha256'] ?? ''),
                    ];
                }
            }
        }
        return $owners;
    }

    /** @return array<string,mixed> */
    private function hostedMediaInventoryLocal(int $siteId, string $root, string $publicBase): array
    {
        $catalogResult = ['catalogs'=>[], 'issues'=>[]];
        $legacyDirectory = $root . '/cisco_cnf/all';
        if (is_dir($legacyDirectory)) {
            try { $catalogResult = $this->specialCatalogList($legacyDirectory, $publicBase); }
            catch (\Throwable $error) {
                $catalogResult['issues'][] = ['catalog_id'=>'', 'message'=>$error->getMessage()];
            }
        }
        $wallpaperResult = ['sets'=>[], 'issues'=>[]];
        $wallpaperSets = [];
        try {
            $wallpaperResult = (array)$this->tftpOp(['op'=>'wallpaper_set_list','site_id'=>$siteId]);
            if (empty($wallpaperResult['ok'])) {
                $wallpaperResult['issues'][] = ['set_id'=>'', 'message'=>(string)($wallpaperResult['message'] ?? 'Wallpaper catalogs could not be inspected')];
            } else {
                // wallpaper_set_list intentionally returns rendered variants only.
                // Pull the immutable manifest for each set so retained hosted HTTP
                // aliases are included in the ownership graph as well.
                $wallpaperSets = is_array($wallpaperResult['sets'] ?? null) ? $wallpaperResult['sets'] : [];
                foreach ($wallpaperSets as &$wallpaperSet) {
                    $setId = trim((string)($wallpaperSet['id'] ?? ''));
                    if ($setId === '') { continue; }
                    try {
                        $details = (array)$this->tftpOp([
                            'op'=>'wallpaper_set_get', 'site_id'=>$siteId, 'set_id'=>$setId,
                        ]);
                        $manifest = is_array($details['manifest'] ?? null) ? $details['manifest'] : [];
                        if ($manifest) {
                            $wallpaperSet['aliases'] = (array)($manifest['aliases'] ?? []);
                        }
                    } catch (\Throwable $aliasError) {
                        $wallpaperResult['issues'][] = [
                            'set_id'=>$setId,
                            'message'=>'Wallpaper compatibility aliases could not be inspected: ' . $aliasError->getMessage(),
                        ];
                    }
                }
                unset($wallpaperSet);
            }
        } catch (\Throwable $error) {
            $wallpaperResult['issues'][] = ['set_id'=>'', 'message'=>$error->getMessage()];
        }
        $scan = (new \FreePBX\modules\Ucmprovisioner\HostedMediaInventory($root, $publicBase))->scan(
            null,
            null,
            self::hostedMediaOwners((array)($catalogResult['catalogs'] ?? []), $wallpaperSets),
            $this->mediaAssetUsageIndex($siteId)
        );
        $issues = array_merge((array)($catalogResult['issues'] ?? []), (array)($wallpaperResult['issues'] ?? []), (array)$scan['issues']);
        return [
            'ok'=>true,
            'site_id'=>$siteId,
            'items'=>$scan['items'],
            'catalog_assets'=>$scan['items'],
            'issues'=>$issues,
            'truncated'=>$scan['truncated'],
            'source'=>'http_media_library',
            'background_choices'=>array_values(array_filter($scan['items'],
                static fn(array $item): bool => $item['kind'] === 'background' && $item['selectable'])),
            'message'=>'Phone-facing HTTP media inventory loaded directly from the Media Manager libraries.',
        ];
    }

    /** @return array<string,mixed> */
    private function specialCatalogLibrary(int $siteId, string $root, string $dir, string $publicBase): array
    {
        $catalogResult = $this->specialCatalogList($dir, $publicBase);
        $scan = (new \FreePBX\modules\Ucmprovisioner\HostedMediaInventory($root, $publicBase))->scan(
            'cisco_cnf',
            'all',
            self::hostedMediaOwners((array)($catalogResult['catalogs'] ?? [])),
            $this->mediaAssetUsageIndex($siteId)
        );
        $catalogResult['unmanaged_items'] = array_values(array_filter(
            (array)$scan['items'],
            static fn(array $item): bool => ($item['kind'] ?? '') === 'background' && empty($item['managed'])
        ));
        $catalogResult['served_items'] = $scan['items'];
        $catalogResult['issues'] = array_merge((array)($catalogResult['issues'] ?? []), (array)$scan['issues']);
        $catalogResult['truncated'] = $scan['truncated'];
        return $catalogResult;
    }

    /** Convert an existing loose Cisco CNF BMP into a retained catalog without changing its device URL. */
    private function specialAssetAdopt(string $dir, string $publicBase, array $request): array
    {
        $name = self::specialLooseBmpName((string)($request['name'] ?? ''));
        $path = $dir . '/' . $name;
        if (!is_file($path) || !is_readable($path) || filesize($path) > 8 * 1024 * 1024) {
            return ['ok'=>false, 'message'=>'The loose Cisco BMP is missing, unreadable, or exceeds 8 MiB'];
        }
        foreach ((array)($this->specialCatalogList($dir, $publicBase)['catalogs'] ?? []) as $catalog) {
            if (strcasecmp((string)($catalog['published_name'] ?? ''), $name) === 0) {
                return ['ok'=>false, 'message'=>"$name already belongs to a retained special catalog"];
            }
        }
        $published = (string)file_get_contents($path);
        \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($published);
        $id = self::specialCatalogId((string)($request['catalog_id'] ?? pathinfo($name, PATHINFO_FILENAME)));
        if (is_dir($dir . '/.catalogs/' . $id)) {
            return ['ok'=>false, 'message'=>"Special catalog $id already exists"];
        }
        $source = self::specialCatalogSource(base64_encode($published), $name);
        $manifest = self::specialCatalogManifest([
            'id'=>$id,
            'name'=>(string)($request['catalog_name'] ?? pathinfo($name, PATHINFO_FILENAME)),
            'source_name'=>$source['name'],
            'source_file'=>$source['file'],
            'source_mime'=>$source['mime'],
            'source_sha256'=>$source['sha256'],
            'published_name'=>$name,
            'published_sha256'=>hash('sha256', $published),
            'transform'=>['zoom'=>100, 'x'=>0, 'y'=>0],
            'settings'=>['width'=>90, 'height'=>56, 'tones'=>4],
            'revision'=>1,
            'updated'=>time(),
        ], $id);
        $catalogDirectory = $dir . '/.catalogs/' . $id;
        self::replaceSpecialCatalogFiles([
            $catalogDirectory . '/' . $manifest['source_file']=>$source['content'],
            $catalogDirectory . '/manifest.json'=>json_encode(
                $manifest,
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ) . "\n",
        ]);
        return [
            'ok'=>true,
            'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $manifest),
            'manifest'=>$manifest,
            'message'=>"$name adopted into a retained special catalog; its phone URL was unchanged",
        ];
    }

    /** Delete only an unclaimed loose Cisco CNF BMP; catalog-owned outputs fail closed. */
    private function specialAssetDelete(string $dir, string $name, int $siteId = 0, array $request = []): array
    {
        if ($siteId<1) { return ['ok'=>false,'message'=>'Captured site and reference-bound retirement preview are required']; }
        $name = self::specialLooseBmpName($name);
        foreach ((array)($this->specialCatalogList($dir, 'http://localhost')['catalogs'] ?? []) as $catalog) {
            if (strcasecmp((string)($catalog['published_name'] ?? ''), $name) === 0) {
                return ['ok'=>false, 'message'=>"$name belongs to a retained catalog; delete the catalog instead"];
            }
        }
        return $this->hostedRetirementOperation($siteId,$dir,$name,$request + ['family'=>'cisco_cnf','model'=>'all']);
    }

    /** Captured-site reference preview and retained HTTP file/catalog transactions. */
    /** Internal catalog coordinator; never exposed as an RPC method. */
    public function wallpaperHostedRetirementLocal(int $siteId,string $family,string $model): \FreePBX\modules\Ucmprovisioner\HostedAssetRetirement
    {
        if ($siteId!==$this->localSiteId() || !in_array($family,['yealink','grandstream'],true)
            || !preg_match('/^[a-z0-9_-]{1,64}$/D',$model)) { throw new \InvalidArgumentException('Wallpaper retirement must execute on its owner PBX'); }
        return new \FreePBX\modules\Ucmprovisioner\HostedAssetRetirement($this->db,'/var/lib/asterisk/ucmprovisioner/media/'.$family.'/'.$model,$family,$model);
    }

    private function hostedRetirementOperation(int $siteId,string $dir,string $name,array $request,?string $catalogId=null): array
    {
        try {
            $service=new \FreePBX\modules\Ucmprovisioner\HostedAssetRetirement($this->getDb(),$dir,(string)($request['family']??'cisco_cnf'),(string)($request['model']??'all'));
            $store=new \FreePBX\modules\Ucmprovisioner\TftpStore($this);
            if (($request['op']??'')==='restore_retired') {
                return $store->withSiteLock($siteId,fn():array=>$service->restore($siteId,(string)($request['recovery_id']??'')));
            }
            $action=function() use($service,$store,$siteId,$name,$request,$catalogId):array {
                $asset=$service->asset($name);
                $external=$store->assetReferenceSnapshot($siteId,null,[$asset]);
                $external['subject_asset_key']=$asset['asset_key'];
                $preview=$service->preview($siteId,$name,$external,$catalogId);
                if (($request['op']??'')==='retirement_preview'||empty($request['expected_revision'])) {
                    return ['ok'=>true,'changed'=>false,'retirement_preview'=>$preview,'message'=>'Review exact dependencies before retirement. No file was changed.'];
                }
                return $service->retire($siteId,$name,$external,(string)$request['expected_revision'],$catalogId);
            };
            return empty($request['expected_revision'])||($request['op']??'')==='retirement_preview'?$action():$store->withSiteLock($siteId,$action);
        } catch (\Throwable $error) { return ['ok'=>false,'message'=>$error->getMessage()]; }
    }

    private static function specialLooseBmpName(string $name): string
    {
        $name = basename(trim($name));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.bmp$/iD', $name) !== 1) {
            throw new \InvalidArgumentException('Choose a safe loose Cisco BMP filename');
        }
        return $name;
    }

    /** @return array<string,mixed> */
    private static function mediaAudioMetadata(string $family, string $model, string $path, string $extension): array
    {
        $header = (string)@file_get_contents($path, false, null, 0, 12);
        $signatureOk = match ($extension) {
            'wav'=>strlen($header) >= 12 && substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE',
            'ogg'=>str_starts_with($header, 'OggS'),
            'mp3'=>str_starts_with($header, 'ID3')
                || (strlen($header) >= 2 && ord($header[0]) === 0xff && (ord($header[1]) & 0xe0) === 0xe0),
            default=>false,
        };
        $labels = ['cisco_xml'=>'Cisco XML', 'yealink'=>'Yealink', 'grandstream'=>'Grandstream'];
        $compatibility = ($labels[$family] ?? $family) . ($model === 'all' ? ' managed library' : ' ' . $model);
        return [
            'media_kind'=>'audio',
            'format'=>$extension,
            'compatibility'=>$compatibility,
            'compatibility_family'=>$family,
            'compatibility_model'=>$model,
            'validation_status'=>$signatureOk ? 'verified' : 'invalid',
            'validation_message'=>$signatureOk
                ? strtoupper($extension) . ' container signature verified; handset codec limits remain model and firmware specific'
                : 'File content does not match its .' . $extension . ' extension',
        ];
    }

    /** @return array<string,string[]> keyed by lower-case media filename */
    private function mediaAssetUsageIndex(int $siteId): array
    {
        $index = [];
        $collect = static function ($value, string $label) use (&$collect, &$index): void {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) { $collect($decoded, $label); return; }
                $path = parse_url(trim($value), PHP_URL_PATH);
                $name = basename(str_replace('\\', '/', is_string($path) && $path !== '' ? $path : trim($value)));
                if (!in_array(
                    strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                    ['png','jpg','jpeg','gif','bmp','wav','mp3','ogg'],
                    true
                )) { return; }
                $index[strtolower($name)][$label] = true;
                return;
            }
            if (!is_array($value)) { return; }
            foreach ($value as $child) { $collect($child, $label); }
        };
        // A partial usage index would falsely label managed assets as unused. Let a
        // schema/database failure reach the caller instead of presenting bad data.
        $templates = $this->db->prepare('SELECT id,name,setting_overrides FROM ucmp_templates WHERE site_id=? OR site_id IS NULL');
        $templates->execute([$siteId]);
        foreach ($templates->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $collect($row['setting_overrides'], 'Template: ' . (string)$row['name']);
        }
        $settings = $this->db->prepare('SELECT skey,sval FROM ucmp_settings WHERE site_id=? OR site_id IS NULL');
        $settings->execute([$siteId]);
        foreach ($settings->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $collect($row['sval'], 'Site setting: ' . (string)$row['skey']);
        }
        foreach ($index as $name=>$labels) {
            $index[$name] = array_keys($labels);
            sort($index[$name], SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $index;
    }

    private static function specialCatalogId(string $id): string
    {
        $id = trim($id);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Special catalog ID must use 1-40 letters, numbers, underscores or hyphens');
        }
        return $id;
    }

    private static function specialCatalogName(string $name): string
    {
        $name = trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name));
        if ($name === '') { throw new \InvalidArgumentException('Special catalog name is required'); }
        return function_exists('mb_substr') ? mb_substr($name, 0, 64) : substr($name, 0, 64);
    }

    /** @param mixed $value @return array{zoom:int,x:int,y:int} */
    private static function specialCatalogTransform($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        $value = is_array($value) ? $value : [];
        return [
            'zoom'=>max(10, min(400, (int)($value['zoom'] ?? 100))),
            'x'=>max(-100, min(100, (int)($value['x'] ?? 0))),
            'y'=>max(-100, min(100, (int)($value['y'] ?? 0))),
        ];
    }

    /** @param mixed $value @return array<string,bool|int|float|string> */
    private static function specialCatalogSettings($value): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if ($value === null || $value === []) { return []; }
        if (!is_array($value) || array_is_list($value) || count($value) > 32) {
            throw new \InvalidArgumentException('Special catalog settings must be an object with at most 32 fields');
        }
        $settings = [];
        foreach ($value as $key=>$item) {
            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,31}$/D', (string)$key) !== 1 || !is_scalar($item)) {
                throw new \InvalidArgumentException('Special catalog settings contain an invalid field');
            }
            if (is_string($item)) {
                $item = trim($item);
                if (strlen($item) > 128 || preg_match('/[\x00-\x1f\x7f]/', $item)) {
                    throw new \InvalidArgumentException('Special catalog settings contain unsafe text');
                }
            }
            $settings[(string)$key] = $item;
        }
        ksort($settings, SORT_STRING);
        return $settings;
    }

    /** @return array{content:string,extension:string,mime:string,name:string,sha256:string,file:string} */
    private static function specialCatalogSource(string $encoded, string $originalName): array
    {
        $content = base64_decode($encoded, true);
        if ($content === false || $content === '' || strlen($content) > 8 * 1024 * 1024) {
            throw new \InvalidArgumentException('Special catalog source is empty, invalid, or larger than 8 MiB');
        }
        if (str_starts_with($content, "\x89PNG\r\n\x1a\n")) { $extension = 'png'; $mime = 'image/png'; }
        elseif (str_starts_with($content, "\xff\xd8")) { $extension = 'jpg'; $mime = 'image/jpeg'; }
        elseif (str_starts_with($content, 'BM')) { $extension = 'bmp'; $mime = 'image/bmp'; }
        else { throw new \InvalidArgumentException('Special catalog source must be a PNG, JPEG, or BMP image'); }
        $originalName = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($originalName)) ?: "source.$extension";
        $originalName = substr($originalName, 0, 255);
        $sha256 = hash('sha256', $content);
        return [
            'content'=>$content, 'extension'=>$extension, 'mime'=>$mime,
            'name'=>$originalName, 'sha256'=>$sha256,
            'file'=>\FreePBX\modules\Ucmprovisioner\AssetFilename::fromHash('o', $sha256, $extension),
        ];
    }

    /** @param mixed $value @return array<string,mixed> */
    private static function specialCatalogManifest($value, string $expectedId = ''): array
    {
        if (is_string($value)) { $value = json_decode($value, true); }
        if (!is_array($value) || array_is_list($value)) { throw new \InvalidArgumentException('Special catalog manifest is invalid'); }
        $id = self::specialCatalogId((string)($value['id'] ?? $expectedId));
        if ($expectedId !== '' && !hash_equals(self::specialCatalogId($expectedId), $id)) {
            throw new \InvalidArgumentException('Special catalog manifest identity does not match the request');
        }
        $sourceFile = (string)($value['source_file'] ?? '');
        $sourceHash = strtolower((string)($value['source_sha256'] ?? ''));
        $published = (string)($value['published_name'] ?? '');
        $publishedHash = strtolower((string)($value['published_sha256'] ?? ''));
        if (preg_match('/^(?:o|source-)[a-f0-9]{12}\.(?:png|jpg|bmp)$/D', $sourceFile) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
            || basename($published) !== $published
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.bmp$/iD', $published) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $publishedHash) !== 1) {
            throw new \InvalidArgumentException('Special catalog manifest contains an unsafe file or hash');
        }
        $sourceMime = (string)($value['source_mime'] ?? '');
        if (!in_array($sourceMime, ['image/png','image/jpeg','image/bmp'], true)) {
            throw new \InvalidArgumentException('Special catalog source type is invalid');
        }
        $sourceExtension = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));
        $expectedMime = ['png'=>'image/png','jpg'=>'image/jpeg','bmp'=>'image/bmp'][$sourceExtension] ?? '';
        if (!in_array($sourceFile, [
                'source-' . substr($sourceHash, 0, 12) . '.' . $sourceExtension,
                \FreePBX\modules\Ucmprovisioner\AssetFilename::fromHash('o', $sourceHash, $sourceExtension),
            ], true)
            || !hash_equals($expectedMime, $sourceMime)) {
            throw new \InvalidArgumentException('Special catalog source identity does not match its content hash and type');
        }
        $sourceName = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename((string)($value['source_name'] ?? ''))) ?: $sourceFile;
        return [
            'version'=>1,
            'id'=>$id,
            'name'=>self::specialCatalogName((string)($value['name'] ?? $id)),
            'source_name'=>substr($sourceName, 0, 255),
            'source_file'=>$sourceFile,
            'source_mime'=>$sourceMime,
            'source_sha256'=>$sourceHash,
            'published_name'=>$published,
            'published_sha256'=>$publishedHash,
            'transform'=>self::specialCatalogTransform($value['transform'] ?? []),
            'settings'=>self::specialCatalogSettings($value['settings'] ?? []),
            'revision'=>max(1, (int)($value['revision'] ?? 1)),
            'updated'=>max(1, (int)($value['updated'] ?? time())),
        ];
    }

    /** @return array<string,mixed> */
    private function specialCatalogReadManifest(string $dir, string $catalogId): array
    {
        $catalogId = self::specialCatalogId($catalogId);
        $path = $dir . '/.catalogs/' . $catalogId . '/manifest.json';
        $content = @file_get_contents($path);
        if (!is_string($content)) { throw new \RuntimeException("Special catalog $catalogId was not found"); }
        return self::specialCatalogManifest($content, $catalogId);
    }

    /** Return null only when no manifest exists; malformed retained state is an error. */
    private function specialCatalogReadOptionalManifest(string $dir, string $catalogId): ?array
    {
        $catalogId = self::specialCatalogId($catalogId);
        $path = $dir . '/.catalogs/' . $catalogId . '/manifest.json';
        return is_file($path) ? $this->specialCatalogReadManifest($dir, $catalogId) : null;
    }

    /** @return array<string,mixed> */
    private function specialCatalogDescribe(string $dir, string $publicBase, array $manifest): array
    {
        $catalogDir = $dir . '/.catalogs/' . $manifest['id'];
        $source = $catalogDir . '/' . $manifest['source_file'];
        $published = $dir . '/' . $manifest['published_name'];
        $issues = [];
        if (!is_file($source) || !hash_equals((string)$manifest['source_sha256'], (string)@hash_file('sha256', $source))) {
            $issues[] = 'Original source is missing or changed';
        }
        if (!is_file($published) || !hash_equals((string)$manifest['published_sha256'], (string)@hash_file('sha256', $published))) {
            $issues[] = 'Published BMP is missing or changed';
        } elseif (filesize($published) > 8 * 1024 * 1024) {
            $issues[] = 'Published BMP exceeds the media limit';
        } else {
            try { \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp((string)file_get_contents($published)); }
            catch (\Throwable $error) { $issues[] = $error->getMessage(); }
        }
        $version = substr((string)$manifest['published_sha256'], 0, 16);
        $url = \FreePBX\modules\Ucmprovisioner\MediaUrl::asset($publicBase, 'cisco_cnf', 'all', (string)$manifest['published_name'], $version);
        if (!str_starts_with(strtolower($url), 'http://')) {
            throw new \RuntimeException('Cisco CNF special backgrounds require a plain HTTP media base URL');
        }
        return [
            'catalog_id'=>(string)$manifest['id'],
            'manifest_sha256'=>hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'name'=>(string)$manifest['name'],
            'source_name'=>(string)$manifest['source_name'],
            'source_mime'=>(string)$manifest['source_mime'],
            'published_name'=>(string)$manifest['published_name'],
            'published_sha256'=>(string)$manifest['published_sha256'],
            'transform'=>(array)$manifest['transform'],
            'settings'=>(array)$manifest['settings'],
            'revision'=>(int)$manifest['revision'],
            'updated'=>(int)$manifest['updated'],
            'complete'=>!$issues,
            'issues'=>$issues,
            'url'=>$url,
            'preview_url'=>\FreePBX\modules\Ucmprovisioner\MediaUrl::preview($publicBase, 'cisco_cnf', 'all', (string)$manifest['published_name'], $version),
        ];
    }

    /** @return array<string,mixed> */
    private function specialCatalogList(string $dir, string $publicBase): array
    {
        $root = $dir . '/.catalogs';
        // Listing is read-only; the shared writer creates a correctly grouped catalog.
        if (!is_dir($root)) { return ['ok'=>true, 'catalogs'=>[], 'issues'=>[], 'transfer_protocol'=>2]; }
        $catalogs = []; $issues = [];
        foreach ((array)@scandir($root) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) { continue; }
            try {
                $manifest = $this->specialCatalogReadManifest($dir, (string)$entry);
                $catalogs[] = $this->specialCatalogDescribe($dir, $publicBase, $manifest);
            } catch (\Throwable $error) {
                $issues[] = ['catalog_id'=>(string)$entry, 'message'=>$error->getMessage()];
            }
        }
        usort($catalogs, static fn(array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        return ['ok'=>true,'catalogs'=>$catalogs,'issues'=>$issues,'transfer_protocol'=>2];
    }

    /** @return array<string,mixed> */
    private function specialCatalogGet(string $dir, string $publicBase, string $catalogId, bool $includeSource): array
    {
        $manifest = $this->specialCatalogReadManifest($dir, $catalogId);
        $result = [
            'ok'=>true,
            'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $manifest),
            'manifest'=>$manifest,
        ];
        if ($includeSource) {
            $path = $dir . '/.catalogs/' . $manifest['id'] . '/' . $manifest['source_file'];
            if (!is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                return ['ok'=>false,'message'=>'Special catalog source is missing or exceeds 8 MiB'];
            }
            $bytes = @file_get_contents($path,false,null,0,8*1024*1024+1);
            if (!is_string($bytes) || strlen($bytes)>8*1024*1024 || !hash_equals((string)$manifest['source_sha256'],hash('sha256',$bytes))) {
                return ['ok'=>false,'message'=>'Special catalog source no longer matches its retained manifest'];
            }
            $result['source'] = base64_encode($bytes);
        }
        return $result;
    }

    /** Stage a bounded file set; preserve uncertain publication for reconciliation. @param array<string,string> $files */
    private static function replaceSpecialCatalogFiles(array $files): void
    {
        $token = bin2hex(random_bytes(6)); $staged = []; $backups = []; $activated = [];
        $attempted=false;
        try {
            foreach ($files as $path=>$content) {
                $parent = dirname($path);
                \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::directory($parent);
                \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::assertNoLinks($path);
                if (file_exists($path)&&!is_file($path)) { throw new \RuntimeException('Special catalog target is not a regular file'); }
                if (is_file($path)) {
                    \FreePBX\modules\Ucmprovisioner\AssetFilename::assertSameContent(basename($path), hash('sha256', $content), (string)hash_file('sha256', $path));
                }
                $tmp = $path . '.ucmp-tmp-' . $token;
                $staged[$path] = $tmp;
                \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::write($tmp,$content);
            }
            foreach (array_keys($files) as $path) {
                if (!is_file($path)) { continue; }
                $backup = $path . '.ucmp-bak-' . $token;
                $attempted=true;
                if (!@rename($path, $backup)) { throw new \RuntimeException("Cannot preserve $path"); }
                $backups[$path] = $backup;
            }
            foreach ($staged as $path=>$tmp) {
                $attempted=true;
                if (!@rename($tmp, $path)) { throw new \RuntimeException("Cannot activate $path"); }
                $activated[$path] = true; unset($staged[$path]);
                if (!hash_equals(hash('sha256',$files[$path]),(string)@hash_file('sha256',$path))) {
                    throw new \RuntimeException('Special catalog publication readback failed');
                }
            }
        } catch (\Throwable $error) {
            if ($attempted) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Special catalog publication requires reconciliation: '.$error->getMessage(),
                    ['ok'=>false,'status'=>'unknown','recovery_required'=>true,'transaction_id'=>$token,
                     'activated'=>array_keys($activated),'backups'=>$backups,'staged'=>$staged]);
            }
            foreach ($staged as $tmp) { @unlink($tmp); }
            throw $error;
        }
        foreach ($backups as $backup) {
            if (!@unlink($backup)) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException('Special catalog published; before-state cleanup requires reconciliation',
                    ['ok'=>false,'status'=>'partial','publication_completed'=>true,'recovery_required'=>true,'transaction_id'=>$token,'backups'=>$backups]);
            }
        }
    }

    /** @return array<string,mixed> */
    private function specialCatalogSave(string $dir, string $publicBase, array $request): array
    {
        $id = self::specialCatalogId((string)($request['catalog_id'] ?? ''));
        $intent = strtolower(trim((string)($request['intent'] ?? 'update')));
        if (!in_array($intent, ['create','update'], true)) { throw new \InvalidArgumentException('Special catalog save intent must be create or update'); }
        $existing = $this->specialCatalogReadOptionalManifest($dir, $id);
        if ($intent === 'create' && $existing) { throw new \InvalidArgumentException("Special catalog $id already exists"); }
        if ($intent === 'update' && !$existing) { throw new \InvalidArgumentException("Special catalog $id does not exist"); }
        if ($existing && !hash_equals(hash('sha256',json_encode($existing,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            (string)($request['expected_manifest_sha256'] ?? ''))) {
            return ['ok'=>false,'conflict'=>true,'message'=>'Special catalog changed or its editor revision is missing; reopen it before saving'];
        }

        $source = null;
        if ((string)($request['source'] ?? '') !== '') {
            $source = self::specialCatalogSource((string)$request['source'], (string)($request['source_name'] ?? 'source'));
        } elseif ($existing) {
            $path = $dir . '/.catalogs/' . $id . '/' . $existing['source_file'];
            $bytes = @file_get_contents($path,false,null,0,8*1024*1024+1);
            if (!is_string($bytes) || strlen($bytes)>8*1024*1024 || !hash_equals((string)$existing['source_sha256'],hash('sha256',$bytes))) { throw new \RuntimeException('Existing special catalog source is missing or changed'); }
            $source = [
                'content'=>$bytes, 'mime'=>$existing['source_mime'], 'name'=>$existing['source_name'],
                'sha256'=>$existing['source_sha256'], 'file'=>$existing['source_file'],
            ];
        } else { throw new \InvalidArgumentException('A new special catalog requires its original source image'); }

        $publishedName = (string)($existing['published_name'] ?? \FreePBX\modules\Ucmprovisioner\AssetFilename::content('g', $id, 'bmp'));
        if ($existing && isset($request['published_name']) && !hash_equals((string)$existing['published_name'], (string)$request['published_name'])) {
            throw new \InvalidArgumentException('A retained special catalog published filename is immutable; create a new catalog to use another phone-facing filename');
        }
        if (basename($publishedName) !== $publishedName || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,123}\.bmp$/iD', $publishedName) !== 1) {
            throw new \InvalidArgumentException('Published special background filename must be a safe BMP filename');
        }
        $published = null;
        $encodedPublished = (string)($request['content'] ?? $request['published_content'] ?? '');
        if ($encodedPublished !== '') {
            $published = base64_decode($encodedPublished, true);
            if ($published === false || $published === '' || strlen($published) > 8 * 1024 * 1024) {
                throw new \InvalidArgumentException('Published special background is empty, invalid, or larger than 8 MiB');
            }
            \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($published);
        } elseif ($existing) {
            $published = @file_get_contents($dir . '/' . $publishedName,false,null,0,8*1024*1024+1);
            if (!is_string($published) || strlen($published)>8*1024*1024 || !hash_equals((string)$existing['published_sha256'],hash('sha256',$published))) { throw new \RuntimeException('Existing published special background is missing or changed'); }
            \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($published);
        } else { throw new \InvalidArgumentException('A new special catalog requires its generated Cisco BMP'); }

        foreach ((array)($this->specialCatalogList($dir, $publicBase)['catalogs'] ?? []) as $catalog) {
            if ($catalog['catalog_id'] !== $id && strcasecmp((string)$catalog['published_name'], $publishedName) === 0) {
                throw new \InvalidArgumentException("Published filename $publishedName belongs to another special catalog");
            }
        }
        if (!$existing && is_file($dir . '/' . $publishedName)) {
            throw new \InvalidArgumentException("Published filename $publishedName already exists outside a retained catalog");
        }
        $manifest = self::specialCatalogManifest([
            'id'=>$id,
            'name'=>(string)($request['name'] ?? $existing['name'] ?? $id),
            'source_name'=>$source['name'], 'source_file'=>$source['file'],
            'source_mime'=>$source['mime'], 'source_sha256'=>$source['sha256'],
            'published_name'=>$publishedName, 'published_sha256'=>hash('sha256', $published),
            'transform'=>$request['transform'] ?? $existing['transform'] ?? [],
            'settings'=>$request['settings'] ?? $existing['settings'] ?? [],
            'revision'=>(int)($existing['revision'] ?? 0) + 1, 'updated'=>time(),
        ], $id);
        $catalogDir = $dir . '/.catalogs/' . $id;
        self::replaceSpecialCatalogFiles([
            $catalogDir . '/' . $manifest['source_file']=>$source['content'],
            $dir . '/' . $manifest['published_name']=>$published,
            $catalogDir . '/manifest.json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        ]);
        if ($existing && $existing['source_file'] !== $manifest['source_file']) { @unlink($catalogDir . '/' . $existing['source_file']); }
        return [
            'ok'=>true, 'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $manifest),
            'manifest'=>$manifest, 'message'=>$intent === 'create' ? 'Special catalog created' : 'Special catalog updated',
        ];
    }

    /** @return array<string,mixed> */
    private function specialCatalogRename(string $dir, string $publicBase, array $request): array
    {
        $oldId = self::specialCatalogId((string)($request['catalog_id'] ?? ''));
        $newId = self::specialCatalogId((string)($request['new_catalog_id'] ?? $oldId));
        $manifest = $this->specialCatalogReadManifest($dir, $oldId);
        if (!hash_equals(hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),
            (string)($request['expected_manifest_sha256'] ?? ''))) {
            return ['ok'=>false,'conflict'=>true,'message'=>'Special catalog changed or its editor revision is missing; refresh it before renaming'];
        }
        $manifest['id'] = $newId;
        $manifest['name'] = self::specialCatalogName((string)($request['name'] ?? $manifest['name']));
        $manifest['revision'] = (int)$manifest['revision'] + 1;
        $manifest['updated'] = time();
        $manifest = self::specialCatalogManifest($manifest, $newId);
        $oldDir = $dir . '/.catalogs/' . $oldId;
        $newDir = $dir . '/.catalogs/' . $newId;
        $retained=[];
        if ($oldId === $newId) {
            self::replaceSpecialCatalogFiles([$oldDir . '/manifest.json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"]);
        } else {
            if (is_dir($newDir)) { throw new \InvalidArgumentException("Special catalog $newId already exists"); }
            $source = @file_get_contents($oldDir . '/' . $manifest['source_file']);
            if (!is_string($source)) { throw new \RuntimeException('Special catalog source is missing'); }
            $newManifest=json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            self::replaceSpecialCatalogFiles([
                $newDir . '/' . $manifest['source_file']=>$source,
                $newDir . '/manifest.json'=>$newManifest,
            ]);
            $retained=\FreePBX\modules\Ucmprovisioner\RetainedAssetSource::retirePreviousDirectory($oldDir,$newDir,
                ['manifest.json'=>hash('sha256',$newManifest),$manifest['source_file']=>hash('sha256',$source)]);
        }
        return [
            'ok'=>true, 'old_catalog_id'=>$oldId,
            'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $manifest),
            'manifest'=>$manifest,
            'recovery_id'=>$retained['recovery_id'] ?? null,
            'cleanup_pending'=>!empty($retained['cleanup_pending']),
            'message'=>'Special catalog renamed; its phone-facing BMP filename was preserved. ' . ($retained['message'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function specialCatalogDelete(string $dir, string $catalogId, int $siteId = 0, array $request = []): array
    {
        if ($siteId<1) { return ['ok'=>false,'message'=>'Captured site and reference-bound retirement preview are required']; }
        $manifest = $this->specialCatalogReadManifest($dir, $catalogId);
        $catalogDir = $dir . '/.catalogs/' . $manifest['id'];
        $published = $dir . '/' . $manifest['published_name'];
        if (is_file($published)
            && !hash_equals((string)$manifest['published_sha256'], (string)@hash_file('sha256', $published))) {
            return ['ok'=>false,'message'=>'Published BMP changed outside this catalog; it was not deleted'];
        }
        return $this->hostedRetirementOperation($siteId,$dir,(string)$manifest['published_name'],$request + ['family'=>'cisco_cnf','model'=>'all'],(string)$manifest['id']);
    }

    /** @return array<string,mixed> */
    private function specialCatalogDownloadChunk(string $dir, array $request): array
    {
        $manifest = $this->specialCatalogReadManifest($dir, (string)($request['catalog_id'] ?? ''));
        $role = (string)($request['role'] ?? '');
        $path = match ($role) {
            'source'=>$dir . '/.catalogs/' . $manifest['id'] . '/' . $manifest['source_file'],
            'published'=>$dir . '/' . $manifest['published_name'],
            default=>throw new \InvalidArgumentException('Special catalog transfer role must be source or published'),
        };
        if (($request['manifest_sha256'] ?? '') !== $manifest[$role . '_sha256']) { throw new \DomainException('Special catalog manifest changed during transfer'); }
        $chunk = \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::download($path, $request);
        if ($chunk['sha256'] !== $manifest[$role . '_sha256']) { throw new \DomainException('Special catalog file differs from its manifest'); }
        return $chunk;
    }

    private static function specialCatalogTransferDirectory(string $dir, array $request): string
    {
        $id = self::specialCatalogId((string)($request['catalog_id'] ?? ''));
        $transferId = (string)($request['transfer_id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId)) { throw new \InvalidArgumentException('A unique special catalog transfer ID is required; upgrade the sending PBX'); }
        return $dir . '/.catalog-sync/' . $id . '/' . $transferId;
    }

    /** @return array<string,mixed> */
    private function specialCatalogUploadChunk(string $dir, array $request): array
    {
        $id = self::specialCatalogId((string)($request['catalog_id'] ?? ''));
        $role = (string)($request['role'] ?? '');
        if (!in_array($role, ['source','published'], true)) { throw new \InvalidArgumentException('Special catalog transfer role must be source or published'); }
        $staging = self::specialCatalogTransferDirectory($dir, $request);
        \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::directory($staging);
        $part = $staging . '/' . $role . '.part';
        $request['overwrite'] = false;
        return \FreePBX\modules\Ucmprovisioner\MediaChunkTransfer::upload($part, $staging . '/receipts/' . $role,
            $id . ':' . $role, $request);
    }

    /** @return array<string,mixed> */
    private function specialCatalogActivate(string $dir, string $publicBase, array $request): array
    {
        $id = self::specialCatalogId((string)($request['catalog_id'] ?? ''));
        $manifest = self::specialCatalogManifest($request['manifest'] ?? [], $id);
        $existing = $this->specialCatalogReadOptionalManifest($dir, $id);
        $staging = self::specialCatalogTransferDirectory($dir, $request);
        $sourcePart = $staging . '/source.part'; $publishedPart = $staging . '/published.part';
        if (empty($request['overwrite']) && $existing) {
            @unlink($sourcePart); @unlink($publishedPart); @rmdir($staging);
            return ['ok'=>true,'skipped'=>true,'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $existing),'message'=>'Existing special catalog preserved'];
        }
        $currentRevision = $existing ? hash('sha256', json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) : 'absent';
        if (!hash_equals($currentRevision, (string)($request['expected_destination'] ?? ''))) {
            return ['ok'=>false,'status'=>'conflict','message'=>'Destination special catalog changed during transfer; refresh before retrying'];
        }
        $source = @file_get_contents($sourcePart); $published = @file_get_contents($publishedPart);
        if (!is_string($source) || !is_string($published)
            || !hash_equals((string)$manifest['source_sha256'], hash('sha256', $source))
            || !hash_equals((string)$manifest['published_sha256'], hash('sha256', $published))) {
            return ['ok'=>false,'message'=>'Transferred special catalog files do not match the source manifest'];
        }
        $inspectedSource = self::specialCatalogSource(base64_encode($source), (string)$manifest['source_name']);
        if (!hash_equals((string)$manifest['source_sha256'], (string)$inspectedSource['sha256'])
            || !hash_equals((string)$manifest['source_mime'], (string)$inspectedSource['mime'])) {
            return ['ok'=>false,'message'=>'Transferred special catalog source type does not match its manifest'];
        }
        \FreePBX\modules\Ucmprovisioner\MediaUrl::assertLegacyCiscoBmp($published);
        foreach ((array)($this->specialCatalogList($dir, $publicBase)['catalogs'] ?? []) as $catalog) {
            if ($catalog['catalog_id'] !== $id && strcasecmp((string)$catalog['published_name'], (string)$manifest['published_name']) === 0) {
                return ['ok'=>false,'message'=>'The transferred published filename belongs to another special catalog'];
            }
        }
        $targetPublished = $dir . '/' . $manifest['published_name'];
        if (is_file($targetPublished)
            && (!$existing || !hash_equals((string)$existing['published_name'], (string)$manifest['published_name']))) {
            return ['ok'=>false,'message'=>'The transferred published filename would overwrite an unmanaged media file'];
        }
        $catalogDir = $dir . '/.catalogs/' . $id;
        self::replaceSpecialCatalogFiles([
            $catalogDir . '/' . $manifest['source_file']=>$source,
            $dir . '/' . $manifest['published_name']=>$published,
            $catalogDir . '/manifest.json'=>json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        ]);
        if ($existing && $existing['source_file'] !== $manifest['source_file']) { @unlink($catalogDir . '/' . $existing['source_file']); }
        if ($existing
            && $existing['published_name'] !== $manifest['published_name']
            && is_file($dir . '/' . $existing['published_name'])
            && hash_equals((string)$existing['published_sha256'], (string)@hash_file('sha256', $dir . '/' . $existing['published_name']))) {
            @unlink($dir . '/' . $existing['published_name']);
        }
        @unlink($sourcePart); @unlink($publishedPart); @rmdir($staging);
        return ['ok'=>true,'skipped'=>false,'catalog'=>$this->specialCatalogDescribe($dir, $publicBase, $manifest),'message'=>'Special catalog activated'];
    }

    /** Run a replication request with durable cross-page status and history. */
    public function replicationProgress(int $siteId, string $stage, string $message, ?array $result = null, ?array $bytes = null): void
    {
        if ($this->replicationActivityId < 1) { return; }
        try { (new \FreePBX\modules\Ucmprovisioner\ReplicationActivityService($this->db))->progress($this->replicationActivityId, $siteId, $stage, $message, $result, $bytes); }
        catch (\Throwable $error) { $this->replicationProgressError = 'Intermediate replication progress could not be saved'; }
    }

    public function replicationPlan(array $plan): void
    {
        if ($this->replicationActivityId < 1) { return; }
        try { (new \FreePBX\modules\Ucmprovisioner\ReplicationActivityService($this->db))->plan($this->replicationActivityId,$plan); }
        catch (\Throwable $error) { $this->replicationProgressError = 'Replication plan could not be saved'; }
    }

    /** Run a replication request with durable cross-page status and history. */
    public function trackedReplication(string $kind, string $label, array $request, callable $runner): array
    {
        if ($this->replicationActivityId > 0) { return (array)$runner(); }
        ignore_user_abort(true);
        $activity = new \FreePBX\modules\Ucmprovisioner\ReplicationActivityService($this->db);
        $targets = $this->replicationTargetIds($request['target_site_ids'] ?? ($request['targets'] ?? []));
        $source = (int)($request['source_site_id'] ?? ($request['site_id'] ?? 0));
        $jobId = max(0, (int)($request['id'] ?? 0));
        if ($jobId > 0 && !$targets) {
            $job = $this->replicationJobContext($kind, $jobId);
            if ($job) {
                $targets = $this->replicationTargetIds($job['targets_json'] ?? []);
                if ($source < 1) { $source = (int)($job['source_site_id'] ?? 0); }
                if (trim((string)($job['name'] ?? '')) !== '') { $label .= ': ' . trim((string)$job['name']); }
            }
        }
        if ($source < 1) { $source = $this->localSiteId(); }
        $run = $activity->begin($kind, $label, $source, $targets, [
            'operation'=>substr((string)($request['op'] ?? ''), 0, 64),
            'job_id'=>$jobId,
        ]);
        $id = (int)$run['id'];
        $activity->update($id, 0, count($targets), 'Preparing selected assets', 'The transfer continues even if this page is reloaded');
        $previousActivityId = $this->replicationActivityId;
        $this->replicationActivityId = $id;
        try {
            $result = (array)$runner();
        } catch (\Throwable $error) {
            try { $activity->fail($id, $error); }
            catch (\Throwable $reportingError) { /* Preserve the operation's original failure. */ }
            throw $error;
        } finally {
            $this->replicationActivityId = $previousActivityId;
        }
        if ($this->replicationProgressError !== '') { $result['reporting_error'] = $this->replicationProgressError; $this->replicationProgressError = ''; }
        $result = $activity->describeResult($result, $targets);
        if (!empty($result['destination_summary'])) { $result['message'] = $result['destination_summary'] . '. ' . ($result['message'] ?? ''); }
        $result['replication_run_id'] = $id;
        try { $activity->finish($id, $result); }
        catch (\Throwable $reportingError) {
            $result['reporting_error'] = trim((string)($result['reporting_error'] ?? '') . ' Replication history could not be saved: ' . $reportingError->getMessage());
            $result['message'] .= ' Destination results below are retained; history was not updated. Review them before retrying.';
        }
        return $result;
    }

    /** @return array<int,int> */
    private function replicationTargetIds($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$value), static fn(int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function replicationRequestLabel(string $operation, array $request): string
    {
        $labels = [
            'sync_firmware_bundle'=>'Firmware package replication',
            'sync_cisco_locale_bundle'=>'Cisco locale companion replication',
            'sync_timezone_version'=>'Cisco timezone version replication',
            'sync_timezone_bundle'=>'Active Cisco timezone replication',
            'sync_wallpaper_media'=>'Wallpaper / ringtone replication',
            'special_catalog_sync'=>'Non-standard media catalog replication',
            'sync'=>'Hosted audio replication',
            'media-job'=>'Saved Media replication job',
            'firmware-run'=>'Saved Firmware replication job',
            'firmware-copy'=>'Firmware-tab inventory replication',
            'configuration-job'=>'Configuration and dependency synchronization',
        ];
        $label = $labels[$operation] ?? 'Managed replication';
        $id = max(0, (int)($request['id'] ?? 0));
        return $id > 0 ? $label . ' #' . $id : $label;
    }

    /** @return array<string,mixed>|null */
    private function replicationJobContext(string $kind, int $id): ?array
    {
        $table = [
            'media'=>'ucmp_media_replication_jobs',
            'firmware'=>'ucmp_firmware_replication_jobs',
            'configuration'=>'ucmp_configuration_sync_jobs',
        ][$kind] ?? '';
        if ($table === '' || $id < 1) { return null; }
        $stmt = $this->db->prepare("SELECT name,source_site_id,targets_json FROM $table WHERE id=?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Persist and operate controller-owned recurring media replication jobs. */
    public function mediaReplicationOp(array $req): array
    {
        $service = $this->mediaReplicationService();
        $op = strtolower(trim((string)($req['op'] ?? 'list')));
        switch ($op) {
            case 'list':
                return ['ok'=>true, 'jobs'=>$service->list()];
            case 'save':
                return $service->save($req);
            case 'delete':
                return $service->delete((int)($req['id'] ?? 0));
            case 'run':
                return $service->runNow((int)($req['id'] ?? 0));
            default:
                throw new \InvalidArgumentException('Unknown media replication operation');
        }
    }

    /** Five-minute CLI tick; each saved job owns its cadence and retry policy. */
    public function runScheduledMediaReplication(): array
    {
        return $this->mediaReplicationService()->runDue();
    }

    private function mediaReplicationService(): \FreePBX\modules\Ucmprovisioner\MediaReplicationService
    {
        \FreePBX\modules\Ucmprovisioner\MediaReplicationService::ensureSchema($this->db);
        return new \FreePBX\modules\Ucmprovisioner\MediaReplicationService($this->db, $this);
    }

    /** Persist and operate controller-owned exact firmware/timezone replication jobs. */
    public function firmwareReplicationOp(array $req): array
    {
        $service = $this->firmwareReplicationService();
        $op = strtolower(trim((string)($req['op'] ?? 'list')));
        if ($op === 'copy') {
            $decodeList = static function ($value): array {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    return is_array($decoded) ? $decoded : [];
                }
                return is_array($value) ? $value : [];
            };
            return $service->replicateSelection(
                (int)($req['source_site_id'] ?? $this->localSiteId()),
                $decodeList($req['target_site_ids'] ?? ($req['targets'] ?? [])),
                $decodeList($req['packages'] ?? ($req['firmware_packages'] ?? [])),
                (string)($req['timezone_bundle_key'] ?? ($req['timezone_version'] ?? '')),
                !array_key_exists('changed_only', $req) || !empty($req['changed_only']),
                !empty($req['activate_timezone']),
                [
                    'include_all_firmware'=>!empty($req['include_all_firmware']),
                    'include_all_timezones'=>!empty($req['include_all_timezones']),
                    'include_all_locales'=>!empty($req['include_all_locales']),
                    'locale_user_locale'=>(string)($req['locale_user_locale'] ?? 'English_United_States'),
                    'locale_network_locale'=>(string)($req['locale_network_locale'] ?? ''),
                ]
            );
        }
        return match ($op) {
            'list' => ['ok'=>true, 'jobs'=>$service->list()],
            'save' => $service->save($req),
            'delete' => $service->delete((int)($req['id'] ?? 0)),
            'run' => $service->runNow((int)($req['id'] ?? 0)),
            default => throw new \InvalidArgumentException('Unknown firmware replication operation'),
        };
    }

    /** One-minute CLI tick; each saved firmware job owns its cadence and retry policy. */
    public function runScheduledFirmwareReplication(): array
    {
        return $this->firmwareReplicationService()->runDue();
    }

    private function firmwareReplicationService(): \FreePBX\modules\Ucmprovisioner\FirmwareReplicationService
    {
        \FreePBX\modules\Ucmprovisioner\FirmwareReplicationService::ensureSchema($this->db);
        return new \FreePBX\modules\Ucmprovisioner\FirmwareReplicationService($this->db, $this);
    }

    /** Persist and operate controller-owned cross-site configuration jobs. */
    public function configurationSyncOp(array $req): array
    {
        $service = $this->configurationSyncService();
        return match (strtolower(trim((string)($req['op'] ?? 'list')))) {
            'list' => ['ok'=>true, 'jobs'=>$service->list()],
            'save' => $service->save($req),
            'delete' => $service->delete((int)($req['id'] ?? 0)),
            'run' => $service->runNow((int)($req['id'] ?? 0), !empty($req['force'])),
            'preview_legacy', 'adopt_legacy' => $service->legacyOwnership((int)($req['id'] ?? 0), (int)($req['target_site_id'] ?? 0),
                ($req['op'] ?? '') === 'adopt_legacy' && !empty($req['ownership_confirmed']), (string)($req['review_hash'] ?? '')),
            'resume' => $service->resume(
                (int)($req['id'] ?? 0),
                (int)($req['target_site_id'] ?? 0),
                (string)($req['object_type'] ?? ''),
                (string)($req['object_key'] ?? '')
            ),
            'states' => [
                'ok'=>true,
                'items'=>$this->configurationSyncStates(
                    (int)($req['site_id'] ?? 0) ?: $this->localSiteId(),
                    (string)($req['object_type'] ?? '')
                ),
            ],
            default => throw new \InvalidArgumentException('Unknown configuration sync operation'),
        };
    }

    public function runScheduledConfigurationSync(): array
    {
        $service = $this->configurationSyncService();
        $recovery = $this->reconcileConfigurationSyncDependenciesLocal();
        $result = $service->runDue();
        $result['dependency_recovery'] = $recovery;
        if (empty($recovery['ok'])) {
            $result['ok'] = false;
            $result['message'] .= '; ' . $recovery['message'];
        }
        return $result;
    }

    private function configurationSyncService(): \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService
    {
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        return new \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService($this->db, $this);
    }

    /** Read-only protocol capability probe used before any destination mutation. */
    public function configurationSyncCapabilities(int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return (array)$this->remoteQuery($siteId, 'configurationSyncCapabilitiesLocal', []);
        }
        return $this->configurationSyncCapabilitiesLocal();
    }

    /** @return array{ok:bool,schema:int,scopes:array<int,string>} */
    public function configurationSyncCapabilitiesLocal(): array
    {
        return [
            'ok'=>true,
            'schema'=>1,
            'scopes'=>array_values(\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SCOPES),
            'object_filter'=>['templates'=>true,'groups'=>true,'settings'=>true,'setting_keys'=>true],
        ];
    }

    protected function webDirectory(): \FreePBX\modules\Ucmprovisioner\WebPhonebook
    {
        return new \FreePBX\modules\Ucmprovisioner\WebPhonebook();
    }

    public function saveWebDirectoryPolicy(array $policy, string $revision, array $input = [], ?array $faviconUpload = null): void
    {
        $items = $this->configurationSyncEditGuard('web_directory', 0, 'web-directory-policy', $input);
        if (!empty($input['web_phonebook_remove_favicon'])) { $policy['favicon']=''; }
        if ($faviconUpload !== null && ($faviconUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($faviconUpload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($faviconUpload['tmp_name'] ?? null) || !is_uploaded_file($faviconUpload['tmp_name'])) {
                throw new \InvalidArgumentException('Favicon upload failed. Choose a PNG or JPEG no larger than 1 MiB.');
            }
            $bytes = file_get_contents($faviconUpload['tmp_name'], false, null, 0, 1048577);
            if ($bytes === false) { throw new \RuntimeException('Cannot read the uploaded favicon.'); }
            $policy['favicon'] = \FreePBX\modules\Ucmprovisioner\WebPhonebook::faviconFromImage($bytes);
        }
        $this->webDirectory()->savePolicy($policy, $revision);
        $this->pauseConfigurationSyncItems($items, $input);
    }

    /** Read only portable provisioning policy from one site. */
    public function configurationSyncSnapshot(int $siteId, array $scopes, array $objectFilter = []): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return (array)$this->remoteQuery($siteId, 'configurationSyncSnapshotLocal', [$scopes, $objectFilter]);
        }
        return $this->configurationSyncSnapshotLocal($scopes, $objectFilter);
    }

    /** @return array<string,mixed> */
    public function configurationSyncSnapshotLocal(array $scopes, array $objectFilter = []): array
    {
        $scopes = array_values(array_unique(array_intersect(
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SCOPES,
            array_map('strval', $scopes)
        )));
        if (!$scopes) { throw new \InvalidArgumentException('Choose at least one configuration sync scope'); }
        $siteId = $this->localSiteId();
        $site = $this->getSite($siteId) ?: [];
        $templates = $this->getTemplatesLocal($siteId);
        $requestedTemplateIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($objectFilter['templates'] ?? null) ? $objectFilter['templates'] : []),
            static fn(int $id): bool => $id > 0
        )));
        if ($requestedTemplateIds) {
            $foundTemplateIds = array_map(static fn(array $template): int => (int)($template['id'] ?? 0), $templates);
            $missingTemplateIds = array_values(array_diff($requestedTemplateIds, $foundTemplateIds));
            if ($missingTemplateIds) {
                throw new \InvalidArgumentException('One or more selected source templates no longer exist: ' . implode(', ', $missingTemplateIds));
            }
            $templates = array_values(array_filter(
                $templates,
                static fn(array $template): bool => in_array((int)($template['id'] ?? 0), $requestedTemplateIds, true)
            ));
        }
        $requestedGroupIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($objectFilter['groups'] ?? ($objectFilter['group_ids'] ?? null)) ? ($objectFilter['groups'] ?? $objectFilter['group_ids']) : []),
            static fn(int $id): bool => $id > 0
        )));
        $groupRows = [];
        $selectedGroupRows = [];
        if ($requestedGroupIds || in_array('groups', $scopes, true)) {
            $allGroups = [];
            foreach ($this->getGroupsLocal($siteId) as $group) {
                $groupId = (int)($group['id'] ?? 0);
                if ($groupId < 1) { continue; }
                $detail = $this->getGroupLocal($groupId) ?: $group;
                $detail['id'] = $groupId;
                $allGroups[$groupId] = $detail;
                if (!$requestedGroupIds || in_array($groupId, $requestedGroupIds, true)) {
                    $selectedGroupRows[$groupId] = $detail;
                }
            }
            // Include ancestors as path-only dependencies so a selected child
            // still has a stable full name/path and parent object key. They are
            // not emitted as objects unless explicitly selected.
            if ($requestedGroupIds) {
                foreach ($requestedGroupIds as $groupId) {
                    $seen = [];
                    while ($groupId > 0 && isset($allGroups[$groupId]) && empty($seen[$groupId])) {
                        $seen[$groupId] = true;
                        $groupRows[$groupId] = $allGroups[$groupId];
                        $groupId = (int)($allGroups[$groupId]['parent_group_id'] ?? 0);
                    }
                }
            } else {
                $groupRows = $allGroups;
            }
        }
        if ($requestedGroupIds) {
            $foundGroupIds = array_map(static fn(array $group): int => (int)($group['id'] ?? 0), array_values($selectedGroupRows));
            $missingGroupIds = array_values(array_diff($requestedGroupIds, $foundGroupIds));
            if ($missingGroupIds) {
                throw new \InvalidArgumentException('One or more selected source groups no longer exist: ' . implode(', ', $missingGroupIds));
            }
        }
        $settingsFilter = is_array($objectFilter['settings'] ?? ($objectFilter['settings_categories'] ?? null))
            ? ($objectFilter['settings'] ?? $objectFilter['settings_categories']) : [];
        $settingsFilter = array_values(array_unique(array_intersect(
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES,
            array_map('strval', $settingsFilter)
        )));
        sort($settingsFilter, SORT_STRING);
        $templateIndex = [];
        foreach ($templates as $template) {
            $sourceId = (int)($template['id'] ?? 0);
            if ($sourceId < 1) { continue; }
            $templateIndex[(string)$sourceId] = [
                'object_key'=>'template:' . $sourceId,
                'name'=>(string)($template['name'] ?? ('Template #' . $sourceId)),
                'revision'=>(string)max(1, (int)($template['revision'] ?? 1)),
            ];
        }
        $payload = [
            'ok'=>true,
            'schema'=>1,
            'created_at'=>time(),
            'source_site'=>[
                'site_key'=>(string)($site['site_key'] ?? ('site-' . $siteId)),
                'name'=>(string)($site['name'] ?? ('Site #' . $siteId)),
            ],
            'scopes'=>$scopes,
            'object_filter'=>[
                'templates'=>$requestedTemplateIds,
                'groups'=>$requestedGroupIds,
                'settings'=>$settingsFilter,
            ],
            'template_index'=>$templateIndex,
        ];
        if (array_key_exists('setting_keys', $objectFilter)) { $payload['object_filter']['setting_keys'] = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::settingKeys($objectFilter['setting_keys']); }
        if (in_array('templates', $scopes, true)) {
            $payload['templates'] = array_map(static function (array $template): array {
                $sourceId = (int)$template['id'];
                $settings = json_decode((string)($template['setting_overrides'] ?? '{}'), true) ?: [];
                foreach ([
                    'cisco_phone_password','cisco_ssh_password','cisco_admin_password','phone_password','admin_password','user_password',
                    'ntp_server','services_url','media_base_url','phone_services_base_url',
                ] as $destinationOwnedKey) { unset($settings[$destinationOwnedKey]); }
                $template['setting_overrides'] = json_encode((object)$settings, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                unset($template['site_id'], $template['updated']);
                $template['_source_id'] = $sourceId;
                $template['_object_key'] = 'template:' . $sourceId;
                $template['_source_revision'] = (string)max(1, (int)($template['revision'] ?? 1));
                unset($template['id']);
                return $template;
            }, $templates);
        }
        if (in_array('default_templates', $scopes, true)) {
            $rules = $this->getDefaultTemplateRulesLocal($siteId);
            if ($requestedTemplateIds) {
                $rules = array_values(array_filter(
                    $rules,
                    static fn(array $rule): bool => in_array((int)($rule['template_id'] ?? 0), $requestedTemplateIds, true)
                ));
            }
            $payload['default_templates'] = array_values(array_map(static function (array $rule) use ($templateIndex): array {
                $templateId = (string)(int)($rule['template_id'] ?? 0);
                return [
                    'template_key'=>(string)($templateIndex[$templateId]['object_key'] ?? ''),
                    'template_name'=>(string)($templateIndex[$templateId]['name'] ?? ($rule['template_name'] ?? '')),
                    'target_key'=>(string)($rule['target_key'] ?? ''),
                    'target_vendor'=>(string)($rule['target_vendor'] ?? ''),
                    'target_family'=>(string)($rule['target_family'] ?? ''),
                    'target_series'=>(string)($rule['target_series'] ?? ''),
                    'target_model'=>(string)($rule['target_model'] ?? ''),
                    'target_role'=>(string)($rule['target_role'] ?? ''),
                    'enabled'=>!empty($rule['enabled']) ? 1 : 0,
                ];
            }, $rules));
        }
        if (in_array('site_defaults', $scopes, true)) {
            $stored = $this->getSiteSettingsLocal($siteId);
            $payload['site_defaults'] = array_intersect_key($stored, array_fill_keys([
                'firmware_defaults', 'cisco_timezone_defaults', 'cisco_wallpaper_defaults', 'hosted_image_defaults',
            ], true));
            if (isset($payload['object_filter']['setting_keys'])) {
                $payload['site_defaults'] = array_intersect_key($payload['site_defaults'],array_fill_keys($payload['object_filter']['setting_keys'],true));
            }
        }
        if (in_array('groups', $scopes, true)) {
            $payload['groups'] = $this->configurationSyncGroupSnapshot(
                array_values($groupRows),
                $templateIndex,
                $siteId,
                $requestedGroupIds ?: null
            );
        }
        $granularSettings = $settingsFilter ?: array_values(array_intersect(
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES,
            $scopes
        ));
        if ($granularSettings) {
            $storedSettings = $this->getSiteSettingsLocal($siteId);
            $payload['settings'] = $this->configurationSyncSettingsSnapshot($storedSettings, $granularSettings, $siteId, $requestedTemplateIds, $payload['object_filter']['setting_keys'] ?? null);
            if (array_key_exists('setting_keys', $payload['object_filter'])) {
                $selectedFields = array_fill_keys($payload['object_filter']['setting_keys'], true);
                foreach ($payload['settings'] as $scope=>&$values) {
                    if ($scope !== 'settings_default_template_selectors') { $values = array_intersect_key($values, $selectedFields); }
                }
                unset($values);
            }
        }
        if (in_array('phonebooks', $scopes, true)) {
            $payload['phonebooks'] = array_map(static function (array $book): array {
                $book['_object_key'] = 'phonebook:' . (string)$book['book_key'];
                $book['_source_revision'] = (string)max(1, (int)($book['updated'] ?? 1));
                unset($book['id']);
                return $book;
            }, $this->getDirectoryPhonebooks());
        }
        if (in_array('web_directory', $scopes, true)) {
            $policy = $this->webDirectory()->policy();
            $payload['web_directory'] = [
                'policy'=>$policy,
                'revision'=>hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)),
            ];
        }
        return $payload;
    }

    /** @return array<int,array<string,mixed>> */
    private function configurationSyncGroupSnapshot(array $groups, array $templateIndex, int $siteId, ?array $emitIds = null): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $id = (int)($group['id'] ?? 0);
            if ($id > 0) { $byId[$id] = $group; }
        }
        $paths = [];
        $pathOf = function (int $id, array $seen = []) use (&$pathOf, &$paths, $byId): string {
            if (isset($paths[$id])) { return $paths[$id]; }
            if ($id < 1 || !isset($byId[$id]) || isset($seen[$id])) { return ''; }
            $seen[$id] = true;
            $group = $byId[$id];
            $name = trim((string)($group['name'] ?? '')) ?: 'Unnamed group';
            $parent = (int)($group['parent_group_id'] ?? 0);
            $parentPath = $parent > 0 ? $pathOf($parent, $seen) : '';
            return $paths[$id] = $parentPath !== '' ? $parentPath . '/' . $name : $name;
        };
        // Group identity is the source site identity plus the immutable source
        // row id.  The display path remains useful for first-time destination
        // matching, but a rename/reparent must never create a new sync object.
        $sourceSite = $this->getSite($siteId) ?: [];
        $sourceIdentity = trim((string)($sourceSite['site_key'] ?? '')) ?: ('site-' . $siteId);
        $emitSet = [];
        if ($emitIds !== null) {
            foreach ($emitIds as $emitId) {
                $emitId = (int)$emitId;
                while ($emitId > 0 && isset($byId[$emitId]) && !isset($emitSet[$emitId])) {
                    $emitSet[$emitId] = true;
                    $emitId = (int)($byId[$emitId]['parent_group_id'] ?? 0);
                }
            }
        }
        $out = [];
        foreach ($byId as $id=>$group) {
            if ($emitIds !== null && !isset($emitSet[$id])) { continue; }
            $path = $pathOf($id);
            $parentId = (int)($group['parent_group_id'] ?? 0);
            $templateMap = $group['template_map'] ?? [];
            if (is_string($templateMap)) { $templateMap = json_decode($templateMap, true) ?: []; }
            $layers = [];
            if ((int)($group['template_id'] ?? 0) > 0) {
                $primaryTemplateId = (int)$group['template_id'];
                $hasPrimary = false;
                foreach ((array)$templateMap as $layer) {
                    if (is_array($layer) && (int)($layer['template_id'] ?? 0) === $primaryTemplateId) {
                        $hasPrimary = true;
                        break;
                    }
                }
                if (!$hasPrimary) {
                    $templateMap = array_merge([['template_id'=>$primaryTemplateId,'position'=>0]], (array)$templateMap);
                }
            }
            $seenTemplateIds = [];
            foreach ((array)$templateMap as $index=>$layer) {
                if (!is_array($layer)) { continue; }
                $templateId = (int)($layer['template_id'] ?? 0);
                if ($templateId < 1) { continue; }
                if (isset($seenTemplateIds[$templateId])) { continue; }
                $seenTemplateIds[$templateId] = true;
                $template = $this->getTemplateLocal($templateId) ?: [];
                $templateKey = (string)($templateIndex[(string)$templateId]['object_key'] ?? ('template:' . $templateId));
                $layers[] = [
                    'template_key'=>$templateKey,
                    'template_name'=>(string)($templateIndex[(string)$templateId]['name'] ?? ($template['name'] ?? ('Template #' . $templateId))),
                    'position'=>(int)($layer['position'] ?? (($index + 1) * 10)),
                ];
            }
            usort($layers, static fn(array $a,array $b): int => ((int)$a['position'] <=> (int)$b['position']) ?: strcmp((string)$a['template_key'],(string)$b['template_key']));
            $memberExceptions = $group['member_exceptions'] ?? [];
            if (is_string($memberExceptions)) { $memberExceptions = json_decode($memberExceptions, true) ?: []; }
            $safeExceptions = [];
            foreach ((array)$memberExceptions as $exception) {
                if (!is_array($exception)) { continue; }
                $templateId = (int)($exception['template_id'] ?? 0);
                if ($templateId < 1) { continue; }
                $template = $this->getTemplateLocal($templateId) ?: [];
                $extensions = array_values(array_unique(array_filter(array_map(
                    static fn($extension): string => trim((string)$extension),
                    is_array($exception['extensions'] ?? null) ? $exception['extensions'] : []
                ), static fn(string $extension): bool => $extension !== '')));
                if (!$extensions) { continue; }
                sort($extensions, SORT_NATURAL | SORT_FLAG_CASE);
                $safeExceptions[] = [
                    'template_key'=>(string)($templateIndex[(string)$templateId]['object_key'] ?? ('template:' . $templateId)),
                    'template_name'=>(string)($templateIndex[(string)$templateId]['name'] ?? ($template['name'] ?? ('Template #' . $templateId))),
                    'extensions'=>$extensions,
                ];
            }
            $members = [];
            foreach ((array)($group['member_extensions'] ?? []) as $extension) {
                $extension = trim((string)$extension);
                if ($extension !== '') { $members[$extension] = true; }
            }
            $extra = $group['extra_targets'] ?? [];
            if (is_string($extra)) { $extra = json_decode($extra, true) ?: []; }
            $safeExtra = [];
            foreach ((array)$extra as $row) {
                if (!is_array($row)) { continue; }
                $safeRow = [];
                foreach (['feature','target','label'] as $key) {
                    if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                        $safeRow[$key] = substr(trim((string)$row[$key]), 0, 255);
                    }
                }
                if ($safeRow) { $safeExtra[] = $safeRow; }
            }
            $intended = [
                'name'=>trim((string)($group['name'] ?? '')),
                'group_path'=>$path,
                'parent_group_key'=>$parentId > 0 ? $this->configurationSyncStableGroupKey($sourceIdentity, $parentId) : '',
                'template_map'=>$layers,
                'member_exceptions'=>$safeExceptions,
                'member_extensions'=>array_keys($members),
                'member_blf'=>!empty($group['member_blf']) ? 1 : 0,
                'extra_targets'=>$safeExtra,
            ];
            // Paths are transport/matching metadata.  They must not enter the
            // content revision: renaming/reparenting an ancestor changes its
            // descendants' display paths but not their own policy content.
            $contentRevision = $this->configurationSyncDigest([
                'name'=>$intended['name'],
                'parent_group_key'=>$intended['parent_group_key'],
                'template_map'=>$intended['template_map'],
                'member_exceptions'=>$intended['member_exceptions'],
                'member_extensions'=>$intended['member_extensions'],
                'member_blf'=>$intended['member_blf'],
                'extra_targets'=>$intended['extra_targets'],
            ]);
            $intended['_source_group_id'] = $id;
            $intended['_object_key'] = $this->configurationSyncStableGroupKey($sourceIdentity, $id);
            $intended['_source_revision'] = $contentRevision;
            // This is transport metadata, not content.  It must not alter the
            // revision when an operator selects a child versus the whole tree.
            $intended['_dependency_only'] = $emitIds !== null && !in_array($id, $emitIds, true);
            $out[] = $intended;
        }
        // Apply ancestors before descendants.  Stable path/id tie-breakers make
        // the order deterministic even when the source query order changes.
        usort($out, static function (array $a, array $b): int {
            $depth = static fn(array $row): int => substr_count(trim((string)($row['group_path'] ?? ''), '/'), '/') + 1;
            return (($depth($a) <=> $depth($b))
                ?: strcmp(strtolower((string)($a['group_path'] ?? '')), strtolower((string)($b['group_path'] ?? '')))
                ?: ((int)($a['_source_group_id'] ?? 0) <=> (int)($b['_source_group_id'] ?? 0)));
        });
        return $out;
    }

    private function configurationSyncStableGroupKey(string $sourceIdentity, int $sourceGroupId): string
    {
        $identity = strtolower(trim($sourceIdentity)) ?: 'unknown-site';
        return 'group:' . hash('sha256', $identity . '|group:' . $sourceGroupId);
    }

    /** @return array<string,array<string,mixed>> */
    private function configurationSyncSettingsSnapshot(array $stored, array $scopes, int $siteId, array $templateFilter = [], ?array $selectedKeys = null): array
    {
        $fields = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS;
        $out = [];
        foreach ($scopes as $scope) {
            if ($scope === 'settings_default_template_selectors') {
                $rules = [];
                $templates = [];
                foreach ($this->getTemplatesLocal($siteId) as $template) { $templates[(int)($template['id'] ?? 0)] = $template; }
                foreach ($this->getDefaultTemplateRulesLocal($siteId) as $rule) {
                    $templateId = (int)($rule['template_id'] ?? 0);
                    if ($templateFilter && !in_array($templateId,$templateFilter,true)) { continue; }
                    $rules[] = [
                        'template_key'=>$templateId > 0 ? 'template:' . $templateId : '',
                        'template_name'=>(string)($templates[$templateId]['name'] ?? ''),
                        'target_key'=>(string)($rule['target_key'] ?? ''),
                        'target_vendor'=>(string)($rule['target_vendor'] ?? ''),
                        'target_family'=>(string)($rule['target_family'] ?? ''),
                        'target_series'=>(string)($rule['target_series'] ?? ''),
                        'target_model'=>(string)($rule['target_model'] ?? ''),
                        'target_role'=>(string)($rule['target_role'] ?? ''),
                        'enabled'=>!empty($rule['enabled']) ? 1 : 0,
                    ];
                }
                $out[$scope] = ['default_templates'=>$rules];
                continue;
            }
            $out[$scope] = [];
            foreach ($fields[$scope] ?? [] as $key) {
                if ($selectedKeys !== null && !in_array($key,$selectedKeys,true)) { continue; }
                if ($selectedKeys === null && in_array($key,\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ADDRESS_FIELDS,true)) { continue; }
                if (!array_key_exists($key, $stored)) {
                    if ($selectedKeys !== null) {
                        $out[$scope][$key] = in_array($key,['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults'],true) ? [] : null;
                    }
                    continue;
                }
                $value = $stored[$key];
                if (in_array($key, ['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults'], true)) {
                    $decoded = is_string($value) ? (json_decode($value, true) ?: []) : $value;
                    if (is_array($decoded)) { $out[$scope][$key] = $decoded; }
                } elseif (is_scalar($value)) {
                    $out[$scope][$key] = (string)$value;
                }
            }
        }
        return $out;
    }

    /** Copy selected managed dependencies before importing policy. */
    public function configurationSyncAssets(int $sourceSiteId, array $targetSiteIds, array $scopes, array $options = []): array
    {
        $targetSiteIds = array_values(array_unique(array_filter(array_map('intval', $targetSiteIds), static fn(int $id): bool => $id > 0)));
        $operations = [];
        $errors = [];
        $warnings = [];
        $needsConfirmation = false;
        $items = [];
        foreach ($targetSiteIds as $targetSiteId) { $items[(string)$targetSiteId] = []; }
        $context = [
            'job_key'=>trim((string)($options['job_key'] ?? '')),
            'job_id'=>max(0,(int)($options['job_id'] ?? 0)),
            'source_site_key'=>trim((string)($options['source_site_key'] ?? ('site-' . $sourceSiteId))),
            'source_site_name'=>substr(trim((string)($options['source_site_name'] ?? ('Site #' . $sourceSiteId))),0,80),
            'soft_lock'=>!empty($options['soft_lock']),
            'force'=>!empty($options['force']),
        ];
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $context['job_key'])) {
            throw new \InvalidArgumentException('Configuration sync asset ownership key is invalid');
        }
        $syncAssetDefaults = in_array('settings_assets', $scopes, true)
            || in_array('site_defaults', $scopes, true);
        $syncTemplateDependencies = in_array('templates', $scopes, true);
        $syncAllWallpapers = in_array('wallpaper_catalogs', $scopes, true);
        $syncAllFirmware = in_array('firmware', $scopes, true);
        $assetDefaults = ['firmware'=>[], 'wallpaper'=>[], 'hosted'=>[]];
        $requiredTimezoneAssets = [];
        $requiredLegacyTimezoneKeys = [];
        if ($syncAssetDefaults) {
            try {
                $sourceSettings = $this->getSiteSettings($sourceSiteId);
                if (isset($options['snapshot']['object_filter']['setting_keys'])) {
                    $sourceSettings = array_intersect_key($sourceSettings,array_fill_keys($options['snapshot']['object_filter']['setting_keys'],true));
                }
                $decodeMap = static function ($value): array {
                    if (is_string($value)) { $value = json_decode($value, true) ?: []; }
                    return is_array($value) ? $value : [];
                };
                foreach ($decodeMap($sourceSettings['firmware_defaults'] ?? []) as $profile=>$load) {
                    $profile = trim((string)$profile);
                    $load = trim((string)$load);
                    if ($profile !== '' && $load !== '') { $assetDefaults['firmware'][strtolower($profile . "\0" . $load)] = [$profile,$load]; }
                }
                foreach ($decodeMap($sourceSettings['cisco_timezone_defaults'] ?? []) as $profile=>$assetKey) {
                    $profile = trim((string)$profile);
                    $assetKey = strtolower(trim((string)$assetKey));
                    if ($profile !== '' && preg_match('/^[a-f0-9]{64}$/D', $assetKey) === 1) {
                        $requiredTimezoneAssets[$assetKey] = $profile;
                    }
                }
                foreach ($decodeMap($sourceSettings['cisco_wallpaper_defaults'] ?? []) as $filename) {
                    $name = strtolower(basename((string)$filename));
                    if ($name !== '') { $assetDefaults['wallpaper'][$name] = true; }
                }
                foreach ($decodeMap($sourceSettings['hosted_image_defaults'] ?? []) as $url) {
                    $name = strtolower(basename((string)parse_url((string)$url, PHP_URL_PATH)));
                    if ($name !== '') { $assetDefaults['hosted'][$name] = true; }
                }
            } catch (\Throwable $e) {
                $errors[] = 'Source site asset defaults could not be read: ' . $e->getMessage();
            }
        }
        if ($syncTemplateDependencies) {
            foreach ((array)(($options['snapshot']['templates'] ?? [])) as $template) {
                if (!is_array($template)) { continue; }
                $templateSettings = $template['setting_overrides'] ?? [];
                if (is_string($templateSettings)) { $templateSettings = json_decode($templateSettings, true) ?: []; }
                if (!is_array($templateSettings)) { continue; }
                foreach (['firmware_by_profile','inactive_firmware_by_profile'] as $mapKey) {
                    $map = $templateSettings[$mapKey] ?? [];
                    if (is_string($map)) { $map = json_decode($map, true) ?: []; }
                    foreach ((array)$map as $profile=>$load) {
                        $profile = trim((string)$profile); $load = trim((string)$load);
                        if ($profile !== '' && $load !== '') {
                            $assetDefaults['firmware'][strtolower($profile . "\0" . $load)] = [$profile,$load];
                        }
                    }
                }
                foreach (['cisco_wallpaper_by_profile'] as $mapKey) {
                    $map = $templateSettings[$mapKey] ?? [];
                    if (is_string($map)) { $map = json_decode($map, true) ?: []; }
                    foreach ((array)$map as $filename) {
                        $name = strtolower(basename((string)$filename));
                        if ($name !== '') { $assetDefaults['wallpaper'][$name] = true; }
                    }
                }
                foreach (['hosted_image_by_profile'] as $mapKey) {
                    $map = $templateSettings[$mapKey] ?? [];
                    if (is_string($map)) { $map = json_decode($map, true) ?: []; }
                    foreach ((array)$map as $url) {
                        $name = strtolower(basename((string)parse_url((string)$url, PHP_URL_PATH)));
                        if ($name !== '') { $assetDefaults['hosted'][$name] = true; }
                    }
                }
                $timezoneMap = $templateSettings['cisco_timezone_by_profile'] ?? [];
                if (is_string($timezoneMap)) { $timezoneMap = json_decode($timezoneMap, true) ?: []; }
                foreach ((array)$timezoneMap as $profile=>$assetKey) {
                    $profile = trim((string)$profile);
                    $assetKey = strtolower(trim((string)$assetKey));
                    if ($profile !== '' && preg_match('/^[a-f0-9]{64}$/D', $assetKey) === 1) {
                        $requiredTimezoneAssets[$assetKey] = $profile;
                    }
                }
                foreach (['cisco_default_wallpaper'] as $key) {
                    $name = strtolower(basename((string)($templateSettings[$key] ?? '')));
                    if ($name !== '') { $assetDefaults['wallpaper'][$name] = true; }
                }
                foreach (['hosted_image_url','legacy_logo_url'] as $key) {
                    $name = strtolower(basename((string)parse_url((string)($templateSettings[$key] ?? ''), PHP_URL_PATH)));
                    if ($name !== '') { $assetDefaults['hosted'][$name] = true; }
                }
                $timezoneKey = strtolower(trim((string)($templateSettings['cisco_timezone_bundle_key'] ?? '')));
                if (preg_match('/^[a-f0-9]{64}$/D', $timezoneKey) === 1) { $requiredLegacyTimezoneKeys[$timezoneKey] = true; }
            }
        }
        if ($syncAllWallpapers || $syncAssetDefaults || $syncTemplateDependencies) {
            try {
                $listed = (array)$this->tftpOp(['op'=>'wallpaper_set_list','site_id'=>$sourceSiteId]);
                if (empty($listed['ok'])) {
                    throw new \RuntimeException((string)($listed['message'] ?? 'Source wallpaper catalogs are unavailable'));
                }
                $requiredWallpapers = $assetDefaults['wallpaper'] + $assetDefaults['hosted'];
                $matchedWallpapers = [];
                foreach ((array)($listed['sets'] ?? []) as $catalog) {
                    if (empty($catalog['complete'])) { continue; }
                    $catalogId = (string)($catalog['id'] ?? '');
                    if ($catalogId === '') { continue; }
                    $catalogFiles = [];
                    foreach ((array)($catalog['variants'] ?? []) as $variant) {
                        if (empty($variant['enabled'])) { continue; }
                        foreach (['filename','full','url'] as $field) {
                            $value = (string)($variant[$field] ?? '');
                            $path = $field === 'url' ? (string)parse_url($value, PHP_URL_PATH) : $value;
                            $name = strtolower(basename($path));
                            if ($name !== '') { $catalogFiles[$name] = true; }
                        }
                    }
                    $referenced = array_intersect_key($requiredWallpapers, $catalogFiles);
                    if (!$syncAllWallpapers && !$referenced) { continue; }
                    $matchedWallpapers += $referenced;
                    $sourceRevision = $this->configurationSyncCatalogRevision('wallpaper_catalog', $catalog);
                    $intended = [
                        'catalog_id'=>$catalogId,
                        'name'=>(string)($catalog['name'] ?? $catalogId),
                        'scope_label'=>(string)($catalog['scope_label'] ?? 'Supported fleet'),
                    ];
                    foreach ($targetSiteIds as $targetSiteId) {
                        try {
                            $prepared = $this->configurationSyncPrepareAsset(
                                $targetSiteId,
                                $context,
                                'wallpaper_catalog',
                                'wallpaper:' . $catalogId,
                                $sourceRevision,
                                $intended
                            );
                            if (!empty($prepared['item'])) { $items[(string)$targetSiteId][] = $prepared['item']; }
                            if (empty($prepared['allow'])) {
                                if (($prepared['item']['status'] ?? '') === 'conflict') { $needsConfirmation = true; }
                                $warnings[] = 'Site #' . $targetSiteId . ' / ' . $catalogId . ': ' . (string)($prepared['message'] ?? 'catalog sync paused');
                                continue;
                            }
                            if (empty($prepared['already_current'])) {
                                $copy = (array)$this->tftpOp([
                                    'op'=>'sync_wallpaper_media','site_id'=>$sourceSiteId,'scope'=>'wallpapers',
                                    'set_ids'=>[$catalogId],'target_site_ids'=>[$targetSiteId],
                                    'overwrite'=>!empty($prepared['overwrite']) ? 1 : 0,
                                ]);
                                $operations[] = ['scope'=>'wallpaper_catalogs','catalog_id'=>$catalogId,'target_site_id'=>$targetSiteId] + $copy;
                                if (empty($copy['ok'])) {
                                    throw new \RuntimeException((string)($copy['message'] ?? 'Wallpaper catalog copy failed'));
                                }
                            }
                            $committed = $this->configurationSyncCommitAsset(
                                $targetSiteId,
                                $context,
                                'wallpaper_catalog',
                                'wallpaper:' . $catalogId,
                                $sourceRevision,
                                $intended
                            );
                            if (!empty($committed['item'])) { $items[(string)$targetSiteId][] = $committed['item']; }
                            if (empty($committed['ok'])) { throw new \RuntimeException((string)($committed['message'] ?? 'Copied wallpaper catalog did not verify')); }
                        } catch (\Throwable $e) {
                            $errors[] = 'Site #' . $targetSiteId . ' / ' . $catalogId . ': ' . $e->getMessage();
                        }
                    }
                }

                $special = (array)$this->mediaAssetOp([
                    'op'=>'special_catalog_list','site_id'=>$sourceSiteId,'family'=>'cisco_cnf','model'=>'all',
                ]);
                if (!empty($special['ok'])) {
                    foreach ((array)($special['catalogs'] ?? []) as $catalog) {
                        if (empty($catalog['complete'])) { continue; }
                        $catalogId = (string)($catalog['catalog_id'] ?? '');
                        if ($catalogId === '') { continue; }
                        $publishedName = strtolower(basename((string)($catalog['published_name'] ?? '')));
                        $referenced = $publishedName !== '' && isset($requiredWallpapers[$publishedName])
                            ? [$publishedName=>true]
                            : [];
                        if (!$syncAllWallpapers && !$referenced) { continue; }
                        $matchedWallpapers += $referenced;
                        $sourceRevision = $this->configurationSyncCatalogRevision('special_catalog', $catalog);
                        $intended = [
                            'catalog_id'=>$catalogId,
                            'name'=>(string)($catalog['name'] ?? $catalogId),
                            'published_name'=>(string)($catalog['published_name'] ?? ''),
                        ];
                        foreach ($targetSiteIds as $targetSiteId) {
                            try {
                                $prepared = $this->configurationSyncPrepareAsset(
                                    $targetSiteId,
                                    $context,
                                    'special_catalog',
                                    'special:' . $catalogId,
                                    $sourceRevision,
                                    $intended
                                );
                                if (!empty($prepared['item'])) { $items[(string)$targetSiteId][] = $prepared['item']; }
                                if (empty($prepared['allow'])) {
                                    if (($prepared['item']['status'] ?? '') === 'conflict') { $needsConfirmation = true; }
                                    $warnings[] = 'Site #' . $targetSiteId . ' / ' . $catalogId . ': ' . (string)($prepared['message'] ?? 'special catalog sync paused');
                                    continue;
                                }
                                if (empty($prepared['already_current'])) {
                                    $copy = (array)$this->mediaAssetOp([
                                        'op'=>'special_catalog_sync','site_id'=>$sourceSiteId,
                                        'family'=>'cisco_cnf','model'=>'all','set_ids'=>[$catalogId],
                                        'target_site_ids'=>[$targetSiteId],
                                        'overwrite'=>!empty($prepared['overwrite']) ? 1 : 0,
                                    ]);
                                    $operations[] = ['scope'=>'special_catalogs','catalog_id'=>$catalogId,'target_site_id'=>$targetSiteId] + $copy;
                                    if (empty($copy['ok'])) {
                                        throw new \RuntimeException((string)($copy['message'] ?? 'Special catalog copy failed'));
                                    }
                                }
                                $committed = $this->configurationSyncCommitAsset(
                                    $targetSiteId,
                                    $context,
                                    'special_catalog',
                                    'special:' . $catalogId,
                                    $sourceRevision,
                                    $intended
                                );
                                if (!empty($committed['item'])) { $items[(string)$targetSiteId][] = $committed['item']; }
                                if (empty($committed['ok'])) { throw new \RuntimeException((string)($committed['message'] ?? 'Copied special catalog did not verify')); }
                            } catch (\Throwable $e) {
                                $errors[] = 'Site #' . $targetSiteId . ' / ' . $catalogId . ': ' . $e->getMessage();
                            }
                        }
                    }
                } else {
                    $errors[] = (string)($special['message'] ?? 'Source special catalogs are unavailable');
                }

                if ($syncAssetDefaults || $syncTemplateDependencies) {
                    foreach (array_diff_key($requiredWallpapers, $matchedWallpapers) as $filename=>$_) {
                        $errors[] = 'Selected policy references wallpaper ' . $filename . ', but no complete managed source catalog owns it';
                    }
                }

                // Ringtones are immutable named payloads rather than editable catalogs.
                // Preserve destination collisions and report them, but do not create a
                // misleading catalog-level lock for a loose audio filename.
                if ($syncAllWallpapers) {
                    try {
                        $ringtones = $this->mediaReplicationService()->replicateSelection(
                            $sourceSiteId,
                            $targetSiteIds,
                            ['ringtones'],
                            true
                        );
                        $operations[] = ['scope'=>'ringtones'] + $ringtones;
                        if (empty($ringtones['ok'])) { $errors[] = (string)($ringtones['message'] ?? 'Ringtone copy failed'); }
                    } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
                }
            } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        }
        if ($syncAllFirmware || $syncAssetDefaults || $syncTemplateDependencies) {
            try {
                $inventory = (array)$this->tftpOp(['op'=>'firmware_inventory','site_id'=>$sourceSiteId]);
                if (empty($inventory['ok'])) { throw new \RuntimeException((string)($inventory['message'] ?? 'Source firmware inventory is unavailable')); }
                $seen = [];
                $matchedFirmware = [];
                foreach ((array)($inventory['bundles'] ?? []) as $bundle) {
                    if (($bundle['status'] ?? '') !== 'complete') { continue; }
                    $profile = trim((string)($bundle['profile'] ?? ''));
                    $load = trim((string)($bundle['load_name'] ?? ''));
                    $key = strtolower($profile . '|' . $load);
                    if ($profile === '' || $load === '' || isset($seen[$key])) { continue; }
                    $seen[$key] = true;
                    $defaultKey = strtolower($profile . "\0" . $load);
                    if (!$syncAllFirmware && !isset($assetDefaults['firmware'][$defaultKey])) { continue; }
                    if (isset($assetDefaults['firmware'][$defaultKey])) { $matchedFirmware[$defaultKey] = true; }
                    $copy = (array)$this->tftpOp([
                        'op'=>'sync_firmware_bundle', 'site_id'=>$sourceSiteId,
                        'profile'=>$profile, 'load_name'=>$load,
                        'target_site_ids'=>$targetSiteIds, 'overwrite'=>!empty($context['force']) ? 1 : 0,
                    ]);
                    $operations[] = ['scope'=>'firmware','profile'=>$profile,'load_name'=>$load] + $copy;
                    if (empty($copy['ok'])) {
                        if (empty($context['force'])) { $needsConfirmation = true; }
                        $errors[] = "$profile / $load: " . (string)($copy['message'] ?? 'copy failed');
                    }
                }
                if ($syncAssetDefaults || $syncTemplateDependencies) {
                    foreach (array_diff_key($assetDefaults['firmware'], $matchedFirmware) as [$profile,$load]) {
                        $errors[] = "Selected policy references firmware $profile / $load, but that exact source package is not complete";
                    }
                }
                if ($syncAllFirmware || $requiredTimezoneAssets || $requiredLegacyTimezoneKeys) {
                    $versions = (array)$this->tftpOp(['op'=>'timezone_versions','site_id'=>$sourceSiteId]);
                    if (empty($versions['ok'])) { throw new \RuntimeException((string)($versions['message'] ?? 'Source timezone inventory is unavailable')); }
                    $matchedTimezoneAssets = [];
                    $matchedLegacyTimezone = [];
                    $bundleIds = [];
                    foreach ((array)($versions['assets'] ?? []) as $asset) {
                        $assetKey = strtolower(trim((string)($asset['asset_key'] ?? '')));
                        $profile = (string)($asset['profile'] ?? '');
                        $bundleId = (int)($asset['source_bundle_id'] ?? 0);
                        if ($bundleId < 1 || $assetKey === '' || empty($asset['selectable'])) { continue; }
                        if (isset($requiredTimezoneAssets[$assetKey])
                            && (string)$requiredTimezoneAssets[$assetKey] === $profile) {
                            $matchedTimezoneAssets[$assetKey] = true;
                            $bundleIds[$bundleId] = true;
                        }
                    }
                    foreach ((array)($versions['bundles'] ?? []) as $bundle) {
                        $key = strtolower(trim((string)($bundle['bundle_key'] ?? '')));
                        $bundleId = (int)($bundle['id'] ?? 0);
                        $status = strtolower(trim((string)($bundle['status'] ?? '')));
                        if ($key === '' || $bundleId < 1 || !in_array($status, ['complete','partial'], true)) { continue; }
                        if ($syncAllFirmware || !empty($requiredLegacyTimezoneKeys[$key])) { $bundleIds[$bundleId] = true; }
                        if (!empty($requiredLegacyTimezoneKeys[$key])) { $matchedLegacyTimezone[$key] = true; }
                    }
                    foreach (array_keys($bundleIds) as $bundleId) {
                        $copy = (array)$this->tftpOp([
                            'op'=>'sync_timezone_version','site_id'=>$sourceSiteId,
                            'bundle_id'=>$bundleId,'target_site_ids'=>$targetSiteIds,
                        ]);
                        $operations[] = ['scope'=>'cisco_timezone_assets','bundle_id'=>$bundleId] + $copy;
                        if (empty($copy['ok'])) { $errors[] = 'Cisco timezone asset bundle #' . $bundleId . ': ' . (string)($copy['message'] ?? 'copy failed'); }
                    }
                    foreach (array_diff_key($requiredTimezoneAssets, $matchedTimezoneAssets) as $key=>$profile) {
                        $errors[] = "A pushed setting or template references Cisco timezone asset $profile / $key, but that retained source asset is unavailable";
                    }
                    foreach (array_diff_key($requiredLegacyTimezoneKeys, $matchedLegacyTimezone) as $key=>$_) {
                        $errors[] = 'A pushed template references Cisco timezone version ' . $key . ', but that retained source version is unavailable';
                    }
                }
                if ($syncAllFirmware) {
                    $locale = (array)$this->tftpOp([
                        'op'=>'cisco_locale_inventory','site_id'=>$sourceSiteId,
                        'user_locale'=>'English_United_States',
                    ]);
                    if (empty($locale['ok'])) { throw new \RuntimeException((string)($locale['message'] ?? 'Source locale inventory is unavailable')); }
                    foreach ((array)($locale['profiles'] ?? []) as $localeProfile=>$localeState) {
                        if (($localeState['status'] ?? '') !== 'complete') { continue; }
                        $copy = (array)$this->tftpOp([
                            'op'=>'sync_cisco_locale_bundle','site_id'=>$sourceSiteId,
                            'profile'=>$localeProfile,'user_locale'=>'English_United_States',
                            'network_locale'=>'','target_site_ids'=>$targetSiteIds,
                            'overwrite'=>!empty($context['force']) ? 1 : 0,
                        ]);
                        $operations[] = ['scope'=>'cisco_locale','profile'=>$localeProfile] + $copy;
                        if (empty($copy['ok'])) { $errors[] = (string)($copy['message'] ?? 'Cisco locale copy failed'); }
                    }
                }
            } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
        }
        foreach ($items as $targetId=>$targetItems) {
            $deduped = [];
            foreach ($targetItems as $item) {
                $key = (string)($item['object_type'] ?? '') . '|' . (string)($item['object_key'] ?? '');
                if ($key !== '|') { $deduped[$key] = $item; }
            }
            $items[$targetId] = array_values($deduped);
        }
        return [
            'ok'=>!$errors,
            'status'=>$errors ? ($operations ? 'partial' : 'error') : ($warnings ? 'warning' : ($operations ? 'success' : 'skipped')),
            'operations'=>$operations,
            'items'=>$items,
            'needs_confirmation'=>$needsConfirmation,
            'warnings'=>array_values(array_unique($warnings)),
            'message'=>$errors
                ? implode('; ', array_values(array_unique($errors)))
                : ($warnings ? implode('; ', array_values(array_unique($warnings))) : ($operations ? 'Selected managed dependencies copied' : 'No managed dependency scope selected')),
        ];
    }

    /** Decide whether one destination catalog may be updated by an automatic job. */
    public function configurationSyncPrepareAsset(
        int $targetSiteId,
        array $context,
        string $objectType,
        string $objectKey,
        string $sourceRevision,
        array $intended
    ): array {
        if (!$this->isSiteLocal($targetSiteId)) {
            return (array)$this->remoteQuery($targetSiteId, 'configurationSyncPrepareAssetLocal', [
                $context,$objectType,$objectKey,$sourceRevision,$intended,
            ]);
        }
        return $this->configurationSyncPrepareAssetLocal($context,$objectType,$objectKey,$sourceRevision,$intended);
    }

    public function configurationSyncPrepareAssetLocal(
        array $context,
        string $objectType,
        string $objectKey,
        string $sourceRevision,
        array $intended
    ): array {
        $context = $this->configurationSyncAssetContext($context);
        $current = $this->configurationSyncCurrentCatalog($objectType, $objectKey);
        $currentRevision = (string)($current['revision'] ?? '');
        $existing = $this->configurationSyncItemRow((string)$context['job_key'],$objectType,$objectKey);
        $otherOwner = $this->configurationSyncOwnerConflictByKey($objectType,$objectKey,(string)$context['job_key']);
        if ($otherOwner) {
            $item = $this->upsertConfigurationSyncItem(
                $context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,
                'owner_conflict',$intended,[],
                'Another automatic job already owns this destination catalog'
            );
            return ['ok'=>true,'allow'=>false,'item'=>$item,'message'=>'another configuration job already owns this catalog'];
        }
        if ($existing && (string)$existing['status'] === 'paused' && empty($context['force'])) {
            return [
                'ok'=>true,'allow'=>false,'item'=>$this->configurationSyncItemOutput($existing),
                'message'=>$this->configurationSyncPauseMessage($existing),
            ];
        }
        if ($existing
            && in_array((string)$existing['status'], ['active','missing_dependency'], true)
            && $currentRevision !== ''
            && (string)$existing['destination_revision'] !== ''
            && !hash_equals((string)$existing['destination_revision'], $currentRevision)
            && !hash_equals($sourceRevision, $currentRevision)
            && empty($context['force'])) {
            $item = $this->upsertConfigurationSyncItem(
                $context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,
                'paused',$intended,[],
                'Destination catalog changed after its last automatic synchronization'
            );
            return ['ok'=>true,'allow'=>false,'item'=>$item,'message'=>'destination edit detected; automatic sync for this catalog is paused'];
        }
        if ($currentRevision !== '' && hash_equals($sourceRevision, $currentRevision)) {
            $item = $this->upsertConfigurationSyncItem(
                $context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,'active',$intended,[]
            );
            return ['ok'=>true,'allow'=>true,'already_current'=>true,'overwrite'=>false,'item'=>$item,'message'=>'catalog already matches source'];
        }
        if (!$existing && !empty($current['exists']) && empty($context['force'])) {
            $item = $this->upsertConfigurationSyncItem(
                $context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,
                'conflict',$intended,[],
                'An unmanaged destination catalog already uses this catalog ID'
            );
            return ['ok'=>true,'allow'=>false,'item'=>$item,'message'=>'destination already has a different unmanaged catalog; explicit confirmation is required'];
        }
        if ($existing && in_array((string)$existing['status'], ['conflict','owner_conflict'], true) && empty($context['force'])) {
            return ['ok'=>true,'allow'=>false,'item'=>$this->configurationSyncItemOutput($existing),'message'=>'catalog conflict remains paused pending explicit confirmation'];
        }
        return [
            'ok'=>true,'allow'=>true,'already_current'=>false,
            'overwrite'=>!empty($current['exists']),
            'message'=>!empty($current['exists']) ? 'owned catalog may be updated' : 'catalog may be created',
        ];
    }

    /** Verify the destination after transfer and establish catalog ownership. */
    public function configurationSyncCommitAsset(
        int $targetSiteId,
        array $context,
        string $objectType,
        string $objectKey,
        string $sourceRevision,
        array $intended
    ): array {
        if (!$this->isSiteLocal($targetSiteId)) {
            return (array)$this->remoteQuery($targetSiteId, 'configurationSyncCommitAssetLocal', [
                $context,$objectType,$objectKey,$sourceRevision,$intended,
            ]);
        }
        return $this->configurationSyncCommitAssetLocal($context,$objectType,$objectKey,$sourceRevision,$intended);
    }

    public function configurationSyncCommitAssetLocal(
        array $context,
        string $objectType,
        string $objectKey,
        string $sourceRevision,
        array $intended
    ): array {
        $context = $this->configurationSyncAssetContext($context);
        $current = $this->configurationSyncCurrentCatalog($objectType, $objectKey);
        $destinationRevision = (string)($current['revision'] ?? '');
        $verified = !empty($current['exists'])
            && $destinationRevision !== ''
            && hash_equals($sourceRevision, $destinationRevision);
        $missing = $verified ? [] : [$objectKey=>'copied catalog did not verify against its source revision'];
        $item = $this->upsertConfigurationSyncItem(
            $context,$objectType,$objectKey,0,$sourceRevision,$destinationRevision,
            $verified ? 'active' : 'missing_dependency',$intended,$missing
        );
        return [
            'ok'=>$verified,'item'=>$item,
            'message'=>$verified ? 'Catalog synchronized and verified' : 'Catalog copy did not verify; destination fallback remains in effect',
        ];
    }

    /** @return array<string,mixed> */
    private function configurationSyncAssetContext(array $context): array
    {
        $metadata = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeTrackingItem([
            'job_key'=>(string)($context['job_key'] ?? ''),
            'source_site_key'=>(string)($context['source_site_key'] ?? ''),
            'source_site_name'=>(string)($context['source_site_name'] ?? ''),
            'object_type'=>'wallpaper_catalog', 'object_key'=>'wallpaper:__context__',
            'status'=>'active',
        ]);
        return [
            'job_key'=>$metadata['job_key'],
            'job_id'=>max(0,(int)($context['job_id'] ?? 0)),
            'source_site_key'=>$metadata['source_site_key'],
            'source_site_name'=>$metadata['source_site_name'],
            'soft_lock'=>!empty($context['soft_lock']),
            'force'=>!empty($context['force']),
        ];
    }

    /** @return array{exists:bool,revision:string,catalog?:array<string,mixed>} */
    private function configurationSyncCurrentCatalog(string $objectType, string $objectKey): array
    {
        if (!in_array($objectType, ['wallpaper_catalog','special_catalog'], true)) {
            throw new \InvalidArgumentException('Unsupported synchronized catalog type');
        }
        $prefix = $objectType === 'wallpaper_catalog' ? 'wallpaper:' : 'special:';
        if (!str_starts_with($objectKey, $prefix)) { throw new \InvalidArgumentException('Synchronized catalog key is invalid'); }
        $catalogId = substr($objectKey, strlen($prefix));
        if ($catalogId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $catalogId) !== 1) {
            throw new \InvalidArgumentException('Synchronized catalog ID is invalid');
        }
        if ($objectType === 'wallpaper_catalog') {
            $listed = (array)$this->tftpOp(['op'=>'wallpaper_set_list','site_id'=>$this->localSiteId()]);
            if (empty($listed['ok'])) { throw new \RuntimeException((string)($listed['message'] ?? 'Cannot inspect destination wallpaper catalogs')); }
            foreach ((array)($listed['sets'] ?? []) as $catalog) {
                if (hash_equals($catalogId, (string)($catalog['id'] ?? ''))) {
                    return ['exists'=>true,'revision'=>$this->configurationSyncCatalogRevision($objectType,$catalog),'catalog'=>$catalog];
                }
            }
            return ['exists'=>false,'revision'=>''];
        }
        $listed = (array)$this->mediaAssetOpLocal([
            'op'=>'special_catalog_list','site_id'=>$this->localSiteId(),'family'=>'cisco_cnf','model'=>'all',
        ]);
        if (empty($listed['ok'])) { throw new \RuntimeException((string)($listed['message'] ?? 'Cannot inspect destination special catalogs')); }
        foreach ((array)($listed['catalogs'] ?? []) as $catalog) {
            if (hash_equals($catalogId, (string)($catalog['catalog_id'] ?? ''))) {
                return ['exists'=>true,'revision'=>$this->configurationSyncCatalogRevision($objectType,$catalog),'catalog'=>$catalog];
            }
        }
        return ['exists'=>false,'revision'=>''];
    }

    private function configurationSyncCatalogRevision(string $objectType, array $catalog): string
    {
        if ($objectType === 'special_catalog') {
            return $this->configurationSyncDigest(array_intersect_key($catalog, array_fill_keys([
                'catalog_id','name','source_name','published_name','transform','settings','revision','complete','manifest_sha256',
            ], true)));
        }
        $variants = [];
        foreach ((array)($catalog['variants'] ?? []) as $variant) {
            $stable = array_intersect_key((array)$variant, array_fill_keys([
                'profile','enabled','models','source','source_sha256','full','thumbnail',
                'full_sha256','thumbnail_sha256','full_transform','thumbnail_transform','delivery','dir',
            ], true));
            $variants[(string)($stable['profile'] ?? count($variants))] = $stable;
        }
        return $this->configurationSyncDigest([
            'id'=>(string)($catalog['id'] ?? ''),'name'=>(string)($catalog['name'] ?? ''),
            'source'=>(string)($catalog['source'] ?? ''),'source_sha256'=>(string)($catalog['source_sha256'] ?? ''),
            'variants'=>$variants,'original_scope'=>(array)($catalog['original_scope'] ?? []),
        ]);
    }

    public function configurationSyncApply(int $targetSiteId, array $snapshot, array $options): array
    {
        $targetSiteId = $targetSiteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($targetSiteId)) {
            return (array)$this->remoteQuery($targetSiteId, 'configurationSyncApplyLocal', [$snapshot, $options]);
        }
        return $this->configurationSyncApplyLocal($snapshot, $options);
    }

    /** Apply portable policy without replacing destination PBX identity or credentials. */
    public function configurationSyncApplyLocal(array $snapshot, array $options): array
    {
        return $this->withConfigurationSyncLock(function () use ($snapshot,$options): array {
            $result = $this->configurationSyncApplyUnlocked($snapshot, $options);
            $result['dependency_recovery'] = $this->reconcileConfigurationSyncDependenciesLocal(false);
            return $result;
        });
    }

    /** Retry retained intent only: no source RPC, force, ownership release or phone command. */
    public function reconcileConfigurationSyncDependenciesLocal(bool $refresh = true): array
    {
        if ($this->configurationSyncReconciling) { return ['ok'=>true,'resolved'=>0,'message'=>'Dependency recovery is already running']; }
        try {
            return $this->withConfigurationSyncLock(function () use ($refresh): array {
                if ($this->db->inTransaction()) { throw new \LogicException('Dependency recovery requires no active transaction'); }
                \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
                $query = $this->db->prepare("SELECT * FROM ucmp_configuration_sync_items WHERE target_site_id=? AND (status='missing_dependency' OR (status='active' AND phone_marker_pending=1)) ORDER BY updated,id LIMIT 100");
                $query->execute([$this->localSiteId()]);
                $rows = $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if ($refresh) { $this->configurationSyncLiveAssets = []; }
                $resolved = 0; $errors = []; $started = microtime(true);
                $this->configurationSyncReconciling = true;
                try {
                    foreach ($rows as $row) {
                        if (microtime(true) - $started > 30) { break; }
                        try {
                            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::assertControllableJobKey((string)$row['job_key']);
                            $source = json_decode((string)$row['intended_json'], true, 512, JSON_THROW_ON_ERROR);
                            if (!is_array($source)) { throw new \RuntimeException('Retained dependency policy is invalid'); }
                            $type = (string)$row['object_type'];
                            $snapshot = ['schema'=>1,'source_site'=>['site_key'=>$row['source_site_key'],'name'=>$row['source_site_name']]];
                            if (in_array($type,['template','group'],true)) {
                                $scope = $type === 'template' ? 'templates' : 'groups';
                                $source['_object_key'] = $row['object_key'];
                                $source['_source_revision'] = $row['source_revision'];
                                $snapshot[$scope] = [$source];
                            } elseif (in_array($type,['default_templates','settings_default_template_selectors'],true)) {
                                $scope = $type;
                                if ($type === 'default_templates') { $snapshot[$scope] = [$source]; }
                                else { $snapshot['settings'][$scope] = ['default_templates'=>[$source]]; }
                            } elseif ($type === 'site_defaults' || in_array($type,\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES,true)) {
                                $scope = $type;
                                $values = isset($source['profile'])
                                    ? [(string)$source['setting']=>[(string)$source['profile']=>$source['value']]]
                                    : [(string)$source['setting']=>$source['value']];
                                if ($type === 'site_defaults') { $snapshot[$scope] = $values; }
                                else { $snapshot['settings'][$scope] = $values; }
                            } else { continue; }
                            $snapshot['scopes'] = [$scope];
                            $result = $this->configurationSyncApplyUnlocked($snapshot, [
                                'job_key'=>$row['job_key'],'job_id'=>(int)$row['job_id'],
                                'source_site_key'=>$row['source_site_key'],'source_site_name'=>$row['source_site_name'],
                                'soft_lock'=>!empty($row['soft_lock']),'force'=>false,
                            ]);
                            if (empty($result['ok'])) { $errors[] = $row['object_key'] . ': ' . $result['message']; }
                            foreach ((array)($result['items'] ?? []) as $item) {
                                if ($row['status'] === 'missing_dependency' && ($item['status'] ?? '') === 'active') { $resolved++; }
                            }
                        } catch (\Throwable $error) {
                            $errors[] = $row['object_key'] . ': ' . $error->getMessage();
                            if ($error instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($error->receipt['status'] ?? '') === 'unknown') { break; }
                        } finally {
                            // Round-robin unresolved items; a permanently absent asset cannot starve later arrivals.
                            $this->db->prepare('UPDATE ucmp_configuration_sync_items SET updated=? WHERE id=?')->execute([time(),(int)$row['id']]);
                        }
                    }
                } finally { $this->configurationSyncReconciling = false; }
                return ['ok'=>!$errors,'resolved'=>$resolved,'errors'=>$errors,
                    'message'=>$errors ? implode('; ',$errors) : $resolved . ' dependency item(s) resolved automatically'];
            });
        } catch (\Throwable $error) { return ['ok'=>false,'resolved'=>0,'message'=>$error->getMessage()]; }
    }

    /** Internal service boundary; deliberately absent from the RPC allowlist. */
    public function withConfigurationSyncLock(callable $operation): mixed
    {
        if ($this->configurationWriteLocked) { return $operation(); }
        $name = 'ucmp-configuration-' . $this->localSiteId();
        $mysql = (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite';
        if ($mysql) {
            $lock = $this->db->prepare('SELECT GET_LOCK(?,0)');
            $lock->execute([$name]);
            if ((int)$lock->fetchColumn() !== 1) { throw new \RuntimeException('Another configuration edit or import is in progress; retry from current state'); }
        }
        $this->configurationWriteLocked=true;
        try { return $operation(); }
        finally {
            $this->configurationWriteLocked=false;
            if ($mysql) {
                try { $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
                catch (\Throwable) { /* Connection teardown releases the named lock. */ }
            }
        }
    }

    private function configurationSyncApplyUnlocked(array $snapshot, array $options): array
    {
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        if ((int)($snapshot['schema'] ?? 0) !== 1 || empty($snapshot['source_site'])) {
            throw new \InvalidArgumentException('Unsupported configuration sync snapshot');
        }
        $incomingScopes = array_map('strval', is_array($snapshot['scopes'] ?? null) ? $snapshot['scopes'] : []);
        $unknownScopes = array_values(array_diff($incomingScopes, \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SCOPES));
        if ($unknownScopes) {
            throw new \InvalidArgumentException('Unsupported configuration sync scope: ' . implode(', ', $unknownScopes));
        }
        $ackScopes = array_values(array_unique(array_intersect(
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SCOPES,
            $incomingScopes
        )));
        sort($ackScopes, SORT_STRING);
        if (!$ackScopes) { throw new \InvalidArgumentException('Choose at least one configuration sync scope'); }
        foreach (['templates','groups','phonebooks','site_defaults','default_templates'] as $scope) {
            if (array_key_exists($scope, $snapshot) !== in_array($scope, $ackScopes, true)) {
                throw new \InvalidArgumentException('Configuration payload must match its explicit scope: ' . $scope);
            }
            if (array_key_exists($scope, $snapshot) && !is_array($snapshot[$scope])) {
                throw new \InvalidArgumentException('Configuration scope payload must be an array: ' . $scope);
            }
        }
        if (array_key_exists('settings', $snapshot) && !is_array($snapshot['settings'])) { throw new \InvalidArgumentException('Settings scope payload must be an array'); }
        $webScope = in_array('web_directory', $ackScopes, true);
        if ($webScope !== array_key_exists('web_directory', $snapshot)) {
            throw new \InvalidArgumentException('Web-directory policy requires its explicit synchronization scope and payload');
        }
        if ($webScope && empty($options['web_directory']['access_confirmed'])) {
            throw new \InvalidArgumentException('The controlling job has not confirmed web-directory access-policy replication');
        }
        $rawFilter = is_array($snapshot['object_filter'] ?? null) ? $snapshot['object_filter'] : [];
        $ackFilter = [
            'templates'=>array_values(array_unique(array_filter(array_map('intval', is_array($rawFilter['templates'] ?? null) ? $rawFilter['templates'] : []), static fn(int $id): bool => $id > 0))),
            'groups'=>array_values(array_unique(array_filter(array_map('intval', is_array($rawFilter['groups'] ?? ($rawFilter['group_ids'] ?? null)) ? ($rawFilter['groups'] ?? $rawFilter['group_ids']) : []), static fn(int $id): bool => $id > 0))),
            'settings'=>array_values(array_unique(array_intersect(
                \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES,
                array_map('strval', is_array($rawFilter['settings'] ?? ($rawFilter['settings_categories'] ?? null)) ? ($rawFilter['settings'] ?? $rawFilter['settings_categories']) : [])
            ))),
        ];
        if (array_key_exists('setting_keys', $rawFilter)) { $ackFilter['setting_keys'] = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::settingKeys($rawFilter['setting_keys']); }
        sort($ackFilter['templates'], SORT_NUMERIC);
        sort($ackFilter['groups'], SORT_NUMERIC);
        sort($ackFilter['settings'], SORT_STRING);
        if ($ackFilter['templates'] && !in_array('templates', $ackScopes, true)) {
            throw new \InvalidArgumentException('A template filter requires the templates scope');
        }
        if ($ackFilter['groups'] && !in_array('groups', $ackScopes, true)) {
            throw new \InvalidArgumentException('A group filter requires the groups scope');
        }
        if (array_diff($ackFilter['settings'], array_values(array_intersect(\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES, $ackScopes)))) {
            throw new \InvalidArgumentException('A Settings category filter requires the matching Settings scope');
        }
        $settingScopes = $ackFilter['settings'] ?: array_values(array_intersect(\FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES, $ackScopes));
        if (array_key_exists('setting_keys', $ackFilter)) {
            if (array_diff(array_keys((array)($snapshot['site_defaults'] ?? [])), $ackFilter['setting_keys'])) {
                throw new \InvalidArgumentException('Site-default payload contains fields outside the selected list');
            }
            foreach ((array)($snapshot['settings'] ?? []) as $scope=>$values) {
                if ($scope !== 'settings_default_template_selectors' && array_diff(array_keys((array)$values), $ackFilter['setting_keys'])) {
                    throw new \InvalidArgumentException('Settings payload contains fields outside the selected list');
                }
            }
        }
        $settingPayloads = array_keys($snapshot['settings'] ?? []);
        if (array_diff($settingScopes, $settingPayloads) || array_diff($settingPayloads, $settingScopes)) {
            throw new \InvalidArgumentException('Settings category payloads must match their explicit scopes and filter');
        }
        $jobKey = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeJobKey((string)($options['job_key'] ?? ''));
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::assertControllableJobKey($jobKey);
        $sourceKey = trim((string)($options['source_site_key'] ?? ($snapshot['source_site']['site_key'] ?? '')));
        $sourceName = trim((string)($options['source_site_name'] ?? ($snapshot['source_site']['name'] ?? '')));
        $metadata = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeTrackingItem([
            'job_key'=>$jobKey, 'source_site_key'=>$sourceKey, 'source_site_name'=>$sourceName,
            'object_type'=>'site_defaults', 'object_key'=>'__context__', 'status'=>'active',
        ]);
        $context = [
            'job_key'=>$metadata['job_key'], 'job_id'=>max(0,(int)($options['job_id'] ?? 0)),
            'source_site_key'=>$metadata['source_site_key'], 'source_site_name'=>$metadata['source_site_name'],
            'target_site_id'=>$this->localSiteId(), 'soft_lock'=>!empty($options['soft_lock']),
            'force'=>!empty($options['force']),
        ];
        $items = [];
        $warnings = [];
        $errors = [];
        $needsConfirmation = false;
        $changedTemplateIds = [];
        $changedGroupIds = [];
        $broadChange = false;
        // A CLI worker may apply several jobs after separate dependency copies.
        if (!$this->configurationSyncReconciling) { $this->configurationSyncLiveAssets = []; }
        $this->configurationSyncApplying = true;
        try {
            if ($webScope) {
                try {
                    $result = $this->applyConfigurationSyncWebDirectory(
                        (array)$snapshot['web_directory'],
                        $context + ['hard_lock'=>!empty($options['web_directory']['hard_lock'])]
                    );
                    $items[] = $result['item'];
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Web directory: ' . $e->getMessage(); }
            }
            foreach ((array)($snapshot['phonebooks'] ?? []) as $book) {
                try {
                    $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncPhonebook((array)$book, $context));
                    $items[] = $result['item'];
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Phonebook: ' . $e->getMessage(); }
            }
            foreach ((array)($snapshot['templates'] ?? []) as $template) {
                try {
                    $this->resolveConfigurationSyncTemplateDependencies((array)$template);
                    $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncTemplate((array)$template, $context));
                    $items[] = $result['item'];
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                    if (!empty($result['changed']) && !empty($result['item']['destination_id'])) { $changedTemplateIds[] = (int)$result['item']['destination_id']; }
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Template: ' . $e->getMessage(); }
            }
            if (array_key_exists('site_defaults', $snapshot)) {
                try {
                    $this->resolveConfigurationSyncAssetSettings((array)$snapshot['site_defaults'], true);
                    $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncSiteDefaults((array)$snapshot['site_defaults'], $context));
                    $items = array_merge($items, (array)($result['items'] ?? (isset($result['item']) ? [$result['item']] : [])));
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                    $broadChange = $broadChange || !empty($result['changed']);
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Site defaults: ' . $e->getMessage(); }
            }
            if (array_key_exists('default_templates', $snapshot)) {
                try {
                    $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncDefaultTemplates((array)$snapshot['default_templates'], $context));
                    $items = array_merge($items, (array)($result['items'] ?? (isset($result['item']) ? [$result['item']] : [])));
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                    $broadChange = $broadChange || !empty($result['changed']);
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Default templates: ' . $e->getMessage(); }
            }
            foreach ((array)($snapshot['groups'] ?? []) as $group) {
                try {
                    $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncGroup((array)$group, $context));
                    $items[] = $result['item'];
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                    if (!empty($result['changed']) && !empty($result['item']['destination_id'])) { $changedGroupIds[] = (int)$result['item']['destination_id']; }
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = 'Group: ' . $e->getMessage(); }
            }
            foreach ((array)($snapshot['settings'] ?? []) as $scope=>$settings) {
                if (!in_array((string)$scope, \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_SCOPES, true)) { continue; }
                try {
                    if ((string)$scope === 'settings_assets') { $this->resolveConfigurationSyncAssetSettings((array)$settings, true); }
                    if ((string)$scope === 'settings_cisco_policy' && array_filter(array_intersect_key((array)$settings,array_flip(['dial_template_file','softkey_file','featurepolicy_file'])))) {
                        $this->configurationSyncInventory('list', ['path'=>'']);
                    }
                    if ((string)$scope === 'settings_default_template_selectors') {
                        $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncDefaultTemplates((array)($settings['default_templates'] ?? []), $context, (string)$scope));
                    } else {
                        $result = $this->configurationSyncTransaction(fn(): array => $this->applyConfigurationSyncSettingsCategory((string)$scope, (array)$settings, $context), (string)$scope);
                    }
                    $items = array_merge($items, (array)($result['items'] ?? (isset($result['item']) ? [$result['item']] : [])));
                    $needsConfirmation = $needsConfirmation || !empty($result['needs_confirmation']);
                    if (!empty($result['warning'])) { $warnings[] = $result['warning']; }
                    $broadChange = $broadChange || !empty($result['changed']);
                } catch (\Throwable $e) { if ($e instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException && ($e->receipt['status'] ?? '') === 'unknown') { throw $e; } $errors[] = ucfirst(str_replace('_',' ',(string)$scope)) . ': ' . $e->getMessage(); }
            }
        } catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
            throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException($error->getMessage(),
                $error->receipt + ['completed_items'=>$items,'warnings'=>$warnings,'errors'=>$errors]);
        } finally {
            $this->configurationSyncApplying = false;
        }
        $reportingErrors = [];
        $pending = $this->db->prepare('SELECT id,object_type,destination_id FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? AND phone_marker_pending=1');
        $pending->execute([$jobKey,$this->localSiteId()]);
        foreach ($pending->fetchAll(PDO::FETCH_ASSOC) ?: [] as $repair) {
            try {
                $type = (string)$repair['object_type'];
                if ($type === 'group') { $this->markConfigurationSyncGroupPhonesPending([(int)$repair['destination_id']]); }
                else { $this->markConfigurationSyncPhonesPending($type === 'template' ? [(int)$repair['destination_id']] : [], $type !== 'template'); }
                $this->db->prepare('UPDATE ucmp_configuration_sync_items SET phone_marker_pending=0 WHERE id=?')->execute([(int)$repair['id']]);
            } catch (\Throwable $error) { $reportingErrors[] = 'Policy was imported, but dependent phone status could not be updated: ' . $error->getMessage(); }
        }
        $errors = array_merge($errors, $reportingErrors);
        $ok = !$errors;
        $status = $errors ? ($items ? 'partial' : 'error') : (($warnings || $needsConfirmation) ? 'warning' : 'success');
        return [
            'ok'=>$ok, 'status'=>$status, 'needs_confirmation'=>$needsConfirmation,
            'scopes'=>$ackScopes, 'object_filter'=>$ackFilter,
            'items'=>$items, 'warnings'=>array_values(array_unique($warnings)), 'errors'=>$errors,
            'reporting_error'=>implode('; ', $reportingErrors),
            'message'=>$errors
                ? implode('; ', $errors)
                : ($needsConfirmation ? 'Destination changes were preserved; confirm the reported conflicts before replacing them' : ($warnings ? 'Configuration imported with destination fallbacks or paused items; missing dependencies retry automatically when available' : 'Configuration imported; destination PBX identity and credentials were preserved')),
        ];
    }

    /** Commit each database object/category together with ownership and repair intent. */
    private function configurationSyncTransaction(callable $apply, string $scope = ''): array
    {
        if ($this->db->inTransaction()) { throw new \RuntimeException('Configuration sync requires its own object transaction'); }
        $file = ['settings_blf_federation'=>'mesh-scheduler','settings_phone_automation'=>'phone-rebuild-scheduler'][$scope] ?? '';
        if ($file !== '') {
            $lock = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock('/var/lib/asterisk/ucmprovisioner/' . $file . '.lock');
            if ($lock === false) { throw new \RuntimeException('The schedule is running; settings were not changed. Retry after it finishes.'); }
            $this->configurationSyncScheduleLocks[$file] = $lock;
        }
        $this->configurationSyncAfterCommit = [];
        $commitAttempted = false;
        try {
            if (!$this->db->beginTransaction()) { throw new \RuntimeException('Cannot begin configuration sync transaction'); }
            $result = $apply();
            if (!empty($result['changed'])) {
                foreach ((array)($result['items'] ?? (isset($result['item']) ? [$result['item']] : [])) as $item) {
                    if (in_array((string)($item['status'] ?? ''), ['active','missing_dependency'], true)
                        && !in_array((string)($item['object_type'] ?? ''), ['phonebook','web_directory'], true)) {
                        $this->db->prepare('UPDATE ucmp_configuration_sync_items SET phone_marker_pending=1 WHERE id=?')->execute([(int)$item['id']]);
                    }
                }
            }
            $commitAttempted = true;
            if (!$this->db->commit()) { throw new \RuntimeException('Configuration commit was not confirmed'); }
        } catch (\Throwable $error) {
            $this->configurationSyncAfterCommit = [];
            $transactionActive = $this->db->inTransaction();
            if ($transactionActive) {
                try { $this->db->rollBack(); } catch (\Throwable) { /* The outcome stays unknown. */ }
            }
            if ($commitAttempted) {
                throw new \FreePBX\modules\Ucmprovisioner\RpcOperationException(
                    'Configuration commit is unconfirmed; reconcile saved objects and ownership before retrying. '
                        . ($transactionActive ? 'Database rejected the commit' : 'The database transaction ended before commit confirmation')
                        . ' (' . get_class($error) . ', code ' . (string)$error->getCode() . ').',
                    ['ok'=>false,'status'=>'unknown','failed_stage'=>'configuration_commit','error_type'=>get_class($error),'error_code'=>(string)$error->getCode(),
                     'transaction_active'=>$transactionActive,'items'=>$result['items'] ?? (isset($result['item']) ? [$result['item']] : [])]
                );
            }
            throw $error;
        } finally {
            foreach ($this->configurationSyncScheduleLocks as $lock) { flock($lock, LOCK_UN); fclose($lock); }
            $this->configurationSyncScheduleLocks = [];
        }
        $publications = $this->configurationSyncAfterCommit;
        $this->configurationSyncAfterCommit = [];
        foreach ($publications as $publish) {
            try { $publish(); }
            catch (\Throwable $error) { $result['warning'] = trim(($result['warning'] ?? '') . '; ' . $error->getMessage(), '; '); }
        }
        return $result;
    }

    public function configurationSyncResume(int $targetSiteId, string $jobKey, string $objectType, string $objectKey): array
    {
        if (!$this->isSiteLocal($targetSiteId)) {
            return (array)$this->remoteQuery($targetSiteId, 'configurationSyncResumeLocal', [$jobKey,$objectType,$objectKey]);
        }
        return $this->configurationSyncResumeLocal($jobKey, $objectType, $objectKey);
    }

    public function configurationSyncResumeLocal(string $jobKey, string $objectType, string $objectKey): array
    {
        return $this->withConfigurationSyncLock(fn(): array => $this->configurationSyncResumeUnlocked($jobKey, $objectType, $objectKey));
    }

    private function configurationSyncResumeUnlocked(string $jobKey, string $objectType, string $objectKey): array
    {
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        $jobKey = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeJobKey($jobKey);
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::assertControllableJobKey($jobKey);
        $objectType = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectType($objectType);
        $objectKey = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectKey($objectKey);
        if ($objectType === 'web_directory') {
            if ($objectKey !== 'web-directory-policy' || !$this->webDirectory()->resumeManagedPolicy($jobKey)) {
                return ['ok'=>false, 'message'=>'This job does not own a paused web-directory policy'];
            }
        }
        $stmt = $this->db->prepare("UPDATE ucmp_configuration_sync_items SET status='active',destination_revision=NULL,paused_at=0,paused_by=NULL,pause_reason=NULL,updated=? WHERE job_key=? AND target_site_id=? AND object_type=? AND object_key=? AND status='paused'");
        $stmt->execute([time(),$jobKey,$this->localSiteId(),$objectType,$objectKey]);
        return ['ok'=>$stmt->rowCount() === 1,'message'=>$stmt->rowCount() === 1 ? 'Object sync resumed; the next manual or scheduled run will reconcile it' : 'Synchronized object was not found'];
    }

    /** Release ownership metadata only; synchronized destination content is intentionally preserved. */
    public function configurationSyncRelease(int $targetSiteId, string $jobKey): array
    {
        if (!$this->isSiteLocal($targetSiteId)) {
            return (array)$this->remoteQuery($targetSiteId, 'configurationSyncReleaseLocal', [$jobKey]);
        }
        return $this->configurationSyncReleaseLocal($jobKey);
    }

    public function configurationSyncReleaseLocal(string $jobKey): array
    {
        return $this->withConfigurationSyncLock(fn(): array => $this->configurationSyncReleaseUnlocked($jobKey));
    }

    private function configurationSyncReleaseUnlocked(string $jobKey): array
    {
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        $jobKey = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeJobKey($jobKey);
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::assertControllableJobKey($jobKey);
        if (str_starts_with($jobKey, 'cfgw:')) {
            $this->webDirectory()->releaseManagedPolicy($jobKey);
        }
        $stmt = $this->db->prepare("UPDATE ucmp_configuration_sync_items SET status='released',soft_lock=0,updated=? WHERE job_key=? AND target_site_id=?");
        $stmt->execute([time(),$jobKey,$this->localSiteId()]);
        return ['ok'=>true,'released'=>$stmt->rowCount(),'message'=>'Configuration sync ownership released; destination content was preserved'];
    }

    public function configurationSyncLegacyOwnership(int $siteId, array $request): array
    {
        return $this->isSiteLocal($siteId) ? $this->configurationSyncLegacyOwnershipLocal($request)
            : (array)$this->remoteQuery($siteId, 'configurationSyncLegacyOwnershipLocal', [$request]);
    }

    /** Old keys contain no controller identity; adoption requires a reviewed, explicit choice. */
    public function configurationSyncLegacyOwnershipLocal(array $request): array
    {
        return $this->withConfigurationSyncLock(function () use ($request): array {
            $legacy = (string)($request['legacy_key'] ?? '');
            $owner = (string)($request['job_key'] ?? '');
            $source = (string)($request['source_site_key'] ?? '');
            if (!preg_match('/^cfg:[1-9][0-9]*:([1-9][0-9]*)$/D', $legacy, $old)
                || !preg_match('/^cfgc:[A-Za-z0-9_.-]{1,64}:([1-9][0-9]*)$/D', $owner, $new) || $old[1] !== $new[1]) {
                throw new \InvalidArgumentException('Invalid legacy ownership migration');
            }
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
            $read = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? ORDER BY id');
            $read->execute([$legacy,$this->localSiteId()]);
            $rows = $read->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (!$rows) { return ['ok'=>true,'items'=>[],'message'=>'No legacy ownership remains on this destination']; }
            foreach ($rows as $row) {
                if ($row['source_site_key'] !== $source) { throw new \RuntimeException('Legacy source identity differs from this job; ownership was preserved'); }
                if ($this->configurationSyncItemRow($owner,(string)$row['object_type'],(string)$row['object_key'])) {
                    throw new \RuntimeException('Current and legacy ownership overlap; reconcile those records on the destination first');
                }
            }
            $hash = $this->configurationSyncDigest(['legacy'=>$legacy,'owner'=>$owner,'source'=>$source,'rows'=>$rows]);
            $items = array_map(fn(array $row): array => array_intersect_key($this->configurationSyncItemOutput($row),
                array_flip(['object_type','object_key','destination_id','status','source_site_name'])), $rows);
            if (empty($request['apply'])) {
                return ['ok'=>true,'review_hash'=>$hash,'items'=>$items,'message'=>'Legacy keys cannot identify the original controller. Confirm these objects belong to this job before adopting them.'];
            }
            if (!hash_equals($hash,(string)($request['review_hash'] ?? ''))) { throw new \RuntimeException('Legacy ownership changed after review; preview it again'); }
            return $this->configurationSyncTransaction(function () use ($rows,$owner,$legacy,$items,$source,$hash): array {
                $locked = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? ORDER BY id'
                    . ((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'));
                $locked->execute([$legacy,$this->localSiteId()]);
                $fresh = $locked->fetchAll(PDO::FETCH_ASSOC) ?: [];
                if (!hash_equals($hash,$this->configurationSyncDigest(['legacy'=>$legacy,'owner'=>$owner,'source'=>$source,'rows'=>$fresh]))) {
                    throw new \RuntimeException('Legacy ownership changed during adoption; preview it again');
                }
                foreach ($rows as $row) {
                    $move = $this->db->prepare('UPDATE ucmp_configuration_sync_items SET job_key=? WHERE id=? AND job_key=? AND updated=?');
                    $move->execute([$owner,(int)$row['id'],$legacy,(int)$row['updated']]);
                    if ($move->rowCount() !== 1) { throw new \RuntimeException('Legacy ownership changed during adoption'); }
                }
                return ['ok'=>true,'items'=>$items,'message'=>'Legacy ownership adopted; object content, pauses and revisions were preserved'];
            });
        });
    }

    private function applyConfigurationSyncWebDirectory(array $source, array $context): array
    {
        $policy = \FreePBX\modules\Ucmprovisioner\WebPhonebook::normalizePolicy((array)($source['policy'] ?? []));
        $revision = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        if (!hash_equals($revision, (string)($source['revision'] ?? ''))) {
            throw new \InvalidArgumentException('Source web-directory policy revision does not match its content');
        }
        $web = $this->webDirectory();
        $result = $web->applyManagedPolicy($policy, $context + ['source_revision'=>$revision], !empty($context['force']));
        $status = (string)($result['status'] ?? 'active');
        $item = $this->upsertConfigurationSyncItem(
            $context, 'web_directory', 'web-directory-policy', 0, $revision, (string)$result['policy_revision'],
            $status, ['policy'=>$policy, 'hard_lock'=>!empty($context['hard_lock'])],
            (array)($result['missing'] ?? []), (string)($result['message'] ?? '')
        );
        return ['item'=>$item, 'changed'=>!empty($result['changed']),
            'warning'=>$status === 'active' ? '' : (string)($result['message'] ?? 'Web-directory policy was not replaced: ' . $status)];
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncGroup(array $source, array $context): array
    {
        $objectKey = trim((string)($source['_object_key'] ?? ''));
        $sourceRevision = (string)($source['_source_revision'] ?? '');
        if (!preg_match('/^group:[a-f0-9]{64}$/D', $objectKey) || $sourceRevision === '') {
            throw new \InvalidArgumentException('Source group identity is invalid');
        }
        $tracked = $this->configurationSyncItemRow((string)$context['job_key'], 'group', $objectKey);
        if ($tracked && (string)$tracked['status'] === 'paused') {
            return ['changed'=>false,'warning'=>$this->configurationSyncPauseMessage($tracked),'item'=>$this->configurationSyncItemOutput($tracked)];
        }
        if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) {
            return ['changed'=>false,'needs_confirmation'=>true,'warning'=>'Group conflict remains pending explicit confirmation','item'=>$this->configurationSyncItemOutput($tracked)];
        }
        $destination = null;
        if ($tracked && (int)($tracked['destination_id'] ?? 0) > 0) { $destination = $this->getGroupLocal((int)$tracked['destination_id']); }
        $destinationGroups = $this->configurationSyncDestinationGroups();
        $sourcePath = trim((string)($source['group_path'] ?? ''), '/');
        if (!$destination && $sourcePath !== '') {
            $destination = $destinationGroups['rows'][$this->configurationSyncGroupPathKey($sourcePath)] ?? null;
            if ($destination && !$tracked && empty($context['force'])) {
                return $this->configurationSyncConflictResult($context,'group',$objectKey,$sourceRevision,$destination,$source,[],'Destination already has a local group with the same stable name/path');
            }
        }
        if ($destination) {
            $owner = $this->configurationSyncOwnerConflict('group',(int)$destination['id'],(string)$context['job_key']);
            if ($owner) {
                return $this->configurationSyncConflictResult($context,'group',$objectKey,$sourceRevision,$destination,$source,[],'Group is owned by configuration sync ' . (string)$owner['source_site_name'],'owner_conflict');
            }
            $policyRevision = $this->configurationSyncGroupPolicyRevision($destination);
            $destinationRevision = str_starts_with((string)($tracked['destination_revision'] ?? ''), 'p:')
                ? $policyRevision
                : (string)($destinationGroups['revisions'][$this->configurationSyncGroupPathKey($this->configurationSyncGroupPath((int)$destination['id'], $destinationGroups['all'] ?? []))] ?? $this->configurationSyncGroupRevision($destination));
            if ($tracked && (string)$tracked['status'] === 'active'
                && hash_equals((string)($tracked['source_revision'] ?? ''),$sourceRevision)
                && hash_equals((string)($tracked['destination_revision'] ?? ''),$destinationRevision)) {
                return ['changed'=>false,'item'=>$this->upsertConfigurationSyncItem($context,'group',$objectKey,(int)$destination['id'],$sourceRevision,$policyRevision,'active',$source,[])];
            }
            if ($tracked && (string)($tracked['destination_revision'] ?? '') !== ''
                && !hash_equals((string)$tracked['destination_revision'],$destinationRevision)
                && empty($context['force'])) {
                return $this->configurationSyncConflictResult($context,'group',$objectKey,$sourceRevision,$destination,$source,[],'Group changed on this destination after its last sync');
            }
        }
        $missing = [];
        $parentKey = trim((string)($source['parent_group_key'] ?? ''));
        $parentId = 0;
        if ($parentKey !== '') {
            $parentTracked = $this->configurationSyncItemRow((string)$context['job_key'], 'group', $parentKey);
            $parent = ($parentTracked && (int)($parentTracked['destination_id'] ?? 0) > 0)
                ? $this->getGroupLocal((int)$parentTracked['destination_id'])
                : null;
            if (!$parent && $sourcePath !== '' && strrpos($sourcePath, '/') !== false) {
                $parentPath = substr($sourcePath, 0, (int)strrpos($sourcePath, '/'));
                $parent = $destinationGroups['rows'][$this->configurationSyncGroupPathKey($parentPath)] ?? null;
            }
            if ($parent) { $parentId = (int)$parent['id']; }
            else { $missing['parent_group_key'] = 'parent group ' . $parentKey; }
        }
        if (!$parentId && $destination) { $parentId = (int)($destination['parent_group_id'] ?? 0); }
        $layers = [];
        foreach ((array)($source['template_map'] ?? []) as $layer) {
            if (!is_array($layer)) { continue; }
            $templateKey = trim((string)($layer['template_key'] ?? ''));
            $templateId = 0;
            $mapped = $templateKey !== '' ? $this->configurationSyncItemRow((string)$context['job_key'],'template',$templateKey) : null;
            if ($mapped) { $templateId = (int)($mapped['destination_id'] ?? 0); }
            if ($templateId < 1 && trim((string)($layer['template_name'] ?? '')) !== '') {
                $find = $this->db->prepare('SELECT id FROM ucmp_templates WHERE site_id=? AND name=? ORDER BY id LIMIT 1');
                $find->execute([$this->localSiteId(),trim((string)$layer['template_name'])]);
                $templateId = (int)$find->fetchColumn();
            }
            if ($templateId < 1 || !$this->getTemplateLocal($templateId)) {
                $missing['template:' . ($templateKey ?: (string)($layer['template_name'] ?? 'unknown'))] = 'destination template';
                continue;
            }
            $layers[] = ['template_id'=>$templateId,'position'=>(int)($layer['position'] ?? ((count($layers) + 1) * 10))];
        }
        // Shared policy is portable; extension assignments and their exceptions
        // are local. Do not submit membership or consult the source's extensions.
        $payload = [
            'id'=>(int)($destination['id'] ?? 0),
            'site_id'=>$this->localSiteId(),
            'name'=>trim((string)($source['name'] ?? 'Group')),
            'parent_group_id'=>$parentId ?: null,
            'template_id'=>$layers ? (int)$layers[0]['template_id'] : null,
            'template_map'=>$layers,
            'member_exceptions'=>$destination['member_exceptions'] ?? [],
            'member_blf'=>!empty($source['member_blf']) ? 1 : 0,
            'extra_targets'=>(array)($source['extra_targets'] ?? []),
        ];
        if ($destination) { $payload['revision'] = max(1,(int)($destination['revision'] ?? 1)); }
        $templateDependencyMissing = false;
        foreach (array_keys($missing) as $missingKey) {
            if (strpos($missingKey, 'template:') === 0) { $templateDependencyMissing = true; break; }
        }
        if ($templateDependencyMissing && $destination) {
            // A destination must never receive a partially replaced stack. Keep
            // its complete existing stack until all source dependencies exist.
            $payload['template_id'] = $destination['template_id'] ?? null;
            $payload['template_map'] = $destination['template_map'] ?? [];
        } elseif ($templateDependencyMissing) {
            // New destination group: no source layer is safe to apply partially.
            $payload['template_id'] = null;
            $payload['template_map'] = [];
        }
        if ($this->configurationSyncReconciling && $tracked && $destination
            && $missing && $missing == (json_decode((string)$tracked['missing_json'],true) ?: [])) {
            return ['changed'=>false,'item'=>$this->configurationSyncItemOutput($tracked)];
        }
        $savedId = $this->saveGroupLocal($payload);
        $saved = $this->getGroupLocal($savedId) ?: $payload;
        $afterRevision = $this->configurationSyncGroupPolicyRevision($saved);
        $item = $this->upsertConfigurationSyncItem($context,'group',$objectKey,$savedId,$sourceRevision,$afterRevision,$missing ? 'missing_dependency' : 'active',$source,$missing);
        return [
            'changed'=>true,
            'warning'=>$missing ? 'Group ' . (string)($source['name'] ?? '') . ' used destination fallbacks: ' . implode(', ',array_keys($missing)) : '',
            'item'=>$item,
        ];
    }

    /** @return array{rows:array<string,array<string,mixed>>,revisions:array<string,string>,all:array<int,array<string,mixed>>} */
    private function configurationSyncDestinationGroups(): array
    {
        $rows = [];
        $revisions = [];
        $all = [];
        foreach ($this->getGroupsLocal($this->localSiteId()) as $group) {
            $id = (int)($group['id'] ?? 0);
            if ($id > 0) { $all[$id] = $this->getGroupLocal($id) ?: $group; }
        }
        $snapshot = $this->configurationSyncGroupSnapshot(array_values($all), [], $this->localSiteId());
        foreach ($snapshot as $intended) {
            $key = $this->configurationSyncGroupPathKey((string)($intended['group_path'] ?? ''));
            $match = null;
            foreach ($all as $group) {
                if ($this->configurationSyncGroupPathKey($this->configurationSyncGroupPath((int)($group['id'] ?? 0),$all)) === $key) { $match = $group; break; }
            }
            if ($match) { $rows[$key] = $match; $revisions[$key] = (string)($intended['_source_revision'] ?? $this->configurationSyncDigest($intended)); }
        }
        return ['rows'=>$rows,'revisions'=>$revisions,'all'=>$all];
    }

    private function configurationSyncGroupPath(int $id, array $groups, array $seen = []): string
    {
        if ($id < 1 || !isset($groups[$id]) || isset($seen[$id])) { return ''; }
        $seen[$id] = true;
        $group = $groups[$id];
        $name = trim((string)($group['name'] ?? '')) ?: 'Unnamed group';
        $parent = (int)($group['parent_group_id'] ?? 0);
        $parentPath = $parent > 0 ? $this->configurationSyncGroupPath($parent,$groups,$seen) : '';
        return $parentPath !== '' ? $parentPath . '/' . $name : $name;
    }

    private function configurationSyncGroupPathKey(string $path): string
    {
        $path = preg_replace('#/+#', '/', trim($path, '/'));
        $path = is_string($path) ? $path : trim($path, '/');
        return strtolower($path);
    }

    private function configurationSyncGroupPolicyRevision(array $group): string
    {
        $policy = [
            'name'=>(string)($group['name'] ?? ''),
            'parent_group_id'=>(int)($group['parent_group_id'] ?? 0),
            'template_id'=>(int)($group['template_id'] ?? 0),
            'member_blf'=>!empty($group['member_blf']),
        ];
        foreach (['template_map','extra_targets'] as $key) {
            $value = $group[$key] ?? [];
            $policy[$key] = is_string($value) ? (json_decode($value,true) ?: []) : $value;
        }
        // Keep the versioned receipt within the existing 64-character wire limit.
        return 'p:' . substr($this->configurationSyncDigest($policy), 0, 62);
    }

    /** Legacy receipt revision, retained only to verify upgrades without masking local edits. */
    private function configurationSyncGroupRevision(array $group): string
    {
        $rows = [];
        foreach ($this->getGroupsLocal($this->localSiteId()) as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) { $rows[$id] = $this->getGroupLocal($id) ?: $row; }
        }
        $id = (int)($group['id'] ?? 0);
        $snapshot = $this->configurationSyncGroupSnapshot(array_values($rows), [], $this->localSiteId());
        foreach ($snapshot as $intended) {
            if ((int)($intended['_source_group_id'] ?? 0) === $id) { return (string)($intended['_source_revision'] ?? ''); }
        }
        return $this->configurationSyncDigest(['path'=>$this->configurationSyncGroupPath($id,$rows),'revision'=>(int)($group['revision'] ?? 1)]);
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncSettingsCategory(string $scope, array $source, array $context): array
    {
        $allowed = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::SETTINGS_FIELDS;
        $assetResult = $scope === 'settings_assets'
            ? $this->applyConfigurationSyncSiteDefaults($source,$context,'settings_assets')
            : ['changed'=>false,'items'=>[],'needs_confirmation'=>false,'warning'=>''];
        $items = []; $warnings = []; $needsConfirmation = false; $changed = false; $updates = []; $savedItems = [];
        $currentSettings = $this->getSiteSettingsLocal($this->localSiteId());
        foreach ($allowed[$scope] ?? [] as $key) {
            if (!array_key_exists($key,$source) || ($source[$key] !== null && !is_scalar($source[$key]))) { continue; }
            $value = $source[$key] === null ? null : (string)$source[$key];
            $objectKey = 'setting:' . $key;
            $intended = ['scope'=>$scope,'setting'=>$key,'value'=>$value];
            $sourceRevision = $this->configurationSyncDigest(['value'=>$value]);
            $present = array_key_exists($key,$currentSettings);
            $currentValue = $present ? (string)$currentSettings[$key] : '';
            $currentRevision = $this->configurationSyncDigest(['present'=>$present,'value'=>$currentValue]);
            $tracked = $this->configurationSyncItemRow((string)$context['job_key'],$scope,$objectKey);
            if ($tracked && (string)$tracked['status'] === 'paused') { $items[]=$this->configurationSyncItemOutput($tracked); $warnings[]=$this->configurationSyncPauseMessage($tracked); continue; }
            if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) { $items[]=$this->configurationSyncItemOutput($tracked); $needsConfirmation=true; continue; }
            $owner = $this->configurationSyncOwnerConflictByKey($scope,$objectKey,(string)$context['job_key']);
            if ($owner) { $result=$this->configurationSyncConflictResult($context,$scope,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Setting is already owned by ' . (string)$owner['source_site_name'],'owner_conflict'); $items[]=$result['item']; $warnings[]=$result['warning']; continue; }
            if ($tracked && (string)($tracked['destination_revision'] ?? '') !== '' && !hash_equals((string)$tracked['destination_revision'],$currentRevision) && empty($context['force'])) {
                $result=$this->configurationSyncConflictResult($context,$scope,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Setting changed on this destination after its last sync'); $items[]=$result['item']; $warnings[]=$result['warning']; $needsConfirmation=true; continue;
            }
            if (in_array($key,['dial_template_file','softkey_file','featurepolicy_file'],true) && $value !== null && $value !== '') {
                $inventory = $this->configurationSyncInventory('list', ['path'=>'']);
                $files = array_filter((array)($inventory['entries'] ?? []),static fn(array $file): bool => empty($file['dir']) && (int)($file['size'] ?? 0) > 0);
                if (!in_array($value,array_column($files,'name'),true)) {
                    $items[]=$this->upsertConfigurationSyncItem($context,$scope,$objectKey,0,$sourceRevision,$currentRevision,'missing_dependency',$intended,['tftp:' . $value=>'TFTP companion file']);
                    $warnings[]="Destination kept its own $key because companion '$value' is missing; it will be retried after the file arrives";
                    continue;
                }
            }
            if ($key === 'default_phonebook_key' && $value !== null && $value !== '') {
                $catalog = $this->getPublishedDirectoryPhonebooksLocal();
                $available = array_fill_keys(array_map(static fn(array $book): string => (string)($book['book_key'] ?? ''), (array)($catalog['phonebooks'] ?? [])), true);
                if (!isset($available[$value])) {
                    $items[] = $this->upsertConfigurationSyncItem($context,$scope,$objectKey,0,$sourceRevision,$currentRevision,'missing_dependency',$intended,['phonebook:' . $value=>'generated published phonebook snapshot']);
                    $warnings[] = "Destination kept its own phonebook default because '$value' is not in its generated cache";
                    continue;
                }
            }
            $sameValue = (!$present && ($value === null || $value === '')) || ($present && $value !== null && hash_equals($currentValue,$value));
            // A saved default can outlive a failed cache publication. Retry that
            // publication even when the database value already matches.
            if ($sameValue && $key !== 'default_phonebook_key') { $items[]=$this->upsertConfigurationSyncItem($context,$scope,$objectKey,0,$sourceRevision,$currentRevision,'active',$intended,[]); continue; }
            if (!$sameValue && !$tracked && $present && empty($context['force'])) { $result=$this->configurationSyncConflictResult($context,$scope,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Destination already has a different local setting'); $items[]=$result['item']; $warnings[]=$result['warning']; $needsConfirmation=true; continue; }
            $updates[$key] = $value;
            $currentSettings[$key]=$value;
            $afterRevision=$this->configurationSyncDigest(['present'=>$value !== null,'value'=>$value ?? '']);
            $savedItems[] = [$objectKey,$sourceRevision,$afterRevision,$intended];
            $changed=$changed || !$sameValue || $key === 'default_phonebook_key';
        }
        if ($updates) {
            try { $this->saveSettingsLocal(['site_id'=>$this->localSiteId(),'settings'=>$updates]); }
            catch (\FreePBX\modules\Ucmprovisioner\RpcOperationException $error) {
                if (empty($error->receipt['settings_saved'])) { throw $error; }
                $warnings[] = $error->getMessage();
            }
            $savedSettings = $this->getSiteSettingsLocal($this->localSiteId());
            foreach ($savedItems as [$objectKey,$sourceRevision,$afterRevision,$intended]) {
                $key = $intended['setting'];
                $afterRevision = $this->configurationSyncDigest(['present'=>array_key_exists($key,$savedSettings),'value'=>(string)($savedSettings[$key] ?? '')]);
                $items[] = $this->upsertConfigurationSyncItem($context,$scope,$objectKey,0,$sourceRevision,$afterRevision,'active',$intended,[]);
            }
        }
        if (!empty($assetResult['warning'])) { $warnings[] = (string)$assetResult['warning']; }
        return [
            'changed'=>$changed || !empty($assetResult['changed']),
            'items'=>array_merge((array)($assetResult['items'] ?? []),$items),
            'needs_confirmation'=>$needsConfirmation || !empty($assetResult['needs_confirmation']),
            'warning'=>$warnings ? implode('; ',array_values(array_unique($warnings))) : '',
        ];
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncTemplate(array $source, array $context): array
    {
        $objectKey = trim((string)($source['_object_key'] ?? ''));
        $sourceRevision = (string)($source['_source_revision'] ?? $source['revision'] ?? '1');
        $name = trim((string)($source['name'] ?? ''));
        if (!preg_match('/^template:[1-9][0-9]*$/D', $objectKey) || $name === '') {
            throw new \InvalidArgumentException('Source template identity is invalid');
        }
        $tracked = $this->configurationSyncItemRow($context['job_key'], 'template', $objectKey);
        if ($tracked && (string)$tracked['status'] === 'paused') {
            return ['changed'=>false,'warning'=>$this->configurationSyncPauseMessage($tracked),'item'=>$this->configurationSyncItemOutput($tracked)];
        }
        if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) {
            return ['changed'=>false,'needs_confirmation'=>true,'warning'=>'Template conflict remains pending explicit confirmation','item'=>$this->configurationSyncItemOutput($tracked)];
        }
        $destination = null;
        if ($tracked && (int)($tracked['destination_id'] ?? 0) > 0) { $destination = $this->getTemplateLocal((int)$tracked['destination_id']); }
        if (!$destination) {
            $stmt = $this->db->prepare('SELECT * FROM ucmp_templates WHERE site_id=? AND name=? ORDER BY id LIMIT 1');
            $stmt->execute([$this->localSiteId(),$name]);
            $destination = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($destination && !$tracked && empty($context['force'])) {
                return $this->configurationSyncConflictResult($context,'template',$objectKey,$sourceRevision,$destination,$source,[],"Destination already has a local template named '$name'");
            }
        }
        if ($destination) {
            $owner = $this->configurationSyncOwnerConflict('template', (int)$destination['id'], (string)$context['job_key']);
            if ($owner) {
                return $this->configurationSyncConflictResult($context,'template',$objectKey,$sourceRevision,$destination,$source,[],"Template '$name' is owned by configuration sync " . (string)$owner['source_site_name'], 'owner_conflict');
            }
            $destinationRevision = (string)max(1,(int)($destination['revision'] ?? 1));
            if ($tracked && (string)$tracked['status'] === 'active'
                && hash_equals((string)($tracked['source_revision'] ?? ''), $sourceRevision)
                && hash_equals((string)($tracked['destination_revision'] ?? ''), $destinationRevision)) {
                $item = $this->upsertConfigurationSyncItem($context,'template',$objectKey,(int)$destination['id'],$sourceRevision,$destinationRevision,'active',$source,[]);
                return ['changed'=>false,'item'=>$item];
            }
            if ($tracked
                && (string)($tracked['destination_revision'] ?? '') !== ''
                && !hash_equals((string)$tracked['destination_revision'], (string)max(1,(int)($destination['revision'] ?? 1)))
                && empty($context['force'])) {
                return $this->configurationSyncConflictResult($context,'template',$objectKey,$sourceRevision,$destination,$source,[],"Template '$name' changed on this destination after its last sync");
            }
        }

        $payload = array_intersect_key($source, array_fill_keys([
            'name','target_vendor','target_family','target_series','target_role','target_model',
            'phonebook_key','phonebook_filename','buttons','capacity_overrides','softkeys',
            'feature_policy','setting_overrides','member_blf',
        ], true));
        $payload['site_id'] = $this->localSiteId();
        $payload['id'] = (int)($destination['id'] ?? 0);
        if ($destination) { $payload['revision'] = max(1,(int)($destination['revision'] ?? 1)); }
        // A source PBX URL/identity is never portable. The destination serves its own selected phonebook.
        $payload['phonebook_source_site'] = '';
        $payload['phonebook_source_url'] = '';
        [$payload,$missing] = $this->resolveConfigurationSyncTemplateDependencies($payload);
        if ($this->configurationSyncReconciling && $tracked && $destination
            && $missing && $missing == (json_decode((string)$tracked['missing_json'],true) ?: [])) {
            return ['changed'=>false,'item'=>$this->configurationSyncItemOutput($tracked)];
        }
        $savedId = $this->saveTemplateLocal($payload);
        $saved = $this->getTemplateLocal($savedId) ?: [];
        $status = $missing ? 'missing_dependency' : 'active';
        $item = $this->upsertConfigurationSyncItem(
            $context, 'template', $objectKey, $savedId, $sourceRevision,
            (string)max(1,(int)($saved['revision'] ?? 1)), $status, $source, $missing
        );
        return [
            'changed'=>true,
            'warning'=>$missing ? "Template '$name' uses destination defaults for " . implode(', ', array_keys($missing)) . ' until those dependencies exist' : '',
            'item'=>$item,
        ];
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncPhonebook(array $source, array $context): array
    {
        $bookKey = trim((string)($source['book_key'] ?? ''));
        $objectKey = trim((string)($source['_object_key'] ?? ('phonebook:' . $bookKey)));
        $sourceRevision = (string)($source['_source_revision'] ?? $source['updated'] ?? '1');
        if (!preg_match('/^[a-z0-9_-]{1,64}$/D', $bookKey) || $objectKey !== 'phonebook:' . $bookKey) {
            throw new \InvalidArgumentException('Source phonebook identity is invalid');
        }
        $tracked = $this->configurationSyncItemRow($context['job_key'], 'phonebook', $objectKey);
        if ($tracked && (string)$tracked['status'] === 'paused') {
            return ['changed'=>false,'warning'=>$this->configurationSyncPauseMessage($tracked),'item'=>$this->configurationSyncItemOutput($tracked)];
        }
        if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) {
            return ['changed'=>false,'needs_confirmation'=>true,'warning'=>'Phonebook conflict remains pending explicit confirmation','item'=>$this->configurationSyncItemOutput($tracked)];
        }
        $stmt = $this->db->prepare('SELECT * FROM ucmp_phonebooks WHERE book_key=? LIMIT 1');
        $stmt->execute([$bookKey]);
        $destination = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($destination && !$tracked && empty($context['force'])) {
            return $this->configurationSyncConflictResult($context,'phonebook',$objectKey,$sourceRevision,$destination,$source,[],"Destination already has a local phonebook named '" . (string)$destination['name'] . "'");
        }
        if ($destination) {
            $owner = $this->configurationSyncOwnerConflict('phonebook', (int)$destination['id'], (string)$context['job_key']);
            if ($owner) {
                return $this->configurationSyncConflictResult($context,'phonebook',$objectKey,$sourceRevision,$destination,$source,[],"Phonebook '$bookKey' is owned by configuration sync " . (string)$owner['source_site_name'], 'owner_conflict');
            }
            $destinationRevision = (string)max(1,(int)($destination['updated'] ?? 1));
            if ($tracked && (string)$tracked['status'] === 'active'
                && hash_equals((string)($tracked['source_revision'] ?? ''), $sourceRevision)
                && hash_equals((string)($tracked['destination_revision'] ?? ''), $destinationRevision)) {
                $item = $this->upsertConfigurationSyncItem($context,'phonebook',$objectKey,(int)$destination['id'],$sourceRevision,$destinationRevision,'active',$source,[]);
                $this->deferConfigurationSyncPhonebookPublication();
                return ['changed'=>false,'item'=>$item];
            }
            if ($tracked && (string)($tracked['destination_revision'] ?? '') !== ''
                && !hash_equals((string)$tracked['destination_revision'], (string)max(1,(int)($destination['updated'] ?? 1)))
                && empty($context['force'])) {
                return $this->configurationSyncConflictResult($context,'phonebook',$objectKey,$sourceRevision,$destination,$source,[],"Phonebook '$bookKey' changed on this destination after its last sync");
            }
        }
        $payload = array_intersect_key($source, array_fill_keys([
            'book_key','name','filename','title','include_mode','rules','exclusions','manual_entries',
            'group_by','hierarchy','name_format','sort_by','include_mobile','cisco_page_size',
        ], true));
        $payload['id'] = (int)($destination['id'] ?? 0);
        // Publication targets and which book is default belong to the destination PBX.
        $payload['publish_sites'] = $destination ? (string)($destination['publish_sites'] ?? '["*"]') : ['*'];
        $payload['is_default'] = $destination ? !empty($destination['is_default']) : false;
        // The reviewed sync job owns its stable source key. Interactive designer
        // review is separate; ordinary scheduled source updates remain automatic.
        $directory = $this->engine('DirectoryService');
        if (!$directory) { throw new \RuntimeException('Directory service is unavailable'); }
        $savedId = $directory->importPhonebook(['phonebook'=>$payload], false);
        $this->deferConfigurationSyncPhonebookPublication();
        $saved = $this->getDirectoryPhonebook($savedId) ?: [];
        $item = $this->upsertConfigurationSyncItem(
            $context,'phonebook',$objectKey,$savedId,$sourceRevision,
            (string)max(1,(int)($saved['updated'] ?? time())),'active',$source,[]
        );
        return ['changed'=>true,'item'=>$item];
    }

    private function deferConfigurationSyncPhonebookPublication(): void
    {
        $this->configurationSyncAfterCommit['phonebooks'] = function (): void {
            $directory = $this->engine('DirectoryService');
            if (!$directory) { throw new \RuntimeException('Phonebook saved; directory publication service unavailable'); }
            $publication = $directory->republishSavedPhonebooks();
            if (!empty($publication['warnings'])) { throw new \RuntimeException(implode('; ', $publication['warnings'])); }
        };
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncSiteDefaults(array $source, array $context, string $objectType = 'site_defaults'): array
    {
        $items = [];
        $warnings = [];
        $needsConfirmation = false;
        $changed = false;
        $currentSettings = $this->getSiteSettingsLocal($this->localSiteId());
        foreach (['firmware_defaults','cisco_timezone_defaults','cisco_wallpaper_defaults','hosted_image_defaults'] as $settingKey) {
            $sourceMap = $source[$settingKey] ?? [];
            if (is_string($sourceMap)) { $sourceMap = json_decode($sourceMap, true) ?: []; }
            if (!is_array($sourceMap)) { continue; }
            $currentMap = $currentSettings[$settingKey] ?? [];
            if (is_string($currentMap)) { $currentMap = json_decode($currentMap, true) ?: []; }
            if (!is_array($currentMap)) { $currentMap = []; }
            foreach ($sourceMap as $profile=>$value) {
                if (!is_string($profile) || $profile === '' || strlen($profile) > 160 || !is_scalar($value)) { continue; }
                $value = (string)$value;
                $objectKey = $settingKey . ':' . $profile;
                $intended = ['setting'=>$settingKey,'profile'=>$profile,'value'=>$value];
                $sourceRevision = $this->configurationSyncDigest(['value'=>$value]);
                $present = array_key_exists($profile, $currentMap);
                $currentValue = $present ? (string)$currentMap[$profile] : '';
                $currentRevision = $this->configurationSyncDigest(['present'=>$present,'value'=>$currentValue]);
                $tracked = $this->configurationSyncItemRow((string)$context['job_key'],$objectType,$objectKey);
                if ($tracked && (string)$tracked['status'] === 'paused') {
                    $items[] = $this->configurationSyncItemOutput($tracked);
                    $warnings[] = $this->configurationSyncPauseMessage($tracked);
                    continue;
                }
                if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) {
                    $items[] = $this->configurationSyncItemOutput($tracked);
                    $warnings[] = 'Site-default assignment conflict remains pending explicit confirmation: ' . $profile;
                    $needsConfirmation = true;
                    continue;
                }
                $owner = $this->configurationSyncOwnerConflictByKey($objectType,$objectKey,(string)$context['job_key']);
                if ($owner) {
                    $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Site-default assignment is already owned by ' . (string)$owner['source_site_name'],'owner_conflict');
                    $items[] = $result['item'];
                    $warnings[] = $result['warning'];
                    continue;
                }
                if ($tracked && (string)($tracked['destination_revision'] ?? '') !== ''
                    && !hash_equals((string)$tracked['destination_revision'],$currentRevision)
                    && !hash_equals($sourceRevision,$this->configurationSyncDigest(['value'=>$currentValue]))
                    && empty($context['force'])) {
                    $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Destination assignment changed after its last sync: ' . $profile);
                    $items[] = $result['item'];
                    $warnings[] = $result['warning'];
                    $needsConfirmation = true;
                    continue;
                }
                [$resolved,$missing] = $this->resolveConfigurationSyncAssetSettings([$settingKey=>[$profile=>$value]], true);
                $resolvedMap = (array)($resolved[$settingKey] ?? []);
                if (!array_key_exists($profile,$resolvedMap)) {
                    $item = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,'missing_dependency',$intended,$missing ?: [$objectKey=>'managed dependency']);
                    $items[] = $item;
                    $warnings[] = 'Destination kept its own default for ' . $profile . ' because the pushed dependency is unavailable';
                    continue;
                }
                $resolvedValue = (string)$resolvedMap[$profile];
                if ($present && hash_equals($resolvedValue,$currentValue)) {
                    $items[] = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,'active',$intended,[]);
                    continue;
                }
                if (!$tracked && $present && empty($context['force'])) {
                    $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$intended,[],'Destination already has a different local assignment for ' . $profile);
                    $items[] = $result['item'];
                    $warnings[] = $result['warning'];
                    $needsConfirmation = true;
                    continue;
                }
                $currentMap[$profile] = $resolvedValue;
                $this->saveSettingsLocal(['site_id'=>$this->localSiteId(),'settings'=>[
                    $settingKey=>json_encode($currentMap ?: new \stdClass(), JSON_UNESCAPED_SLASHES),
                ]]);
                $currentSettings[$settingKey] = json_encode($currentMap, JSON_UNESCAPED_SLASHES);
                $afterRevision = $this->configurationSyncDigest(['present'=>true,'value'=>$resolvedValue]);
                $items[] = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$afterRevision,'active',$intended,[]);
                $changed = true;
            }
        }
        return [
            'changed'=>$changed,'items'=>$items,'needs_confirmation'=>$needsConfirmation,
            'warning'=>$warnings ? implode('; ',array_values(array_unique($warnings))) : '',
        ];
    }

    /** @return array<string,mixed> */
    private function applyConfigurationSyncDefaultTemplates(array $sourceRules, array $context, string $objectType = 'default_templates'): array
    {
        $items = [];
        $warnings = [];
        $needsConfirmation = false;
        $changed = false;
        $current = $this->getDefaultTemplateRulesLocal($this->localSiteId());
        $byTarget = [];
        foreach ($current as $rule) { $byTarget[(string)($rule['target_key'] ?? '')] = $rule; }
        foreach ($sourceRules as $sourceRule) {
            if (!is_array($sourceRule)) { continue; }
            $targetKey = strtolower(trim((string)($sourceRule['target_key'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/D',$targetKey)) { $warnings[] = 'A pushed default-template rule has an invalid target identity'; continue; }
            $objectKey = 'rule:' . $targetKey;
            $sourceRevision = $this->configurationSyncDigest($sourceRule);
            $currentRule = $byTarget[$targetKey] ?? null;
            $currentRevision = $this->configurationSyncDigest($currentRule ? $this->configurationSyncRuleDigestData([$currentRule]) : []);
            $tracked = $this->configurationSyncItemRow((string)$context['job_key'],$objectType,$objectKey);
            if ($tracked && (string)$tracked['status'] === 'paused') {
                $items[] = $this->configurationSyncItemOutput($tracked);
                $warnings[] = $this->configurationSyncPauseMessage($tracked);
                continue;
            }
            if ($tracked && (string)$tracked['status'] === 'conflict' && empty($context['force'])) {
                $items[] = $this->configurationSyncItemOutput($tracked);
                $warnings[] = 'Default-template target conflict remains pending explicit confirmation';
                $needsConfirmation = true;
                continue;
            }
            $owner = $this->configurationSyncOwnerConflictByKey($objectType,$objectKey,(string)$context['job_key']);
            if ($owner) {
                $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$sourceRule,[],'Default-template target is already owned by ' . (string)$owner['source_site_name'],'owner_conflict');
                $items[] = $result['item'];
                $warnings[] = $result['warning'];
                continue;
            }
            if ($tracked && (string)($tracked['destination_revision'] ?? '') !== ''
                && !hash_equals((string)$tracked['destination_revision'],$currentRevision)
                && empty($context['force'])) {
                $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$sourceRule,[],'Default-template target changed after its last sync');
                $items[] = $result['item'];
                $warnings[] = $result['warning'];
                $needsConfirmation = true;
                continue;
            }
            $templateKey = trim((string)($sourceRule['template_key'] ?? ''));
            $templateId = 0;
            if ($templateKey !== '') {
                $mapped = $this->configurationSyncItemRow($context['job_key'], 'template', $templateKey);
                $templateId = (int)($mapped['destination_id'] ?? 0);
            }
            if ($templateId < 1 && trim((string)($sourceRule['template_name'] ?? '')) !== '') {
                $find = $this->db->prepare('SELECT id FROM ucmp_templates WHERE site_id=? AND name=? ORDER BY id LIMIT 1');
                $find->execute([$this->localSiteId(),trim((string)$sourceRule['template_name'])]);
                $templateId = (int)$find->fetchColumn();
            }
            if ($templateId < 1 || !$this->getTemplateLocal($templateId)) {
                $missing = [$templateKey ?: $objectKey=>'destination template'];
                $items[] = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,'missing_dependency',$sourceRule,$missing);
                $warnings[] = 'Destination kept its own default-template target because the pushed template is unavailable';
                continue;
            }
            $desired = [
                'template_id'=>$templateId,
                'target_key'=>$targetKey,
                'target_vendor'=>(string)($sourceRule['target_vendor'] ?? ''),
                'target_family'=>(string)($sourceRule['target_family'] ?? ''),
                'target_series'=>(string)($sourceRule['target_series'] ?? ''),
                'target_model'=>(string)($sourceRule['target_model'] ?? ''),
                'target_role'=>(string)($sourceRule['target_role'] ?? ''),
                'enabled'=>!empty($sourceRule['enabled']) ? 1 : 0,
            ];
            $desiredRevision = $this->configurationSyncDigest($this->configurationSyncRuleDigestData([$desired]));
            if ($currentRule && hash_equals($desiredRevision,$currentRevision)) {
                $items[] = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$currentRevision,'active',$sourceRule,[]);
                continue;
            }
            if (!$tracked && $currentRule && empty($context['force'])) {
                $result = $this->configurationSyncConflictResult($context,$objectType,$objectKey,$sourceRevision,['id'=>0,'revision'=>$currentRevision],$sourceRule,[],'Destination already has a different local rule for this default-template target');
                $items[] = $result['item'];
                $warnings[] = $result['warning'];
                $needsConfirmation = true;
                continue;
            }
            $merged = [];
            foreach ($current as $rule) {
                if ((string)($rule['target_key'] ?? '') !== $targetKey) { $merged[] = $rule; }
            }
            $merged[] = $desired;
            $this->saveSettingsLocal(['site_id'=>$this->localSiteId(),'default_template_rules'=>$merged]);
            $current = $this->getDefaultTemplateRulesLocal($this->localSiteId());
            $byTarget = [];
            foreach ($current as $rule) { $byTarget[(string)($rule['target_key'] ?? '')] = $rule; }
            $savedRule = $byTarget[$targetKey] ?? $desired;
            $afterRevision = $this->configurationSyncDigest($this->configurationSyncRuleDigestData([$savedRule]));
            $items[] = $this->upsertConfigurationSyncItem($context,$objectType,$objectKey,0,$sourceRevision,$afterRevision,'active',$sourceRule,[]);
            $changed = true;
        }
        return [
            'changed'=>$changed,'items'=>$items,'needs_confirmation'=>$needsConfirmation,
            'warning'=>$warnings ? implode('; ',array_values(array_unique($warnings))) : '',
        ];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function resolveConfigurationSyncTemplateDependencies(array $payload): array
    {
        $settings = $payload['setting_overrides'] ?? [];
        if (is_string($settings)) { $settings = json_decode($settings, true) ?: []; }
        if (!is_array($settings)) { $settings = []; }
        [$settings,$missing] = $this->resolveConfigurationSyncAssetSettings($settings, false);
        $payload['setting_overrides'] = $settings;
        $bookKey = trim((string)($payload['phonebook_key'] ?? ''));
        if (in_array($bookKey, [
            \FreePBX\modules\Ucmprovisioner\TemplateEngine::PHONEBOOK_SITE_DEFAULT,
            \FreePBX\modules\Ucmprovisioner\TemplateEngine::PHONEBOOK_NONE,
        ], true)) {
            $payload['phonebook_filename'] = '';
            $payload['phonebook_source_site'] = '';
            $payload['phonebook_source_url'] = '';
        } elseif ($bookKey !== '') {
            $stmt = $this->db->prepare('SELECT filename FROM ucmp_phonebooks WHERE book_key=? LIMIT 1');
            $stmt->execute([$bookKey]);
            $filename = $stmt->fetchColumn();
            if ($filename === false) {
                $missing['phonebook:' . $bookKey] = 'managed phonebook';
                $payload['phonebook_key'] = '';
                $payload['phonebook_filename'] = '';
            } else {
                $payload['phonebook_filename'] = (string)$filename;
            }
        }
        if (!empty($settings['cisco_networkLocale']) || !empty($settings['cisco_userLocale'])) {
            $userLocale = trim((string)($settings['cisco_userLocale'] ?? '')) ?: 'English_United_States';
            $networkLocale = trim((string)($settings['cisco_networkLocale'] ?? ''));
            $inventory = $this->configurationSyncInventory('cisco_locale_inventory', [
                'user_locale'=>$userLocale,'network_locale'=>$networkLocale,
            ]);
            $completeProfiles = array_filter((array)($inventory['profiles'] ?? []),
                static fn(array $profile): bool => ($profile['status'] ?? '') === 'complete');
            if (!$completeProfiles) {
                $missing['cisco_locale_bundle:' . $userLocale] = 'Cisco locale companions for ' . $userLocale;
                unset($settings['cisco_networkLocale'],$settings['cisco_userLocale']);
            }
            $payload['setting_overrides'] = $settings;
        }
        return [$payload,$missing];
    }

    /** Inventory can write its own cache. Never run it inside an object transaction. */
    private function configurationSyncInventory(string $op, array $options = []): array
    {
        $key = $op . ':' . json_encode($options, JSON_THROW_ON_ERROR);
        if (!array_key_exists($key,$this->configurationSyncLiveAssets)) {
            if ($this->db->inTransaction()) { throw new \LogicException('Configuration dependency inventory must be prepared before its transaction'); }
            try {
                $result = $op === 'hosted_media'
                    ? $this->mediaAssetOpLocal(['op'=>'inventory','site_id'=>$this->localSiteId()])
                    : $this->tftpOp(['op'=>$op,'site_id'=>$this->localSiteId()] + $options);
                $this->configurationSyncLiveAssets[$key] = (array)$result;
            } catch (\Throwable $error) {
                $this->configurationSyncLiveAssets[$key] = ['ok'=>false,'message'=>$error->getMessage()];
            }
        }
        $result = $this->configurationSyncLiveAssets[$key];
        if (empty($result['ok'])) {
            throw new \RuntimeException('Dependency inventory unavailable (' . $op . '): ' . (string)($result['message'] ?? 'lookup failed'));
        }
        return $result;
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function resolveConfigurationSyncAssetSettings(array $settings, bool $siteDefaults): array
    {
        $siteId = $this->localSiteId();
        $missing = [];
        $has = static function (array $keys) use ($settings): bool {
            foreach ($keys as $key) {
                $value = $settings[$key] ?? null;
                if (is_string($value) && str_starts_with(trim($value), '{')) { $value = json_decode($value,true); }
                if (!empty($value)) { return true; }
            }
            return false;
        };
        $firmware = $assetNames = $hostedExact = $hostedByName = $timezoneKeys = $timezoneAssetKeys = [];
        if ($has(['firmware_defaults','firmware_by_profile','inactive_firmware_by_profile'])) {
            $inventory = $this->configurationSyncInventory('firmware_rescan');
            foreach ((array)($inventory['bundles'] ?? []) as $row) {
                if (($row['status'] ?? '') === 'complete') { $firmware[(string)$row['profile']][(string)$row['load_name']] = true; }
            }
        }
        if ($has(['cisco_wallpaper_defaults','cisco_wallpaper_by_profile','cisco_default_wallpaper'])) {
            $catalogs = $this->configurationSyncInventory('wallpaper_set_list');
            foreach ((array)($catalogs['sets'] ?? []) as $catalog) {
                if (empty($catalog['complete'])) { continue; }
                foreach ((array)($catalog['variants'] ?? []) as $variant) {
                    if (empty($variant['enabled'])) { continue; }
                    foreach (['filename','full','thumbnail'] as $field) {
                        $name = strtolower(basename((string)($variant[$field] ?? '')));
                        if ($name !== '') { $assetNames[$name] = true; }
                    }
                }
            }
        }
        if ($has(['hosted_image_defaults','hosted_image_by_profile','hosted_image_url','hosted_ringtone_url','legacy_logo_url'])) {
            $inventory = $this->configurationSyncInventory('hosted_media');
            foreach ((array)($inventory['items'] ?? []) as $item) {
                if (isset($item['validation_status']) && $item['validation_status'] !== 'verified') { continue; }
                $url = trim((string)($item['url'] ?? ''));
                $name = strtolower(basename((string)($item['filename'] ?? $item['name'] ?? parse_url($url, PHP_URL_PATH))));
                if ($url !== '') { $hostedExact[$url] = $url; }
                if ($name !== '' && $url !== '') { $hostedByName[$name] = !array_key_exists($name,$hostedByName) || $hostedByName[$name] === $url ? $url : ''; }
            }
        }
        if ($has(['cisco_timezone_defaults','cisco_timezone_by_profile','cisco_timezone_bundle_key'])) {
            $versions = $this->configurationSyncInventory('timezone_versions');
            foreach ((array)($versions['bundles'] ?? []) as $bundle) {
                $key = strtolower(trim((string)($bundle['bundle_key'] ?? '')));
                if ($key !== '' && ($bundle['status'] ?? '') !== 'quarantined') { $timezoneKeys[$key] = true; }
            }
            foreach ((array)($versions['assets'] ?? []) as $asset) {
                $profile = (string)($asset['profile'] ?? '');
                $key = strtolower(trim((string)($asset['asset_key'] ?? '')));
                if ($profile !== '' && $key !== '' && !empty($asset['selectable'])) { $timezoneAssetKeys[$profile][$key] = true; }
            }
        }

        $firmwareKeys = $siteDefaults ? ['firmware_defaults'] : ['firmware_by_profile','inactive_firmware_by_profile'];
        foreach ($firmwareKeys as $mapKey) {
            $map = $settings[$mapKey] ?? [];
            if (is_string($map)) { $map = json_decode($map, true) ?: []; }
            if (!is_array($map)) { $map = []; }
            foreach ($map as $profile=>$load) {
                if (trim((string)$load) === '') { unset($map[$profile]); continue; }
                if (empty($firmware[(string)$profile][(string)$load])) {
                    $missing[$mapKey . ':' . $profile] = 'firmware ' . (string)$load;
                    unset($map[$profile]);
                }
            }
            if ($map) { $settings[$mapKey] = $map; } else { unset($settings[$mapKey]); }
        }
        $wallpaperKeys = $siteDefaults ? ['cisco_wallpaper_defaults'] : ['cisco_wallpaper_by_profile'];
        foreach ($wallpaperKeys as $mapKey) {
            $map = $settings[$mapKey] ?? [];
            if (is_string($map)) { $map = json_decode($map, true) ?: []; }
            if (!is_array($map)) { $map = []; }
            foreach ($map as $profile=>$filename) {
                $filename = basename((string)$filename);
                if ($filename === '' || empty($assetNames[strtolower($filename)])) {
                    $missing[$mapKey . ':' . $profile] = 'wallpaper ' . ($filename ?: '(empty)');
                    unset($map[$profile]);
                }
            }
            if ($map) { $settings[$mapKey] = $map; } else { unset($settings[$mapKey]); }
        }
        $hostedKeys = $siteDefaults ? ['hosted_image_defaults'] : ['hosted_image_by_profile'];
        foreach ($hostedKeys as $mapKey) {
            $map = $settings[$mapKey] ?? [];
            if (is_string($map)) { $map = json_decode($map, true) ?: []; }
            if (!is_array($map)) { $map = []; }
            foreach ($map as $profile=>$url) {
                $url = trim((string)$url);
                $name = strtolower(basename((string)parse_url($url, PHP_URL_PATH)));
                $resolved = $hostedExact[$url] ?? ($hostedByName[$name] ?? '');
                if ($resolved === '') {
                    $missing[$mapKey . ':' . $profile] = 'hosted wallpaper ' . ($name ?: $url);
                    unset($map[$profile]);
                } else { $map[$profile] = $resolved; }
            }
            if ($map) { $settings[$mapKey] = $map; } else { unset($settings[$mapKey]); }
        }
        $timezoneMapKeys = $siteDefaults ? ['cisco_timezone_defaults'] : ['cisco_timezone_by_profile'];
        foreach ($timezoneMapKeys as $mapKey) {
            $map = $settings[$mapKey] ?? [];
            if (is_string($map)) { $map = json_decode($map, true) ?: []; }
            if (!is_array($map)) { $map = []; }
            foreach ($map as $profile=>$assetKey) {
                $assetKey = strtolower(trim((string)$assetKey));
                if (empty($timezoneAssetKeys[(string)$profile][$assetKey])) {
                    $missing[$mapKey . ':' . $profile] = 'Cisco timezone asset ' . ($assetKey ?: '(empty)');
                    unset($map[$profile]);
                }
            }
            if ($map) { $settings[$mapKey] = $map; } else { unset($settings[$mapKey]); }
        }
        if (!$siteDefaults && !empty($settings['cisco_default_wallpaper'])) {
            $filename = basename((string)$settings['cisco_default_wallpaper']);
            if ($filename === '' || empty($assetNames[strtolower($filename)])) {
                $missing['cisco_default_wallpaper'] = 'wallpaper ' . ($filename ?: '(empty)');
                unset($settings['cisco_default_wallpaper']);
            } else { $settings['cisco_default_wallpaper'] = $filename; }
        }
        if (!$siteDefaults) {
            foreach (['hosted_image_url','hosted_ringtone_url','legacy_logo_url'] as $urlKey) {
                $url = trim((string)($settings[$urlKey] ?? ''));
                if ($url === '') { continue; }
                $name = strtolower(basename((string)parse_url($url, PHP_URL_PATH)));
                $resolved = $hostedExact[$url] ?? ($hostedByName[$name] ?? '');
                if ($resolved === '') {
                    $missing[$urlKey] = 'managed media ' . ($name ?: $url);
                    unset($settings[$urlKey]);
                } else { $settings[$urlKey] = $resolved; }
            }
        }
        if (!$siteDefaults && !empty($settings['cisco_timezone_bundle_key'])) {
            $key = strtolower(trim((string)$settings['cisco_timezone_bundle_key']));
            if (empty($timezoneKeys[$key])) {
                $missing['cisco_timezone_bundle_key'] = 'Cisco timezone version ' . $key;
                unset($settings['cisco_timezone_bundle_key']);
            }
        }
        return [$settings,$missing];
    }

    /** @return array<string,mixed>|null */
    private function configurationSyncItemRow(string $jobKey, string $objectType, string $objectKey): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE job_key=? AND target_site_id=? AND object_type=? AND object_key=? LIMIT 1');
        $stmt->execute([$jobKey,$this->localSiteId(),$objectType,$objectKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function configurationSyncOwnerConflict(string $objectType, int $destinationId, string $jobKey): ?array
    {
        if ($destinationId < 1) { return null; }
        $stmt = $this->db->prepare("SELECT * FROM ucmp_configuration_sync_items WHERE target_site_id=? AND object_type=? AND destination_id=? AND job_key<>? AND status IN ('active','missing_dependency') ORDER BY updated DESC LIMIT 1");
        $stmt->execute([$this->localSiteId(),$objectType,$destinationId,$jobKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function configurationSyncOwnerConflictByKey(string $objectType, string $objectKey, string $jobKey): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM ucmp_configuration_sync_items WHERE target_site_id=? AND object_type=? AND object_key=? AND job_key<>? AND status IN ('active','missing_dependency') ORDER BY updated DESC LIMIT 1");
        $stmt->execute([$this->localSiteId(),$objectType,$objectKey,$jobKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return array<string,mixed> */
    private function upsertConfigurationSyncItem(
        array $context,
        string $objectType,
        string $objectKey,
        int $destinationId,
        string $sourceRevision,
        string $destinationRevision,
        string $status,
        mixed $intended,
        array $missing,
        string $pauseReason = ''
    ): array {
        $now = time();
        $tracked = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeTrackingItem([
            'job_key'=>(string)($context['job_key'] ?? ''),
            'source_site_key'=>(string)($context['source_site_key'] ?? ''),
            'source_site_name'=>(string)($context['source_site_name'] ?? ''),
            'object_type'=>$objectType, 'object_key'=>$objectKey,
            'source_revision'=>$sourceRevision, 'destination_revision'=>$destinationRevision,
            'status'=>$status,
            'paused_by'=>$status === 'paused' ? $this->configurationSyncActor() : '',
        ]);
        $objectType = $tracked['object_type'];
        $objectKey = $tracked['object_key'];
        $existing = $this->configurationSyncItemRow((string)$context['job_key'],$objectType,$objectKey);
        $values = [
            (int)($context['job_id'] ?? 0), $tracked['source_site_key'], $tracked['source_site_name'],
            $destinationId > 0 ? $destinationId : null, $tracked['source_revision'], $tracked['destination_revision'],
            $tracked['status'], !empty($context['soft_lock'])?1:0,
            json_encode($intended, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            json_encode($missing, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            $status === 'paused' ? $now : 0,
            $status === 'paused' ? $tracked['paused_by'] : null,
            $status === 'paused' ? $pauseReason : null,
            $now, $now,
        ];
        if ($existing) {
            $values[] = (int)$existing['id'];
            $this->db->prepare('UPDATE ucmp_configuration_sync_items SET job_id=?,source_site_key=?,source_site_name=?,destination_id=?,source_revision=?,destination_revision=?,status=?,soft_lock=?,intended_json=?,missing_json=?,paused_at=?,paused_by=?,pause_reason=?,last_sync=?,updated=? WHERE id=?')->execute($values);
            $id = (int)$existing['id'];
        } else {
            $this->db->prepare('INSERT INTO ucmp_configuration_sync_items (job_key,job_id,source_site_key,source_site_name,target_site_id,object_type,object_key,destination_id,source_revision,destination_revision,status,soft_lock,intended_json,missing_json,paused_at,paused_by,pause_reason,last_sync,updated) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
                $tracked['job_key'],$values[0],$values[1],$values[2],$this->localSiteId(),$objectType,$objectKey,
                $values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10],$values[11],$values[12],$values[13],$values[14],
            ]);
            $id = (int)$this->db->lastInsertId();
        }
        $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE id=?');
        $stmt->execute([$id]);
        return $this->configurationSyncItemOutput($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string,mixed> */
    private function configurationSyncConflictResult(
        array $context,
        string $objectType,
        string $objectKey,
        string $sourceRevision,
        array $destination,
        mixed $intended,
        array $missing,
        string $message,
        string $status = 'conflict'
    ): array {
        $destinationRevision = (string)($destination['revision'] ?? $destination['updated'] ?? '');
        $item = $this->upsertConfigurationSyncItem(
            $context,$objectType,$objectKey,(int)($destination['id'] ?? 0),$sourceRevision,
            $destinationRevision,$status,$intended,$missing
        );
        return ['changed'=>false,'needs_confirmation'=>$status === 'conflict','warning'=>$message,'item'=>$item];
    }

    /** @return array<string,mixed> */
    private function configurationSyncItemOutput(array $row): array
    {
        return [
            'id'=>(int)($row['id'] ?? 0), 'job_key'=>(string)($row['job_key'] ?? ''),
            'job_id'=>(int)($row['job_id'] ?? 0), 'source_site_key'=>(string)($row['source_site_key'] ?? ''),
            'source_site_name'=>(string)($row['source_site_name'] ?? ''), 'target_site_id'=>(int)($row['target_site_id'] ?? 0),
            'object_type'=>(string)($row['object_type'] ?? ''), 'object_key'=>(string)($row['object_key'] ?? ''),
            'destination_id'=>(int)($row['destination_id'] ?? 0), 'source_revision'=>(string)($row['source_revision'] ?? ''),
            'destination_revision'=>(string)($row['destination_revision'] ?? ''), 'status'=>(string)($row['status'] ?? 'active'),
            'soft_lock'=>!empty($row['soft_lock']), 'intended'=>json_decode((string)($row['intended_json'] ?? ''), true),
            'missing'=>json_decode((string)($row['missing_json'] ?? ''), true) ?: [],
            'paused_at'=>(int)($row['paused_at'] ?? 0), 'paused_by'=>(string)($row['paused_by'] ?? ''),
            'pause_reason'=>(string)($row['pause_reason'] ?? ''), 'last_sync'=>(int)($row['last_sync'] ?? 0),
        ];
    }

    private function configurationSyncPauseMessage(array $item): string
    {
        $detail = trim((string)($item['pause_reason'] ?? ''));
        $when = (int)($item['paused_at'] ?? 0);
        return 'Automatic sync for this object is paused after a destination edit'
            . ($when > 0 ? ' on ' . date('Y-m-d H:i:s', $when) : '')
            . ($detail !== '' ? ': ' . $detail : '');
    }

    private function configurationSyncDigest(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            foreach ($item as $key=>$child) { $item[$key] = $normalize($child); }
            return $item;
        };
        return hash('sha256', (string)json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @return array<int,array<string,mixed>> */
    private function configurationSyncRuleDigestData(array $rules): array
    {
        $out = [];
        foreach ($rules as $rule) {
            $out[] = array_intersect_key($rule, array_fill_keys([
                'template_id','target_key','target_vendor','target_family','target_series','target_model','target_role','enabled',
            ], true));
        }
        usort($out, static fn(array $a,array $b): int => strcmp((string)($a['target_key'] ?? ''),(string)($b['target_key'] ?? '')));
        return $out;
    }

    private function configurationSyncActor(): string
    {
        $actor = '';
        if (isset($_SESSION['AMP_user']) && is_object($_SESSION['AMP_user'])) {
            foreach (['username','getUsername'] as $member) {
                try {
                    $actor = $member === 'getUsername' && method_exists($_SESSION['AMP_user'],$member)
                        ? (string)$_SESSION['AMP_user']->{$member}()
                        : (string)($_SESSION['AMP_user']->{$member} ?? '');
                } catch (\Throwable) { $actor = ''; }
                if ($actor !== '') { break; }
            }
        }
        return substr(
            $actor ?: (string)($_SERVER['REMOTE_USER'] ?? 'local administrator'),
            0,
            \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ACTOR_MAX_LENGTH
        );
    }

    /** Mark only known consumers unless defaults changed, in which case every managed phone is affected. */
    private function markConfigurationSyncPhonesPending(array $templateIds, bool $all): void
    {
        $siteId = $this->localSiteId();
        $now = time();
        $templateIds = array_values(array_unique(array_filter(array_map('intval',$templateIds), static fn(int $id): bool => $id > 0)));
        if (!$all && $templateIds) {
            $marksAll = $this->db->prepare('SELECT COUNT(*) FROM ucmp_default_template_rules WHERE site_id=? AND template_id IN (' . implode(',',array_fill(0,count($templateIds),'?')) . ')');
            $marksAll->execute(array_merge([$siteId],$templateIds));
            $all = (int)$marksAll->fetchColumn() > 0;
        }
        if ($all) {
            $this->db->prepare('UPDATE ucmp_phones SET updated=' . LogicalExtensionService::phoneUpdateExpression($this->db) . ' WHERE site_id=? AND extension IS NOT NULL AND extension<>\'\'')->execute([$now,$siteId]);
            return;
        }
        foreach ($templateIds as $templateId) {
            $groups = $this->db->prepare('SELECT id FROM ucmp_groups WHERE site_id=? AND (template_id=? OR template_map LIKE ? OR member_exceptions LIKE ?)');
            $needle = '%"template_id":' . $templateId . '%';
            $groups->execute([$siteId,$templateId,$needle,$needle]);
            $groupIds = array_map('intval',$groups->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if (!$groupIds) { continue; }
            $this->db->prepare('UPDATE ucmp_phones SET updated=' . LogicalExtensionService::phoneUpdateExpression($this->db) . ' WHERE site_id=? AND group_id IN (' . implode(',',array_fill(0,count($groupIds),'?')) . ')')->execute(array_merge([$now,$siteId],$groupIds));
        }
    }

    private function markConfigurationSyncGroupPhonesPending(array $groupIds): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval',$groupIds), static fn(int $id): bool => $id > 0)));
        if (!$groupIds) { return; }
        $this->db->prepare('UPDATE ucmp_phones SET updated=' . LogicalExtensionService::phoneUpdateExpression($this->db) . ' WHERE site_id=? AND group_id IN (' . implode(',',array_fill(0,count($groupIds),'?')) . ')')->execute(array_merge([time(),$this->localSiteId()],$groupIds));
    }

    /** @return array<int,array<string,mixed>> */
    private function configurationSyncEditGuard(string $objectType, int $destinationId, string $objectKey, array $input): array
    {
        if ($this->configurationSyncApplying) { return []; }
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        $where = $destinationId > 0 ? 'destination_id=?' : 'object_key=?';
        $value = $destinationId > 0 ? $destinationId : $objectKey;
        $stmt = $this->db->prepare("SELECT * FROM ucmp_configuration_sync_items WHERE target_site_id=? AND object_type=? AND $where AND status IN ('active','missing_dependency') ORDER BY updated DESC");
        $stmt->execute([$this->localSiteId(),$objectType,$value]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$items) { return []; }
        $locked = array_filter($items, static fn(array $item): bool => !empty($item['soft_lock']));
        if ($locked && empty($input['sync_edit_confirmed'])) {
            $source = implode(', ', array_values(array_unique(array_map(static fn(array $item): string => (string)$item['source_site_name'], $locked))));
            throw new \RuntimeException(
                'This object is automatically synchronized from ' . $source
                . '. Confirm the synchronized-object edit to pause only this object before saving.'
            );
        }
        return $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private function pauseConfigurationSyncItems(array $items, array $input): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $items), static fn(int $id): bool => $id > 0)));
        if (!$ids) { return; }
        $reason = trim((string)($input['sync_edit_reason'] ?? '')) ?: 'Destination administrator edited this synchronized object';
        $stmt = $this->db->prepare("UPDATE ucmp_configuration_sync_items SET status='paused',paused_at=?,paused_by=?,pause_reason=?,updated=? WHERE id=?");
        $now = time();
        foreach ($ids as $id) { $stmt->execute([$now,$this->configurationSyncActor(),substr($reason,0,2000),$now,$id]); }
    }

    /** Local Media Manager preflight for one automatically synchronized catalog. */
    public function configurationSyncCatalogEditGuard(string $objectType, string $objectKey, array $input): array
    {
        if (!in_array($objectType, ['wallpaper_catalog','special_catalog'], true)) {
            return ['ok'=>false,'message'=>'Unsupported synchronized catalog type'];
        }
        try {
            $items = $this->configurationSyncEditGuard($objectType,0,$objectKey,$input);
            return [
                'ok'=>true,
                'items'=>array_map(fn(array $item): array => $this->configurationSyncItemOutput($item), $items),
            ];
        } catch (\RuntimeException $error) {
            return ['ok'=>false,'sync_edit_required'=>true,'message'=>$error->getMessage()];
        }
    }

    /** Pause only the catalog whose confirmed destination edit succeeded. */
    public function configurationSyncCatalogEditPause(array $items, array $input): void
    {
        $this->pauseConfigurationSyncItems($items, $input);
    }

    /** Read destination-side sync ownership/warnings for list and editor badges. */
    public function configurationSyncStates(int $siteId, string $objectType): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return (array)$this->remoteQuery($siteId, 'configurationSyncStatesLocal', [$objectType]);
        }
        return $this->configurationSyncStatesLocal($objectType);
    }

    /** @return array<int,array<string,mixed>> */
    public function configurationSyncStatesLocal(string $objectType): array
    {
        $objectType = \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::normalizeObjectType($objectType);
        \FreePBX\modules\Ucmprovisioner\ConfigurationSyncService::ensureSchema($this->db);
        $stmt = $this->db->prepare('SELECT * FROM ucmp_configuration_sync_items WHERE target_site_id=? AND object_type=? ORDER BY updated DESC,id DESC');
        $stmt->execute([$this->localSiteId(),$objectType]);
        return array_map(fn(array $row): array => $this->configurationSyncItemOutput($row), $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function syncPublishMesh(bool $apply = false, array $siteIds = [], ?string $expectedPlanHash = null): array
    {
        if ($apply && $this->asteriskConfigurationWriteInProgress()) {
            return [
                'ok' => false,
                'mode' => 'deferred',
                'busy' => true,
                'message' => 'BLF mesh apply was deferred because FreePBX/Asterisk configuration files are being generated or were changed in the last 20 seconds.',
            ];
        }
        $eng = $this->engine('PublishMesh');
        if (!$eng) { return ['ok' => false, 'message' => 'PublishMesh engine not yet available']; }
        $local = $this->getSite($this->localSiteId()) ?: [];
        $participants = [];

        // Remote participants commit first. The controller commits last, so the
        // common failure path leaves its local state unchanged while compensation runs.
        foreach ($this->getSites() as $site) {
            $siteId = (int)($site['id'] ?? 0);
            if (!empty($site['is_local']) || empty($site['enabled'])) {
                continue;
            }
            if ($siteIds && !in_array($siteId, $siteIds, true)) {
                continue;
            }
            $siteKey = (string)($site['site_key'] ?: $siteId);
            $participantId = 'remote:' . $siteKey;
            $peer = [
                'key' => (string)($local['site_key'] ?? 'controller'),
                'name' => (string)($local['name'] ?? 'controller'),
                'endpoint' => (string)($site['remote_trunk_endpoint'] ?? ''),
                'peer_resource' => (string)($site['trunk_endpoint'] ?? ''),
                'local_addr' => (string)($site['pbx_addr'] ?? ''),
                'local_port' => (int)($site['pbx_sip_port'] ?? 5160),
                'peer_addr' => (string)($local['pbx_addr'] ?? ''),
                'peer_port' => (int)($local['pbx_sip_port'] ?? 5160),
            ];
            if (empty($site['pbx_agent_ready']) || $peer['endpoint'] === '' || $peer['peer_resource'] === '') {
                $reason = empty($site['pbx_agent_ready'])
                    ? 'PBX agent is not enrolled'
                    : 'Both exact PJSIP endpoints must be selected';
                $participants[] = [
                    'id' => $participantId,
                    'preview' => static fn(): array => ['ok'=>false, 'message'=>$reason],
                    'prepare' => static fn(): array => ['ok'=>false, 'message'=>$reason],
                    'commit' => static fn(): array => ['ok'=>false, 'message'=>$reason],
                    'rollback' => static fn(): array => ['ok'=>true, 'message'=>'Nothing was prepared'],
                ];
                continue;
            }
            $participants[] = [
                'id' => $participantId,
                'preview' => fn(): array => (array)$this->remoteQuery($siteId, 'previewExplicitPublishPeer', [$peer]),
                'prepare' => fn(string $planHash, string $transactionId): array => (array)$this->remoteQuery(
                    $siteId,
                    'prepareExplicitPublishPeer',
                    [$peer, $planHash, $transactionId]
                ),
                'commit' => fn(string $transactionId): array => (array)$this->remoteQuery(
                    $siteId,
                    'commitPublishMeshTransaction',
                    [$transactionId]
                ),
                'rollback' => fn(string $transactionId): array => (array)$this->remoteQuery(
                    $siteId,
                    'rollbackPublishMeshTransaction',
                    [$transactionId]
                ),
            ];
        }

        $participants[] = [
            'id' => 'local:' . (string)($local['site_key'] ?? $this->localSiteId()),
            'preview' => fn(): array => $eng->preview($siteIds),
            'prepare' => fn(string $planHash, string $transactionId): array => $eng->prepare($siteIds, $planHash, $transactionId),
            'commit' => fn(string $transactionId): array => $eng->commit($transactionId),
            'rollback' => fn(string $transactionId): array => $eng->rollback($transactionId),
        ];
        return \FreePBX\modules\Ucmprovisioner\PublishMesh::coordinate(
            $participants,
            $apply,
            $expectedPlanHash
        );
    }

    /** One-minute cron tick for the site-owned phone rebuild/reboot schedule. */
    public function runScheduledPhoneRebuild(): array
    {
        $lock = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock('/var/lib/asterisk/ucmprovisioner/phone-rebuild-scheduler.lock');
        if ($lock === false) { return ['ok'=>true,'skipped'=>true,'message'=>'Another scheduled phone rebuild is already running']; }
        try {
        $siteId = $this->localSiteId();
        if ((string)$this->getSetting('phone_rebuild_schedule_enabled', $siteId, '0') !== '1') {
            return ['ok'=>true, 'skipped'=>true, 'message'=>'Scheduled phone rebuild is disabled'];
        }
        $interval = max(15, min(10080, (int)$this->getSetting('phone_rebuild_interval_minutes', $siteId, 1440)));
        $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
            $this->getSetting('phone_rebuild_schedule_json', $siteId, ''),
            $interval,
            15
        );
        if ($schedule['mode'] === 'manual') {
            return ['ok'=>true, 'skipped'=>true, 'message'=>'Phone rebuild schedule is manual only'];
        }
        $now = time();
        $nextRun = (int)$this->getSetting('phone_rebuild_next_run', $siteId, '0');
        if ($nextRun < 1) {
            $nextRun = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, $now);
            $this->setSetting('phone_rebuild_next_run', (string)$nextRun, $siteId);
        }
        if ($nextRun > $now) {
            return ['ok'=>true, 'skipped'=>true, 'next_run'=>$nextRun, 'message'=>'Scheduled phone rebuild is not due until ' . date('c', $nextRun)];
        }

            // Consume this due slot before any external action. A lost result or
            // failed history write must not cause the next cron tick to replay it.
            $nextRun = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, $now);
            $this->setSetting('phone_rebuild_next_run', (string)$nextRun, $siteId);
            $this->setSetting('phone_rebuild_last_attempt', (string)$now, $siteId);
            $result = []; $stage = 'preview';
            try {
            if ($this->asteriskConfigurationWriteInProgress()) {
                $result = ['ok'=>true, 'skipped'=>true, 'busy'=>true, 'message'=>'Scheduled phone rebuild was deferred while FreePBX/Asterisk configuration was changing'];
                $nextRun = $now + 60;
            } else {
                $preview = $this->rebuild('all', false, $siteId, false, false);
                if (empty($preview['ok']) || empty($preview['plan_hash'])) {
                    $result = ['ok'=>false, 'message'=>'Scheduled phone rebuild preview failed: ' . (string)($preview['message'] ?? 'unknown error'), 'preview'=>$preview];
                } else {
                    $stage = 'build';
                    $result = $this->rebuild('all', true, $siteId, false, false, (string)$preview['plan_hash']);
                    if (!empty($result['ok']) && (string)$this->getSetting('phone_rebuild_reboot', $siteId, '0') === '1') {
                        $ids = [];
                        foreach ((array)($result['phones'] ?? []) as $phone) {
                            if (in_array((string)($phone['family'] ?? ''), ['cisco_xml','cisco_cnf','yealink'], true)) {
                                $ids[] = (int)($phone['id'] ?? 0);
                            }
                        }
                        $stage = 'phone_commands';
                        $result['scheduled_reboots'] = [];
                        foreach (array_chunk(array_values(array_filter($ids)), 200) as $chunk) {
                            $result['scheduled_reboots'][] = $this->sendPhoneCommands($chunk, 'reboot', $siteId);
                        }
                        $failed = array_sum(array_map(static fn(array $batch): int => (int)($batch['failed'] ?? 0), $result['scheduled_reboots']));
                        if ($failed || array_filter($result['scheduled_reboots'], static fn(array $batch): bool => empty($batch['ok']))) {
                            $result['ok'] = false; $result['status'] = 'partial';
                        }
                        $result['message'] = rtrim((string)($result['message'] ?? ''), '.')
                            . sprintf('; scheduled warm reboot requested for %d supported phone(s)%s.', count($ids), $failed ? " ($failed failed)" : '');
                    }
                }
            }
            } catch (\Throwable $error) {
                $result = array_replace($result, ['ok'=>false,'status'=>'unknown','failed_stage'=>$stage,
                    'message'=>'Scheduled phone operation was not confirmed; retained known stages and consumed this schedule slot: ' . $error->getMessage()]);
                if ($error instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException) { $result['operation_receipt'] = $error->receipt; }
            }
            $status = !empty($result['busy']) ? 'deferred' : (!empty($result['ok']) ? 'success' : 'error');
            try {
                if (!empty($result['busy'])) { $this->setSetting('phone_rebuild_next_run', (string)$nextRun, $siteId); }
                $this->setSetting('phone_rebuild_last_status', (string)($result['message'] ?? $status), $siteId);
                if (!empty($result['ok']) && empty($result['busy']) && empty($result['skipped'])) {
                    $this->setSetting('phone_rebuild_last_success', (string)time(), $siteId);
                }
                $this->logOperation($siteId, 'phone-rebuild-schedule', $status, (string)($result['message'] ?? 'Scheduled phone rebuild finished'));
            } catch (\Throwable $error) {
                $result['reporting_error'] = 'Run outcome retained; saving its history failed: ' . $error->getMessage();
            }
            return ['next_run'=>$nextRun] + $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * One-minute cron tick for the independently scheduled federation job.
     * Rebuilds never call this method.
     */
    public function runScheduledPublishMesh(): array
    {
        $lock = \FreePBX\modules\Ucmprovisioner\ServiceFilePermissions::lock('/var/lib/asterisk/ucmprovisioner/mesh-scheduler.lock');
        if ($lock === false) { return ['ok'=>true,'skipped'=>true,'message'=>'Another BLF mesh sync is already running']; }
        try {
        $siteId = $this->localSiteId();
        if ((string)$this->getSetting('blf_mesh_schedule_enabled', $siteId, '0') !== '1') {
            return ['ok'=>true, 'skipped'=>true, 'message'=>'Scheduled BLF mesh sync is disabled'];
        }
        $interval = max(15, min(10080, (int)$this->getSetting('blf_mesh_interval_minutes', $siteId, 60)));
        $schedule = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::normalize(
            $this->getSetting('blf_mesh_schedule_json', $siteId, ''),
            $interval,
            15
        );
        if ($schedule['mode'] === 'manual') {
            return ['ok'=>true, 'skipped'=>true, 'message'=>'Scheduled BLF mesh sync is manual-only'];
        }
        $lastAttempt = (int)$this->getSetting('blf_mesh_last_attempt', $siteId, 0);
        $nextRun = (int)$this->getSetting('blf_mesh_next_run', $siteId, 0);
        if ($nextRun < 1 && $lastAttempt > 0) {
            $nextRun = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, $lastAttempt);
        }
        if ($nextRun > time()) {
            return ['ok'=>true, 'skipped'=>true, 'next_run'=>$nextRun, 'message'=>'Scheduled BLF mesh sync is not due until ' . date('c', $nextRun)];
        }

            $nextRun = \FreePBX\modules\Ucmprovisioner\RecurringSchedule::nextRun($schedule, time());
            $this->setSetting('blf_mesh_next_run', (string)$nextRun, $siteId);
            $this->setSetting('blf_mesh_last_attempt', (string)time(), $siteId);
            $result = []; $stage = 'preview';
            try {
            if ($this->asteriskConfigurationWriteInProgress()) {
                $result = ['ok'=>true, 'skipped'=>true, 'busy'=>true, 'message'=>'Scheduled BLF mesh sync was deferred while FreePBX/Asterisk configuration was changing'];
            } else {
                $preview = $this->syncPublishMesh(false);
                if (empty($preview['ok'])) {
                    $result = $preview;
                } else {
                    $changed = false;
                    foreach ((array)($preview['participants'] ?? []) as $participant) {
                        if (!empty($participant['changed'])) { $changed = true; break; }
                    }
                    $stage = $changed ? 'apply' : 'preview';
                    $result = $changed
                        ? $this->syncPublishMesh(true, [], (string)$preview['plan_hash'])
                        : ['ok'=>true, 'skipped'=>true, 'changed'=>false, 'message'=>'BLF mesh already matches the saved site topology'];
                }
            }
            } catch (\Throwable $error) {
                $result = ['ok'=>false,'status'=>'unknown','failed_stage'=>$stage,
                    'message'=>'Scheduled mesh operation was not confirmed; this schedule slot remains consumed: ' . $error->getMessage()];
                if ($error instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException) { $result['operation_receipt'] = $error->receipt; }
            }
            try {
                if (!empty($result['busy'])) {
                    $nextRun = time() + 300;
                    $this->setSetting('blf_mesh_next_run', (string)$nextRun, $siteId);
                }
                $this->setSetting('blf_mesh_last_status', mb_substr((string)($result['message'] ?? ''), 0, 1000, 'UTF-8'), $siteId);
                if (!empty($result['ok']) && empty($result['busy'])) {
                    $this->setSetting('blf_mesh_last_success', (string)time(), $siteId);
                }
                $this->logOperation($siteId, 'mesh-schedule', !empty($result['ok']) ? 'success' : 'error', (string)($result['message'] ?? 'Scheduled BLF mesh sync finished'));
            } catch (\Throwable $error) {
                $result['reporting_error'] = 'Run outcome retained; saving its history failed: ' . $error->getMessage();
            }
            return ['next_run'=>$nextRun] + $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Refuse mesh prepare/apply while retrieve_conf/reload is active or files are still settling. */
    private function asteriskConfigurationWriteInProgress(): bool
    {
        if (is_dir('/proc')) {
            $seen = 0;
            foreach ((array)glob('/proc/[0-9]*/cmdline') as $path) {
                if (++$seen > 10000) { break; }
                $pid = (int)basename(dirname($path));
                if ($pid === getmypid()) { continue; }
                $command = @file_get_contents($path);
                if ($command === false || $command === '') { continue; }
                $command = str_replace("\0", ' ', $command);
                if (preg_match('/(?:retrieve_conf|fwconsole\s+(?:reload|r|restart)\b|freepbx_engine\s+(?:start|restart)\b)/i', $command)) {
                    return true;
                }
            }
        }
        if (!is_dir('/etc/asterisk')) { return false; }
        $cutoff = time() - 20;
        foreach (new \DirectoryIterator('/etc/asterisk') as $file) {
            if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.conf') && $file->getMTime() >= $cutoff) {
                return true;
            }
        }
        return false;
    }

    /** Bounded participant RPC: preview one peer against this PBX only. */
    public function previewExplicitPublishPeer(array $peer): array
    {
        $eng = $this->engine('PublishMesh');
        if (!$eng) { return ['ok' => false, 'message' => 'PublishMesh engine not yet available']; }
        return $eng->previewExplicitPeer($this->boundedMeshPeer($peer));
    }

    /** Bounded participant RPC: stage one approved peer without changing live Asterisk state. */
    public function prepareExplicitPublishPeer(array $peer, string $planHash, string $transactionId): array
    {
        if ($this->asteriskConfigurationWriteInProgress()) {
            return ['ok'=>false, 'busy'=>true, 'message'=>'Mesh prepare was deferred while this PBX was generating Asterisk configuration'];
        }
        $eng = $this->engine('PublishMesh');
        if (!$eng) { return ['ok' => false, 'message' => 'PublishMesh engine not yet available']; }
        return $eng->prepareExplicitPeer($this->boundedMeshPeer($peer), $planHash, $transactionId);
    }

    /** Commit only a transaction already prepared on this PBX; no site selector crosses RPC. */
    public function commitPublishMeshTransaction(string $transactionId): array
    {
        $eng = $this->engine('PublishMesh');
        return $eng ? $eng->commit($transactionId) : ['ok'=>false, 'message'=>'PublishMesh engine not yet available'];
    }

    /** Roll back only a transaction already prepared on this PBX; no site selector crosses RPC. */
    public function rollbackPublishMeshTransaction(string $transactionId): array
    {
        $eng = $this->engine('PublishMesh');
        return $eng ? $eng->rollback($transactionId) : ['ok'=>false, 'message'=>'PublishMesh engine not yet available'];
    }

    /**
     * Replace caller-controlled local identity and require the selected endpoint
     * and peer address to match one enabled local FreePBX PJSIP trunk.
     */
    private function boundedMeshPeer(array $peer): array
    {
        $local = $this->getSite($this->localSiteId()) ?: [];
        $endpoint = trim(substr((string)($peer['endpoint'] ?? ''), 0, 128));
        $peerAddress = trim(substr((string)($peer['peer_addr'] ?? ''), 0, 128), " []\t\r\n");
        $trunks = $this->listPjsipTrunks(0);
        $trunk = null;
        foreach ((array)($trunks['trunks'] ?? []) as $candidate) {
            if (hash_equals((string)($candidate['endpoint'] ?? ''), $endpoint)) {
                $trunk = $candidate;
                break;
            }
        }
        if (!$trunk) {
            throw new \InvalidArgumentException('Mesh endpoint must be an enabled PJSIP trunk on this PBX');
        }
        $serverHosts = [];
        $stmt = $this->db->prepare(
            "SELECT data FROM pjsip WHERE id=? AND keyword IN ('sip_server','outbound_proxy')"
        );
        $stmt->execute([(int)$trunk['trunkid']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
            $value = trim((string)$value);
            if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $serverHosts[] = $value;
            } elseif (preg_match('/^(?:sips?:)?(?:[^@\s]+@)?(?:\[([0-9a-f:.]+)\]|([A-Za-z0-9._-]+))(?::([0-9]{1,5}))?(?:;[^\r\n]*)?$/iD', $value, $match)
                && (empty($match[3]) || ((int)$match[3] >= 1 && (int)$match[3] <= 65535))) {
                $serverHosts[] = $match[1] !== '' ? $match[1] : $match[2];
            }
        }
        $normalizeHost = static fn(string $host): string => filter_var($host, FILTER_VALIDATE_IP) ? (string)inet_ntop(inet_pton($host)) : strtolower($host);
        if (!$serverHosts || !in_array($normalizeHost($peerAddress), array_map($normalizeHost, $serverHosts), true)) {
            throw new \InvalidArgumentException('Mesh peer address must match the selected local trunk server');
        }
        return [
            'key' => 'controller',
            'name' => 'controller',
            'endpoint' => $endpoint,
            'peer_resource' => trim(substr((string)($peer['peer_resource'] ?? ''), 0, 128)),
            'local_addr' => trim((string)($local['pbx_addr'] ?? '')),
            'local_port' => (int)($local['pbx_sip_port'] ?? 5160),
            'peer_addr' => $peerAddress,
            'peer_port' => (int)($peer['peer_port'] ?? 5160),
        ];
    }
    public function testSite(int $id): array
    {
        try {
            $conn = $this->siteConnection($id);
            return $conn->test();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Return enabled PJSIP trunks as exact endpoint identifiers for the Sites dropdown. */
    public function listPjsipTrunks(int $siteId = 0): array
    {
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'listPjsipTrunks', [0]);
        }
        $out = [];
        try {
            $rows = $this->db->query("SELECT trunkid, name, tech, channelid, disabled FROM trunks ORDER BY name")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (strtolower((string)($row['disabled'] ?? 'off')) === 'on') {
                    continue;
                }
                $tech = strtolower((string)($row['tech'] ?? ''));
                if (strpos($tech, 'pjsip') === false) {
                    continue;
                }
                $endpoint = trim((string)($row['channelid'] ?? ''));
                $endpoint = preg_replace('/^pjsip\//i', '', $endpoint);
                if ($endpoint === '') {
                    continue;
                }
                $out[$endpoint] = [
                    'endpoint' => $endpoint,
                    'label' => (string)($row['name'] ?: $endpoint),
                    'trunkid' => (int)$row['trunkid'],
                ];
            }
        } catch (\Throwable $e) {
            return ['ok' => false, 'trunks' => [], 'message' => 'Unable to read the FreePBX trunks table: ' . $e->getMessage()];
        }
        return [
            'ok' => true,
            'trunks' => array_values($out),
            'message' => $out ? '' : 'No enabled PJSIP trunks exist on this PBX.',
        ];

    }

    /** Bound displayed diagnostics without retaining request secrets or key material. */
    private function safeOperationText(string $text, array $secrets = []): string
    {
        foreach ($_REQUEST as $name => $value) {
            if (is_string($value) && $value !== '' && preg_match('/pass|secret|token|csrf|api[_-]?key/i', (string)$name)) {
                $secrets[] = $value;
            }
        }
        $secrets = array_values(array_filter($secrets, static fn($value): bool => is_string($value) && $value !== ''));
        if ($secrets) { $text = str_replace($secrets, '[redacted]', $text); }
        $text = (string)preg_replace('/-----BEGIN [^-]*PRIVATE KEY-----.*?(?:-----END [^-]*PRIVATE KEY-----|$)/s', '[private key redacted]', $text);
        $text = (string)preg_replace('/^UCM_AUTHORIZED_KEYS_B64=.*$/m', '[public key data omitted]', $text);
        $text = (string)preg_replace("/\\b(?:admin_pass|password|passwd|secret|token|api[_-]?key|authorization|cookie)[\"']?\\s*[:=]\\s*[^\\r\\n]+/i", '[credential field redacted]', $text);
        $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (strlen($text) <= 8192) { return $text; }
        // Keep the stage at the start and the failing command's output at the end.
        $head = function_exists('mb_strcut') ? mb_strcut($text, 0, 2048, 'UTF-8') : substr($text, 0, 2048);
        $tail = function_exists('mb_strcut') ? mb_strcut($text, strlen($text) - 6000, 6000, 'UTF-8') : substr($text, -6000);
        return $head . "\n[earlier output truncated]\n" . $tail;
    }

    /** Keep the full bounded cause in detail; the summary remains one readable line. */
    private function operationFailureResult(\Throwable $error, string $command, array $secrets = []): array
    {
        $messages = [];
        for ($cause = $error, $depth = 0; $cause !== null && $depth < 5; $cause = $cause->getPrevious(), $depth++) {
            $message = trim($cause->getMessage());
            if ($message !== '' && !in_array($message, $messages, true)) { $messages[] = $message; }
        }
        $summary = trim(explode("\n", str_replace("\r", '', $error->getMessage()))[0]);
        $recovery = $error instanceof \FreePBX\modules\Ucmprovisioner\RpcOperationException
            ? \FreePBX\modules\Ucmprovisioner\RpcResponse::publicFailureResult($error, fn(string $value): string => $this->safeOperationText($value, $secrets))
            : [];
        if (!empty($recovery['failed_stage'])) { $messages[] = 'Stage: ' . $recovery['failed_stage']; }
        if (!empty($recovery['recovery_required'])) { $messages[] = 'Completion needs reconciliation; retain the operation receipt before retrying.'; }
        return [
            'ok'=>false,
            'message'=>$this->safeOperationText($summary !== '' ? $summary : 'The operation failed without an error message.', $secrets),
            'detail'=>$this->safeOperationText('Action: ' . ($command ?: 'ajax') . "\n" . implode("\nCaused by:\n", $messages), $secrets),
        ] + $recovery;
    }

    /** Returned partial failures need the same visible/logged detail as thrown failures. */
    private function siteActionEditorRevision(int $siteId): ?string
    {
        $base=$this->siteActionSavedBase[$siteId]??null;
        if (!$base) { return null; }
        $current=$this->getSite($siteId);
        if (!$current) { return null; }
        $managed=['pbx_ssh_user','pbx_ssh_secret','pbx_host_key','pbx_agent_user','pbx_agent_ready','pbx_bootstrapped_at',
            'pbx_api_enabled','pbx_api_ready','pbx_api_secret','pbx_api_last_ok','pbx_tftp_bootstrapped_at',
            'docker_ssh_user','docker_ssh_secret','docker_host_key','docker_bootstrapped_at'];
        foreach (array_unique(array_merge(array_keys($base),array_keys($current))) as $field) {
            if (!in_array($field,$managed,true)&&(string)($base[$field]??'')!==(string)($current[$field]??'')) { return null; }
        }
        return self::siteEditorRevision($current);
    }

    private function siteActionResponse(int $siteId, string $command, array $result): array
    {
        $revision=$this->siteActionEditorRevision($siteId);
        if ($revision!==null) { $result['site_editor_revision']=$revision; }
        $result['message'] = $this->safeOperationText((string)($result['message'] ?? ''));
        $detail = $result['detail'] ?? '';
        if (is_array($detail)) { $detail = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); }
        $result['detail'] = $this->safeOperationText((string)$detail);
        if (($result['ok'] ?? true) === false) {
            $result['detail'] = $this->safeOperationText('Action: ' . $command . "\nStage: " . (string)($result['phase'] ?? 'site action') . "\n"
                . ($result['detail'] !== '' ? $result['detail'] : $result['message'] . "\nNo additional command output was recorded."));
            $this->logOperation($siteId ?: null, $command, !empty($result['partial']) || ($result['status'] ?? '') === 'partial' ? 'warning' : 'error', $result['message'], $result['detail']);
        }
        return ['status'=>true, 'result'=>$result];
    }

    public function logOperation(?int $siteId, string $operation, string $level, string $message, string $detail = ''): void
    {
        try {
            if ($detail === '' && (strlen($message) > 255 || in_array($level, ['error','warning'], true))) { $detail = $message; }
            $message = $this->safeOperationText($message);
            $detail = $this->safeOperationText($detail);
            $this->db->prepare('INSERT INTO ucmp_operation_log (created, site_id, level, operation, message, detail) VALUES (?,?,?,?,?,?)')
                ->execute([time(), $siteId ?: null, substr($level, 0, 12), substr($operation, 0, 48), substr($message, 0, 255), $detail]);
            $this->db->exec('DELETE FROM ucmp_operation_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM ucmp_operation_log ORDER BY id DESC LIMIT 500) keep_rows)');
        } catch (\Throwable $e) {
            // Logging must never hide the operation's real result.
        }
    }

    /** Enable/disable the local encrypted federation endpoint. Called through SSH bootstrap. */
    public function configureFederationApiLocal(string $secret, bool $enabled = true): array
    {
        $localId = $this->localSiteId();
        if (!$enabled) {
            $this->setSetting('federation_api_enabled', '0', $localId);
            $this->setSetting('federation_api_secret', '', $localId);
            return ['ok'=>true,'message'=>'Federation API disabled'];
        }
        if (!preg_match('/^[A-Fa-f0-9]{64,128}$/', $secret)) { throw new \InvalidArgumentException('Invalid federation API secret'); }
        $this->setSetting('federation_api_secret', $this->enc(strtolower($secret)), $localId);
        $this->setSetting('federation_api_enabled', '1', $localId);
        return ['ok'=>true,'message'=>'Encrypted federation API enabled','api_version'=>1];
    }

    /** One-click API enrollment/rotation; no root access is needed after SSH-agent enrollment. */
    public function configureSiteApi(int $siteId, bool $enabled, string $scheme, string $host, int $port, bool $verifyTls): array
    {
        $site = $this->getSite($siteId);
        if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
        $scheme = strtolower(trim($scheme));
        if (!in_array($scheme, ['http','https'], true)) { throw new \InvalidArgumentException('API scheme must be HTTP or HTTPS'); }
        $host = trim($host) ?: trim((string)($site['pbx_host'] ?? ''));
        $endpoint = $this->validateSiteApiEndpoint($siteId, [
            'api_scheme'=>$scheme, 'api_host'=>$host, 'api_port'=>(string)$port, 'api_verify_tls'=>$verifyTls,
        ]);
        $scheme = $endpoint['scheme']; $host = $endpoint['host']; $port = $endpoint['port']; $verifyTls = $endpoint['verify_tls'];
        if (!$enabled) {
            $result = $this->remoteQueryViaSsh($siteId, 'configureFederationApiLocal', ['', false]);
            if (empty($result['ok'])) { return $result; }
            $this->db->prepare('UPDATE ucmp_sites SET pbx_api_enabled=0,pbx_api_ready=0,pbx_api_secret=NULL,pbx_api_last_ok=0 WHERE id=?')->execute([$siteId]);
            unset($this->siteConnections[$siteId]);
            $this->logOperation($siteId, 'api-disable', 'success', 'Federation API disabled; SSH recovery remains enrolled');
            return ['ok'=>true,'message'=>'Federation API disabled. The constrained SSH channel remains available for recovery.'];
        }
        $secret = bin2hex(random_bytes(32));
        $configured = $this->remoteQueryViaSsh($siteId, 'configureFederationApiLocal', [$secret, true]);
        if (empty($configured['ok'])) { return $configured; }
        $this->db->prepare('UPDATE ucmp_sites SET pbx_api_enabled=1,pbx_api_ready=0,pbx_api_scheme=?,pbx_api_host=?,pbx_api_port=?,pbx_api_verify_tls=?,pbx_api_secret=? WHERE id=?')
            ->execute([$scheme,$host,$port,$verifyTls?1:0,$this->enc($secret),$siteId]);
        // remoteQueryViaSsh() created this connection before the rotated secret was saved.
        // Rebuild it so the enrollment probe uses the same key the remote just accepted.
        unset($this->siteConnections[$siteId]);
        try {
            $probe = $this->siteConnection($siteId)->execApi(['method'=>'moduleStatus','args'=>[]]);
            if (empty($probe['ok'])) { throw new \RuntimeException((string)($probe['message'] ?? 'API probe failed')); }
            $extensions = $this->siteConnection($siteId)->execApi(['method'=>'getAllSipExtensionsLocal','args'=>[]]);
            if (!array_is_list($extensions)) { throw new \RuntimeException('Management RPC returned an invalid extension catalog'); }
            $this->db->prepare('UPDATE ucmp_sites SET pbx_api_ready=1,pbx_api_last_ok=? WHERE id=?')->execute([time(),$siteId]);
            unset($this->siteConnections[$siteId]);
            $this->logOperation(
                $siteId,
                'api-enrollment',
                'success',
                'Encrypted federation API enrolled and management RPC verified',
                strtoupper($scheme) . " $host:$port; " . count($extensions) . ' extension(s)'
            );
            return [
                'ok'=>true,
                'message'=>'Encrypted federation API and extension management RPC verified. SSH is now the recovery channel.',
                'version'=>$probe['version'] ?? null,
                'extensions'=>count($extensions),
            ];
        } catch (\Throwable $e) {
            $this->logOperation($siteId, 'api-enrollment', 'error', 'Remote accepted the API key, but the controller probe failed', $e->getMessage());
            throw new \RuntimeException('Remote accepted the API key, but the controller could not reach the endpoint: ' . $e->getMessage());
        }
    }

    public function getOperationLogs(?int $siteId = null, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        if ($siteId) {
            $s = $this->db->prepare("SELECT * FROM ucmp_operation_log WHERE site_id = ? ORDER BY id DESC LIMIT $limit");
            $s->execute([$siteId]);
            return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $this->db->query("SELECT * FROM ucmp_operation_log ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Read-only Sites polling: never fall back to all-site logs or run migrations. */
    private function siteOperationLogs(int $siteId): array
    {
        if ($siteId <= 0 || !$this->getSite($siteId)) {
            throw new \InvalidArgumentException('Choose a saved site to read its operation log');
        }
        $logs = [];
        foreach ($this->getOperationLogs($siteId, 20) as $row) {
            $logs[] = [
                'id'=>(int)$row['id'], 'created'=>(int)$row['created'],
                'created_label'=>date('Y-m-d H:i:s', (int)$row['created']),
                'level'=>(string)$row['level'], 'operation'=>(string)$row['operation'],
                'message'=>$this->safeOperationText((string)$row['message']),
                'detail'=>$this->safeOperationText((string)($row['detail'] ?? '')),
            ];
        }
        return ['ok'=>true, 'logs'=>$logs, 'collected_at'=>time()];
    }

    public function moduleStatus(): array
    {
        $xml = @simplexml_load_file(__DIR__ . '/module.xml');
        $version = $xml ? (string)$xml->version : 'unknown';
        $patch = $this->asteriskPatchPreflight();
        $freepbx = trim((string)@shell_exec('/usr/bin/timeout --signal=KILL 5 /usr/sbin/fwconsole --version 2>/dev/null'));
        $asterisk = trim((string)($patch['asterisk_version'] ?? ''));
        $f2bClass = '\\FreePBX\\modules\\Ucmprovisioner\\Fail2banService';
        $fail2ban = class_exists($f2bClass) ? $f2bClass::enrollmentStatus() : ['enrolled'=>false,'message'=>'Fail2ban service engine is unavailable'];
        return ['ok' => true, 'installed' => true, 'version' => $version, 'freepbx' => $freepbx, 'asterisk' => $asterisk, 'ucm_patch_detected' => $patch['usecallmanager_detected'], 'patch_preflight' => $patch, 'signing' => $this->moduleSigningStatus(), 'fail2ban' => $fail2ban];
    }

    /** Read-only patch/package inventory for the selected site. */
    public function getPatchInventory(int $siteId = 0): array
    {
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'getPatchInventoryLocal');
        }
        return $this->getPatchInventoryLocal();
    }

    /** RPC-safe local inventory; contains no privileged or mutating action. */
    public function getPatchInventoryLocal(): array
    {
        return (new \FreePBX\modules\Ucmprovisioner\PatchInventoryService())->inventory();
    }

    /** Privileged actions remain local; remote sites expose inventory only. */
    public function getPatchWorkflow(int $siteId = 0, string $catalogId = ''): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if ($siteId > 0 && !$this->isSiteLocal($siteId)) {
            $inventory = $this->getPatchInventory($siteId);
            return [
                'ok'=>true,
                'enrolled'=>false,
                'local_only'=>true,
                'remote_site'=>true,
                'catalog'=>\FreePBX\modules\Ucmprovisioner\PatchCatalog::entries(),
                'runtime'=>[
                    'running_version'=>$inventory['running_version'] ?? '',
                    'installed_version'=>$inventory['installed_version'] ?? '',
                    'installed_binary_sha256'=>$inventory['binary_sha256'] ?? '',
                    'chan_sip_loaded'=>!empty($inventory['chan_sip_loaded']),
                    'usecallmanager_detected'=>!empty($inventory['usecallmanager_detected']),
                ],
                'bundles'=>$inventory['managed_bundles'] ?? [],
                'incoming'=>$inventory['managed_incoming'] ?? [],
                'active_bundle_id'=>$inventory['active_bundle_id'] ?? '',
                'pinned_bundle_id'=>$inventory['pinned_bundle_id'] ?? '',
                'pin_protected'=>!empty($inventory['pin_protected']),
                'pin_drift'=>!empty($inventory['pin_drift']),
                'history'=>$inventory['history'] ?? [],
                'message'=>'Remote inventory is read-only. Open that PBX locally to build, import, activate or pin a version.',
            ];
        }
        return (new \FreePBX\modules\Ucmprovisioner\PatchWorkflowService())->state($catalogId);
    }

    /** Authenticated browser action; deliberately absent from remoteDispatch(). */
    public function patchWorkflowOp(array $request): array
    {
        $siteId = (int)($request['site_id'] ?? 0) ?: $this->localSiteId();
        $operation = strtolower(trim((string)($request['op'] ?? 'status')));
        if ($operation === 'sync') {
            return $this->syncAsteriskBundle(
                $siteId,
                (string)($request['bundle_id'] ?? ''),
                is_array($request['target_site_ids'] ?? null)
                    ? $request['target_site_ids']
                    : preg_split('/\s*,\s*/', (string)($request['target_site_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)
            );
        }
        if (!$this->isSiteLocal($siteId)) {
            throw new \RuntimeException('Patch Manager actions are local-only; open the target PBX directly');
        }
        if ($operation === 'upload') {
            $staged = $this->stageAsteriskUpload($_FILES['file'] ?? null, $request);
            $operation = 'import';
            $request['incoming_id'] = $staged['incoming_id'];
        }
        $payload = [
            'op'=>$operation,
        ];
        foreach ([
            'catalog_id','bundle_id','incoming_id','declared_version','confirmation',
            'high_strength_srtp','jobs','declared_patched',
        ] as $field) {
            if (array_key_exists($field, $request)) {
                $payload[$field] = is_scalar($request[$field]) ? (string)$request[$field] : '';
            }
        }
        $result = (new \FreePBX\modules\Ucmprovisioner\PatchWorkflowService())->handle($payload);
        if ($operation !== 'status') {
            $this->logOperation(
                $siteId,
                'patch-' . $operation,
                !empty($result['ok']) ? 'success' : 'error',
                (string)($result['message'] ?? 'Patch workflow request completed'),
                (string)($result['bundle']['bundle_id'] ?? ($result['active_bundle_id'] ?? ''))
            );
        }
        return $result;
    }

    /** @return array{incoming_id:string} */
    private function stageAsteriskUpload(mixed $upload, array $request): array
    {
        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Choose an Asterisk executable or an exported UCM Provisioner bundle');
        }
        $size = (int)($upload['size'] ?? 0);
        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($size < 4 || $size > 536870912 || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Asterisk upload is missing or exceeds the 512 MiB manager limit');
        }
        $root = '/var/lib/asterisk/ucmprovisioner/asterisk-incoming';
        if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
            throw new \RuntimeException('Cannot create the Asterisk incoming directory');
        }
        @chown($root, 'asterisk'); @chgrp($root, 'asterisk'); @chmod($root, 0770);
        $id = bin2hex(random_bytes(16));
        $destination = $root . '/' . $id . '.upload';
        if (!@move_uploaded_file($tmp, $destination)) {
            throw new \RuntimeException('Could not stage the Asterisk upload');
        }
        @chown($destination, 'asterisk'); @chgrp($destination, 'asterisk'); @chmod($destination, 0640);
        $meta = [
            'filename'=>basename((string)($upload['name'] ?? 'upload')),
            'declared_version'=>substr(trim((string)($request['declared_version'] ?? '')), 0, 64),
            'received_at'=>time(),
            'size_bytes'=>$size,
        ];
        $metaPath = $root . '/' . $id . '.json';
        if (@file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            @unlink($destination);
            throw new \RuntimeException('Could not record the staged Asterisk upload');
        }
        @chown($metaPath, 'asterisk'); @chgrp($metaPath, 'asterisk'); @chmod($metaPath, 0640);
        return ['incoming_id'=>$id];
    }

    /**
     * Copy a root-owned, read-only exported bundle to other sites' incoming
     * inventories. Import and activation remain explicit on each target PBX.
     */
    public function syncAsteriskBundle(int $sourceSiteId, string $bundleId, array $targetSiteIds): array
    {
        $sourceSiteId = $sourceSiteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($sourceSiteId)) {
            throw new \RuntimeException('Open the source PBX locally to synchronize its compiled Asterisk bundle');
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{8,95}$/D', $bundleId)) {
            throw new \InvalidArgumentException('Choose a valid managed Asterisk bundle');
        }
        $source = '/var/lib/asterisk/ucmprovisioner/asterisk-bundles/' . $bundleId . '.tar.gz';
        if (!is_file($source) || is_link($source) || filesize($source) > 536870912) {
            throw new \RuntimeException('The selected compiled Asterisk bundle is unavailable');
        }
        $targets = array_values(array_unique(array_filter(array_map('intval', $targetSiteIds), static fn(int $id): bool => $id > 0 && $id !== $sourceSiteId)));
        if (!$targets) { throw new \InvalidArgumentException('Choose at least one different target site'); }
        $size = (int)filesize($source);
        $sha256 = hash_file('sha256', $source);
        $chunkSize = 524288;
        $total = (int)ceil($size / $chunkSize);
        $results = [];
        foreach ($targets as $targetId) {
            $transferId = bin2hex(random_bytes(16));
            $handle = fopen($source, 'rb');
            if (!$handle) { throw new \RuntimeException('Could not open the compiled Asterisk bundle'); }
            try {
                for ($index = 0; $index < $total; $index++) {
                    $chunk = fread($handle, $chunkSize);
                    if (!is_string($chunk) || $chunk === '') { throw new \RuntimeException('Asterisk bundle transfer ended early'); }
                    $part = $this->remoteQuery($targetId, 'stageAsteriskBundleChunkLocal', [
                        $transferId, $index, $total, base64_encode($chunk),
                    ]);
                    if (empty($part['ok'])) { throw new \RuntimeException((string)($part['message'] ?? 'Target rejected an Asterisk bundle chunk')); }
                }
                $commit = $this->remoteQuery($targetId, 'commitAsteriskBundleLocal', [
                    $transferId, $total, $sha256, $bundleId, $size,
                ]);
                $results[] = ['site_id'=>$targetId] + (array)$commit;
            } catch (\Throwable $failure) {
                try { $this->remoteQuery($targetId, 'abortAsteriskBundleLocal', [$transferId]); }
                catch (\Throwable) {
                    // Incoming chunks expire independently; keep the original site
                    // transfer error as the durable replication result.
                }
                $results[] = ['site_id'=>$targetId,'ok'=>false,'message'=>$failure->getMessage()];
            } finally {
                fclose($handle);
            }
        }
        $ok = !in_array(false, array_map(static fn(array $row): bool => !empty($row['ok']), $results), true);
        return [
            'ok'=>$ok,
            'sites'=>$results,
            'message'=>$ok
                ? 'Compiled Asterisk bundle synchronized; import it from Incoming bundles on each target PBX.'
                : 'One or more target Asterisk inventories failed to synchronize.',
        ];
    }

    public function stageAsteriskBundleChunkLocal(string $transferId, int $index, int $total, string $chunk): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId) || $index < 0 || $total < 1 || $total > 1024 || $index >= $total) {
            throw new \InvalidArgumentException('Invalid Asterisk bundle transfer chunk');
        }
        $decoded = base64_decode($chunk, true);
        if (!is_string($decoded) || $decoded === '' || strlen($decoded) > 524288) {
            throw new \InvalidArgumentException('Invalid Asterisk bundle transfer payload');
        }
        $directory = $this->asteriskTransferDirectory($transferId);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create Asterisk bundle transfer storage');
        }
        @chown($directory, 'asterisk'); @chgrp($directory, 'asterisk'); @chmod($directory, 0770);
        $path = $directory . '/' . sprintf('%06d.part', $index);
        if (file_put_contents($path, $decoded, LOCK_EX) !== strlen($decoded)) {
            throw new \RuntimeException('Could not stage Asterisk bundle chunk');
        }
        @chown($path, 'asterisk'); @chgrp($path, 'asterisk'); @chmod($path, 0640);
        return ['ok'=>true,'index'=>$index];
    }

    public function commitAsteriskBundleLocal(string $transferId, int $total, string $sha256, string $bundleId, int $size): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId)
            || !preg_match('/^[a-f0-9]{64}$/D', $sha256)
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{8,95}$/D', $bundleId)
            || $total < 1 || $total > 1024 || $size < 4 || $size > 536870912
        ) {
            throw new \InvalidArgumentException('Invalid Asterisk bundle commit');
        }
        $directory = $this->asteriskTransferDirectory($transferId);
        $root = dirname($directory);
        $tmp = $root . '/' . $transferId . '.tmp';
        $output = fopen($tmp, 'xb');
        if (!$output) { throw new \RuntimeException('Could not create Asterisk bundle commit file'); }
        try {
            for ($index = 0; $index < $total; $index++) {
                $part = $directory . '/' . sprintf('%06d.part', $index);
                $input = fopen($part, 'rb');
                if (!$input) { throw new \RuntimeException('Asterisk bundle transfer is incomplete'); }
                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new \RuntimeException('Could not assemble an Asterisk bundle chunk');
                    }
                } finally {
                    fclose($input);
                }
            }
        } catch (\Throwable $failure) {
            @unlink($tmp);
            throw $failure;
        } finally {
            fclose($output);
        }
        if (filesize($tmp) !== $size || !hash_equals($sha256, hash_file('sha256', $tmp))) {
            @unlink($tmp);
            throw new \RuntimeException('Asterisk bundle transfer hash or size does not match');
        }
        $incomingId = $transferId;
        $destination = $root . '/' . $incomingId . '.upload';
        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not commit synchronized Asterisk bundle');
        }
        @chown($destination, 'asterisk'); @chgrp($destination, 'asterisk'); @chmod($destination, 0640);
        $metaPath = $root . '/' . $incomingId . '.json';
        $meta = json_encode([
            'filename'=>$bundleId . '.tar.gz',
            'declared_version'=>'',
            'source_site'=>'synchronized',
            'received_at'=>time(),
            'size_bytes'=>$size,
            'sha256'=>$sha256,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($metaPath, $meta, LOCK_EX) !== strlen($meta)) {
            @unlink($destination);
            throw new \RuntimeException('Could not record the synchronized Asterisk bundle');
        }
        @chown($metaPath, 'asterisk'); @chgrp($metaPath, 'asterisk'); @chmod($metaPath, 0640);
        $this->removeAsteriskTransferDirectory($directory);
        return ['ok'=>true,'incoming_id'=>$incomingId,'bundle_id'=>$bundleId,'message'=>'Bundle received and awaiting local import'];
    }

    public function abortAsteriskBundleLocal(string $transferId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $transferId)) {
            throw new \InvalidArgumentException('Invalid Asterisk bundle transfer ID');
        }
        $this->removeAsteriskTransferDirectory($this->asteriskTransferDirectory($transferId));
        return ['ok'=>true];
    }

    private function asteriskTransferDirectory(string $transferId): string
    {
        return '/var/lib/asterisk/ucmprovisioner/asterisk-incoming/.transfer-' . $transferId;
    }

    private function removeAsteriskTransferDirectory(string $directory): void
    {
        $root = '/var/lib/asterisk/ucmprovisioner/asterisk-incoming';
        if (!str_starts_with($directory, $root . '/.transfer-') || !is_dir($directory) || is_link($directory)) { return; }
        foreach (glob($directory . '/*.part') ?: [] as $part) {
            if (is_file($part) && !is_link($part)) { @unlink($part); }
        }
        @rmdir($directory);
    }

    public function asteriskPatchPreflight(): array
    {
        $glue = new \FreePBX\modules\Ucmprovisioner\AsteriskGlue($this);
        return $glue->patchPreflight();
    }

    /** Lightweight runtime probe used by the dashboard; deliberately avoids the GPG/module scan. */
    public function ucmPatchStatus(): array
    {
        static $status = null;
        if (is_array($status)) { return $status; }
        $preflight = $this->asteriskPatchPreflight();
        $detected = !empty($preflight['usecallmanager_detected']);
        return $status = [
            'detected' => $detected,
            'application' => 'SIPCiscoPage',
            'eligible' => !empty($preflight['eligible']),
            'asterisk_version' => (string)($preflight['asterisk_version'] ?? ''),
            'chan_sip_loaded' => !empty($preflight['chan_sip_loaded']),
            'message' => !empty($preflight['eligible'])
                ? 'USECALLMANAGER Asterisk runtime is eligible'
                : (string)($preflight['warning'] ?? 'USECALLMANAGER Asterisk runtime preflight failed'),
        ];
    }

    /**
     * Report FreePBX's own module-integrity result. This is OpenPGP module
     * signing, deliberately separate from the X.509 Certificate Manager.
     */
    public function moduleSigningStatus(): array
    {
        if (function_exists('posix_geteuid') && (posix_getpwuid(posix_geteuid())['name'] ?? '') === 'ucmp-pbx') {
            return ['ok'=>false, 'trusted'=>false, 'state'=>'unavailable', 'label'=>'Signature not checked through the SSH agent',
                'details'=>['Use the federation API or an administrator version check; the restricted agent does not access the FreePBX GPG keyring.']];
        }
        try {
            $verification = \FreePBX::GPG()->verifyModule('ucmprovisioner');
            $code = (int)($verification['status'] ?? 0);
            $details = array_values(array_filter(array_map('strval', (array)($verification['details'] ?? ($verification['trustdetails'] ?? [])))));
            $trusted = (($code & 1) === 1) && (($code & 128) === 128) && (($code & (2 | 4 | 8 | 16 | 32 | 64)) === 0);
            if ($trusted) {
                $state = 'locally_signed';
                $label = 'Locally signed and verified';
            } elseif (($code & 8) !== 0) {
                $state = 'unsigned';
                $label = 'Unsigned';
            } elseif (($code & 2) !== 0) {
                $state = 'tampered';
                $label = 'Signature mismatch / changed files';
            } elseif (($code & 4) !== 0) {
                if (is_file(__DIR__ . '/module.sig')) {
                    $state = 'untrusted_signature';
                    $label = 'Signature present; local key is not trusted';
                    $details[] = 'The signature file exists, but this PBX cannot validate its signing key. Re-sign on this PBX to repair the local trust anchor.';
                } else {
                    $state = 'invalid';
                    $label = 'Invalid or unknown signing key';
                }
            } elseif (($code & 32) !== 0) {
                $state = 'expired';
                $label = 'Signing key expired';
            } elseif (($code & 64) !== 0) {
                $state = 'revoked';
                $label = 'Signing key revoked';
            } elseif (($code & 16) !== 0) {
                $state = 'unsupported';
                $label = 'Signature format unsupported';
            } else {
                $state = 'unverified';
                $label = 'Not verified';
            }
            return ['ok' => true, 'trusted' => $trusted, 'state' => $state, 'label' => $label, 'status_code' => $code, 'details' => $details];
        } catch (\Throwable $e) {
            return ['ok' => false, 'trusted' => false, 'state' => 'error', 'label' => 'Signing check failed', 'status_code' => 0, 'details' => [$e->getMessage()]];
        }
    }

    /** Build the request-scoped fields used by the PBX onboarding actions. */
    private function remoteOnboardingInput(array $request): array
    {
        return [
            'admin_user'=>(string)($request['admin_user'] ?? ''),
            'admin_pass'=>(string)($request['admin_pass'] ?? ''),
            'use_sudo'=>(($request['use_sudo'] ?? '') === '1'),
            'expected_host_key'=>(string)($request['expected_host_key'] ?? ''),
            'api_enabled'=>array_key_exists('api_enabled', $request) ? (($request['api_enabled'] ?? '') === '1') : true,
            'api_scheme'=>(string)($request['api_scheme'] ?? ''),
            'api_host'=>(string)($request['api_host'] ?? ''),
            'api_port'=>(string)($request['api_port'] ?? ''),
            'api_verify_tls'=>array_key_exists('api_verify_tls', $request) ? (($request['api_verify_tls'] ?? '') === '1') : null,
            'sign_after'=>(($request['sign_after'] ?? '1') === '1'),
        ];
    }

    /** Parse only a bounded JSON line from a privileged remote probe. */
    private function decodeRemoteProbe(string $output): ?array
    {
        if ($output === '' || strlen($output) > 32768) { return null; }
        foreach (array_reverse(preg_split('/\R/', $output) ?: []) as $line) {
            $candidate = json_decode(trim((string)$line), true);
            if (is_array($candidate)) { return $candidate; }
        }
        return null;
    }

    /**
     * Fresh administrator-side module/platform probe. It intentionally does not
     * call the remote module, RPC or API, so a blank/legacy target can be
     * classified before any installer or agent write is dispatched.
     */
    public function probeRemoteModuleAdmin(
        int $siteId,
        string $adminUser,
        string $adminPass,
        bool $useSudo = false,
        string $expectedHostKey = ''
    ): array {
        $site = $this->getSite($siteId);
        if (!$site || !empty($site['is_local'])) {
            throw new \InvalidArgumentException('Choose a saved remote site');
        }
        try {
            [$ssh, $hostKey] = $this->remoteAdminSsh($site, $adminUser, $adminPass, $expectedHostKey);
            $probePhp = <<<'PHP'
require '/etc/freepbx.conf';
$out = [
    'probe_status' => 'ok',
    'php_version' => PHP_VERSION,
    'file_present' => false,
    'file_version' => null,
    'registry_present' => false,
    'registry_version' => null,
    'registry_enabled' => null,
    'registry_status' => null,
    'core_version' => null,
    'framework_version' => null,
    'bmo_loadable' => null,
];
try {
    $path = '/var/www/html/admin/modules/ucmprovisioner/module.xml';
    $xml = is_file($path) ? @simplexml_load_file($path) : false;
    $out['file_present'] = is_file($path);
    $out['file_version'] = $xml ? trim((string)$xml->version) : null;
    if ($out['file_present'] && !$xml) { $out['file_error'] = 'module.xml could not be parsed'; }
} catch (Throwable $e) { $out['file_error'] = 'module.xml probe failed'; }
try {
    $db = \FreePBX::Database();
    if ($db instanceof PDO || (is_object($db) && method_exists($db, 'prepare'))) {
        $stmt = $db->prepare('SELECT modulename, version, enabled FROM modules WHERE modulename IN (?, ?)');
        $stmt->execute(['ucmprovisioner', 'core']);
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $name = strtolower((string)($row['modulename'] ?? ''));
            if ($name === 'ucmprovisioner') {
                $out['registry_present'] = true;
                $out['registry_version'] = trim((string)($row['version'] ?? '')) ?: null;
                $out['registry_enabled'] = ((string)($row['enabled'] ?? '') === '1' || strtolower((string)($row['enabled'] ?? '')) === 'true');
            } elseif ($name === 'core') {
                $out['core_version'] = trim((string)($row['version'] ?? '')) ?: null;
            }
        }
    } else { $out['registry_error'] = 'FreePBX database handle is not queryable'; }
} catch (Throwable $e) { $out['registry_error'] = 'FreePBX module registry probe failed'; }
try {
    $fw = trim((string)@shell_exec('/usr/sbin/fwconsole --version 2>/dev/null'));
    if (preg_match('/\b(\d+\.\d+(?:\.\d+)?)\b/', $fw, $m)) { $out['framework_version'] = $m[1]; }
} catch (Throwable $e) { $out['framework_error'] = 'FreePBX framework probe failed'; }
echo json_encode($out, JSON_UNESCAPED_SLASHES);
exit(0);
PHP;
            $command = '/usr/bin/php -r ' . escapeshellarg($probePhp);
            $output = $this->execPrivilegedScript($ssh, $command, $adminUser, $adminPass, $useSudo);
            $exit = $ssh->getExitStatus();
            $probe = is_int($exit) && $exit === 0 ? $this->decodeRemoteProbe((string)$output) : null;
            if (!is_array($probe) || ($probe['probe_status'] ?? '') !== 'ok') {
                return ['ok'=>false, 'status'=>'unknown', 'probe_status'=>'unknown', 'comparison'=>'unknown', 'host_key'=>$hostKey, 'message'=>'The PBX administrator module probe returned no valid bounded result; module state is unknown.'];
            }
            $probe['host_key'] = $hostKey;
            return ['ok'=>true] + $probe;
        } catch (\Throwable $e) {
            return ['status'=>'unknown', 'probe_status'=>'unknown', 'comparison'=>'unknown']
                + $this->operationFailureResult($e, 'PBX administrator module probe', [$adminPass]);
        }
    }

    /** Run the potentially bootstrapping BMO loadability check only after the
     * file, registry and supported platform checks identify a current module. */
    public function probeRemoteBmoLoadability(int $siteId, string $adminUser, string $adminPass, bool $useSudo = false, string $expectedHostKey = ''): array
    {
        try {
            $site = $this->getSite($siteId);
            if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
            [$ssh, $hostKey] = $this->remoteAdminSsh($site, $adminUser, $adminPass, $expectedHostKey);
            $php = <<<'PHP'
require '/etc/freepbx.conf';
try {
    $object=FreePBX::Ucmprovisioner();
    $signing=method_exists($object, 'moduleSigningStatus') ? $object->moduleSigningStatus() : ['state'=>'unknown','label'=>'Signing status unavailable on this version'];
    echo json_encode(['ok'=>is_object($object),'signing'=>$signing], JSON_UNESCAPED_SLASHES);
}
catch (Throwable $e) { echo json_encode(['ok'=>false], JSON_UNESCAPED_SLASHES); }
exit(0);
PHP;
            $output = $this->execPrivilegedScript($ssh, '/usr/sbin/runuser -u asterisk -- /usr/bin/php -r ' . escapeshellarg($php), $adminUser, $adminPass, $useSudo);
            if ($ssh->getExitStatus() !== 0) { throw new \RuntimeException('BMO loadability command failed'); }
            $result = $this->decodeRemoteProbe((string)$output);
            if (!is_array($result) || !array_key_exists('ok', $result)) { throw new \RuntimeException('BMO loadability probe returned no valid result'); }
            return ['ok'=>(bool)$result['ok'], 'ready'=>(bool)$result['ok'], 'status'=>(bool)$result['ok'] ? 'current' : 'broken-current', 'host_key'=>$hostKey, 'signing'=>$result['signing'] ?? null, 'message'=>(bool)$result['ok'] ? 'BMO loadability probe passed.' : 'BMO loadability probe failed.'];
        } catch (\Throwable $e) {
            return ['ready'=>false, 'status'=>'unknown'] + $this->operationFailureResult($e, 'PBX BMO loadability probe', [$adminPass]);
        }
    }

    /** Convert a successful admin probe into an install/no-install decision. */
    private function summarizeRemoteModuleProbe(array $probe, string $requiredVersion): array
    {
        if (($probe['probe_status'] ?? '') !== 'ok') {
            return ['status'=>'unknown', 'comparison'=>'unknown', 'remote_version'=>null, 'message'=>'Remote module probe is unknown; no installer action was dispatched.'];
        }
        if (!empty($probe['file_error']) || !empty($probe['registry_error'])
            || trim((string)($probe['php_version'] ?? '')) === ''
            || trim((string)($probe['framework_version'] ?? '')) === ''
            || trim((string)($probe['core_version'] ?? '')) === '') {
            return ['status'=>'unknown', 'comparison'=>'unknown', 'remote_version'=>null, 'message'=>'The remote platform or module registry probe is incomplete; state is unknown.'];
        }
        if (version_compare((string)$probe['php_version'], '8.2.0', '<')
            || version_compare((string)$probe['framework_version'], '17.0.1', '<')
            || version_compare((string)$probe['core_version'], '17.0.1', '<')) {
            return ['status'=>'unsupported', 'comparison'=>'unknown', 'remote_version'=>null, 'message'=>'The remote PBX does not meet the supported PHP 8.2 / FreePBX 17.0.1 platform baseline.'];
        }
        $fileVersion = trim((string)($probe['file_version'] ?? ''));
        $registryVersion = trim((string)($probe['registry_version'] ?? ''));
        $versions = array_values(array_filter([$fileVersion, $registryVersion], static fn(string $v): bool => $v !== ''));
        foreach ($versions as $version) {
            if (!preg_match('/^\d+(?:\.\d+)*(?:[A-Za-z][0-9A-Za-z._-]*)?$/', $version)) {
                return ['status'=>'unknown', 'comparison'=>'unknown', 'remote_version'=>$version, 'message'=>'The remote module reported an invalid version; state is unknown.'];
            }
        }
        $newer = false; $older = false;
        foreach ($versions as $version) {
            $cmp = version_compare($version, $requiredVersion);
            $newer = $newer || $cmp > 0;
            $older = $older || $cmp < 0;
        }
        if ($newer) {
            return ['status'=>'remote_newer', 'comparison'=>'remote_newer', 'remote_version'=>$fileVersion ?: $registryVersion, 'message'=>'The remote filesystem or module registry is newer; refusing to downgrade it.'];
        }
        if (!$versions && empty($probe['file_present']) && empty($probe['registry_present'])) {
            return ['status'=>'missing', 'comparison'=>'missing', 'remote_version'=>null, 'message'=>'The remote module is not installed.'];
        }
        if (!$versions || (empty($probe['file_present']) && !empty($probe['registry_present'])) || (!empty($probe['file_present']) && empty($probe['registry_present']))) {
            return ['status'=>empty($probe['file_present']) ? 'missing' : 'missingregistry', 'comparison'=>'remote_older', 'remote_version'=>$versions[0] ?? null, 'message'=>'The remote module probe is incomplete; registry and filesystem state do not agree.'];
        }
        if ($fileVersion !== $registryVersion) {
            return ['status'=>'registry_mismatch', 'comparison'=>$older ? 'remote_older' : 'same', 'remote_version'=>$fileVersion, 'message'=>'The remote module filesystem and registry versions do not agree.'];
        }
        if ($older) {
            return ['status'=>'older', 'comparison'=>'remote_older', 'remote_version'=>$fileVersion, 'message'=>'The remote module is older than the controller.'];
        }
        if ($probe['registry_enabled'] !== true) {
            return ['status'=>'disabled', 'comparison'=>'remote_older', 'remote_version'=>$fileVersion, 'message'=>'The remote module is installed but disabled in the FreePBX registry.'];
        }
        return ['status'=>'current', 'comparison'=>'same', 'remote_version'=>$fileVersion, 'message'=>'The remote module is current, enabled and BMO-loadable.'];
    }

    public function remoteModuleStatus(int $siteId, string $adminUser = '', string $adminPass = '', string $expectedHostKey = '', bool $useSudo = false): array
    {
        $site = $this->getSite($siteId);
        if (!$site || !empty($site['is_local'])) {
            return $this->moduleStatus() + ['comparison'=>'same', 'status'=>'current'];
        }
        $local = $this->moduleStatus();
        $required = (string)($local['version'] ?? '');
        if ($adminUser !== '' && $adminPass !== '') {
            $probe = $this->probeRemoteModuleAdmin($siteId, $adminUser, $adminPass, $useSudo, $expectedHostKey);
            if (empty($probe['ok'])) {
                return ['ok'=>false, 'status'=>'unknown', 'comparison'=>'unknown', 'local_version'=>$required, 'remote_version'=>null, 'remote'=>$probe, 'message'=>(string)($probe['message'] ?? 'Remote module state is unknown; no installer action was dispatched.'), 'detail'=>(string)($probe['detail'] ?? '')];
            }
            $summary = $this->summarizeRemoteModuleProbe($probe, $required);
            $signing = ['state'=>'unknown', 'label'=>'Signature not checked for this module state'];
            if (($summary['status'] ?? '') === 'current') {
                $bmo = $this->probeRemoteBmoLoadability($siteId, $adminUser, $adminPass, $useSudo, $expectedHostKey);
                if (is_array($bmo['signing'] ?? null)) { $signing = $bmo['signing']; }
                if (empty($bmo['ok'])) {
                    $summary = empty($bmo['status']) || $bmo['status'] === 'unknown'
                        ? ['status'=>'unknown', 'comparison'=>'unknown', 'remote_version'=>$summary['remote_version'], 'message'=>(string)($bmo['message'] ?? 'BMO loadability is unknown; no install was dispatched.')]
                        : ['status'=>'broken-current', 'comparison'=>'same', 'remote_version'=>$summary['remote_version'], 'message'=>'The remote module is current but BMO loadability failed.'];
                }
            }
            $result = ['ok'=>true, 'status'=>$summary['status'], 'message'=>$summary['message'], 'local_version'=>$required, 'remote_version'=>$summary['remote_version'], 'comparison'=>$summary['comparison'], 'remote'=>$probe, 'required_version'=>$required, 'signing'=>$signing];
            $this->logOperation($siteId, 'remote-version', 'info', $summary['message']);
            return $result;
        }
        if (empty($site['pbx_agent_ready']) && empty($site['pbx_api_ready'])) {
            return ['ok'=>false, 'status'=>'admin_required', 'comparison'=>'unknown', 'local_version'=>$required, 'remote_version'=>null, 'message'=>'Fresh administrator SSH credentials are required to classify a legacy or unenrolled PBX.'];
        }
        try {
            // The web service can verify its own GPG keyring; the restricted SSH agent cannot.
            // Both paths are live reads, never a saved snapshot.
            $remote = null;
            if (!empty($site['pbx_api_ready'])) {
                try { $remote = $this->siteConnection($siteId)->execApi(['method'=>'moduleStatus','args'=>[]]); }
                catch (\Throwable $ignored) {}
            }
            $transport = is_array($remote) ? 'API' : 'SSH';
            if (!is_array($remote)) { $remote = $this->remoteQueryViaSsh($siteId, 'moduleStatus'); }
            if (!is_array($remote) || empty($remote['ok']) || trim((string)($remote['version'] ?? '')) === '') {
                throw new \RuntimeException('Key-based module status was incomplete');
            }
            $version = (string)$remote['version'];
            if (array_key_exists('installed', $remote) && empty($remote['installed'])) {
                return ['ok'=>true, 'status'=>'missing', 'comparison'=>'missing', 'local_version'=>$required, 'remote_version'=>null, 'remote'=>$remote, 'message'=>'Fresh key-RPC reports that the remote module is missing.'];
            }
            if (array_key_exists('enabled', $remote) && empty($remote['enabled'])) {
                return ['ok'=>true, 'status'=>'disabled', 'comparison'=>'remote_older', 'local_version'=>$required, 'remote_version'=>$version, 'remote'=>$remote, 'message'=>'Fresh key-RPC reports that the remote module is disabled.'];
            }
            $cmp = version_compare($version, $required);
            $label = $cmp < 0 ? 'remote_older' : ($cmp > 0 ? 'remote_newer' : 'same');
            $status = $cmp < 0 ? 'older' : ($cmp > 0 ? 'remote_newer' : 'current');
            $signing = $remote['signing'] ?? ['state'=>'unknown', 'label'=>'Signature not checked'];
            if ($transport === 'SSH' && empty($signing['ok'])) {
                $signing = ['state'=>'unavailable','trusted'=>false,'label'=>'Signature not checked through the SSH agent',
                    'details'=>['Use the federation API or supply administrator credentials for a service-account signature check.']];
            }
            return ['ok'=>true, 'status'=>$status, 'comparison'=>$label, 'local_version'=>$required, 'remote_version'=>$version, 'remote'=>$remote, 'signing'=>$signing, 'message'=>"Controller $required; remote $version ($label, live $transport)."];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'status'=>'admin_required', 'comparison'=>'unknown', 'local_version'=>$required, 'remote_version'=>null, 'message'=>'The enrolled agent could not provide a fresh module readiness result; enter PBX administrator credentials to repair or classify this site.'];
        }
    }

    private function assertModulePackageTreeSafe(string $root): void
    {
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot) || is_link($root)) {
            throw new \RuntimeException('The local module source tree is not a safe package directory');
        }
        $prefix = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $count = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $count++;
            if ($count > 10000) {
                throw new \RuntimeException('The local module tree contains too many entries to package safely');
            }
            if ($entry->isLink() || (!$entry->isDir() && !$entry->isFile())) {
                throw new \RuntimeException('The local module tree contains a link or special file and cannot be packaged');
            }
            $realPath = realpath($entry->getPathname());
            $normalized = $realPath === false ? '' : str_replace('\\', '/', $realPath);
            if ($normalized === '' || !str_starts_with($normalized . ($entry->isDir() ? '/' : ''), $prefix)) {
                throw new \RuntimeException('The local module tree contains an entry outside its package root');
            }
            if ($entry->isFile()) {
                $bytes += $entry->getSize();
                if ($bytes > 128 * 1024 * 1024) {
                    throw new \RuntimeException('The local module tree exceeds the 128 MiB package limit');
                }
            }
        }
    }

    public function installRemoteModule(int $siteId, string $adminUser, string $adminPass, bool $useSudo, bool $signAfter = true, string $expectedHostKey = ''): array
    {
        $site = $this->getSite($siteId);
        if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
        if ($adminUser === '' || $adminPass === '') { throw new \InvalidArgumentException('One-time PBX admin username and password are required'); }
        $signing = $this->moduleSigningStatus();
        if (empty($signing['ok']) || empty($signing['trusted'])) {
            throw new \RuntimeException('Refusing to distribute a controller module tree that is not locally signed and verified');
        }
        $this->assertModulePackageTreeSafe(__DIR__);
        $status = $this->moduleStatus();
        $version = preg_replace('/[^0-9A-Za-z._-]/', '', (string)$status['version']);
        [$ssh, $hostKey] = $this->remoteAdminSsh($site, $adminUser, $adminPass, $expectedHostKey);
        $probe = $this->probeRemoteModuleAdmin($siteId, $adminUser, $adminPass, $useSudo, $expectedHostKey);
        if (empty($probe['ok'])) {
            throw new \RuntimeException('Remote module probe is unknown; refusing to dispatch an install until the PBX administrator probe succeeds.');
        }
        $probeSummary = $this->summarizeRemoteModuleProbe($probe, $version);
        if (($probeSummary['comparison'] ?? '') === 'unknown') {
            throw new \RuntimeException('Remote module/platform probe is unknown; refusing to dispatch an install.');
        }
        if (($probeSummary['comparison'] ?? '') === 'remote_newer') {
            throw new \RuntimeException('Remote site has a newer module in its filesystem or registry; refusing to downgrade it to ' . $version . '. Update this controller first.');
        }

        $token = bin2hex(random_bytes(16));
        $localArchive = tempnam(sys_get_temp_dir(), 'ucmp-package-');
        if ($localArchive === false) { throw new \RuntimeException('Could not allocate a local module package file'); }
        $remoteArchive = "/tmp/ucmprovisioner-$version-$token.tgz";
        $tar = '/bin/tar -czf ' . escapeshellarg($localArchive) . ' -C ' . escapeshellarg(dirname(__DIR__)) . ' ' . escapeshellarg(basename(__DIR__));
        @exec($tar, $tarOut, $tarCode);
        if ($tarCode !== 0 || !is_file($localArchive) || is_link($localArchive)) {
            @unlink($localArchive);
            throw new \RuntimeException('Could not package the current module on this PBX');
        }
        $archiveHash = hash_file('sha256', $localArchive);
        if (!is_string($archiveHash) || !preg_match('/^[a-f0-9]{64}$/D', $archiveHash)) {
            @unlink($localArchive);
            throw new \RuntimeException('Could not hash the local module package');
        }
        $installDispatched = false;
        $signingResult = ['state'=>'unsigned', 'trusted'=>false, 'label'=>'Not signed (checkbox was cleared)'];
        try {
            $sftp = new \phpseclib3\Net\SFTP((string)($site['pbx_host'] ?: $site['pbx_addr']), (int)($site['pbx_ssh_port'] ?: 22));
            $sftpFp = $this->fingerprintHostKey((string)$sftp->getServerPublicHostKey());
            if (!hash_equals($hostKey, $sftpFp)) { throw new \RuntimeException('PBX host key changed between SSH and SFTP connections'); }
            if (!$sftp->login($adminUser, $adminPass)) { throw new \RuntimeException('SFTP login failed while uploading the module package'); }
            if (!$sftp->put($remoteArchive, $localArchive, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) { throw new \RuntimeException('Remote module package upload failed'); }

            $stage = "/tmp/ucmp-install-$token";
            // Two fixed root-owned slots bound disk use while preserving the
            // current and immediately previous operator-recovery copies.
            $backup = '/root/ucmprovisioner-pre-update';
            $previousBackup = '/root/ucmprovisioner-pre-update.previous';
            $failed = '/root/ucmprovisioner-failed-update';
            $script = "set -eu\n" .
                "test -x /usr/sbin/fwconsole\n" .
                "test -x /usr/bin/flock\n" .
                "exec 9>/run/lock/ucmprovisioner-update.lock\n" .
                "/usr/bin/flock -n 9 || { echo 'Another ucmprovisioner update is already running' >&2; exit 75; }\n" .
                "cleanup_stage() { rm -rf -- " . escapeshellarg($stage) . "; rm -f -- " . escapeshellarg($remoteArchive) . "; }\n" .
                "trap cleanup_stage EXIT\n" .
                "printf '%s  %s\n' " . escapeshellarg($archiveHash) . ' ' . escapeshellarg($remoteArchive) . " | sha256sum -c -\n" .
                "test ! -e " . escapeshellarg($stage) . "\n" .
                "mkdir -m 0700 " . escapeshellarg($stage) . "\n" .
                "tar --no-same-owner --no-same-permissions -xzf " . escapeshellarg($remoteArchive) . " -C " . escapeshellarg($stage) . "\n" .
                "test \"$(sed -n 's:.*<rawname>\\([^<]*\\)</rawname>.*:\\1:p' " . escapeshellarg($stage . '/ucmprovisioner/module.xml') . " | head -n1)\" = ucmprovisioner\n" .
                "php -l " . escapeshellarg($stage . '/ucmprovisioner/Ucmprovisioner.class.php') . "\n" .
                "rm -rf " . escapeshellarg($previousBackup) . ' ' . escapeshellarg($failed) . "\n" .
                "if test -e " . escapeshellarg($backup) . "; then mv " . escapeshellarg($backup) . ' ' . escapeshellarg($previousBackup) . "; fi\n" .
                "if test -d /var/www/html/admin/modules/ucmprovisioner; then mv /var/www/html/admin/modules/ucmprovisioner " . escapeshellarg($backup) . "; fi\n" .
                "mv " . escapeshellarg($stage . '/ucmprovisioner') . " /var/www/html/admin/modules/ucmprovisioner\n" .
                // A controller-local signature is never portable. Leaving it in place on another
                // PBX produces the more alarming "invalid key" state, so the target starts clean.
                "rm -f /var/www/html/admin/modules/ucmprovisioner/module.sig\n" .
                "chown -R asterisk:asterisk /var/www/html/admin/modules/ucmprovisioner\n" .
                // Keep the newly installed tree and the previous tree separately when the
                // installer fails.  Reinstalling the old tree here is unsafe: install.php may
                // have already migrated the credential key out of the legacy database row.
                "if ! /usr/sbin/fwconsole ma --autoenable --no-interaction install ucmprovisioner; then echo 'ucmprovisioner install failed; candidate remains in the module directory and previous code remains in /root/ucmprovisioner-pre-update; preserve matching private recovery archives' >&2; exit 1; fi\n" .
                "test -x /usr/local/sbin/ucmp-fail2ban && test -r /usr/local/libexec/ucmp-fail2ban-helper.php && test -r /etc/sudoers.d/ucmp-fail2ban\n" .
                "/usr/sbin/visudo -cf /etc/sudoers.d/ucmp-fail2ban >/dev/null\n" .
                "printf '%s' '{\"op\":\"health\"}' | /usr/sbin/runuser -u asterisk -- /usr/bin/sudo -n /usr/local/sbin/ucmp-fail2ban >/dev/null\n" .
                "/usr/sbin/fwconsole chown\n" .
                ($signAfter
                    ? "if ! php /var/www/html/admin/modules/ucmprovisioner/tools/sign-local-module.php; then echo 'ucmprovisioner signing failed; candidate remains installed and previous code remains in /root/ucmprovisioner-pre-update; preserve matching private recovery archives' >&2; exit 1; fi\n"
                    : '');
            $installDispatched = true;
            $output = $this->execPrivilegedScript($ssh, $script, $adminUser, $adminPass, $useSudo);
            $exit = $ssh->getExitStatus();
            if (is_int($exit) && $exit !== 0) { throw new \RuntimeException("Remote install exited with status $exit: " . substr($output, -2000)); }
            if (strpos($output, "version $version successfully installed") === false) { throw new \RuntimeException('Remote fwconsole did not confirm installation. Output: ' . substr($output, -2000)); }
            if ($signAfter) {
                $signingResult = $this->decodeRemoteProbe(substr($output, -32768));
                if (!is_array($signingResult) || empty($signingResult['ok']) || empty($signingResult['trusted'])) {
                    throw new \RuntimeException('Remote signing did not return a verified local signature');
                }
            }
        } catch (\Throwable $e) {
            // A lost response can hide any installer phase. Do not guess that the old code
            // remains active, replay the install, or restore code without its matching secrets.
            if (!$installDispatched) { throw $e; }
            $this->db->prepare('UPDATE ucmp_sites SET pbx_agent_ready=0 WHERE id=?')->execute([$siteId]);
            $recoveryMessage = "Remote ucmprovisioner $version installation was not confirmed. Inspect the current module, /root/ucmprovisioner-pre-update and private recovery archives before retrying; no automatic rollback or replay was attempted.";
            $this->logOperation($siteId, 'remote-install', 'error', $recoveryMessage);
            return [
                'ok'=>false,
                'status'=>'recovery_required',
                'phase'=>'remote-install',
                'message'=>$recoveryMessage,
                'version'=>$version,
                'signed'=>false,
            ];
        } finally {
            @unlink($localArchive);
        }
        $this->db->prepare('UPDATE ucmp_sites SET pbx_host_key = ? WHERE id = ?')->execute([$hostKey, $siteId]);
        $signed = $signAfter ? ' and locally signed' : '';
        $agent = null;
        $remoteStatus = null;
        $credentialReadiness = null;
        $phase = 'enrollment';
        try {
            // The administrator credentials are request-scoped. Reuse them immediately for
            // enrollment so a successful code install cannot leave a separately required
            // manual step (and so the same confirmed host key remains in force).
            $agent = $this->bootstrapPbxAgent(
                $siteId,
                (string)($site['pbx_host'] ?: $site['pbx_addr']),
                (int)($site['pbx_ssh_port'] ?: 22),
                $adminUser,
                $adminPass,
                $useSudo,
                $hostKey
            );
            if (empty($agent['ok'])) { throw new \RuntimeException('PBX agent enrollment did not complete'); }

            $phase = 'module-readiness';
            // This is deliberately a fresh key-based SSH RPC probe, not an admin-side file
            // check or a snapshot-capable remoteQuery(): moduleStatus is also a cached read in
            // normal UI flows, while this post-install check must observe the newly swapped tree.
            $remoteStatus = $this->remoteQueryViaSsh($siteId, 'moduleStatus');
            if (!is_array($remoteStatus) || empty($remoteStatus['ok'])
                || !hash_equals($version, (string)($remoteStatus['version'] ?? ''))
                || (array_key_exists('installed', (array)$remoteStatus) && empty($remoteStatus['installed']))
                || (array_key_exists('enabled', (array)$remoteStatus) && empty($remoteStatus['enabled']))) {
                throw new \RuntimeException('Key-based module identity/version probe failed');
            }
            $postProbe = $this->probeRemoteModuleAdmin($siteId, $adminUser, $adminPass, $useSudo, $hostKey);
            $postSummary = !empty($postProbe['ok']) ? $this->summarizeRemoteModuleProbe($postProbe, $version) : ['status'=>'unknown', 'comparison'=>'unknown'];
            if (($postSummary['status'] ?? '') !== 'current') {
                throw new \RuntimeException('Fresh administrator module registry/BMO readiness probe failed after installation');
            }

            $phase = 'credential-readiness';
            $credentialProbe = $this->probeRemoteCredentialReadiness($siteId, $adminUser, $adminPass, $useSudo, $hostKey);
            if (empty($credentialProbe['ok'])) { throw new \RuntimeException($credentialProbe['message']); }
            $credentialReadiness = $credentialProbe['result'];
        } catch (\Throwable $e) {
            // Do not leave a stale ready marker after any post-install enrollment/readiness
            // failure. The new module and the retained previous/failed trees remain available
            // for an operator-led recovery; no old module is reinstalled automatically.
            if (!is_array($agent) || empty($agent['ok'])) {
                $this->db->prepare('UPDATE ucmp_sites SET pbx_agent_ready=0 WHERE id=?')->execute([$siteId]);
            }
            $detail = preg_replace('/\s+/', ' ', trim((string)$e->getMessage())) ?: 'readiness verification failed';
            $detail = substr($detail, 0, 500);
            $message = "Installed ucmprovisioner $version$signed, but readiness failed during $phase. Review the reported failure and retry Install/update; automatic enrollment is part of that action.";
            $this->logOperation($siteId, 'remote-install', 'error', $message, $detail);
            return [
                'ok'=>false,
                'status'=>'installed_but_not_ready',
                'phase'=>$phase,
                'message'=>$message,
                'version'=>$version,
                'signed'=>$signAfter,
                'signing'=>$signingResult,
                'agent'=>$agent,
                'detail'=>$detail,
            ];
        }
        $this->logOperation($siteId, 'remote-install', 'success', "Installed ucmprovisioner $version$signed and enrolled ucmp-pbx", $output ?? '');
        return [
            'ok'=>true,
            'status'=>'ready',
            'message'=>"Installed ucmprovisioner $version$signed on {$site['name']} and enrolled/verified ucmp-pbx.",
            'version'=>$version,
            'signed'=>$signAfter,
            'signing'=>$signingResult,
            'agent'=>$agent,
            'module_status'=>['ok'=>true, 'version'=>(string)($remoteStatus['version'] ?? '')],
            'credential_readiness'=>$credentialReadiness,
            'detail'=>$output ?? '',
        ];
    }

    /** Validate and resolve the optional federation API endpoint for onboarding. */
    public function validateSiteApiEndpoint(int $siteId, array $input = []): array
    {
        $site = $this->getSite($siteId);
        if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
        $scheme = strtolower(trim((string)($input['api_scheme'] ?? '')));
        if ($scheme === '') { $scheme = strtolower(trim((string)($site['pbx_api_scheme'] ?? 'http'))) ?: 'http'; }
        if (!in_array($scheme, ['http','https'], true)) { throw new \InvalidArgumentException('API scheme must be HTTP or HTTPS'); }
        $host = trim((string)($input['api_host'] ?? ''));
        if ($host === '') { $host = trim((string)($site['pbx_api_host'] ?? '')); }
        if ($host === '') { $host = trim((string)($site['pbx_host'] ?? '')); }
        if ($host === '' || preg_match('/\s|:\/\/|[\/?#@,]/', $host)) {
            throw new \InvalidArgumentException('API host must be a bare DNS name or IP address, without a scheme, path or CIDR');
        }
        if ($host[0] === '[' || $host[strlen($host) - 1] === ']') {
            throw new \InvalidArgumentException('API IPv6 host must not include brackets');
        }
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDns = (bool)preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host);
        if (!$isIp && !$isDns) { throw new \InvalidArgumentException('API host must be a valid DNS name or IP address'); }
        $rawPort = $input['api_port'] ?? '';
        $port = trim((string)$rawPort) === '' ? (int)($site['pbx_api_port'] ?? 0) : (int)$rawPort;
        if ($port === 0 && trim((string)$rawPort) === '') { $port = $scheme === 'https' ? 443 : 80; }
        if ($port < 1 || $port > 65535 || (trim((string)$rawPort) !== '' && !ctype_digit(trim((string)$rawPort)))) {
            throw new \InvalidArgumentException('API port must be an integer from 1 to 65535');
        }
        $verify = array_key_exists('api_verify_tls', $input) && $input['api_verify_tls'] !== null
            ? (bool)$input['api_verify_tls']
            : !empty($site['pbx_api_verify_tls']);
        return ['scheme'=>$scheme, 'host'=>$host, 'port'=>$port, 'verify_tls'=>$verify];
    }

    /** Fresh key-RPC probe; this intentionally bypasses snapshots and API fallback. */
    public function probeRemoteAgentFresh(int $siteId): array
    {
        try {
            $this->resetSiteConnection($siteId);
            $local = $this->moduleStatus();
            $module = $this->remoteQueryViaSsh($siteId, 'moduleStatus');
            if (!is_array($module) || empty($module['ok']) || trim((string)($module['version'] ?? '')) === '') {
                throw new \RuntimeException('Agent moduleStatus response was incomplete');
            }
            if (trim((string)($module['version'] ?? '')) !== trim((string)($local['version'] ?? ''))) {
                throw new \RuntimeException('Agent module version does not match the controller');
            }
            $trunks = $this->remoteQueryViaSsh($siteId, 'listPjsipTrunks', [0]);
            if (!is_array($trunks) || (array_key_exists('ok', $trunks) && empty($trunks['ok']))) {
                throw new \RuntimeException('Agent management RPC response was incomplete');
            }
            $site = $this->getSite($siteId) ?: [];
            return ['ok'=>true, 'ready'=>true, 'module'=>$module, 'trunks'=>$trunks['trunks'] ?? [], 'host_key'=>(string)($site['pbx_host_key'] ?? ''), 'message'=>'Fresh key-based agent and management RPC probes passed.'];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'ready'=>false, 'status'=>'unknown', 'message'=>'Fresh key-based agent probe failed; agent state is unknown.'];
        }
    }

    /** Verify the service-account credential store after an install or repair. */
    public function probeRemoteCredentialReadiness(int $siteId, string $adminUser, string $adminPass, bool $useSudo = false, string $expectedHostKey = ''): array
    {
        try {
            $site = $this->getSite($siteId);
            if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
            [$ssh, $hostKey] = $this->remoteAdminSsh($site, $adminUser, $adminPass, $expectedHostKey);
            $php = "require '/etc/freepbx.conf'; echo json_encode(FreePBX::Ucmprovisioner()->credentialStorageReadiness(), JSON_UNESCAPED_SLASHES); exit(0);";
            $command = '/usr/sbin/runuser -u asterisk -- /usr/bin/php -r ' . escapeshellarg($php);
            $output = $this->execPrivilegedScript($ssh, $command, $adminUser, $adminPass, $useSudo);
            $exit = $ssh->getExitStatus();
            if ($exit !== 0) { throw new \RuntimeException('Credential storage readiness command exited with status ' . (is_int($exit) ? $exit : 'unknown')); }
            $result = $this->decodeRemoteProbe((string)$output);
            if (!is_array($result) || empty($result['ok']) || (string)($result['key_status'] ?? '') !== 'private_file' || empty($result['directory_sources_ready'])) {
                throw new \RuntimeException('Credential storage readiness response was incomplete');
            }
            return ['ok'=>true, 'ready'=>true, 'host_key'=>$hostKey, 'result'=>$result, 'message'=>'Service-account credential storage readiness passed.'];
        } catch (\Throwable $e) {
            $detail = substr(preg_replace('/\s+/', ' ', str_replace($adminPass, '[redacted]', $e->getMessage())), 0, 500);
            return ['ok'=>false, 'ready'=>false, 'status'=>'unknown', 'message'=>'Credential storage readiness failed: ' . $detail];
        }
    }

    /** Probe an existing API key without rotating it. */
    public function probeRemoteApi(int $siteId): array
    {
        try {
            $connection = $this->siteConnection($siteId);
            $module = $connection->execApi(['method'=>'moduleStatus','args'=>[]]);
            if (!is_array($module) || empty($module['ok'])) { throw new \RuntimeException('API moduleStatus probe failed'); }
            $extensions = $connection->execApi(['method'=>'getAllSipExtensionsLocal','args'=>[]]);
            if (!is_array($extensions) || !array_is_list($extensions)) { throw new \RuntimeException('API management RPC returned an invalid extension catalog'); }
            return ['ok'=>true, 'ready'=>true, 'module'=>$module, 'extensions'=>count($extensions), 'message'=>'Existing federation API key and management RPC verified.'];
        } catch (\Throwable $e) {
            return ['ok'=>false, 'ready'=>false, 'status'=>'unknown', 'message'=>'Existing federation API probe failed; the API key may need re-enrollment.'];
        }
    }

    /**
     * Prepare or repair one saved remote site. This is the single UI lifecycle
     * boundary; raw installRemoteModule remains the staged recovery-safe action.
     */
    public function prepareRemoteSite(int $siteId, array $input = [], bool $forceInstall = false): array
    {
        $site = $this->getSite($siteId, true);
        if (!$site || !empty($site['is_local'])) { throw new \InvalidArgumentException('Choose a saved remote site'); }
        $adminUser = trim((string)($input['admin_user'] ?? ''));
        $adminPass = (string)($input['admin_pass'] ?? '');
        if ($adminUser === '' || $adminPass === '') { throw new \InvalidArgumentException('Request-scoped PBX administrator username and password are required'); }
        $useSudo = !empty($input['use_sudo']);
        $expectedHostKey = trim((string)($input['expected_host_key'] ?? ''));
        $endpoint = $this->validateSiteApiEndpoint($siteId, $input);
        $moduleStatus = $this->remoteModuleStatus($siteId, $adminUser, $adminPass, $expectedHostKey, $useSudo);
        $result = [
            'ok'=>false, 'ready'=>false, 'pbx_api_ready'=>false,
            'status'=>'unknown', 'phase'=>'probe', 'message'=>(string)($moduleStatus['message'] ?? 'Remote module state is unknown.'),
            'detail'=>(string)($moduleStatus['detail'] ?? ''),
            'required_version'=>(string)($moduleStatus['local_version'] ?? ''),
            'local_version'=>(string)($moduleStatus['local_version'] ?? ''),
            'remote_version'=>$moduleStatus['remote_version'] ?? null,
            'comparison'=>(string)($moduleStatus['comparison'] ?? 'unknown'),
            'components'=>['module'=>['ready'=>false, 'status'=>'unknown'], 'agent'=>['ready'=>false, 'status'=>'unknown'], 'api'=>['ready'=>false, 'status'=>'unknown']],
            'agent'=>null, 'trunks'=>[], 'host_key'=>(string)($moduleStatus['remote']['host_key'] ?? ($site['pbx_host_key'] ?? '')), 'signing'=>$moduleStatus['signing'] ?? null, 'result'=>null,
        ];
        if (empty($moduleStatus['ok']) || ($moduleStatus['comparison'] ?? 'unknown') === 'unknown' || ($moduleStatus['status'] ?? '') === 'admin_required') {
            $result['status'] = 'unknown';
            $result['components']['module'] = ['ready'=>false, 'status'=>'unknown', 'probe'=>$moduleStatus];
            return $result;
        }
        if (($moduleStatus['comparison'] ?? '') === 'remote_newer') {
            $result['status'] = 'remote_newer';
            $result['message'] = 'Remote module filesystem or registry is newer; no write was dispatched.';
            $result['components']['module'] = ['ready'=>false, 'status'=>'remote_newer', 'version'=>$moduleStatus['remote_version'] ?? null];
            return $result;
        }

        $installStatuses = ['missing','older','remote_older','missingregistry','registry_mismatch','disabled','broken-current'];
        $needsInstall = $forceInstall || in_array((string)($moduleStatus['status'] ?? ''), $installStatuses, true);
        $moduleResult = null;
        if ($needsInstall) {
            $result['phase'] = 'module-install';
            try {
                $moduleResult = $this->installRemoteModule($siteId, $adminUser, $adminPass, $useSudo, array_key_exists('sign_after', $input) ? !empty($input['sign_after']) : true, $expectedHostKey);
            } catch (\Throwable $e) {
                $result['status'] = 'recovery_required';
                $result['message'] = substr('Remote module installation was not completed: ' . $e->getMessage(), 0, 600);
                $result['detail'] = $this->operationFailureResult($e, 'PBX module installation', [$adminPass])['detail'];
                $result['components']['module'] = ['ready'=>false, 'status'=>'recovery_required'];
                return $result;
            }
            if (!is_array($moduleResult) || empty($moduleResult['ok'])) {
                $result['status'] = (string)($moduleResult['status'] ?? 'recovery_required');
                $result['phase'] = (string)($moduleResult['phase'] ?? 'module-install');
                $result['message'] = (string)($moduleResult['message'] ?? 'Remote module installation did not complete.');
                $result['remote_version'] = $moduleResult['version'] ?? $result['remote_version'];
                $result['signing'] = $moduleResult['signing'] ?? $result['signing'];
                $result['result'] = $moduleResult['detail'] ?? null;
                $result['detail'] = $moduleResult['detail'] ?? '';
                $installed = $result['status'] === 'installed_but_not_ready';
                $result['components']['module'] = ['ready'=>$installed && $result['phase'] === 'credential-readiness', 'status'=>$installed ? 'installed' : $result['status'], 'version'=>$result['remote_version']];
                $result['agent'] = $moduleResult['agent'] ?? null;
                $result['components']['agent'] = ['ready'=>!empty($moduleResult['agent']['ok']), 'status'=>!empty($moduleResult['agent']['ok']) ? 'ready' : 'unknown'];
                return $result;
            }
            $result['signing'] = $moduleResult['signing'] ?? null;
            $result['remote_version'] = $moduleResult['version'] ?? $result['local_version'];
            $result['comparison'] = 'same';
            $result['result'] = $moduleResult['detail'] ?? null;
            $result['detail'] = $moduleResult['detail'] ?? '';
            $result['components']['module'] = ['ready'=>true, 'status'=>'ready', 'version'=>$moduleResult['version'] ?? ($moduleStatus['local_version'] ?? null)];
            $result['agent'] = $moduleResult['agent'] ?? null;
            $result['trunks'] = (array)($result['agent']['trunks'] ?? []);
            $result['host_key'] = (string)($result['agent']['host_key'] ?? ($site['pbx_host_key'] ?? $result['host_key']));
            $result['components']['agent'] = ['ready'=>!empty($result['agent']['ok']), 'status'=>!empty($result['agent']['ok']) ? 'ready' : 'unknown'];
        } else {
            $result['components']['module'] = ['ready'=>true, 'status'=>'ready', 'version'=>$moduleStatus['remote_version'] ?? null];
            $agent = !empty($site['pbx_agent_ready']) ? $this->probeRemoteAgentFresh($siteId) : ['ok'=>false, 'status'=>'unknown', 'message'=>'PBX agent is not enrolled'];
            if (empty($agent['ok'])) {
                $result['phase'] = 'agent';
                try {
                    $agent = $this->bootstrapPbxAgent($siteId, trim((string)($site['pbx_host'] ?? $site['pbx_addr'] ?? '')), (int)($site['pbx_ssh_port'] ?: 22), $adminUser, $adminPass, $useSudo, $expectedHostKey);
                } catch (\Throwable $e) {
                    $result['status'] = 'agent_not_ready';
                    $result['message'] = substr('The module is ready, but PBX agent repair failed: ' . $e->getMessage(), 0, 600);
                    $result['detail'] = $this->operationFailureResult($e, 'PBX agent repair', [$adminPass])['detail'];
                    $result['components']['agent'] = ['ready'=>false, 'status'=>'unknown'];
                    return $result;
                }
            }
            $result['agent'] = $agent;
            $result['trunks'] = (array)($agent['trunks'] ?? []);
            $result['host_key'] = (string)($agent['host_key'] ?? ($site['pbx_host_key'] ?? $result['host_key']));
            $result['components']['agent'] = ['ready'=>!empty($agent['ok']), 'status'=>!empty($agent['ok']) ? 'ready' : 'unknown'];
        }
        if (empty($result['components']['agent']['ready'])) {
            $result['status'] = 'agent_not_ready';
            $result['phase'] = 'agent';
            $result['message'] = 'The module is ready, but the PBX agent is not ready.';
            return $result;
        }

        // A current module still honors the operator's signing choice without a reinstall.
        if (!$needsInstall && (!array_key_exists('sign_after', $input) || !empty($input['sign_after']))) {
            $result['phase'] = 'signing';
            try { $result['signing'] = $this->signSiteModule($siteId, $adminUser, $adminPass, $useSudo, $expectedHostKey); }
            catch (\Throwable $e) {
                $result['status'] = 'partial';
                $result['message'] = substr('PBX module and agent are ready, but local signing failed: ' . $e->getMessage(), 0, 600);
                $result['detail'] = $this->operationFailureResult($e, 'PBX local signing', [$adminPass])['detail'];
                return $result;
            }
        }

        $site = $this->getSite($siteId, true) ?: $site;
        $credential = $moduleResult['credential_readiness'] ?? $this->probeRemoteCredentialReadiness($siteId, $adminUser, $adminPass, $useSudo, $expectedHostKey);
        if (empty($credential['ok'])) {
            $result['status'] = 'partial';
            $result['phase'] = 'credential-readiness';
            $result['message'] = 'PBX agent is ready, but credential storage is not ready: ' . substr((string)($credential['message'] ?? 'readiness could not be verified'), 0, 600);
            $result['detail'] = (string)($credential['detail'] ?? $credential['message'] ?? '');
            $result['components']['credential'] = ['ready'=>false, 'status'=>'unknown', 'message'=>$result['message']];
            return $result;
        }
        $result['components']['credential'] = ['ready'=>true, 'status'=>'ready'];
        if (array_key_exists('api_enabled', $input) && empty($input['api_enabled'])) {
            $result['ok'] = true; $result['status'] = 'agent_ready'; $result['phase'] = 'agent';
            $result['message'] = 'PBX module, credentials and SSH agent are ready; federation API enrollment was disabled for this request.';
            return $result;
        }
        $savedSecret = trim((string)($site['pbx_api_secret'] ?? ''));
        $hasConfiguredApi = !empty($site['pbx_api_enabled']) && $savedSecret !== '' && trim((string)($site['pbx_api_host'] ?? '')) !== '';
        $explicitEndpoint = trim((string)($input['api_scheme'] ?? '')) !== '' || trim((string)($input['api_host'] ?? '')) !== '' || trim((string)($input['api_port'] ?? '')) !== '' || array_key_exists('api_verify_tls', $input);
        $endpointChanged = $hasConfiguredApi && $explicitEndpoint && (strtolower((string)$site['pbx_api_scheme']) !== $endpoint['scheme'] || trim((string)$site['pbx_api_host']) !== $endpoint['host'] || (int)$site['pbx_api_port'] !== $endpoint['port'] || (bool)$site['pbx_api_verify_tls'] !== (bool)$endpoint['verify_tls']);
        $api = $hasConfiguredApi && !$endpointChanged ? $this->probeRemoteApi($siteId) : ['ok'=>false, 'status'=>'not_configured'];
        if (!empty($api['ok'])) {
            $this->db->prepare('UPDATE ucmp_sites SET pbx_api_enabled=1,pbx_api_ready=1,pbx_api_last_ok=? WHERE id=?')->execute([time(),$siteId]);
        }
        if (empty($api['ok'])) {
            $result['phase'] = 'api';
            // A failed fresh probe must not leave an old green API marker if the
            // following SSH configuration fails before configureSiteApi saves it.
            $this->db->prepare('UPDATE ucmp_sites SET pbx_api_ready=0,pbx_api_last_ok=0 WHERE id=?')->execute([$siteId]);
            $this->resetSiteConnection($siteId);
            try {
                $api = $this->configureSiteApi($siteId, true, $endpoint['scheme'], $endpoint['host'], $endpoint['port'], $endpoint['verify_tls']);
            } catch (\Throwable $e) {
                $api = ['status'=>'unknown'] + $this->operationFailureResult($e, 'Federation API enrollment', [$adminPass]);
            }
        }
        $result['components']['api'] = ['ready'=>!empty($api['ok']), 'status'=>!empty($api['ok']) ? 'ready' : 'unknown', 'result'=>$api];
        $result['pbx_api_ready'] = !empty($api['ok']);
        if (empty($api['ok'])) {
            $result['status'] = 'partial'; $result['phase'] = 'api'; $result['message'] = (string)($api['message'] ?? 'Agent is ready, but federation API readiness is unknown.');
            $result['detail'] = (string)($api['detail'] ?? $api['message'] ?? '');
            $result['ok'] = false;
            return $result;
        }
        $result['ok'] = true; $result['ready'] = true; $result['status'] = 'ready'; $result['phase'] = 'complete';
        $result['message'] = 'PBX module, agent, credential store and federation management API are ready.';
        return $result;
    }

    /**
     * Enroll or refresh the target PBX's own root-controlled local signer.
     * Credentials are supplied for this request only and are never persisted.
     */
    public function signSiteModule(int $siteId, string $adminUser, string $adminPass, bool $useSudo, string $expectedHostKey = ''): array
    {
        $site = $this->getSite($siteId);
        if (!$site) { throw new \InvalidArgumentException('Choose a saved site'); }
        if ($adminUser === '' || $adminPass === '') { throw new \InvalidArgumentException('One-time PBX admin username and password are required'); }
        [$ssh, $hostKey] = $this->remoteAdminSsh($site, $adminUser, $adminPass, $expectedHostKey);
        $script = '/var/www/html/admin/modules/ucmprovisioner/tools/sign-local-module.php';
        $command = 'test -f ' . escapeshellarg($script) . ' && php ' . escapeshellarg($script);
        $output = trim($this->execPrivilegedScript($ssh, $command, $adminUser, $adminPass, $useSudo));
        $exit = $ssh->getExitStatus();
        if (is_int($exit) && $exit !== 0) {
            throw new \RuntimeException('Module signing failed with status ' . $exit . ': ' . substr($output, -1800));
        }
        $result = null;
        foreach (array_reverse(preg_split('/\R/', $output) ?: []) as $line) {
            $candidate = json_decode(trim((string)$line), true);
            if (is_array($candidate)) { $result = $candidate; break; }
        }
        if (!is_array($result) || empty($result['ok']) || empty($result['trusted'])) {
            throw new \RuntimeException('The PBX signer did not return a verified local signature: ' . substr($output, -1800));
        }
        $this->db->prepare('UPDATE ucmp_sites SET pbx_host_key = ? WHERE id = ?')->execute([$hostKey, $siteId]);
        $fingerprint = (string)($result['fingerprint'] ?? 'unknown');
        $this->logOperation($siteId, 'module-sign', 'success', 'Module locally signed and verified', $fingerprint);
        $result['message'] = 'Module locally signed and verified on ' . (string)($site['name'] ?? ('site #' . $siteId)) . '.';
        return $result;
    }

    /**
     * Credential-free SSH key probe used by the UI's explicit first-contact
     * confirmation step. Host and port come from the validated request draft or saved row.
     */
    public function probeSiteHostKey(int $siteId, string $channel = 'pbx'): array
    {
        $site = $this->getSite($siteId);
        if (!$site) { throw new \InvalidArgumentException('Choose a saved site'); }
        if (!in_array($channel, ['pbx','docker'], true)) {
            throw new \InvalidArgumentException('Choose PBX or Docker SSH');
        }
        if ($channel === 'docker') {
            $host = trim((string)($site['docker_host'] ?? ''));
            $port = (int)($site['docker_ssh_port'] ?? 22);
            $saved = trim((string)($site['docker_host_key'] ?? ''));
        } else {
            $host = trim((string)($site['pbx_host'] ?: $site['pbx_addr']));
            $port = (int)($site['pbx_ssh_port'] ?? 22);
            $saved = trim((string)($site['pbx_host_key'] ?? ''));
        }
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Enter a valid SSH host and port before probing its fingerprint');
        }
        $probe = new \phpseclib3\Net\SSH2($host, $port);
        $fingerprint = $this->fingerprintHostKey((string)$probe->getServerPublicHostKey());
        if ($saved !== '' && !hash_equals($saved, $fingerprint)) {
            throw new \RuntimeException(
                strtoupper($channel) . " SSH host key mismatch for $host:$port (saved $saved; observed $fingerprint)"
            );
        }
        return [
            'ok'=>true,
            'channel'=>$channel,
            'host'=>$host,
            'port'=>$port,
            'fingerprint'=>$fingerprint,
            'pinned'=>$saved !== '',
            'message'=>$saved !== ''
                ? strtoupper($channel) . ' SSH fingerprint matches the saved pin.'
                : 'First contact: verify this fingerprint independently before confirming.',
        ];
    }

    private function remoteAdminSsh(array $site, string $user, string $password, string $expectedHostKey = ''): array
    {
        $host = (string)($site['pbx_host'] ?: $site['pbx_addr']);
        $port = (int)($site['pbx_ssh_port'] ?: 22);
        $ssh = new \phpseclib3\Net\SSH2($host, $port);
        try {
            $blob = $ssh->getServerPublicHostKey();
            $fp = $this->fingerprintHostKey((string)$blob);
        }
        catch (\Throwable $e) {
            throw new \RuntimeException("PBX SSH handshake failed for $host:$port\nStage: SSH connection and host-key exchange. No administrator credentials were sent.\n" . $this->safeOperationText($e->getMessage(), [$password]), 0, $e);
        }
        $trusted = trim((string)($site['pbx_host_key'] ?? ''));
        if ($trusted === '') { $trusted = trim($expectedHostKey); }
        if (!preg_match('/^SHA256:[A-Za-z0-9+\\/_-]+$/D', $trusted)) {
            throw new \RuntimeException("Verify and confirm the PBX SSH fingerprint before sending administrator credentials (observed $fp)");
        }
        if (!hash_equals($trusted, $fp)) {
            throw new \RuntimeException("PBX host key mismatch for $host:$port (got $fp)");
        }
        $this->loginEnrollmentAdministrator($ssh, 'PBX', $host, $port, $user, $password);
        return [$ssh, $fp];
    }

    /** Both enrollment providers report authentication separately from later sudo/SFTP failures. */
    private function loginEnrollmentAdministrator(\phpseclib3\Net\SSH2 $ssh, string $provider, string $host, int $port, string $user, string $password): void
    {
        $cause = null;
        try {
            if ($ssh->login($user, $password)) { return; }
        } catch (\Throwable $e) { $cause = $e; }
        $serverDetail = $cause ? $cause->getMessage() : (string)$ssh->getLastError();
        $detail = "$provider SSH administrator authentication failed for $user@$host:$port\n"
            . "Stage: SSH administrator authentication.\n"
            . "The SSH host fingerprint matched. No command was sent on this connection.\n"
            . ($serverDetail !== '' ? 'SSH diagnostic: ' . $serverDetail . "\n" : "The server returned no additional authentication diagnostic.\n")
            . "SSH rejection alone does not distinguish a missing account, an incorrect password or disabled password login.\n"
            . ($provider === 'Docker'
                ? "Use an administrator of this Docker host, not the PBX account or the module's ucmp-tftp runtime account. Enable Use sudo for a non-root administrator."
                : "Use an administrator of this PBX, not the module's restricted ucmp-pbx or ucmp-tftp runtime account.");
        throw new \RuntimeException($this->safeOperationText($detail, [$password]), 0, $cause);
    }

    /**
     * Execute a fixed bootstrap/install script without putting the temporary
     * sudo password in argv or a remote shell command line. phpseclib opens the
     * exec channel first, then the password is written only to sudo's stdin.
     */
    private function execPrivilegedScript(\phpseclib3\Net\SSH2 $ssh, string $script, string $adminUser, string $adminPass, bool $useSudo): string
    {
        // Signing intentionally stays silent while GnuPG and FreePBX refresh
        // their caches; the default SSH timeout is too short for that phase.
        $ssh->setTimeout(300);
        $command = 'bash -c ' . escapeshellarg($script);
        if (!$useSudo || $adminUser === 'root') {
            $result = $ssh->exec($command);
            if ($result === false) { throw new \RuntimeException('Remote privileged command could not be started'); }
            return (string)$result;
        }

        $command = "sudo -S -p '' -- bash -c " . escapeshellarg($script);
        if ($ssh->exec($command, false) !== true) {
            throw new \RuntimeException('Remote sudo command could not be started');
        }
        $ssh->write($adminPass . "\n", \phpseclib3\Net\SSH2::CHANNEL_EXEC);
        $output = '';
        while (true) {
            $chunk = $ssh->read('', \phpseclib3\Net\SSH2::READ_NEXT, \phpseclib3\Net\SSH2::CHANNEL_EXEC);
            if ($chunk === true) {
                if ($ssh->isTimeout()) { throw new \RuntimeException('Remote sudo command timed out'); }
                break;
            }
            if ($chunk === false || $chunk === null) {
                throw new \RuntimeException('Remote sudo channel closed unexpectedly');
            }
            $output .= (string)$chunk;
        }
        $stderr = (string)$ssh->getStdError();
        return $output . ($stderr !== '' ? "\n" . $stderr : '');
    }
    public function networkScan(int $siteId, string $cidr, string $method = 'nmap', string $adminUser = '', string $adminPass = '', bool $useSudo = true): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        $request = ['cidr'=>$cidr, 'method'=>$method, 'admin_user'=>$adminUser, 'admin_pass'=>$adminPass, 'use_sudo'=>$useSudo];
        try {
            $site = $this->getSite($siteId);
            if (!$site) { throw new \InvalidArgumentException('Select a saved site'); }
            if ($this->isSiteLocal($siteId)) { return $this->networkScanRequestLocal($request); }
            if (empty($site['pbx_api_enabled']) || empty($site['pbx_api_ready'])) {
                throw new \RuntimeException('The selected PBX federation API is not ready. Enable its API or run the scan from that PBX.');
            }
            // Scan requests never use remoteQuery: its recovery path can open SSH.
            // This distinct method also makes older receivers reject credentials
            // instead of silently ignoring them and performing an unprivileged scan.
            return $this->siteConnection($siteId)->execApi([
                'rpc_version'=>2, 'method'=>'networkScanRequestLocal', 'args'=>[$request],
            ]);
        } catch (\Throwable $error) {
            return ['ok'=>false, 'message'=>$this->safeOperationText($error->getMessage(), [$adminPass])];
        }
    }

    /** The API and local GUI share local authentication; credentials are never journalled. */
    public function networkScanRequestLocal(array $request): array
    {
        $password = (string)($request['admin_pass'] ?? '');
        try {
            return $this->engine('NetworkScan')->scan(
                $this->localSiteId(), (string)($request['cidr'] ?? ''), (string)($request['method'] ?? 'nmap'),
                (string)($request['admin_user'] ?? ''), $password, !empty($request['use_sudo'])
            );
        } catch (\Throwable $error) {
            return ['ok'=>false, 'message'=>$this->safeOperationText($error->getMessage(), [$password])];
        }
    }

    public function networkScanLocal(string $cidr, string $method = 'nmap'): array
    {
        $eng = $this->engine('NetworkScan');
        if (!$eng) { return ['ok' => false, 'message' => 'NetworkScan engine not yet available']; }
        return $eng->scan($this->localSiteId(), $cidr, $method);
    }
    /**
     * Recover a missing physical MAC from a deterministic per-device TFTP file.
     *
     * Registrations from Cisco CNF/XML, Yealink and Grandstream devices do not
     * always expose a MAC. Their existing per-MAC filename plus primary-account
     * extension can recover it. Only one unambiguous candidate is accepted;
     * multiple historical files are left for an operator to resolve so a retired
     * handset is never rebound automatically. The historical method name remains
     * part of the federation RPC interface.
     */
    public function backfillLegacyMacs(int $siteId, bool $restoreSuppressed = false): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'backfillLegacyMacs', [$siteId, $restoreSuppressed]);
        }
        return $this->withConfigurationSyncLock(fn(): array => $this->backfillLegacyMacsLocked($siteId, $restoreSuppressed));
    }

    private function backfillLegacyMacsLocked(int $siteId, bool $restoreSuppressed): array
    {
        $eng = $this->engine('TftpStore');
        if (!$eng) {
            return ['ok' => false, 'message' => 'TftpStore engine not available'];
        }
        try {
            $candidates = $eng->scanLegacyMacsByExtension($siteId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $filled = [];
        $ambiguous = [];
        $suppressedSkipped = [];
        $suppressedStmt = $this->db->prepare(
            'SELECT mac FROM ucmp_phone_mac_suppressions WHERE site_id=?'
        );
        $suppressedStmt->execute([$siteId]);
        $suppressed = array_fill_keys(array_map(
            static fn($mac): string => strtoupper((string)$mac),
            $suppressedStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
        ), true);
        foreach ($this->getPhonesLocal(['site_id' => $siteId]) as $p) {
            $ext = (string) $p['extension'];
            if ($ext === '' || !empty($p['mac']) || empty($candidates[$ext])) {
                continue;
            }
            if (count($candidates[$ext]) === 1) {
                $candidateMac = strtoupper((string)$candidates[$ext][0]);
                if (isset($suppressed[$candidateMac]) && !$restoreSuppressed) {
                    $suppressedSkipped[] = "$ext: $candidateMac";
                    continue;
                }
                if (isset($suppressed[$candidateMac])) {
                    $this->db->prepare(
                        'DELETE FROM ucmp_phone_mac_suppressions WHERE site_id=? AND mac=?'
                    )->execute([$siteId, $candidateMac]);
                    unset($suppressed[$candidateMac]);
                }
                $this->db->prepare('UPDATE ucmp_phones SET mac = ?, updated = ? WHERE id = ?')
                    ->execute([$candidateMac, LogicalExtensionService::nextPhoneUpdate($p), $p['id']]);
                $filled[] = "$ext: $candidateMac";
            } else {
                $ambiguous[] = "$ext: " . implode(', ', $candidates[$ext]) . ' (multiple candidates — likely includes decommissioned/swapped units; enter the correct one manually)';
            }
        }
        return [
            'ok'        => true,
            'filled'    => $filled,
            'ambiguous' => $ambiguous,
            'suppressed_skipped'=>$suppressedSkipped,
            'message'   => sprintf(
                'Backfilled %d extension(s) from unambiguous TFTP config matches%s%s.',
                count($filled),
                $ambiguous ? ('; ' . count($ambiguous) . ' left for manual entry (multiple historical candidates)') : '',
                $suppressedSkipped ? ('; ' . count($suppressedSkipped) . ' intentionally deleted MAC(s) left unprovisioned') : ''
            ),
        ];
    }
    public function liveStatus(int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        if (!$this->isSiteLocal($siteId)) {
            return $this->remoteQuery($siteId, 'liveStatus', [$siteId]);
        }
        $eng = $this->engine('LiveStatus');
        if (!$eng) { return ['ok' => false, 'message' => 'LiveStatus engine not yet available']; }
        try {
            return ['ok' => true, 'byExt' => $eng->check($siteId)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Lazily build a SiteConnection for a site (decrypted creds). */
    public function siteConnection(int $siteId)
    {
        if (isset($this->siteConnections[$siteId])) {
            return $this->siteConnections[$siteId];
        }
        $cls = '\\FreePBX\\modules\\Ucmprovisioner\\SiteConnection';
        if (!class_exists($cls)) {
            throw new \Exception('SiteConnection engine not available (run composer install)');
        }
        $site = $this->getSite($siteId, true);
        if (!$site) { throw new \Exception("Unknown site $siteId"); }
        return $this->siteConnections[$siteId] = new $cls($site);
    }

    /** Drop a request-local connection after enrollment or architecture activation. */
    public function resetSiteConnection(int $siteId): void
    {
        unset($this->siteConnections[$siteId]);
    }

    /** Lazily create an engine instance by short name. Returns null if missing. */
    private function engine(string $name)
    {
        if (isset($this->engines[$name])) { return $this->engines[$name]; }
        $cls = '\\FreePBX\\modules\\Ucmprovisioner\\' . $name;
        if (!class_exists($cls)) { return null; }
        return $this->engines[$name] = new $cls($this);
    }

    public function getDb(): PDO { return $this->db; }
    public function localSiteId(): int
    {
        $v = $this->db->query("SELECT id FROM ucmp_sites WHERE is_local = 1 LIMIT 1")->fetchColumn();
        return (int)($v ?: 0);
    }

    /* ---------------------------------------------------------------- */
    /* Helpers                                                           */
    /* ---------------------------------------------------------------- */
    private function upsert(string $table, array $row, int $id): int
    {
        if ($id > 0) {
            $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($row)));
            $row['id'] = $id;
            $where = 'id = :id';
            $revisioned = in_array($table, ['ucmp_templates','ucmp_groups'], true);
            if ($revisioned) {
                $where .= ' AND CASE WHEN revision>0 THEN revision ELSE 1 END = :previous_revision';
                $row['previous_revision'] = max(1, (int)$row['revision'] - 1);
            }
            $statement = $this->db->prepare("UPDATE `$table` SET $set WHERE $where");
            foreach ($row as $key=>$value) {
                $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            }
            $statement->execute();
            if ($revisioned && $statement->rowCount() !== 1) {
                throw new \RuntimeException('The object changed or was deleted while saving. Reload it before retrying.');
            }
            return $id;
        }
        $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($row)));
        $ph = implode(',', array_map(fn($k) => ":$k", array_keys($row)));
        $this->db->prepare("INSERT INTO `$table` ($cols) VALUES ($ph)")->execute($row);
        return (int)$this->db->lastInsertId();
    }
    private function jsonField($v): ?string
    {
        if ($v === null || $v === '') { return null; }
        if (is_array($v)) { return json_encode($v); }
        // already a JSON string? validate
        json_decode((string)$v);
        return json_last_error() === JSON_ERROR_NONE ? (string)$v : json_encode($v);
    }

    /** JSON embedded in HTML must never contain a literal closing script delimiter. */
    public static function browserJson($value, int $flags = 0, int $depth = 512)
    {
        return json_encode($value, $flags | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT, $depth);
    }

    /**
     * Validate the operator-controlled schema-2 call-state policy at the write
     * boundary. Schema-less maps remain readable for older records and remote
     * modules; opening and explicitly saving one converts it through the visible
     * current editor rather than mutating it silently during an upgrade.
     */
    private function softkeyField($value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if (is_array($value)) {
            $decoded = $value;
        } else {
            try {
                $decoded = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Softkey policy must be valid JSON', 0, $e);
            }
        }
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Softkey policy must be a JSON object');
        }
        if (!$decoded) { return null; }
        if (!\FreePBX\modules\Ucmprovisioner\SoftkeyPolicy::isV2($decoded)) {
            return json_encode(
                $decoded,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        $policy = \FreePBX\modules\Ucmprovisioner\SoftkeyPolicy::normalizeV2($decoded);
        $hasContent = !empty($policy['states'])
            || !empty($policy['definitions'])
            || !empty($policy['annotations'])
            || !empty($policy['unresolved'])
            || !empty($policy['visual']);
        if (!$hasContent) { return null; }
        return json_encode(
            $policy,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
    private function nullableInt($v): ?int
    {
        return ($v === null || $v === '' ) ? null : (int)$v;
    }
    private function normMac(string $mac): string
    {
        return strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));
    }

    /*
     * Site bootstrap values stored in the module database are encrypted with
     * AES-GCM and a random key created for this PBX. Directory reader secrets
     * use separate mode-0600 files. Encryption limits disclosure from a copied
     * database; an attacker who controls both the database and this PBX can also
     * obtain the local key, so host access remains the primary security boundary.
     */
    public function alignedSipSecret(string $extension, string $secret): string
    {
        return \FreePBX\modules\Ucmprovisioner\SipSecretPolicy::aligned($extension,$secret,$this->credentialKeyStore()->read());
    }

    private function cryptKey(): string
    {
        $k = $this->credentialKeyStore()->read();
        if (!$k) {
            $k = bin2hex(random_bytes(32));
            $this->setSetting('crypt_key', $k);
        }
        return hash('sha256', $k, true);
    }
    protected function credentialKeyStore(): \FreePBX\modules\Ucmprovisioner\LocalCredentialKey
    {
        return new \FreePBX\modules\Ucmprovisioner\LocalCredentialKey($this->db);
    }
    public function credentialKeyStatus(): array { return $this->credentialKeyStore()->status(); }

    /** Read-only install verification, run as asterisk; never exposed through federation RPC. */
    public function credentialStorageReadiness(): array
    {
        $failures = [];
        $keyState = 'unavailable';
        try {
            $keyState = (string)$this->credentialKeyStore()->status()['state'];
            if ($keyState !== 'private_file') { $failures[] = 'Credential-key conversion is incomplete'; }
        } catch (\Throwable $e) { $failures[] = 'The matching private credential key cannot be read'; }
        if (!$failures) {
            foreach (['ucmp_sites','ucmp_settings'] as $table) {
                foreach ($this->db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    foreach ($row as $value) {
                        if (is_string($value) && preg_match('/^(?:gcm|enc):/', $value) && $this->dec($value) === '') {
                            $failures[] = 'An existing encrypted configuration value cannot be decrypted';
                            break 3;
                        }
                    }
                }
            }
        }
        $directoryReady = true;
        $directory = new \FreePBX\modules\Ucmprovisioner\DirectoryService($this);
        foreach ($this->db->query('SELECT * FROM ucmp_directory_sources')->fetchAll(PDO::FETCH_ASSOC) as $source) {
            if ((string)$source['secret_ref'] === 'legacy-setting:ad_bind_password'
                || (!empty($source['enabled']) && empty($directory->sourceRuntimeStatus($source)['secret_configured']))) {
                $directoryReady = false;
            }
        }
        if (!$directoryReady) { $failures[] = 'An enabled directory reader credential is unavailable or its storage upgrade is incomplete'; }
        return ['ok'=>!$failures, 'key_status'=>$keyState, 'directory_sources_ready'=>$directoryReady, 'failures'=>$failures];
    }

    protected function credentialArchives(): \FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService
    {
        return new \FreePBX\modules\Ucmprovisioner\ConfigurationArchiveService($this->db,$this);
    }

    /** Install hook only: preserve credentials and move their storage without a network call. */
    public function upgradeCredentialStorage(): array
    {
        $store=$this->credentialKeyStore();
        $status=$store->status(); // Missing or disagreeing keys must never be replaced.
        $sources=$this->db->query("SELECT id FROM ucmp_directory_sources WHERE source_key='legacy-default' AND secret_ref='legacy-setting:ad_bind_password'")->fetchAll(PDO::FETCH_COLUMN);
        if (empty($status['migration_required']) && $status['state']!=='uninitialized' && !$sources) {
            return ['ok'=>true,'message'=>'Credential storage is current'];
        }
        $site=$this->getSite($this->localSiteId()) ?: [];
        $siteKey=(string)($site['site_key'] ?? '');
        if ($siteKey==='') { throw new \RuntimeException('Local controller identity is required before credential upgrade'); }
        // A new installation has no ciphertext to recover. Seed its first key using
        // the existing generator, then move it through the same verified path.
        if ($status['state']==='uninitialized') { $this->cryptKey(); }
        $archives=$this->credentialArchives();
        $backup=$archives->export(['sites','settings','directory'],$this->currentModuleVersion(),'recovery',false);
        $verified=$archives->verifyRecoveryBackup($backup['path'],$siteKey,hash('sha256',$store->read()));
        if (empty($verified['ok']) || !isset($verified['database_members']['database/directory.json'])) {
            throw new \RuntimeException('Credential upgrade recovery backup verification failed; original credentials retained');
        }
        if (!empty($store->status()['migration_required'])) {
            $store->migrate($backup['path'],$siteKey,[$archives,'verifyRecoveryBackup']);
        }
        $directory=new \FreePBX\modules\Ucmprovisioner\DirectoryService($this);
        foreach ($sources as $sourceId) { $directory->migrateLegacySource((int)$sourceId); }
        return ['ok'=>true,'backup'=>basename($backup['path']),'message'=>'Credential storage upgraded automatically; verified local recovery backup retained'];
    }

    public function enc(string $plain): string
    {
        if ($plain === '') { return ''; }
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $this->cryptKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('Secret encryption failed');
        }
        return 'gcm:' . base64_encode($iv . $tag . $ct);
    }
    public function dec(string $stored): string
    {
        if ($stored === '') { return ''; }
        if (strpos($stored, 'gcm:') === 0) {
            $raw = base64_decode(substr($stored, 4), true);
            if ($raw === false || strlen($raw) < 28) { return ''; }
            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $ct = substr($raw, 28);
            $pt = openssl_decrypt($ct, 'aes-256-gcm', $this->cryptKey(), OPENSSL_RAW_DATA, $iv, $tag);
            return $pt === false ? '' : $pt;
        }
        if (strpos($stored, 'enc:') !== 0) { return $stored; }
        $raw = base64_decode(substr($stored, 4));
        $iv = substr($raw, 0, 16);
        $ct = substr($raw, 16);
        $pt = openssl_decrypt($ct, 'aes-256-cbc', $this->cryptKey(), OPENSSL_RAW_DATA, $iv);
        return $pt === false ? '' : $pt;
    }

    private function ensureProvisionerKey(): array
    {
        // The normal key store is shared with Asterisk.  An already-enrolled remote agent may
        // have inherited the old 0750 directory mode, however, which lets ucmp-pbx read but not
        // create its first key.  Keep a non-root upgrade path in the agent's own home; both
        // locations are group-readable by asterisk and SiteConnection knows how to locate them.
        $preferredDir = '/etc/asterisk/keys';
        $agentDir = '/var/lib/ucmp-pbx/keys';

        // An empty writable preferred directory must not shadow an already enrolled pair in
        // the agent fallback.  Existing or orphaned material is authoritative: validate it or
        // fail closed, never rotate to a new location.  Check parent components before writes.
        $assertNoSymlink = static function (string $path): void {
            $current = rtrim($path, '/');
            while ($current !== '' && $current !== '/') {
                if (is_link($current)) {
                    throw new \RuntimeException("Provisioner key path cannot be a symbolic link: $current");
                }
                $parent = dirname($current);
                if ($parent === $current) { break; }
                $current = $parent;
            }
        };
        $existing = null;
        foreach ([$preferredDir, $agentDir] as $candidateDir) {
            $candidateKey = $candidateDir . '/ucmp_id_rsa';
            $candidatePub = $candidateKey . '.pub';
            foreach ([$candidateDir, $candidateKey, $candidatePub, $candidateKey . '.lock'] as $path) {
                $assertNoSymlink($path);
            }
            $privatePresent = file_exists($candidateKey);
            $publicPresent = file_exists($candidatePub);
            if ($privatePresent && !is_file($candidateKey)) {
                throw new \RuntimeException("Provisioner private key path is not a regular file: $candidateKey");
            }
            if ($publicPresent && !is_file($candidatePub)) {
                throw new \RuntimeException("Provisioner public key path is not a regular file: $candidatePub");
            }
            if ($publicPresent && !$privatePresent) {
                throw new \RuntimeException("Provisioner public key exists without its private enrollment key: $candidatePub");
            }
            if ($privatePresent && $existing === null) {
                $existing = [$candidateDir, $candidateKey, $candidatePub];
            }
        }
        if ($existing !== null) {
            [$dir, $keyPath, $pubPath] = $existing;
        } else {
            $dir = (is_dir($preferredDir) && is_writable($preferredDir)) ? $preferredDir : $agentDir;
            $keyPath = $dir . '/ucmp_id_rsa';
            $pubPath = $keyPath . '.pub';
            $assertNoSymlink($dir);
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $pair=\FreePBX\modules\Ucmprovisioner\ProvisionerKeyPair::ensure($keyPath);
        // Both the FreePBX web process and the constrained cross-site agent are members of
        // the asterisk group.  Group-read on the private key avoids granting the agent root.
        @chgrp($dir, 'asterisk');
        @chmod($dir, $dir === $preferredDir ? 0770 : 0750);
        @chgrp($keyPath, 'asterisk');
        @chmod($keyPath, 0640);
        @chgrp($pubPath, 'asterisk');
        @chmod($pubPath, 0644);
        return $pair;
    }

    /** Parse, reject private material, and canonicalize an SSH public key for authorized_keys. */
    private function normalizeProvisionerPublicKey(string $value, string $source): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/BEGIN (?:OPENSSH |RSA |EC )?PRIVATE KEY/', $value)) {
            throw new \RuntimeException("$source did not return a public SSH key");
        }
        try {
            $key = \phpseclib3\Crypt\PublicKeyLoader::load($value);
            if (method_exists($key, 'getPublicKey')) {
                // Some loaders return a private-key object. Never accept it merely because it can
                // be converted to a public key—the remote RPC must not disclose private material.
                throw new \RuntimeException("$source returned private key material");
            }
            $openSsh = trim((string)$key->toString('OpenSSH'));
        } catch (\Throwable $e) {
            throw new \RuntimeException("$source returned an invalid public SSH key: " . $e->getMessage());
        }
        if (!preg_match('/^(?:ssh-(?:rsa|ed25519)|ecdsa-sha2-[^ ]+) [A-Za-z0-9+\/=]+(?:\s+.*)?$/', $openSsh)) {
            throw new \RuntimeException("$source returned an unsupported SSH public-key format");
        }
        return $openSsh;
    }

    public function getProvisionerPublicKey(): array
    {
        $key = $this->ensureProvisionerKey();
        return ['ok' => true, 'public_key' => $key['public_key']];
    }

    /**
     * Mirror a controller-completed Docker enrollment into this PBX's own local registry row.
     * The forced-command agent may invoke only this bounded field update; no credential or
     * arbitrary site ID crosses the federation boundary.
     */
    public function adoptDockerEnrollment(array $data): array
    {
        $host = trim((string)($data['docker_host'] ?? ''));
        $port = (int)($data['docker_ssh_port'] ?? 22);
        $hostKey = trim((string)($data['docker_host_key'] ?? ''));
        $bindPath = rtrim(trim((string)($data['tftp_bind_path'] ?? '/opt/tftp-server/tftpboot')), '/');
        if ($host === '' || $port < 1 || $port > 65535 || !preg_match('/^SHA256:[A-Za-z0-9+\/_-]+$/', $hostKey)) {
            throw new \InvalidArgumentException('Invalid Docker enrollment metadata');
        }
        if ($bindPath !== '/opt/tftp-server/tftpboot') {
            throw new \InvalidArgumentException('TFTP bind path must be exactly /opt/tftp-server/tftpboot');
        }
        $container = trim((string)($data['tftp_container'] ?? ''));
        if ($container === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $container)) {
            throw new \InvalidArgumentException('A concrete existing TFTP container name or ID is required');
        }
        $localId = $this->localSiteId();
        $local = $this->getSite($localId, true);
        if (!$local) {
            throw new \RuntimeException('Local site metadata is unavailable for Docker enrollment adoption');
        }
        $serviceHost = $this->siteConnectionFields([], ['tftp_service_host'=>$data['tftp_service_host'] ?? $host])['tftp_service_host'];
        // Probe the candidate with this PBX's own provisioner private key. Setting the
        // secret to null also makes SiteConnection refuse its legacy password fallback.
        $candidate = array_merge($local, [
            'docker_host'=>$host,
            'docker_ssh_port'=>$port,
            'docker_ssh_user'=>'ucmp-tftp',
            'docker_ssh_secret'=>null,
            'docker_host_key'=>$hostKey,
            'tftp_root_path'=>'/tftpboot',
            'tftp_bind_path'=>$bindPath,
            'tftp_container'=>$container,
            'tftp_service_host'=>$serviceHost,
            'tftp_mode'=>'docker',
        ]);
        $candidateConnection = new \FreePBX\modules\Ucmprovisioner\SiteConnection($candidate);
        try {
            $candidateSftp = $candidateConnection->tftpSftp('docker');
            \FreePBX\modules\Ucmprovisioner\DockerEnrollment::verifySftp($candidateSftp);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Owning-PBX Docker SFTP probe failed before local readiness was recorded: ' . $e->getMessage(), 0, $e);
        }
        $active = \FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($local);
        $this->db->prepare("UPDATE ucmp_sites SET docker_host=?, docker_ssh_port=?, docker_ssh_user='ucmp-tftp', docker_ssh_secret=NULL, docker_host_key=?, tftp_root_path='/tftpboot', tftp_bind_path=?, tftp_container=?, tftp_service_host=?, docker_bootstrapped_at=?, tftp_pending_mode=? WHERE id=?")
            ->execute([$host, $port, $hostKey, $bindPath, $container, $serviceHost, time(), $active === 'docker' ? null : 'docker', $localId]);
        $this->resetSiteConnection($localId);
        $this->logOperation($localId, 'tftp-enrollment-sync', 'success', 'Controller enrollment metadata adopted by local site');
        return ['ok'=>true, 'message'=>'Local site now uses the enrolled ucmp-tftp channel'];
    }

    /** Store a controller-completed PBX-hosted TFTP bootstrap on the site's own PBX. */
    public function adoptPbxTftpEnrollment(array $data): array
    {
        $host = trim((string)($data['pbx_host'] ?? ''));
        $port = (int)($data['pbx_ssh_port'] ?? 22);
        $hostKey = trim((string)($data['pbx_host_key'] ?? ''));
        $serviceHost = trim((string)($data['pbx_tftp_service_host'] ?? ''), " []\t\r\n");
        if ($host === '' || $port < 1 || $port > 65535
            || !preg_match('/^SHA256:[A-Za-z0-9+\/_-]+$/D', $hostKey)
            || ($serviceHost !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $serviceHost))) {
            throw new \InvalidArgumentException('Invalid PBX-hosted TFTP enrollment metadata');
        }
        $localId = $this->localSiteId();
        $local = $this->getSite($localId, true);
        if (!$local) { throw new \RuntimeException('Local site metadata is unavailable for PBX TFTP adoption'); }
        $serviceHost = $this->siteConnectionFields([], ['pbx_tftp_service_host'=>$serviceHost])['pbx_tftp_service_host'];
        $candidate = array_replace($local, ['pbx_host'=>$host, 'pbx_ssh_port'=>$port, 'pbx_host_key'=>$hostKey,
            'pbx_tftp_service_host'=>$serviceHost, 'pbx_tftp_root_path'=>'/tftpboot']);
        try {
            $connection = new \FreePBX\modules\Ucmprovisioner\SiteConnection($candidate);
            \FreePBX\modules\Ucmprovisioner\DockerEnrollment::verifySftp($connection->tftpSftp('pbx'));
        } catch (\Throwable $e) {
            throw new \RuntimeException('Owning-PBX SFTP write/read/delete failed before readiness was recorded: ' . $e->getMessage(), 0, $e);
        }
        $active = \FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($local);
        $pending = $active === 'pbx' ? null : 'pbx';
        $this->db->prepare(
            "UPDATE ucmp_sites SET pbx_host=?, pbx_ssh_port=?, pbx_host_key=?, pbx_tftp_service_host=?,
             pbx_tftp_root_path='/tftpboot', pbx_tftp_bind_path='/srv/tftp/tftpboot',
             pbx_tftp_daemon='tftpd-hpa', pbx_tftp_bootstrapped_at=?, tftp_pending_mode=? WHERE id=?"
        )->execute([$host, $port, $hostKey, $serviceHost !== '' ? $serviceHost : null, time(), $pending, $localId]);
        unset($this->siteConnections[$localId]);
        $this->logOperation($localId, 'pbx-tftp-enrollment-sync', 'success', 'PBX-hosted TFTP bootstrap metadata adopted by local site');
        return ['ok'=>true, 'message'=>'PBX-hosted TFTP enrollment metadata was adopted'];
    }

    /** Fixed, read-only local probe used directly and through federation RPC. */
    public function inspectPbxTftpLocal(): array
    {
        $localId = $this->localSiteId();
        $site = $this->getSite($localId) ?: [];
        $root = (string)($site['pbx_tftp_bind_path'] ?? '/srv/tftp/tftpboot');
        $active = trim((string)@shell_exec('/usr/bin/systemctl is-active tftpd-hpa 2>/dev/null'));
        $enabled = trim((string)@shell_exec('/usr/bin/systemctl is-enabled tftpd-hpa 2>/dev/null'));
        $listenerOutput = trim((string)@shell_exec("/usr/bin/ss -H -lun 'sport = :69' 2>/dev/null"));
        $configuredDirectory = '';
        $config = @file_get_contents('/etc/default/tftpd-hpa');
        if (is_string($config) && preg_match('/^TFTP_DIRECTORY=["\']?([^"\'\r\n]+)["\']?/m', $config, $match)) {
            $configuredDirectory = trim((string)$match[1]);
        }
        $stat = @stat($root);
        $owner = '';
        if (is_array($stat) && function_exists('posix_getpwuid')) {
            $pw = @posix_getpwuid((int)$stat['uid']);
            $owner = is_array($pw) ? (string)($pw['name'] ?? '') : '';
        }
        $directoryReady = is_dir($root) && $configuredDirectory === $root;
        $ready = $active === 'active' && $listenerOutput !== '' && $directoryReady
            && !empty($site['pbx_tftp_bootstrapped_at']);
        return [
            'ok'=>$ready,
            'installed'=>is_file('/usr/sbin/in.tftpd') || is_file('/usr/bin/in.tftpd'),
            'daemon'=>'tftpd-hpa',
            'service_state'=>$active !== '' ? $active : 'not installed',
            'enabled_state'=>$enabled !== '' ? $enabled : 'unknown',
            'listener'=>$listenerOutput !== '' ? $listenerOutput : 'no UDP/69 listener detected',
            'root'=>$root,
            'root_exists'=>is_dir($root),
            'root_owner'=>$owner,
            'configured_directory'=>$configuredDirectory,
            'voice_subnets'=>(string)($site['voice_subnets'] ?? ''),
            'active_mode'=>\FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($site),
            'pending_mode'=>(string)($site['tftp_pending_mode'] ?? ''),
            'message'=>$ready
                ? 'tftpd-hpa is enabled, listening on UDP/69, and serving the managed PBX TFTP root.'
                : 'PBX-hosted TFTP is not fully ready; review the daemon, listener, directory, and bootstrap state below.',
        ];
    }

    /** Read the PBX-hosted daemon state from exactly the selected site. */
    public function inspectPbxTftp(int $siteId): array
    {
        $siteId = $siteId ?: $this->localSiteId();
        $result = $this->isSiteLocal($siteId)
            ? $this->inspectPbxTftpLocal()
            : $this->remoteLiveQuery($siteId, 'inspectPbxTftpLocal', []);
        $site = $this->getSite($siteId) ?: [];
        $result['active_mode'] = \FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($site);
        $result['pending_mode'] = (string)($site['tftp_pending_mode'] ?? '');
        if (!empty($site['pbx_tftp_bootstrapped_at'])) {
            try {
                $conn = $this->siteConnection($siteId);
                $list = $conn->tftpSftp('pbx')->nlist($conn->tftpRoot('pbx'));
                $result['sftp_ok'] = is_array($list);
                $result['sftp_entries'] = is_array($list) ? count($list) : 0;
            } catch (\Throwable $error) {
                $result['sftp_ok'] = false;
                $result['sftp_error'] = $error->getMessage();
                $result['ok'] = false;
            }
        } else {
            $result['sftp_ok'] = false;
        }
        return $result;
    }

    /** Activate one already-bootstrapped backend on this PBX; federation allowlisted. */
    public function activateTftpArchitectureLocal(string $targetMode, string $expectedMode): array
    {
        if (!in_array($targetMode, ['docker', 'pbx'], true)
            || !in_array($expectedMode, ['docker', 'pbx'], true)
            || $targetMode === $expectedMode) {
            throw new \InvalidArgumentException('Invalid TFTP architecture transition');
        }
        $localId = $this->localSiteId();
        $site = $this->getSite($localId) ?: [];
        if (\FreePBX\modules\Ucmprovisioner\SiteConnection::activeTftpMode($site) !== $expectedMode) {
            throw new \RuntimeException('The local TFTP architecture changed before activation');
        }
        $readyField = $targetMode === 'pbx' ? 'pbx_tftp_bootstrapped_at' : 'docker_bootstrapped_at';
        if (empty($site[$readyField])) {
            throw new \RuntimeException('The requested TFTP backend has not completed bootstrap');
        }
        $stmt = $this->db->prepare('UPDATE ucmp_sites SET tftp_mode=?, tftp_pending_mode=NULL WHERE id=? AND tftp_mode=?');
        $stmt->execute([$targetMode, $localId, $expectedMode]);
        if ($stmt->rowCount() !== 1) { throw new \RuntimeException('TFTP architecture activation lost its concurrency check'); }
        unset($this->siteConnections[$localId]);
        $this->logOperation($localId, 'tftp-architecture-activate', 'success', strtoupper($targetMode) . ' TFTP activated');
        return ['ok'=>true, 'active_mode'=>$targetMode, 'message'=>strtoupper($targetMode) . ' TFTP activated'];
    }

    private function addError(string $msg): void
    {
        if (function_exists('out')) { out('UCM Provisioner error: ' . $msg); }
    }

    private function currentModuleVersion(): string
    {
        $xml = @file_get_contents(__DIR__ . '/module.xml');
        if (is_string($xml) && preg_match('/<version>\s*([^<]+)\s*<\/version>/i', $xml, $match)) {
            return trim((string)$match[1]);
        }
        return 'unknown';
    }

    /**
     * One-time enrollment of the least-privilege PBX control account. The SSH key is constrained
     * to a root-owned dispatcher; the dispatcher can only invoke remoteDispatch(), whose method
     * allowlist and site-ID normalization live above. Runtime work executes as the unprivileged
     * account with membership in the asterisk group, not as root. Bootstrap credentials are
     * never persisted.
     */
    public function bootstrapPbxAgent(int $id, string $host, int $port, string $adminUser, string $adminPass, bool $useSudo, string $expectedHostKey = ''): array
    {
        if ($id <= 0 || $host === '' || $adminUser === '' || $adminPass === '') {
            throw new \InvalidArgumentException('Saved site, PBX host, bootstrap username, and password are required');
        }
        $site = $this->getSite($id);
        if (!$site) { throw new \InvalidArgumentException('Save the site before enrolling its PBX agent'); }
        $savedHost = trim((string)($site['pbx_host'] ?: $site['pbx_addr']));
        $savedPort = (int)($site['pbx_ssh_port'] ?: 22);
        if ($savedHost !== trim($host) || $savedPort !== ($port ?: 22)) {
            throw new \InvalidArgumentException('PBX enrollment host and port must match the saved site');
        }
        $key = $this->ensureProvisionerKey();
        $ssh = new \phpseclib3\Net\SSH2($host, $port ?: 22);
        try {
            $hostKeyBlob = $ssh->getServerPublicHostKey();
            $hostKey = $this->fingerprintHostKey((string)$hostKeyBlob);
        }
        catch (\Throwable $e) {
            throw new \RuntimeException("PBX SSH handshake failed for $host:" . ($port ?: 22)
                . "\nStage: SSH connection and host-key exchange. No administrator credentials were sent.\n"
                . $this->safeOperationText($e->getMessage(), [$adminPass]), 0, $e);
        }
        $trusted = trim((string)($site['pbx_host_key'] ?? '')) ?: trim($expectedHostKey);
        if (!preg_match('/^SHA256:[A-Za-z0-9+\\/_-]+$/D', $trusted) || !hash_equals($trusted, $hostKey)) {
            throw new \RuntimeException("PBX host key was not confirmed before login (observed $hostKey)");
        }
        $this->loginEnrollmentAdministrator($ssh, 'PBX', $host, $port ?: 22, $adminUser, $adminPass);

        $agent = <<<'PHP'
#!/usr/bin/php
<?php
if (PHP_SAPI !== 'cli' || $argc !== 2) {
    fwrite(STDERR, "invalid request\n");
    exit(64);
}
$payload = $argv[1] === '--stdin-v1' ? stream_get_contents(STDIN, 1048577) : $argv[1];
if (!is_string($payload) || $payload === '' || strlen($payload) > 1048576 || preg_match('/\A[A-Za-z0-9+\/=]+\z/D', $payload) !== 1) {
    fwrite(STDERR, "invalid request\n");
    exit(64);
}
require_once '/etc/freepbx.conf';
echo FreePBX::Ucmprovisioner()->remoteDispatch($payload);
PHP;
        $dispatcher = <<<'SH'
#!/bin/sh
case "${SSH_ORIGINAL_COMMAND:-}" in
  ucmp-rpc-capabilities)
    printf '%s\n' '{"rpc_stdin":1}'
    ;;
  ucmp-rpc-stdin-v1)
    exec /usr/local/libexec/ucmp-pbx-agent --stdin-v1
    ;;
  ucmp-rpc\ *)
    payload=${SSH_ORIGINAL_COMMAND#ucmp-rpc }
    exec /usr/local/libexec/ucmp-pbx-agent "$payload"
    ;;
  *)
    echo "ucmp-pbx: command rejected" >&2
    exit 126
    ;;
esac
SH;
        $authorized = 'command="/usr/local/libexec/ucmp-pbx-dispatch",no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-pty ' . $key['public_key'] . "\n";

        // Check every managed redirect and its existing parent chain before any root write.
        // Missing paths are fine; install/mkdir below creates them. Existing symlinks are not.
        $symlinkPreflight = <<<'SH'
for path in \
  /etc /etc/asterisk /etc/asterisk/keys /etc/asterisk/keys/ucmp_id_rsa /etc/asterisk/keys/ucmp_id_rsa.pub \
  /etc/sudoers.d /etc/sudoers.d/ucmp-pbx \
  /usr /usr/local /usr/local/libexec /usr/local/libexec/ucmp-pbx-agent /usr/local/libexec/ucmp-pbx-dispatch \
  /var /var/lib /var/lib/ucmp-pbx /var/lib/ucmp-pbx/.ssh /var/lib/ucmp-pbx/.ssh/authorized_keys
do
  if test -L "$path"; then
    echo "refusing symlinked PBX agent path: $path" >&2
    exit 73
  fi
done
SH;

        $script = "set -eu\n" .
            $symlinkPreflight . "\n" .
            "id -u ucmp-pbx >/dev/null 2>&1 || useradd --system --create-home --home-dir /var/lib/ucmp-pbx --shell /bin/sh ucmp-pbx\n" .
            "usermod -d /var/lib/ucmp-pbx -s /bin/sh -a -G asterisk ucmp-pbx\n" .
            // Generate the random bootstrap password on the PBX.  It is never present in this
            // PHP process, remote command argv, SSH output, or persistent state.
            "account_password=\"\$(/usr/bin/php -r 'echo bin2hex(random_bytes(32));')\"\n" .
            "test -n \"\$account_password\"\n" .
            "printf '%s\\n' \"ucmp-pbx:\$account_password\" | chpasswd\n" .
            "unset account_password\n" .
            "install -d -o root -g root -m 0755 /usr/local/libexec\n" .
            "printf %s " . escapeshellarg(base64_encode($agent)) . " | base64 -d > /usr/local/libexec/ucmp-pbx-agent\n" .
            "printf %s " . escapeshellarg(base64_encode($dispatcher)) . " | base64 -d > /usr/local/libexec/ucmp-pbx-dispatch\n" .
            "chown root:root /usr/local/libexec/ucmp-pbx-agent /usr/local/libexec/ucmp-pbx-dispatch\n" .
            "chmod 0755 /usr/local/libexec/ucmp-pbx-agent /usr/local/libexec/ucmp-pbx-dispatch\n" .
            "install -d -o ucmp-pbx -g ucmp-pbx -m 0700 /var/lib/ucmp-pbx/.ssh\n" .
            "printf %s " . escapeshellarg(base64_encode($authorized)) . " | base64 -d > /var/lib/ucmp-pbx/.ssh/authorized_keys\n" .
            "chown ucmp-pbx:ucmp-pbx /var/lib/ucmp-pbx/.ssh/authorized_keys\n" .
            "chmod 0600 /var/lib/ucmp-pbx/.ssh/authorized_keys\n" .
            "rm -f /etc/sudoers.d/ucmp-pbx\n" .
            // Group write is intentional: ucmp-pbx is constrained by forced-command RPC and is
            // in the asterisk group so it can create the site's own Docker-access key on demand.
            "install -d -o asterisk -g asterisk -m 0770 /etc/asterisk/keys\n" .
            "test ! -f /etc/asterisk/keys/ucmp_id_rsa || chown asterisk:asterisk /etc/asterisk/keys/ucmp_id_rsa\n" .
            "test ! -f /etc/asterisk/keys/ucmp_id_rsa || chmod 0640 /etc/asterisk/keys/ucmp_id_rsa\n" .
            "test ! -f /etc/asterisk/keys/ucmp_id_rsa.pub || chown asterisk:asterisk /etc/asterisk/keys/ucmp_id_rsa.pub\n" .
            "test ! -f /etc/asterisk/keys/ucmp_id_rsa.pub || chmod 0644 /etc/asterisk/keys/ucmp_id_rsa.pub\n";
        $output = $this->execPrivilegedScript($ssh, $script, $adminUser, $adminPass, $useSudo);
        $bootstrapExit = $ssh->getExitStatus();
        if ($bootstrapExit !== 0) {
            throw new \RuntimeException('PBX agent bootstrap failed (exit status ' . var_export($bootstrapExit, true) . '): ' . substr($output, 0, 500));
        }

        $test = new \phpseclib3\Net\SSH2($host, $port ?: 22);
        $testHostKey = $this->fingerprintHostKey((string)$test->getServerPublicHostKey());
        if (!hash_equals($hostKey, $testHostKey)) {
            throw new \RuntimeException('PBX host key changed before the post-enrollment verification login');
        }
        $private = \phpseclib3\Crypt\PublicKeyLoader::load((string)file_get_contents($key['private']));
        if (!$test->login('ucmp-pbx', $private)) {
            throw new \RuntimeException("PBX agent installed but key login failed. Bootstrap output: $output");
        }
        $probePayload = base64_encode((string)json_encode(['method' => 'listPjsipTrunks', 'args' => [0]]));
        $probe = (string)$test->exec('ucmp-rpc ' . $probePayload);
        $probeData = json_decode($probe, true);
        if (!is_array($probeData) || empty($probeData['ok'])) {
            throw new \RuntimeException('PBX agent installed but the provisioner RPC probe failed: ' . substr($probe, 0, 500));
        }
        $this->db->prepare("UPDATE ucmp_sites SET pbx_ssh_user='ucmp-pbx', pbx_ssh_secret=NULL, pbx_agent_user='ucmp-pbx', pbx_agent_ready=1, pbx_host_key=?, pbx_bootstrapped_at=? WHERE id=?")
            ->execute([$hostKey, time(), $id]);
        $this->logOperation($id, 'pbx-enrollment', 'success', 'ucmp-pbx agent enrolled and RPC probe passed', $output);
        return ['ok' => true, 'message' => 'ucmp-pbx forced-command agent enrolled and verified; bootstrap password was not stored.', 'host_key' => $hostKey, 'trunks' => $probeData['trunks'] ?? []];
    }

    private function fingerprintHostKey(string $key): string
    {
        $parts = preg_split('/\s+/', trim($key));
        $blob = base64_decode($parts[1] ?? '', true);
        if ($blob === false || $blob === '') {
            throw new \RuntimeException('Could not parse SSH host key');
        }
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    /**
     * Install or repair the module-managed TFTP daemon and SFTP data plane on
     * the selected PBX. Temporary administrator credentials are never stored.
     */
    public function bootstrapPbxTftp(
        int $id,
        string $adminUser,
        string $adminPass,
        bool $useSudo,
        bool $installMissing,
        string $expectedHostKey = ''
    ): array {
        if ($id <= 0 || trim($adminUser) === '' || $adminPass === '') {
            throw new \InvalidArgumentException('Saved site, PBX administrator username, and temporary password are required');
        }
        $site = $this->getSite($id);
        if (!$site) { throw new \InvalidArgumentException('Save the site before bootstrapping PBX-hosted TFTP'); }
        $host = trim((string)($site['pbx_host'] ?: ($site['pbx_addr'] ?? '')));
        $port = (int)($site['pbx_ssh_port'] ?: 22);
        if ($host === '') { throw new \InvalidArgumentException('Save the PBX management host before bootstrap'); }
        if (empty($site['is_local']) && empty($site['pbx_agent_ready'])) {
            throw new \RuntimeException('Enroll this site\'s ucmp-pbx agent before bootstrapping PBX-hosted TFTP');
        }

        [$ssh, $hostKey] = $this->remoteAdminSsh($site, trim($adminUser), $adminPass, $expectedHostKey);
        $existingAuthorizedKeys = $this->existingTftpAuthorizedKeys($ssh, $adminUser, $adminPass, $useSudo);
        $key = $this->ensureProvisionerKey();
        $publicKeys = [$key['public_key']];
        if (empty($site['is_local'])) {
            $remote = $this->remoteLiveQuery($id, 'getProvisionerPublicKey');
            if (empty($remote['ok'])) {
                throw new \RuntimeException('The remote PBX could not prepare its local TFTP key');
            }
            $publicKeys[] = $this->normalizeProvisionerPublicKey(
                trim((string)($remote['public_key'] ?? '')),
                'The remote PBX agent'
            );
        }
        $authorizedKeys = \FreePBX\modules\Ucmprovisioner\DockerEnrollment::mergeAuthorizedKeys($existingAuthorizedKeys, $publicKeys);

        $tftpdConfig = <<<'CONF'
# ucmprovisioner managed PBX TFTP
TFTP_USERNAME="tftp"
TFTP_DIRECTORY="/srv/tftp/tftpboot"
TFTP_ADDRESS=":69"
TFTP_OPTIONS="--secure --verbose"
CONF;
        $sshBlock = <<<'CONF'

# BEGIN UCM PROVISIONER PBX TFTP
Match all
Match User ucmp-tftp
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthenticationMethods publickey
    ChrootDirectory /srv/tftp
    ForceCommand internal-sftp -d /tftpboot
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
Match all
# END UCM PROVISIONER PBX TFTP
CONF;
        $sshTransaction = \FreePBX\modules\Ucmprovisioner\SshEnrollmentTransaction::scripts(bin2hex(random_bytes(12)), $sshBlock);
        $install = $installMissing
            ? "if ! command -v in.tftpd >/dev/null 2>&1 || ! command -v tftp >/dev/null 2>&1; then export DEBIAN_FRONTEND=noninteractive; apt-get update; apt-get install -y tftpd-hpa tftp-hpa; fi\n"
            : "command -v in.tftpd >/dev/null 2>&1 && command -v tftp >/dev/null 2>&1 || { echo 'The tftpd-hpa server or tftp-hpa client is missing and automatic installation was not authorized' >&2; exit 69; }\n";
        $restoreTftp = <<<'SH'
set -e
test ! -L /etc/default/tftpd-hpa
if test -f /etc/default/tftpd-hpa; then
  cmp -s /etc/default/tftpd-hpa "$ssh_recovery/tftpd-hpa.candidate" || cmp -s /etc/default/tftpd-hpa "$ssh_recovery/tftpd-hpa.before" || { echo 'TFTP configuration changed after enrollment; retained recovery requires review' >&2; exit 73; }
fi
if test -f "$ssh_recovery/tftpd-hpa.before"; then
  cp -p "$ssh_recovery/tftpd-hpa.before" /etc/default/tftpd-hpa
elif test -f "$ssh_recovery/tftpd-hpa.absent"; then
  rm -f /etc/default/tftpd-hpa
else
  echo 'TFTP before-state is incomplete; retained recovery requires review' >&2; exit 73
fi
if test -f "$ssh_recovery/tftpd-hpa.enabled"; then systemctl enable tftpd-hpa >/dev/null; else systemctl disable tftpd-hpa >/dev/null 2>&1 || test ! -f "$ssh_recovery/tftpd-hpa.installed"; fi
if test -f "$ssh_recovery/tftpd-hpa.active"; then systemctl restart tftpd-hpa; else systemctl stop tftpd-hpa >/dev/null 2>&1 || test ! -f "$ssh_recovery/tftpd-hpa.installed"; fi
SH;
        $tftpSetup = <<<'SH'
test ! -L /etc/default/tftpd-hpa
if test -f /etc/default/tftpd-hpa; then cp -p /etc/default/tftpd-hpa "$ssh_recovery/tftpd-hpa.before"; else touch "$ssh_recovery/tftpd-hpa.absent"; fi
if command -v in.tftpd >/dev/null 2>&1; then touch "$ssh_recovery/tftpd-hpa.installed"; fi
if systemctl is-active --quiet tftpd-hpa; then touch "$ssh_recovery/tftpd-hpa.active"; fi
if systemctl is-enabled --quiet tftpd-hpa; then touch "$ssh_recovery/tftpd-hpa.enabled"; fi
SH;
        $restoreTftpCommand = 'ssh_recovery="$ssh_recovery" sh -eu -c ' . escapeshellarg($restoreTftp) . "\n";
        $tftpSetup .= "\nrollback_ucmp_tftp() {\n" . $restoreTftpCommand . "}\n"
            . "trap 'rc=\$?; trap - EXIT HUP INT TERM; rollback_ucmp_tftp || echo TFTP_recovery_required >&2; rollback_ucmp_ssh || echo SSH_recovery_required >&2; exit \"\$rc\"' EXIT\n";
        // Each child retains errexit even when its parent collects both rollback results.
        $rollbackScript = "set -eu\nssh_root=/etc/ssh\nssh_recovery=" . escapeshellarg('/etc/ssh/ucmp-recovery-' . $sshTransaction['recovery_id'])
            . "\ntest ! -L \"\$ssh_root/.ucmp-enrollment.lock\"\nexec 8>\"\$ssh_root/.ucmp-enrollment.lock\"\nflock -n 8\nrollback_failed=0\n"
            . trim($restoreTftpCommand) . " || rollback_failed=1\n"
            . 'ssh_root="$ssh_root" ssh_recovery="$ssh_recovery" sh -eu -c ' . escapeshellarg($sshTransaction['restore'])
            . " || rollback_failed=1\nexit \"\$rollback_failed\"\n";
        $probeName = 'ucmp-pbx-tftp-probe-' . bin2hex(random_bytes(12));
        $script = "set -eu\n" .
            "if ss -H -lunp 'sport = :69' 2>/dev/null | grep -q . && ! systemctl is-active --quiet tftpd-hpa; then echo 'UDP/69 is already owned by a different TFTP service; refusing to replace it' >&2; exit 74; fi\n" .
            "test \"\$(systemctl is-enabled tftpd-hpa 2>/dev/null || true)\" != masked || { echo 'tftpd-hpa is masked; explicit administrator review is required' >&2; exit 74; }\n" .
            $sshTransaction['setup'] . "\n" . $tftpSetup . "\n" .
            $install .
            $this->tftpAccountSetupScript() .
            "if test -L /srv/tftp || test -L /srv/tftp/tftpboot; then echo 'refusing symlinked PBX TFTP root' >&2; exit 73; fi\n" .
            "install -d -o root -g root -m 0755 /srv/tftp\n" .
            "install -d -o ucmp-tftp -g ucmp-tftp -m 0755 /srv/tftp/tftpboot\n" .
            "test \"$(readlink -f /srv/tftp)\" = /srv/tftp\n" .
            "test \"$(readlink -f /srv/tftp/tftpboot)\" = /srv/tftp/tftpboot\n" .
            \FreePBX\modules\Ucmprovisioner\SshEnrollmentTransaction::authorizedKeysScript($existingAuthorizedKeys, $authorizedKeys) . "\n" .
            "if test -f /etc/default/tftpd-hpa && ! grep -q '^# ucmprovisioner managed PBX TFTP$' /etc/default/tftpd-hpa; then test -e /etc/default/tftpd-hpa.pre-ucmprovisioner || cp -a /etc/default/tftpd-hpa /etc/default/tftpd-hpa.pre-ucmprovisioner; fi\n" .
            "printf %s " . escapeshellarg(base64_encode($tftpdConfig . "\n")) . " | base64 -d > \"\$ssh_recovery/tftpd-hpa.candidate\"\n" .
            "install -o root -g root -m 0644 \"\$ssh_recovery/tftpd-hpa.candidate\" /etc/default/tftpd-hpa\n" .
            $sshTransaction['stage'] .
            "systemctl daemon-reload\n" .
            "systemctl enable tftpd-hpa >/dev/null\n" .
            "systemctl restart tftpd-hpa\n" .
            "systemctl is-active --quiet tftpd-hpa\n" .
            "ss -H -lun 'sport = :69' | grep -q .\n" .
            "probe_path=/srv/tftp/tftpboot/" . $probeName . "\n" .
            "printf '%s\\n' 'ucmprovisioner local TFTP retrieval probe' > \"\$probe_path\"\n" .
            "chmod 0644 \"\$probe_path\"\n" .
            "probe_file=\$(mktemp /tmp/ucmp-pbx-tftp.XXXXXXXX)\n" .
            "if timeout 8 tftp 127.0.0.1 -m binary -c get " . escapeshellarg($probeName) . " \"\$probe_file\" && cmp \"\$probe_path\" \"\$probe_file\"; then probe_ok=1; else probe_ok=0; fi\n" .
            "rm -f \"\$probe_path\" \"\$probe_file\"\n" .
            "test \"\$probe_ok\" = 1 || { echo 'local TFTP retrieval probe failed' >&2; exit 76; }\n" .
            $sshTransaction['finish'] .
            "echo 'tftpd-hpa active; UDP/69 listening; local retrieval passed; managed root /srv/tftp/tftpboot'\n";
        try {
            $output = $this->execPrivilegedScriptChecked($ssh, $script, trim($adminUser), $adminPass, $useSudo);
        } catch (\Throwable $e) {
            throw new \RuntimeException('PBX TFTP configuration failed: ' . $e->getMessage() . '; recovery reference ' . $sshTransaction['recovery_id'], 0, $e);
        }
        try {
            $candidate = array_replace($site, ['pbx_host_key'=>$hostKey]);
            $connection = new \FreePBX\modules\Ucmprovisioner\SiteConnection($candidate);
            $probe = \FreePBX\modules\Ucmprovisioner\DockerEnrollment::verifySftp($connection->tftpSftp('pbx'));
        } catch (\Throwable $verificationError) {
            $this->rollbackTftpEnrollment($ssh, $rollbackScript, $sshTransaction['recovery_id'], $adminUser, $adminPass, $useSudo, $verificationError);
        }

        $serviceHost = \FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($site, 'pbx');
        if (empty($site['is_local'])) {
            try {
            $adopted = $this->remoteLiveQuery($id, 'adoptPbxTftpEnrollment', [[
                'pbx_host'=>$host,
                'pbx_ssh_port'=>$port,
                'pbx_host_key'=>$hostKey,
                'pbx_tftp_service_host'=>$serviceHost,
            ]]);
            if (empty($adopted['ok'])) {
                throw new \RuntimeException((string)($adopted['message'] ?? 'Owning PBX rejected TFTP adoption'));
            }
            } catch (\Throwable $adoptionError) {
                $message = 'PBX TFTP is partially complete: local retrieval and controller SFTP passed, but owning-PBX adoption is uncertain. ' . substr($adoptionError->getMessage(), 0, 500);
                $this->logOperation($id, 'pbx-tftp-bootstrap', 'warning', $message, $output);
                return ['ok'=>false, 'partial'=>true, 'phase'=>'owner-adoption', 'recovery_id'=>$sshTransaction['recovery_id'], 'host_key'=>$hostKey, 'message'=>$message, 'detail'=>$output];
            }
        }
        $this->db->prepare(
            "UPDATE ucmp_sites SET pbx_host_key=?, pbx_tftp_service_host=?,
             pbx_tftp_root_path='/tftpboot', pbx_tftp_bind_path='/srv/tftp/tftpboot',
             pbx_tftp_daemon='tftpd-hpa', pbx_tftp_bootstrapped_at=? WHERE id=?"
        )->execute([$hostKey, $serviceHost, time(), $id]);
        unset($this->siteConnections[$id]);
        $this->logOperation($id, 'pbx-tftp-bootstrap', 'success', 'tftpd-hpa and jailed ucmp-tftp data plane verified', $output);
        return [
            'ok'=>true,
            'host_key'=>$hostKey,
            'entries'=>$probe['entries'],
            'recovery_id'=>$sshTransaction['recovery_id'],
            'message'=>'PBX-hosted TFTP passed local UDP retrieval and jailed SFTP write/read/delete from the controller and owning PBX. Phone-network retrieval is not yet verified.',
            'detail'=>$output,
        ];
    }

    /** A locked account needs a password hash for SSH, never password-based SFTP access. */
    private function tftpAccountSetupScript(): string
    {
        return <<<'SH'
set_account_password() { account_password=$(od -An -N32 -tx1 /dev/urandom | tr -d '[:space:]'); printf %s "$account_password" | grep -Eq '^[0-9a-f]{64}$'; printf '%s\n' "ucmp-tftp:$account_password" | chpasswd; unset account_password; }
if ! id -u ucmp-tftp >/dev/null 2>&1; then
  useradd -M -d /tftpboot -s /usr/sbin/nologin ucmp-tftp
  set_account_password
elif passwd -S ucmp-tftp 2>/dev/null | awk '$2 ~ /^L/ {found=1} END {exit !found}'; then
  set_account_password
fi
usermod -d /tftpboot -s /usr/sbin/nologin ucmp-tftp

SH;
    }

    /** Compensation preserves the failing phase and original bounded diagnostic. */
    private function rollbackTftpEnrollment(\phpseclib3\Net\SSH2 $ssh, string $rollback, string $recoveryId, string $adminUser, string $adminPass, bool $useSudo, \Throwable $cause): void
    {
        $message = 'SFTP write/read/delete post-check failed: ' . substr($cause->getMessage(), 0, 600);
        try {
            $this->execPrivilegedScriptChecked($ssh, $rollback, $adminUser, $adminPass, $useSudo);
        } catch (\Throwable $rollbackError) {
            throw new \RuntimeException($message . '; rollback did not complete: ' . substr($rollbackError->getMessage(), 0, 400) . '; retained recovery ' . $recoveryId, 0, $cause);
        }
        throw new \RuntimeException($message . '; previous service/SSH configuration restored; retained recovery ' . $recoveryId, 0, $cause);
    }

    /** Build a secret-free Docker metadata query for the existing-container path. */
    private function dockerEnrollmentMetadataScript(string $requested): string
    {
        $format = 'ID={{.Id}}' . "\t" .
            'NAME={{.Name}}' . "\t" .
            'RUNNING={{.State.Running}}' . "\t" .
            'NETWORK={{.HostConfig.NetworkMode}}' . "\t" .
            'BIND={{range .Mounts}}{{if and (eq .Type "bind") (eq .Source "/opt/tftp-server/tftpboot")}}{{.Source}}|{{.Destination}}|{{.RW}}{{end}}{{end}}' . "\t" .
            'TFTP={{if eq .HostConfig.NetworkMode "host"}}1{{else}}{{with (index .HostConfig.PortBindings "69/udp")}}{{with (index . 0)}}{{if eq .HostPort "69"}}1{{else}}0{{end}}{{end}}{{end}}{{end}}';
        $inspect = 'docker inspect --format=' . escapeshellarg($format) . ' ';
        if ($requested !== '') {
            $inspect .= escapeshellarg($requested) . "\n";
        } else {
            $inspect = "for container_id in \$(docker ps -q); do\n"
                . $inspect . '"$container_id"' . "\ndone\n";
        }
        return "set -eu\n"
            . "command -v docker >/dev/null 2>&1 || { echo 'Docker CLI is unavailable' >&2; exit 70; }\n"
            . "docker info >/dev/null 2>&1 || { echo 'Docker engine is unavailable' >&2; exit 71; }\n"
            . "test -d /opt/tftp-server && test ! -L /opt/tftp-server\n"
            . "test -d /opt/tftp-server/tftpboot && test ! -L /opt/tftp-server/tftpboot\n"
            . "test \"\$(readlink -f /opt/tftp-server)\" = /opt/tftp-server\n"
            . "test \"\$(readlink -f /opt/tftp-server/tftpboot)\" = /opt/tftp-server/tftpboot\n"
            . "printf '%s\\n' UCM_DOCKER_META=1\n"
            . $inspect;
    }

    /** Execute a remote read-only metadata query and select the saved/unique container. */
    private function dockerEnrollmentPreflight(\phpseclib3\Net\SSH2 $ssh, string $adminUser, string $adminPass, bool $useSudo, string $requested): array
    {
        $output = $this->execPrivilegedScriptChecked($ssh, $this->dockerEnrollmentMetadataScript($requested), $adminUser, $adminPass, $useSudo);
        $rows = \FreePBX\modules\Ucmprovisioner\DockerEnrollment::parseInspection($output);
        $selected = \FreePBX\modules\Ucmprovisioner\DockerEnrollment::selectContainer($rows, $requested);
        $selected['PREFLIGHT'] = $output;
        return $selected;
    }

    /** Read the old account key file before the SSH transaction replaces anything. */
    private function existingTftpAuthorizedKeys(\phpseclib3\Net\SSH2 $ssh, string $adminUser, string $adminPass, bool $useSudo): string
    {
        $output = $this->execPrivilegedScriptChecked($ssh, "set -eu\n"
            . "command -v runuser >/dev/null 2>&1 || { echo 'runuser is required to verify account access' >&2; exit 69; }\n"
            . "test ! -L /etc/ssh/authorized_keys && test ! -L /etc/ssh/authorized_keys/ucmp-tftp\n"
            . "test ! -e /etc/ssh/authorized_keys/ucmp-tftp || test -f /etc/ssh/authorized_keys/ucmp-tftp\n"
            . "printf '\\nUCM_AUTHORIZED_KEYS_B64='\n"
            . "if test -f /etc/ssh/authorized_keys/ucmp-tftp; then base64 -w 0 /etc/ssh/authorized_keys/ucmp-tftp; fi\n"
            . "printf '\\n'\n",
            $adminUser, $adminPass, $useSudo);
        return \FreePBX\modules\Ucmprovisioner\DockerEnrollment::decodeAuthorizedKeys($output);
    }

    /** Execute a bounded privileged script and require an explicit remote zero exit status. */
    private function execPrivilegedScriptChecked(\phpseclib3\Net\SSH2 $ssh, string $script, string $adminUser, string $adminPass, bool $useSudo): string
    {
        $output = $this->execPrivilegedScript($ssh, $script, $adminUser, $adminPass, $useSudo);
        $status = $ssh->getExitStatus();
        if ($status !== 0) {
            $stderr = trim((string)$ssh->getStdError());
            throw new \RuntimeException('Remote enrollment command failed with exit status '
                . ($status === false ? 'unknown' : (string)$status)
                . "\nStage: remote command execution after SSH administrator authentication.\n"
                . $this->safeOperationText(trim($output . ($stderr !== '' && !str_contains($output, $stderr) ? "\n" . $stderr : '')) ?: 'The remote command returned no output.', [$adminPass]));
        }
        return $output;
    }

    public function bootstrapDockerHost(int $id, string $host, int $port, string $rootPath, string $adminUser, string $adminPass, bool $useSudo, string $expectedHostKey = ''): array
    {
        if ($host === '' || $adminUser === '' || $adminPass === '') {
            throw new \Exception("Docker host, admin username, and password are required");
        }
        
        $targetSite = $this->getSite($id);
        if (!$targetSite) {
            throw new \InvalidArgumentException('Save the site before bootstrapping its Docker host');
        }
        if (trim((string)($targetSite['docker_host'] ?? '')) !== trim($host)
            || (int)($targetSite['docker_ssh_port'] ?: 22) !== ($port ?: 22)) {
            throw new \InvalidArgumentException('Docker enrollment host and port must match the saved site');
        }
        $rootPath = rtrim(trim($rootPath), '/');
        if (!in_array($rootPath, ['', '/tftpboot', '/opt/tftp-server/tftpboot'], true)) {
            throw new \InvalidArgumentException('The TFTP host bind path must be exactly /opt/tftp-server/tftpboot');
        }
        $hostDir = '/opt/tftp-server/tftpboot';
        $requestedContainer = trim((string)($targetSite['tftp_container'] ?? ''));

        $ssh = new \phpseclib3\Net\SSH2($host, $port ?: 22);
        try {
            $hostKeyBlob = $ssh->getServerPublicHostKey();
            $hostKey = $this->fingerprintHostKey((string)$hostKeyBlob);
        }
        catch (\Throwable $e) {
            throw new \RuntimeException("Docker SSH handshake failed for $host:" . ($port ?: 22)
                . "\nStage: SSH connection and host-key exchange. No administrator credentials were sent.\n"
                . $this->safeOperationText($e->getMessage(), [$adminPass]), 0, $e);
        }
        $trusted = trim((string)($targetSite['docker_host_key'] ?? '')) ?: trim($expectedHostKey);
        if (!preg_match('/^SHA256:[A-Za-z0-9+\\/_-]+$/D', $trusted) || !hash_equals($trusted, $hostKey)) {
            throw new \RuntimeException("Docker host key was not confirmed before login (observed $hostKey)");
        }
        $this->loginEnrollmentAdministrator($ssh, 'Docker', $host, $port ?: 22, $adminUser, $adminPass);

        // Read-only preflight rejects missing/ambiguous containers and an absent bind root.
        $container = $this->dockerEnrollmentPreflight($ssh, $adminUser, $adminPass, $useSudo, $requestedContainer);
        $existingAuthorizedKeys = $this->existingTftpAuthorizedKeys($ssh, $adminUser, $adminPass, $useSudo);
        $key = $this->ensureProvisionerKey();
        $keyPath = $key['private'];
        $pubKeyContent = trim((string)$key['public_key']);
        if ($pubKeyContent === '') {
            throw new \Exception('Local SSH public key is empty');
        }
        // Only the controller and this site's own PBX need write access to this TFTP tree.
        $allPubKeys = [$pubKeyContent];
        if (empty($targetSite['is_local'])) {
            // This is deliberately uncached: a saved pbx_agent_ready bit is not key proof.
            $remote = $this->remoteLiveQuery((int)$targetSite['id'], 'getProvisionerPublicKey');
            if (empty($remote['ok'])) {
                throw new \RuntimeException('The remote PBX could not prepare its Docker-access key: ' . (string)($remote['message'] ?? 'unknown remote error'));
            }
            $allPubKeys[] = $this->normalizeProvisionerPublicKey(
                trim((string)($remote['public_key'] ?? '')),
                'The remote PBX agent'
            );
        }
        $authorizedKeysContent = \FreePBX\modules\Ucmprovisioner\DockerEnrollment::mergeAuthorizedKeys($existingAuthorizedKeys, $allPubKeys);
        $sshTransaction=\FreePBX\modules\Ucmprovisioner\SshEnrollmentTransaction::scripts(bin2hex(random_bytes(12)), <<<'CONF'
# ucmprovisioner: least-privilege SFTP-only access to the TFTP volume
Match User ucmp-tftp
    AuthorizedKeysFile /etc/ssh/authorized_keys/%u
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthenticationMethods publickey
    ChrootDirectory /opt/tftp-server
    ForceCommand internal-sftp -d /tftpboot
    PermitTTY no
    AllowTcpForwarding no
    X11Forwarding no
    PermitTunnel no
CONF
        );
        $selectedId = (string)$container['ID'];
        $authorizedKeysHash = hash('sha256', $existingAuthorizedKeys);
        $script = "set -eu\n" .
            $sshTransaction['setup'] . "\n" .
            "expected_authorized_keys_hash=" . escapeshellarg($authorizedKeysHash) . "\n" .
            "if test -f /etc/ssh/authorized_keys/ucmp-tftp; then actual_authorized_keys_hash=\$(sha256sum /etc/ssh/authorized_keys/ucmp-tftp | awk '{print \$1}'); else actual_authorized_keys_hash=\$(printf %s '' | sha256sum | awk '{print \$1}'); fi\n" .
            "test \"\$actual_authorized_keys_hash\" = \"\$expected_authorized_keys_hash\" || { echo 'authorized_keys changed during preflight; refusing to overwrite concurrent enrollment' >&2; exit 74; }\n" .
            $this->tftpAccountSetupScript() .
            "chown root:root /opt/tftp-server\n" .
            "chmod 755 /opt/tftp-server\n" .
            \FreePBX\modules\Ucmprovisioner\SshEnrollmentTransaction::authorizedKeysScript($existingAuthorizedKeys, $authorizedKeysContent) . "\n" .
            "test -d " . escapeshellarg($hostDir) . " && test ! -L " . escapeshellarg($hostDir) . "\n" .
            "test \"$(readlink -f /opt/tftp-server)\" = /opt/tftp-server\n" .
            "test \"$(readlink -f " . escapeshellarg($hostDir) . ")\" = " . escapeshellarg($hostDir) . "\n" .
            "chown ucmp-tftp:ucmp-tftp " . escapeshellarg($hostDir) . "\n" .
            "chmod 775 " . escapeshellarg($hostDir) . "\n" .
            "test \"$(docker inspect --format='{{.State.Running}}' " . escapeshellarg($selectedId) . ")\" = true\n" .
            $sshTransaction['stage'] . $sshTransaction['finish'];
            
        try {
            $output = $this->execPrivilegedScriptChecked($ssh, $script, $adminUser, $adminPass, $useSudo);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Docker TFTP configuration failed: ' . $e->getMessage() . '; recovery reference ' . $sshTransaction['recovery_id'], 0, $e);
        }
        try {
        $testSsh = new \phpseclib3\Net\SFTP($host, $port ?: 22);
        $testHostKey = $this->fingerprintHostKey((string)$testSsh->getServerPublicHostKey());
        if (!hash_equals($hostKey, $testHostKey)) {
            throw new \RuntimeException('Docker host key changed before the post-enrollment SFTP verification login');
        }
        $privKey = \phpseclib3\Crypt\PublicKeyLoader::load((string)file_get_contents($keyPath));
        if (!$testSsh->login('ucmp-tftp', $privKey)) {
            throw new \Exception('Bootstrap complete, but key-based login failed for ucmp-tftp');
        }
        \FreePBX\modules\Ucmprovisioner\DockerEnrollment::verifySftp($testSsh);
        } catch (\Throwable $verificationError) {
            try {
                $this->execPrivilegedScriptChecked($ssh,$sshTransaction['rollback'],$adminUser,$adminPass,$useSudo);
            } catch (\Throwable $rollbackError) {
                throw new \RuntimeException('SFTP post-check failed: ' . substr($verificationError->getMessage(), 0, 600) . '; SSH rollback failed: ' . substr($rollbackError->getMessage(), 0, 400) . '; retained recovery ' . $sshTransaction['recovery_id'],0,$verificationError);
            }
            throw new \RuntimeException('SFTP post-check failed: ' . substr($verificationError->getMessage(), 0, 600) . '; previous SSH policy restored; retained recovery ' . $sshTransaction['recovery_id'],0,$verificationError);
        }

        $containerName = ltrim((string)($container['NAME'] ?? ''), '/');
        if (empty($targetSite['is_local'])) {
            try {
                $adopted = $this->remoteLiveQuery((int)$targetSite['id'], 'adoptDockerEnrollment', [[
                    'docker_host' => $host,
                    'docker_ssh_port' => $port ?: 22,
                    'docker_host_key' => $hostKey,
                    'tftp_bind_path' => $hostDir,
                    'tftp_container' => $containerName,
                    'tftp_service_host' => \FreePBX\modules\Ucmprovisioner\SiteConnection::resolvedTftpHost($targetSite, 'docker'),
                ]]);
            } catch (\Throwable $adoptionError) {
                $message = 'Docker enrollment is partially complete: controller SFTP passed, but owning-PBX metadata adoption is uncertain; SSH recovery ' . $sshTransaction['recovery_id'] . ' is retained. ' . $adoptionError->getMessage();
                $this->logOperation($id, 'tftp-enrollment', 'warning', $message, $output);
                return ['ok'=>false, 'partial'=>true, 'recovery_id'=>$sshTransaction['recovery_id'], 'host_key'=>$hostKey, 'tftp_container'=>$containerName, 'message'=>$message, 'detail'=>$output];
            }
            if (empty($adopted['ok'])) {
                $message = 'Docker enrollment is partially complete: controller SFTP passed, but the owning PBX rejected metadata adoption; SSH recovery ' . $sshTransaction['recovery_id'] . ' is retained. ' . (string)($adopted['message'] ?? 'unknown error');
                $this->logOperation($id, 'tftp-enrollment', 'warning', $message, $output);
                return ['ok'=>false, 'partial'=>true, 'recovery_id'=>$sshTransaction['recovery_id'], 'host_key'=>$hostKey, 'tftp_container'=>$containerName, 'message'=>$message, 'detail'=>$output];
            }
        }
        $this->db->prepare("UPDATE ucmp_sites SET docker_ssh_user='ucmp-tftp', docker_ssh_secret=NULL, docker_host_key=?, tftp_root_path='/tftpboot', tftp_bind_path=?, tftp_container=?, docker_bootstrapped_at=? WHERE id=?")
            ->execute([$hostKey, $hostDir, $containerName, time(), $id]);
        $this->resetSiteConnection($id);
        $this->logOperation($id, 'tftp-enrollment', 'success', 'ucmp-tftp enrolled; key authentication, jailed SFTP write/read/delete, and existing-container preflight passed', $output);
        
        return [
            'ok' => true,
            'message' => 'Existing Docker container ' . $containerName . ' is enrolled: controller and owning-PBX keys are merged, jailed SFTP write/read/delete passed.',
            'recovery_id'=>$sshTransaction['recovery_id'],
            'host_key' => $hostKey,
            'tftp_container'=>$containerName,
            'detail' => $output
        ];
    }
}
