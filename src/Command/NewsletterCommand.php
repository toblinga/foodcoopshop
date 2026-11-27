<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\NewsletterCampaign;
use App\Services\MailTransportService;
use App\Services\Newsletter\NewsletterCampaignService;
use App\Services\Newsletter\NewsletterLibraryService;
use App\Services\Newsletter\NewsletterSubscriberService;
use App\Services\Newsletter\RapidmailArchiveImportService;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use Throwable;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * bin/cake newsletter <aktion> [id] [email]
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterCommand extends AppCommand
{

    public const ACTIONS = [
        'status' => 'Übersicht Abonnent:innen und Kampagnen (mit id: Fortschritt einer Kampagne)',
        'sync_customers' => 'Kund:innen mit newsletter_enabled = 1 als aktive Abonnent:innen übernehmen',
        'purge_pending' => 'Unbestätigte Anmeldungen mit abgelaufenem Link löschen',
        'create_test_campaign' => 'Kampagnen-Entwurf mit Beispiel-Bausteinen anlegen',
        'render' => 'Kampagne <id> rendern (html_cache), Ausgabe mit --out <datei>',
        'send_test' => 'Testmail der Kampagne <id> an <email> (über die Queue)',
        'start' => 'Versand der Kampagne <id> anstoßen (Empfänger:innen eintragen, Queue)',
        'resume' => 'Versand der Kampagne <id> wieder anstoßen (nach Abbruch)',
        'process' => 'eine Portion der Kampagne <id> sofort verschicken (ohne Queue, zum Testen)',
        'send_due' => 'Geplante Kampagnen starten, deren Termin erreicht ist (per Cron, z. B. alle 5 Minuten)',
        'import_library' =>'Standard-Bausteine und Standard-Vorlage aus config/newsletter_library.json installieren (vorhandene bleiben)',
        'import_archive' => 'Versendete Newsletter aus .eml-Dateien übernehmen: --dir <ordner> [--dry-run] (doppelte werden übersprungen)',
    ];

    public static function defaultName(): string
    {
        return 'newsletter';
    }

    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $description = "Newsletter-Werkzeuge. Aktionen:\n";
        foreach (self::ACTIONS as $action => $text) {
            $description .= '  ' . str_pad($action, 22) . $text . "\n";
        }
        $parser->setDescription($description);
        $parser->addArgument('action', ['required' => true, 'choices' => array_keys(self::ACTIONS)]);
        $parser->addArgument('id', ['required' => false, 'help' => 'Kampagnen-ID']);
        $parser->addArgument('email', ['required' => false, 'help' => 'Adresse für send_test']);
        $parser->addOption('out', ['help' => 'Datei für render']);
        $parser->addOption('subject', ['help' => 'Betreff für create_test_campaign']);
        $parser->addOption('dir', ['help' => 'import_archive: Ordner mit .eml-Dateien']);
        $parser->addOption('file', ['help' => 'import_library: eigene JSON-Datei (Standard: config/newsletter_library.json)']);
        $parser->addOption('dry-run', ['boolean' => true, 'help' => 'import_archive: nur prüfen, nichts speichern']);
        $parser->addOption('retry-stuck', [
            'help' => 'resume: Empfänger:innen, die länger als so viele Minuten in "sending" hängen, erneut einreihen (Achtung: möglicher Doppelversand)',
        ]);
        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $action = (string)$args->getArgument('action');
        $campaignService = new NewsletterCampaignService();
        $subscriberService = new NewsletterSubscriberService();

        try {
            switch ($action) {
                case 'status':
                    return $this->status($args, $io, $campaignService);
                case 'sync_customers':
                    $result = $subscriberService->importCustomersWithNewsletterEnabled();
                    $io->out(sprintf('Neu: %d, verknüpft: %d, übersprungen: %d', $result['created'], $result['linked'], $result['skipped']));
                    return static::CODE_SUCCESS;
                case 'purge_pending':
                    $io->out('Gelöscht: ' . $subscriberService->purgeExpiredPending());
                    return static::CODE_SUCCESS;
                case 'create_test_campaign':
                    $campaign = $campaignService->create([
                        'subject' => (string)($args->getOption('subject') ?: 'Testausgabe ' . date('d.m.Y H:i')),
                        'preheader' => 'Neues aus dem Laden – Testausgabe',
                        'blocks' => self::getSampleBlocks(),
                    ]);
                    $io->out('Kampagne angelegt: ' . $campaign->id);
                    return static::CODE_SUCCESS;
                case 'render':
                    $campaign = $campaignService->get($this->requireId($args));
                    $html = $campaignService->render($campaign);
                    $out = (string)$args->getOption('out');
                    if ($out !== '') {
                        file_put_contents($out, $html);
                        $io->out('Geschrieben: ' . $out);
                    } else {
                        $io->out($html);
                    }
                    return static::CODE_SUCCESS;
                case 'send_test':
                    $campaign = $campaignService->get($this->requireId($args));
                    $email = (string)$args->getArgument('email');
                    if ($email === '') {
                        $io->err('Bitte Adresse angeben: bin/cake newsletter send_test <id> <email>');
                        return static::CODE_ERROR;
                    }
                    $campaignService->sendTest($campaign, $email);
                    $io->out('Testmail eingereiht (Mailweg: ' . MailTransportService::getTransportName() . '). Queue-Worker: bin/cake queue run');
                    return static::CODE_SUCCESS;
                case 'start':
                    $campaign = $campaignService->get($this->requireId($args));
                    $count = $campaignService->start($campaign);
                    $io->out('Versand angestoßen, Empfänger:innen: ' . $count . ', ' . NewsletterCampaignService::getSendsPerMinute() . ' pro Minute.');
                    return static::CODE_SUCCESS;
                case 'resume':
                    $campaign = $campaignService->get($this->requireId($args));
                    $retry = $args->getOption('retry-stuck');
                    $open = $campaignService->resume($campaign, $retry !== null && $retry !== '' ? (int)$retry : null);
                    $io->out('Offen: ' . $open);
                    return static::CODE_SUCCESS;
                case 'process':
                    $result = $campaignService->processBatch($this->requireId($args), false);
                    $io->out(json_encode($result) ?: '');
                    return static::CODE_SUCCESS;
                case 'send_due':
                    $due = $campaignService->sendDue();
                    foreach ($due['started'] as $id) {
                        $io->out('Versand gestartet: Kampagne ' . $id);
                    }
                    foreach ($due['failed'] as $id => $reason) {
                        $io->err('Geplanter Versand abgebrochen (Kampagne ' . $id . '): ' . $reason);
                    }
                    if ($due['started'] === [] && $due['failed'] === []) {
                        $io->out('Nichts fällig.');
                    }
                    return $due['failed'] === [] ? static::CODE_SUCCESS : static::CODE_ERROR;
                case 'import_library':
                    $file = (string)$args->getOption('file');
                    $installed = (new NewsletterLibraryService())->install($file !== '' ? $file : null);
                    $io->out(sprintf('Bausteine neu: %d, Vorlagen neu: %d', $installed['blocks'], $installed['templates']));
                    foreach ($installed['skipped'] as $line) {
                        $io->out('  vorhanden, unverändert: ' . $line);
                    }
                    return static::CODE_SUCCESS;
                case 'import_archive':
                    $dir = (string)$args->getOption('dir');
                    if ($dir === '') {
                        $io->err('Bitte Ordner angeben: bin/cake newsletter import_archive --dir <ordner> [--dry-run]');
                        return static::CODE_ERROR;
                    }
                    $dryRun = (bool)$args->getOption('dry-run');
                    $result = (new RapidmailArchiveImportService())->importDirectory($dir, $dryRun);
                    foreach ($result['campaigns'] as $c) {
                        $io->out(sprintf('%-18s %s  %s  (Bilder: %d)%s', $c['status'], $c['sent_at'], $c['subject'], $c['images'], $c['id'] ? ' [id ' . $c['id'] . ']' : ''));
                    }
                    foreach ($result['errors'] as $line) {
                        $io->err('Fehler: ' . $line);
                    }
                    $io->out(sprintf(
                        'Dateien: %d, %s: %d, vorhanden/übersprungen: %d, Bilder: %d%s',
                        $result['files'],
                        $dryRun ? 'würden importiert' : 'importiert',
                        $result['imported'],
                        $result['skipped'],
                        $result['images'],
                        $dryRun ? ' (Probelauf, nichts gespeichert)' : '',
                    ));
                    return $result['errors'] === [] ? static::CODE_SUCCESS : static::CODE_ERROR;
            }
        } catch (Throwable $e) {
            $io->err($e->getMessage());
            return static::CODE_ERROR;
        }
        return static::CODE_ERROR;
    }

    private function requireId(Arguments $args): int
    {
        $id = (int)$args->getArgument('id');
        if ($id <= 0) {
            throw new \InvalidArgumentException('Bitte Kampagnen-ID angeben.');
        }
        return $id;
    }

    private function status(Arguments $args, ConsoleIo $io, NewsletterCampaignService $campaignService): int
    {
        $id = (int)$args->getArgument('id');
        if ($id > 0) {
            $campaign = $campaignService->get($id);
            $io->out(json_encode($campaignService->getProgress($campaign)) ?: '');
            return static::CODE_SUCCESS;
        }
        $subscribersTable = TableRegistry::getTableLocator()->get('NewsletterSubscribers');
        $rows = $subscribersTable->find()
            ->select(['status', 'cnt' => $subscribersTable->find()->func()->count('*')])
            ->groupBy(['status'])
            ->disableHydration()
            ->all();
        $io->out('Abonnent:innen:');
        foreach ($rows as $row) {
            $io->out('  ' . str_pad((string)$row['status'], 14) . $row['cnt']);
        }
        $io->out('Mailweg: ' . MailTransportService::getTransportName() . ', max. ' . (MailTransportService::getMaxPerMinute() ?: 'unbegrenzt') . ' Mails/Minute, Newsletter ' . NewsletterCampaignService::getSendsPerMinute() . '/Minute');
        $io->out('Kampagnen:');
        /** @var iterable<NewsletterCampaign> $campaigns */
        $campaigns = TableRegistry::getTableLocator()->get('NewsletterCampaigns')->find()->orderBy(['id' => 'DESC'])->limit(20)->all();
        foreach ($campaigns as $campaign) {
            $io->out(sprintf('  #%d %-8s %d/%d gesendet, %d Fehler  %s', $campaign->id, $campaign->status, $campaign->count_sent, $campaign->count_total, $campaign->count_failed, $campaign->subject));
        }
        return static::CODE_SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getSampleBlocks(): array
    {
        return [
            ['type' => 'heading', 'text' => 'Hallo {{first_name}}, frisch eingetroffen!', 'level' => 1],
            ['type' => 'text', 'html' => '<p>Das ist eine <strong>Testausgabe</strong> des neuen Newsletters. Diese Woche gibt es knackiges Wintergemüse, frisches Brot und neue Käsesorten aus der Region.</p>'],
            ['type' => 'button', 'label' => 'Zum Shop', 'href' => '/shop'],
            ['type' => 'divider'],
            ['type' => 'heading', 'text' => 'Rezept der Woche', 'level' => 2],
            ['type' => 'text', 'html' => '<p>Kürbis-Linsen-Suppe mit Kernöl: einfach, wärmend und in 30 Minuten fertig.</p><ul><li>1 Hokkaido</li><li>150 g rote Linsen</li><li>Kürbiskernöl</li></ul>'],
            ['type' => 'button', 'label' => 'Zu den Rezepten', 'href' => '/rezepte'],
            ['type' => 'spacer', 'height' => 12],
        ];
    }

}
