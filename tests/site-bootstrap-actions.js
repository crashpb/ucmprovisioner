'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../assets/js/ucmprovisioner.js'), 'utf8');
const sites = fs.readFileSync(path.join(__dirname, '../views/sites.php'), 'utf8');
new vm.Script(source);

assert.match(sites, /Check version/);
assert.match(sites, /Install \/ update/);
assert.doesNotMatch(sites, /id="ucm-pbx-bootstrap"|id="ucm-api-enable"|id="ucm-api-disable"/);
assert.equal((sites.match(/name="pbx_host"/g) || []).length, 1);
assert.match(sites, /existing compatible running TFTP container/);
assert.match(sites, /It does not create a container, image, bind mount or TFTP tree/);
assert.match(sites, /\$rowApiReady=!empty\(\$site\['pbx_api_enabled'\]\) && !empty\(\$site\['pbx_api_ready'\]\)/);
assert.doesNotMatch(sites, /id="ucm-pbx-bootstrap"[^>]*pbxControlReady/);
assert.doesNotMatch(sites, /id="ucm-remote-install"[^>]*pbxControlReady/);
assert.match(source, /api_scheme: \$\('#ucm-api-scheme'\)\.val\(\)/);
assert.match(source, /api_host: \$\('#ucm-api-host'\)\.val\(\)/);
assert.match(source, /api_port: \$\('#ucm-api-port'\)\.val\(\)/);
assert.match(source, /api_verify_tls: \$\('#ucm-api-verify'\)\.val\(\)/);
assert.match(source, /Apply the displayed connection settings/);
assert.match(sites, /resolvedTftpHost\(\$site, 'docker'\)/);
assert.match(sites, /resolvedTftpHost\(\$site, 'pbx'\)/);
assert.match(sites, /id="ucm-docker-tftp-service-host"[\s\S]*?data-tftp-automatic/);
assert.match(sites, /id="ucm-pbx-tftp-service-host"[\s\S]*?data-tftp-automatic/);
assert.match(source, /tftp_service_host: \$\.trim\(\$\('\[name="tftp_service_host"\]'\)\.val\(\) \|\| ''\)/);
assert.match(source, /pbx_tftp_service_host: \$\.trim\(\$\('\[name="pbx_tftp_service_host"\]'\)\.val\(\) \|\| ''\)/);
assert.match(sites, /id="ucm-operation-log-panel" data-site-id/);
assert.match(sites, /id="ucm-operation-log-refresh"[^>]*data-site-id/);
assert.match(sites, /data-log-id=/);
assert.match(sites, /No additional output was recorded for this operation/);
assert.match(source, /command: 'operationlogs', id: siteId/);
assert.match(source, /siteOperationLogRefreshPending/);
assert.match(source, /body\.detail \|\| result\.detail/);
assert.match(sites, /id="ucm-docker-admin-user" value=""[^>]*section-ucm-docker-admin/);
assert.match(sites, /Docker host administrator/);
assert.match(sites, /may differ from the PBX administrator/);
assert.match(sites, /section-ucm-pbx-admin username/);

function extractFunction(name) {
  const start = source.indexOf('  function ' + name + '(');
  assert.ok(start >= 0, name + ' exists');
  const brace = source.indexOf('{', start);
  let depth = 0, quote = '', escaped = false;
  for (let i = brace; i < source.length; i++) {
    const c = source[i];
    if (quote) {
      if (escaped) { escaped = false; }
      else if (c === '\\') { escaped = true; }
      else if (c === quote) { quote = ''; }
      continue;
    }
    if (c === '"' || c === "'") { quote = c; continue; }
    if (c === '{') { depth++; }
    if (c === '}' && --depth === 0) { return source.slice(start, i + 1); }
  }
  throw new Error('Could not extract ' + name);
}

