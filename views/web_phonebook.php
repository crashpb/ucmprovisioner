<?php
declare(strict_types=1);
if (!defined('UCMP_WEB_PHONEBOOK_PAGE')) { http_response_code(403); exit; }
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Company Directory</title>
<?php if (!empty($policy['favicon'])): ?><link rel="icon" type="image/png" href="?action=favicon&amp;v=<?php echo substr(hash('sha256',$policy['favicon']),0,16); ?>"><?php endif; ?>
<link rel="stylesheet" href="?action=css">
<script src="?action=js" defer></script>
</head>
<body>
<div id="ucmp-webbook" data-layout="table" aria-label="Company web phonebook">
  
  <div class="pb-app">
    <header class="pb-chrome">
      <div class="pb-brand"><span class="pb-brandmark"><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 18v-1a5 5 0 0 1 10 0v1M7 2v4M17 2v4"/></svg></span><div><strong>UCM Provisioner</strong><small>Company phonebook</small></div></div>
      <div class="pb-actions"><select class="pb-book-select" id="pb-book" aria-label="Phonebook"></select><button type="button" class="pb-button pb-icon-only" id="pb-theme" aria-label="Appearance: system. Switch to light."><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-9-9 7 7 0 0 0 9 9z"/></svg></button></div>
    </header>
    <main class="pb-main"><div class="pb-status" id="pb-status" role="status" aria-live="polite">Loading published phonebook…</div>
      <header class="pb-intro"><div><div class="pb-kicker">People &amp; numbers</div><h2 id="pb-heading">Company directory</h2><p class="pb-subtitle">Find a colleague. Make the phonebook work for you.</p></div><div class="pb-actions"><button type="button" class="pb-button" id="pb-print" disabled><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>Print</button><button type="button" class="pb-button pb-primary" id="pb-export" disabled><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 12h8M8 16h8M12 12v8"/></svg>Export to Excel</button></div></header>
      <section class="pb-dialog" id="pb-detail" aria-label="Contact details" hidden></section>
      <section class="pb-dialog" id="pb-output" aria-label="Phonebook output options" hidden>
        <header class="pb-dialog-head"><div><div class="pb-kicker" id="pb-output-kicker">Output options</div><h3 id="pb-output-title">Export to Excel</h3></div><button type="button" class="pb-button pb-icon-only" id="pb-close-output" aria-label="Close output options"><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button></header>
        <div class="pb-dialog-body" id="pb-output-options"><div class="pb-dialog-grid">
          <div><fieldset><legend>Which contacts?</legend><label class="pb-choice"><input type="radio" name="pb-output-scope" value="filtered" checked><span id="pb-scope-filtered">Current results</span></label><label class="pb-choice"><input type="radio" name="pb-output-scope" value="selected"><span id="pb-scope-selected">Selected contacts</span></label><label class="pb-choice"><input type="radio" name="pb-output-scope" value="all"><span id="pb-scope-all">Whole phonebook</span></label></fieldset><div id="pb-print-settings"><label class="pb-filter-label" for="pb-print-group">Arrange printout</label><select id="pb-print-group"><option value="department">Group by department</option><option value="site">Group by site</option><option value="none">One alphabetical list</option></select><p class="pb-output-note">A4 landscape · Repeated column headings</p></div><p class="pb-output-note" id="pb-excel-note">Excel workbook (.xlsx) · Numbers kept as text</p></div>
          <div><fieldset><legend>Columns to include</legend><div class="pb-column-choices" id="pb-output-columns"></div></fieldset><p class="pb-output-note">Choose from the fields permitted for this phonebook.</p></div>
        </div></div>
        <div class="pb-dialog-body" id="pb-output-preview" hidden></div>
        <footer class="pb-dialog-footer"><span class="pb-output-summary" id="pb-output-summary"></span><div class="pb-actions"><button type="button" class="pb-button" id="pb-back-options" hidden>Back to options</button><button type="button" class="pb-button pb-primary" id="pb-preview-output">Preview workbook</button></div><button type="button" class="pb-button pb-primary" id="pb-execute-output" hidden>Download .xlsx</button></footer>
      </section>
      <div class="pb-workspace">
        <aside class="pb-sidebar" aria-label="Browse contacts"><div class="pb-side-title">Browse by site</div><div id="pb-site-links"></div><div class="pb-side-title pb-section-gap">Departments</div><div id="pb-department-links"></div></aside>
        <div class="pb-content">
          <section class="pb-search-panel" aria-label="Search and filters"><label class="pb-search" for="pb-search"><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/></svg><input id="pb-search" type="search" maxlength="160" autocomplete="off" dir="auto" placeholder="Search contacts" aria-label="Search phonebook"><button type="button" class="pb-clear-search" id="pb-clear-search" aria-label="Clear search" hidden><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6"/></svg></button></label><div class="pb-filters"><span class="pb-filter-label">Narrow by</span><select id="pb-site" aria-label="Filter by site"></select><select id="pb-department" aria-label="Filter by department"></select><button type="button" class="pb-reset" id="pb-reset" hidden>Clear filters</button></div></section>
          <section class="pb-results" aria-label="Directory results">
            <header class="pb-results-top"><div class="pb-result-count" id="pb-count" aria-live="polite"></div><div class="pb-actions"><select class="pb-sort" id="pb-sort" aria-label="Sort contacts"><option value="name">Name A–Z</option><option value="extension">Extension</option><option value="department">Department</option></select><div class="pb-view-switch" aria-label="Directory view"><button type="button" data-layout="table" aria-pressed="true"><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 5h13M8 12h13M8 19h13M3 5h.01M3 12h.01M3 19h.01"/></svg>Table</button><button type="button" data-layout="people" aria-pressed="false"><svg class="pb-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>People</button></div></div></header>
            <div class="pb-selection" id="pb-selection" hidden><span id="pb-selected-count" aria-live="polite"></span><div class="pb-actions"><button type="button" class="pb-reset" id="pb-select-results">Select all results</button><button type="button" class="pb-reset" id="pb-clear-selection">Clear selection</button></div></div>
            <div id="pb-contact-list"></div>
            <footer class="pb-pagination"><span id="pb-page-range"></span><div class="pb-actions"><button type="button" class="pb-button" id="pb-prev" disabled aria-label="Previous page">Previous</button><span id="pb-page-number"></span><button type="button" class="pb-button" id="pb-next" disabled aria-label="Next page">Next</button></div></footer>
          </section>
          <footer class="pb-footer"><span>Read-only directory · <a id="pb-help" hidden rel="noopener noreferrer">Report a correction / get help</a></span><div class="pb-actions"><span id="pb-published"></span><button type="button" class="pb-reset" id="pb-refresh">Refresh</button></div></footer>
        </div>
      </div>
    </main>
  </div>
  <div class="pb-printable" id="pb-printable"></div>
</div>
</body>
</html>
