<?php
declare(strict_types=1);

namespace FreePBX\modules\Ucmprovisioner;

/** One request boundary for page, assets, data and workbook responses. */
final class WebPhonebookHttp
{
    public static function run(WebPhonebook $webBook): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        
        $fail = static function (int $status, string $message): never {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            exit;
        };
        try {
            $policy = $webBook->policy();
            if (!WebPhonebook::peerAllowed($policy, $_SERVER)) { $fail(403, 'Web phonebook access is not enabled for this network.'); }
            if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') { $fail(403, 'Open the phonebook directly from its internal address.'); }
            $action = $_GET['action'] ?? 'page';
            if (!is_string($action) || !in_array($action, ['page','css','js','favicon','data','export'], true)) { $fail(400, 'Unknown phonebook action.'); }
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($method !== ($action === 'export' ? 'POST' : 'GET')) {
                header('Allow: ' . ($action === 'export' ? 'POST' : 'GET')); $fail(405, 'Unsupported request method.');
            }
            if ($action === 'css' || $action === 'js') {
                header('Content-Type: ' . ($action === 'css' ? 'text/css' : 'text/javascript') . '; charset=UTF-8');
                readfile(dirname(__DIR__) . '/assets/' . $action . '/web-phonebook.' . $action);
                exit;
            }
            if ($action === 'page') {
                define('UCMP_WEB_PHONEBOOK_PAGE', true);
                require dirname(__DIR__) . '/views/web_phonebook.php';
                exit;
            }
            if ($action === 'favicon') {
                if (empty($policy['favicon'])) { $fail(404, 'No custom favicon is configured.'); }
                header('Content-Type: image/png');
                echo base64_decode($policy['favicon'], true);
                exit;
            }
            $request = [];
            if ($action === 'export') {
                if (empty($policy['export_enabled'])) { $fail(403, 'Excel export is disabled by policy.'); }
                if (strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
                    $fail(415, 'Use a JSON export request.');
                }
                $body = file_get_contents('php://input', false, null, 0, 1048577);
                if (!is_string($body) || strlen($body) > 1048576) { $fail(413, 'The export request is too large.'); }
                $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($request)) { $fail(400, 'Invalid export request.'); }
            }
            $key = $action === 'export' ? ($request['book'] ?? '') : ($_GET['book'] ?? '');
            if (!is_string($key) || ($key !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $key))) { $fail(400, 'Invalid phonebook.'); }
            $model = $webBook->model($policy, $key);
            if ($action === 'data') {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                exit;
            }
            $selection = WebPhonebook::exportSelection($model, $request);
            $bytes = PhonebookWorkbook::create($selection['rows'], $selection['columns'], $model['fields'], [
                'Phonebook'=>$model['title'], 'Policy'=>$model['policy_label'], 'Revision'=>$model['revision'],
                'Accepted source time'=>$model['accepted_at'] ? gmdate('c',$model['accepted_at']) : 'Unknown',
                'Snapshot generated'=>gmdate('c',$model['generated']), 'Exported at'=>gmdate('c'), 'Scope'=>$request['scope'] ?? '',
            ]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="phonebook-' . $model['book'] . '-' . gmdate('Ymd') . '.xlsx"');
            header('Content-Length: ' . strlen($bytes));
            echo $bytes;
        } catch (\OutOfBoundsException $e) {
            $fail(404, $e->getMessage());
        } catch (\UnexpectedValueException $e) {
            $fail(409, $e->getMessage());
        } catch (\InvalidArgumentException | \JsonException $e) {
            $fail(400, 'Invalid phonebook data or request.');
        } catch (\Throwable $e) {
            $fail(503, 'The published phonebook is temporarily unavailable. Ask the administrator to check publication.');
        }
    }
}
