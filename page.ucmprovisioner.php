<?php
/**
 * UCM Provisioner — page controller. Renders a tabbed UI and includes the
 * active view. Invoked by FreePBX when displaying the module page.
 */
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

/** @var \FreePBX\modules\Ucmprovisioner $ucm */
$ucm = \FreePBX::Ucmprovisioner();

$tabs = [
    'dashboard' => _('Dashboard'),
    'phones'    => _('Phones'),
    'templates' => _('Templates'),
    'groups'    => _('Groups'),
    'sites'     => _('Sites'),
    'directory' => _('Directory & AD'),
    'patches'   => _('Patch Manager'),
    'media'     => _('Media'),
    'firmware'  => _('Firmware'),
    'services'  => _('Phone Services'),
    'fail2ban'  => _('Fail2ban'),
    'tftp'      => _('TFTP Browser'),
    'settings'  => _('Settings'),
];
$requestedTab = $_REQUEST['tab'] ?? 'dashboard';
$active = is_string($requestedTab) ? $requestedTab : 'dashboard';
$offlinePage = false;
if (!isset($tabs[$active])) {
    $active = 'dashboard';
}
$ucmpCsrfToken = $ucm->pageCsrfToken();
$ucmpCssFile = __DIR__ . '/assets/css/ucmprovisioner.css';
$ucmpCssVersion = is_file($ucmpCssFile)
    ? substr((string)hash_file('sha256', $ucmpCssFile), 0, 16)
    : 'missing';
