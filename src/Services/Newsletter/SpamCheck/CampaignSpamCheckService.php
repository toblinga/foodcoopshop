<?php
declare(strict_types=1);

namespace App\Services\Newsletter\SpamCheck;

use App\Model\Entity\NewsletterCampaign;
use App\Model\Entity\NewsletterSubscriber;
use App\Services\Newsletter\NewsletterCampaignService;
use Cake\Core\Configure;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 *
 * Verbindet den Spam-Check mit einer Kampagne: rendert sie wie beim Versand
 * (NewsletterCampaignService::buildMessage), uebergibt Header, Textteil und
 * Absender und ordnet Meldungen den Bausteinen zu.
 */
class CampaignSpamCheckService
{

    public function __construct(
        private readonly ?NewsletterCampaignService $campaignService = null,
        private readonly ?NewsletterSpamCheckService $spamCheck = null,
    ) {
    }

    /**
     * Bausteine der Kampagne: Editor-Format {"format":"fcs-newsletter-blocks","blocks":[…]}
     * oder flache Liste (Phase-1-Renderer).
     *
     * @return array{editorFormat: bool, blocks: list<array<string, mixed>>}
     */
    public static function blocksOf(NewsletterCampaign $campaign): array
    {
        $decoded = json_decode((string)$campaign->blocks, true);
        if (is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])) {
            return ['editorFormat' => true, 'blocks' => array_values(array_filter($decoded['blocks'], 'is_array'))];
        }
        return ['editorFormat' => false, 'blocks' => $campaign->getBlocksArray()];
    }

    /**
     * @param array<string, mixed> $options zusaetzliche Optionen fuer NewsletterSpamCheckService::check()
     * @param string|null $html fertiges HTML (z. B. ungespeicherter Stand aus dem Editor); null = html_cache bzw. Renderer
     */
    public function check(NewsletterCampaign $campaign, array $options = [], ?string $html = null): SpamCheckResult
    {
        $campaignService = $this->campaignService ?? new NewsletterCampaignService();
        $baseUrl = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        $blocks = self::blocksOf($campaign);

        // HTML mit Platzhaltern (bekannte Platzhalter sind erlaubt), Header und Textteil wie beim Versand.
        // Editor-Kampagnen rendert der Client (html_cache), alte Kampagnen der PHP-Renderer.
        if ($html === null || trim($html) === '') {
            $html = $blocks['editorFormat'] ? (string)$campaign->html_cache : $campaignService->render($campaign, false);
        }
        $unsubscribeUrl = $baseUrl . '/newsletter/spamcheck-abmelden';
        $message = $campaignService->buildMessage(
            $campaign,
            $html,
            new NewsletterSubscriber(['first_name' => 'Vorname', 'email' => 'spamcheck@example.test']),
            $unsubscribeUrl,
        );
        $from = $message->getFrom();
        $replyTo = $message->getReplyTo();
        $headers = [];
        foreach ($message->getHeaders(['from', 'subject']) as $name => $value) {
            $headers[(string)$name] = (string)$value;
        }

        $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $isTestInstance = (bool)Configure::read('debug')
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || (bool)preg_match('/\.(test|localhost|local)$/', $host);

        $options += [
            'language' => str_starts_with((string)Configure::read('App.defaultLocale'), 'en') ? 'en' : 'de',
            'textPart' => $message->getBodyText(),
            'fromEmail' => (string)array_key_first($from),
            'fromName' => (string)($from[array_key_first($from)] ?? ''),
            'replyTo' => (string)(array_key_first($replyTo) ?? ''),
            'headers' => $headers,
            'isTestInstance' => $isTestInstance,
        ];

        $spamCheck = $this->spamCheck ?? new NewsletterSpamCheckService(
            null,
            null,
            new SystemDnsResolver(),
            new LocalImageInfoProvider([
                $baseUrl . '/' => WWW_ROOT,
                '/' => WWW_ROOT,
            ]),
            new HttpLinkProbe(),
        );
        $result = $spamCheck->check($html, (string)$campaign->subject, (string)$campaign->preheader, $options);
        // Editor-HTML traegt data-block-id selbst; sonst Zuordnung ueber den Inhalt
        return $this->assignBlocks($result, $blocks['blocks']);
    }

    /**
     * Ordnet Meldungen ohne blockId einem Baustein zu, solange der Renderer die
     * Bausteine nicht mit data-block-id kennzeichnet: Suche nach Bild, Link oder
     * Textausschnitt der Meldung im JSON der Bausteine.
     * blockId = 'id' des Bausteins, sonst sein Index (0-basiert) als String.
     *
     * @param list<array<string, mixed>> $blocks
     */
    public function assignBlocks(SpamCheckResult $result, array $blocks): SpamCheckResult
    {
        if ($blocks === []) {
            return $result;
        }
        $haystacks = [];
        foreach ($blocks as $index => $block) {
            $id = isset($block['id']) && is_scalar($block['id']) ? (string)$block['id'] : (string)$index;
            $haystacks[$id] = mb_strtolower(html_entity_decode(strip_tags((string)json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $findings = [];
        foreach ($result->findings as $finding) {
            if ($finding->blockId === null && $finding->field === 'body' && $finding->path !== null) {
                $blockId = $this->findBlock($finding, $haystacks);
                if ($blockId !== null) {
                    $finding = new SpamCheckFinding(
                        $finding->ruleId,
                        $finding->level,
                        $finding->category,
                        $finding->message,
                        $finding->tip,
                        $finding->field,
                        $blockId,
                        $finding->path,
                        $finding->params,
                        $finding->weight,
                    );
                }
            }
            $findings[] = $finding;
        }
        return new SpamCheckResult($findings, $result->score, $result->traffic, $result->trafficLabel, $result->stats, $result->spamAssassin);
    }

    /**
     * @param array<string, string> $haystacks
     */
    private function findBlock(SpamCheckFinding $finding, array $haystacks): ?string
    {
        $needles = [];
        foreach (['src', 'href', 'snippet', 'placeholder', 'text', 'textHost', 'host'] as $key) {
            $value = trim((string)($finding->params[$key] ?? ''), " …\t\n");
            if (mb_strlen($value) >= 3 && $value !== 'data:') {
                $needles[] = mb_strtolower($value);
            }
        }
        foreach ($needles as $needle) {
            foreach ($haystacks as $id => $haystack) {
                if (str_contains($haystack, $needle)) {
                    return (string)$id;
                }
            }
        }
        return null;
    }

}
