<?php
declare(strict_types=1);

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @link          https://www.foodcoopshop.com
 */

namespace Api\Controller;

use Api\Contacts\ContactCards;
use App\Model\Entity\Customer;
use App\Model\Table\CustomerPermissionsTable as P;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use DOMDocument;
use DOMElement;

/**
 * Read-only CardDAV server (RFC 6352) for the member contacts, e.g. for Thunderbird and DAVx5 (Android):
 *
 *   /api/v1/carddav/                         start (PROPFIND)
 *   /api/v1/carddav/principal/               user principal
 *   /api/v1/carddav/contacts/                address book
 *   /api/v1/carddav/contacts/fcs-customer-N.vcf   one contact
 *   /.well-known/carddav                     redirects to /api/v1/carddav/
 *
 * Login: any user name, the API token as password (Basic) - or the token as Bearer.
 * Same rules as /api/v1/contacts: permission contacts.read, only active members with consent,
 * every delivery of contact data is written to the action log. Nothing can be changed via CardDAV.
 */
class CarddavController extends ApiAppController
{
    private const DAV = 'DAV:';
    private const CARD = 'urn:ietf:params:xml:ns:carddav';
    private const CS = 'http://calendarserver.org/ns/';

    private string $prefix = '';

    public function beforeFilter(EventInterface $event): void
    {
        if ($this->getRequest()->getParam('action') === 'wellKnown') {
            $this->autoRender = false;
            return;
        }
        parent::beforeFilter($event);
    }

    protected function unauthenticated(): Response
    {
        return parent::unauthenticated()
            ->withHeader('WWW-Authenticate', 'Basic realm="FoodCoopShop contacts", charset="UTF-8"');
    }

    private function davHeaders(Response $response): Response
    {
        return $response->withHeader('DAV', '1, 3, addressbook');
    }

    /**
     * Address of the CardDAV start including a possible subdirectory of the installation.
     */
    private function prefixFromPath(string $path, string $marker, string $target): string
    {
        $pos = strpos($path, $marker);
        return ($pos === false ? '' : substr($path, 0, $pos)) . $target;
    }

    public function wellKnown(): Response
    {
        $location = $this->prefixFromPath($this->getRequest()->getUri()->getPath(), '/.well-known/', '/api/v1/carddav/');
        return $this->getResponse()->withStatus(301)->withHeader('Location', $location);
    }

    public function handle(): Response
    {
        if ($r = $this->requirePermission(P::CONTACTS_READ)) {
            return $this->davHeaders($r);
        }
        $request = $this->getRequest();
        $path = $request->getUri()->getPath();
        $this->prefix = $this->prefixFromPath($path, '/api/v1/carddav', '/api/v1/carddav');
        $relative = trim(substr($path, strlen($this->prefix)), '/');
        $segments = $relative === '' ? [] : array_map('rawurldecode', explode('/', $relative));

        $resource = $this->resolve($segments);
        $method = strtoupper((string) ($request->getAttribute('davMethod') ?? $request->getMethod()));

        if ($resource === null) {
            return $this->davHeaders($this->text(404, 'not found'));
        }
        return $this->davHeaders(match ($method) {
            'PROPFIND' => $this->propfind($resource),
            'REPORT' => $this->report($resource),
            'GET', 'HEAD' => $this->get($resource, $method === 'HEAD'),
            'OPTIONS' => $this->getResponse()->withHeader('Allow', 'OPTIONS, GET, HEAD, PROPFIND, REPORT')->withStatus(200),
            default => $this->text(403, 'read-only address book')
                ->withHeader('Allow', 'OPTIONS, GET, HEAD, PROPFIND, REPORT'),
        });
    }

    /**
     * @param list<string> $segments
     * @return array{type: string, href: string, customerId?: int}|null
     */
    private function resolve(array $segments): ?array
    {
        $href = $this->prefix . '/' . ($segments ? join('/', array_map('rawurlencode', $segments)) : '');
        return match (true) {
            $segments === [] => ['type' => 'root', 'href' => $href],
            $segments === ['principal'] => ['type' => 'principal', 'href' => $href . '/'],
            $segments === ['contacts'] => ['type' => 'book', 'href' => $href . '/'],
            count($segments) === 2 && $segments[0] === 'contacts' && ContactCards::customerIdFromName($segments[1]) !== null
                => ['type' => 'card', 'href' => $href, 'customerId' => ContactCards::customerIdFromName($segments[1])],
            default => null,
        };
    }

