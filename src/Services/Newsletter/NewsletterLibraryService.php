<?php
declare(strict_types=1);

namespace App\Services\Newsletter;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use RuntimeException;

/**
 * FoodCoopShop - The open source software for your foodcoop
 *
 * Licensed under the GNU Affero General Public License version 3
 * For full copyright and license information, please see LICENSE
 * Redistributions of files must retain the above copyright notice.
 *
 * Installiert die Standard-Bausteine und die Standard-Vorlage aus config/newsletter_library.json.
 * Vorhandene Einträge mit gleichem Namen und Typ bleiben unverändert (Änderungen der Nutzer:innen werden nie überschrieben).
 *
 * Aufruf: bin/cake newsletter import_library
 *
 * @since         FoodCoopShop 4.3.0
 * @license       https://opensource.org/licenses/AGPL-3.0
 * @link          https://www.foodcoopshop.com
 */
class NewsletterLibraryService
{

    /**
     * @return array{blocks: int, templates: int, skipped: list<string>}
     */
    public function install(?string $file = null): array
    {
        $path = $file ?? CONFIG . 'newsletter_library.json';
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException('Bibliotheksdatei fehlt: ' . $path);
        }
        $library = json_decode($raw, true);
        if (!is_array($library)) {
            throw new RuntimeException('Bibliotheksdatei ist kein gültiges JSON.');
        }
        $library = $this->replaceTokens($library);
        $editor = new NewsletterEditorService();
        $table = TableRegistry::getTableLocator()->get('NewsletterTemplates');

        $result = ['blocks' => 0, 'templates' => 0, 'skipped' => []];
        foreach ($library['blocks'] ?? [] as $item) {
            $name = (string)($item['name'] ?? '');
            if ($this->exists($table, 'block', $name)) {
                $result['skipped'][] = 'Baustein: ' . $name;
                continue;
            }
            $editor->saveTemplate('block', $name, [$item['block']]);
            $result['blocks']++;
        }
        foreach ($library['templates'] ?? [] as $item) {
            $name = (string)($item['name'] ?? '');
            if ($this->exists($table, 'template', $name)) {
                $result['skipped'][] = 'Vorlage: ' . $name;
                continue;
            }
            $editor->saveTemplate('template', $name, $item['doc']);
            $result['templates']++;
        }

        return $result;
    }

    private function exists(\Cake\ORM\Table $table, string $type, string $name): bool
    {
        return $table->find()->where(['type' => $type, 'name' => mb_substr($name, 0, 255)])->count() > 0;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function replaceTokens(array $data): array
    {
        $base = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        $map = [
            '__BASE_URL__' => $base,
            '__LOGO_URL__' => $base . '/files/images/' . Configure::read('app.logoFileName'),
            '__APP_NAME__' => (string)Configure::read('appDb.FCS_APP_NAME'),
            '__APP_EMAIL__' => (string)Configure::read('appDb.FCS_APP_EMAIL'),
        ];
        array_walk_recursive($data, function (&$value) use ($map) {
            if (is_string($value)) {
                $value = strtr($value, $map);
            }
        });

        return $data;
    }

}
