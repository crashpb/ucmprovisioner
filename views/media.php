<?php
/** Managed phone media catalogs over the selected site's TFTP SFTP channel. */
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
$sites=$ucm->getSites(); $models=$ucm->getModels(); $defaultSite=$ucm->localSiteId();
$hostedModels=array_values(array_filter(
    $models,
    static fn(array $model): bool => (string)$model['family']==='cisco_cnf'
));
$wallpaperProfiles=\FreePBX\modules\Ucmprovisioner\ModelCatalog::wallpaperProfiles();
$dectWithoutProvisionedWallpaper=['Yealink'=>[],'Grandstream'=>[]];
$audioModelLibraries=['cisco_xml'=>[],'yealink'=>[],'grandstream'=>[]];
foreach($models as $model){
    $capabilities=\FreePBX\modules\Ucmprovisioner\ModelCatalog::capabilitiesFor((string)$model['vendor'],(string)$model['model']);
    if(isset($dectWithoutProvisionedWallpaper[(string)$model['vendor']])
        && in_array((string)($capabilities['role']??''),['dect_base','dect_handset'],true)){
        $dectWithoutProvisionedWallpaper[(string)$model['vendor']][]=(string)$model['model'];
    }
    $vendor=strtolower((string)$model['vendor']);
    $family=$vendor==='cisco'?'cisco_xml':$vendor;
    if(isset($audioModelLibraries[$family]) && (string)$model['family']!=='cisco_cnf'){
        $audioModelLibraries[$family][]=[
            'value'=>strtolower((string)$model['model']),
            'label'=>(string)$model['vendor'].' '.(string)$model['model'],
        ];
    }
}
?>
<div id="ucmp-media-preview-v2" class="ucm-container mp2-app">
  <header class="mp2-top">
    <div>
      <h2><?php echo _('Media'); ?></h2>
      <p class="mp2-muted"><?php echo _('Catalog-based wallpapers, special backgrounds, ringtones and site replication'); ?></p>
    </div>
    <label class="mp2-site-context">
      <span><?php echo _('Source / editing site'); ?></span>
      <select id="ucm-media-site">
        <?php foreach($sites as $s): ?>
          <option value="<?php echo (int)$s['id']; ?>" <?php echo (int)$s['id']===$defaultSite?'selected':''; ?>>
            <?php echo htmlspecialchars((string)$s['name']); ?><?php echo !empty($s['is_local'])?' ('._('local').')':''; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  </header>

  <nav class="mp2-tabs" aria-label="<?php echo _('Phone media work areas'); ?>">
    <button type="button" class="mp2-tab mp2-active" data-media-tab="wallpapers"><?php echo _('Wallpaper catalogs'); ?></button>
    <button type="button" class="mp2-tab" data-media-tab="legacy"><?php echo _('Non-standard / legacy backgrounds'); ?></button>
    <button type="button" class="mp2-tab" data-media-tab="audio"><?php echo _('Ringtones & audio'); ?></button>
    <button type="button" class="mp2-tab" data-media-tab="replication"><?php echo _('Site replication'); ?></button>
    <button type="button" class="mp2-tab" data-media-tab="diagnostics"><?php echo _('Asset index (advanced)'); ?></button>
  </nav>

  <div class="mp2-live" id="ucm-media-message" role="status" aria-live="polite">
    <?php echo _('Select a profile to preview or edit only that profile inside the current catalog.'); ?>
  </div>
  <section id="ucm-hosted-retirement" class="mp2-surface" hidden aria-live="polite"></section>

  <section class="mp2-view mp2-active ucm-media-panel" data-media-panel="wallpapers" data-media-owned-panel="wallpapers">
    <section class="mp2-surface" data-media-owned-panel="wallpapers">
      <header class="mp2-surface-head">
        <div class="mp2-head-copy">
          <span class="mp2-kicker" id="ucm-bg-editor-mode"><?php echo _('New catalog'); ?></span>
          <h3><?php echo _('Master image and profile editor'); ?></h3>
          <p class="mp2-muted"><?php echo _('Scope determines the profiles. Each selected profile keeps its own enabled state, source image, scale and placement.'); ?></p>
        </div>
        <button type="button" class="mp2-btn" id="ucm-bg-new"><?php echo _('+ New catalog'); ?></button>
      </header>

      <div class="mp2-editor" id="ucm-wallpaper-workbench">
        <aside class="mp2-setup">
          <div class="mp2-field">
            <span class="mp2-label"><?php echo _('Master image'); ?></span>
            <label class="mp2-upload">
              <input type="file" id="ucm-bg-source" accept="image/png,image/jpeg,image/bmp">
              <strong id="ucm-bg-master-action"><?php echo _('Choose master image'); ?></strong>
              <span class="mp2-muted"><?php echo _('One large PNG, JPEG or BMP'); ?></span>
            </label>
            <span class="mp2-muted"><?php echo _('The source filename suggests the catalog name once. It never becomes the catalog identity.'); ?></span>
          </div>
          <div class="mp2-field">
            <label for="ucm-bg-name"><?php echo _('Catalog name'); ?></label>
            <div class="mp2-name-row">
              <input id="ucm-bg-name" type="text" placeholder="<?php echo _('Company wallpaper'); ?>">
              <button type="button" class="mp2-btn" id="ucm-bg-rename" disabled><?php echo _('Rename'); ?></button>
            </div>
          </div>
          <div class="mp2-field">
            <label for="ucm-bg-coverage"><?php echo _('Scope'); ?></label>
            <select id="ucm-bg-coverage">
              <option value="all"><?php echo _('All supported models'); ?></option>
              <option value="brand"><?php echo _('One brand'); ?></option>
              <option value="series"><?php echo _('One series / display group'); ?></option>
              <option value="model"><?php echo _('One exact model'); ?></option>
            </select>
          </div>
          <div class="mp2-field mp2-hidden" id="ucm-bg-brand-field">
            <label for="ucm-bg-brand"><?php echo _('Brand'); ?></label>
            <select id="ucm-bg-brand">
              <option value=""><?php echo _('Choose a brand'); ?></option>
              <option value="Cisco">Cisco</option>
              <option value="Yealink">Yealink</option>
              <option value="Grandstream">Grandstream</option>
            </select>
          </div>
          <div class="mp2-field mp2-hidden" id="ucm-bg-series-field">
            <label for="ucm-bg-series"><?php echo _('Series / compatible display group'); ?></label>
            <select id="ucm-bg-series" disabled><option value=""><?php echo _('Choose a brand first'); ?></option></select>
          </div>
          <div class="mp2-field mp2-hidden" id="ucm-bg-model-field">
            <label for="ucm-bg-model-scope"><?php echo _('Exact model'); ?></label>
            <select id="ucm-bg-model-scope" disabled><option value=""><?php echo _('Choose a profile first'); ?></option></select>
          </div>
          <div class="mp2-source-meta">
            <span><?php echo _('Master source'); ?> <b id="ucm-bg-master-name"><?php echo _('Not selected'); ?></b></span>
            <span><?php echo _('Dimensions'); ?> <b id="ucm-bg-master-size">—</b></span>
            <span><?php echo _('Catalog ID'); ?> <b id="ucm-bg-catalog-id"><?php echo _('Assigned on save'); ?></b></span>
          </div>
        </aside>

        <div class="mp2-middle">
          <aside class="mp2-profiles">
            <h3><?php echo _('Profiles in this scope'); ?></h3>
            <div class="mp2-scope-summary" id="ucm-bg-scope-summary"></div>
            <select id="ucm-media-model" class="ucm-media-profile-select" aria-hidden="true" tabindex="-1">
              <?php foreach($wallpaperProfiles as $profile): ?>
                <option
                  data-key="<?php echo htmlspecialchars((string)$profile['key']); ?>"
                  data-vendor="<?php echo htmlspecialchars((string)$profile['vendor']); ?>"
                  data-series="<?php echo htmlspecialchars((string)$profile['series_key']); ?>"
                  data-series-label="<?php echo htmlspecialchars((string)$profile['series']); ?>"
                  data-models="<?php echo htmlspecialchars(implode(',',(array)$profile['models'])); ?>"
                  data-delivery="<?php echo htmlspecialchars((string)$profile['delivery']); ?>"
                  data-format="<?php echo htmlspecialchars((string)$profile['format']); ?>"
                  data-max="<?php echo (int)$profile['max_bytes']; ?>"
                  data-dir="<?php echo htmlspecialchars((string)$profile['dir']); ?>"
                  data-tones="<?php echo (int)($profile['grayscale_tones'] ?? 0); ?>"
                  data-fw="<?php echo (int)$profile['width']; ?>"
                  data-fh="<?php echo (int)$profile['height']; ?>"
                  data-tw="<?php echo (int)$profile['thumbnail_width']; ?>"
                  data-th="<?php echo (int)$profile['thumbnail_height']; ?>"
                ><?php echo htmlspecialchars($profile['vendor'].' · '.$profile['width'].'×'.$profile['height'].' · '.$profile['series'].' — '.implode(', ',$profile['models'])); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mp2-profile-list" id="ucm-bg-profile-list"></div>
            <div class="mp2-profile-actions" id="ucm-bg-profile-actions">
              <strong id="ucm-bg-profile-state"><?php echo _('Inherits master'); ?></strong>
              <label class="mp2-switch"><input type="checkbox" id="ucm-bg-profile-enabled" checked> <?php echo _('Generate and publish this profile'); ?></label>
              <label class="mp2-switch mp2-hidden" id="ucm-bg-profile-invert-wrap"><input type="checkbox" id="ucm-bg-profile-invert"> <?php echo _('Invert grayscale (black ↔ white)'); ?></label>
              <label class="mp2-file-button"><input type="file" id="ucm-bg-profile-source" accept="image/png,image/jpeg,image/bmp"> <?php echo _('Upload profile image'); ?></label>
              <div class="mp2-profile-action-row">
                <button type="button" class="mp2-btn" id="ucm-bg-profile-source-remove" disabled><?php echo _('Remove alternate'); ?></button>
                <button type="button" class="mp2-btn" id="ucm-bg-profile-reset"><?php echo _('Reset profile'); ?></button>
              </div>
            </div>
            <details class="mp2-exclusions">
              <summary><?php echo _('Devices excluded from generation'); ?></summary>
              <p class="mp2-muted"><?php echo _('Models without a documented managed custom-wallpaper path are capability exclusions, not fake output profiles.'); ?></p>
              <?php foreach($dectWithoutProvisionedWallpaper as $vendor=>$names): if(!$names)continue; ?>
                <p><strong><?php echo htmlspecialchars($vendor); ?>:</strong> <?php echo htmlspecialchars(implode(', ',array_values(array_unique($names)))); ?></p>
              <?php endforeach; ?>
            </details>
          </aside>

          <main class="mp2-work">
            <div class="mp2-work-head">
              <h3 id="ucm-bg-profile-heading"><?php echo _('Select a profile'); ?></h3>
              <span class="mp2-badge" id="ucm-bg-profile-badge"><?php echo _('Base image · inherited'); ?></span>
            </div>
            <div class="mp2-stage-host" id="ucm-wall-stage-host">
              <div class="mp2-stage-outer" id="ucm-wall-scene">
                <div class="mp2-stage" id="ucm-wall-output">
                  <img id="ucm-wall-image" alt="">
                  <canvas id="ucm-wall-preview" hidden aria-hidden="true"></canvas>
                  <div class="ucm-wall-empty" id="ucm-wall-empty"><?php echo _('Choose a master image to begin. The editor stays bounded until an image is available.'); ?></div>
                  <span class="mp2-output-tag mp2-stage-label" id="ucm-bg-output-tag"></span>
                </div>
                <div class="mp2-transform-box" id="ucm-wall-frame" tabindex="0" aria-label="<?php echo _('Draggable source image frame'); ?>">
                  <span class="mp2-move-grip" data-wall-handle="move" data-handle="move" title="<?php echo _('Move image frame'); ?>" aria-label="<?php echo _('Move image frame'); ?>">↕</span>
                  <?php foreach(['n','s','e','w','ne','nw','se','sw'] as $handle): ?>
                    <span class="mp2-handle" data-wall-handle="<?php echo $handle; ?>" data-handle="<?php echo $handle; ?>"></span>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
            <div class="mp2-warning mp2-hidden" id="ucm-wall-coverage-warning"><?php echo _('The checkerboard is uncovered output. It will never be published: saving is blocked until the source covers the complete profile. Use Auto-cover output.'); ?></div>
            <div class="mp2-editor-note">
              <span><?php echo _('Drag the outline to move it; drag any edge or corner to scale without distorting the source.'); ?></span>
              <span id="ucm-media-path"></span>
            </div>
            <details class="mp2-exclusions mp2-device-output" id="ucm-bg-device-output">
              <summary><?php echo _('Exact device output preview'); ?></summary>
              <canvas id="ucm-bg-full-canvas"></canvas>
              <p class="mp2-muted"><?php echo _('This is the quantized file sent to a grayscale phone. The larger editor above stays smooth so a 95 × 34 profile remains practical to position.'); ?></p>
            </details>
            <details class="mp2-exclusions" id="ucm-thumb-output">
              <summary><?php echo _('Generated vendor thumbnail preview'); ?></summary>
              <canvas id="ucm-bg-thumb-canvas"></canvas>
              <p class="mp2-muted" id="ucm-media-dimensions"></p>
            </details>
          </main>
        </div>

        <aside class="mp2-controls">
          <div class="mp2-control-group">
            <div class="mp2-selected-status">
              <strong><?php echo _('Editing the selected profile'); ?></strong>
              <span id="ucm-bg-editing"><?php echo _('Changes here belong only to the selected profile.'); ?></span>
            </div>
          </div>
          <div class="mp2-control-group">
            <h3><?php echo _('Precise placement'); ?></h3>
            <label class="mp2-range"><span><?php echo _('Scale'); ?></span><input id="ucm-full-zoom" type="range" min="60" max="220" value="100"><output id="ucm-full-zoom-value">100%</output></label>
            <label class="mp2-range"><span><?php echo _('Horizontal'); ?></span><input id="ucm-full-x" type="range" min="-180" max="180" value="0"><output id="ucm-full-x-value">0 px</output></label>
            <label class="mp2-range"><span><?php echo _('Vertical'); ?></span><input id="ucm-full-y" type="range" min="-140" max="140" value="0"><output id="ucm-full-y-value">0 px</output></label>
            <div class="mp2-button-row">
              <button type="button" class="mp2-btn" id="ucm-wall-auto-cover"><?php echo _('Auto-cover output'); ?></button>
              <button type="button" class="mp2-btn" id="ucm-wall-center"><?php echo _('Center'); ?></button>
              <button type="button" class="mp2-btn" id="ucm-wall-fit-now"><?php echo _('Bring handles into view'); ?></button>
            </div>
            <label class="mp2-switch"><input type="checkbox" id="ucm-wall-fit-handles" checked> <?php echo _('Auto-fit output and drag handles'); ?></label>
            <label class="mp2-range"><span><?php echo _('Editor zoom'); ?></span><input id="ucm-wall-view-zoom" type="range" min="25" max="300" step="5" value="100"><output id="ucm-wall-view-zoom-value">100%</output></label>
            <p class="mp2-muted"><span id="ucm-wall-view-scale"><?php echo _('Fitted view 100%'); ?></span>. <?php echo _('Editor zoom changes only the on-screen camera, never the saved wallpaper scale or placement.'); ?></p>
          </div>
          <details class="mp2-control-group" id="ucm-thumb-controls">
            <summary><?php echo _('Cisco thumbnail placement'); ?></summary>
            <label class="mp2-range"><span><?php echo _('Scale'); ?></span><input id="ucm-thumb-zoom" type="range" min="100" max="400" value="100"><output id="ucm-thumb-zoom-value">100%</output></label>
            <label class="mp2-range"><span><?php echo _('Horizontal'); ?></span><input id="ucm-thumb-x" type="range" min="-100" max="100" value="0"><output></output></label>
            <label class="mp2-range"><span><?php echo _('Vertical'); ?></span><input id="ucm-thumb-y" type="range" min="-100" max="100" value="0"><output></output></label>
          </details>
          <div class="mp2-control-group">
            <h3><?php echo _('Save this profile'); ?></h3>
            <p class="mp2-muted"><?php echo _('Switching profiles keeps drafts. Saving commits this profile enabled state, source choice, scale and placement.'); ?></p>
            <button type="button" class="mp2-btn mp2-block" id="ucm-bg-save-profile"><?php echo _('Save profile override'); ?></button>
          </div>
          <button type="button" class="mp2-btn mp2-primary mp2-block" id="ucm-bg-upload"><?php echo _('Save all catalog changes'); ?></button>
          <button type="button" class="mp2-btn mp2-block" id="ucm-bg-repair-encoding" disabled><?php echo _('Repair Cisco PNG encoding'); ?></button>
          <button type="button" class="mp2-btn mp2-block" id="ucm-bg-repair-hosted" disabled><?php echo _('Repair hosted filenames'); ?></button>
          <button type="button" class="mp2-btn mp2-block" id="ucm-bg-refresh"><?php echo _('Refresh catalogs'); ?></button>
        </aside>
      </div>
    </section>

    <section class="mp2-surface" data-media-owned-panel="wallpapers">
      <header class="mp2-surface-head">
        <div class="mp2-head-copy">
          <span class="mp2-kicker"><?php echo _('Catalog library'); ?></span>
          <h3><?php echo _('Open and manage complete catalogs'); ?></h3>
          <p class="mp2-muted"><?php echo _('Edit reopens the master, scope, enabled profiles, alternate images and every saved placement above.'); ?></p>
        </div>
        <button type="button" class="mp2-btn mp2-primary" id="ucm-bg-new-library"><?php echo _('+ New catalog'); ?></button>
      </header>
      <div class="mp2-library" id="ucm-bg-grid"></div>
      <details class="mp2-exclusions" id="ucm-bg-legacy-details">
        <summary><?php echo _('Legacy / unlinked wallpaper pairs'); ?></summary>
        <p class="mp2-muted"><?php echo _('These predate linked manifests and are never guessed into a catalog.'); ?></p>
        <div id="ucm-bg-legacy-grid" class="mp2-library"></div>
      </details>
    </section>
  </section>

  <section class="mp2-view ucm-media-panel" data-media-panel="legacy" data-media-owned-panel="legacy" hidden>
    <section class="mp2-surface" data-media-owned-panel="legacy">
      <header class="mp2-surface-head">
        <div class="mp2-head-copy">
          <span class="mp2-kicker"><?php echo _('Special conversion pipeline'); ?></span>
          <h3><?php echo _('Non-standard / legacy backgrounds'); ?></h3>
          <p class="mp2-muted"><?php echo _('One upload and one editor for phone formats that cannot use the standard wallpaper catalog pipeline.'); ?></p>
        </div>
        <div class="mp2-button-row">
          <button type="button" class="mp2-btn" id="ucm-hosted-new"><?php echo _('+ New special catalog'); ?></button>
          <button type="button" class="mp2-btn" id="ucm-hosted-open-replication"><?php echo _('Open replication'); ?></button>
        </div>
      </header>
      <div class="mp2-legacy-editor" id="ucm-hosted-editor">
        <aside class="mp2-setup">
          <div class="mp2-field">
            <span class="mp2-label"><?php echo _('Source image'); ?></span>
            <label class="mp2-upload"><input type="file" id="ucm-hosted-file" accept="image/png,image/jpeg,image/bmp"><strong><?php echo _('Choose or replace source'); ?></strong><span class="mp2-muted"><?php echo _('PNG, JPEG or BMP'); ?></span></label>
          </div>
          <div class="mp2-field"><label for="ucm-hosted-name"><?php echo _('Catalog / asset name'); ?></label><input id="ucm-hosted-name" type="text" placeholder="<?php echo _('Reception legacy'); ?>"></div>
          <div class="mp2-field"><label for="ucm-hosted-family"><?php echo _('Special target family'); ?></label><select id="ucm-hosted-family"><option value="cisco_cnf"><?php echo _('Cisco 7940/7960 four-tone palette BMP'); ?></option></select></div>
          <div class="mp2-field"><label for="ucm-hosted-model"><?php echo _('Model library'); ?></label><select id="ucm-hosted-model"></select></div>
          <div class="mp2-source-meta"><span id="ucm-hosted-help"></span><span><?php echo _('A compact filename is assigned automatically; the catalog name remains unchanged.'); ?></span></div>
        </aside>
        <main class="mp2-work">
          <div class="mp2-work-head"><h3><?php echo _('Crop and placement preview'); ?></h3><span class="mp2-badge" id="ucm-hosted-output-size">90 × 56</span></div>
          <div class="mp2-stage-host mp2-legacy-stage-host" id="ucm-hosted-stage-host">
            <div class="mp2-stage-outer" id="ucm-hosted-scene">
              <div class="mp2-stage" id="ucm-hosted-output">
                <img id="ucm-hosted-image" alt="">
                <div class="ucm-wall-empty" id="ucm-hosted-empty"><?php echo _('Choose a source image to begin this special catalog.'); ?></div>
                <span class="mp2-output-tag mp2-stage-label">90 × 56 BMP output</span>
              </div>
              <div class="mp2-transform-box" id="ucm-hosted-frame" tabindex="0" aria-label="<?php echo _('Draggable special-background source frame'); ?>">
                <span class="mp2-move-grip" data-hosted-handle="move" data-wall-handle="move" data-handle="move" title="<?php echo _('Move image frame'); ?>" aria-label="<?php echo _('Move image frame'); ?>">↕</span>
                <?php foreach(['n','s','e','w','ne','nw','se','sw'] as $handle): ?>
                  <span class="mp2-handle" data-hosted-handle="<?php echo $handle; ?>" data-wall-handle="<?php echo $handle; ?>" data-handle="<?php echo $handle; ?>"></span>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
          <div class="mp2-warning mp2-hidden" id="ucm-hosted-coverage-warning"><?php echo _('The special-background output has uncovered pixels. Auto-cover or reset placement before saving.'); ?></div>
          <canvas id="ucm-hosted-canvas" hidden></canvas>
          <p class="mp2-muted"><?php echo _('Drag the visible frame or its eight handles. Conversion happens only after this retained crop and placement.'); ?></p>
        </main>
        <aside class="mp2-controls">
          <div class="mp2-control-group">
            <h3><?php echo _('Precise placement'); ?></h3>
            <input type="hidden" id="ucm-hosted-width" value="90"><input type="hidden" id="ucm-hosted-height" value="56">
            <label class="mp2-range"><span><?php echo _('Scale'); ?></span><input type="range" id="ucm-hosted-zoom" min="100" max="400" value="100"><output></output></label>
            <label class="mp2-range"><span><?php echo _('Horizontal'); ?></span><input type="range" id="ucm-hosted-x" min="-100" max="100" value="0"><output></output></label>
            <label class="mp2-range"><span><?php echo _('Vertical'); ?></span><input type="range" id="ucm-hosted-y" min="-100" max="100" value="0"><output></output></label>
            <label class="mp2-switch"><input type="checkbox" id="ucm-hosted-invert"> <?php echo _('Invert grayscale (black ↔ white)'); ?></label>
            <div class="mp2-button-row"><button type="button" class="mp2-btn" id="ucm-hosted-auto-cover"><?php echo _('Auto-cover output'); ?></button><button type="button" class="mp2-btn" id="ucm-hosted-center"><?php echo _('Center'); ?></button><button type="button" class="mp2-btn" id="ucm-hosted-reset"><?php echo _('Reset'); ?></button></div>
          </div>
          <button type="button" class="mp2-btn mp2-primary mp2-block" id="ucm-hosted-publish"><?php echo _('Save special catalog'); ?></button>
        </aside>
      </div>
    </section>
    <section class="mp2-surface" data-media-owned-panel="legacy">
      <header class="mp2-surface-head"><div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Complete served library'); ?></span><h3><?php echo _('Managed catalogs and loose legacy backgrounds'); ?></h3><p class="mp2-muted"><?php echo _('Every Cisco CNF BMP currently served by this PBX appears here. Loose files can be adopted without changing their phone URL, or deleted here.'); ?></p></div></header>
      <div class="mp2-library" id="ucm-hosted-list"></div>
    </section>
  </section>

  <section class="mp2-view ucm-media-panel" data-media-panel="audio" data-media-owned-panel="audio" hidden>
    <div class="mp2-simple-grid" id="ucm-audio-workbench" data-media-owned-panel="audio">
      <section class="mp2-surface mp2-pad">
        <div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Separate media library'); ?></span><h3><?php echo _('Ringtones & audio'); ?></h3><p class="mp2-muted"><?php echo _('Upload one or multiple files, listen to the originals, then publish them to a compatible phone library.'); ?></p></div>
        <div class="mp2-field"><label for="ucm-audio-family"><?php echo _('Phone family'); ?></label><select id="ucm-audio-family"><option value="cisco_ringlist"><?php echo _('Cisco standard / wideband ring list'); ?></option><option value="cisco_xml"><?php echo _('Cisco XML model library'); ?></option><option value="yealink"><?php echo _('Yealink model library'); ?></option><option value="grandstream"><?php echo _('Grandstream model library'); ?></option></select></div>
        <div class="mp2-field" id="ucm-audio-model-field"><label for="ucm-audio-model"><?php echo _('Model / compatible library'); ?></label><select id="ucm-audio-model"></select></div>
        <div class="mp2-field" id="ucm-ring-type-field"><label for="ucm-ring-type"><?php echo _('Cisco ring-list format'); ?></label><select id="ucm-ring-type"><option value="raw"><?php echo _('Standard .raw — 8 kHz μ-law, mono'); ?></option><option value="rwb"><?php echo _('Wideband .rwb — 16 kHz signed 16-bit, mono'); ?></option></select></div>
        <div class="mp2-field"><label class="mp2-upload"><input type="file" id="ucm-ring-file" multiple accept=".raw,.rwb,.wav,.mp3,.ogg,audio/*"><strong><?php echo _('Choose audio files'); ?></strong><span class="mp2-muted"><?php echo _('Cisco, Yealink or Grandstream'); ?></span></label></div>
        <div id="ucm-ring-upload-preview" class="ucm-audio-upload-list"></div>
        <button type="button" class="mp2-btn mp2-primary" id="ucm-ring-upload"><?php echo _('Add reviewed files to audio library'); ?></button>
        <p class="mp2-audio-note"><?php echo _('Playback uses the original upload. Phone-format validation remains separate so the source can be reviewed before publishing.'); ?></p>
      </section>
      <section class="mp2-surface mp2-pad">
        <div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Model-aware choices'); ?></span><h3><?php echo _('Audio library'); ?></h3><p class="mp2-muted"><?php echo _('Native controls provide play, pause, seeking and volume preview where the browser supports the format.'); ?></p></div>
        <div class="mp2-table-wrap"><div class="ucm-ring-list" id="ucm-ring-list"></div></div>
      </section>
    </div>
  </section>

  <section class="mp2-view ucm-media-panel" data-media-panel="replication" data-media-owned-panel="replication" hidden>
    <section class="mp2-surface" data-media-owned-panel="replication">
      <header class="mp2-surface-head"><div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Selected source site'); ?></span><h3><?php echo _('Site replication'); ?></h3><p class="mp2-muted"><?php echo _('First select the complete catalogs to copy, then select the destination sites.'); ?></p></div></header>
      <div class="mp2-sync-layout">
        <div class="mp2-sync-step">
          <h3><?php echo _('1. Catalogs to synchronize'); ?></h3>
          <label class="mp2-switch"><input type="checkbox" id="ucm-media-sync-all"> <?php echo _('Select all catalogs'); ?></label>
          <button type="button" class="mp2-btn" id="ucm-media-sync-refresh"><?php echo _('Refresh catalogs'); ?></button>
          <div class="mp2-table-wrap"><table><thead><tr><th><?php echo _('Select'); ?></th><th><?php echo _('Catalog'); ?></th><th><?php echo _('Type'); ?></th><th><?php echo _('Revision'); ?></th><th><?php echo _('Contents'); ?></th></tr></thead><tbody id="ucm-media-sync-catalogs"><tr><td colspan="5" class="mp2-muted"><?php echo _('Open this work area to load catalogs.'); ?></td></tr></tbody></table></div>
        </div>
        <div class="mp2-sync-step">
          <h3><?php echo _('2. Target sites'); ?></h3>
          <div class="mp2-site-list" id="ucm-media-sync-targets">
            <?php foreach($sites as $s): ?>
              <?php $isLocal=!empty($s['is_local']); $savedStatus=strtolower((string)($s['status']??'')); $isOffline=in_array($savedStatus,['offline','unreachable','failed'],true); ?>
              <label class="mp2-site">
                <input type="checkbox" class="ucm-sync-target" value="<?php echo (int)$s['id']; ?>" <?php echo $isLocal?'disabled':''; ?>>
                <span class="mp2-site-copy"><strong><?php echo htmlspecialchars((string)$s['name']); ?></strong><span class="mp2-muted"><?php echo $isLocal?_('Current source site'):($isOffline?_('Last saved state: offline / unreachable; cached inventory retained'):($savedStatus!==''?htmlspecialchars(_('Last saved state').': '.$savedStatus):_('Status checked when synchronization runs; cached inventory is retained'))); ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
          <h3><?php echo _('3. Confirm'); ?></h3>
          <label class="mp2-switch"><input type="checkbox" id="ucm-sync-overwrite"> <?php echo _('Replace differing managed target files'); ?></label>
          <p class="mp2-muted"><?php echo _('A wallpaper catalog moves with its master, profile images, transforms, disabled states, generated outputs and manifest.'); ?></p>
          <button type="button" class="mp2-btn mp2-primary mp2-block" id="ucm-media-sync-selected"><?php echo _('Synchronize selected catalogs'); ?></button>
          <div id="ucm-media-sync-results" aria-live="polite"></div>
        </div>
      </div>
    </section>

    <section class="mp2-surface" data-media-owned-panel="replication">
      <header class="mp2-surface-head"><div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Recurring job'); ?></span><h3><?php echo _('Automatic replication'); ?></h3><p class="mp2-muted"><?php echo _('Choose an exact minute interval, daily time, or weekday schedule independently from the manual selection above.'); ?></p></div><button type="button" class="mp2-btn" id="ucm-media-job-new"><?php echo _('New job'); ?></button></header>
      <form id="ucm-media-job-form" class="mp2-auto-grid">
        <input type="hidden" id="ucm-media-job-id" value="0">
        <div class="mp2-auto-column">
          <div class="mp2-field"><label for="ucm-media-job-name"><?php echo _('Job name'); ?></label><input id="ucm-media-job-name" maxlength="80" value="<?php echo _('Replicate changed media'); ?>"></div>
          <label class="mp2-switch"><input type="checkbox" id="ucm-media-job-enabled" checked> <?php echo _('Job enabled'); ?></label>
          <div class="ucmp-schedule-editor mp2-schedule-editor" id="ucm-media-job-schedule" data-schedule-editor>
            <div class="ucmp-schedule-heading"><strong><?php echo _('Run schedule'); ?></strong><span><?php echo sprintf(_('Uses this PBX timezone: %s'), htmlspecialchars(date_default_timezone_get())); ?></span></div>
            <label><?php echo _('Cadence'); ?><select id="ucm-media-job-mode" data-schedule-mode><option value="manual"><?php echo _('Manual only'); ?></option><option value="interval" selected><?php echo _('Minute interval'); ?></option><option value="daily"><?php echo _('Daily at a time'); ?></option><option value="weekly"><?php echo _('Selected weekdays'); ?></option></select></label>
            <label data-schedule-field="interval"><?php echo _('Every (minutes)'); ?><input type="number" id="ucm-media-job-interval" data-schedule-interval min="5" max="10080" step="1" value="30"></label>
            <label data-schedule-field="clock"><?php echo _('PBX local time'); ?><input type="time" id="ucm-media-job-time" data-schedule-time step="60" value="02:00"></label>
            <fieldset data-schedule-field="weekly"><legend><?php echo _('Run on'); ?></legend><div class="ucmp-weekdays"><?php foreach([1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'] as $day=>$label): ?><label><input type="checkbox" class="ucm-media-job-weekday" data-schedule-weekday value="<?php echo $day; ?>" checked> <?php echo _($label); ?></label><?php endforeach; ?></div></fieldset>
          </div>
          <label class="mp2-switch"><input type="checkbox" id="ucm-media-job-changed" checked> <?php echo _('Preserve an existing target filename'); ?></label>
          <label class="mp2-switch"><input type="checkbox" id="ucm-media-job-retry" checked> <?php echo _('Retry unreachable targets on the next run'); ?></label>
        </div>
        <div class="mp2-auto-column">
          <div><h3><?php echo _('Content scope'); ?></h3><div class="mp2-choice-list"><label class="mp2-choice"><input type="checkbox" class="ucm-media-job-scope" value="wallpapers" checked><span><?php echo _('Wallpaper catalogs'); ?></span></label><label class="mp2-choice"><input type="checkbox" class="ucm-media-job-scope" value="special"><span><?php echo _('Non-standard / legacy backgrounds'); ?></span></label><label class="mp2-choice"><input type="checkbox" class="ucm-media-job-scope" value="ringtones" checked><span><?php echo _('Ringtones & audio'); ?></span></label></div></div>
          <div><h3><?php echo _('Target sites'); ?></h3><div class="mp2-choice-list" id="ucm-media-job-targets"><?php foreach($sites as $s): ?><label class="mp2-choice"><input type="checkbox" class="ucm-media-job-target" value="<?php echo (int)$s['id']; ?>"><span><?php echo htmlspecialchars((string)$s['name']); ?><?php echo !empty($s['is_local'])?'<br><span class="mp2-muted">'._('Source site').'</span>':''; ?></span></label><?php endforeach; ?></div></div>
          <div class="mp2-job-summary" id="ucm-media-job-summary"></div>
          <button type="submit" class="mp2-btn mp2-primary" id="ucm-media-job-save"><?php echo _('Save recurring replication job'); ?></button>
        </div>
      </form>
    </section>

    <section class="mp2-surface" data-media-owned-panel="replication">
      <header class="mp2-surface-head"><div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Saved schedules'); ?></span><h3><?php echo _('Automatic replication jobs'); ?></h3></div></header>
      <div class="mp2-pad mp2-table-wrap"><table><thead><tr><th><?php echo _('Job'); ?></th><th><?php echo _('Schedule'); ?></th><th><?php echo _('Scope'); ?></th><th><?php echo _('Targets'); ?></th><th><?php echo _('Last / next run'); ?></th><th><?php echo _('Status'); ?></th><th><?php echo _('Actions'); ?></th></tr></thead><tbody id="ucm-media-jobs"><tr><td colspan="7" class="mp2-muted"><?php echo _('Loading automatic jobs…'); ?></td></tr></tbody></table></div>
    </section>
  </section>

  <section class="mp2-view ucm-media-panel" data-media-panel="diagnostics" data-media-owned-panel="diagnostics" hidden>
    <section class="mp2-surface" data-media-owned-panel="diagnostics">
      <header class="mp2-surface-head">
        <div class="mp2-head-copy"><span class="mp2-kicker"><?php echo _('Unified physical inventory'); ?></span><h3><?php echo _('Asset index (advanced)'); ?></h3><p class="mp2-muted"><?php echo _("This is one view of TFTP assets and every phone-facing HTTP media library. Observed only means the bytes exist and decode, but no retained Media catalog owns them."); ?></p></div>
        <div class="mp2-button-row"><button type="button" class="mp2-btn" id="ucm-media-assets-refresh"><?php echo _('Reload all media'); ?></button><button type="button" class="mp2-btn mp2-primary" id="ucm-media-assets-rescan"><?php echo _('Verify / refresh all media'); ?></button><button type="button" class="mp2-btn" id="ucm-media-assets-reset"><?php echo _('Restart TFTP scan'); ?></button></div>
      </header>
      <div class="mp2-pad">
        <div id="ucm-media-assets-summary" class="mp2-muted"></div>
        <div class="mp2-table-wrap ucm-asset-index-viewport" data-page-size="50"><table><thead><tr><th><?php echo _('ID / status'); ?></th><th><?php echo _('Kind / profile'); ?></th><th><?php echo _('File / manifest'); ?></th><th><?php echo _('Decoded metadata'); ?></th><th><?php echo _('Usage / reason'); ?></th></tr></thead><tbody id="ucm-media-assets"></tbody></table></div>
        <div class="mp2-pagination"><button type="button" class="mp2-btn" id="ucm-media-assets-prev"><?php echo _('Previous'); ?></button><span id="ucm-media-assets-page"><?php echo _('Page 1 of 1'); ?></span><button type="button" class="mp2-btn" id="ucm-media-assets-next"><?php echo _('Next'); ?></button></div>
        <div id="ucm-asset-retirement" hidden aria-live="polite"></div>
      </div>
    </section>
  </section>
</div>
<script>
(function($){
  var hostedSizes=<?php $sizeMap=[];foreach($hostedModels as $hostedModel){$size=\FreePBX\modules\Ucmprovisioner\ModelCatalog::hostedMediaSize((string)$hostedModel['vendor'],(string)$hostedModel['model']);if($size!==null)$sizeMap[(string)$hostedModel['family'].'|'.strtoupper((string)$hostedModel['model'])]=array_map('intval',$size);}echo \FreePBX\modules\Ucmprovisioner::browserJson($sizeMap,JSON_UNESCAPED_SLASHES); ?>;
  var hostedModelLibraries=<?php $libraryMap=['cisco_cnf'=>[],'yealink'=>[],'grandstream'=>[]];foreach($hostedModels as $hostedModel){$family=(string)$hostedModel['family'];if(isset($libraryMap[$family]))$libraryMap[$family][]= ['value'=>strtolower((string)$hostedModel['model']),'label'=>(string)$hostedModel['vendor'].' '.(string)$hostedModel['model']];}echo \FreePBX\modules\Ucmprovisioner::browserJson($libraryMap,JSON_UNESCAPED_SLASHES); ?>;
  var audioModelLibraries=<?php echo \FreePBX\modules\Ucmprovisioner::browserJson($audioModelLibraries,JSON_UNESCAPED_SLASHES); ?>;
  var mediaRoot=$('#ucmp-media-preview-v2');
  function site(){return $('#ucm-media-site').val();}
  var mediaOperation=null,mediaEpoch=0,mediaSiteEpoch=0,replicationCatalogRequest=0;
  function operationSite(){return mediaOperation?mediaOperation.site_id:site();}
  function beginMediaOperation(){
    if(mediaOperation){message('Finish the current Media operation before starting another.',true);return false;}
    mediaEpoch++;mediaSiteEpoch++;replicationCatalogRequest++;mediaPanelLoaded.replication=false;
    mediaOperation={site_id:site(),family:String($('#ucm-audio-family').val()||''),model:String($('#ucm-audio-model').val()||'all'),controls:[]};
    mediaRoot.find('input,select,textarea,button').each(function(){mediaOperation.controls.push([this,this.disabled]);this.disabled=true;});
    return true;
  }
  function endMediaOperation(){var operation=mediaOperation;mediaOperation=null;if(operation)operation.controls.forEach(function(row){row[0].disabled=row[1];});}
  function mediaRequest(options){
    var epoch=options.catalog?mediaSiteEpoch:mediaEpoch,capturedSite=options.data&&typeof options.data.get==='function'?options.data.get('site_id'):(options.data&&options.data.site_id!==undefined?options.data.site_id:operationSite());
    return window.ucmpAjax(options).then(function(response){if(epoch!==(options.catalog?mediaSiteEpoch:mediaEpoch)||String(site())!==String(capturedSite))return rejected('Media context changed; stale response ignored.');return response;});
  }
  ['click','pointerdown','input','change'].forEach(function(type){mediaRoot[0].addEventListener(type,function(event){if(mediaOperation){event.preventDefault();event.stopImmediatePropagation();return;}if(type==='change'||type==='input')mediaEpoch++;},true);});
  function syncAwareRequest(sender,data){var payload=$.extend({},data);return sender(payload).then(function(resp){var r=result(resp);if(!r.sync_edit_required||payload.sync_edit_confirmed)return resp;if(!window.confirm((r.message||'This catalog is automatically synchronized.')+'\n\nContinue and pause automatic sync for only this catalog?'))return resp;payload.sync_edit_confirmed=1;payload.sync_edit_reason='Destination administrator edited this synchronized catalog in Media Manager';return sender(payload).then(function(confirmedResp){return result(confirmedResp).sync_paused?loadCatalogSyncStates().then(function(){return confirmedResp;}):confirmedResp;});});}
  var activeWallpaperRevision='',wallpaperCatalogRevisions={};
  function call(data){
    var capturedSite=operationSite();
    if(data.set_id&&data.expected_revision===undefined)data=$.extend({},data,{expected_revision:String(data.set_id)===String(activeSetId)?activeWallpaperRevision:(wallpaperCatalogRevisions[capturedSite+':'+data.set_id]||'')});
    return syncAwareRequest(function(payload){return mediaRequest({data:$.extend({command:'tftp',site_id:capturedSite},payload)});},data).then(function(resp){
      var r=result(resp);if(data.op==='wallpaper_set_list'&&r.ok)(r.sets||[]).forEach(function(set){wallpaperCatalogRevisions[capturedSite+':'+set.id]=String(set.revision||'');});
      return resp;
    });
  }
  function replicationCall(data){return window.ucmpAjax({data:$.extend({command:'mediareplication'},data)});}
  function configurationSyncCall(data){return mediaRequest({catalog:true,data:$.extend({command:'configurationsync',site_id:operationSite()},data)});}
  function result(resp){return (resp&&resp.result)||{};}
  function message(text,bad){$('#ucm-media-message').stop(true,true).show().toggleClass('alert-danger',!!bad).toggleClass('alert-success',!bad).text(text);}
  function loadCatalogSyncStates(){var epoch=mediaSiteEpoch,wallpaper=configurationSyncCall({op:'states',object_type:'wallpaper_catalog'}),special=configurationSyncCall({op:'states',object_type:'special_catalog'});return $.when(wallpaper,special).done(function(wallResp,specialResp){if(epoch!==mediaSiteEpoch)return;catalogSyncStates={wallpaper_catalog:{},special_catalog:{}};[[wallResp,'wallpaper_catalog'],[specialResp,'special_catalog']].forEach(function(entry){var response=entry[0]&&entry[0][0]!==undefined?entry[0][0]:entry[0],kind=entry[1],r=result(response);(r.items||[]).forEach(function(item){if(String(item.status||'')!=='released')catalogSyncStates[kind][String(item.object_key||'')]=item;});});});}
  function catalogSyncBadge(kind,key){var state=(catalogSyncStates[kind]||{})[key];if(!state)return null;var status=String(state.status||'active').replace(/_/g,' '),source=String(state.source_site_name||state.source_site_key||'source site'),text=status==='active'?('Auto sync · '+source+(state.soft_lock?' · confirmation required to edit':'')):('Auto sync '+status+' · '+source),badge=$('<span class="mp2-chip">').toggleClass('mp2-override',status!=='active').text(text);if(state.pause_reason)badge.attr('title',String(state.pause_reason));return badge;}
  window.ucmpMediaContractCheck=function(){
    var panels=mediaRoot.find('[data-media-panel]'),orphaned=[];
    $('[data-media-owned-panel]').each(function(index){
      var owner=String($(this).data('media-owned-panel')||''),insideRoot=$(this).closest('#ucmp-media-preview-v2').length===1,inside=insideRoot&&owner&&$(this).closest('[data-media-panel="'+owner+'"]').length===1;
      if(!inside)orphaned.push(this.id||owner||('owned-'+index));
    });
    return{ok:panels.length===5&&orphaned.length===0&&panels.filter(':not([hidden])').length===1,visiblePanels:panels.filter(':not([hidden])').map(function(){return String($(this).data('media-panel'));}).get(),orphaned:orphaned};
  };
  function enforceMediaContract(){var state=window.ucmpMediaContractCheck();if(state.ok)return true;mediaRoot.find('[data-media-tab]').prop('disabled',true);message('Fatal Media layout error: a workbench escaped its panel or more than one panel is visible. No Media operation was started.',true);return false;}
  function selectMediaTab(name){var panels=mediaRoot.find('[data-media-panel]'),tabs=mediaRoot.find('[data-media-tab]');panels.prop('hidden',true).removeClass('mp2-active');panels.filter('[data-media-panel="'+name+'"]').prop('hidden',false).addClass('mp2-active');tabs.removeClass('mp2-active active').filter('[data-media-tab="'+name+'"]').addClass('mp2-active active');return enforceMediaContract();}
  mediaRoot.find('[data-media-tab]').on('click',function(){var name=String($(this).data('media-tab')||'wallpapers');if(!selectMediaTab(name))return;loadMediaPanel(name,false);});
  $(document).on('play','.ucm-container audio',function(){var current=this;$('.ucm-container audio').each(function(){if(this!==current)this.pause();});});
  var mediaAssetRows=[],mediaAssetPage=0;
  function renderMediaAssetPage(){
    var body=$('#ucm-media-assets').empty(),size=parseInt($('.ucm-asset-index-viewport').data('page-size'),10)||50,pages=Math.max(1,Math.ceil(mediaAssetRows.length/size)),counts={};
    mediaAssetPage=Math.max(0,Math.min(mediaAssetPage,pages-1));
    mediaAssetRows.forEach(function(asset){var status=String(asset.status||asset.validation_status||asset.state||'discovered');counts[status]=(counts[status]||0)+1;});
    mediaAssetRows.slice(mediaAssetPage*size,(mediaAssetPage+1)*size).forEach(function(asset){
      var status=String(asset.status||asset.validation_status||asset.state||'discovered'),manifest=Array.isArray(asset.manifest)?asset.manifest:[],meta=asset.metadata||{},usage=Array.isArray(asset.usage)?asset.usage:[],issues=Array.isArray(asset.issues)?asset.issues:[],statusClass={verified:'label-success',incomplete:'label-warning',invalid:'label-danger',stale:'label-default',discovered:'label-info'}[status]||'label-default',statusLabel={verified:'Verified',incomplete:'Incomplete',invalid:'Invalid',stale:'Retired',discovered:'Observed only'}[status]||status,provenance=String(asset.provenance||'discovered'),originLabel={generated:'Generated by Media Manager',managed_write:'Written by module',operator_upload:'Operator upload',tftp_observation:'Observed on TFTP',http_observation:'Observed in HTTP media library',discovered:'Observed on TFTP'}[provenance]||provenance;
      body.append($('<tr>').toggleClass('text-muted',status==='stale').append(
        $('<td>').append($('<code>').text('#'+String(asset.asset_id||asset.id||'?')),' ',$('<span class="label">').addClass(statusClass).text(statusLabel)),
        $('<td>').append($('<strong>').text(asset.kind||''),$('<small class="ucm-fw-compatible">').text(asset.profile||'No compatibility profile'),$('<small class="ucm-fw-compatible">').text('Origin: '+originLabel)),
        $('<td>').append($('<code>').text(asset.filename||''),$('<small class="ucm-fw-compatible">').text(manifest.map(function(file){return String(file.role||'file')+': '+String(file.relative_path||file.filename||'');}).join(' / '))),
        $('<td>').append($('<span>').text([meta.width&&meta.height?(meta.width+'x'+meta.height):'',meta.audio_codec||'',meta.sample_rate?(meta.sample_rate+' Hz'):'',asset.asset_version?('v'+asset.asset_version):''].filter(Boolean).join(' / ')||asset.content_type||'Not decoded'),$('<small class="ucm-fw-compatible">').text('SHA-256 '+String(asset.content_sha256||''))),
        $('<td>').append($('<span>').text(usage.map(function(use){return String(use.type||'use')+': '+String(use.label||use.id||'');}).join(' / ')||'Unused'),$('<small class="ucm-fw-compatible text-warning">').text(issues.map(function(issue){return issue.message||issue.code;}).join(' / ')), /^\d+$/.test(String(asset.asset_id||''))?$('<button type="button" class="mp2-btn">').text('Retirement / references').on('click',function(){openRetirement(asset);}):$('<small>').text('HTTP library: use its owning catalog controls'))
      ));
    });
    if(!body.children().length)body.append('<tr><td colspan="5" class="text-muted">No managed assets have been cataloged on this site.</td></tr>');
    $('#ucm-media-assets-summary').text(Object.keys(counts).sort().map(function(status){var label={verified:'verified',incomplete:'incomplete',invalid:'invalid',stale:'retired',discovered:'observed only'}[status]||status;return counts[status]+' '+label;}).join(' / ')||'No catalog entries');
    $('#ucm-media-assets-page').text('Page '+(mediaAssetPage+1)+' of '+pages+' · '+mediaAssetRows.length+' assets');
    $('#ucm-media-assets-prev').prop('disabled',mediaAssetPage===0);
    $('#ucm-media-assets-next').prop('disabled',mediaAssetPage>=pages-1);
  }
  function loadMediaAssets(){
    $('#ucm-media-assets').html('<tr><td colspan="5">Loading unified asset catalog…</td></tr>');
    var capturedSite=site(),rows=[],seen={},incomplete=false;
    function page(offset){call({op:'managed_asset_inventory',site_id:capturedSite,state:'current',limit:500,offset:offset}).done(function(resp){if(String(site())!==String(capturedSite))return;var r=result(resp);if(!r.ok){message(r.message||'Cannot load asset catalog',true);return;}(r.catalog_assets||r.assets||[]).forEach(function(asset){var key=String(asset.asset_id||asset.id||asset.asset_key);if(!seen[key]){seen[key]=true;rows.push(asset);}});incomplete=incomplete||r.http_inventory_truncated||r.http_media_ok===false;if(r.truncated&&Number(r.next_offset)>offset){page(Number(r.next_offset));return;}mediaAssetRows=rows;mediaAssetPage=0;renderMediaAssetPage();message(incomplete?'TFTP pages loaded; HTTP inventory is incomplete. Narrow the HTTP library before assuming an asset is unused.':(r.message||'Unified media inventory loaded.'),incomplete);}).fail(function(){message('Asset catalog request failed; partial results were not presented as complete.',true);});}
    page(0);
  }
  var retirement=null,retirementPreviewRequest=0;
  function openRetirement(asset){
    retirement={site_id:site(),asset_id:Number(asset.asset_id),replacement_asset_id:0,expected_revision:''};
    var panel=$('#ucm-asset-retirement').empty().prop('hidden',false),select=$('<select class="form-control">').append($('<option value="0">').text('No reassignment'));
    mediaAssetRows.filter(function(row){return /^\d+$/.test(String(row.asset_id||''))&&Number(row.asset_id)!==Number(asset.asset_id)&&row.kind===asset.kind&&String(row.profile||'')===String(asset.profile||'')&&row.selectable;}).forEach(function(row){select.append($('<option>').val(row.asset_id).text('#'+row.asset_id+' '+row.filename));});
    select.on('change',function(){retirement.replacement_asset_id=Number(this.value);previewRetirement();});
    panel.append($('<h4>').text('Retire #'+asset.asset_id+' '+asset.filename),$('<p>').text('Captured site '+retirement.site_id+'. References, required dependencies and retained rollback data remain protected. Retirement quarantines bytes; it does not free retained disk space.'),$('<label>').text('Optional compatible replacement').append(select),$('<div id="ucm-asset-retirement-detail">'),$('<button type="button" class="mp2-btn">').text('Refresh preview').on('click',previewRetirement),$('<button type="button" class="mp2-btn" id="ucm-asset-reassign">').text('Reassign configuration references').on('click',function(){applyRetirement('asset_reassign');}),$('<button type="button" class="mp2-btn" id="ucm-asset-retire">').text('Quarantine unreferenced asset').on('click',function(){applyRetirement('asset_retire');}));
    previewRetirement();
  }
  function previewRetirement(){
    if(!retirement||String(site())!==String(retirement.site_id)){message('Site changed; reopen the asset on the intended site.',true);return;}
    var captured=$.extend({},retirement),request=++retirementPreviewRequest;retirement.expected_revision='';$('#ucm-asset-reassign,#ucm-asset-retire').prop('disabled',true);
    call($.extend({},captured,{op:'asset_retirement_preview'})).done(function(resp){if(request!==retirementPreviewRequest||!retirement||retirement.asset_id!==captured.asset_id||retirement.replacement_asset_id!==captured.replacement_asset_id||String(site())!==String(captured.site_id))return;var r=result(resp),p=r.preview||{},detail=$('#ucm-asset-retirement-detail').empty();if(!r.ok){detail.text(r.message||'Preview failed');return;}retirement.expected_revision=p.revision;detail.append($('<p>').text('Protected: '+p.protected_bytes+' bytes · eligible for quarantine: '+p.reclaimable_bytes+' bytes · '+p.reference_count+' reference(s)'));(p.references||[]).forEach(function(ref){detail.append($('<div>').text(ref.type+': '+String(ref.label||ref.id||'')+(ref.mutable?' (configuration may be reassigned)':' (protected)')));});(p.dependencies||[]).forEach(function(dep){detail.append($('<div>').text('Dependency: '+String(dep.filename||dep.asset_key||'')+(dep.required?' (required)':'')));});(p.blockers||[]).forEach(function(blocker){detail.append($('<div class="text-danger">').text(blocker));});$('#ucm-asset-reassign').prop('disabled',!p.can_reassign);$('#ucm-asset-retire').prop('disabled',!p.can_retire);}).fail(function(){message('Retirement preview failed; no changes made.',true);});
  }
  function removeCiscoRingtone(name,ext){
    if(mediaOperation||!confirm('Delete '+name+'?')||!beginMediaOperation())return;
    call({op:'delete',path:name}).then(function(resp){
      var removed=result(resp);
      if(!removed.ok)return rejected(removed.message||'Ringtone was not removed');
      return call({op:'regen_ringlist',wideband:ext==='rwb'?1:0}).then(function(indexResp){
        var index=result(indexResp);
        message(index.ok?'Ringtone removed and index rebuilt':'Ringtone removed; index rebuild needs attention: '+(index.message||'unknown result'),!index.ok);
        loadRings();
      });
    }).fail(function(error){message(typeof error==='string'?error:'Ringtone operation response is uncertain; inspect its state before retrying.',true);}).always(endMediaOperation);
  }
  function applyRetirement(op){
    if(mediaOperation)return;
    if(!retirement||!retirement.expected_revision||String(site())!==String(retirement.site_id)){message('A fresh preview on the captured site is required.',true);return;}
    if(!confirm(op==='asset_reassign'?'Reassign only the exact configuration references in this preview? Affected phones still need rebuilding.':'Move this unreferenced asset into retained private recovery storage?'))return;
    if(!beginMediaOperation())return;
    var captured=$.extend({},retirement);retirement.expected_revision='';$('#ucm-asset-reassign,#ucm-asset-retire').prop('disabled',true);
    call($.extend({},captured,{op:op})).done(function(resp){var r=result(resp);message(r.message||'Asset operation completed',!r.ok);if(r.ok&&r.recovery_id){var recoveryId=r.recovery_id;$('#ucm-asset-retirement-detail').empty().append($('<p>').text('Recovery ID: '+recoveryId),$('<button type="button" class="mp2-btn">').text('Restore retained asset').on('click',function(){if(String(site())!==String(captured.site_id)){message('Return to the captured site before restoring.',true);return;}if(!beginMediaOperation())return;call({op:'asset_restore',site_id:captured.site_id,recovery_id:recoveryId}).done(function(response){var restored=result(response);message(restored.message||'Recovery completed',!restored.ok);if(restored.ok)loadMediaAssets();}).fail(function(){message('Recovery response is uncertain; inspect retained state before retrying.',true);}).always(endMediaOperation);}));loadMediaAssets();}else previewRetirement();}).fail(function(){message('Asset operation response failed. Verify current state before retrying.',true);}).always(endMediaOperation);
  }
  function dir(){return 'Desktops/'+String($('#ucm-media-model option:selected').data('dir')||'');}
  function hostedRetirementRequest(send,data){
    if(['delete','special_asset_delete','special_catalog_delete'].indexOf(data.op)===-1)return send(data);
    if(!beginMediaOperation())return rejected('Another Media operation is active.');
    var captured=$.extend({},data),panel=$('#ucm-hosted-retirement').empty().prop('hidden',false);
    panel.append($('<h4>').text('HTTP media retirement'),$('<p>').text('Reading references on captured site '+captured.site_id+' / '+captured.family+' / '+captured.model+'. No file has been changed.'));
    return send($.extend({},captured,{op:'retirement_preview',retire_op:captured.op})).then(function(response){
      var reply=result(response),plan=reply.retirement_preview;
      if(!reply.ok||!plan){message(reply.message||'Retirement preview failed; no changes made.',true);return rejected(reply.message||'Missing retirement preview');}
      if(String(plan.site_id)!==String(captured.site_id)||plan.family!==captured.family||plan.model!==captured.model||!plan.revision)return rejected('Retirement preview scope differs from the captured library.');
      var details=['Site '+captured.site_id+' / '+captured.family+' / '+captured.model+' / '+plan.filename,'Protected: '+plan.protected_bytes+' bytes; eligible for quarantine: '+plan.reclaimable_bytes+' bytes'];
      (plan.references||[]).forEach(function(ref){details.push('Reference: '+String(ref.type||'')+' '+String(ref.label||ref.id||''));});
      (plan.dependencies||[]).forEach(function(dep){details.push('Retained dependency: '+String(dep.filename||dep.asset_key||''));});
      (plan.blockers||[]).forEach(function(blocker){details.push('Blocked: '+blocker);});
      if(plan.replacement_instruction)details.push(plan.replacement_instruction);
      panel.empty().append($('<h4>').text('HTTP retirement dependency preview'));
      details.forEach(function(line){panel.append($('<p>').text(line));});
      panel.append($('<p>').text(plan.retention||'Quarantine retains the public file and editable source; it does not free retained disk space.'));
      if(!plan.can_retire){message('Protected HTTP media was not changed. Resolve the listed references, then reopen its preview.',true);return rejected('HTTP asset is protected');}
      if(!window.confirm(details.join('\n')+'\n\nQuarantine this exact file and its complete editable catalog? Retained bytes remain recoverable.'))return rejected('Retirement cancelled; no file changed.');
      return send($.extend({},captured,{expected_revision:plan.revision})).then(function(retiredResponse){
        var retired=result(retiredResponse);
        if(retired.ok&&retired.recovery_id){
          var recoveryId=retired.recovery_id;
          panel.empty().append($('<h4>').text('HTTP media retained for recovery'),$('<p>').text('Site '+captured.site_id+' / '+captured.family+' / '+captured.model+' · Recovery ID: '+recoveryId));
          panel.append($('<button type="button" class="mp2-btn">').text('Restore retained HTTP asset').on('click',function(){
            if(String(site())!==String(captured.site_id)){message('Return to the captured site before restoring.',true);return;}
            if(!window.confirm('Restore recovery '+recoveryId+' to site '+captured.site_id+' / '+captured.family+' / '+captured.model+' without overwriting another revision?')||!beginMediaOperation())return;
            send({command:'mediaasset',op:'restore_retired',site_id:captured.site_id,family:captured.family,model:captured.model,recovery_id:recoveryId}).always(endMediaOperation).done(function(restoredResponse){var restored=result(restoredResponse);message(restored.message||'Recovery response received',!restored.ok);if(restored.ok){panel.append($('<p>').text('Restored; recovery receipt remains retained.'));loadRings();loadHosted();loadMediaAssets();}}).fail(function(){message('Recovery response is uncertain. Inspect the retained recovery ID before retrying.',true);});
          }));
        }
        return retiredResponse;
      });
    }).always(endMediaOperation);
  }
  function hostedCall(data){var captured=$.extend({command:'mediaasset',site_id:operationSite(),family:'cisco_cnf',model:'all'},data);return hostedRetirementRequest(function(payload){return syncAwareRequest(function(request){return mediaRequest({data:request});},payload);},captured);}
  $('#ucm-media-site').on('change',function(){if(!mediaOperation)$('#ucm-hosted-retirement').prop('hidden',true);});
  function hostedPreset(){var f=$('#ucm-hosted-family').val(),model=String($('#ucm-hosted-model').val()||'').toUpperCase(),verified=hostedSizes[f+'|'+model]||null,w=f==='cisco_cnf'?90:320,h=f==='cisco_cnf'?56:240,help='Prepared brand library for audio or a firmware-specific file documented outside the standard wallpaper catalog.';if(verified){w=verified[0];h=verified[1];help='Verified '+w+'×'+h+' special-file canvas. Standard wallpaper generation still belongs in Wallpaper catalogs.';}if(f==='cisco_cnf')help='Cisco 7940/7960 require a 90×56 Windows BMP dithered to the four grayscale levels their LCD can display. The photo editor performs this conversion.';$('#ucm-hosted-width').val(w);$('#ucm-hosted-height').val(h);$('#ucm-hosted-help').text(help+' The returned HTTP URL can be selected in site defaults or a template.');renderHosted();}
  function hostedModels(){var select=$('#ucm-hosted-model').empty().append($('<option value="all">').text('Cisco 7940/7960 shared logo library')).val('all').prop('disabled',true);hostedPreset();loadHosted();}
  function resetSpecialCatalog(){mediaEpoch++;if(hostedSourceImage&&hostedSourceImage._objectUrl)URL.revokeObjectURL(hostedSourceImage._objectUrl);hostedSourceImage=null;hostedSourceData='';hostedSourceName='';hostedSourceDirty=false;activeSpecialCatalogId='';activeSpecialRevision='';specialPublishedName='';$('#ucm-hosted-name,#ucm-hosted-file').val('');$('#ucm-hosted-zoom').val(100);$('#ucm-hosted-x,#ucm-hosted-y').val(0);$('#ucm-hosted-invert').prop('checked',false);$('#ucm-hosted-publish').text('Save special catalog');renderHosted();}
  function openSpecialCatalog(id){var epoch=++mediaEpoch;message('Loading retained special catalog…');hostedCall({op:'special_catalog_get',catalog_id:id,include_source:1}).done(function(resp){var r=result(resp);if(!r.ok){message(r.message||'Cannot open special catalog',true);return;}var catalog=r.catalog||{},manifest=r.manifest||{},img=new Image();img.onload=function(){if(epoch!==mediaEpoch)return;if(hostedSourceImage&&hostedSourceImage._objectUrl)URL.revokeObjectURL(hostedSourceImage._objectUrl);hostedSourceImage=img;hostedSourceData=String(r.source||'');hostedSourceName=String(catalog.source_name||manifest.source_name||'source');hostedSourceDirty=false;activeSpecialCatalogId=String(catalog.catalog_id||id);activeSpecialRevision=String(catalog.manifest_sha256||'');specialPublishedName=String(catalog.published_name||manifest.published_name||'');$('#ucm-hosted-name').val(catalog.name||activeSpecialCatalogId);$('#ucm-hosted-zoom').val((catalog.transform||{}).zoom||100);$('#ucm-hosted-x').val((catalog.transform||{}).x||0);$('#ucm-hosted-y').val((catalog.transform||{}).y||0);$('#ucm-hosted-invert').prop('checked',!!((catalog.settings||manifest.settings||{}).invert));$('#ucm-hosted-publish').text('Save catalog changes');renderHosted();message('Special catalog opened with its retained source and placement.',false);};img.onerror=function(){message('The retained special-catalog source could not be decoded.',true);};img.src='data:'+String(catalog.source_mime||manifest.source_mime||'image/png')+';base64,'+String(r.source||'');});}
  function renderLooseHostedItems(grid,items){(items||[]).forEach(function(item){var display=String(item.name||''),catalogId=safeName(display.replace(/\.bmp$/i,'')),valid=item.content_verified===true,preview=$('<div class="mp2-thumb">').append($('<img alt="">').attr('src',item.preview_url||item.url)),identity=$('<div class="mp2-cell">').append($('<span class="mp2-cell-label">').text('Loose served filename'),$('<code>').text(display),$('<span class="mp2-muted">').text('No retained catalog owns this file')),scope=$('<div class="mp2-cell mp2-scope">').append($('<span class="mp2-cell-label">').text('Target'),$('<span>').text('Cisco 7940/7960 · HTTP media library'),$('<span class="mp2-muted">').text('Phone URL remains unchanged when adopted')),state=$('<div class="mp2-cell mp2-overrides">').append($('<span class="mp2-cell-label">').text('Media Manager state'),$('<span>').toggleClass('text-danger',!valid).text(valid?'Observed loose BMP · '+Number(item.usage_count||0)+' assignment(s)':String(item.validation_message||'Invalid BMP'))),actions=$('<div class="mp2-actions">').append($('<button type="button" class="mp2-btn mp2-primary">').prop('disabled',!valid).text('Adopt into catalog').on('click',function(){hostedCall({op:'special_asset_adopt',name:display,catalog_id:catalogId,catalog_name:display.replace(/\.bmp$/i,'')}).done(function(resp){var adopted=result(resp);message(adopted.message||'Loose BMP adoption finished',!adopted.ok);if(adopted.ok){activeSpecialCatalogId=String((adopted.catalog||{}).catalog_id||catalogId);loadHosted();mediaPanelLoaded.diagnostics=false;mediaPanelLoaded.replication=false;}});}),$('<button type="button" class="mp2-btn mp2-danger">').text('Delete served file').on('click',function(){if(!confirm('Delete loose served file '+display+'? Any saved phone/template URL will become unavailable.'))return;hostedCall({op:'special_asset_delete',name:display}).done(function(resp){var deleted=result(resp);message(deleted.message||'Loose BMP delete finished',!deleted.ok);if(deleted.ok){loadHosted();mediaPanelLoaded.diagnostics=false;}});}));grid.append($('<article class="mp2-catalog mp2-unmanaged-media">').attr('data-unmanaged-name',display).append(preview,identity,scope,state,actions));});}
  function loadHosted(){
    var grid=$('#ucm-hosted-list').html('<p class="mp2-muted">Loading every served Cisco CNF background…</p>');
    return hostedCall({op:'special_catalog_list'}).done(function(resp){
      var r=result(resp);grid.empty();
      if(!r.ok){grid.append($('<p class="text-danger">').text(r.message||'Cannot list special catalogs'));return;}
      (r.catalogs||[]).forEach(function(catalog){
        var card=$('<article class="mp2-catalog">').attr('data-special-set-id',catalog.catalog_id).toggleClass('mp2-selected',String(activeSpecialCatalogId)===String(catalog.catalog_id));
        var preview=$('<button type="button" class="mp2-thumb">').append($('<img alt="">').attr('src',catalog.preview_url||catalog.url)).on('click',function(){openSpecialCatalog(catalog.catalog_id);});
        var name=$('<input type="text">').val(catalog.name);
        var identity=$('<div class="mp2-cell">').append(
          $('<span class="mp2-cell-label">').text('Catalog name'),
          $('<div class="mp2-name-row">').append(name,$('<button type="button" class="mp2-btn">').text('Rename').on('click',function(){
            var display=String(name.val()||'').trim(),newId=safeName(display);
            if(!display||!newId)return message('Enter a valid special catalog name.',true);
            hostedCall({op:'special_catalog_rename',expected_manifest_sha256:catalog.manifest_sha256,catalog_id:catalog.catalog_id,new_catalog_id:newId,name:display}).done(function(renameResp){
              var renamed=result(renameResp);message(renamed.message||'Special catalog renamed',!renamed.ok);
              if(renamed.ok&&String(activeSpecialCatalogId)===String(catalog.catalog_id))activeSpecialCatalogId=String((renamed.catalog||{}).catalog_id||newId);
              loadHosted();mediaPanelLoaded.replication=false;
            });
          })),$('<span class="mp2-muted">').text(catalog.catalog_id+' · revision '+catalog.revision)
        );
        var scope=$('<div class="mp2-cell mp2-scope">').append($('<span class="mp2-cell-label">').text('Target'),$('<span>').text('Cisco 7940/7960 · 90 × 56 palette BMP'),$('<span class="mp2-muted">').text('Published as '+catalog.published_name));
        var state=$('<div class="mp2-cell mp2-overrides">').append($('<span class="mp2-cell-label">').text('Retained source and placement'),$('<span>').text(catalog.source_name+' · scale '+Number((catalog.transform||{}).zoom||100)+'%'),$('<span>').toggleClass('text-danger',!catalog.complete).text(catalog.complete?'Valid managed output':(catalog.issues||[]).join(' / ')));
        var syncBadge=catalogSyncBadge('special_catalog','special:'+String(catalog.catalog_id));if(syncBadge)state.append(syncBadge);
        var actions=$('<div class="mp2-actions">').append(
          $('<button type="button" class="mp2-btn mp2-primary">').text('Edit / replace source').on('click',function(){openSpecialCatalog(catalog.catalog_id);}),
          $('<button type="button" class="mp2-btn mp2-danger">').text('Delete').on('click',function(){
            if(!confirm('Delete the complete special catalog '+catalog.name+'?'))return;
            hostedCall({op:'special_catalog_delete',catalog_id:catalog.catalog_id}).done(function(deleteResp){
              var deleted=result(deleteResp);message(deleted.message||'Special catalog deleted',!deleted.ok);
              if(deleted.ok&&String(activeSpecialCatalogId)===String(catalog.catalog_id))resetSpecialCatalog();
              loadHosted();mediaPanelLoaded.replication=false;
            });
          })
        );
        card.append(preview,identity,scope,state,actions);grid.append(card);
      });
      renderLooseHostedItems(grid,r.unmanaged_items||[]);
      if(!grid.children().length)grid.html('<div class="mp2-selected-status"><strong>No served legacy backgrounds yet</strong><span>Choose one source image and save the first Cisco 7940/7960 catalog.</span></div>');
    });
  }
  function safeName(value){return String(value||'').trim().replace(/\.png$/i,'').replace(/[^A-Za-z0-9_-]+/g,'_').replace(/^_+|_+$/g,'').substr(0,40);}
  function upload(path,file,filename,capturedSite){var fd=new FormData();fd.append('command','tftp');fd.append('op','upload');fd.append('compact_name','1');fd.append('site_id',capturedSite||operationSite());fd.append('path',path);fd.append('file',file,filename||file.name||'upload.bin');return mediaRequest({data:fd});}
  var sourceImage=null,hostedSourceImage=null,hostedNeedsRender=false,hostedSourceData='',hostedSourceName='',hostedSourceDirty=false,activeSpecialCatalogId='',activeSpecialRevision='',specialPublishedName='',hostedEditorViewScale=1,hostedDrag=null,hostedCoverageOk=false,sourceDirty=false,activeSetId='',activeProfileKey='',wallpaperState={variants:{}},profileImages={},profileSourceDirty={},profileSourceRemoved={},editorViewScale=1,wallDrag=null,ringObjectUrls=[],mediaJobsById={},catalogSyncStates={wallpaper_catalog:{},special_catalog:{}},mediaPanelLoaded={wallpapers:false,legacy:false,audio:false,replication:false,diagnostics:false};
  function profileFrom(o){return{key:String(o.data('key')||''),vendor:String(o.data('vendor')||''),series:String(o.data('series')||''),seriesLabel:String(o.data('series-label')||''),models:String(o.data('models')||'').split(',').filter(Boolean),delivery:String(o.data('delivery')||''),format:String(o.data('format')||'png'),maxBytes:parseInt(o.data('max')||0,10),dir:String(o.data('dir')||''),tones:parseInt(o.data('tones')||0,10),fw:parseInt(o.data('fw')||320,10),fh:parseInt(o.data('fh')||212,10),tw:parseInt(o.data('tw')||0,10),th:parseInt(o.data('th')||0,10)};}
  function profile(){return profileFrom($('#ucm-media-model option:selected'));}
  function allProfiles(){return $('#ucm-media-model option').map(function(){return profileFrom($(this));}).get();}
  function profiles(){var brand=String($('#ucm-bg-brand').val()||''),series=String($('#ucm-bg-series').val()||''),model=String($('#ucm-bg-model-scope').val()||'');return allProfiles().filter(function(p){return(!brand||p.vendor===brand)&&(!series||p.series===series)&&(!model||p.models.indexOf(model)!==-1);});}
  function currentScope(){var brand=String($('#ucm-bg-brand').val()||''),series=String($('#ucm-bg-series').val()||''),model=String($('#ucm-bg-model-scope').val()||''),label=model?(brand+' '+model):(series?($('#ucm-bg-series option:selected').text()):(brand?(brand+' — all supported profiles'):'All supported models'));return{brand:brand,series:series,model:model,label:label};}
  function targetModels(p){var model=String($('#ucm-bg-model-scope').val()||'');return model&&p.models.indexOf(model)!==-1?[model]:p.models.slice();}
  function updateCoverageFields(reset){var mode=String($('#ucm-bg-coverage').val()||'all');$('#ucm-bg-brand-field').toggleClass('mp2-hidden',mode==='all');$('#ucm-bg-series-field').toggleClass('mp2-hidden',mode==='all'||mode==='brand');$('#ucm-bg-model-field').toggleClass('mp2-hidden',mode!=='model');if(reset){if(mode==='all')$('#ucm-bg-brand').val('');$('#ucm-bg-series,#ucm-bg-model-scope').val('');refreshWallpaperScope();}}
  function refreshWallpaperScope(){var brand=String($('#ucm-bg-brand').val()||''),oldSeries=String($('#ucm-bg-series').val()||''),series=$('#ucm-bg-series').empty().append($('<option value="">').text(brand?'All supported '+brand+' profiles':'Choose a brand first')),seen={};allProfiles().filter(function(p){return brand&&p.vendor===brand;}).forEach(function(p){if(!seen[p.series]){seen[p.series]=true;series.append($('<option>').val(p.series).text(p.seriesLabel+' · '+p.fw+'×'+p.fh));}});series.prop('disabled',!brand);if(oldSeries&&seen[oldSeries])series.val(oldSeries);var selectedSeries=String(series.val()||''),model=$('#ucm-bg-model-scope').empty().append($('<option value="">').text(selectedSeries?'All models in selected profile':'Select a profile/series first')),models={};allProfiles().filter(function(p){return p.vendor===brand&&p.series===selectedSeries;}).forEach(function(p){p.models.forEach(function(name){models[name]=true;});});Object.keys(models).sort().forEach(function(name){model.append($('<option>').val(name).text(name));});model.prop('disabled',!selectedSeries);$('#ucm-media-model option').each(function(){var p=profileFrom($(this)),show=(!brand||p.vendor===brand)&&(!selectedSeries||p.series===selectedSeries);$(this).prop('disabled',!show).toggle(show);});if($('#ucm-media-model option:selected').prop('disabled'))$('#ucm-media-model option:not(:disabled)').first().prop('selected',true);activeProfileKey=profile().key;restoreTransform(activeProfileKey);updatePath();}
  function defaultTransform(){return{full:{zoom:100,x:0,y:0},thumbnail:{zoom:100,x:0,y:0}};}
  function rowFor(key){var row=wallpaperState.variants[key]||{};if(typeof row.enabled==='undefined')row.enabled=true;wallpaperState.variants[key]=row;return row;}
  function transformFor(key){var row=rowFor(key),d=defaultTransform();return{full:$.extend({},d.full,row.full_transform||row.full||{}),thumbnail:$.extend({},d.thumbnail,row.thumbnail_transform||row.thumbnail||{})};}
  function imageFor(key){return profileImages[key]||sourceImage;}
  function rememberTransform(key){if(!key)return;var row=rowFor(key),p=profile();row.enabled=$('#ucm-bg-profile-enabled').is(':checked');row.invert=!!(p.tones&&$('#ucm-bg-profile-invert').is(':checked'));row.full_transform={zoom:parseInt($('#ucm-full-zoom').val(),10),x:parseInt($('#ucm-full-x').val(),10),y:parseInt($('#ucm-full-y').val(),10)};row.thumbnail_transform={zoom:parseInt($('#ucm-thumb-zoom').val(),10),x:parseInt($('#ucm-thumb-x').val(),10),y:parseInt($('#ucm-thumb-y').val(),10)};wallpaperState.variants[key]=row;}
  function restoreTransform(key){var t=transformFor(key),row=rowFor(key),p=profile();$('#ucm-full-zoom').val(t.full.zoom);$('#ucm-full-x').val(t.full.x);$('#ucm-full-y').val(t.full.y);$('#ucm-thumb-zoom').val(t.thumbnail.zoom);$('#ucm-thumb-x').val(t.thumbnail.x);$('#ucm-thumb-y').val(t.thumbnail.y);$('#ucm-bg-profile-enabled').prop('checked',row.enabled!==false);$('#ucm-bg-profile-invert').prop('checked',!!row.invert);$('#ucm-bg-profile-invert-wrap').toggleClass('mp2-hidden',!p.tones);$('#ucm-bg-profile-source').val('');updateProfileState();}
  function movementRange(frame,output){return frame>=output?Math.max(0,(frame-output)/2):Math.max(0,(frame+output)/2-Math.min(32,output/4));}
  function cropGeometry(cw,ch,image,zoom,x,y){if(!image||!image.naturalWidth||!image.naturalHeight)return null;var cover=Math.max(cw/image.naturalWidth,ch/image.naturalHeight),scale=cover*(zoom/100),dw=image.naturalWidth*scale,dh=image.naturalHeight*scale,rx=movementRange(dw,cw),ry=movementRange(dh,ch),dx=(cw-dw)/2+(x/100)*rx,dy=(ch-dh)/2+(y/100)*ry;return{cover:cover,dw:dw,dh:dh,dx:dx,dy:dy,rx:rx,ry:ry,covered:dx<=.5&&dy<=.5&&dx+dw>=cw-.5&&dy+dh>=ch-.5};}
  function paint(canvas,zoom,x,y,image){var ctx=canvas.getContext('2d'),cw=canvas.width,ch=canvas.height,g=cropGeometry(cw,ch,image,zoom,x,y);ctx.clearRect(0,0,cw,ch);if(!g)return;ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.drawImage(image,g.dx,g.dy,g.dw,g.dh);}
  function quantizeGrayscale(canvas,tones,invert){if(!tones)return;var ctx=canvas.getContext('2d'),image=ctx.getImageData(0,0,canvas.width,canvas.height),data=image.data,step=255/(tones-1),bayer=[0,8,2,10,12,4,14,6,3,11,1,9,15,7,13,5];for(var y=0;y<canvas.height;y++){for(var x=0;x<canvas.width;x++){var i=(y*canvas.width+x)*4,lum=.2126*data[i]+.7152*data[i+1]+.0722*data[i+2];if(invert)lum=255-lum;var adjust=(bayer[(y%4)*4+(x%4)]-7.5)*(step/16),gray=Math.max(0,Math.min(255,Math.round((lum+adjust)/step)*step));data[i]=data[i+1]=data[i+2]=gray;}}ctx.putImageData(image,0,0);}
  function hasCustomSource(row){return !!(profileImages[activeProfileKey]||row.source_mode==='custom'||row.source_file||row.custom_source);}
  function profileState(key){var row=rowFor(key),t=transformFor(key),custom=!!(profileImages[key]||row.source_mode==='custom'||row.source_file||row.custom_source),edited=t.full.zoom!==100||t.full.x!==0||t.full.y!==0;return row.enabled===false?'Disabled / retained':(custom?'Alternate image':(row.invert?'Inverted grayscale':(edited?'Custom placement':'Inherits master')));}
  function renderProfileCards(){
    var actions=$('#ucm-bg-profile-actions').detach(),list=$('#ucm-bg-profile-list').empty(),visible=$('#ucm-media-model option:not(:disabled)'),selectedCard=null;
    visible.each(function(){var option=$(this),p=profileFrom(option),state=profileState(p.key),card=$('<article class="mp2-profile ucm-bg-profile-card">').attr('data-profile-key',p.key).toggleClass('mp2-selected',p.key===activeProfileKey),select=$('<button type="button" class="mp2-profile-select">');select.append($('<span class="mp2-profile-title">').append($('<span>').text(p.fw+' × '+p.fh),$('<span class="mp2-badge">').toggleClass('mp2-custom',state!=='Inherits master'&&state!=='Disabled / retained').toggleClass('mp2-disabled',state==='Disabled / retained').text(state)),$('<span class="mp2-profile-description">').text(p.vendor+' · '+p.seriesLabel+' · '+p.models.join(', ')),$('<span class="mp2-profile-output">').text(p.delivery==='cisco_list'?'Cisco catalog output':p.format.toUpperCase()+' vendor library output'));select.on('click',function(){if(p.key===activeProfileKey)return;$('#ucm-media-model option').filter(function(){return String($(this).data('key'))===p.key;}).prop('selected',true);$('#ucm-media-model').trigger('change');});card.append(select);if(p.key===activeProfileKey)selectedCard=card;list.append(card);});
    $('#ucm-bg-scope-summary').text(visible.length+' supported profile(s) · '+profiles().reduce(function(total,p){return total+targetModels(p).length;},0)+' compatible model entries');
    if(selectedCard)selectedCard.append(actions);else list.after(actions);
    if(!list.children().length)list.html('<p class="mp2-muted">No documented wallpaper profile matches this scope.</p>');
  }
  function updateProfileLabels(){$('#ucm-media-model option').each(function(){var option=$(this),key=String(option.data('key')||''),base=String(option.data('base-label')||option.text());if(!option.data('base-label'))option.data('base-label',base);option.text(base+' ['+profileState(key).toLowerCase()+']');});renderProfileCards();}
  function updateProfileState(){var p=profile(),label=profileState(activeProfileKey),custom=label==='Alternate image';$('#ucm-bg-profile-state').text(label);$('#ucm-bg-profile-badge').text(label==='Inherits master'?'Base image · inherited':label).toggleClass('mp2-custom',label!=='Inherits master'&&label!=='Disabled / retained').toggleClass('mp2-disabled',label==='Disabled / retained');$('#ucm-bg-profile-heading').text('Editing '+p.fw+' × '+p.fh);$('#ucm-bg-output-tag').text(p.fw+' × '+p.fh+' output');$('#ucm-bg-profile-source-remove').prop('disabled',!custom);updateProfileLabels();}
  function loadProfileImage(key){var epoch=mediaEpoch,row=rowFor(key),deferred=$.Deferred();if(profileImages[key]||!activeSetId||!(row.source_mode==='custom'||row.source_file||row.custom_source)){deferred.resolve();return deferred.promise();}call({op:'wallpaper_profile_source_get',set_id:activeSetId,profile:key}).done(function(resp){var r=result(resp);if(!r.ok){deferred.reject(r.message||'Profile source unavailable');return;}var img=new Image();img.onload=function(){if(epoch!==mediaEpoch){deferred.reject('Wallpaper context changed');return;}profileImages[key]=img;row.source_mode=String(r.source_mode||'custom');row.source=String(r.source_name||row.source||'');deferred.resolve();};img.onerror=function(){deferred.reject('Profile source could not be decoded');};img.src='data:'+r.mime+';base64,'+r.source;}).fail(function(){deferred.reject('Profile source request failed');});return deferred.promise();}
  function stageDimensions(p){return{width:Math.max(1,p.fw),height:Math.max(1,p.fh)};}
  function fitWallScene(g,stage,force){var host=document.getElementById('ucm-wall-stage-host'),scene=document.getElementById('ucm-wall-scene'),fit=force||$('#ucm-wall-fit-handles').is(':checked'),left=Math.min(0,g?g.dx:0),top=Math.min(0,g?g.dy:0),right=Math.max(stage.width,g?g.dx+g.dw:stage.width),bottom=Math.max(stage.height,g?g.dy+g.dh:stage.height),unionW=Math.max(1,right-left),unionH=Math.max(1,bottom-top),hostW=Math.max(1,host.clientWidth),hostH=Math.max(1,host.clientHeight),base=fit?Math.min((hostW-40)/unionW,(hostH-40)/unionH):1,viewZoom=Math.max(.25,Math.min(3,(parseInt($('#ucm-wall-view-zoom').val(),10)||100)/100)),scale=Math.max(.05,Math.min(8,base))*viewZoom,tx=(hostW-unionW*scale)/2-left*scale,ty=(hostH-unionH*scale)/2-top*scale;editorViewScale=Math.max(.05,scale);scene.style.transform='translate('+tx+'px,'+ty+'px) scale('+editorViewScale+')';scene.style.setProperty('--ucm-handle-inverse',String(1/editorViewScale));scene.style.setProperty('--mp2-handle-inverse',String(1/editorViewScale));$('#ucm-wall-view-zoom-value').text(Math.round(viewZoom*100)+'%');$('#ucm-wall-view-scale').text('Fitted camera '+Math.round(editorViewScale*100)+'%');}
  function renderStudio(forceFit){var p=profile(),row=rowFor(p.key),image=imageFor(p.key),full=document.getElementById('ucm-bg-full-canvas'),preview=document.getElementById('ucm-wall-preview'),thumb=document.getElementById('ucm-bg-thumb-canvas'),fz=parseInt($('#ucm-full-zoom').val(),10),fx=parseInt($('#ucm-full-x').val(),10),fy=parseInt($('#ucm-full-y').val(),10),tz=parseInt($('#ucm-thumb-zoom').val(),10),hasThumb=p.tw>0&&p.th>0,stage=stageDimensions(p),g=cropGeometry(stage.width,stage.height,image,fz,fx,fy),scene=document.getElementById('ucm-wall-scene'),output=document.getElementById('ucm-wall-output'),wallImage=document.getElementById('ucm-wall-image'),frame=document.getElementById('ucm-wall-frame');scene.style.width=stage.width+'px';scene.style.height=stage.height+'px';output.style.width=stage.width+'px';output.style.height=stage.height+'px';if(image&&g){wallImage.src=image.src;wallImage.style.left=g.dx+'px';wallImage.style.top=g.dy+'px';wallImage.style.width=g.dw+'px';wallImage.style.height=g.dh+'px';wallImage.style.filter=p.tones?('grayscale(1)'+(row.invert?' invert(1)':'')):'none';frame.hidden=false;frame.style.left=g.dx+'px';frame.style.top=g.dy+'px';frame.style.width=g.dw+'px';frame.style.height=g.dh+'px';}else{wallImage.removeAttribute('src');wallImage.style.filter='none';frame.hidden=true;}wallImage.hidden=!image;preview.hidden=true;$('#ucm-wall-empty').prop('hidden',!!image);if(full.width!==p.fw||full.height!==p.fh){full.width=p.fw;full.height=p.fh;}paint(full,fz,fx,fy,image);quantizeGrayscale(full,p.tones,!!row.invert);$('#ucm-bg-device-output').toggle(!!(image&&p.tones));if(hasThumb){if(thumb.width!==p.tw||thumb.height!==p.th){thumb.width=p.tw;thumb.height=p.th;}paint(thumb,tz,parseInt($('#ucm-thumb-x').val(),10),parseInt($('#ucm-thumb-y').val(),10),image);quantizeGrayscale(thumb,p.tones,!!row.invert);}var uncovered=row.enabled!==false&&!!image&&(!g||!g.covered);$('#ucm-wall-coverage-warning').prop('hidden',!uncovered).toggleClass('mp2-hidden',!uncovered);$('#ucm-bg-save-profile,#ucm-bg-upload').prop('disabled',uncovered||!image);$('#ucm-thumb-output,#ucm-thumb-controls').toggle(hasThumb);$('#ucm-full-zoom-value').text(fz+'%');$('#ucm-full-x-value').text(fx+' px');$('#ucm-full-y-value').text(fy+' px');$('#ucm-thumb-zoom-value').text(tz+'%');$('#ucm-media-dimensions').text((hasThumb?('Full '+p.fw+'×'+p.fh+' '+p.format.toUpperCase()+' · thumbnail '+p.tw+'×'+p.th+' PNG'):('Full '+p.fw+'×'+p.fh+' '+p.format.toUpperCase()+' · no catalog thumbnail required'))+(p.tones?' · '+p.tones+' grayscale tones'+(row.invert?' · inverted':''):''));updateProfileState();if(!wallDrag)fitWallScene(g,stage,!!forceFit);}
  function renderedPair(p){var row=rowFor(p.key),t=transformFor(p.key),image=imageFor(p.key),full=document.createElement('canvas'),thumb=document.createElement('canvas'),mime=p.format==='jpg'?'image/jpeg':'image/png',quality=.9,encoded,g,invert=!!(p.tones&&row.invert);if(row.enabled===false)return{enabled:false,full:'',thumb:'',transform:t,invert:invert};if(!image)throw new Error(p.vendor+' '+p.fw+'×'+p.fh+' has no source image.');g=cropGeometry(p.fw,p.fh,image,t.full.zoom,t.full.x,t.full.y);if(!g||!g.covered)throw new Error(p.vendor+' '+p.fw+'×'+p.fh+' has uncovered output. Use Auto-cover output before saving.');full.width=p.fw;full.height=p.fh;paint(full,t.full.zoom,t.full.x,t.full.y,image);quantizeGrayscale(full,p.tones,invert);encoded=full.toDataURL(mime,quality).split(',')[1];while(p.format==='jpg'&&p.maxBytes&&Math.ceil(encoded.length*3/4)>p.maxBytes&&quality>.45){quality-=.08;encoded=full.toDataURL(mime,quality).split(',')[1];}if(p.maxBytes&&Math.ceil(encoded.length*3/4)>p.maxBytes)throw new Error(p.vendor+' '+p.fw+'×'+p.fh+' output cannot fit the documented '+Math.round(p.maxBytes/1024)+' KiB limit even after JPEG optimization.');if(p.tw>0&&p.th>0){thumb.width=p.tw;thumb.height=p.th;paint(thumb,t.thumbnail.zoom,t.thumbnail.x,t.thumbnail.y,image);quantizeGrayscale(thumb,p.tones,invert);}return{enabled:true,full:encoded,thumb:p.tw>0?thumb.toDataURL('image/png').split(',')[1]:'',transform:t,invert:invert};}
  function outputName(base,p,all){var sameDir=all.filter(function(x){return x.dir===p.dir;}).length;return sameDir>1?base+'-'+p.fw+'x'+p.fh:base;}
  function hostedStageDimensions(){var host=document.getElementById('ucm-hosted-stage-host'),width=Math.min(540,Math.max(270,(host.clientWidth||620)-68));return{width:width,height:width*(56/90)};}
  function fitHostedScene(g,stage){var host=document.getElementById('ucm-hosted-stage-host'),scene=document.getElementById('ucm-hosted-scene'),left=Math.min(0,g?g.dx:0),top=Math.min(0,g?g.dy:0),right=Math.max(stage.width,g?g.dx+g.dw:stage.width),bottom=Math.max(stage.height,g?g.dy+g.dh:stage.height),unionW=Math.max(1,right-left),unionH=Math.max(1,bottom-top),scale=Math.min(1,(Math.max(1,host.clientWidth)-32)/unionW,(Math.max(1,host.clientHeight)-32)/unionH),tx=(host.clientWidth-unionW*scale)/2-left*scale,ty=(host.clientHeight-unionH*scale)/2-top*scale;hostedEditorViewScale=Math.max(.15,scale);scene.style.transform='translate('+tx+'px,'+ty+'px) scale('+hostedEditorViewScale+')';scene.style.setProperty('--ucm-handle-inverse',String(1/hostedEditorViewScale));scene.style.setProperty('--mp2-handle-inverse',String(1/hostedEditorViewScale));}
  function renderHosted(){var canvas=document.getElementById('ucm-hosted-canvas'),zoom=parseInt($('#ucm-hosted-zoom').val()||100,10),x=parseInt($('#ucm-hosted-x').val()||0,10),y=parseInt($('#ucm-hosted-y').val()||0,10),invert=$('#ucm-hosted-invert').is(':checked'),stage=hostedStageDimensions(),g=cropGeometry(stage.width,stage.height,hostedSourceImage,zoom,x,y),scene=document.getElementById('ucm-hosted-scene'),output=document.getElementById('ucm-hosted-output'),image=document.getElementById('ucm-hosted-image'),frame=document.getElementById('ucm-hosted-frame');canvas.width=90;canvas.height=56;paint(canvas,zoom,x,y,hostedSourceImage);quantizeGrayscale(canvas,4,invert);scene.style.width=stage.width+'px';scene.style.height=stage.height+'px';output.style.width=stage.width+'px';output.style.height=stage.height+'px';if(hostedSourceImage&&g){image.src=hostedSourceImage.src;image.style.left=g.dx+'px';image.style.top=g.dy+'px';image.style.width=g.dw+'px';image.style.height=g.dh+'px';image.style.filter='grayscale(1)'+(invert?' invert(1)':'');frame.hidden=false;frame.style.left=g.dx+'px';frame.style.top=g.dy+'px';frame.style.width=g.dw+'px';frame.style.height=g.dh+'px';}else{image.removeAttribute('src');image.style.filter='none';frame.hidden=true;}$('#ucm-hosted-empty').prop('hidden',!!hostedSourceImage);hostedCoverageOk=!!(g&&g.covered);$('#ucm-hosted-coverage-warning').prop('hidden',hostedCoverageOk||!hostedSourceImage).toggleClass('mp2-hidden',hostedCoverageOk||!hostedSourceImage);$('#ucm-hosted-publish').prop('disabled',!hostedSourceImage||!hostedCoverageOk);$('#ucm-hosted-zoom').siblings('output').text(zoom+'%');$('#ucm-hosted-x').siblings('output').text(x+' px');$('#ucm-hosted-y').siblings('output').text(y+' px');if(!hostedDrag)fitHostedScene(g,stage);}
  function legacyBmpBase64(canvas){var width=canvas.width,height=canvas.height,stride=(width+3)&~3,pixelOffset=14+40+1024,size=pixelOffset+stride*height,bytes=new Uint8Array(size),view=new DataView(bytes.buffer),pixels=canvas.getContext('2d').getImageData(0,0,width,height).data;bytes[0]=66;bytes[1]=77;view.setUint32(2,size,true);view.setUint32(10,pixelOffset,true);view.setUint32(14,40,true);view.setInt32(18,width,true);view.setInt32(22,height,true);view.setUint16(26,1,true);view.setUint16(28,8,true);view.setUint32(34,stride*height,true);view.setUint32(46,256,true);for(var i=0;i<256;i++){var palette=54+i*4;bytes[palette]=i;bytes[palette+1]=i;bytes[palette+2]=i;bytes[palette+3]=0;}for(var y=0;y<height;y++){var output=pixelOffset+(height-1-y)*stride;for(var x=0;x<width;x++){var source=(y*width+x)*4,lum=Math.round(.2126*pixels[source]+.7152*pixels[source+1]+.0722*pixels[source+2]);bytes[output+x]=Math.max(0,Math.min(255,Math.round(lum/85)*85));}}var binary='',chunk=8192;for(var at=0;at<bytes.length;at+=chunk)binary+=String.fromCharCode.apply(null,bytes.subarray(at,Math.min(bytes.length,at+chunk)));return btoa(binary);}
  function updatePath(){var p=profile(),models=targetModels(p);$('#ucm-media-path').text(p.delivery==='cisco_list'?('Desktops/'+p.dir+'/List.xml'):('HTTP '+p.vendor+' libraries for '+models.join(', ')+' · '+p.format.toUpperCase()+' up to '+Math.round(p.maxBytes/1024)+' KiB'));renderStudio();if($('#ucm-bg-legacy-details').prop('open'))loadLegacyBackgrounds();}
  function loadWallpaperSets(){
    var grid=$('#ucm-bg-grid').html('<p class="mp2-muted">Loading linked wallpaper catalogs…</p>');
    return call({op:'wallpaper_set_list'}).done(function(resp){
      var r=result(resp),sets=r.sets||[],openFirst=!activeSetId&&!sourceImage;
      grid.empty();
      if(!r.ok){grid.append($('<p class="text-danger">').text(r.message||'Cannot load wallpaper catalogs'));return;}
      sets.forEach(function(set){
        var variants=set.variants||[],selected=variants.filter(function(v){return v.profile===profile().key;})[0]||variants[0],card=$('<article class="mp2-catalog">').attr({'data-set-id':set.id,'data-catalog-id':set.id}).toggleClass('mp2-selected',String(activeSetId)===String(set.id)),preview=$('<button type="button" class="mp2-thumb" aria-label="Edit catalog">').text('No preview'),nameInput=$('<input type="text">').val(set.name),modified=set.modified_scopes||[],status=set.current_variant_count+' of '+set.variant_count+' profiles current';
        if(selected&&selected.current&&selected.url)preview.empty().append($('<img alt="">').attr('src',selected.url));
        else if(selected&&selected.current&&selected.thumbnail)call({op:'get_base64',path:'Desktops/'+selected.dir+'/'+selected.thumbnail}).done(function(imageResp){var image=result(imageResp);if(image.ok)preview.empty().append($('<img alt="">').attr('src','data:'+image.mime+';base64,'+image.content));});
        preview.on('click',function(){openWallpaperSet(set.id);});
        var identity=$('<div class="mp2-cell">').append($('<span class="mp2-cell-label">').text('Catalog name'),$('<div class="mp2-name-row">').append(nameInput,$('<button type="button" class="mp2-btn">').text('Rename').on('click',function(){var display=String(nameInput.val()||'').trim(),newId=safeName(display);if(!display||!newId){message('Enter a valid catalog name.',true);return;}call({op:'wallpaper_set_rename',set_id:set.id,new_set_id:newId,set_name:display}).done(function(renameResp){var renamed=result(renameResp);message(renamed.message||'Catalog rename complete',!renamed.ok);if(renamed.ok&&String(activeSetId)===String(set.id))activeSetId=String(renamed.set_id||newId);loadWallpaperSets();loadReplicationCatalogs();});})),$('<span class="mp2-muted">').text(String(set.id)+' · revision '+String(set.revision||set.catalog_revision||'current'))),scope=$('<div class="mp2-cell mp2-scope">').append($('<span class="mp2-cell-label">').text('Original scope'),$('<span>').text(set.scope_label||'Supported fleet'),$('<span class="mp2-muted">').text(status)),overrides=$('<div class="mp2-cell mp2-overrides">').append($('<span class="mp2-cell-label">').text('Profile-specific settings'),modified.length?$('<div class="mp2-chips">').append(modified.map(function(label){return $('<span class="mp2-chip mp2-override">').text(label);})): $('<span class="mp2-muted">').text('No profile-specific overrides')),actions=$('<div class="mp2-actions">').append($('<button type="button" class="mp2-btn mp2-primary">').text('Edit catalog').on('click',function(){openWallpaperSet(set.id);}),$('<button type="button" class="mp2-btn">').text('Replicate').on('click',function(){if(selectMediaTab('replication'))loadMediaPanel('replication',true,set.id);}),$('<button type="button" class="mp2-btn mp2-danger">').text('Delete').on('click',function(){if(!confirm('Delete '+set.name+' and every linked model output and compatibility alias?'))return;call({op:'wallpaper_set_delete',set_id:set.id}).done(function(deleteResp){var deleted=result(deleteResp);message(deleted.message||'Wallpaper catalog delete complete',!deleted.ok);if(deleted.ok&&String(activeSetId)===String(set.id))newWallpaperSet();loadWallpaperSets();loadReplicationCatalogs();loadLegacyBackgrounds();});}));
        var syncBadge=catalogSyncBadge('wallpaper_catalog','wallpaper:'+String(set.id));if(syncBadge)overrides.append(syncBadge);
        if((set.issues||[]).length)scope.append($('<span class="text-danger">').text(set.issues[0]));
        card.append(preview,identity,scope,overrides,actions);grid.append(card);
      });
      if(!sets.length){grid.html('<div class="mp2-selected-status"><strong>No wallpaper catalogs yet</strong><span>Choose a master image, name and scope above. The bounded editor will preview every documented profile before anything is generated.</span></div>');return;}
      if(openFirst&&!mediaRoot.find('[data-media-panel="wallpapers"]').prop('hidden'))openWallpaperSet(sets[0].id);
    });
  }
  function loadLegacyBackgrounds(){if(!$('#ucm-bg-legacy-details').prop('open'))return $.Deferred().resolve().promise();var grid=$('#ucm-bg-legacy-grid').empty(),p=profile();if(p.delivery!=='cisco_list'){grid.html('<p class="text-muted">Unlinked HTTP images and firmware-specific formats stay in Non-standard / legacy backgrounds above; they are never guessed into a linked catalog.</p>');return $.Deferred().resolve().promise();}grid.html('<p class="text-muted">Loading unlinked pairs…</p>');return call({op:'wallpaper_choices',profile:p.key}).done(function(resp){var r=result(resp);grid.empty();if(!r.ok){grid.append($('<p class="text-danger">').text(r.message||'Cannot load this resolution'));return;}(r.choices||[]).filter(function(choice){return!choice.linked;}).forEach(function(choice){var card=$('<div class="ucm-media-card">'),preview=$('<div class="ucm-media-preview">').text('No thumbnail');call({op:'get_base64',path:'Desktops/'+p.dir+'/'+choice.thumbnail}).done(function(resp){var image=result(resp);if(image.ok)preview.empty().append($('<img alt="">').attr('src','data:'+image.mime+';base64,'+image.content));});card.append(preview,$('<strong>').text(choice.filename),$('<small>').text('Unlinked · '+p.key));card.append($('<button class="btn btn-xs btn-danger">').text('Delete this pair').on('click',function(){if(!confirm('Delete only '+choice.filename+' from '+p.dir+'?'))return;call({op:'delete',path:'Desktops/'+p.dir+'/'+choice.filename}).then(function(first){var a=result(first);if(!a.ok)return $.Deferred().reject(a.message||'Full image delete failed');return call({op:'delete',path:'Desktops/'+p.dir+'/'+choice.thumbnail});}).then(function(second){var b=result(second);if(!b.ok)return $.Deferred().reject(b.message||'Thumbnail delete failed');return call({op:'regen_list',path:'Desktops/'+p.dir});}).done(function(){message('Legacy wallpaper pair deleted.',false);loadLegacyBackgrounds();}).fail(function(error){message(String(error||'Legacy pair deletion was incomplete.'),true);});}));grid.append(card);});if(!grid.children().length)grid.html('<p class="text-muted">No legacy/unlinked pairs match this exact Cisco profile.</p>');});}
  function applyManifest(manifest){
    activeWallpaperRevision=String(manifest.editor_revision||'');
    Object.keys(profileImages).forEach(function(key){var image=profileImages[key];if(image&&image._objectUrl)URL.revokeObjectURL(image._objectUrl);});profileImages={};profileSourceDirty={};profileSourceRemoved={};wallpaperState={variants:manifest.variants||{}};Object.keys(wallpaperState.variants).forEach(function(key){var row=wallpaperState.variants[key]||{};row.source_mode=row.source&&manifest.source&&String(row.source)!==String(manifest.source)?'custom':'master';wallpaperState.variants[key]=row;});
    activeSetId=String(manifest.id||'');
    var keys=Object.keys(wallpaperState.variants),first=keys[0]||'',variants=keys.map(function(key){return wallpaperState.variants[key]||{};}),vendors={},series={},exact='',firstOption=$('#ucm-media-model option').filter(function(){return String($(this).data('key'))===first;}).first();
    variants.forEach(function(row){if(row.vendor)vendors[String(row.vendor)]=true;if(row.series_key)series[String(row.series_key)]=true;});
    if(firstOption.length&&Object.keys(vendors).length===0)vendors[String(firstOption.data('vendor')||'')]=true;
    if(firstOption.length&&Object.keys(series).length===0)series[String(firstOption.data('series')||'')]=true;
    delete vendors[''];delete series[''];
    if(variants.length===1&&(variants[0].models||[]).length===1)exact=String(variants[0].models[0]);
    var savedScope=manifest.original_scope||{};
    $('#ucm-bg-coverage').val(savedScope.model?'model':(savedScope.series?'series':(savedScope.brand?'brand':'all')));
    updateCoverageFields(false);
    $('#ucm-bg-brand').val(String(savedScope.brand||'')||(Object.keys(vendors).length===1?Object.keys(vendors)[0]:''));
    refreshWallpaperScope();
    $('#ucm-bg-series').val(String(savedScope.series||'')||(Object.keys(series).length===1?Object.keys(series)[0]:''));
    refreshWallpaperScope();
    $('#ucm-bg-model-scope').val(String(savedScope.model||'')||exact);
    var matching=profiles();
    $('#ucm-media-model option').each(function(){var p=profileFrom($(this)),show=matching.some(function(candidate){return candidate.key===p.key;});$(this).prop('disabled',!show).toggle(show);});
    if(first){$('#ucm-media-model option').filter(function(){return String($(this).data('key'))===first;}).prop('selected',true);}
    activeProfileKey=profile().key;
    $('#ucm-bg-name').val(manifest.name||activeSetId);
    $('#ucm-bg-rename,#ucm-bg-repair-encoding,#ucm-bg-repair-hosted').prop('disabled',!activeSetId);
    $('#ucm-bg-editor-mode').text('Editing catalog '+activeSetId);
    $('#ucm-bg-catalog-id').text(activeSetId||'Assigned on save');
    $('#ucm-bg-master-action').text('Replace master image');
    $('#ucm-bg-master-name').text(manifest.source_name||manifest.source||'Retained master image');
    $('#ucm-bg-master-size').text(sourceImage?(sourceImage.naturalWidth+' × '+sourceImage.naturalHeight):'Retained source');
    $('#ucm-bg-editing').text('Editing linked catalog '+(manifest.name||activeSetId)+'. Saving one profile preserves every other variant.');
    restoreTransform(activeProfileKey);
    updatePath();
    loadProfileImage(activeProfileKey).done(function(){renderStudio(true);}).fail(function(error){message(String(error||'Profile-specific source could not be loaded.'),true);renderStudio(true);});
  }
  function openWallpaperSet(id){var epoch=++mediaEpoch;message('Loading retained wallpaper source…');call({op:'wallpaper_set_get',set_id:id}).done(function(resp){var r=result(resp);if(!r.ok){message(r.message||'Could not open wallpaper set',true);return;}var img=new Image();img.onload=function(){if(epoch!==mediaEpoch)return;if(sourceImage&&sourceImage._objectUrl)URL.revokeObjectURL(sourceImage._objectUrl);sourceImage=img;sourceDirty=false;applyManifest(r.manifest||{});message('Wallpaper catalog opened. Select any retained profile to inspect or adjust it.',false);};img.onerror=function(){message('The retained wallpaper source could not be decoded.',true);};img.src='data:'+r.mime+';base64,'+r.source;});}
  function newWallpaperSet(){mediaEpoch++;if(sourceImage&&sourceImage._objectUrl)URL.revokeObjectURL(sourceImage._objectUrl);Object.keys(profileImages).forEach(function(key){var image=profileImages[key];if(image&&image._objectUrl)URL.revokeObjectURL(image._objectUrl);});sourceImage=null;sourceDirty=false;activeSetId='';wallpaperState={variants:{}};profileImages={};profileSourceDirty={};profileSourceRemoved={};$('#ucm-bg-name,#ucm-bg-source,#ucm-bg-profile-source').val('');$('#ucm-bg-coverage').val('all');updateCoverageFields(true);activeProfileKey=profile().key;$('#ucm-bg-rename,#ucm-bg-repair-encoding,#ucm-bg-repair-hosted').prop('disabled',true);$('#ucm-bg-editor-mode').text('New catalog');$('#ucm-bg-catalog-id').text('Assigned on save');$('#ucm-bg-master-action').text('Choose master image');$('#ucm-bg-master-name').text('Not selected');$('#ucm-bg-master-size').text('—');$('#ucm-bg-editing').text('Choose a master source, then preview every profile in the selected scope.');restoreTransform(activeProfileKey);renderStudio(true);}
  function normalizedSource(image){var canvas=document.createElement('canvas'),max=2560,scale=Math.min(1,max/Math.max(image.naturalWidth,image.naturalHeight));canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));var ctx=canvas.getContext('2d');ctx.fillStyle='#000';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.imageSmoothingEnabled=true;ctx.imageSmoothingQuality='high';ctx.drawImage(image,0,0,canvas.width,canvas.height);return{mime:'image/jpeg',source:canvas.toDataURL('image/jpeg',0.9).split(',')[1]};}
  $('#ucm-bg-source').on('change',function(){var epoch=++mediaEpoch,file=this.files&&this.files[0];if(!file)return;if(!activeSetId&&!String($('#ucm-bg-name').val()||'').trim())$('#ucm-bg-name').val(String(file.name||'').replace(/\.[^.]+$/,''));var url=URL.createObjectURL(file),img=new Image();img.onload=function(){if(epoch!==mediaEpoch){URL.revokeObjectURL(url);return;}if(sourceImage&&sourceImage._objectUrl)URL.revokeObjectURL(sourceImage._objectUrl);img._objectUrl=url;sourceImage=img;sourceDirty=true;$('#ucm-bg-master-action').text('Replace master image');$('#ucm-bg-master-name').text(file.name);$('#ucm-bg-master-size').text(img.naturalWidth+' × '+img.naturalHeight);if(!activeSetId){wallpaperState={variants:{}};profiles().forEach(function(p){wallpaperState.variants[p.key]={enabled:true};});restoreTransform(profile().key);}renderStudio(true);};img.onerror=function(){URL.revokeObjectURL(url);message('The selected source image could not be decoded.',true);};img.src=url;});
  $('#ucm-bg-profile-source').on('change',function(){var epoch=++mediaEpoch,file=this.files&&this.files[0],key=activeProfileKey;if(!file||!key)return;var url=URL.createObjectURL(file),img=new Image();img.onload=function(){if(epoch!==mediaEpoch){URL.revokeObjectURL(url);return;}var old=profileImages[key];if(old&&old._objectUrl)URL.revokeObjectURL(old._objectUrl);img._objectUrl=url;profileImages[key]=img;profileSourceDirty[key]=true;delete profileSourceRemoved[key];var row=rowFor(key);row.source_mode='custom';row.source_file='pending';renderStudio(true);};img.onerror=function(){URL.revokeObjectURL(url);message('The profile-specific image could not be decoded.',true);};img.src=url;});
  $('#ucm-bg-profile-source-remove').on('click',function(){var key=activeProfileKey,row=rowFor(key),old=profileImages[key];if(old&&old._objectUrl)URL.revokeObjectURL(old._objectUrl);delete profileImages[key];delete profileSourceDirty[key];profileSourceRemoved[key]=true;row.source_mode='master';delete row.source_file;delete row.custom_source;$('#ucm-bg-profile-source').val('');renderStudio(true);});
  $('#ucm-bg-profile-reset').on('click',function(){var row=rowFor(activeProfileKey),d=defaultTransform();row.full_transform=$.extend({},d.full);row.thumbnail_transform=$.extend({},d.thumbnail);restoreTransform(activeProfileKey);renderStudio(true);});
  $('#ucm-bg-profile-enabled,#ucm-bg-profile-invert').on('change',function(){rememberTransform(activeProfileKey);renderStudio(true);});
  $('#ucm-wall-auto-cover').on('click',function(){$('#ucm-full-zoom').val(Math.max(100,parseInt($('#ucm-full-zoom').val(),10)||100));rememberTransform(activeProfileKey);renderStudio(true);});
  $('#ucm-wall-center').on('click',function(){$('#ucm-full-x,#ucm-full-y').val(0);rememberTransform(activeProfileKey);renderStudio(true);});
  $('#ucm-wall-fit-now').on('click',function(){renderStudio(true);});
  $('#ucm-wall-fit-handles').on('change',function(){renderStudio();});
  $('#ucm-wall-view-zoom').on('input change',function(){renderStudio();});
  $('#ucm-wall-frame').on('pointerdown',function(event){var handle=String($(event.target).closest('[data-wall-handle]').data('wall-handle')||'move'),p=profile(),image=imageFor(p.key),stage=stageDimensions(p),zoom=parseInt($('#ucm-full-zoom').val(),10),x=parseInt($('#ucm-full-x').val(),10),y=parseInt($('#ucm-full-y').val(),10),g=cropGeometry(stage.width,stage.height,image,zoom,x,y);if(!g)return;event.preventDefault();this.setPointerCapture(event.originalEvent.pointerId);wallDrag={pointer:event.originalEvent.pointerId,handle:handle,startClientX:event.originalEvent.clientX,startClientY:event.originalEvent.clientY,zoom:zoom,x:x,y:y,stage:stage,g:g,aspect:g.dw/g.dh,coverW:g.dw/(zoom/100)};});
  $('#ucm-wall-frame').on('pointermove',function(event){if(!wallDrag||event.originalEvent.pointerId!==wallDrag.pointer)return;var dx=(event.originalEvent.clientX-wallDrag.startClientX)/editorViewScale,dy=(event.originalEvent.clientY-wallDrag.startClientY)/editorViewScale,h=wallDrag.handle;if(h==='move'){var nx=wallDrag.g.rx?wallDrag.x+(dx/wallDrag.g.rx)*100:wallDrag.x,ny=wallDrag.g.ry?wallDrag.y+(dy/wallDrag.g.ry)*100:wallDrag.y;$('#ucm-full-x').val(Math.max(-100,Math.min(100,Math.round(nx))));$('#ucm-full-y').val(Math.max(-100,Math.min(100,Math.round(ny))));}else{var changes=[],width=wallDrag.g.dw;if(h.indexOf('e')!==-1)changes.push(dx);if(h.indexOf('w')!==-1)changes.push(-dx);if(h.indexOf('s')!==-1)changes.push(dy*wallDrag.aspect);if(h.indexOf('n')!==-1)changes.push(-dy*wallDrag.aspect);if(changes.length)width+=changes.reduce(function(sum,value){return sum+value;},0)/changes.length;width=Math.max(wallDrag.coverW*.6,Math.min(wallDrag.coverW*2.2,width));var height=width/wallDrag.aspect,left=(h.indexOf('w')!==-1)?wallDrag.g.dx+wallDrag.g.dw-width:((h.indexOf('e')!==-1)?wallDrag.g.dx:wallDrag.g.dx+(wallDrag.g.dw-width)/2),top=(h.indexOf('n')!==-1)?wallDrag.g.dy+wallDrag.g.dh-height:((h.indexOf('s')!==-1)?wallDrag.g.dy:wallDrag.g.dy+(wallDrag.g.dh-height)/2),rx=movementRange(width,wallDrag.stage.width),ry=movementRange(height,wallDrag.stage.height),nx=rx?((left-(wallDrag.stage.width-width)/2)/rx)*100:0,ny=ry?((top-(wallDrag.stage.height-height)/2)/ry)*100:0;$('#ucm-full-zoom').val(Math.round(width/wallDrag.coverW*100));$('#ucm-full-x').val(Math.max(-100,Math.min(100,Math.round(nx))));$('#ucm-full-y').val(Math.max(-100,Math.min(100,Math.round(ny))));}rememberTransform(activeProfileKey);renderStudio();});
  $('#ucm-wall-frame').on('pointerup pointercancel',function(event){if(!wallDrag||event.originalEvent.pointerId!==wallDrag.pointer)return;wallDrag=null;renderStudio(true);});
  $('#ucm-wall-frame').on('keydown',function(event){if(['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].indexOf(event.key)===-1)return;event.preventDefault();var step=event.shiftKey?5:1;if(event.key==='ArrowLeft'||event.key==='ArrowRight')$('#ucm-full-x').val(Math.max(-100,Math.min(100,parseInt($('#ucm-full-x').val(),10)+(event.key==='ArrowLeft'?-step:step))));else $('#ucm-full-y').val(Math.max(-100,Math.min(100,parseInt($('#ucm-full-y').val(),10)+(event.key==='ArrowUp'?-step:step))));rememberTransform(activeProfileKey);renderStudio();});
  $('#ucm-hosted-frame').on('pointerdown',function(event){var handle=String($(event.target).closest('[data-hosted-handle]').data('hosted-handle')||'move'),stage=hostedStageDimensions(),zoom=parseInt($('#ucm-hosted-zoom').val(),10),x=parseInt($('#ucm-hosted-x').val(),10),y=parseInt($('#ucm-hosted-y').val(),10),g=cropGeometry(stage.width,stage.height,hostedSourceImage,zoom,x,y);if(!g)return;event.preventDefault();this.setPointerCapture(event.originalEvent.pointerId);hostedDrag={pointer:event.originalEvent.pointerId,handle:handle,startClientX:event.originalEvent.clientX,startClientY:event.originalEvent.clientY,zoom:zoom,x:x,y:y,stage:stage,g:g,aspect:g.dw/g.dh,coverW:g.dw/(zoom/100)};});
  $('#ucm-hosted-frame').on('pointermove',function(event){if(!hostedDrag||event.originalEvent.pointerId!==hostedDrag.pointer)return;var dx=(event.originalEvent.clientX-hostedDrag.startClientX)/hostedEditorViewScale,dy=(event.originalEvent.clientY-hostedDrag.startClientY)/hostedEditorViewScale,h=hostedDrag.handle;if(h==='move'){var nx=hostedDrag.g.rx?hostedDrag.x+(dx/hostedDrag.g.rx)*100:hostedDrag.x,ny=hostedDrag.g.ry?hostedDrag.y+(dy/hostedDrag.g.ry)*100:hostedDrag.y;$('#ucm-hosted-x').val(Math.max(-100,Math.min(100,Math.round(nx))));$('#ucm-hosted-y').val(Math.max(-100,Math.min(100,Math.round(ny))));}else{var changes=[],width=hostedDrag.g.dw;if(h.indexOf('e')!==-1)changes.push(dx);if(h.indexOf('w')!==-1)changes.push(-dx);if(h.indexOf('s')!==-1)changes.push(dy*hostedDrag.aspect);if(h.indexOf('n')!==-1)changes.push(-dy*hostedDrag.aspect);if(changes.length)width+=changes.reduce(function(sum,value){return sum+value;},0)/changes.length;width=Math.max(hostedDrag.coverW,Math.min(hostedDrag.coverW*4,width));var height=width/hostedDrag.aspect,left=h.indexOf('w')!==-1?hostedDrag.g.dx+hostedDrag.g.dw-width:(h.indexOf('e')!==-1?hostedDrag.g.dx:hostedDrag.g.dx+(hostedDrag.g.dw-width)/2),top=h.indexOf('n')!==-1?hostedDrag.g.dy+hostedDrag.g.dh-height:(h.indexOf('s')!==-1?hostedDrag.g.dy:hostedDrag.g.dy+(hostedDrag.g.dh-height)/2),rx=movementRange(width,hostedDrag.stage.width),ry=movementRange(height,hostedDrag.stage.height),nx=rx?((left-(hostedDrag.stage.width-width)/2)/rx)*100:0,ny=ry?((top-(hostedDrag.stage.height-height)/2)/ry)*100:0;$('#ucm-hosted-zoom').val(Math.max(100,Math.min(400,Math.round(width/hostedDrag.coverW*100))));$('#ucm-hosted-x').val(Math.max(-100,Math.min(100,Math.round(nx))));$('#ucm-hosted-y').val(Math.max(-100,Math.min(100,Math.round(ny))));}renderHosted();});
  $('#ucm-hosted-frame').on('pointerup pointercancel',function(event){if(!hostedDrag||event.originalEvent.pointerId!==hostedDrag.pointer)return;hostedDrag=null;renderHosted();});
  $('#ucm-hosted-frame').on('keydown',function(event){if(['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].indexOf(event.key)===-1)return;event.preventDefault();var step=event.shiftKey?5:1,target=event.key==='ArrowLeft'||event.key==='ArrowRight'?$('#ucm-hosted-x'):$('#ucm-hosted-y'),delta=event.key==='ArrowLeft'||event.key==='ArrowUp'?-step:step;target.val(Math.max(-100,Math.min(100,parseInt(target.val(),10)+delta)));renderHosted();});
  $('#ucm-hosted-auto-cover').on('click',function(){$('#ucm-hosted-zoom').val(Math.max(100,parseInt($('#ucm-hosted-zoom').val(),10)||100));$('#ucm-hosted-x,#ucm-hosted-y').val(0);renderHosted();});
  $('#ucm-hosted-center').on('click',function(){$('#ucm-hosted-x,#ucm-hosted-y').val(0);renderHosted();});
  $('#ucm-hosted-reset').on('click',function(){$('#ucm-hosted-zoom').val(100);$('#ucm-hosted-x,#ucm-hosted-y').val(0);$('#ucm-hosted-invert').prop('checked',false);renderHosted();});
  $(window).on('resize.ucmMedia',function(){window.clearTimeout(window.ucmMediaResizeTimer);window.ucmMediaResizeTimer=window.setTimeout(function(){if(!mediaRoot.find('[data-media-panel="wallpapers"]').prop('hidden'))renderStudio(true);if(!mediaRoot.find('[data-media-panel="legacy"]').prop('hidden'))renderHosted();},80);});
  function loadSpecialSource(file){
    if(!file||!beginMediaOperation())return;
    var epoch=mediaEpoch;
    return fileBase64(file).then(function(content){
      if(!content)return rejected('The selected source file is empty.');
      var ready=$.Deferred(),img=new Image();
      img.onload=function(){
        if(epoch!==mediaEpoch){ready.reject('Media context changed; source was not replaced.');return;}
        if(hostedSourceImage&&hostedSourceImage._objectUrl)URL.revokeObjectURL(hostedSourceImage._objectUrl);
        hostedSourceImage=img;hostedSourceData=content;hostedSourceName=file.name;hostedSourceDirty=true;
        if(!activeSpecialCatalogId&&!String($('#ucm-hosted-name').val()||'').trim())$('#ucm-hosted-name').val(String(file.name||'').replace(/\.[^.]+$/,''));
        $('#ucm-hosted-zoom').val(100);$('#ucm-hosted-x,#ucm-hosted-y').val(0);
        $('#ucm-hosted-publish').text(activeSpecialCatalogId?'Save catalog changes':'Save special catalog');ready.resolve();
      };
      img.onerror=function(){ready.reject('The selected image could not be decoded; the previous source was retained.');};
      img.src='data:'+(file.type||'application/octet-stream')+';base64,'+content;
      return ready.promise();
    }).fail(function(error){$('#ucm-hosted-file').val('');message(String(error||'Source read failed; the previous source was retained.'),true);}).always(function(){endMediaOperation();renderHosted();});
  }
  $('#ucm-hosted-file').on('change',function(){loadSpecialSource(this.files&&this.files[0]);});
  $('#ucm-hosted-width,#ucm-hosted-height,#ucm-hosted-zoom,#ucm-hosted-x,#ucm-hosted-y,#ucm-hosted-invert').on('input change',renderHosted);
  $('#ucm-wallpaper-workbench input[type=range]').on('input change',function(){rememberTransform(profile().key);renderStudio();});
  function rejected(reason){var deferred=$.Deferred();deferred.reject(reason);return deferred.promise();}
  function loadProfileImages(selected){var chain=$.Deferred().resolve();selected.forEach(function(p){chain=chain.then(function(){return loadProfileImage(p.key);});});return chain;}
  function retainProfileSources(setId,selected,send){var chain=$.Deferred().resolve();selected.forEach(function(p){chain=chain.then(function(){var key=p.key,row=rowFor(key);if(profileSourceDirty[key]){var custom=normalizedSource(profileImages[key]);message('Retaining the alternate source for '+p.vendor+' '+p.fw+'×'+p.fh+'…');return send('Retain source for '+key,{op:'wallpaper_profile_source_save',set_id:setId,profile:key,mime:custom.mime,source:custom.source}).then(function(resp){var r=result(resp);if(!r.ok)return rejected(r.message||'Profile source save failed');row.source=String(r.source||'');row.source_mode=String(r.source_mode||'custom');delete profileSourceDirty[key];delete profileSourceRemoved[key];return r;});}if(profileSourceRemoved[key]){message('Restoring master-image inheritance for '+p.vendor+' '+p.fw+'×'+p.fh+'…');return send('Use master source for '+key,{op:'wallpaper_profile_source_reset',set_id:setId,profile:key}).then(function(resp){var r=result(resp);if(!r.ok)return rejected(r.message||'Profile source reset failed');row.source=String(r.source||'');row.source_mode='master';delete profileSourceRemoved[key];return r;});}return true;});});return chain;}
  var wallpaperSaveUncertain={};
  function saveWallpaperProfiles(selected){
    rememberTransform(profile().key);
    var display=String($('#ucm-bg-name').val()||'').trim(),setId=activeSetId||safeName(display),saved=0,receipts=[],sourceNeeded=sourceDirty||!activeSetId,sourceIntent=activeSetId?'update':'create',scope=JSON.stringify(currentScope()),operationKey=site()+':'+setId;
    if(!setId||!display||!sourceImage){message('Wallpaper catalog name and a master source image are required.',true);return;}
    if(!selected.length){message('The current scope contains no supported wallpaper profile.',true);return;}
    if(wallpaperSaveUncertain[operationKey]){message('The previous save has an unconfirmed outcome. Reload this catalog and reconcile its recorded operation before saving again.',true);return;}
    if(!beginMediaOperation())return;
    function showReceipts(){
      var box=$('#ucm-wallpaper-save-results');
      if(!box.length)box=$('<details id="ucm-wallpaper-save-results">').insertAfter($('#ucm-media-message'));
      box.empty().append($('<summary>').text('Last wallpaper save: '+saved+' profile(s) confirmed'),$('<pre>').text(JSON.stringify(receipts,null,2)));
    }
    function send(label,data){
      var receipt={step:label,status:'pending'};receipts.push(receipt);showReceipts();
      return call(data).then(function(resp){
        var r=result(resp);receipt.status=r.ok?'succeeded':(r.recovery_required?'needs_review':'failed');receipt.result=r;
        if(r.ok&&r.revision)activeWallpaperRevision=String(r.revision);
        if(r.recovery_required)wallpaperSaveUncertain[operationKey]=true;
        showReceipts();return resp;
      },function(){
        receipt.status='unknown';receipt.message='The PBX response was lost; this operation may have completed.';
        wallpaperSaveUncertain[operationKey]=true;showReceipts();
        return rejected(receipt.message+' Reload the catalog and reconcile before retrying.');
      });
    }
    message('Preparing '+selected.length+' linked wallpaper profile(s)…');
    var chain=loadProfileImages(selected);
    if(sourceNeeded)chain=chain.then(function(){
      var source=normalizedSource(sourceImage);
      return send('Retain master source',{op:'wallpaper_set_source',intent:sourceIntent,set_id:setId,set_name:display,mime:source.mime,source:source.source,scope:scope}).then(function(resp){
        var r=result(resp);if(!r.ok)return rejected(r.message||'Wallpaper source save failed');
        activeSetId=setId;sourceDirty=false;return r;
      });
    });
    chain=chain.then(function(){return retainProfileSources(setId,selected,send);}).then(function(){
      var pairs;
      try{pairs=selected.map(function(p){var rendered=renderedPair(p);return{profile:p.key,dir:p.dir,models:targetModels(p),enabled:rendered.enabled?1:0,invert:rendered.invert?1:0,full:rendered.full,thumb:rendered.thumb,transform:rendered.transform};});}
      catch(error){return rejected(error.message||'Image export failed');}
      var batches=[];while(pairs.length)batches.push(pairs.splice(0,3));
      var writes=$.Deferred().resolve();
      batches.forEach(function(batch,index){writes=writes.then(function(){
        message('Saving wallpaper batch '+(index+1)+' of '+batches.length+'…');
        return send('Publish '+batch.map(function(p){return p.profile;}).join(', '),{op:'put_media_pairs',intent:'update',set_id:setId,set_name:display,pairs:JSON.stringify(batch)}).then(function(resp){
          var r=result(resp);if(!r.ok)return rejected(r.message||'Wallpaper save failed');saved+=batch.length;showReceipts();return r;
        });
      });});
      return writes;
    });
    chain.done(function(){
      activeSetId=setId;sourceDirty=false;$('#ucm-bg-rename').prop('disabled',false);
      $('#ucm-bg-editing').text('Editing linked catalog '+display+'. Saving one profile preserves every other variant.');
      message('Saved '+saved+' linked wallpaper profile(s).',false);loadWallpaperSets();loadLegacyBackgrounds();updateProfileState();
    }).fail(function(error){
      message(saved+' profile(s) confirmed saved. '+String(error||'Wallpaper catalog save failed.')+' Earlier results are retained below.',true);showReceipts();
    }).always(function(){endMediaOperation();renderStudio();});
  }
  $('#ucm-bg-save-profile').on('click',function(){saveWallpaperProfiles([profile()]);});
  $('#ucm-bg-upload').on('click',function(){saveWallpaperProfiles(profiles());});
  function clearRingObjectUrls(){ringObjectUrls.forEach(function(url){URL.revokeObjectURL(url);});ringObjectUrls=[];}
  function mulawSample(value){var u=(~value)&255,sign=u&128,exponent=(u>>4)&7,mantissa=u&15,sample=((mantissa<<3)+132)<<exponent;sample-=132;return sign?-sample:sample;}
  function ringtoneWavUrl(bytes,ext){var samples=ext==='raw'?bytes.length:Math.floor(bytes.length/2),rate=ext==='raw'?8000:16000,buffer=new ArrayBuffer(44+samples*2),view=new DataView(buffer),offset=0,write=function(value){for(var i=0;i<value.length;i++)view.setUint8(offset++,value.charCodeAt(i));},little=true;if(ext==='rwb'&&samples>2){var score=function(order){var total=0,previous=0,limit=Math.min(samples,4096);for(var i=0;i<limit;i++){var value=new DataView(bytes.buffer,bytes.byteOffset+i*2,2).getInt16(0,order);total+=Math.abs(value-previous);previous=value;}return total;};little=score(true)<=score(false);}write('RIFF');view.setUint32(offset,36+samples*2,true);offset+=4;write('WAVEfmt ');view.setUint32(offset,16,true);offset+=4;view.setUint16(offset,1,true);offset+=2;view.setUint16(offset,1,true);offset+=2;view.setUint32(offset,rate,true);offset+=4;view.setUint32(offset,rate*2,true);offset+=4;view.setUint16(offset,2,true);offset+=2;view.setUint16(offset,16,true);offset+=2;write('data');view.setUint32(offset,samples*2,true);offset+=4;for(var sampleIndex=0;sampleIndex<samples;sampleIndex++){var sample=ext==='raw'?mulawSample(bytes[sampleIndex]):new DataView(bytes.buffer,bytes.byteOffset+sampleIndex*2,2).getInt16(0,little);view.setInt16(offset,sample,true);offset+=2;}var url=URL.createObjectURL(new Blob([buffer],{type:'audio/wav'}));ringObjectUrls.push(url);return url;}
  function ringtonePlayer(bytes,ext,name){return $('<audio controls preload="metadata">').attr({src:ringtoneWavUrl(bytes,ext),'aria-label':'Preview '+name});}
  function decodedBytes(encoded){var binary=atob(String(encoded||'')),bytes=new Uint8Array(binary.length);for(var i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);return bytes;}
  function audioAssetCall(data){var captured=$.extend({command:'mediaasset',site_id:operationSite(),family:mediaOperation?mediaOperation.family:$('#ucm-audio-family').val(),model:mediaOperation?mediaOperation.model:($('#ucm-audio-model').val()||'all')},data);return hostedRetirementRequest(function(payload){return mediaRequest({data:payload});},captured);}
  function refreshAudioModels(){var family=String($('#ucm-audio-family').val()||'cisco_ringlist'),hosted=family!=='cisco_ringlist',select=$('#ucm-audio-model').empty().append($('<option value="all">').text('All compatible models / shared library'));(audioModelLibraries[family]||[]).forEach(function(model){select.append($('<option>').val(model.value).text(model.label));});$('#ucm-audio-model-field').toggleClass('mp2-hidden',!hosted);$('#ucm-ring-type-field').toggleClass('mp2-hidden',hosted);$('#ucm-ring-file').attr('accept',hosted?'.wav,.mp3,.ogg,audio/wav,audio/mpeg,audio/ogg':'.raw,.rwb');$('#ucm-ring-upload-preview').empty();loadRings();}
  function previewRingFiles(files){var box=$('#ucm-ring-upload-preview').empty();clearRingObjectUrls();Array.from(files||[]).forEach(function(file){var ext=String(file.name||'').split('.').pop().toLowerCase(),row=$('<div class="ucm-audio-preview">').append($('<strong>').text(file.name)),player=$('<span class="ucm-ring-player">');row.append(player);box.append(row);if(['raw','rwb'].indexOf(ext)!==-1){var reader=new FileReader();reader.onload=function(){try{player.append(ringtonePlayer(new Uint8Array(reader.result),ext,file.name));}catch(error){player.addClass('text-danger').text('Preview failed: '+error.message);}};reader.readAsArrayBuffer(file);}else if(['wav','mp3','ogg'].indexOf(ext)!==-1){var url=URL.createObjectURL(file);ringObjectUrls.push(url);player.append($('<audio controls preload="metadata">').attr({src:url,'aria-label':'Preview '+file.name}));}else player.addClass('text-danger').text('Unsupported audio extension');});}
  function loadRings(){
    clearRingObjectUrls();var family=String($('#ucm-audio-family').val()||'cisco_ringlist'),list=$('#ucm-ring-list').empty();
    if(family==='cisco_ringlist'){call({op:'list',path:''}).done(function(resp){var r=result(resp),ext=$('#ucm-ring-type').val();(r.entries||[]).filter(function(e){return!e.dir&&new RegExp('\\.'+ext+'$','i').test(e.name);}).forEach(function(e){var row=$('<div class="ucm-ring-row">'),player=$('<span class="ucm-ring-player mp2-muted">').text('Preview not loaded');row.append($('<strong>').text(e.name),player,$('<div class="mp2-button-row">').append($('<button type="button" class="mp2-btn">').text('Preview').on('click',function(){var button=$(this).prop('disabled',true).text('Loading…');call({op:'get_base64',path:e.path||e.name}).done(function(audioResp){var audio=result(audioResp);if(!audio.ok){player.addClass('text-danger').text(audio.message||'Preview could not be loaded');return;}try{player.removeClass('mp2-muted text-danger').empty().append(ringtonePlayer(decodedBytes(audio.content),ext,e.name));}catch(error){player.addClass('text-danger').text('Preview failed: '+error.message);}}).always(function(){button.prop('disabled',false).text('Preview');});}),$('<button type="button" class="mp2-btn mp2-danger">').text('Remove').on('click',function(){removeCiscoRingtone(e.name,ext);})));list.append(row);});if(!list.children().length)list.html('<p class="mp2-muted">No '+ext+' tones on this site.</p>');});return;}
    audioAssetCall({op:'list'}).done(function(resp){var r=result(resp);if(!r.ok){list.html('<p class="text-danger"></p>').find('p').text(r.message||'Cannot load audio library');return;}(r.items||[]).filter(function(item){return item.media_kind==='audio';}).forEach(function(item){var used=(item.used_by||[]).join(', ')||'Not assigned',row=$('<div class="ucm-ring-row">').attr('data-audio-name',item.name),player=$('<span class="ucm-ring-player">').append($('<audio controls preload="metadata">').attr({src:item.preview_url||item.url,'aria-label':'Preview '+item.name})),details=$('<span>').append($('<strong>').text(item.compatibility||family),$('<small class="ucm-fw-compatible">').toggleClass('text-danger',item.validation_status==='invalid').text((item.validation_status||'unverified')+' · '+(item.validation_message||'')),$('<small class="ucm-fw-compatible">').text('Used by: '+used));row.append($('<strong>').text(item.name),player,details,$('<button type="button" class="mp2-btn mp2-danger">').text('Remove').on('click',function(){if(!confirm('Delete '+item.name+' from this audio library?'))return;audioAssetCall({op:'delete',name:item.name}).done(function(deleteResp){var deleted=result(deleteResp);message(deleted.message||'Audio removed',!deleted.ok);loadRings();});}));list.append(row);});if(!list.children().length)list.html('<p class="mp2-muted">No WAV, MP3 or OGG files in this model library.</p>');});
  }
  function fileBase64(file){var deferred=$.Deferred(),reader=new FileReader();reader.onload=function(){deferred.resolve(String(reader.result||'').split(',')[1]||'');};reader.onerror=function(){deferred.reject('Could not read '+file.name);};reader.readAsDataURL(file);return deferred.promise();}
  $('#ucm-ring-file').on('change',function(){previewRingFiles(this.files);});
  $('#ucm-ring-upload').on('click',function(){var input=$('#ucm-ring-file')[0],files=Array.from(input.files||[]),family=String($('#ucm-audio-family').val()||'cisco_ringlist'),ext=$('#ucm-ring-type').val(),button=$(this);if(!files.length){message('Choose at least one audio file.',true);return;}var invalid=files.filter(function(file){var suffix=String(file.name||'').split('.').pop().toLowerCase();return family==='cisco_ringlist'?suffix!==ext:['wav','mp3','ogg'].indexOf(suffix)===-1;});if(invalid.length){message(family==='cisco_ringlist'?'Every selected file must be a matching .'+ext+' file. Compact phone filenames are assigned automatically.':'Every selected file must be WAV, MP3 or OGG.',true);return;}if(!beginMediaOperation())return;button.prop('disabled',true);var chain=$.Deferred().resolve();files.forEach(function(file){chain=chain.then(function(){message('Uploading '+file.name+'…');if(family==='cisco_ringlist')return upload(file.name,file).then(function(resp){var r=result(resp);return r.ok?r:rejected(r.message||('Upload failed for '+file.name));});return fileBase64(file).then(function(content){return audioAssetCall({op:'upload',name:file.name,content:content}).then(function(resp){var r=result(resp);return r.ok?r:rejected(r.message||('Upload failed for '+file.name));});});});});if(family==='cisco_ringlist')chain=chain.then(function(){return call({op:'regen_ringlist',wideband:ext==='rwb'?1:0}).then(function(resp){var r=result(resp);return r.ok?r:rejected(r.message||'Ring-list rebuild failed');});});chain.done(function(){message('Published '+files.length+' audio file(s).',false);input.value='';$('#ucm-ring-upload-preview').empty();loadRings();}).fail(function(reason){message(String(reason||'Audio upload failed.'),true);}).always(function(){endMediaOperation();button.prop('disabled',false);});});
  $('#ucm-hosted-publish').on('click',function(){var display=String($('#ucm-hosted-name').val()||'').trim(),id=activeSpecialCatalogId||safeName(display),canvas=document.getElementById('ucm-hosted-canvas');if(!display||!id||!hostedSourceImage||(!hostedSourceData&&!activeSpecialCatalogId)){message('Choose a source image and enter a catalog name.',true);return;}renderHosted();if(!hostedCoverageOk){message('The image must cover the complete 90 × 56 output. Center it or use Auto-cover output before saving.',true);return;}var payload={op:'special_catalog_save',expected_manifest_sha256:activeSpecialRevision,intent:activeSpecialCatalogId?'update':'create',catalog_id:id,name:display,published_name:specialPublishedName||'',content:legacyBmpBase64(canvas),transform:JSON.stringify({zoom:parseInt($('#ucm-hosted-zoom').val(),10)||100,x:parseInt($('#ucm-hosted-x').val(),10)||0,y:parseInt($('#ucm-hosted-y').val(),10)||0}),settings:JSON.stringify({width:90,height:56,tones:4,invert:$('#ucm-hosted-invert').is(':checked')})};if(hostedSourceDirty||!activeSpecialCatalogId){payload.source=hostedSourceData;payload.source_name=hostedSourceName;}if(!beginMediaOperation())return;var button=$(this).prop('disabled',true);hostedCall(payload).done(function(resp){var r=result(resp);message(r.message||'Special catalog saved',!r.ok);if(r.ok){var catalog=r.catalog||{};activeSpecialCatalogId=String(catalog.catalog_id||id);activeSpecialRevision=String(catalog.manifest_sha256||'');specialPublishedName=String(catalog.published_name||payload.published_name);hostedSourceDirty=false;$('#ucm-hosted-file').val('');button.text('Save catalog changes');loadHosted();loadReplicationCatalogs();}}).fail(function(){message('Special catalog save response is uncertain. Inspect the catalog before retrying.',true);}).always(function(){endMediaOperation();renderHosted();});});
  function syncTargets(){return $('.ucm-sync-target:checked:not(:disabled)').map(function(){return parseInt($(this).val(),10);}).get();}
  function refreshSyncTargets(){$('.ucm-sync-target').each(function(){var own=String($(this).val())===String(site());$(this).prop('disabled',own);if(own)$(this).prop('checked',false);});}
  function requireTargets(){var targets=syncTargets();if(!targets.length)message('Open Replication and choose at least one target site different from the source site.',true);return targets;}
  function responseValue(value){return Array.isArray(value)?value[0]:value;}
  function replicationRow(kind,id,name,type,revision,contents,checked,extra){var input=$('<input type="checkbox" class="ucm-sync-catalog">').attr({'data-set-id':id,'data-sync-kind':kind}).prop('checked',!!checked);Object.keys(extra||{}).forEach(function(key){input.attr('data-'+key,extra[key]);});return $('<tr>').append($('<td>').append(input),$('<td>').append($('<strong>').text(name)),$('<td>').text(type),$('<td>').text(revision||'current'),$('<td>').text(contents||''));}
  function loadReplicationCatalogs(preselectId){
    if(mediaRoot.find('[data-media-panel="replication"]').prop('hidden')){mediaPanelLoaded.replication=false;return $.Deferred().resolve().promise();}
    var request=++replicationCatalogRequest,epoch=mediaSiteEpoch,capturedSite=site(),family=String($('#ucm-audio-family').val()||'cisco_ringlist'),model=String($('#ucm-audio-model').val()||'all'),ringExt=String($('#ucm-ring-type').val()||'raw'),body=$('#ucm-media-sync-catalogs');
    mediaPanelLoaded.replication=true;
    body.html('<tr><td colspan="5" class="mp2-muted">Loading exact catalog inventory…</td></tr>');
    function current(){return request===replicationCatalogRequest&&epoch===mediaSiteEpoch&&String(site())===String(capturedSite)&&family===String($('#ucm-audio-family').val()||'cisco_ringlist')&&model===String($('#ucm-audio-model').val()||'all')&&ringExt===String($('#ucm-ring-type').val()||'raw');}
    function read(data){return mediaRequest({catalog:true,data:$.extend({site_id:capturedSite},data)}).then(function(resp){var r=result(resp);return r.ok?r:{ok:false,message:r.message||'Catalog response was not successful'};},function(error){return{ok:false,message:typeof error==='string'?error:'Catalog request failed or timed out'};});}
    var audio=family==='cisco_ringlist'?read({command:'tftp',op:'list',path:''}):read({command:'mediaasset',op:'list',family:family,model:model});
    return $.when(read({command:'tftp',op:'wallpaper_set_list'}),read({command:'mediaasset',op:'special_catalog_list',family:'cisco_cnf',model:'all'}),audio).done(function(wallpapers,special,audio){
      if(!current())return;
      body.empty();
      (wallpapers.sets||[]).forEach(function(set){body.append(replicationRow('wallpaper',set.id,set.name,'Wallpaper catalog',set.revision||set.catalog_revision,(set.variant_count||0)+' profiles · '+(set.current_variant_count||0)+' current',String(preselectId)===String(set.id)));});(special.catalogs||[]).forEach(function(catalog){body.append(replicationRow('special',catalog.catalog_id,catalog.name,'Special background',catalog.revision,'Retained source + transform + '+catalog.published_name,String(preselectId)===String(catalog.catalog_id)));});if(family==='cisco_ringlist'){(audio.entries||[]).filter(function(entry){return!entry.dir&&new RegExp('\\.'+ringExt+'$','i').test(entry.name);}).forEach(function(entry){body.append(replicationRow('ringtone','ringtone:'+entry.name,entry.name,'Cisco '+ringExt.toUpperCase()+' ringtone',String(entry.mtime||'current'),'Exact tone + regenerated '+(ringExt==='rwb'?'Ringlist-wb.xml':'Ringlist.xml'),false,{name:entry.name}));});}else{(audio.items||[]).filter(function(item){return item.media_kind==='audio';}).forEach(function(item){body.append(replicationRow('audio','audio:'+family+':'+model+':'+item.name,item.name,'Audio · '+(item.compatibility||family),String(item.mtime||'current'),item.validation_status+' · '+Number(item.usage_count||0)+' assignment(s)',false,{family:family,model:model,name:item.name}));});}
      var failures=[];
      [[wallpapers,'Wallpaper catalogs'],[special,'Special catalogs'],[audio,'Audio']].forEach(function(entry){if(!entry[0].ok)failures.push(entry[1]+': '+entry[0].message);});
      mediaPanelLoaded.replication=!failures.length;
      failures.forEach(function(text){body.append($('<tr>').append($('<td colspan="5" class="text-danger">').text(text+' — use Refresh catalogs to retry.')));});
      if(!body.children().length)body.html('<tr><td colspan="5" class="mp2-muted">No managed catalogs are available on this source site.</td></tr>');
      $('#ucm-media-sync-all').prop('checked',false);
    }).fail(function(){if(!current())return;mediaPanelLoaded.replication=false;body.html('<tr><td colspan="5" class="text-danger">Catalog inventory could not be loaded. Use Refresh catalogs to retry.</td></tr>');});
  }
  function selectedSetIds(kind){return $('.ucm-sync-catalog:checked[data-sync-kind="'+kind+'"]').map(function(){return String($(this).data('set-id'));}).get();}
  function selectedRingtoneNames(){return $('.ucm-sync-catalog:checked[data-sync-kind="ringtone"]').map(function(){return String($(this).data('name'));}).get();}
  function selectedAudioRows(){return $('.ucm-sync-catalog:checked[data-sync-kind="audio"]').map(function(){return{family:String($(this).data('family')),model:String($(this).data('model')),name:String($(this).data('name'))};}).get();}
  $('#ucm-media-sync-all').on('change',function(){$('.ucm-sync-catalog').prop('checked',this.checked);});
  $('#ucm-hosted-open-replication').on('click',function(){if(selectMediaTab('replication'))loadMediaPanel('replication',true,activeSpecialCatalogId);});
  $('#ucm-media-sync-selected').on('click',function(){
    var targets=requireTargets(),wallpaperIds=selectedSetIds('wallpaper'),specialIds=selectedSetIds('special'),ringtoneNames=selectedRingtoneNames(),audioRows=selectedAudioRows(),overwrite=$('#ucm-sync-overwrite').is(':checked')?1:0,button=$(this);
    if(!targets.length)return;
    if(!wallpaperIds.length&&!specialIds.length&&!ringtoneNames.length&&!audioRows.length){message('Select at least one exact catalog or audio file in step 1.',true);return;}
    if(!beginMediaOperation())return;
    var remaining=targets.slice(),failed=false,box=$('#ucm-media-sync-results').empty(),chain=$.Deferred().resolve();
    function record(label,r){
      box.append($('<h4>').text(label),window.ucmpReplicationResults(r));
      if(!r.ok){
        failed=true;
        var succeeded=(r.destination_results||[]).filter(function(row){return row.status==='success';}).map(function(row){return Number(row.site_id);});
        remaining=remaining.filter(function(id){return succeeded.indexOf(Number(id))!==-1;});
      }
      return r;
    }
    function step(label,send){
      chain=chain.then(function(){
        if(!remaining.length){box.append($('<p class="text-warning">').text(label+': not started because no destination completed the preceding step.'));return;}
        message('Synchronizing '+label+' to '+remaining.length+' destination(s)…',false);
        return send(remaining.slice()).then(function(resp){return record(label,result(resp));});
      });
    }
    if(wallpaperIds.length)step('Wallpaper catalogs',function(ids){return call({op:'sync_wallpaper_media',scope:'wallpapers',set_ids:JSON.stringify(wallpaperIds),target_site_ids:ids,overwrite:overwrite});});
    if(specialIds.length)step('Special catalogs',function(ids){return hostedCall({op:'special_catalog_sync',set_ids:JSON.stringify(specialIds),target_site_ids:ids,overwrite:overwrite});});
    if(ringtoneNames.length)step('Cisco ringtones',function(ids){return call({op:'sync_wallpaper_media',scope:'ringtones',names:JSON.stringify(ringtoneNames),target_site_ids:ids,overwrite:overwrite});});
    var groups={};audioRows.forEach(function(row){var key=row.family+'|'+row.model;(groups[key]=groups[key]||{family:row.family,model:row.model,names:[]}).names.push(row.name);});
    Object.keys(groups).forEach(function(key){var group=groups[key];step('Audio '+key,function(ids){return mediaRequest({data:{command:'mediaasset',site_id:operationSite(),family:group.family,model:group.model,op:'sync',names:JSON.stringify(group.names),target_site_ids:ids,overwrite:overwrite}});});});
    chain.done(function(){message(failed?'Replication finished with destination failures. Results are shown below; failed destinations did not receive later scopes.':'Selected managed catalogs synchronized to all selected destinations.',failed);})
      .fail(function(){message('The request ended without a confirmed result. Earlier results are retained below; inspect Replication activity before retrying.',true);})
      .always(function(){endMediaOperation();button.prop('disabled',false);if(window.ucmpReplicationActivityRefresh)window.ucmpReplicationActivityRefresh();});
  });
  function formatMediaJobTime(value){return Number(value||0)>0?new Date(Number(value)*1000).toLocaleString():'Never';}
  function refreshMediaJobTargets(){$('.ucm-media-job-target').each(function(){var own=String($(this).val())===String(site());$(this).prop('disabled',own);if(own)$(this).prop('checked',false);});$('#ucm-media-job-summary').text('Source: '+$('#ucm-media-site option:selected').text()+'. Offline targets remain saved and are retried according to this job.');}
  function mediaSchedule(){var mode=String($('#ucm-media-job-mode').val()||'interval'),days=$('.ucm-media-job-weekday:checked').map(function(){return parseInt(this.value,10);}).get();return{mode:mode,interval_minutes:parseInt($('#ucm-media-job-interval').val(),10)||5,time:String($('#ucm-media-job-time').val()||'02:00'),weekdays:mode==='weekly'?days:[1,2,3,4,5,6,7]};}
  function refreshMediaSchedule(){var value=mediaSchedule(),box=$('#ucm-media-job-schedule');box.find('[data-schedule-field="interval"]').prop('hidden',value.mode!=='interval');box.find('[data-schedule-field="clock"]').prop('hidden',['daily','weekly'].indexOf(value.mode)===-1);box.find('[data-schedule-field="weekly"]').prop('hidden',value.mode!=='weekly');return value;}
  function resetMediaJob(){$('#ucm-media-job-id').val('0');$('#ucm-media-job-name').val('Replicate changed media');$('#ucm-media-job-enabled,#ucm-media-job-changed,#ucm-media-job-retry').prop('checked',true);$('#ucm-media-job-mode').val('interval');$('#ucm-media-job-interval').val('30');$('#ucm-media-job-time').val('02:00');$('.ucm-media-job-weekday').prop('checked',true);$('.ucm-media-job-scope').prop('checked',false).filter('[value="wallpapers"],[value="ringtones"]').prop('checked',true);$('.ucm-media-job-target').prop('checked',false);refreshMediaSchedule();refreshMediaJobTargets();}
  function editMediaJob(job){var schedule=job.schedule||{mode:'interval',interval_minutes:job.interval_minutes,time:'02:00',weekdays:[1,2,3,4,5,6,7]};if(String(site())!==String(job.source_site_id))$('#ucm-media-site').val(String(job.source_site_id)).trigger('change');$('#ucm-media-job-id').val(job.id);$('#ucm-media-job-name').val(job.name);$('#ucm-media-job-enabled').prop('checked',!!Number(job.enabled));$('#ucm-media-job-mode').val(schedule.mode||'interval');$('#ucm-media-job-interval').val(schedule.interval_minutes||job.interval_minutes||30);$('#ucm-media-job-time').val(schedule.time||'02:00');$('.ucm-media-job-weekday').each(function(){$(this).prop('checked',(schedule.weekdays||[]).indexOf(parseInt(this.value,10))!==-1);});$('.ucm-media-job-scope').each(function(){$(this).prop('checked',(job.scopes||[]).indexOf(String($(this).val()))!==-1);});$('.ucm-media-job-target').each(function(){$(this).prop('checked',(job.target_site_ids||[]).indexOf(parseInt($(this).val(),10))!==-1);});$('#ucm-media-job-changed').prop('checked',!!Number(job.changed_only));$('#ucm-media-job-retry').prop('checked',!!Number(job.retry_unreachable));refreshMediaSchedule();refreshMediaJobTargets();}
  function loadMediaJobs(){var body=$('#ucm-media-jobs').html('<tr><td colspan="7" class="text-muted">Loading automatic jobs…</td></tr>');replicationCall({op:'list'}).done(function(resp){var r=result(resp);body.empty();mediaJobsById={};if(!r.ok){body.html('<tr><td colspan="7" class="text-danger"></td></tr>').find('td').text(r.message||'Cannot load media replication jobs');return;}(r.jobs||[]).forEach(function(job){mediaJobsById[job.id]=job;var row=$('<tr>'),jobCell=$('<td>').append($('<strong>').text(job.name),$('<small>').text('Source: '+(job.source_site_name||('#'+job.source_site_id))),$('<small>').text(Number(job.enabled)?'Enabled':'Paused')),runCell=$('<td>').append($('<button type="button" class="btn btn-xs btn-success">').text('Run now').on('click',function(){var button=$(this).prop('disabled',true).text('Running…');replicationCall({op:'run',id:job.id}).done(function(resp){var run=result(resp);message(run.message||'Media replication run finished',!run.ok);$('#ucm-media-sync-results').empty().append(window.ucmpReplicationResults(run));loadMediaJobs();}).fail(function(){message('Media replication run request failed.',true);}).always(function(){button.prop('disabled',false).text('Run now');});}),$('<button type="button" class="btn btn-xs btn-default">').text('Edit').on('click',function(){editMediaJob(job);}),$('<button type="button" class="btn btn-xs btn-danger">').text('Delete').on('click',function(){if(!confirm('Delete automatic job '+job.name+'?'))return;replicationCall({op:'delete',id:job.id}).done(function(resp){var deleted=result(resp);message(deleted.message||'Replication job delete complete',!deleted.ok);loadMediaJobs();});}));row.append(jobCell,$('<td>').text(job.schedule_description||('Every '+job.interval_minutes+' min')),$('<td>').text((job.scopes||[]).join(', ')),$('<td>').text((job.target_site_names||[]).join(', ')),$('<td>').append($('<small>').text('Last: '+formatMediaJobTime(job.last_attempt)),$('<small>').text('Next: '+formatMediaJobTime(job.next_run))),$('<td>').append($('<strong>').text(job.last_status||'never'),$('<small>').text(job.last_message||'Not run yet'),job.last_result?window.ucmpReplicationResults(job.last_result):null),runCell);body.append(row);});if(!body.children().length)body.html('<tr><td colspan="7" class="text-muted">No automatic media replication jobs.</td></tr>');}).fail(function(){body.html('<tr><td colspan="7" class="text-danger">Cannot load media replication jobs.</td></tr>');});}
  function loadMediaPanel(name,force,preselectId){if(!force&&mediaPanelLoaded[name]){if(name==='wallpapers')renderStudio(true);if(name==='legacy')renderHosted();return;}mediaPanelLoaded[name]=true;if(name==='wallpapers'){renderStudio(true);loadWallpaperSets();return;}if(name==='legacy'){hostedModels();return;}if(name==='audio'){refreshAudioModels();return;}if(name==='replication'){loadReplicationCatalogs(preselectId);loadMediaJobs();return;}if(name==='diagnostics')loadMediaAssets();}
  function loadInitialMediaPanel(){var epoch=mediaSiteEpoch;loadCatalogSyncStates().always(function(){if(epoch!==mediaSiteEpoch)return;var active=String(mediaRoot.find('[data-media-panel]:not([hidden])').data('media-panel')||'wallpapers');loadMediaPanel(active,false);});}
  function invalidateMediaPanels(){Object.keys(mediaPanelLoaded).forEach(function(name){mediaPanelLoaded[name]=false;});}
  $('#ucm-media-job-new').on('click',resetMediaJob);$('#ucm-media-job-schedule').on('input change',':input',refreshMediaSchedule);refreshMediaSchedule();
  $('#ucm-media-job-form').on('submit',function(event){event.preventDefault();var scopes=$('.ucm-media-job-scope:checked').map(function(){return String($(this).val());}).get(),targets=$('.ucm-media-job-target:checked:not(:disabled)').map(function(){return parseInt($(this).val(),10);}).get(),schedule=refreshMediaSchedule(),payload={op:'save',id:parseInt($('#ucm-media-job-id').val(),10)||0,name:String($('#ucm-media-job-name').val()||'').trim(),source_site_id:parseInt(site(),10),enabled:$('#ucm-media-job-enabled').is(':checked')?1:0,interval_minutes:schedule.interval_minutes,schedule_mode:schedule.mode,schedule_time:schedule.time,schedule_weekdays:JSON.stringify(schedule.weekdays),scopes:JSON.stringify(scopes),target_site_ids:JSON.stringify(targets),changed_only:$('#ucm-media-job-changed').is(':checked')?1:0,retry_unreachable:$('#ucm-media-job-retry').is(':checked')?1:0},button=$('#ucm-media-job-save').prop('disabled',true);if(!payload.name||!scopes.length||!targets.length){message('Job name, at least one content scope, and at least one target site are required.',true);button.prop('disabled',false);return;}if(schedule.mode==='weekly'&&!schedule.weekdays.length){message('Choose at least one weekday for a weekly replication job.',true);button.prop('disabled',false);return;}replicationCall(payload).done(function(resp){var saved=result(resp);message(saved.message||'Media replication job saved',!saved.ok);if(saved.ok){resetMediaJob();loadMediaJobs();}}).fail(function(){message('Media replication job save request failed.',true);}).always(function(){button.prop('disabled',false);});});
  function continueAssetScan(token,capturedSite){capturedSite=capturedSite||site();if(String(site())!==String(capturedSite))return;call({site_id:capturedSite,op:'managed_asset_rescan',scan_token:token||'',kinds:'background,ringtone,locale'}).done(function(resp){var r=result(resp);if(!r.ok){message(r.message||'TFTP verification failed; its saved cursor and existing inventory were retained.',true);$('#ucm-media-assets-rescan').prop('disabled',false);return;}if(!r.complete){message('TFTP verification: '+Number(r.files||0)+' file(s), '+Number(r.directories||0)+' directories, '+Number(r.remaining_tasks||0)+' task(s) remaining. HTTP media will be read live afterward.');window.setTimeout(function(){continueAssetScan(r.scan_token,capturedSite);},50);return;}message('TFTP verification completed; refreshing the live HTTP media libraries…',false);$('#ucm-media-assets-rescan').prop('disabled',false);loadMediaAssets();}).fail(function(){message('TFTP verification request failed. Click Verify / refresh all media to resume its saved cursor.',true);$('#ucm-media-assets-rescan').prop('disabled',false);});}
  $('#ucm-media-assets-rescan').on('click',function(){$(this).prop('disabled',true);message('Verifying TFTP in bounded slices; phone-facing HTTP media will then be read directly from Media Manager storage…');continueAssetScan('');});
  $('#ucm-media-assets-reset').on('click',function(){if(!confirm('Restart only the resumable TFTP traversal? HTTP-served media is always read live and needs no cursor reset. Current catalog rows stay available until verification completes.'))return;var button=$(this).prop('disabled',true);call({op:'managed_asset_scan_reset'}).done(function(resp){var r=result(resp);if(!r.ok){message(r.message||'Could not restart the TFTP scan.',true);return;}message(r.message+' Starting a fresh TFTP verification…');$('#ucm-media-assets-rescan').prop('disabled',true);continueAssetScan('');}).fail(function(){message('Could not clear the saved TFTP cursor; current inventory was preserved.',true);}).always(function(){button.prop('disabled',false);});});
  $('#ucm-bg-rename').on('click',function(){var display=String($('#ucm-bg-name').val()||'').trim(),newId=safeName(display),button=$(this);if(!activeSetId||!display||!newId){message('Open a catalog and enter a valid new catalog name.',true);return;}if(!confirm('Rename this whole catalog to '+display+'? Site/template references will move; old filenames stay as managed compatibility aliases for already-generated phone configs.'))return;button.prop('disabled',true);message('Renaming catalog outputs and references…');call({op:'wallpaper_set_rename',set_id:activeSetId,new_set_id:newId,set_name:display}).done(function(resp){var r=result(resp);message(r.message||'Catalog rename complete',!r.ok);if(r.ok){activeSetId=String(r.set_id||newId);loadWallpaperSets();openWallpaperSet(activeSetId);}}).fail(function(){message('Catalog rename request failed; the previous catalog was retained.',true);}).always(function(){button.prop('disabled',false);});});
  $('#ucm-bg-repair-encoding').on('click',function(){var button=$(this);if(!activeSetId){message('Open a wallpaper catalog first.',true);return;}if(!confirm('Re-encode legacy Cisco PNG outputs in this catalog? Crop, placement, source images and non-Cisco outputs remain unchanged.'))return;button.prop('disabled',true);message('Checking and repairing Cisco PNG encoding…');call({op:'wallpaper_set_repair_encoding',set_id:activeSetId}).done(function(resp){var r=result(resp);message(r.message||'Cisco PNG repair finished',!r.ok);if(r.ok){loadWallpaperSets();openWallpaperSet(activeSetId);mediaPanelLoaded.diagnostics=false;}}).fail(function(){message('Cisco PNG repair request failed; existing files were retained.',true);}).always(function(){button.prop('disabled',false);});});
  $('#ucm-bg-repair-hosted').on('click',function(){
    if(!activeSetId)return;
    var button=$(this),captured={op:'wallpaper_set_repair_hosted_names',site_id:site(),set_id:activeSetId};
    function current(){return String(site())===String(captured.site_id)&&activeSetId===captured.set_id;}
    button.prop('disabled',true);message('Verifying existing images and repairing hosted filenames…');
    call(captured).done(function(resp){if(!current())return;var r=result(resp);message(r.message||'Hosted filename repair finished',!r.ok);loadWallpaperSets();openWallpaperSet(captured.set_id);mediaPanelLoaded.diagnostics=false;})
      .fail(function(){if(current())message('Repair completion was not confirmed. Reopen the catalog before retrying.',true);})
      .always(function(){button.prop('disabled',!activeSetId);});
  });
  $('#ucm-hosted-family').on('change',function(){resetSpecialCatalog();hostedModels();});
  $('#ucm-hosted-model').on('change',function(){hostedPreset();loadHosted();});
  $('#ucm-hosted-new').on('click',resetSpecialCatalog);
  $('#ucm-bg-coverage').on('change',function(){updateCoverageFields(true);});
  $('#ucm-bg-brand').on('change',function(){$('#ucm-bg-series,#ucm-bg-model-scope').val('');refreshWallpaperScope();});
  $('#ucm-bg-series').on('change',function(){$('#ucm-bg-model-scope').val('');refreshWallpaperScope();});
  $('#ucm-bg-model-scope').on('change',function(){var matching=profiles();$('#ucm-media-model option').each(function(){var p=profileFrom($(this)),show=matching.some(function(candidate){return candidate.key===p.key;});$(this).prop('disabled',!show).toggle(show);});if($('#ucm-media-model option:selected').prop('disabled'))$('#ucm-media-model option:not(:disabled)').first().prop('selected',true);activeProfileKey=profile().key;restoreTransform(activeProfileKey);loadProfileImage(activeProfileKey).always(function(){updatePath();renderStudio(true);});});
  $('#ucm-media-model').on('change',function(){rememberTransform(activeProfileKey);activeProfileKey=profile().key;restoreTransform(activeProfileKey);updatePath();loadProfileImage(activeProfileKey).done(function(){renderStudio(true);}).fail(function(error){message(String(error||'Profile-specific source could not be loaded.'),true);});});
  $('#ucm-media-site').on('change',function(){if(mediaOperation){$(this).val(mediaOperation.site_id);return;}mediaEpoch++;mediaSiteEpoch++;replicationCatalogRequest++;retirement=null;retirementPreviewRequest++;$('#ucm-asset-retirement').prop('hidden',true);var active=String(mediaRoot.find('[data-media-panel]:not([hidden])').data('media-panel')||'wallpapers');refreshSyncTargets();refreshMediaJobTargets();newWallpaperSet();resetSpecialCatalog();clearRingObjectUrls();$('#ucm-ring-file').val('');$('#ucm-ring-upload-preview').empty();catalogSyncStates={wallpaper_catalog:{},special_catalog:{}};invalidateMediaPanels();loadInitialMediaPanel();});
  $('#ucm-bg-new').on('click',newWallpaperSet);
  $('#ucm-bg-new-library').on('click',newWallpaperSet);
  $('#ucm-media-sync-refresh').on('click',function(){loadReplicationCatalogs();});
  $('#ucm-audio-family,#ucm-audio-model,#ucm-ring-type').on('change',function(){replicationCatalogRequest++;mediaPanelLoaded.replication=false;});
  $('#ucm-bg-refresh').on('click',function(){loadWallpaperSets();loadLegacyBackgrounds();});
  $('#ucm-ring-type').on('change',function(){$('#ucm-ring-file').val('');$('#ucm-ring-upload-preview').empty();loadRings();});
  $('#ucm-audio-family').on('change',refreshAudioModels);
  $('#ucm-audio-model').on('change',function(){$('#ucm-ring-file').val('');$('#ucm-ring-upload-preview').empty();loadRings();});
  $('#ucm-media-assets-refresh').on('click',loadMediaAssets);
  $('#ucm-media-assets-prev').on('click',function(){mediaAssetPage--;renderMediaAssetPage();});
  $('#ucm-media-assets-next').on('click',function(){mediaAssetPage++;renderMediaAssetPage();});
  $('#ucm-bg-legacy-details').on('toggle',function(){if(this.open)loadLegacyBackgrounds();});
  if(!enforceMediaContract())return;
  updateCoverageFields(false);activeProfileKey=profile().key;restoreTransform(activeProfileKey);refreshSyncTargets();refreshMediaJobTargets();refreshWallpaperScope();updatePath();loadInitialMediaPanel();
})(jQuery);
</script>