    // ---------------------------------------------------------------- data

    /**
     * @return array<int, array{customer: Customer, vcard: string, etag: string}> by customer id
     */
    private function cards(): array
    {
        $cards = [];
        foreach (ContactCards::customers($this->getTableLocator()) as $customer) {
            $vcard = ContactCards::vcard($customer);
            $cards[$customer->id_customer] = ['customer' => $customer, 'vcard' => $vcard, 'etag' => ContactCards::etag($vcard)];
        }
        return $cards;
    }

    /**
     * @param array<int, array{customer: Customer, vcard: string, etag: string}> $cards
     */
    private function ctag(array $cards): string
    {
        return md5(join('|', array_map(fn($c) => $c['etag'], $cards)) . '#' . count($cards));
    }

    private function logDelivery(int $count): void
    {
        $this->getTableLocator()->get('ActionLogs')->customSave(
            'customer_contacts_exported', $this->customer->id_customer, 0, 'customers',
            __('{0}_contacts_exported_via_API_({1}).', [$count, 'CardDAV'])
                . ' (' . ($this->customer->get('api_token_name') ?? '-') . ')',
        );
    }

    // ---------------------------------------------------------------- xml helpers

    private function x(string $text): string
    {
        return str_replace("\r", '&#13;', htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
    }

    private function text(int $status, string $message): Response
    {
        return $this->getResponse()->withStatus($status)->withType('text/plain')->withStringBody($message);
    }

    private function multistatus(string $responses): Response
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">'
            . $responses . '</d:multistatus>';
        return $this->getResponse()->withStatus(207, 'Multi-Status')
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withStringBody($xml);
    }

    private function xmlError(int $status, string $element): Response
    {
        return $this->getResponse()->withStatus($status)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withStringBody('<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<d:error xmlns:d="DAV:"><d:' . $element . '/></d:error>');
    }

