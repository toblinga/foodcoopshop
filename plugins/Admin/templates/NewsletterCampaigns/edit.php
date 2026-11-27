<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Newsletter-Kampagne: Bausteineditor (Entwurf) bzw. Versandstand und Vorschau.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
use Cake\Core\Configure;
use Cake\Core\Plugin;

$asset = function (string $type, string $file): string {
    $path = Plugin::path('Admin') . 'webroot' . DS . $type . DS . str_replace('/', DS, $file);
    return '/admin/' . $type . '/' . $file . '?v=' . (file_exists($path) ? filemtime($path) : '0');
};
$id = (int)$campaign->id;
$isDraft = $campaign->status === 'draft';

$this->element('addScript', ['script' => Configure::read('app.jsNamespace') . '.Admin.init();']);
echo $this->element('Newsletter/adminTabs', ['active' => 'campaigns']);

if (!$isDraft) {
    $statusLabels = ['sending' => 'wird gesendet', 'sent' => 'gesendet'];
    ?>
    <div id="newsletterCampaignStatus" class="nl-sent-view">
        <div class="filter-container">
            <h1><?php echo h($campaign->subject); ?></h1>
            <div class="right">
                <?php echo $this->Html->link('<i class="fas fa-arrow-left"></i> Zur Liste', '/admin/newsletter-campaigns', ['class' => 'btn btn-outline-light', 'escape' => false]); ?>
                <?php echo $this->Form->postLink('<i class="far fa-copy"></i> Als neuen Entwurf kopieren', '/admin/newsletter-campaigns/duplicate/' . $id, ['class' => 'btn btn-outline-light', 'escape' => false]); ?>
            </div>
        </div>
        <p>Status: <b id="nl-status"><?php echo h($statusLabels[$campaign->status] ?? $campaign->status); ?></b>
            <?php if ($campaign->started_at) { ?> · gestartet <?php echo $campaign->started_at->format('d.m.Y H:i'); ?><?php } ?>
            <?php if ($campaign->sent_at) { ?> · fertig <?php echo $campaign->sent_at->format('d.m.Y H:i'); ?><?php } ?>
        </p>
        <div class="nl-progress-box">
            <div class="nl-bar"><div id="nl-bar-fill" style="width:<?php echo $progress['total'] ? round(($progress['sent'] + $progress['failed'] + $progress['skipped']) / $progress['total'] * 100) : 0; ?>%"></div></div>
            <p id="nl-progress-text"><?php echo (int)$progress['sent']; ?> verschickt, <?php echo (int)$progress['failed']; ?> Fehler, <?php echo (int)$progress['skipped']; ?> übersprungen, <?php echo (int)$progress['queued'] + (int)$progress['sending']; ?> offen – von <?php echo (int)$progress['total']; ?></p>
        </div>
        <div class="nl-preview-switch">
            <button type="button" class="btn btn-outline-light active" data-w="100%">Desktop</button>
            <button type="button" class="btn btn-outline-light" data-w="390px">Handy (390 px)</button>
        </div>
        <div class="nl-preview-wrap"><iframe id="nl-preview" src="/admin/newsletter-campaigns/preview/<?php echo $id; ?>" title="Vorschau"></iframe></div>
    </div>
    <style>
        .nl-progress-box { max-width: 640px; }
        .nl-bar { height: 14px; background: #f7efe9; border-radius: 7px; overflow: hidden; }
        .nl-bar div { height: 100%; background: #800000; transition: width .5s; }
        .nl-preview-switch { margin: 10px 0; }
        .nl-preview-switch .btn.active { background: #800000 !important; color: #fff !important; border-color: #800000 !important; }
        .nl-preview-wrap { background: #ddd3ca; padding: 12px; border-radius: 8px; text-align: center; }
        #nl-preview { width: 100%; max-width: 760px; height: 900px; border: 0; background: #fff; }
    </style>
    <script>
    (function () {
        document.querySelectorAll('.nl-preview-switch button').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('.nl-preview-switch button').forEach(function (x) { x.classList.remove('active'); });
                b.classList.add('active');
                document.getElementById('nl-preview').style.maxWidth = b.getAttribute('data-w');
            });
        });
        <?php if ($campaign->status === 'sending') { ?>
        (function poll() {
            fetch('/admin/newsletter-campaigns/progress/<?php echo $id; ?>', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (p) {
                    var d = p.detail || {};
                    document.getElementById('nl-bar-fill').style.width = (p.total ? Math.round(p.sent / p.total * 100) : 0) + '%';
                    document.getElementById('nl-progress-text').textContent = (d.sent || 0) + ' verschickt, ' + (d.failed || 0) + ' Fehler, ' + (d.skipped || 0) + ' übersprungen, ' + ((d.queued || 0) + (d.sending || 0)) + ' offen – von ' + p.total;
                    document.getElementById('nl-status').textContent = p.status === 'sent' ? 'gesendet' : 'wird gesendet';
                    if (p.status !== 'sent') setTimeout(poll, 5000);
                });
        })();
        <?php } ?>
    })();
    </script>
    <?php
    return;
}