function deferred() {
  let state = 'pending', value;
  const done = [], failed = [], completed = [];
  const request = {
    done(fn) { if (state === 'resolved') { fn(value); } else if (state === 'pending') { done.push(fn); } return this; },
    fail(fn) { if (state === 'rejected') { fn(value); } else if (state === 'pending') { failed.push(fn); } return this; },
    always(fn) { if (state !== 'pending') { fn(value); } else { completed.push(fn); } return this; },
    resolve(result) {
      if (state !== 'pending') { return this; }
      state = 'resolved'; value = result;
      done.slice().forEach(fn => fn(result));
      completed.slice().forEach(fn => fn(result));
      return this;
    },
    reject(error) {
      if (state !== 'pending') { return this; }
      state = 'rejected'; value = error;
      failed.slice().forEach(fn => fn(error));
      completed.slice().forEach(fn => fn(error));
      return this;
    }
  };
  return request;
}

class Node {
  constructor(name, card) {
    this.name = name;
    this.card = card || this;
    this.length = 1;
    this.value = '';
    this.textValue = '';
    this.disabled = false;
    this.classes = new Set();
    this.dataValues = {id: 42};
    this.children = [];
  }
  val(value) { if (value === undefined) { return this.value; } this.value = String(value); return this; }
  prop(name, value) { if (value === undefined) { return this[name]; } this[name] = value; return this; }
  text(value) {
    if (value === undefined) { return this.textValue; }
    this.textValue = String(value);
    this.children = [];
    return this;
  }
  empty() { this.textValue = ''; this.children = []; return this; }
  remove() { return this; }
  append(child) {
    this.children.push(child);
    this.textValue += child && typeof child.text === 'function' ? child.text() : String(child || '');
    return this;
  }
  addClass(classes) { String(classes || '').split(/\s+/).filter(Boolean).forEach(item => this.classes.add(item)); return this; }
  removeClass(classes) { String(classes || '').split(/\s+/).filter(Boolean).forEach(item => this.classes.delete(item)); return this; }
  closest() { return this.card; }
  find() { return {length: 1, first: () => this.card.feedback}; }
  data(name, value) { if (value === undefined) { return this.dataValues[name]; } this.dataValues[name] = value; return this; }
  removeData(name) { delete this.dataValues[name]; return this; }
}

function harness({pinned = '', confirm = true} = {}) {
  const result = new Node('#ucm-bootstrap-result');
  const feedback = new Node('.ucm-inline-action-feedback');
  const card = {find: () => ({length: 1, first: () => feedback})};
  const button = new Node('#ucm-button', card);
  const pin = new Node('#ucm-pbx-host-key');
  const password = new Node('#ucm-pbx-admin-pass');
  pin.val(pinned).data('host', 'pbx.example').data('port', '22');
  const nodes = new Map([
    ['#ucm-bootstrap-result', result],
    ['#ucm-pbx-host-key', pin],
    ['#ucm-docker-host-key', new Node('#ucm-docker-host-key')],
    ['#ucm-pbx-admin-pass', password]
  ]);
  const requests = [];
  const confirmations = [];
  const operationLogRefreshes = [];
  function $(selector) {
    if (selector instanceof Node) { return selector; }
    if (String(selector).charAt(0) === '<') { return new Node(selector); }
    if (String(selector).indexOf(',') !== -1) {
      const items = String(selector).split(',').map(item => $(item.trim()));
      return {length: items.length, prop(name, value) { items.forEach(item => item.prop(name, value)); return this; }};
    }
    if (!nodes.has(selector)) { nodes.set(selector, new Node(selector)); }
    return nodes.get(selector);
  }
  $.trim = value => String(value == null ? '' : value).trim();
  $('#ucm-pbx-host').val('pbx.example');
  $('#ucm-pbx-port').val('22');
  $('#ucm-docker-host').val('docker.example');
  $('#ucm-api-scheme').val('http');
  $('#ucm-api-verify').val('1');
  const context = {
    $, operationLogRefresh(force) { operationLogRefreshes.push(force); }, window: {
      confirm(message) { confirmations.push(message); return confirm; },
      ucmpAjax(options) { const request = deferred(); requests.push({data: options.data, request}); return request; }
    }
  };
  vm.createContext(context);
  for (const name of ['bootstrapFeedback', 'bootstrapComponentReady', 'bootstrapAgentResultKnown', 'siteConnectionDraft', 'syncTftpAddressDefault', 'renderModuleVersion', 'bootstrapRequest', 'applyPbxBootstrapResult', 'withConfirmedHostKey']) {
    vm.runInContext(extractFunction(name), context);
  }
  return {context, button, pin, password, result, feedback, requests, confirmations, operationLogRefreshes, node: selector => $(selector)};
}