    private function parseBody(): ?DOMDocument
    {
        $body = trim((string) $this->getRequest()->getBody());
        if ($body === '') {
            return null;
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $ok ? $dom : null;
    }

    /**
     * Property names a client asked for inside <prop>, null = not restricted (allprop).
     *
     * @return list<array{0: string, 1: string}>|null
     */
    private function requestedProps(?DOMDocument $dom): ?array
    {
        if ($dom === null || $dom->documentElement === null) {
            return null;
        }
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->namespaceURI === self::DAV && $node->localName === 'prop') {
                $props = [];
                foreach ($node->childNodes as $p) {
                    if ($p instanceof DOMElement) {
                        $props[] = [(string) $p->namespaceURI, (string) $p->localName];
                    }
                }
                return $props;
            }
        }
        return null;
    }

    /**
     * All properties we know for a resource as ready XML, key "{namespace}name".
     *
     * @param array{type: string, href: string, customerId?: int} $resource
     * @param array<int, array{customer: Customer, vcard: string, etag: string}> $cards
     * @return array<string, string>
     */
    private function properties(array $resource, array $cards): array
    {
        $href = fn(string $h) => '<d:href>' . $this->x($h) . '</d:href>';
        $principal = $this->prefix . '/principal/';
        $privileges = '<d:current-user-privilege-set><d:privilege><d:read/></d:privilege></d:current-user-privilege-set>';
        $common = [
            '{DAV:}current-user-principal' => '<d:current-user-principal>' . $href($principal) . '</d:current-user-principal>',
            '{DAV:}principal-URL' => '<d:principal-URL>' . $href($principal) . '</d:principal-URL>',
            '{DAV:}owner' => '<d:owner>' . $href($principal) . '</d:owner>',
            '{DAV:}current-user-privilege-set' => $privileges,
            '{urn:ietf:params:xml:ns:carddav}addressbook-home-set' => '<c:addressbook-home-set>' . $href($this->prefix . '/') . '</c:addressbook-home-set>',
        ];

        switch ($resource['type']) {
            case 'root':
                return $common + [
                    '{DAV:}resourcetype' => '<d:resourcetype><d:collection/></d:resourcetype>',
                    '{DAV:}displayname' => '<d:displayname>FoodCoopShop</d:displayname>',
                ];
            case 'principal':
                return $common + [
                    '{DAV:}resourcetype' => '<d:resourcetype><d:collection/><d:principal/></d:resourcetype>',
                    '{DAV:}displayname' => '<d:displayname>' . $this->x(trim($this->customer->firstname . ' ' . $this->customer->lastname)) . '</d:displayname>',
                ];
            case 'book':
                return $common + [
                    '{DAV:}resourcetype' => '<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype>',
                    '{DAV:}displayname' => '<d:displayname>' . $this->x(__('Members')) . '</d:displayname>',
                    '{http://calendarserver.org/ns/}getctag' => '<cs:getctag>' . $this->ctag($cards) . '</cs:getctag>',
                    '{urn:ietf:params:xml:ns:carddav}supported-address-data' => '<c:supported-address-data><c:address-data-type content-type="text/vcard" version="3.0"/></c:supported-address-data>',
                    '{DAV:}supported-report-set' => '<d:supported-report-set>'
                        . '<d:supported-report><d:report><c:addressbook-query/></d:report></d:supported-report>'
                        . '<d:supported-report><d:report><c:addressbook-multiget/></d:report></d:supported-report>'
                        . '</d:supported-report-set>',
                ];
            default:
                $card = $cards[$resource['customerId']] ?? null;
                if ($card === null) {
                    return [];
                }
                $c = $card['customer'];
                return $common + [
                    '{DAV:}resourcetype' => '<d:resourcetype/>',
                    '{DAV:}displayname' => '<d:displayname>' . $this->x(trim($c->firstname . ' ' . $c->lastname)) . '</d:displayname>',
                    '{DAV:}getetag' => '<d:getetag>' . $this->x($card['etag']) . '</d:getetag>',
                    '{DAV:}getcontenttype' => '<d:getcontenttype>text/vcard; charset=utf-8</d:getcontenttype>',
                    '{DAV:}getcontentlength' => '<d:getcontentlength>' . strlen($card['vcard']) . '</d:getcontentlength>',
                    '{urn:ietf:params:xml:ns:carddav}address-data' => '<c:address-data>' . $this->x($card['vcard']) . '</c:address-data>',
                ];
        }
    }

    /**
     * @param array{type: string, href: string, customerId?: int} $resource
     * @param array<int, array{customer: Customer, vcard: string, etag: string}> $cards
     * @param list<array{0: string, 1: string}>|null $requested
     */
    private function responseXml(array $resource, array $cards, ?array $requested): string
    {
        $available = $this->properties($resource, $cards);
        $found = [];
        $missing = [];
        if ($requested === null) {
            $found = $available;
            unset($found['{urn:ietf:params:xml:ns:carddav}address-data']); // not part of allprop
        } else {
            foreach ($requested as [$ns, $name]) {
                $key = '{' . $ns . '}' . $name;
                if (isset($available[$key])) {
                    $found[$key] = $available[$key];
                } else {
                    $missing[] = '<n:' . $name . ' xmlns:n="' . $this->x($ns) . '"/>';
                }
            }
        }
        $xml = '<d:response><d:href>' . $this->x($resource['href']) . '</d:href>';
        if ($found || !$missing) {
            $xml .= '<d:propstat><d:prop>' . join('', $found) . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
        }
        if ($missing) {
            $xml .= '<d:propstat><d:prop>' . join('', $missing) . '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
        }
        return $xml . '</d:response>';
    }

    // ---------------------------------------------------------------- methods

    /**
     * @param array{type: string, href: string, customerId?: int} $resource
     */
    private function propfind(array $resource): Response
    {
        $dom = $this->parseBody();
        if (trim((string) $this->getRequest()->getBody()) !== '' && $dom === null) {
            return $this->text(400, 'invalid xml');
        }
        $requested = $this->requestedProps($dom);
        $cards = in_array($resource['type'], ['book', 'card'], true) || $this->getRequest()->getHeaderLine('Depth') !== '0'
            ? $this->cards() : [];
        if ($resource['type'] === 'card' && !isset($cards[$resource['customerId']])) {
            return $this->text(404, 'not found');
        }

        $xml = $this->responseXml($resource, $cards, $requested);
        if ($this->getRequest()->getHeaderLine('Depth') !== '0') {
            foreach ($this->children($resource, $cards) as $child) {
                $xml .= $this->responseXml($child, $cards, $requested);
            }
        }
        return $this->multistatus($xml);
    }

    /**
     * @param array{type: string, href: string, customerId?: int} $resource
     * @param array<int, array{customer: Customer, vcard: string, etag: string}> $cards
     * @return list<array{type: string, href: string, customerId?: int}>
     */
    private function children(array $resource, array $cards): array
    {
        if ($resource['type'] === 'root') {
            return [
                ['type' => 'principal', 'href' => $this->prefix . '/principal/'],
                ['type' => 'book', 'href' => $this->prefix . '/contacts/'],
            ];
        }
        if ($resource['type'] === 'book') {
            $children = [];
            foreach (array_keys($cards) as $id) {
                $children[] = ['type' => 'card', 'href' => $this->prefix . '/contacts/' . ContactCards::uid($id) . '.vcf', 'customerId' => $id];
            }
            return $children;
        }
        return [];
    }

    /**
     * @param array{type: string, href: string, customerId?: int} $resource
     */
    private function report(array $resource): Response
    {
        if ($resource['type'] !== 'book') {
            return $this->xmlError(403, 'supported-report');
        }
        $dom = $this->parseBody();
        if ($dom === null || $dom->documentElement === null) {
            return $this->text(400, 'invalid xml');
        }
        $root = $dom->documentElement;
        $requested = $this->requestedProps($dom);
        $cards = $this->cards();

        if ($root->namespaceURI === self::CARD && $root->localName === 'addressbook-query') {
            // filters are not evaluated, the address book is small: the client gets all contacts and filters itself
            $ids = array_keys($cards);
        } elseif ($root->namespaceURI === self::CARD && $root->localName === 'addressbook-multiget') {
            $ids = [];
            $notFound = '';
            foreach ($root->getElementsByTagNameNS(self::DAV, 'href') as $hrefNode) {
                $name = basename(rawurldecode((string) parse_url(trim($hrefNode->textContent), PHP_URL_PATH)));
                $id = ContactCards::customerIdFromName($name);
                if ($id !== null && isset($cards[$id])) {
                    $ids[] = $id;
                } else {
                    $notFound .= '<d:response><d:href>' . $this->x(trim($hrefNode->textContent)) . '</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>';
                }
            }
        } else {
            // sync-collection and everything else is not supported (not advertised); clients fall back to ctag/etag
            return $this->xmlError(403, $root->localName === 'sync-collection' ? 'valid-sync-token' : 'supported-report');
        }

        $xml = '';
        foreach ($ids as $id) {
            $xml .= $this->responseXml(
                ['type' => 'card', 'href' => $this->prefix . '/contacts/' . ContactCards::uid($id) . '.vcf', 'customerId' => $id],
                $cards,
                $requested,
            );
        }
        $this->logDelivery(count($ids));
        return $this->multistatus($xml . ($notFound ?? ''));
    }

    /**
     * @param array{type: string, href: string, customerId?: int} $resource
     */
    private function get(array $resource, bool $headOnly): Response
    {
        if ($resource['type'] !== 'card') {
            return $this->text(405, 'use PROPFIND/REPORT')->withHeader('Allow', 'OPTIONS, PROPFIND, REPORT');
        }
        $cards = $this->cards();
        $card = $cards[$resource['customerId']] ?? null;
        if ($card === null) {
            return $this->text(404, 'not found');
        }
        $this->logDelivery(1);
        $response = $this->getResponse()
            ->withHeader('Content-Type', 'text/vcard; charset=utf-8')
            ->withHeader('ETag', $card['etag']);
        return $headOnly ? $response : $response->withStringBody($card['vcard']);
    }
}