?>
<link rel="stylesheet" href="assets/ucmprovisioner/css/ucmprovisioner.css?v=<?php echo rawurlencode($ucmpCssVersion); ?>">
<script>
(function () {
    var pageBody = document.getElementById('page_body');
    if (pageBody) { pageBody.classList.add('ucmp-page-body'); }
})();
</script>
<div class="container-fluid ucmp-module-shell">
  <h1><?php echo _('UCM Provisioner'); ?></h1>
  <ul class="nav nav-tabs" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
      <li role="presentation" class="<?php echo $active === $key ? 'active' : ''; ?>">
        <a href="config.php?display=ucmprovisioner&amp;tab=<?php echo $key; ?><?php echo $key === 'settings' ? '&amp;view=form' : ''; ?>"><?php echo $label; ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="tab-content" style="padding-top:15px;">
    <script>
    (function($, token) {
        function isFormData(value) {
            return !!(window.FormData && value instanceof window.FormData);
        }

        /*
         * One transport for every module AJAX call.  It is deliberately
         * module-scoped: other FreePBX modules keep their own HTTP semantics.
         */
        window.ucmpAjax = function(options) {
            options = $.extend({}, options || {});
            options.url = options.url || 'ajax.php';
            options.type = options.method = 'POST';
            options.dataType = options.dataType || 'json';
            options.headers = $.extend({}, options.headers || {}, {
                'X-UCM-Provisioner-CSRF': token
            });
            if (isFormData(options.data)) {
                options.processData = false;
                options.contentType = false;
                options.data.append('module', 'ucmprovisioner');
                options.data.append('ucmp_csrf', token);
            } else {
                options.data = $.extend({}, options.data || {}, {
                    module: 'ucmprovisioner',
                    ucmp_csrf: token
                });
            }
            return $.ajax(options);
        };

        $.ajaxPrefilter(function(options) {
            var url = String(options.url || '');
            var data = options.data;
            var moduleName = '';
            if (isFormData(data)) {
                moduleName = data.get ? String(data.get('module') || '') : '';
            } else if (typeof data === 'string') {
                var match = data.match(/(?:^|&)module=([^&]*)/);
                moduleName = match ? decodeURIComponent(match[1].replace(/\+/g, ' ')) : '';
            } else if (data && typeof data === 'object') {
                moduleName = String(data.module || '');
            }
            if (url.indexOf('ajax.php') === -1 || moduleName !== 'ucmprovisioner') {
                return;
            }
            // The BMO intentionally rejects GET. Keep this as a final transport
            // boundary so a future jQuery shorthand cannot bypass that contract.
            options.type = options.method = 'POST';
            if (isFormData(options.data)) {
                options.processData = false;
                options.contentType = false;
                if (!options.data.has || !options.data.has('ucmp_csrf')) {
                    options.data.append('ucmp_csrf', token);
                }
            } else if (typeof options.data === 'string') {
                if (!/(?:^|&)ucmp_csrf=/.test(options.data)) {
                    options.data += (options.data ? '&' : '') + 'ucmp_csrf=' + encodeURIComponent(token);
                }
            } else {
                options.data = $.extend({}, options.data || {}, {ucmp_csrf: token});
            }
        });
    })(jQuery, <?php echo json_encode($ucmpCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
    </script>
    <?php
    $viewFile = __DIR__ . '/views/' . $active . '.php';
    ob_start();
    if (is_file($viewFile)) {
        include $viewFile;
    } else {
        echo '<div class="alert alert-info">' . sprintf(_('The "%s" view is under construction.'), htmlspecialchars($active)) . '</div>';
    }
    $viewHtml = (string)ob_get_clean();
    $offlineReads = array_values(array_filter(
        $ucm->getSiteReadStates(),
        static fn(array $state): bool => !empty($state['offline'])
    ));
    $offlinePage = (bool)$offlineReads;
    if ($offlineReads) {
        $bySite = [];
        foreach ($offlineReads as $state) {
            $bySite[(int)$state['site_id']][] = $state;
        }
        echo '<div class="alert alert-warning ucm-site-offline-banner" role="status"><strong>'
            . _('One or more sites are unreachable.') . '</strong><ul>';
        foreach ($bySite as $states) {
            $sample = $states[0];
            $cached = array_values(array_filter($states, static fn(array $state): bool => ($state['source'] ?? '') === 'cache'));
            if ($cached) {
                $oldest = min(array_map(static fn(array $state): int => (int)($state['fetched_at'] ?? 0), $cached));
                $when = $oldest > 0 ? date('Y-m-d H:i:s', $oldest) : _('unknown time');
                $message = sprintf(
                    _('%s is offline. This page is showing its last-known-good read-only state captured at %s; saves and actions still require a live connection.'),
                    (string)$sample['site_name'],
                    $when
                );
            } else {
                $message = sprintf(
                    _('%s is offline and no snapshot exists for one or more parts of this page. Empty sections are not proof that the site has no data.'),
                    (string)$sample['site_name']
                );
            }
            $errors = array_values(array_unique(array_filter(array_map(
                static fn(array $state): string => trim((string)($state['error'] ?? '')),
                $states
            ))));
            if ($errors) {
                $message .= ' ' . sprintf(_('Last connection error: %s'), $errors[0]);
            }
            echo '<li>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul></div>';
    }
    $specializedVisualTabs = ['media','phones','templates','firmware'];
    if (!in_array($active, $specializedVisualTabs, true)) {
        echo '<div id="ucmp-admin-v3-' . htmlspecialchars($active, ENT_QUOTES, 'UTF-8') . '" class="ucmp-admin-v3" data-admin-page="' . htmlspecialchars($active, ENT_QUOTES, 'UTF-8') . '">' . $viewHtml . '</div>';
    } else {
        echo $viewHtml;
    }
    ?>
  </div>
</div>

<aside id="ucmp-replication-activity" class="ucmp-replication-activity" aria-live="polite" aria-label="<?php echo htmlspecialchars(_('Replication activity'), ENT_QUOTES, 'UTF-8'); ?>">
  <button type="button" class="ucmp-replication-activity-toggle" aria-expanded="false">
    <span><i class="fa fa-exchange"></i> <?php echo _('Replication activity'); ?></span>
    <span class="ucmp-replication-activity-count"><?php echo _('Checking…'); ?></span>
  </button>
  <div class="ucmp-replication-activity-body" hidden>
    <div class="ucmp-replication-activity-list"><p class="text-muted"><?php echo _('Loading controller-side transfer status…'); ?></p></div>
    <p class="help-block"><?php echo _('Each transfer is tracked independently. Active server work continues when this page is reloaded or another module tab is opened.'); ?></p>
  </div>
</aside>

<script>
jQuery(function($) {
    var offlineSnapshot = <?php echo $offlinePage ? 'true' : 'false'; ?>;
    var pageCsrf = <?php echo json_encode($ucmpCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    $('.ucmp-admin-v3').each(function() {
        var page = $(this), cards = page.find('section.ucm-card').filter(function() {
            return $(this).parents('section.ucm-card').length === 0;
        });
        if (cards.length < 2 || page.children('.ucmp-section-nav').length) { return; }
        var nav = $('<nav class="ucmp-section-nav" aria-label="Page sections">');
        cards.each(function(index) {
            var card = $(this), heading = $.trim(card.find('> .ucm-card-heading h3, > h3').first().text());
            if (!heading) { return; }
            var id = card.attr('id') || ('ucmp-' + page.data('admin-page') + '-section-' + (index + 1));
            card.attr('id', id).addClass('ucmp-section-target');
            $('<button type="button">').text(heading).on('click', function() {
                card.get(0).scrollIntoView({behavior:'smooth', block:'start'});
            }).appendTo(nav);
        });
        if (nav.children().length > 1) {
            var intro = page.find('> .ucm-container > .ucm-page-intro, > .ucm-page-intro').first();
            if (intro.length) { nav.insertAfter(intro); } else { page.prepend(nav); }
        }
    });
    window.ucmpPostAction = function(url) {
        var parsed = new URL(url, window.location.href);
        var route = new URL(parsed.pathname, window.location.origin);
        ['display', 'tab'].forEach(function(name) {
            var value = parsed.searchParams.get(name);
            if (value !== null) { route.searchParams.set(name, value); }
        });
        var form = $('<form>', {method: 'post', action: route.pathname + route.search});
        parsed.searchParams.forEach(function(value, name) {
            if (name === 'display' || name === 'tab') { return; }
            form.append($('<input>', {type: 'hidden', name: name, value: value}));
        });
        form.append($('<input>', {type: 'hidden', name: 'ucmp_csrf', value: pageCsrf}));
        if (parsed.searchParams.get('action') === 'delphone') {
            form.append($('<input>', {type:'hidden',name:'expected_binding',value:$('[name="expected_binding"]').val() || ''}));
        }
        $('body').append(form);
        form.trigger('submit');
    };
    $('.tab-content form').filter(function() {
        return String(this.method || '').toLowerCase() === 'post';
    }).each(function() {
        if (!this.elements.ucmp_csrf) {
            $('<input>', {type: 'hidden', name: 'ucmp_csrf', value: pageCsrf}).appendTo(this);
        }
    });
    $(document).on('click', '.tab-content a[href*="action=del"],.tab-content a[href*="action=dup"]', function(e) {
        if (e.isDefaultPrevented()) { return; }
        e.preventDefault();
        window.ucmpPostAction(this.href);
    });
    if (offlineSnapshot) {
        $('.tab-content form').has('input[name="action"]').find(':input').prop('disabled', true);
        $('.tab-content .ucm-rebuild,.tab-content .ucm-cmd,.tab-content .ucm-action,.tab-content .ucm-restart-selected,.tab-content .ucm-delete-selected')
            .prop('disabled', true).addClass('disabled').attr('title', 'This site is offline; cached state is read-only.');
        $('#action-bar #ucm-delete,#action-bar #submit')
            .prop('disabled', true).addClass('disabled').attr('title', 'This site is offline; cached state is read-only.');
        $('.tab-content a[href*="action=del"],.tab-content a[href*="action=save"]')
            .addClass('disabled').attr('aria-disabled', 'true').on('click', function(e){ e.preventDefault(); });
    }
    // Intercept action-bar submit button click to handle form submission securely
    $(document).on('click', '#action-bar input[name="submit"], #action-bar button[name="submit"], #submit', function(e) {
        var $form = $('form.fpbx-submit');
        if ($form.length && !$form.data('submitting')) {
            e.preventDefault();
            if (!$form.get(0).reportValidity()) { return; }
            var activeTab = <?php echo json_encode($active); ?>;
            var action = $form.attr('action') || '';
            if (action && action.indexOf('tab=') === -1) {
                action = action + (action.indexOf('?') === -1 ? '?' : '&') + 'tab=' + activeTab;
            }
            $form.attr('action', action);
            $form.data('submitting', true);
            var submitEvent = $.Event('submit');
            try { $form.trigger(submitEvent); }
            finally { if (submitEvent.isDefaultPrevented()) { $form.removeData('submitting'); } }
        }
    });

    window.ucmpReplicationResults = function(result) {
        var box=$('<div class="ucmp-destination-results">'),rows=result.destination_results||[];
        if(result.destination_summary)box.append($('<strong>').text(result.destination_summary));
        rows.forEach(function(row){
            var status={success:'Succeeded',error:'Failed',partial:'Partially completed',unknown:'Needs review',running:'In progress'}[row.status]||'Not completed';
            var details=$('<details>').prop('open',row.status!=='success'),list=$('<ul>');
            details.append($('<summary>').text((row.name||('Site #'+row.site_id))+' — '+status));
            (row.details||[]).forEach(function(item){
                var prefix=item.scope?item.scope+': ':'',info=item.message||'No completion recorded';
                if(item.transport)info+=' · '+item.transport.toUpperCase();
                if(item.copied||item.skipped)info+=' · '+Number(item.copied||0)+' copied, '+Number(item.skipped||0)+' unchanged';
                if(item.recovery_required)info+=' · Review retained transaction before retrying';
                if(item.staging_incomplete)info+=' · Private staging incomplete; files not published'+(item.staged_bytes?' · '+Number(item.staged_bytes)+' bytes acknowledged':'');
                list.append($('<li>').text(prefix+info));
            });
            box.append(details.append(list));
        });
        if(!rows.length)box.append($('<p class="text-muted">').text('No per-site completion was recorded.'));
        return box;
    };

    (function replicationActivity() {
        var panel = $('#ucmp-replication-activity'), body = panel.find('.ucmp-replication-activity-body'),
            list = panel.find('.ucmp-replication-activity-list'), count = panel.find('.ucmp-replication-activity-count'),
            timer = null, active = 0, loaded = false, pending = false;
        // Polling updates status; only the operator controls panel visibility.
        try { body.prop('hidden', window.sessionStorage.getItem('ucmp-replication-panel-open') !== '1'); } catch (ignored) {}
        panel.find('.ucmp-replication-activity-toggle').attr('aria-expanded', body.prop('hidden') ? 'false' : 'true');
        function elapsed(row) {
            var start = Number(row.started || row.created || 0), end = Number(row.finished || 0) || Math.floor(Date.now() / 1000), seconds = Math.max(0, end - start);
            if (seconds < 60) { return seconds + 's'; }
            if (seconds < 3600) { return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's'; }
            return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
        }
        function render(rows) {
            loaded = true; active = 0; list.empty();
            (rows || []).forEach(function(row) {
                var running = String(row.status || '') === 'running'; if (running) { active++; }
                var total = Number(row.total_units || 0), complete = Number(row.completed_units || 0), percent = total > 0 ? Math.min(100, Math.round(complete * 100 / total)) : 0;
                // Multi-package work has no reliable overall percentage until its
                // manifest is complete; show activity plus actual stage/byte text.
                var target = (row.target_site_ids || []).length ? (' → site ' + row.target_site_ids.join(', ')) : '';
                var status = $('<span class="ucmp-replication-run-status">').addClass('is-' + String(row.status || 'unknown').replace(/[^a-z0-9_-]/gi, '-')).text(String(row.status || 'unknown').replace(/_/g, ' '));
                var plan = row.plan, wholeJob = null;
                if (plan) {
                    var mb = function(bytes) { return (Number(bytes || 0) / 1000000).toFixed(1) + ' MB'; };
                    wholeJob = $('<span class="ucmp-replication-run-message">').text(
                        'Whole selection: ' + Number(plan.firmware_packages || 0) + ' firmware, ' + Number(plan.locale_packages || 0) + ' locale, ' + Number(plan.timezone_packages || 0) + ' timezone packages · ' +
                        mb(plan.selected_bytes_all_sites) + ' across ' + Number(plan.destinations || 0) + ' sites before identical-file skips' + (plan.sizes_complete ? '' : ' (some sizes unavailable)') +
                        ' · Sent so far: ' + mb(row.bytes_sent) + ' · Already identical: ' + mb(row.bytes_unchanged));
                }
                var bar = $('<span class="ucmp-replication-run-bar"><span></span></span>'); bar.find('span').css('width', (running ? 100 : percent) + '%').toggleClass('is-running', running); bar.attr('aria-label',running?'Transfer in progress; see current stage and byte counts':'Transfer finished');
                list.append($('<article class="ucmp-replication-run">').toggleClass('is-running', running).append(
                    $('<div class="ucmp-replication-run-heading">').append($('<strong>').text(row.label || 'Replication'), status),
                    $('<small>').text('Source site ' + Number(row.source_site_id || 0) + target + ' · ' + elapsed(row)),
                    wholeJob,
                    bar,
                    $('<span class="ucmp-replication-run-item">').text(row.current_item || ''),
                    $('<span class="ucmp-replication-run-message">').text(row.message || ''),
                    row.destination_results ? window.ucmpReplicationResults(row) : null
                ));
            });
            if (!list.children().length) { list.append($('<p class="text-muted">').text('No replication has run in the last 24 hours.')); }
            count.text(active ? (active + ' active') : 'No active transfer').toggleClass('is-active', active > 0);
        }
        function poll() {
            window.clearTimeout(timer);
            if (pending) { return; }
            if (document.hidden) { return; }
            pending = true;
            window.ucmpAjax({data:{command:'replicationactivity',limit:20}}).done(function(resp) {
                var result = resp && resp.result || {};
                if (resp && resp.status && result.ok) { render(result.runs || []); }
                else if (!loaded) { count.text('Status unavailable'); }
            }).fail(function() { if (!loaded) { count.text('Status unavailable'); } }).always(function() {
                pending = false;
                if (active || !body.prop('hidden')) { timer = window.setTimeout(poll, active ? 3000 : 15000); }
            });
        }
        panel.find('.ucmp-replication-activity-toggle').on('click', function() {
            var open = body.prop('hidden'); body.prop('hidden', !open); $(this).attr('aria-expanded', open ? 'true' : 'false');
            try { window.sessionStorage.setItem('ucmp-replication-panel-open', open ? '1' : '0'); } catch (ignored) {}
            if (open) { poll(); } else if (!active) { window.clearTimeout(timer); }
        });
        $(document).on('visibilitychange.ucmpActivity', function() { if (!document.hidden && (active || !loaded || !body.prop('hidden'))) { poll(); } });
        window.ucmpReplicationActivityRefresh = poll;
        poll();
    })();
});
</script>