// TFTP address defaults follow their provider while automatic and preserve a deliberate override.
{
  const h = harness();
  const docker = h.node('[name="tftp_service_host"]');
  const pbx = h.node('[name="pbx_tftp_service_host"]');
  docker.val('docker.example').data('tftp-default', 'docker.example').data('tftp-automatic', '1');
  pbx.val('pbx.example').data('tftp-default', 'pbx.example').data('tftp-automatic', '1');
  h.context.syncTftpAddressDefault('[name="tftp_service_host"]', 'docker.changed', false);
  h.context.syncTftpAddressDefault('[name="pbx_tftp_service_host"]', 'pbx.changed', false);
  assert.equal(docker.val(), 'docker.changed');
  assert.equal(pbx.val(), 'pbx.changed');

  docker.val('198.51.100.20');
  h.context.syncTftpAddressDefault('[name="tftp_service_host"]', 'docker.new', true);
  assert.equal(docker.val(), '198.51.100.20', 'A custom Docker phone-facing address is preserved');
  assert.equal(docker.data('tftp-automatic'), '0');
  docker.val('');
  h.context.syncTftpAddressDefault('[name="tftp_service_host"]', 'docker.newer', true);
  assert.equal(docker.val(), 'docker.newer', 'Clearing a Docker override restores its automatic default');
  assert.equal(docker.data('tftp-automatic'), '1');

  pbx.val('198.51.100.21');
  h.context.syncTftpAddressDefault('[name="pbx_tftp_service_host"]', 'pbx.new', true);
  assert.equal(pbx.val(), '198.51.100.21', 'A custom PBX phone-facing address is preserved');
  assert.equal(pbx.data('tftp-automatic'), '0');
}

// Fingerprint requests include both provider address fields so edits invalidate a stale confirmation.
{
  const h = harness();
  h.node('[name="tftp_service_host"]').val('docker.phone');
  h.node('[name="pbx_tftp_service_host"]').val('pbx.phone');
  const draft = h.context.siteConnectionDraft();
  assert.equal(draft.tftp_service_host, 'docker.phone');
  assert.equal(draft.pbx_tftp_service_host, 'pbx.phone');
  h.node('[name="tftp_service_host"]').val('docker.changed');
  const dockerChanged = h.context.siteConnectionDraft();
  assert.equal(dockerChanged.tftp_service_host, 'docker.changed', 'A Docker TFTP edit changes the stale-result snapshot');
  assert.equal(dockerChanged.pbx_tftp_service_host, 'pbx.phone');
  h.node('[name="pbx_tftp_service_host"]').val('pbx.changed');
  const pbxChanged = h.context.siteConnectionDraft();
  assert.equal(pbxChanged.tftp_service_host, 'docker.changed');
  assert.equal(pbxChanged.pbx_tftp_service_host, 'pbx.changed', 'A PBX TFTP edit changes the stale-result snapshot');
}

function visible(harnessValue, message) {
  assert.match(harnessValue.result.text(), new RegExp(message), 'global result shows ' + message);
  assert.match(harnessValue.feedback.text(), new RegExp(message), 'inline result shows ' + message);
}