$config = [
    'campaignId' => $id,
    'logoUrl' => $logoUrl,
    'initial' => $initial,
    'urls' => [
        'save' => '/admin/newsletter-campaigns/save/{id}',
        'check' => '/admin/newsletter/campaigns/{id}/check',
        'test' => '/admin/newsletter-campaigns/send-test/{id}',
        'count' => '/admin/newsletter-campaigns/recipient-count/{id}',
        'start' => '/admin/newsletter-campaigns/start/{id}',
        'schedule' => '/admin/newsletter-campaigns/schedule/{id}',
        'progress' => '/admin/newsletter-campaigns/progress/{id}',
        'products' => '/admin/newsletter-campaigns/products',
        'posts' => '/admin/newsletter-campaigns/posts',
        'recipes' => '/admin/newsletter-campaigns/posts',
        'media' => '/admin/newsletter-campaigns/media',
        'upload' => '/admin/newsletter-campaigns/upload',
        'templates' => '/admin/newsletter-templates/items',
        'templateSave' => '/admin/newsletter-templates/save',
        'templateDelete' => '/admin/newsletter-templates/delete/{tid}',
        'list' => '/admin/newsletter-campaigns',
    ],
];
?>
<link rel="stylesheet" href="<?php echo $asset('css', 'newsletter-editor.css'); ?>">
<style>
    /* Editor fuellt den Inhaltsbereich des Admins */
    #newsletter-editor-page { margin: 0 -10px; }
    #newsletter-editor-page .app { --nl-height: calc(100vh - 110px); min-height: 640px; border: 1px solid #e6d9cf; border-radius: 8px; overflow: hidden; }
    #newsletter-editor-page .brand a { color: inherit; text-decoration: none; }
</style>
<div id="newsletter-editor-page">
<div class="app nl-app">
    <header class="topbar">
        <div class="brand"><span class="logo-dot" aria-hidden="true"></span><div><strong>Newsletter</strong><small><a href="/admin/newsletter-campaigns">&larr; zur Kampagnenliste</a></small></div></div>
        <div class="top-group">
            <button type="button" id="btn-undo" class="tbtn" title="Rückgängig (Strg+Z)"></button>
            <button type="button" id="btn-redo" class="tbtn" title="Wiederholen (Strg+Y)"></button>
        </div>
        <div class="top-group seg" id="device" role="group" aria-label="Ansicht">
            <button type="button" class="on" data-device="desktop" aria-pressed="true">Desktop</button>
            <button type="button" data-device="mobile" aria-pressed="false">Handy</button>
        </div>
        <span id="save-status" class="save-status" aria-live="polite"></span>
        <div class="top-group right">
            <button type="button" id="btn-preview" class="tbtn"></button>
            <button type="button" id="btn-test" class="tbtn"></button>
            <button type="button" id="btn-schedule" class="tbtn"></button>
            <button type="button" id="btn-send" class="tbtn primary"></button>
        </div>
    </header>
    <aside class="palette" aria-label="Bausteine">
        <div class="tabs" role="tablist">
            <button type="button" role="tab" data-tab="blocks">Bausteine</button>
            <button type="button" role="tab" data-tab="saved">Gespeichert</button>
            <button type="button" role="tab" data-tab="templates">Vorlagen</button>
        </div>
        <div class="tab-panel" id="tab-blocks" role="tabpanel"></div>
        <div class="tab-panel" id="tab-saved" role="tabpanel" hidden></div>
        <div class="tab-panel" id="tab-templates" role="tabpanel" hidden></div>
    </aside>
    <main class="stage" id="paper-wrap"><div class="paper" id="paper"></div></main>
    <aside class="props" id="props" aria-label="Einstellungen"></aside>
</div>
</div>
<div id="toasts" class="toasts"></div>
<script>window.NL_CONFIG = <?php echo json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="<?php echo $asset('js', 'newsletter/nl-render.js'); ?>"></script>
<script src="<?php echo $asset('js', 'newsletter/nl-blocks.js'); ?>"></script>
<script src="<?php echo $asset('js', 'newsletter/fcs-adapters.js'); ?>"></script>
<script src="<?php echo $asset('js', 'newsletter/nl-editor.js'); ?>"></script>
<script>
    (function start() {
        if (!window.Jodit) { setTimeout(start, 50); return; }
        NLEditor.init();
    })();
</script>
