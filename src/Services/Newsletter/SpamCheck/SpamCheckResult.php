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
 * Ergebnis des Newsletter-Spam-Checks: Meldungen, Punkte (0-100), Ampel.
 */
final class SpamCheckResult
{

    public const TRAFFIC_GREEN = 'green';
    public const TRAFFIC_YELLOW = 'yellow';
    public const TRAFFIC_RED = 'red';

    /**
     * @param list<SpamCheckFinding> $findings
     * @param array<string, mixed> $stats
     * @param array<string, mixed>|null $spamAssassin
     */
    public function __construct(
        public readonly array $findings,
        public readonly int $score,
        public readonly string $traffic,
        public readonly string $trafficLabel,
        public readonly array $stats = [],
        public readonly ?array $spamAssassin = null,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->count(SpamCheckFinding::LEVEL_ERROR) > 0;
    }

    public function count(string $level): int
    {
        $n = 0;
        foreach ($this->findings as $finding) {
            if ($finding->level === $level) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * @return list<string>
     */
    public function ruleIds(): array
    {
        return array_values(array_unique(array_map(fn(SpamCheckFinding $f) => $f->ruleId, $this->findings)));
    }

    public function hasRule(string $ruleId): bool
    {
        return in_array($ruleId, $this->ruleIds(), true);
    }

    /**
     * @return list<SpamCheckFinding>
     */
    public function byRule(string $ruleId): array
    {
        return array_values(array_filter($this->findings, fn(SpamCheckFinding $f) => $f->ruleId === $ruleId));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'traffic' => $this->traffic,
            'trafficLabel' => $this->trafficLabel,
            'hasErrors' => $this->hasErrors(),
            'counts' => [
                'error' => $this->count(SpamCheckFinding::LEVEL_ERROR),
                'warning' => $this->count(SpamCheckFinding::LEVEL_WARNING),
                'hint' => $this->count(SpamCheckFinding::LEVEL_HINT),
                'info' => $this->count(SpamCheckFinding::LEVEL_INFO),
            ],
            'findings' => array_map(fn(SpamCheckFinding $f) => $f->toArray(), $this->findings),
            'stats' => $this->stats,
            'spamAssassin' => $this->spamAssassin,
        ];
    }

}