function operationLogHarness() {
  const panel = new Node('#ucm-operation-log-panel');
  const refresh = new Node('#ucm-operation-log-refresh');
  const status = new Node('#ucm-operation-log-status');
  const rows = new Node('#ucm-operation-log-rows');
  panel.data('site-id', 42);
  const nodes = new Map([
    ['#ucm-operation-log-panel', panel],
    ['#ucm-operation-log-refresh', refresh],
    ['#ucm-operation-log-status', status],
    ['#ucm-operation-log-rows', rows]
  ]);
  const requests = [];
  const renderCalls = [];
  function $(selector) {
    if (selector instanceof Node) { return selector; }
    if (!nodes.has(selector)) { nodes.set(selector, new Node(selector)); }
    return nodes.get(selector);
  }
  $.trim = value => String(value == null ? '' : value).trim();
  const context = {
    $, siteOperationLogRequest: null, siteOperationLogRefreshPending: false,
    operationLogStatus(message) { status.text(message); },
    renderOperationLogs(logs) { renderCalls.push(logs); },
    window: {ucmpAjax() { const request = deferred(); requests.push({request}); return request; }}
  };
  vm.createContext(context);
  for (const name of ['operationLogRefresh']) {
    vm.runInContext(extractFunction(name), context);
  }
  return {context, panel, refresh, status, rows, requests, renderCalls};
}

// Log polling never clears existing rows on failure and coalesces an action refresh behind a poll.
{
  const failed = operationLogHarness();
  failed.renderCalls.push(['existing rows']);
  failed.context.operationLogRefresh(true);
  failed.requests[0].request.reject({responseJSON: {result: {message: 'log endpoint unavailable'}}});
  assert.equal(failed.renderCalls.length, 1, 'A failed log refresh preserves existing rows');
  assert.match(failed.status.text(), /Update failed: log endpoint unavailable/);

  const pending = operationLogHarness();
  pending.context.operationLogRefresh(false);
  pending.context.operationLogRefresh(true);
  assert.equal(pending.requests.length, 1, 'A poll and action refresh do not overlap');
  pending.requests[0].request.resolve({status: true, result: {ok: true, logs: [], collected_at: 1}});
  assert.equal(pending.requests.length, 2, 'A pending action refresh runs after the poll settles');
}

