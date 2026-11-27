<?php
declare(strict_types=1);

namespace App\Services\Newsletter\SpamCheck;

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
 * Eine Meldung des Newsletter-Spam-Checks.
 */
final class SpamCheckFinding
{

    public const LEVEL_ERROR = 'error';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_HINT = 'hint';
    public const LEVEL_INFO = 'info';

    public const LEVELS = [self::LEVEL_ERROR, self::LEVEL_WARNING, self::LEVEL_HINT, self::LEVEL_INFO];

    /**
     * @param string $field subject | preheader | body | headers | sender | dns | spamassassin
     * @param array<string, scalar|null> $params Werte fuer die Platzhalter im Text
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly string $level,
        public readonly string $category,
        public readonly string $message,
        public readonly string $tip,
        public readonly string $field = 'body',
        public readonly ?string $blockId = null,
        public readonly ?string $path = null,
        public readonly array $params = [],
        public float $weight = 0.0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'level' => $this->level,
            'category' => $this->category,
            'message' => $this->message,
            'tip' => $this->tip,
            'field' => $this->field,
            'blockId' => $this->blockId,
            'path' => $this->path,
            'params' => $this->params,
            'weight' => round($this->weight, 1),
        ];
    }

}