// Business and transport failures during fingerprint discovery both cancel credentials and reset UI state.
{
  const h = harness();
  h.password.val('secret');
  let cancelled = 0, called = false;
  h.context.withConfirmedHostKey('pbx', h.button, () => { called = true; }, () => { cancelled++; h.password.val(''); });
  assert.equal(h.button.disabled, true);
  assert.match(h.result.text(), /fingerprint/i);
  h.requests[0].request.resolve({status: true, result: {ok: false, message: 'Fingerprint unavailable'}});
  assert.equal(called, false);
  assert.equal(cancelled, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  visible(h, 'Fingerprint unavailable');
  assert.ok(h.operationLogRefreshes.some(Boolean), 'Fingerprint failure schedules an operation-log refresh');

  const t = harness();
  t.password.val('secret');
  let transportCancelled = 0;
  t.context.withConfirmedHostKey('pbx', t.button, () => {}, () => { transportCancelled++; t.password.val(''); });
  t.requests[0].request.reject({responseJSON: {message: 'Fingerprint transport failed'}});
  assert.equal(transportCancelled, 1);
  assert.equal(t.password.val(), '');
  assert.equal(t.button.disabled, false);
  visible(t, 'Fingerprint transport failed');
  assert.ok(t.operationLogRefreshes.some(Boolean), 'Fingerprint transport failure schedules an operation-log refresh');
}

// A declined confirmation never pins or starts the credential-bearing operation.
{
  const h = harness({confirm: false});
  h.password.val('secret');
  let cancelled = 0;
  h.context.withConfirmedHostKey('pbx', h.button, () => { throw new Error('must not run'); }, () => { cancelled++; h.password.val(''); });
  h.requests[0].request.resolve({status: true, result: {ok: true, fingerprint: 'SHA256:test', host: 'pbx.example', port: 22}});
  assert.equal(h.pin.val(), '');
  assert.equal(h.requests.length, 1);
  assert.equal(cancelled, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  visible(h, 'Enrollment cancelled');
}

// After confirmation, the nested request owns the button until it settles.
{
  const h = harness();
  let completed = 0, succeeded = 0;
  h.context.withConfirmedHostKey('docker', h.button, fingerprint => h.context.bootstrapRequest(
    {command: 'bootstrapsite', expected_host_key: fingerprint}, h.button,
    () => { succeeded++; },
    () => { completed++; h.password.val(''); }
  ), () => { h.button.val(''); });
  h.requests[0].request.resolve({status: true, result: {ok: true, fingerprint: 'SHA256:nested', host: 'docker.example', port: 22}});
  assert.equal(h.pin.val(), '', 'Docker pin is stored in its own field, not PBX field');
  assert.equal(h.requests.length, 2);
  assert.equal(h.button.disabled, true, 'Fingerprint cleanup must not re-enable the nested operation');
  assert.match(h.result.text(), /Connecting/);
  h.requests[1].request.resolve({status: true, result: {ok: true, message: 'Docker ready'}});
  assert.equal(succeeded, 1);
  assert.equal(completed, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  visible(h, 'Docker ready');
  assert.ok(h.operationLogRefreshes.some(Boolean), 'Docker completion schedules an operation-log refresh');
}

// An existing pin bypasses fingerprint discovery but still uses the same main-request lifecycle.
{
  const h = harness({pinned: 'SHA256:pinned'});
  h.password.val('secret');
  let confirmations = 0, completed = 0;
  h.context.window.confirm = () => { confirmations++; return true; };
  h.context.withConfirmedHostKey('pbx', h.button, fingerprint => h.context.bootstrapRequest(
    {command: 'bootstrappbx', expected_host_key: fingerprint}, h.button, null,
    () => { completed++; h.password.val(''); }
  ));
  assert.equal(h.requests.length, 1);
  assert.equal(h.requests[0].data.command, 'bootstrappbx');
  assert.equal(h.requests[0].data.expected_host_key, 'SHA256:pinned');
  assert.equal(confirmations, 0);
  assert.equal(h.button.disabled, true);
  h.requests[0].request.resolve({status: true, result: {ok: true, message: 'PBX ready'}});
  assert.equal(completed, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  visible(h, 'PBX ready');
}

// An API partial failure must still let the UI consume a valid SSH-agent component.
{
  const h = harness({pinned: 'SHA256:pinned'});
  let callback = 0, completed = 0;
  h.context.bootstrapRequest(
    {command: 'bootstrappbx'}, h.button,
    result => { callback++; assert.equal(result.components.agent.ok, true); },
    () => { completed++; }
  );
  h.requests[0].request.resolve({status: true, result: {
    ok: false, ready: false, status: 'partial', message: 'PBX prepared; API verification failed',
    components: {agent: {ok: true}, api: {ok: false}}
  }});
  assert.equal(callback, 1);
  assert.equal(completed, 1);
  assert.equal(h.button.disabled, false);
  visible(h, 'PBX prepared; API verification failed');
}

// Explicit component state wins over compatibility fields and controls follow the fresh agent result.
{
  const h = harness();
  const trunk = h.node('#ucm-remote-trunk-endpoint');
  h.context.bootstrapRequest({command: 'bootstrappbx'}, h.button, h.context.applyPbxBootstrapResult);
  h.requests[0].request.resolve({status: true, result: {
    ok: false, agent: {ok: true, host_key: 'raw-agent-key'}, trunks: [{endpoint: 'stale'}],
    components: {agent: {ready: false, status: 'unknown'}, api: {ready: false, status: 'unknown'}}
  }});
  assert.equal(h.node('#ucm-pbx-agent-state').text(), 'enrollment / repair needed');
  for (const selector of ['#ucm-docker-bootstrap', '#ucm-pbx-tftp-bootstrap', '#ucm-remote-trunk-endpoint']) {
    assert.equal(h.node(selector).disabled, true, selector + ' remains locked when the fresh agent component failed');
  }

  trunk.val('keep');
  h.context.applyPbxBootstrapResult({
    ok: true, host_key: 'fresh-agent-key', trunks: [{endpoint: 'keep', label: 'Keep'}],
    components: {agent: {ready: true, status: 'ready'}, api: {ready: true, status: 'ready'}}
  });
  assert.equal(h.pin.val(), 'fresh-agent-key');
  assert.equal(h.node('#ucm-pbx-agent-state').text(), 'enrolled (last verified)');
  assert.equal(trunk.val(), 'keep', 'A still-valid selected trunk survives catalog refresh');
  for (const selector of ['#ucm-docker-bootstrap', '#ucm-pbx-tftp-bootstrap', '#ucm-remote-trunk-endpoint']) {
    assert.equal(h.node(selector).disabled, false, selector + ' unlocks after fresh agent readiness');
  }

  h.context.applyPbxBootstrapResult({ok: true, trunks: [], components: {agent: {ready: true, status: 'ready'}}});
  assert.equal(trunk.val(), '', 'An empty fresh trunk catalog clears stale selections');
}

// Main transport failures still expose the error, reset the button, and run cleanup.
{
  const h = harness({pinned: 'SHA256:pinned'});
  h.password.val('secret');
  let completed = 0;
  h.context.withConfirmedHostKey('pbx', h.button, fingerprint => h.context.bootstrapRequest(
    {command: 'bootstrappbx', expected_host_key: fingerprint}, h.button, null,
    () => { completed++; h.password.val(''); }
  ));
  h.requests[0].request.reject({responseJSON: {result: {message: 'Main request failed', detail: 'ssh: Permission denied'}}});
  assert.equal(completed, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  visible(h, 'Main request failed');
  assert.match(h.result.text(), /Permission denied/, 'AJAX failure keeps nested result detail visible');
}

// Callback exceptions cannot strand either the enrollment button or temporary credentials.
{
  const h = harness();
  h.password.val('secret');
  let cancelled = 0;
  h.context.withConfirmedHostKey('pbx', h.button, () => { throw new Error('callback fixture'); }, () => { cancelled++; h.password.val(''); });
  h.requests[0].request.resolve({status: true, result: {ok: true, fingerprint: 'SHA256:callback', host: 'pbx.example', port: 22}});
  assert.equal(cancelled, 1);
  assert.equal(h.password.val(), '');
  assert.equal(h.button.disabled, false);
  assert.match(h.result.text(), /could not be started/i);

  const main = harness({pinned: 'SHA256:pinned'});
  let completed = 0;
  main.context.withConfirmedHostKey('pbx', main.button, fingerprint => main.context.bootstrapRequest(
    {command: 'bootstrappbx', expected_host_key: fingerprint}, main.button,
    () => { throw new Error('render fixture'); },
    () => { completed++; main.button.val(''); }
  ));
  main.requests[0].request.resolve({status: true, result: {ok: true, message: 'PBX operation complete'}});
  assert.equal(completed, 1);
  assert.equal(main.button.disabled, false);
  assert.equal(main.button.val(), '');
  visible(main, 'page could not update');
}

// A host edit invalidates the old pin and the same current draft reaches the action.
{
  const h = harness({pinned: 'SHA256:pinned'});
  h.node('#ucm-pbx-host').val('changed.example');
  h.node('#ucm-pbx-port').val('2222');
  h.context.withConfirmedHostKey('pbx', h.button, fingerprint => h.context.bootstrapRequest(
    {command: 'remoteversion', expected_host_key: fingerprint}, h.button
  ));
  assert.equal(h.requests[0].data.command, 'sshfingerprint');
  const draft = JSON.parse(h.requests[0].data.site_draft);
  assert.equal(draft.pbx_host, 'changed.example');
  assert.equal(draft.pbx_addr, 'changed.example');
  assert.equal(draft.pbx_api_host, 'changed.example');
  assert.equal(draft.pbx_ssh_port, '2222');
  h.requests[0].request.resolve({status:true, result:{ok:true, fingerprint:'SHA256:new', host:'changed.example', port:2222}});
  assert.equal(h.requests[1].data.site_draft, h.requests[0].data.site_draft);
  h.requests[1].request.resolve({status:true, result:{ok:true, local_version:'1.1.0rc7', remote_version:'1.1.0rc6', comparison:'remote_older'}});
  assert.equal(h.node('#ucm-version-controller').text(), '1.1.0rc7');
  assert.equal(h.node('#ucm-version-remote').text(), '1.1.0rc6');
  assert.equal(h.node('#ucm-version-comparison').text(), 'Update available');
}

console.log('Site bootstrap fingerprint confirmation and nested-request lifecycle assertions passed');
